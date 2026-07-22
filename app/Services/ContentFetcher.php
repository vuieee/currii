<?php
// app/Services/ContentFetcher.php
//
// Fetches an article's original page and extracts a readable content block,
// so articles whose feed only publishes a short excerpt (or nothing) can
// still be read fully inside Currii instead of only linking out.
// This is a best-effort heuristic extractor (largest-plausible-<article>
// block), not a full Readability.js-grade parser — it favors safety and
// "good enough" over perfect fidelity.

class ContentFetcher {
    private const MAX_BYTES = 2_000_000; // 2MB cap on the fetched page
    private const TIMEOUT_SECONDS = 8;

    /**
     * Fetch $url and return a sanitized HTML fragment of its main content,
     * or null if the page couldn't be fetched or no reasonable content block
     * could be identified.
     */
    public function fetchReadable(string $url): ?string {
        $html = $this->fetch($url);
        if ($html === null) return null;

        $extracted = $this->extractMainContent($html);
        if ($extracted === null || trim(strip_tags($extracted)) === '') return null;

        return $this->sanitize($extracted, $url);
    }

    private function fetch(string $url): ?string {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return null;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Currii-Reader/1.0; +https://currii.test)',
            CURLOPT_RANGE => '0-' . self::MAX_BYTES,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
        curl_close($ch);

        if ($httpCode !== 200 || !$body) return null;
        if ($contentType && stripos($contentType, 'html') === false) return null;

        return $body;
    }

    private function extractMainContent(string $html): ?string {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // Force UTF-8 interpretation regardless of the page's declared charset quirks
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        // Strip elements that are never article content
        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'header', 'form', 'iframe', 'aside'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Prefer semantic containers, then fall back to the densest <div>/<section>
        $candidates = [];
        foreach (['//article', '//main', '//*[@itemprop="articleBody"]', '//*[contains(@class,"article-body")]', '//*[contains(@class,"post-content")]', '//*[contains(@class,"entry-content")]'] as $query) {
            foreach ($xpath->query($query) as $node) {
                $candidates[] = $node;
            }
        }

        if (empty($candidates)) {
            // Fallback: pick the <div>/<section> with the most <p> text among reasonably-sized blocks
            $best = null;
            $bestScore = 0;
            foreach ($xpath->query('//div|//section') as $node) {
                $textLen = strlen(trim($node->textContent));
                $pCount = $xpath->query('.//p', $node)->length;
                $score = $textLen + ($pCount * 50);
                if ($score > $bestScore && $textLen > 200) {
                    $bestScore = $score;
                    $best = $node;
                }
            }
            if ($best) $candidates[] = $best;
        }

        if (empty($candidates)) return null;

        // Among candidates, pick the one with the most text
        usort($candidates, fn($a, $b) => strlen($b->textContent) <=> strlen($a->textContent));
        $chosen = $candidates[0];

        $innerHtml = '';
        foreach ($chosen->childNodes as $child) {
            $innerHtml .= $doc->saveHTML($child);
        }
        return $innerHtml;
    }

    /**
     * Strip anything that isn't safe to inject as read-only reader content:
     * scripts, event handlers, styles, forms, and non-http(s) links/srcs.
     */
    private function sanitize(string $fragment, string $baseUrl): string {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="currii-root">' . $fragment . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'button', 'input', 'link', 'meta'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Strip event handler attributes and javascript: URLs; resolve relative image/link URLs
        foreach ($xpath->query('//*') as $node) {
            if (!($node instanceof DOMElement)) continue;
            foreach (iterator_to_array($node->attributes ?? []) as $attr) {
                $name = strtolower($attr->nodeName);
                if (str_starts_with($name, 'on')) {
                    $node->removeAttribute($attr->nodeName);
                    continue;
                }
                if (in_array($name, ['src', 'href']) && stripos(trim($attr->nodeValue), 'javascript:') === 0) {
                    $node->removeAttribute($attr->nodeName);
                    continue;
                }
                if (in_array($name, ['src', 'href'])) {
                    $resolved = $this->resolveUrl($baseUrl, $attr->nodeValue);
                    if ($resolved) $node->setAttribute($attr->nodeName, $resolved);
                }
            }
            if ($node->tagName === 'a') {
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $root = $doc->getElementById('currii-root');
        $out = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
        }
        return $out;
    }

    private function resolveUrl(string $base, string $maybeRelative): ?string {
        $maybeRelative = trim($maybeRelative);
        if ($maybeRelative === '' || str_starts_with($maybeRelative, 'data:')) return $maybeRelative;
        if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;

        $baseParts = parse_url($base);
        if (!$baseParts) return null;
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        if ($host === '') return null;

        if (str_starts_with($maybeRelative, '//')) return $scheme . ':' . $maybeRelative;
        if (str_starts_with($maybeRelative, '/')) return $scheme . '://' . $host . $maybeRelative;

        $basePath = $baseParts['path'] ?? '/';
        $dir = rtrim(substr($basePath, 0, strrpos($basePath, '/') ?: 0), '/');
        return $scheme . '://' . $host . $dir . '/' . $maybeRelative;
    }
}