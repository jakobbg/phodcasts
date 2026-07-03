<?php
declare(strict_types=1);

function render_index_page(string $filter): void {
    $allowedFilters = ['all', 'podcasts', 'books'];
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'all';
    }

    $feeds = list_podcasts($filter);

    // Strip feeds that have no downloaded content.
    $feeds = array_values(array_filter($feeds, static function (array $f): bool {
        return feed_has_content($f['dir']);
    }));

    // Optional initial query from URL. Actual filtering is done in-page by JS.
    $query = trim((string)($_GET['q'] ?? ''));

    $base = base_url();
    // Asset base: strip the script filename, leaving the directory URL with trailing slash
    $assetBase = substr($base, 0, strrpos($base, '/') + 1);
    $ogImageUrl     = $assetBase . 'og.png';
    $iconUrl        = $assetBase . 'apple-touch-icon.png';
    $faviconUrl     = $assetBase . 'favicon.png';

    header('Content-Type: text/html; charset=UTF-8');
    // Allow conditional 304s; pages are dynamic so revalidation is required.
    header('Cache-Control: no-cache');
    // HTTP/2 preload hint for the one external script (theme toggle).
    header('Link: <' . $assetBase . 'js/theme.js>; rel=preload; as=script', false);
    send_security_headers('html');
    require __DIR__ . '/../../views/index.phtml';
}
