<?php
declare(strict_types=1);

// ── Internal app metadata (not user-configurable) ────────────────────────────
const MAX_ITEMS   = 200;
const APP_VERSION = 'v1.3';
const REPO_URL    = 'https://github.com/jakobbg/phodcasts';

// ── Load user settings from config/config.json ───────────────────────────────
(static function (): void {
    $path = __DIR__ . '/config.json';

    if (!is_file($path)) {
        http_response_code(500);
        die('Missing config/config.json — copy config.example.json and edit your settings.');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        http_response_code(500);
        die('Cannot read config/config.json — check file permissions.');
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        http_response_code(500);
        die('config/config.json contains invalid JSON: ' . json_last_error_msg());
    }

    // Defaults used when a key is absent from config.json.
    $defaults = [
        'PODCAST_ROOT'        => '/mnt/podcasts',
        'PODCASTS_SUBDIR'     => 'Podcasts',
        'BOOKS_SUBDIR'        => 'Books',
        'FEED_LANGUAGE'       => 'en',
        'TRUSTED_PROXY_CIDRS' => ['127.0.0.1/32', '::1/128'],
        'FETCH_BOOK_METADATA' => false,
    ];

    define('PODCAST_ROOT',        (string)($cfg['PODCAST_ROOT']        ?? $defaults['PODCAST_ROOT']));
    define('PODCASTS_SUBDIR',     (string)($cfg['PODCASTS_SUBDIR']     ?? $defaults['PODCASTS_SUBDIR']));
    define('BOOKS_SUBDIR',        (string)($cfg['BOOKS_SUBDIR']        ?? $defaults['BOOKS_SUBDIR']));
    define('FEED_LANGUAGE',       (string)($cfg['FEED_LANGUAGE']       ?? $defaults['FEED_LANGUAGE']));
    define('TRUSTED_PROXY_CIDRS', (array) ($cfg['TRUSTED_PROXY_CIDRS'] ?? $defaults['TRUSTED_PROXY_CIDRS']));
    define('FETCH_BOOK_METADATA', (bool)  ($cfg['FETCH_BOOK_METADATA'] ?? $defaults['FETCH_BOOK_METADATA']));
})();

