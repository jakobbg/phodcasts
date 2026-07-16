<?php
declare(strict_types=1);

/**
 * Returns an array of feed entries. Each entry:
 *   id   - feed parameter value, e.g. "Podcasts/My Show"
 *   name - display name (the leaf folder)
 *   type - 'podcast' | 'book'
 *   dir  - absolute path to the feed directory
 *
 * $filter: 'all' | 'podcasts' | 'books'
 */
function list_podcasts(string $filter = 'all'): array {
    $sources = [];
    if ($filter === 'all' || $filter === 'podcasts') {
        $sources[] = ['subdir' => PODCASTS_SUBDIR, 'type' => 'podcast'];
    }
    if ($filter === 'all' || $filter === 'books') {
        $sources[] = ['subdir' => BOOKS_SUBDIR, 'type' => 'book'];
    }

    $out = [];
    foreach ($sources as $s) {
        $parent = PODCAST_ROOT . DIRECTORY_SEPARATOR . $s['subdir'];
        if (!is_dir($parent) || !is_readable($parent)) continue;

        foreach (scandir($parent) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if ($name[0] === '.') continue;
            $full = $parent . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full) || !is_readable($full)) continue;
            $realPath = realpath($full);
            // On some environments (like CIFS/Samba), the directory name might be
            // returned as a short 8.3 alias (e.g. "D0SGZS~6"). realpath() usually
            // resolves this to the canonical long name.
            $displayName = ($realPath !== false) ? basename($realPath) : $name;
            $canonical   = ($realPath !== false) ? $realPath : $full;

            $out[] = [
                'id'   => $s['subdir'] . '/' . $displayName,
                'name' => $displayName,
                'type' => $s['type'],
                'dir'  => $canonical,
            ];
        }
    }

    usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

function resolve_feed_dir(string $feed): ?string {
    // Feed IDs have the form "Podcasts/Name" or "Books/Name".
    $feed = trim($feed);
    if ($feed === '') return null;

    $slash = strpos($feed, '/');
    if ($slash === false || $slash === 0) return null;

    $subdir = substr($feed, 0, $slash);
    $name   = substr($feed, $slash + 1);

    // Validate the category segment.
    if (!in_array($subdir, [PODCASTS_SUBDIR, BOOKS_SUBDIR], true)) return null;

    // Validate the name segment.
    if ($name === '' || $name === '.' || $name === '..') return null;
    if ($name[0] === '.') return null;
    if (str_contains($name, '/') || str_contains($name, "\\")) return null;

    $dir = PODCAST_ROOT . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . $name;

    $realRoot   = realpath(PODCAST_ROOT);
    $realParent = realpath(PODCAST_ROOT . DIRECTORY_SEPARATOR . $subdir);
    $realDir    = realpath($dir);

    if ($realRoot === false || $realParent === false || $realDir === false) return null;
    if (!is_dir($realDir) || !is_readable($realDir)) return null;

    // Confirm realDir is exactly one level under realParent.
    if (dirname($realDir) !== $realParent) return null;

    // Confirm realDir is still inside PODCAST_ROOT (defence-in-depth).
    $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $dirCheck   = rtrim($realDir,  DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($dirCheck, $rootPrefix)) return null;

    return $realDir;
}

function podcast_stats(string $feedDir): array {
    $allowed = allowed_media_mimes();
    $count = 0;
    $newestTs = null;
    $hasContent = false;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) continue;
        $ext = strtolower((string)$fi->getExtension());
        if (!isset($allowed[$ext])) continue;

        $count++;

        if ($fi->getSize() > 0) {
            $hasContent = true;
        }

        $path = $fi->getRealPath() ?: $fi->getPathname();
        $rel = substr($path, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

        $mtime = @filemtime($path);
        if ($mtime === false) $mtime = 0;

        $pubTs = pubdate_from_filename($rel);
        $sortTs = $pubTs ?? $mtime;
        if ($sortTs > 0 && ($newestTs === null || $sortTs > $newestTs)) {
            $newestTs = $sortTs;
        }
    }

    return [
        'count'       => $count,
        'newest_ts'   => $newestTs,
        'has_content' => $hasContent,
    ];
}

