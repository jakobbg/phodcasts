<?php
declare(strict_types=1);

/**
 * Returns feed metadata (stats, covers, and book info) as JSON for a given feed,
 * optionally refreshing the cache from disk. Used for background (async)
 * loading so the initial page render is never blocked by slow network shares.
 *
 * Response shape:
 *   {
 *     "found": true,
 *     "stats": { "count": 10, "newest_ts": 123, "has_content": true },
 *     "covers": [ "http://.../img1.jpg", ... ],
 *     "title": "...", "author": "...", "year": 2001, "description": "..."
 *   }
 */
function send_metadata_json(string $feed): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    send_security_headers('metadata');

    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        echo json_encode(['found' => false, 'error' => 'unknown feed']);
        return;
    }

    $refresh = !empty($_GET['refresh']);
    $meta    = get_feed_metadata($feed, $feedDir, $refresh);

    // If it's a book and we haven't fetched book metadata yet, do it now.
    if (str_starts_with($feed, BOOKS_SUBDIR . '/') && FETCH_BOOK_METADATA && empty($meta['found']) && empty($meta['fetched_at'])) {
        $name = basename($feed);
        $bookMeta = fetch_book_metadata($feed, $name);
        if ($bookMeta) {
            $meta = array_merge($meta, $bookMeta);
        }
    }

    $response = [
        'found'  => (bool)($meta['found'] ?? false),
        'stats'  => $meta['stats'] ?? null,
        'covers' => [],
    ];

    if ($response['stats'] && isset($response['stats']['newest_ts'])) {
        $response['stats']['newest_human'] = human_age((int)$response['stats']['newest_ts']);
        $response['stats']['newest_iso']   = gmdate('Y-m-d', (int)$response['stats']['newest_ts']);
    }

    if (isset($meta['covers']) && is_array($meta['covers'])) {
        foreach ($meta['covers'] as $path) {
            $response['covers'][] = media_url($feed, basename($path));
        }
    }

    if (!empty($meta['found'])) {
        $response['title']       = $meta['title']       ?? null;
        $response['author']      = $meta['author']      ?? null;
        $response['year']        = isset($meta['year']) ? (int)$meta['year'] : null;
        $response['description'] = $meta['description'] ?? null;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
