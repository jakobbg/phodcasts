<?php
declare(strict_types=1);

function render_index_page(string $filter): void {
    $allowedFilters = ['all', 'podcasts', 'books'];
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'all';
    }

    $feeds = list_podcasts($filter);
    $base = base_url();
    // Asset base: strip the script filename, leaving the directory URL with trailing slash
    $assetBase = substr($base, 0, strrpos($base, '/') + 1);
    $ogImageUrl     = $assetBase . 'og.png';
    $iconUrl        = $assetBase . 'apple-touch-icon.png';
    $faviconUrl     = $assetBase . 'favicon.png';

    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/../../views/index.phtml';
}
