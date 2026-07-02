<?php
declare(strict_types=1);

// Podcasts: /mnt/torrents/Podcasts/Podcasts/  Audio Books: /mnt/torrents/Podcasts/Books/

require_once __DIR__ . '/config/bootstrap.php';

$action = (string)($_GET['action'] ?? '');
$feed = (string)($_GET['feed'] ?? '');

if ($action === 'img') {
  $name = (string)($_GET['name'] ?? '');
  send_image_asset(__DIR__, $name);
    exit;
}

if ($action === 'media') {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        echo "Unknown feed";
        exit;
    }
    $file = (string)($_GET['file'] ?? '');
    stream_file($feed, $feedDir, $file);
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

$filter = (string)($_GET['filter'] ?? 'all');
render_index_page($filter);
