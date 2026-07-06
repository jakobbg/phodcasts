<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$action = (string)($_GET['action'] ?? '');
$feed   = (string)($_GET['feed'] ?? '');
$show   = (string)($_GET['show'] ?? '');

// Record (as a side effect) whether this request proves that clean URL
// rewriting (mod_rewrite + .htaccess) is actually active on this server, so
// that later pages (e.g. the index page) can safely offer pretty links only
// once we know they will really work. See use_clean_urls() for details.
use_clean_urls();

if ($action === 'img') {
    $name = (string)($_GET['name'] ?? '');
    send_image_asset(__DIR__, $name);
    exit;
}

if ($action === 'media') {
    $file = (string)($_GET['file'] ?? '');
    $isDownload = !empty($_GET['dl']);
    if ($isDownload) {
        // Force browser download with the original filename.
        $dlName = basename(str_replace(['/', '\\'], '-', $file));
        if ($dlName !== '') {
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $dlName) . '"');
        }
    }
    // Cover images are cached locally on the webserver (cache/covers/), so
    // most requests never have to touch the (possibly slow) feed storage.
    if (!$isDownload && serve_cached_cover($feed, $file)) {
        exit;
    }
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        echo "Unknown feed";
        exit;
    }
    stream_file($feed, $feedDir, $file);
    exit;
}

if ($action === 'meta') {
    send_metadata_json($feed);
    exit;
}

if ($action === 'save_notes') {
    $content = (string)($_POST['content'] ?? '');
    save_notes_handler($feed, $content);
    exit;
}

if ($feed !== '') {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Unknown feed";
        exit;
    }
    $feedType = str_starts_with($feed, BOOKS_SUBDIR . '/') ? 'book' : 'podcast';
    send_rss($feed, $feedDir, $feedType);
    exit;
}

if ($show !== '') {
    require_main_page_password();
    render_show_page($show);
    exit;
}

$filter = (string)($_GET['filter'] ?? 'all');
render_index_page($filter);
