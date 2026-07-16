<?php
declare(strict_types=1);

function allowed_media_mimes(): array {
    return [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'm4b' => 'audio/mp4',
        'mp4' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
    ];
}

function guess_mime(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = allowed_media_mimes();
    if (isset($mimes[$ext])) {
        return $mimes[$ext];
    }
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => 'application/octet-stream',
    };
}

/**
 * Send a complete HTTP response for a pre-validated, readable file.
 * Handles ETag/304 Not Modified, Range/206 Partial Content, and chunked
 * streaming.  The caller must have already checked the file exists and is
 * readable; this function handles everything from the response headers onward.
 *
 * @param string      $path        Absolute path to the file
 * @param int         $size        File size in bytes (from filesize())
 * @param int         $mtime       File modification time (from filemtime())
 * @param string      $mime        Value for the Content-Type header
 * @param string|null $disposition Value for Content-Disposition, or null to omit
 */
function stream_ranged_response(string $path, int $size, int $mtime, string $mime, ?string $disposition = null): void {
    header('Content-Type: ' . $mime);
    if ($disposition !== null) {
        header('Content-Disposition: ' . $disposition);
    }
    header('Accept-Ranges: bytes');
    send_security_headers('media');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $start  = 0;
    $end    = $size - 1;
    $status = 200;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
        $rStart = $m[1] === '' ? null : (int)$m[1];
        $rEnd   = $m[2] === '' ? null : (int)$m[2];

        if ($rStart === null && $rEnd !== null) {
            $len = max(0, $rEnd);
            if ($len > 0) {
                $start = max(0, $size - $len);
            }
        } elseif ($rStart !== null) {
            $start = $rStart;
            if ($rEnd !== null) {
                $end = $rEnd;
            }
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

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo 'Cannot open file';
        return;
    }

    if ($start > 0) {
        fseek($fp, $start);
    }

    $chunk     = 1024 * 1024;
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        $buf  = fread($fp, $read);
        if ($buf === false) {
            break;
        }
        $remaining -= strlen($buf);
        echo $buf;
        flush();
    }
    fclose($fp);
}
