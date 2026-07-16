<?php
declare(strict_types=1);

/**
 * Split a feed name into author and title components.
 * Expects the common "Author - Title" convention.
 * Returns ['author' => string|null, 'title' => string].
 */
function parse_author_and_title(string $feedName): array {
    $pos = strpos($feedName, ' - ');
    if ($pos > 0) {
        $author = trim(substr($feedName, 0, $pos));
        $title  = trim(substr($feedName, $pos + 3));
        if ($author !== '' && $title !== '') {
            return ['author' => $author, 'title' => $title];
        }
    }
    return ['author' => null, 'title' => $feedName];
}

function metadata_cache_path(string $feedId): string {
    return __DIR__ . '/../../cache/metadata/' . sha1($feedId) . '.json';
}

function load_metadata_cache(string $feedId): ?array {
    $path = metadata_cache_path($feedId);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function save_metadata_cache(string $feedId, array $data): void {
    $path = metadata_cache_path($feedId);
    $dir  = dirname($path);
    if (!ensure_cache_dir($dir)) {
        return;
    }
    if (file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        error_log(APP_NAME . ": cannot write metadata cache {$path} — check permissions on cache/metadata/");
    }
}

function openlibrary_get(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 5,
            'ignore_errors' => true,
            'user_agent'    => APP_NAME . '/' . APP_VERSION . ' (self-hosted audiobook server)',
            'header'        => "Accept: application/json\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Fetch and cache Open Library metadata for a book feed.
 * Returns a metadata array on success, or null if not found / disabled.
 *
 * Cache lives at cache/metadata/{sha1(feedId)}.json and persists indefinitely.
 * Delete the file to force a refresh.
 */
function fetch_book_metadata(string $feedId, string $feedName): ?array {
    // Return cached result (including cached negative results) immediately.
    $cached = load_metadata_cache($feedId);
    if ($cached !== null) {
        // We have a cache entry.
        // If it was found (Open Library metadata is there), return it.
        if (!empty($cached['found'])) {
            return $cached;
        }
        // If it was NOT found (negative cache), but we have stats now,
        // it means we already tried Open Library and it failed.
        if (isset($cached['fetched_at']) && empty($cached['found'])) {
            return null;
        }
        // If it only has stats but hasn't tried Open Library yet, proceed.
    }

    ['author' => $author, 'title' => $title] = parse_author_and_title($feedName);

    // Build Open Library search query.
    $params = ['fields' => 'key,title,author_name,first_publish_year,subject', 'limit' => '1'];
    if ($author !== null) {
        $params['title']  = $title;
        $params['author'] = $author;
    } else {
        $params['q'] = $title;
    }
    $searchUrl = 'https://openlibrary.org/search.json?' . http_build_query($params);

    $search = openlibrary_get($searchUrl);
    if (empty($search['docs'][0])) {
        // Cache the miss so we don't keep hitting the API for unknown titles.
        save_metadata_cache($feedId, ['fetched_at' => time(), 'source' => 'openlibrary', 'found' => false]);
        return null;
    }

    $doc = $search['docs'][0];

    // Fetch full work record for the description field.
    $description = null;
    $workKey = $doc['key'] ?? null;
    if (is_string($workKey) && str_starts_with($workKey, '/works/')) {
        $work = openlibrary_get('https://openlibrary.org' . $workKey . '.json');
        if (isset($work['description'])) {
            $description = is_array($work['description'])
                ? ($work['description']['value'] ?? null)
                : (string)$work['description'];
        }
    }

    $meta = [
        'fetched_at'  => time(),
        'source'      => 'openlibrary',
        'found'       => true,
        'title'       => is_string($doc['title']) ? $doc['title'] : $feedName,
        'author'      => isset($doc['author_name'][0]) ? $doc['author_name'][0] : $author,
        'year'        => isset($doc['first_publish_year']) ? (int)$doc['first_publish_year'] : null,
        'description' => $description,
        'subjects'    => array_values(array_slice((array)($doc['subject'] ?? []), 0, 5)),
    ];

    save_metadata_cache($feedId, $meta);
    return $meta;
}

/**
 * Get or refresh general feed statistics and covers.
 * Returns an array containing 'stats', 'covers' and 'episodes'.
 */
function get_feed_metadata(string $feedId, string $feedDir, bool $forceRefresh = false): array {
    $cached = load_metadata_cache($feedId);
    $statsVersion = 2;

    // If we have cached stats and aren't forcing a refresh, return them.
    if (
        !$forceRefresh
        && $cached !== null
        && isset($cached['stats'])
        && (int)($cached['stats_version'] ?? 0) >= $statsVersion
    ) {
        return $cached;
    }

    // Even when a refresh is requested, never rescan disk more than once
    // every CACHE_MIN_REFRESH_INTERVAL seconds — index/show pages trigger a
    // background refresh on every page load, and without this guard that
    // would hit (potentially slow) feed storage on every single visit.
    if (
        $forceRefresh
        && $cached !== null
        && isset($cached['stats'], $cached['stats_fetched_at'])
        && (int)($cached['stats_version'] ?? 0) >= $statsVersion
    ) {
        $age = time() - (int)$cached['stats_fetched_at'];
        if ($age >= 0 && $age < CACHE_MIN_REFRESH_INTERVAL) {
            return $cached;
        }
    }

    $feedType = str_starts_with($feedId, BOOKS_SUBDIR . '/') ? 'book' : 'podcast';
    $mediaFiles = find_media_files($feedDir, $feedType);

    // Enrich with durations (using the episode cache).
    $epCache = load_episode_cache($feedId);
    $epCacheChanged = false;
    foreach ($mediaFiles as &$f) {
        $key = $f['path'];
        if (isset($epCache[$key]) && $epCache[$key]['mtime'] === $f['mtime']) {
            $f['duration'] = $epCache[$key]['duration'];
        } else {
            $f['duration'] = audio_duration($f['path']);
            $epCache[$key] = ['mtime' => $f['mtime'], 'duration' => $f['duration']];
            $epCacheChanged = true;
        }
    }
    unset($f);
    if ($epCacheChanged) {
        save_episode_cache($feedId, $epCache);
    }

    $totalSize     = (int)array_sum(array_column($mediaFiles, 'size'));
    $durations     = array_filter(array_column($mediaFiles, 'duration'), fn($d) => $d !== null);
    $totalDuration = !empty($durations) ? (float)array_sum($durations) : null;

    // 'newest_ts' is based on sort_ts. For podcasts, sort_ts resolves to a
    // real filename date when present, otherwise filesystem mtime.
    $newestTs = null;
    $sortTs   = array_column($mediaFiles, 'sort_ts');
    if (!empty($sortTs)) {
        $newestTs = (int)max($sortTs);
    }

    // 'added_ts' is based on the actual filesystem mtime, never on the
    // synthetic per-track pub dates that find_media_files() assigns to
    // dateless filenames (which are anchored to year 2000 and would
    // otherwise report a wildly wrong "N years ago" age). Audiobooks are
    // typically copied onto disk as one single compilation, so this is the
    // only date that is meaningful for them.
    $addedTs   = null;
    $mtimeList = array_filter(array_column($mediaFiles, 'mtime'), fn($t) => $t > 0);
    if (!empty($mtimeList)) {
        $addedTs = (int)max($mtimeList);
    }

    $stats = [
        'count'          => count($mediaFiles),
        'newest_ts'      => $newestTs,
        'added_ts'       => $addedTs,
        'has_content'    => count($mediaFiles) > 0,
        'total_size'     => $totalSize,
        'total_duration' => $totalDuration,
    ];

    $covers = discover_images($feedDir);

    // Proactively copy cover images into the local webserver cache
    // (cache/covers/) so that the index/show pages and RSS feed never have
    // to touch (possibly slow) feed storage to render a cover — only this
    // background/cold-cache scan does, and only once per change.
    foreach ($covers as $coverPath) {
        cache_cover_image($feedId, $coverPath);
    }

    $data = $cached ?? ['found' => false];
    $data['stats']            = $stats;
    $data['stats_version']    = $statsVersion;
    $data['covers']           = $covers;
    $data['episodes']         = $mediaFiles;
    $data['stats_fetched_at'] = time();

    save_metadata_cache($feedId, $data);
    return $data;
}
