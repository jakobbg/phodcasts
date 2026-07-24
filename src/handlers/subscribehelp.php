<?php
declare(strict_types=1);

function render_subscribe_help_page(string $feed): void {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Unknown feed";
        return;
    }

    $displayTitle = basename($feed);
    $feedType = str_starts_with($feed, BOOKS_SUBDIR . '/') ? 'book' : 'podcast';

    $base = base_url();
    $assetBase = $base;
    $rssUrl = feed_url($feed, true);
    $appleUrl = apple_podcasts_url($feed);

    $rawBack = trim((string)($_GET['return_to'] ?? ''));
    $backUrl = ($rawBack !== '' && $rawBack[0] === '/' && !str_contains($rawBack, '//') && !str_contains($rawBack, "\n") && !str_contains($rawBack, "\r"))
        ? $rawBack
        : show_url($feed);

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');
    send_security_headers('html');
    require __DIR__ . '/../../views/subscribe_help.phtml';
}
