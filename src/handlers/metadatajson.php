<?php
declare(strict_types=1);

/**
 * Returns book metadata as JSON for a given feed, fetching from Open Library
 * if not already cached. Used by the show page for background (async) loading
 * so the initial page render is never blocked by a network request.
 *
 * Response shape:
 *   { "found": true,  "title": "...", "author": "...", "year": 2001, "description": "..." }
 *   { "found": false }
 */
function send_metadata_json(string $feed): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    send_security_headers('rss');

    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        echo json_encode(['found' => false, 'error' => 'unknown feed']);
        return;
    }

    if (!str_starts_with($feed, BOOKS_SUBDIR . '/')) {
        echo json_encode(['found' => false, 'error' => 'not a book feed']);
        return;
    }

    $name = basename($feed);
    $meta = fetch_book_metadata($feed, $name);

    if ($meta === null || empty($meta['found'])) {
        echo json_encode(['found' => false]);
        return;
    }

    echo json_encode([
        'found'       => true,
        'title'       => $meta['title']       ?? null,
        'author'      => $meta['author']      ?? null,
        'year'        => isset($meta['year'])  ? (int)$meta['year'] : null,
        'description' => $meta['description'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
}