function find_media_files(string $feedDir, string $type = 'podcast'): array {
    $allowed = allowed_media_mimes();

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) continue;
        $ext = strtolower((string)$fi->getExtension());
        if (!isset($allowed[$ext])) continue;

        $path = $fi->getRealPath() ?: $fi->getPathname();
        $rel = substr($path, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path);
        if ($size === false) $size = 0;

        $pubTs = pubdate_from_filename($rel);
        $sortTs = $pubTs ?? $mtime;

        $files[] = [
            'path' => $path,
            'rel' => $rel,
            'mtime' => $mtime,
            'pub_ts' => $pubTs,
            'sort_ts' => $sortTs,
            'size' => (int)$size,
            'mime' => $allowed[$ext],
        ];
    }

    usort($files, fn($a, $b) => strnatcasecmp($a['rel'], $b['rel']));
    if (count($files) > MAX_ITEMS) {
        $files = array_slice($files, 0, MAX_ITEMS);
    }

    // For books without a date in the filename, assign synthetic pub dates
    // based on their natural sort position so chapter ordering is preserved
    // in podcast clients that always sort newest-first.
    //
    // Podcasts intentionally keep pub_ts = null when no date is present so
    // they fall back to filesystem mtime for both RSS/pubDate and age badges.
    // This avoids misleading synthetic "20+ years ago" labels.
    $syntheticBase = mktime(12, 0, 0, 1, 1, 2000);
    $syntheticStep = 86400; // one day per episode
    $fileCount = count($files);
    foreach ($files as $i => &$f) {
        if ($f['pub_ts'] === null && $type === 'book') {
            // Assign DESCENDING timestamps so that podcast apps, which always
            // sort by pubDate newest-first, present track 1 first.
            $f['pub_ts'] = $syntheticBase + ($fileCount - 1 - $i) * $syntheticStep;
            $f['sort_ts'] = $f['pub_ts'];
        }
    }
    unset($f);

    // Books: keep ascending filename order. Podcasts: newest first.
    if ($type !== 'book') {
        usort($files, static function (array $a, array $b): int {
            $cmp = $b['sort_ts'] <=> $a['sort_ts'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strnatcasecmp($a['rel'], $b['rel']);
        });
    }

    return $files;
}

/**
 * Discover image files in the feed directory and return absolute paths sorted
 * by best cover candidate first (largest pixel area, then file size).
 */
function discover_images(string $feedDir): array {
    if (!is_dir($feedDir) || !is_readable($feedDir)) return [];

    $allowedExt = [
        'jpg' => true,
        'jpeg' => true,
        'png' => true,
        'webp' => true,
        'gif' => true,
    ];

    $candidates = [];
    foreach (scandir($feedDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        if ($name[0] === '.') continue;

        $path = $feedDir . DIRECTORY_SEPARATOR . $name;
        $realPath = realpath($path);
        $path = ($realPath !== false) ? $realPath : $path;
        if (!is_file($path) || !is_readable($path)) continue;

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($allowedExt[$ext])) continue;

        $size = @filesize($path);
        $size = $size === false ? 0 : (int)$size;

        $dim = @getimagesize($path);
        if (!is_array($dim) || empty($dim[0]) || empty($dim[1])) continue;
        $width = (int)$dim[0];
        $height = (int)$dim[1];
        $area = $width * $height;

        $candidates[] = [
            'path' => $path,
            'area' => $area,
            'size' => $size,
            'name' => basename($path),
        ];
    }

    usort($candidates, static function (array $a, array $b): int {
        if ($a['area'] !== $b['area']) return $b['area'] <=> $a['area'];
        if ($a['size'] !== $b['size']) return $b['size'] <=> $a['size'];
        return strnatcasecmp($a['name'], $b['name']);
    });

    return array_values(array_map(static fn(array $c): string => $c['path'], $candidates));
}

function discover_image(string $feedDir): ?string {
    $images = discover_images($feedDir);
    return $images[0] ?? null;
}

function pubdate_from_filename(string $relPath): ?int {
    // Look for a YYYY-MM-DD date in the filename (e.g. Papaya.2026-02-04.mp3).
    $base = preg_replace('/\.[^.]+$/', '', basename($relPath));
    if (!preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $base, $m)) {
        return null;
    }

    $y = (int)$m[1];
    $mo = (int)$m[2];
    $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        sprintf('%04d-%02d-%02d 12:00:00', $y, $mo, $d),
        new DateTimeZone('UTC')
    );
    if ($dt === false) {
        return null;
    }
    return $dt->getTimestamp();
}

/**
 * Returns true as soon as one non-empty audio file is found in the feed
 * directory. Exits early, so it is much faster than podcast_stats().
 * Used to filter placeholder/stub feeds before rendering.
 */
function feed_has_content(string $feedDir): bool {
    $allowed = allowed_media_mimes();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) continue;
        if (!isset($allowed[strtolower((string)$fi->getExtension())])) continue;
        if ($fi->getSize() > 0) return true;
    }
    return false;
}

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
            error_log(APP_NAME . ": cannot create episode cache dir {$dir} — check permissions on cache/");
            return;
        }
    }
    if (file_put_contents($path, json_encode($cache), LOCK_EX) === false) {
        error_log(APP_NAME . ": cannot write episode cache {$path} — check permissions on cache/episodes/");
    }
}
