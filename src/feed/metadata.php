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
    if (!is_dir($dir)) {
        // Ensure cache root is writable; chmod is a best-effort fix for NAS
        // deployments where the directory is owned by a different user.
        $root = dirname($dir);
        if (is_dir($root) && !is_writable($root)) {
            @chmod($root, 0777);
        }
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log("fablr: cannot create metadata cache dir {$dir} — check permissions on cache/");
            return;
        }
    }
    if (file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        error_log("fablr: cannot write metadata cache {$path} — check permissions on cache/metadata/");
    }
}

function openlibrary_get(string $url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 5,
            'ignore_errors' => true,
            'user_agent'    => 'fablr/1.0 (self-hosted audiobook server)',
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
        return (!empty($cached['found'])) ? $cached : null;
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
