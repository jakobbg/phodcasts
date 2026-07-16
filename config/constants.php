<?php
declare(strict_types=1);

// ── Internal app metadata (not user-configurable) ────────────────────────────
const MAX_ITEMS   = 200;
const APP_NAME    = 'fablr';
const APP_VERSION = 'v1.8.2';
const REPO_URL    = 'https://github.com/jakobbg/fablr';
const APP_QUIP    = 'Fables on demand — Your audio, your schedule';

// Minimum time (in seconds) that must pass since a feed's metadata cache was
// last (re)built before another refresh from disk is allowed. This protects
// slow network-share deployments from being re-scanned on every single page
// load (the index/show pages trigger a background "refresh=1" request each
// time they're opened).
const CACHE_MIN_REFRESH_INTERVAL = 1800; // 30 minutes
const BOOK_ARCHIVE_TTL_DEFAULT_SECONDS = 2678400; // 31 days

// ── Load user settings from config/config.json ───────────────────────────────
(static function (): void {
    $path = __DIR__ . '/config.json';

    if (!is_file($path)) {
        http_response_code(500);
        die('Missing config/config.json — copy config/config.json.sample and edit your local settings.');
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

    $requiredKeys = [
        'PODCAST_ROOT',
        'PODCASTS_SUBDIR',
        'BOOKS_SUBDIR',
        'FEED_LANGUAGE',
        'TRUSTED_PROXY_CIDRS',
        'FETCH_BOOK_METADATA',
        'MAIN_PAGE_PASSWORD',
    ];
    $missing = [];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $cfg)) {
            $missing[] = $key;
        }
    }
    if ($missing !== []) {
        http_response_code(500);
        die('config/config.json is missing required keys: ' . implode(', ', $missing) . '. Copy config/config.json.sample and fill all settings.');
    }
    if (!is_array($cfg['TRUSTED_PROXY_CIDRS'])) {
        http_response_code(500);
        die('config/config.json key TRUSTED_PROXY_CIDRS must be an array.');
    }

    define('PODCAST_ROOT',        (string)$cfg['PODCAST_ROOT']);
    define('PODCASTS_SUBDIR',     (string)$cfg['PODCASTS_SUBDIR']);
    define('BOOKS_SUBDIR',        (string)$cfg['BOOKS_SUBDIR']);
    define('FEED_LANGUAGE',       (string)$cfg['FEED_LANGUAGE']);
    define('TRUSTED_PROXY_CIDRS', (array) $cfg['TRUSTED_PROXY_CIDRS']);
    define('FETCH_BOOK_METADATA', (bool)  $cfg['FETCH_BOOK_METADATA']);
    define('MAIN_PAGE_PASSWORD',  (string)$cfg['MAIN_PAGE_PASSWORD']);

    $bookArchiveEnabled = array_key_exists('BOOK_ARCHIVE_ENABLED', $cfg)
        ? (bool)$cfg['BOOK_ARCHIVE_ENABLED']
        : true;
    define('BOOK_ARCHIVE_ENABLED', $bookArchiveEnabled);

    $bookArchiveTtl = array_key_exists('BOOK_ARCHIVE_TTL_SECONDS', $cfg)
        ? (int)$cfg['BOOK_ARCHIVE_TTL_SECONDS']
        : BOOK_ARCHIVE_TTL_DEFAULT_SECONDS;
    if ($bookArchiveTtl <= 0) {
        $bookArchiveTtl = BOOK_ARCHIVE_TTL_DEFAULT_SECONDS;
    }
    define('BOOK_ARCHIVE_TTL_SECONDS', $bookArchiveTtl);
})();

