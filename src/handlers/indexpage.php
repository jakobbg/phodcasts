<?php
declare(strict_types=1);

function render_index_page(string $filter): void {
    $allowedFilters = ['all', 'podcasts', 'books'];
    if (!in_array($filter, $allowedFilters, true)) {
        $filter = 'all';
    }

    $feeds = list_podcasts($filter);

    // Strip feeds that have no downloaded content.
    // Use cached 'has_content' if available to avoid slow disk scans.
    $feeds = array_values(array_filter($feeds, static function (array $f): bool {
        $cached = load_metadata_cache($f['id']);
        if ($cached !== null && isset($cached['stats']['has_content'])) {
            return $cached['stats']['has_content'];
        }
        return feed_has_content($f['dir']);
    }));

    // Optional initial query from URL. Actual filtering is done in-page by JS.
    $query = trim((string)($_GET['q'] ?? ''));

    $base      = base_url();
    $appPath   = app_base_path();
    $assetBase = $base;
    $ogImageUrl     = $base . 'og.png';
    $iconUrl        = $base . 'apple-touch-icon.png';
    $faviconUrl     = $base . 'favicon.png';

    header('Content-Type: text/html; charset=UTF-8');
    // Allow conditional 304s; pages are dynamic so revalidation is required.
    header('Cache-Control: no-cache');
    // HTTP/2 preload hint for the one external script (theme toggle).
    header('Link: <' . $base . 'js/theme.js>; rel=preload; as=script', false);
    send_security_headers('html');
    require __DIR__ . '/../../views/index.phtml';
}
