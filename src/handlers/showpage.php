<?php
declare(strict_types=1);

// ── Show page ─────────────────────────────────────────────────────────────────

function render_show_page(string $feed): void {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Unknown feed";
        return;
    }

    $feedType = str_starts_with($feed, BOOKS_SUBDIR . '/') ? 'book' : 'podcast';
    $name     = basename($feed);
    $base      = base_url();
    $assetBase = $base;

    // Resolve the back-navigation URL from the ?return_to= parameter.
    // Validate strictly: must be a relative path (starts with /, no // or newlines).
    $rawBack = trim((string)($_GET['return_to'] ?? ''));
    $backUrl = ($rawBack !== '' && $rawBack[0] === '/' && !str_contains($rawBack, '//') && !str_contains($rawBack, "\n") && !str_contains($rawBack, "\r"))
        ? $rawBack
        : $base;  // relative app root — works even for shared/direct links

    // Open Library metadata (books only, when enabled).
    // Only reads the local cache — no blocking network request.
    // If no cache entry exists yet, $metaFetchUrl tells the view to fetch async.
    $meta         = null;
    $metaFetchUrl = null;
    if ($feedType === 'book' && FETCH_BOOK_METADATA) {
        $cached = load_metadata_cache($feed);
        if ($cached !== null && !empty($cached['found'])) {
            $meta = $cached;          // warm cache — instant
        } elseif ($cached === null) {
            // Cold cache — let the browser fetch in the background
            $metaFetchUrl = $base . '?' . http_build_query(['action' => 'meta', 'feed' => $feed]);
        }
        // cached['found'] === false: Open Library returned no match, don't retry
    }

    // Optional notes: prefer manual notes.md in the feed directory;
    // fall back to web-saved notes in cache/notes/.
    $notes    = null;
    $notesRaw = load_feed_notes($feed, $feedDir);
    if ($notesRaw !== null) {
        $notes = render_markdown($notesRaw);
    }

    // Enumerate media files and stats from cache.
    $feedMeta      = get_feed_metadata($feed, $feedDir);
    $mediaFiles    = $feedMeta['episodes'] ?? [];
    $stats         = $feedMeta['stats'] ?? [];
    $episodeCount  = $stats['count'] ?? 0;
    $totalSize     = $stats['total_size'] ?? 0;
    $totalDuration = $stats['total_duration'] ?? null;
    $newestTs      = $stats['newest_ts'] ?? null;
    $addedTs       = $stats['added_ts'] ?? null;

    // Cover images (largest image first).
    $coverImgPaths = $feedMeta['covers'] ?? [];
    $coverUrls     = array_map(static fn(string $p): string => media_url($feed, basename($p)), $coverImgPaths);
    $coverUrl      = $coverUrls[0] ?? null;

    // Background refresh URL for stats, covers, and book metadata.
    $refreshUrl = app_base_path() . '?' . http_build_query(['action' => 'meta', 'feed' => $feed, 'refresh' => 1]);

    $bookArchiveUrl = null;
    $bookArchiveStatusUrl = null;
    if ($feedType === 'book' && BOOK_ARCHIVE_ENABLED) {
        $bookArchiveUrl = app_base_path() . '?' . http_build_query(['action' => 'book_archive', 'feed' => $feed]);
        $bookArchiveStatusUrl = app_base_path() . '?' . http_build_query(['action' => 'book_archive_status', 'feed' => $feed]);
    }

    header('Content-Type: text/html; charset=UTF-8');
    // Allow conditional 304s; pages are dynamic so revalidation is required.
    header('Cache-Control: no-cache');
    // HTTP/2 preload hint for the one external script (theme toggle).
    header('Link: <' . $base . 'js/theme.js>; rel=preload; as=script', false);
    send_security_headers('html');
    require __DIR__ . '/../../views/show.phtml';
}
