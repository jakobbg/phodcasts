<?php
declare(strict_types=1);

function render_index_page(string $filter): void {
    $allowedFilters = ['all', 'podcasts', 'books'];
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'all';
    }

    $feeds      = list_podcasts($filter);
    $totalFeeds = count($feeds);

    // Slice to the current page BEFORE the view loops so podcast_stats() only
    // runs for the feeds actually being rendered.
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $totalPages = $totalFeeds > 0 ? (int)ceil($totalFeeds / FEEDS_PER_PAGE) : 1;
    $page       = min($page, $totalPages);
    $feeds      = array_slice($feeds, ($page - 1) * FEEDS_PER_PAGE, FEEDS_PER_PAGE);

    $base = base_url();
    // Asset base: strip the script filename, leaving the directory URL with trailing slash
    $assetBase = substr($base, 0, strrpos($base, '/') + 1);
    $ogImageUrl     = $assetBase . 'og.png';
    $iconUrl        = $assetBase . 'apple-touch-icon.png';
    $faviconUrl     = $assetBase . 'favicon.png';

    header('Content-Type: text/html; charset=UTF-8');
    send_security_headers('html');
    require __DIR__ . '/../../views/index.phtml';
}
