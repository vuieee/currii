// public/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    const State = {
        articles: [],
        allArticles: [],
        sources: [],
        currentView: 'feed', // 'feed' | 'bookmarks' | 'sources'
        activeArticleId: null,
        activeSourceId: null, // null = All Articles
        activeTag: null,
        isGuest: false
    };

    // --- DOM References ---
    const authScreen = document.getElementById('auth-screen');
    const appRoot = document.getElementById('app-root');

    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginError = document.getElementById('login-error');
    const registerError = document.getElementById('register-error');
    const authTabs = document.querySelectorAll('.auth-tab');
    const guestBtn = document.getElementById('guest-btn');
    const forgotLink = document.getElementById('forgot-password-link');

    const guestBadge = document.getElementById('guest-badge');
    const logoutBtn = document.getElementById('btn-logout');
    const adminBtn = document.getElementById('btn-admin');

    const articleListEl = document.getElementById('article-list');
    const articleListEmptyEl = document.getElementById('article-list-empty');
    const feedPanelTitle = document.getElementById('feed-panel-title');
    const sourceListEl = document.getElementById('source-list');
    const sourceManagePanel = document.getElementById('sources-manage-panel');
    const sourceManageListEl = document.getElementById('source-manage-list');

    const readerPanel = document.querySelector('.reader-content');
    const appLayout = document.querySelector('.app-layout');
    const collapseFeedBtn = document.getElementById('btn-collapse-feed');
    const emptyState = document.querySelector('.reader-empty-state');
    const addFeedBtn = document.getElementById('add-feed-btn');
    const markAllReadBtn = document.getElementById('mark-all-read-btn');

    const searchInput = document.getElementById('search-input');
    const cmdPalette = document.getElementById('cmd-palette');
    const cmdInput = document.getElementById('cmd-input');
    const toastContainer = document.getElementById('toast-container');

    // --- Toasts (replaces alert() feedback) ---
    function toast(message, isError = false) {
        const el = document.createElement('div');
        el.className = `toast ${isError ? 'toast-error' : ''}`;
        el.textContent = message;
        toastContainer.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 200);
        }, 3200);
    }

    // --- App Modal (replaces native prompt()/confirm()) ---
    const appModal = document.getElementById('app-modal');
    const appModalForm = document.getElementById('app-modal-form');
    const appModalTitle = document.getElementById('app-modal-title');
    const appModalMessage = document.getElementById('app-modal-message');
    const appModalLabel = document.getElementById('app-modal-label');
    const appModalInput = document.getElementById('app-modal-input');
    const appModalError = document.getElementById('app-modal-error');
    const appModalCancel = document.getElementById('app-modal-cancel');
    const appModalConfirmBtn = document.getElementById('app-modal-confirm');

    let modalResolver = null;

    function closeModal(result) {
        appModal.close ? appModal.close() : appModal.removeAttribute('open');
        if (modalResolver) {
            modalResolver(result);
            modalResolver = null;
        }
    }

    appModalCancel.addEventListener('click', () => closeModal(null));
    appModal.addEventListener('cancel', (e) => { // Escape key / native dialog cancel event
        e.preventDefault();
        closeModal(null);
    });
    appModal.addEventListener('click', (e) => {
        if (e.target === appModal) closeModal(null); // backdrop click
    });
    appModalForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (appModalInput.classList.contains('hidden')) {
            closeModal(true); // confirm-style dialog
        } else {
            const value = appModalInput.value.trim();
            if (!value) {
                appModalError.textContent = 'This field is required.';
                appModalError.classList.remove('hidden');
                appModalInput.focus();
                return;
            }
            closeModal(value);
        }
    });

    function openModal({ title, message, label, withInput, placeholder, confirmLabel, danger }) {
        appModalTitle.textContent = title;
        appModalError.classList.add('hidden');
        appModalError.textContent = '';

        appModalMessage.classList.toggle('hidden', !message);
        appModalMessage.textContent = message || '';

        appModalLabel.classList.toggle('hidden', !withInput || !label);
        appModalLabel.textContent = label || '';

        appModalInput.classList.toggle('hidden', !withInput);
        appModalInput.value = '';
        appModalInput.placeholder = placeholder || '';

        appModalConfirmBtn.textContent = confirmLabel || 'OK';
        appModalConfirmBtn.classList.toggle('danger', !!danger);

        appModal.showModal ? appModal.showModal() : appModal.setAttribute('open', '');
        if (withInput) {
            setTimeout(() => appModalInput.focus(), 10);
        } else {
            setTimeout(() => appModalConfirmBtn.focus(), 10);
        }

        return new Promise((resolve) => { modalResolver = resolve; });
    }

    // showPrompt(title, label, placeholder) -> Promise<string|null>
    function showPrompt(title, label, placeholder) {
        return openModal({ title, label, withInput: true, placeholder, confirmLabel: 'Add' });
    }

    // showConfirm(title, message) -> Promise<boolean>
    function showConfirm(title, message, danger) {
        return openModal({ title, message, confirmLabel: danger ? 'Remove' : 'OK', danger }).then(r => !!r);
    }

    // --- Auth Screen ---
    authTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetForm = document.getElementById(tab.dataset.form);
            const currentForm = document.querySelector('.auth-form:not(.hidden)');
            if (targetForm === currentForm) return;

            authTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            currentForm?.classList.add('hidden');
            targetForm.classList.remove('hidden');
        });
    });

    function setAdminIconVisible(isAdmin) {
        adminBtn.classList.toggle('hidden', !isAdmin);
    }

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        loginError.textContent = '';
        try {
            const res = await API.login(
                document.getElementById('login-email').value.trim(),
                document.getElementById('login-password').value
            );
            setAdminIconVisible(res.user?.role === 'Admin');
            enterApp(false, true);
        } catch (err) {
            loginError.textContent = err.message;
        }
    });

    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        registerError.textContent = '';
        try {
            await API.register(
                document.getElementById('register-email').value.trim(),
                document.getElementById('register-password').value
            );
            toast('Account created. You can log in now.');
            document.querySelector('.auth-tab[data-form="login-form"]').click();
            registerForm.reset();
        } catch (err) {
            registerError.textContent = err.message;
        }
    });

    guestBtn.addEventListener('click', async () => {
        try {
            await API.guestLogin();
            setAdminIconVisible(false);
            enterApp(true, true);
        } catch (err) {
            toast(err.message, true);
        }
    });

    forgotLink.addEventListener('click', (e) => {
        e.preventDefault();
        toast('If password reset is configured, check your email for a reset link.');
    });

    const switchToLoginLink = document.getElementById('switch-to-login-link');
    switchToLoginLink.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelector('.auth-tab[data-form="login-form"]').click();
    });

    logoutBtn.addEventListener('click', async () => {
        try { await API.logout(); } catch (_) { /* proceed regardless */ }
        location.reload();
    });

    adminBtn.addEventListener('click', () => {
        location.href = '/admin';
    });

    async function enterApp(isGuest, isFreshLogin) {
        State.isGuest = isGuest;
        authScreen.classList.add('hidden');

        if (isFreshLogin && isWelcomeScreenEnabled()) {
            await playWelcomeScreen();
        }

        appRoot.classList.remove('hidden');
        guestBadge.classList.toggle('hidden', !isGuest);
        markAllReadBtn.classList.toggle('hidden', isGuest);
        document.querySelector('.nav-item[data-view="bookmarks"]').classList.toggle('disabled', isGuest);

        // A fresh login/guest-login gets a brand new history entry, so the very
        // first Back press lands inside the app instead of leaving the tab.
        // Restoring an already-active session (boot() on normal page load) just
        // tags the current entry with state, without adding a new one.
        if (isFreshLogin) {
            history.pushState({ view: State.currentView }, '', `#${State.currentView}`);
        } else {
            history.replaceState({ view: State.currentView }, '', `#${State.currentView}`);
        }

        await refreshCurrentView();
    }

    // --- Welcome screen (acts as a loading screen while the app boots) ---
    const welcomeScreen = document.getElementById('welcome-screen');
    const WELCOME_PREF_KEY = 'currii:welcomeScreenEnabled';

    function isWelcomeScreenEnabled() {
        try {
            const stored = localStorage.getItem(WELCOME_PREF_KEY);
            return stored === null ? true : stored === '1'; // on by default
        } catch (_) {
            return true;
        }
    }

    function setWelcomeScreenEnabled(enabled) {
        try { localStorage.setItem(WELCOME_PREF_KEY, enabled ? '1' : '0'); } catch (_) { /* storage may be unavailable */ }
    }

    // --- Settings dialog ---
    const settingsModal = document.getElementById('settings-modal');
    const settingsBtn = document.getElementById('btn-settings');
    const settingsClose = document.getElementById('settings-close');
    const settingsWelcomeToggle = document.getElementById('settings-welcome-toggle');

    settingsBtn.addEventListener('click', () => {
        settingsWelcomeToggle.checked = isWelcomeScreenEnabled();
        settingsDarkToggle.checked = isDarkThemeEnabled();
        renderFontScaleControl();
        settingsModal.showModal ? settingsModal.showModal() : settingsModal.setAttribute('open', '');
    });
    settingsClose.addEventListener('click', () => {
        settingsModal.close ? settingsModal.close() : settingsModal.removeAttribute('open');
    });
    settingsModal.addEventListener('click', (e) => {
        if (e.target === settingsModal) settingsModal.close ? settingsModal.close() : settingsModal.removeAttribute('open');
    });
    settingsWelcomeToggle.addEventListener('change', () => {
        setWelcomeScreenEnabled(settingsWelcomeToggle.checked);
    });

    // --- Dark theme ---
    const THEME_PREF_KEY = 'currii:darkTheme';
    const settingsDarkToggle = document.getElementById('settings-dark-toggle');

    function isDarkThemeEnabled() {
        try { return localStorage.getItem(THEME_PREF_KEY) === '1'; } catch (_) { return false; }
    }
    function applyDarkTheme(enabled) {
        document.body.classList.toggle('theme-dark', enabled);
        try { localStorage.setItem(THEME_PREF_KEY, enabled ? '1' : '0'); } catch (_) { /* storage may be unavailable */ }
    }
    settingsDarkToggle.addEventListener('change', () => applyDarkTheme(settingsDarkToggle.checked));
    applyDarkTheme(isDarkThemeEnabled()); // apply on boot, before first paint of the app UI

    // --- Text size scaling ---
    const FONT_SCALE_KEY = 'currii:fontScale';
    const FONT_SCALE_STEPS = [0.9, 1, 1.1, 1.2];
    const fontDecreaseBtn = document.getElementById('settings-font-decrease');
    const fontIncreaseBtn = document.getElementById('settings-font-increase');
    const scaleFill = document.getElementById('settings-scale-fill');
    const scaleTrack = document.getElementById('settings-scale-track');

    function getFontScale() {
        try {
            const stored = parseFloat(localStorage.getItem(FONT_SCALE_KEY));
            return FONT_SCALE_STEPS.includes(stored) ? stored : 1;
        } catch (_) {
            return 1;
        }
    }
    function applyFontScale(scale) {
        document.documentElement.style.setProperty('--font-scale', scale);
        try { localStorage.setItem(FONT_SCALE_KEY, scale); } catch (_) { /* storage may be unavailable */ }
    }
    function renderFontScaleControl() {
        const current = getFontScale();
        const index = FONT_SCALE_STEPS.indexOf(current);
        const percent = (index / (FONT_SCALE_STEPS.length - 1)) * 100;
        scaleFill.style.width = percent + '%';
        scaleTrack.querySelectorAll('.settings-scale-dot').forEach(dot => {
            dot.classList.toggle('active', parseFloat(dot.dataset.value) <= current);
        });
        fontDecreaseBtn.disabled = index === 0;
        fontIncreaseBtn.disabled = index === FONT_SCALE_STEPS.length - 1;
    }
    function stepFontScale(direction) {
        const current = getFontScale();
        const index = FONT_SCALE_STEPS.indexOf(current);
        const nextIndex = Math.min(FONT_SCALE_STEPS.length - 1, Math.max(0, index + direction));
        applyFontScale(FONT_SCALE_STEPS[nextIndex]);
        renderFontScaleControl();
    }
    fontDecreaseBtn.addEventListener('click', () => stepFontScale(-1));
    fontIncreaseBtn.addEventListener('click', () => stepFontScale(1));
    applyFontScale(getFontScale()); // apply on boot

    function playWelcomeScreen() {
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReducedMotion) return Promise.resolve();

        return new Promise((resolve) => {
            welcomeScreen.classList.remove('hidden');
            const lines = [
                document.getElementById('welcome-line-1'),
                document.getElementById('welcome-line-2'),
                document.getElementById('welcome-line-3'),
            ];
            lines.forEach(l => l.classList.remove('welcome-line-visible'));

            const stepDelay = 750;
            lines.forEach((line, i) => {
                setTimeout(() => line.classList.add('welcome-line-visible'), i * stepDelay + 150);
            });

            const totalDuration = stepDelay * lines.length + 700;
            setTimeout(() => {
                welcomeScreen.classList.add('welcome-screen-exit');
                setTimeout(() => {
                    welcomeScreen.classList.add('hidden');
                    welcomeScreen.classList.remove('welcome-screen-exit');
                    resolve();
                }, 400);
            }, totalDuration);
        });
    }

    // --- Boot: check for an existing session before showing the auth screen ---
    async function boot() {
        try {
            const res = await API.me();
            if (res.success) {
                API.csrfToken = res.csrf_token || null;
                setAdminIconVisible(!res.guest && res.user?.role === 'Admin');
                enterApp(!!res.guest);
                return;
            }
        } catch (_) { /* not logged in */ }
        setAdminIconVisible(false);
        authScreen.classList.remove('hidden');
        appRoot.classList.add('hidden');
    }

    // --- View Switching (Feed / Bookmarks / Sources) ---
    const navItems = document.querySelectorAll('.capsule-nav.center-capsule .nav-item');

    // Switches to the given view, updates the nav highlight, and (unless told not to,
    // e.g. when responding to a popstate event) pushes a new history entry for it —
    // this is what gives the Back button somewhere to go within the app instead of
    // leaving the tab on the very first press after login.
    function switchView(view, pushHistory = true) {
        const item = document.querySelector(`.capsule-nav.center-capsule .nav-item[data-view="${view}"]`);
        if (!item || item.classList.contains('disabled')) return;

        navItems.forEach(nav => nav.classList.remove('active'));
        item.classList.add('active');
        State.currentView = view;
        renderViewChrome();
        refreshCurrentView();

        if (pushHistory) {
            history.pushState({ view }, '', `#${view}`);
        }
    }

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            if (item.classList.contains('disabled')) {
                toast('Bookmarks require a registered account.', true);
                return;
            }
            switchView(item.dataset.view);
        });
    });

    // Restores the correct view when the user presses Back/Forward within the app.
    window.addEventListener('popstate', (event) => {
        if (!appRoot.classList.contains('hidden') && event.state?.view) {
            switchView(event.state.view, false);
        }
    });

    function renderViewChrome() {
        const isSources = State.currentView === 'sources';
        sourceManagePanel.classList.toggle('hidden', !isSources);
        document.getElementById('article-list').parentElement.classList.toggle('hidden', isSources);

        const titles = { feed: 'Feed', bookmarks: 'Starred', sources: 'Manage Sources' };
        feedPanelTitle.textContent = titles[State.currentView];
        markAllReadBtn.classList.toggle('hidden', State.currentView !== 'feed' || State.isGuest);
    }

    async function refreshCurrentView() {
        await loadSources();
        if (State.currentView === 'sources') {
            renderSourceManageList();
        } else if (State.currentView === 'bookmarks') {
            const res = await API.getBookmarks();
            State.allArticles = res.data || [];
            applyFilters();
        } else {
            const res = await API.getArticles();
            State.allArticles = res.data || [];
            applyFilters();
        }
    }

    // Applies the active source filter (and, when searching, the active tag filter)
    // to the last-fetched article set and re-renders — no re-fetch needed.
    function applyFilters() {
        let list = State.allArticles || [];
        if (State.activeSourceId !== null) {
            list = list.filter(a => a.feed_id === State.activeSourceId);
        }
        if (State.activeTag) {
            list = list.filter(a => (a.tags || '').split(',').map(t => t.trim().toLowerCase()).includes(State.activeTag.toLowerCase()));
        }
        renderArticles(list);
    }

    // --- Sources (sidebar) ---
    async function loadSources() {
        const response = await API.getSources();
        State.sources = response.data;

        const totalUnread = State.sources.reduce((sum, s) => sum + (s.unread_count || 0), 0);
        const allActive = State.activeSourceId === null;
        let html = `
            <li class="source-item ${allActive ? 'active' : ''}" data-id="">
                <span class="source-item-label">All Articles</span>
                ${totalUnread ? `<span class="unread-count">${totalUnread}</span>` : ''}
            </li>
        `;
        let currentCategory = null;

        State.sources.forEach(source => {
            if (source.category_name !== currentCategory) {
                currentCategory = source.category_name;
                html += `<li class="category-header">${escapeHtml(currentCategory || 'Uncategorized')}</li>`;
            }
            const icon = source.favicon_url || fallbackIcon();
            const unread = source.unread_count || 0;
            const isActive = State.activeSourceId === source.id;
            html += `
                <li class="source-item ${isActive ? 'active' : ''}" data-id="${source.id}">
                    <img src="${icon}" class="favicon" alt="" width="16" height="16" onerror="this.src='${fallbackIcon()}'">
                    <span class="source-item-label">${escapeHtml(source.title)}</span>
                    ${unread ? `<span class="unread-count">${unread}</span>` : ''}
                </li>
            `;
        });

        sourceListEl.innerHTML = html;

        sourceListEl.querySelectorAll('.source-item').forEach(item => {
            item.addEventListener('click', () => {
                if (State.currentView === 'sources') return; // manage view has its own list
                const id = item.dataset.id;
                State.activeSourceId = id === '' ? null : parseInt(id);
                sourceListEl.querySelectorAll('.source-item').forEach(el => el.classList.remove('active'));
                item.classList.add('active');
                applyFilters();
            });
        });
    }

    function fallbackIcon() {
        return "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'><path d='M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16M4 20h.01'/></svg>";
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    // --- Sources (manage view) ---
    function renderSourceManageList() {
        if (!State.sources.length) {
            sourceManageListEl.innerHTML = '<p class="empty-hint">No sources yet. Use the + button to add one.</p>';
            return;
        }

        sourceManageListEl.innerHTML = State.sources.map(s => `
            <div class="source-manage-row" data-id="${s.id}">
                <img src="${s.favicon_url || fallbackIcon()}" class="favicon" alt="" width="18" height="18" onerror="this.src='${fallbackIcon()}'">
                <div class="source-manage-info">
                    <strong>${escapeHtml(s.title)}</strong>
                    <span class="source-manage-meta">${escapeHtml(s.website_url || '')} • ${s.health_status || 'Online'}</span>
                </div>
                <div class="source-manage-actions">
                    <button class="text-btn" data-action="refresh" title="Refresh">Refresh</button>
                    ${State.isGuest ? '' : `<button class="text-btn" data-action="notify" title="Toggle notifications">${s.notify_email ? 'Notify: On' : 'Notify: Off'}</button>`}
                    <button class="text-btn danger" data-action="delete" title="Remove">Remove</button>
                </div>
            </div>
        `).join('');

        sourceManageListEl.querySelectorAll('.source-manage-row').forEach(row => {
            const id = parseInt(row.dataset.id);
            row.querySelector('[data-action="refresh"]').addEventListener('click', async () => {
                try {
                    const res = await API.refreshSource(id);
                    toast(`${res.articles_added || 0} new article(s) fetched.`);
                    await refreshCurrentView();
                } catch (err) { toast(err.message, true); }
            });
            row.querySelector('[data-action="delete"]').addEventListener('click', async () => {
                const source = State.sources.find(s => s.id === id);
                const ok = await showConfirm('Remove this source?', `${source ? source.title : 'This feed'} and its articles will no longer sync. This can't be undone.`, true);
                if (!ok) return;
                try {
                    await API.removeSource(id);
                    await refreshCurrentView();
                } catch (err) { toast(err.message, true); }
            });
            const notifyBtn = row.querySelector('[data-action="notify"]');
            if (notifyBtn) {
                notifyBtn.addEventListener('click', async () => {
                    const source = State.sources.find(s => s.id === id);
                    try {
                        await API.toggleNotification(id, !source.notify_email);
                        await refreshCurrentView();
                    } catch (err) { toast(err.message, true); }
                });
            }
        });
    }

    // --- Article List Rendering ---
    function renderArticles(articles) {
        State.articles = articles || [];
        articleListEmptyEl.classList.toggle('hidden', State.articles.length > 0);

        articleListEl.innerHTML = State.articles.map(art => {
            const dateObj = new Date(art.published_at);
            const dateLabel = isNaN(dateObj) ? '' : dateObj.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            const preview = (art.summary || stripHtml(art.content) || '').slice(0, 120);
            const initial = (art.source_title || '?').trim().charAt(0).toUpperCase();
            const tagList = (art.tags || '').split(',').map(t => t.trim()).filter(Boolean);
            const tagsHtml = `<span class="row-tags">${tagList.slice(0, 1).map(t => `<button class="row-tag" data-tag="${escapeHtml(t)}">${escapeHtml(t)}</button>`).join('')}</span>`;

            return `
                <div class="article-card ${art.is_read ? 'read' : 'unread'} ${art.is_bookmarked ? 'starred' : ''}" data-id="${art.id}">
                    <span class="row-dot" aria-hidden="true"></span>
                    <span class="row-avatar">${initial}</span>
                    <span class="row-sender">${escapeHtml(art.source_title)}</span>
                    <span class="row-subject">
                        <span class="row-subject-title">${escapeHtml(art.title)}</span><span class="row-subject-sep"> — </span><span class="row-preview">${escapeHtml(preview)}</span>
                    </span>
                    ${tagsHtml}
                    <span class="row-date">${dateLabel}</span>
                    <span class="row-actions">
                        <button class="row-action-btn" data-action="star" title="Star" aria-label="Star">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                        </button>
                    </span>
                </div>
            `;
        }).join('');

        articleListEl.querySelectorAll('.row-tag').forEach(chip => {
            chip.addEventListener('click', (e) => {
                e.stopPropagation();
                searchInput.value = chip.dataset.tag;
                runSearch(chip.dataset.tag);
            });
        });

        document.querySelectorAll('.article-card').forEach(card => {
            const id = parseInt(card.dataset.id);
            card.addEventListener('click', (e) => {
                if (e.target.closest('.row-action-btn')) return;
                openArticle(id);
            });
            card.querySelector('[data-action="star"]')?.addEventListener('click', (e) => {
                e.stopPropagation();
                const article = State.articles.find(a => a.id === id);
                if (article) toggleBookmark(article);
            });
        });
    }

    function stripHtml(html) {
        if (!html) return '';
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || '';
    }

    // --- Read Article Panel ---
    async function fetchFullArticleContent(articleId, article, targetEl) {
        try {
            const res = await API.fetchFullContent(articleId);
            // Guard: the person may have opened a different article while this was in flight
            if (State.activeArticleId !== articleId) return;

            if (!res || !res.success || typeof res.content !== 'string' || !res.content.trim()) {
                throw new Error('No content returned');
            }

            article.content = res.content;
            targetEl.innerHTML = res.content;
        } catch (err) {
            if (State.activeArticleId !== articleId) return;
            targetEl.innerHTML = `
                <div class="reader-fetch-status reader-fetch-error">
                    <span>Couldn't load the full article automatically. Use "Read Original" to view it on the source site.</span>
                </div>
            `;
        }
    }

    window.openArticle = async function (id) {
        const article = State.articles.find(a => a.id === id);
        if (!article) return;

        State.activeArticleId = id;
        emptyState.classList.add('hidden');
        readerPanel.classList.remove('hidden');
        readerPanel.classList.remove('reader-content-enter');
        void readerPanel.offsetWidth; // restart animation even if already open
        readerPanel.classList.add('reader-content-enter');

        document.getElementById('read-title').textContent = article.title;
        document.getElementById('read-website').textContent = article.source_title + (article.author ? ` · ${article.author}` : '');
        document.getElementById('read-date').textContent = new Date(article.published_at).toLocaleString();
        document.getElementById('read-avatar').textContent = (article.source_title || '?').trim().charAt(0).toUpperCase();
        document.getElementById('btn-original').href = article.url;

        const tagList = (article.tags || '').split(',').map(t => t.trim()).filter(Boolean);
        const readerTagsEl = document.getElementById('read-tags');
        readerTagsEl.innerHTML = tagList.map(t => `<button class="reader-tag" data-tag="${escapeHtml(t)}">${escapeHtml(t)}</button>`).join('');
        readerTagsEl.classList.toggle('hidden', tagList.length === 0);
        readerTagsEl.querySelectorAll('.reader-tag').forEach(chip => {
            chip.addEventListener('click', () => {
                searchInput.value = chip.dataset.tag;
                runSearch(chip.dataset.tag);
            });
        });

        // Article body: rendered as read-only content. When the feed only gave us a thin
        // excerpt (or nothing), fetch and extract the original page server-side so the
        // full piece can still be read inside Currii instead of only linking out.
        const readBodyEl = document.getElementById('read-body');
        const hasSubstantialContent = article.content && stripHtml(article.content).trim().length > 400;

        if (hasSubstantialContent) {
            readBodyEl.innerHTML = article.content;
        } else {
            readBodyEl.innerHTML = `
                <div class="reader-fetch-status">
                    <div class="reader-fetch-spinner"></div>
                    <span>Fetching the full article...</span>
                </div>
            `;
            fetchFullArticleContent(id, article, readBodyEl);
        }

        const bookmarkBtn = document.getElementById('btn-bookmark');
        bookmarkBtn.classList.toggle('active', !!article.is_bookmarked);
        bookmarkBtn.title = article.is_bookmarked ? 'Starred' : 'Star';
        bookmarkBtn.onclick = () => toggleBookmark(article);

        if (!article.is_read && !State.isGuest) {
            article.is_read = true;
            document.querySelector(`.article-card[data-id="${id}"]`)?.classList.replace('unread', 'read');
            await API.toggleArticle(id, 'is_read', true);
        }
    };

    async function toggleBookmark(article) {
        if (State.isGuest) {
            toast('Bookmarks require a registered account.', true);
            return;
        }
        const newState = !article.is_bookmarked;
        try {
            await API.toggleArticle(article.id, 'is_bookmarked', newState);
            article.is_bookmarked = newState;
            document.querySelector(`.article-card[data-id="${article.id}"]`)?.classList.toggle('starred', newState);
            if (State.activeArticleId === article.id) {
                const bookmarkBtn = document.getElementById('btn-bookmark');
                bookmarkBtn.classList.toggle('active', newState);
                bookmarkBtn.title = newState ? 'Starred' : 'Star';
            }
            if (State.currentView === 'bookmarks' && !newState) {
                await refreshCurrentView(); // it just dropped out of the bookmarks list
            }
        } catch (err) {
            toast(err.message, true);
        }
    }

    // --- Collapse feed list (auto-hides the article list while reading, expands on hover) ---
    function setFeedCollapsed(collapsed) {
        appLayout.classList.toggle('feed-collapsed', collapsed);
        collapseFeedBtn.classList.toggle('active', collapsed);
        collapseFeedBtn.title = collapsed ? 'Show feed list' : 'Hide feed list';
        try { localStorage.setItem('currii:feedCollapsed', collapsed ? '1' : '0'); } catch (_) { /* storage may be unavailable */ }
    }

    collapseFeedBtn.addEventListener('click', () => {
        setFeedCollapsed(!appLayout.classList.contains('feed-collapsed'));
    });

    try {
        if (localStorage.getItem('currii:feedCollapsed') === '1') setFeedCollapsed(true);
    } catch (_) { /* storage may be unavailable */ }

    // --- Add Feed ---
    addFeedBtn.addEventListener('click', async () => {
        const url = await showPrompt('Add a source', 'Feed URL', 'https://example.com/feed.xml');
        if (!url) return;

        addFeedBtn.disabled = true;
        try {
            await API.addSource(url.trim());
            toast('Source added.');
            await refreshCurrentView();
        } catch (error) {
            toast(error.message || 'Failed to add feed.', true);
        } finally {
            addFeedBtn.disabled = false;
        }
    });

    // --- Mark All Read ---
    markAllReadBtn.addEventListener('click', async () => {
        try {
            await API.markAllRead();
            await refreshCurrentView();
        } catch (err) {
            toast(err.message, true);
        }
    });

    // --- Search ---
    let searchDebounce = null;
    async function runSearch(term) {
        if (!term) {
            await refreshCurrentView();
            return;
        }
        const res = await API.search(term);
        State.allArticles = res.data || [];
        applyFilters();
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => runSearch(searchInput.value.trim()), 300);
    });

    // --- Command Palette (Ctrl+K) ---
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            if (appRoot.classList.contains('hidden') || appModal.open) return;
            cmdPalette.showModal ? cmdPalette.showModal() : cmdPalette.setAttribute('open', '');
            cmdInput.value = '';
            cmdInput.focus();
        }
        if (e.key === 'Escape' && cmdPalette.open) {
            cmdPalette.close ? cmdPalette.close() : cmdPalette.removeAttribute('open');
        }
    });

    let cmdDebounce = null;
    cmdInput.addEventListener('input', () => {
        clearTimeout(cmdDebounce);
        cmdDebounce = setTimeout(async () => {
            const term = cmdInput.value.trim();
            if (!term) { document.getElementById('cmd-results').innerHTML = ''; return; }
            const res = await API.search(term);
            const list = document.getElementById('cmd-results');
            list.innerHTML = res.data.slice(0, 8).map(a => `
                <li data-id="${a.id}">${escapeHtml(a.title)} <span class="cmd-result-source">${escapeHtml(a.source_title)}</span></li>
            `).join('') || '<li class="cmd-no-results">No matches</li>';

            list.querySelectorAll('li[data-id]').forEach(li => {
                li.addEventListener('click', async () => {
                    const id = parseInt(li.dataset.id);
                    if (!State.articles.find(a => a.id === id)) {
                        const res2 = await API.search(term);
                        State.articles = res2.data;
                    }
                    cmdPalette.close ? cmdPalette.close() : cmdPalette.removeAttribute('open');
                    openArticle(id);
                });
            });
        }, 250);
    });

    boot();

    // --- Back/forward navigation fix ---
    // When the browser restores this page from bfcache (e.g. hitting "Back" after
    // visiting /admin), the page doesn't reload or re-run boot() by default — it just
    // repaints whatever DOM state was frozen at navigation time. That can show a stale
    // auth screen or a stale app view instead of the real, current session state.
    // Re-running boot() on a bfcache restore re-checks /api/me and fixes the UI.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            boot();
        }
    });
});
