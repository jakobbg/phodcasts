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
            $out[] = [
                'id'   => $s['subdir'] . '/' . $name,
                'name' => $name,
                'type' => $s['type'],
                'dir'  => $full,
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

        $path = $fi->getPathname();
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

        $path = $fi->getPathname();
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

    // For files without a date in the filename, assign synthetic pub dates
    // based on their natural sort position so episode ordering is preserved.
    // Use a base date far in the past and step forward one day per episode.
    $syntheticBase = mktime(12, 0, 0, 1, 1, 2000);
    $syntheticStep = 86400; // one day per episode
    $fileCount = count($files);
    foreach ($files as $i => &$f) {
        if ($f['pub_ts'] === null) {
            // Books: assign DESCENDING timestamps so that podcast apps, which
            // always sort by pubDate newest-first, end up presenting track 1
            // first (highest timestamp) and the last track last.
            // Podcasts: ascending timestamps keep the oldest episode oldest.
            $f['pub_ts'] = $type === 'book'
                ? $syntheticBase + ($fileCount - 1 - $i) * $syntheticStep
                : $syntheticBase + $i * $syntheticStep;
            $f['sort_ts'] = $f['pub_ts'];
        }
    }
    unset($f);

    // Books: keep ascending filename order. Podcasts: newest first.
    if ($type !== 'book') {
        usort($files, fn($a, $b) => $b['sort_ts'] <=> $a['sort_ts']);
    }

    return $files;
}

function discover_image(string $feedDir): ?string {
    // Per-podcast artwork: check common filenames in priority order.
    foreach (['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png'] as $candidate) {
        $p = $feedDir . DIRECTORY_SEPARATOR . $candidate;
        if (is_file($p) && is_readable($p)) return $p;
    }
    return null;
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
