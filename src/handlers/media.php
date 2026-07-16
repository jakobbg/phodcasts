<?php
declare(strict_types=1);

/**
 * Local, on-disk webserver cache for cover images (as opposed to the
 * metadata JSON cache in cache/metadata/). Covers are read from feed
 * storage (which may be a slow network share) at most once per background
 * refresh; every other request is served straight from this local cache.
 */
function cover_cache_dir(): string {
    return __DIR__ . '/../../cache/covers';
}

function is_cover_file(string $rel): bool {
    $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function cover_cache_path(string $feedId, string $rel): string {
    $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
    $key = sha1($feedId . '|' . basename($rel));
    return cover_cache_dir() . '/' . $key . ($ext !== '' ? '.' . $ext : '');
}

/**
 * Try to serve a cover image straight from the local disk cache.
 * Returns true if the response was fully handled (cache hit, including a
 * 304), false if the caller must fall back to reading from feed storage.
 */
function serve_cached_cover(string $feedId, string $rel): bool {
    if (!is_cover_file($rel)) return false;

    $path = cover_cache_path($feedId, $rel);
    if (!is_file($path) || !is_readable($path)) return false;

    $size  = @filesize($path);
    $mtime = @filemtime($path);
    if ($size === false || $mtime === false) return false;

    header('Content-Type: ' . guess_mime($path));
    header('Accept-Ranges: bytes');
    header('X-Cover-Cache: HIT');
    send_security_headers('media');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        return true;
    }

    header('Content-Length: ' . (string)$size);
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        readfile($path);
    }
    return true;
}

/**
 * Copy a cover image from feed storage into the local webserver cache, so
 * subsequent requests never have to touch the (possibly slow) network share
 * again. Cheap no-op if an identically-sized copy is already cached.
 */
function cache_cover_image(string $feedId, string $absSourcePath): void {
    $rel = basename($absSourcePath);
    if (!is_cover_file($rel) || !is_file($absSourcePath) || !is_readable($absSourcePath)) return;

    $srcSize = @filesize($absSourcePath);
    if ($srcSize === false) return;

    $dest = cover_cache_path($feedId, $rel);
    if (is_file($dest) && @filesize($dest) === $srcSize) {
        return; // already cached and unchanged
    }

    if (!ensure_cache_dir(cover_cache_dir())) {
        return;
    }

    $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@copy($absSourcePath, $tmp)) {
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
        }
    } else {
        @unlink($tmp);
    }
}

function stream_file(string $feed, string $feedDir, string $rel): void {
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, "..")) {
        http_response_code(400);
        echo "Bad file";
        return;
    }

    $abs = $feedDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $realFeed = realpath($feedDir);
    $realAbs = realpath($abs);
    if ($realFeed === false || $realAbs === false || !is_file($realAbs) || !is_readable($realAbs)) {
        http_response_code(404);
        echo "Not found";
        return;
    }
    $realFeed = rtrim($realFeed, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realAbs, $realFeed)) {
        http_response_code(403);
        echo "Forbidden";
        return;
    }

    // Opportunistically populate the local cover cache. This only reaches
    // storage on a cache miss (the caller already checked serve_cached_cover
    // first), so subsequent requests for this cover skip the network share.
    if (is_cover_file($realAbs)) {
        cache_cover_image($feed, $realAbs);
    }

    $size = filesize($realAbs);
    if ($size === false) {
        http_response_code(500);
        echo "Cannot stat file";
        return;
    }

    $mime  = guess_mime($realAbs);
    $mtime = filemtime($realAbs) ?: time();
    stream_ranged_response($realAbs, (int)$size, (int)$mtime, $mime);
}
