<?php
// If your virtual host's document root is this project folder (not public/),
// this sends every request into public/ where the real front controller lives.
$target = '/public' . $_SERVER['REQUEST_URI'];
header('Location: ' . $target, true, 302);
exit;