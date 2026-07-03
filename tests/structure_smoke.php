<?php
declare(strict_types=1);

$requiredPaths = [
    'index.php',
    '.htaccess',
    'config/bootstrap.php',
    'config/constants.php',
    'config/config.json',
    'src/utils/web.php',
    'src/utils/media.php',
    'src/utils/audioduration.php',
    'src/utils/markdown.php',
    'src/feed/library.php',
    'src/feed/metadata.php',
    'src/handlers/showpage.php',
    'tests/utils_smoke.php',
    'tests/markdown_smoke.php',
    'tests/rss_smoke.php',
    'views/show.phtml',
    'src/title/library.php',
    'src/handlers/rss.php',
    'src/handlers/media.php',
    'src/handlers/savenotes.php',
    'src/handlers/metadatajson.php',
    'src/handlers/assets.php',
    'src/handlers/indexpage.php',
    'js/theme.js',
    'views/index.phtml',
    'logo.png',
    'og.png',
    'apple-touch-icon.png',
    'favicon.png',
];

$root = dirname(__DIR__);
$missing = [];

foreach ($requiredPaths as $relPath) {
    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($fullPath)) {
        $missing[] = $relPath;
    }
}

if (!empty($missing)) {
    fwrite(STDERR, "Structure smoke tests failed. Missing paths:\n");
    foreach ($missing as $path) {
        fwrite(STDERR, "- {$path}\n");
    }
    exit(1);
}

// Verify cache directory is writable — caches silently do nothing if it isn't.
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
if (!is_dir($cacheDir)) {
    fwrite(STDERR, "Structure smoke tests failed: cache/ directory does not exist.\n");
    exit(1);
}
if (!is_writable($cacheDir)) {
    fwrite(STDERR, "Structure smoke warning: cache/ is not writable by the current process.\n");
    fwrite(STDERR, "  Run: chmod 777 " . $cacheDir . "\n");
    fwrite(STDERR, "  Episode duration and metadata caches will silently fail until fixed.\n");
    // Non-fatal on CI (may run as a different user); fatal only in production checks.
}

echo "Structure smoke tests passed: " . count($requiredPaths) . "\n";

// Verify config.json is valid JSON and contains required keys.
$cfgRaw = @file_get_contents($root . '/config/config.json');
if ($cfgRaw === false || ($cfg = json_decode($cfgRaw, true)) === null) {
    fwrite(STDERR, "Structure smoke tests failed: config/config.json is missing or contains invalid JSON.\n");
    exit(1);
}
$requiredKeys = ['PODCAST_ROOT', 'PODCASTS_SUBDIR', 'BOOKS_SUBDIR', 'FEED_LANGUAGE', 'TRUSTED_PROXY_CIDRS', 'FETCH_BOOK_METADATA'];
foreach ($requiredKeys as $k) {
    if (!array_key_exists($k, $cfg)) {
        fwrite(STDERR, "Structure smoke tests failed: config/config.json is missing key: {$k}\n");
        exit(1);
    }
}
