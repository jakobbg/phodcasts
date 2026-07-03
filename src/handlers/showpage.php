<?php
declare(strict_types=1);

// ── Episode duration cache ────────────────────────────────────────────────────
// Cache lives at cache/episodes/{sha1(feedId)}.json.
// Structure: { "/abs/path/to/file.mp3": { "mtime": 123, "duration": 3600.0 } }
// A null duration means parsing was attempted but failed.

function episode_cache_path(string $feedId): string {
    return __DIR__ . '/../../cache/episodes/' . sha1($feedId) . '.json';
}

function load_episode_cache(string $feedId): array {
    $path = episode_cache_path($feedId);
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_episode_cache(string $feedId, array $cache): void {
    $path = episode_cache_path($feedId);
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        $root = dirname($dir);
        if (is_dir($root) && !is_writable($root)) {
            @chmod($root, 0777);
        }
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log("phodcasts: cannot create episode cache dir {$dir} — check permissions on cache/");
            return;
        }
    }
    if (file_put_contents($path, json_encode($cache), LOCK_EX) === false) {
        error_log("phodcasts: cannot write episode cache {$path} — check permissions on cache/episodes/");
    }
}

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
    $assetBase = substr($base, 0, strrpos($base, '/') + 1);

    // Resolve the back-navigation URL from the ?return_to= parameter.
    // Validate strictly: must be a relative path (starts with /, no // or newlines).
    $rawBack = trim((string)($_GET['return_to'] ?? ''));
    $backUrl = ($rawBack !== '' && $rawBack[0] === '/' && !str_contains($rawBack, '//') && !str_contains($rawBack, "\n") && !str_contains($rawBack, "\r"))
        ? $rawBack
        : $base;

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

    // Optional notes.md in the feed directory.
    $notes    = null;
    $notesPath = $feedDir . DIRECTORY_SEPARATOR . 'notes.md';
    if (is_file($notesPath) && is_readable($notesPath)) {
        $raw = @file_get_contents($notesPath);
        if ($raw !== false) {
            $notes = render_markdown($raw);
        }
    }

    // Enumerate media files (already ordered correctly for the feed type).
    $mediaFiles = find_media_files($feedDir, $feedType);

    // Enrich each file with duration from cache or fresh parse.
    $cache   = load_episode_cache($feed);
    $changed = false;
    foreach ($mediaFiles as &$f) {
        $key = $f['path'];
        if (array_key_exists($key, $cache) && $cache[$key]['mtime'] === $f['mtime']) {
            $f['duration'] = $cache[$key]['duration']; // may be null (cached miss)
        } else {
            $f['duration'] = audio_duration($f['path']);
            $cache[$key]   = ['mtime' => $f['mtime'], 'duration' => $f['duration']];
            $changed       = true;
        }
    }
    unset($f);
    if ($changed) save_episode_cache($feed, $cache);

    // Aggregate stats.
    $totalSize     = (int)array_sum(array_column($mediaFiles, 'size'));
    $durations     = array_filter(array_column($mediaFiles, 'duration'), fn($d) => $d !== null);
    $totalDuration = !empty($durations) ? (float)array_sum($durations) : null;
    $episodeCount  = count($mediaFiles);

    // Cover images (largest image first).
    $coverImgPaths = discover_images($feedDir);
    $coverUrls     = array_map(static fn(string $p): string => media_url($feed, basename($p)), $coverImgPaths);
    $coverUrl      = $coverUrls[0] ?? null;

    // Newest episode date.
    $newestTs    = null;
    $sortTs      = array_column($mediaFiles, 'sort_ts');
    if (!empty($sortTs)) $newestTs = (int)max($sortTs);

    header('Content-Type: text/html; charset=UTF-8');
    send_security_headers('html');
    require __DIR__ . '/../../views/show.phtml';
}
