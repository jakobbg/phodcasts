<?php
declare(strict_types=1);

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

    $size = filesize($realAbs);
    if ($size === false) {
        http_response_code(500);
        echo "Cannot stat file";
        return;
    }

    $mime = guess_mime($realAbs);
    $mtime = filemtime($realAbs) ?: time();

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');
    $etag = '"' . sha1($realAbs . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\\d*)-(\\d*)/i', $range, $m)) {
        $rStart = $m[1] === '' ? null : (int)$m[1];
        $rEnd = $m[2] === '' ? null : (int)$m[2];

        if ($rStart === null && $rEnd !== null) {
            // suffix bytes: last N bytes
            $len = max(0, $rEnd);
            if ($len > 0) {
                $start = max(0, $size - $len);
            }
        } elseif ($rStart !== null) {
            $start = $rStart;
            if ($rEnd !== null) $end = $rEnd;
        }

        if ($start > $end || $start < 0 || $end >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $status = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if ($status === 206) {
        http_response_code(206);
    }

    if ($method === 'HEAD') {
        return;
    }

    $fp = fopen($realAbs, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo "Cannot open";
        return;
    }

    if ($start > 0) {
        fseek($fp, $start);
    }

    $chunk = 1024 * 1024;
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        $buf = fread($fp, $read);
        if ($buf === false) break;
        $remaining -= strlen($buf);
        echo $buf;
        flush();
    }
    fclose($fp);
}
