<?php
declare(strict_types=1);

function book_archive_cache_dir(): string {
    return __DIR__ . '/../../cache/archives';
}

function book_archive_base_path(string $feedId): string {
    return book_archive_cache_dir() . '/' . sha1($feedId);
}

function book_archive_progress_path(string $base): string {
    return $base . '.progress.json';
}

function book_archive_files(string $feedDir): array {
    $allowed = allowed_media_mimes();
    $base = rtrim($feedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $files = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) {
            continue;
        }

        $ext = strtolower((string)$fi->getExtension());
        if (!isset($allowed[$ext])) {
            continue;
        }

        $path = $fi->getRealPath() ?: $fi->getPathname();
        if (!str_starts_with($path, $base)) {
            continue;
        }

        $rel = substr($path, strlen($base));
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            continue;
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            $mtime = 0;
        }

        $files[] = [
            'path' => $path,
            'rel' => $rel,
            'size' => (int)$size,
            'mtime' => (int)$mtime,
        ];
    }

    usort($files, fn(array $a, array $b): int => strnatcasecmp($a['rel'], $b['rel']));
    return $files;
}

function book_archive_fingerprint(array $files): string {
    $ctx = hash_init('sha256');
    foreach ($files as $f) {
        hash_update($ctx, $f['rel'] . "\0" . (string)$f['size'] . "\0" . (string)$f['mtime'] . "\n");
    }
    return hash_final($ctx);
}

function load_book_archive_meta(string $metaPath): ?array {
    if (!is_file($metaPath) || !is_readable($metaPath)) {
        return null;
    }

    $raw = @file_get_contents($metaPath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function is_book_archive_fresh(string $archivePath, string $metaPath, string $fingerprint): bool {
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        return false;
    }

    $meta = load_book_archive_meta($metaPath);
    if ($meta === null) {
        return false;
    }

    if (($meta['fingerprint'] ?? '') !== $fingerprint) {
        return false;
    }

    $createdAt = (int)($meta['created_at'] ?? 0);
    if ($createdAt <= 0) {
        return false;
    }

    return (time() - $createdAt) <= BOOK_ARCHIVE_TTL_SECONDS;
}

function is_book_archive_cached_and_unexpired(string $archivePath, string $metaPath): bool {
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        return false;
    }

    $meta = load_book_archive_meta($metaPath);
    if ($meta === null) {
        return false;
    }

    $createdAt = (int)($meta['created_at'] ?? 0);
    if ($createdAt <= 0) {
        return false;
    }

    return (time() - $createdAt) <= BOOK_ARCHIVE_TTL_SECONDS;
}

function is_book_archive_build_in_progress(string $lockPath): bool {
    $fp = fopen($lockPath, 'c');
    if ($fp === false) {
        return false;
    }

    $canLock = @flock($fp, LOCK_EX | LOCK_NB);
    if ($canLock) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    fclose($fp);
    return true;
}

function ensure_book_archive_dir(): bool {
    $dir = book_archive_cache_dir();
    if (is_dir($dir)) {
        return true;
    }

    $root = dirname($dir);
    if (is_dir($root) && !is_writable($root)) {
        @chmod($root, 0777);
    }

    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log(APP_NAME . ': cannot create archive cache dir ' . $dir . ' - check permissions on cache/');
        return false;
    }

    return true;
}

function save_book_archive_progress(string $progressPath, array $progress): void {
    $progress['updated_at'] = time();
    @file_put_contents($progressPath, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function load_book_archive_progress(string $progressPath): ?array {
    if (!is_file($progressPath) || !is_readable($progressPath)) {
        return null;
    }

    $raw = @file_get_contents($progressPath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function clear_book_archive_progress(string $progressPath): void {
    @unlink($progressPath);
}

function write_book_archive(string $archivePath, string $metaPath, string $progressPath, array $files, string $fingerprint): bool {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'ZIP support is not available (ZipArchive extension missing).';
        return false;
    }

    if (!ensure_book_archive_dir()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Archive cache directory is not writable.';
        return false;
    }

    $tmpArchive = $archivePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $zip = new ZipArchive();
    $res = $zip->open($tmpArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        clear_book_archive_progress($progressPath);
        @unlink($tmpArchive);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to create archive.';
        return false;
    }

    $totalBytes = 0;
    foreach ($files as $f) {
        $totalBytes += (int)$f['size'];
    }

    $progress = [
        'stage' => 'building',
        'started_at' => time(),
        'files_total' => count($files),
        'files_done' => 0,
        'bytes_total' => $totalBytes,
        'bytes_done' => 0,
    ];
    save_book_archive_progress($progressPath, $progress);

    foreach ($files as $f) {
        if (!$zip->addFile($f['path'], $f['rel'])) {
            $zip->close();
            clear_book_archive_progress($progressPath);
            @unlink($tmpArchive);
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to add file to archive.';
            return false;
        }
        if (method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName($f['rel'], ZipArchive::CM_STORE);
        }

        $progress['files_done']++;
        $progress['bytes_done'] += (int)$f['size'];
        save_book_archive_progress($progressPath, $progress);
    }

    if (!$zip->close()) {
        clear_book_archive_progress($progressPath);
        @unlink($tmpArchive);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to finalize archive.';
        return false;
    }

    if (!@rename($tmpArchive, $archivePath)) {
        clear_book_archive_progress($progressPath);
        @unlink($tmpArchive);
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to store archive cache.';
        return false;
    }

    $totalSize = 0;
    foreach ($files as $f) {
        $totalSize += (int)$f['size'];
    }

    $meta = [
        'fingerprint' => $fingerprint,
        'created_at' => time(),
        'expires_at' => time() + BOOK_ARCHIVE_TTL_SECONDS,
        'file_count' => count($files),
        'source_total_size' => $totalSize,
    ];
    @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    clear_book_archive_progress($progressPath);

    return true;
}

function sanitize_archive_filename(string $feedName): string {
    $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $feedName);
    $safe = trim((string)$safe);
    if ($safe === '') {
        $safe = 'book';
    }
    return $safe . '.zip';
}

function can_defer_book_archive_build(): bool {
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

function should_defer_book_archive_response(): bool {
    // Requests that explicitly carry a download nonce are real download starts
    // from the UI and must never bounce back into the preparing page.
    if (isset($_GET['dl_nonce']) && trim((string)$_GET['dl_nonce']) !== '') {
        return false;
    }
    return can_defer_book_archive_build();
}

function resolve_book_archive_return_url(string $feed): string {
    $fallback = show_url($feed);
    $raw = trim((string)($_GET['return_to'] ?? ''));
    if ($raw === '') {
        return $fallback;
    }

    if ($raw[0] !== '/' || str_contains($raw, '//') || str_contains($raw, "\n") || str_contains($raw, "\r")) {
        return $fallback;
    }

    return $raw;
}

function finish_book_archive_response(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

function send_book_archive_preparing_page(string $feed, string $returnUrl): void {
    $downloadUrl = app_base_path() . '?' . http_build_query([
        'action' => 'book_archive',
        'feed' => $feed,
    ]);
    $statusUrl = app_base_path() . '?' . http_build_query([
        'action' => 'book_archive_status',
        'feed' => $feed,
    ]);

    http_response_code(202);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 5');
    send_security_headers('html');

    echo '<!doctype html><html lang="en"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Preparing archive</title>'
        . '<style>'
        . 'body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:24px;background:#0b1220;color:#e2e8f0}'
        . '.card{max-width:640px;margin:6vh auto;padding:20px 22px;border:1px solid rgba(255,255,255,.16);border-radius:12px;background:rgba(255,255,255,.06)}'
        . 'h1{margin:0 0 8px;font-size:22px}p{margin:8px 0;color:#cbd5e1;line-height:1.5}'
        . 'a{color:#93c5fd}'
        . '</style></head><body><main class="card">'
        . '<h1>Preparing archive...</h1>'
        . '<p>This can take a while for large books. This page checks build status and starts your download as soon as it is ready.</p>'
        . '<p id="status-text">Checking status...</p>'
        . '<p>If nothing happens, <a href="' . h($downloadUrl) . '">click here to retry now</a>.</p>'
        . '<p><a href="' . h($returnUrl) . '">Return to show page</a></p>'
        . '</main>'
        . '<script>'
        . '(function(){'
        . 'var statusEl=document.getElementById("status-text");'
        . 'var statusUrl=' . json_encode($statusUrl, JSON_UNESCAPED_SLASHES) . ';'
        . 'var downloadUrl=' . json_encode($downloadUrl, JSON_UNESCAPED_SLASHES) . ';'
        . 'var returnUrl=' . json_encode($returnUrl, JSON_UNESCAPED_SLASHES) . ';'
        . 'var started=false;'
        . 'function setText(t){if(statusEl){statusEl.textContent=t;}}'
        . 'function startDownloadAndReturn(){'
        . 'if(started){return;}'
        . 'started=true;'
        . 'setText("Archive ready. Starting download...");'
        . 'var frame=document.getElementById("book-download-frame");'
        . 'if(!frame){frame=document.createElement("iframe");frame.id="book-download-frame";frame.style.display="none";document.body.appendChild(frame);}'
        . 'var redirected=false;'
        . 'function goBack(){if(redirected){return;}redirected=true;window.location.href=returnUrl;}'
        . 'frame.onload=goBack;'
        . 'var sep=downloadUrl.indexOf("?")===-1?"?":"&";'
        . 'frame.src=downloadUrl+sep+"dl_nonce="+Date.now();'
        . 'setTimeout(goBack,20000);'
        . '}'
        . 'function check(){'
        . 'fetch(statusUrl,{cache:"no-store"}).then(function(r){return r.ok?r.json():Promise.reject();}).then(function(d){'
        . 'if(d&&d.ready){startDownloadAndReturn();return;}'
        . 'if(d&&d.building){'
        . 'if(d.progress&&typeof d.progress.percent==="number"){'
        . 'setText("Preparing archive: "+d.progress.percent+"% ("+d.progress.files_done+"/"+d.progress.files_total+" files)");'
        . '}else{setText("Still preparing archive...");}'
        . 'return;}'
        . 'setText("Archive not ready yet. Retrying soon...");'
        . '}).catch(function(){setText("Status check failed. Retrying...");});'
        . '}'
        . 'check();setInterval(check,3000);'
        . '}());'
        . '</script>'
        . '</body></html>';
}

function send_book_archive_status(string $feed): void {
    if (!BOOK_ARCHIVE_ENABLED || !str_starts_with($feed, BOOKS_SUBDIR . '/')) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        send_security_headers('metadata');
        echo '{"ok":false,"error":"not_found"}';
        return;
    }

    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        send_security_headers('metadata');
        echo '{"ok":false,"error":"unknown_feed"}';
        return;
    }

    $base = book_archive_base_path($feed);
    $archivePath = $base . '.zip';
    $metaPath = $base . '.json';
    $lockPath = $base . '.lock';
    $progressPath = book_archive_progress_path($base);

    $ready = is_book_archive_cached_and_unexpired($archivePath, $metaPath);
    $building = !$ready && is_book_archive_build_in_progress($lockPath);
    $progress = null;
    $progressData = load_book_archive_progress($progressPath);
    if (is_array($progressData)) {
        $filesTotal = max(0, (int)($progressData['files_total'] ?? 0));
        $filesDone = max(0, (int)($progressData['files_done'] ?? 0));
        $bytesTotal = max(0, (int)($progressData['bytes_total'] ?? 0));
        $bytesDone = max(0, (int)($progressData['bytes_done'] ?? 0));
        $percent = $filesTotal > 0 ? (int)floor(($filesDone / $filesTotal) * 100) : null;
        if ($percent !== null) {
            $percent = max(0, min(100, $percent));
        }

        $progress = [
            'files_total' => $filesTotal,
            'files_done' => min($filesDone, $filesTotal),
            'bytes_total' => $bytesTotal,
            'bytes_done' => min($bytesDone, $bytesTotal),
            'percent' => $percent,
            'started_at' => (int)($progressData['started_at'] ?? 0),
            'updated_at' => (int)($progressData['updated_at'] ?? 0),
        ];
        if (!$ready && $percent !== null && $percent < 100) {
            $building = true;
        }
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    send_security_headers('metadata');
    $payload = [
        'ok' => true,
        'ready' => $ready,
        'building' => $building,
        'retry_after' => 3,
    ];
    if ($progress !== null) {
        $payload['progress'] = $progress;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function stream_archive_file(string $path, string $downloadName): void {
    $size = @filesize($path);
    if ($size === false || $size <= 0) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Cannot read archive file.';
        return;
    }

    $mtime = @filemtime($path) ?: time();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Accept-Ranges: bytes');
    send_security_headers('media');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $start = 0;
    $end = (int)$size - 1;
    $status = 200;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
        $rStart = $m[1] === '' ? null : (int)$m[1];
        $rEnd = $m[2] === '' ? null : (int)$m[2];

        if ($rStart === null && $rEnd !== null) {
            $len = max(0, $rEnd);
            if ($len > 0) {
                $start = max(0, (int)$size - $len);
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
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Cannot open archive.';
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
        if ($buf === false) {
            break;
        }
        $remaining -= strlen($buf);
        echo $buf;
        flush();
    }

    fclose($fp);
}

function send_book_archive(string $feed): void {
    if (!BOOK_ARCHIVE_ENABLED) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        return;
    }

    if (!str_starts_with($feed, BOOKS_SUBDIR . '/')) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        return;
    }

    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Unknown feed';
        return;
    }

    $returnUrl = resolve_book_archive_return_url($feed);

    $files = book_archive_files($feedDir);
    if ($files === []) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No audio files found';
        return;
    }

    $fingerprint = book_archive_fingerprint($files);
    $base = book_archive_base_path($feed);
    $archivePath = $base . '.zip';
    $metaPath = $base . '.json';
    $lockPath = $base . '.lock';
    $progressPath = book_archive_progress_path($base);

    if (!ensure_book_archive_dir()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Archive cache directory is not writable.';
        return;
    }

    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Cannot lock archive cache.';
        return;
    }

    try {
        if (!flock($lockFp, LOCK_EX)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Cannot lock archive build.';
            return;
        }

        $isFresh = is_book_archive_fresh($archivePath, $metaPath, $fingerprint);
        if (!$isFresh) {
            if (should_defer_book_archive_response()) {
                ignore_user_abort(true);
                send_book_archive_preparing_page($feed, $returnUrl);
                finish_book_archive_response();
                write_book_archive($archivePath, $metaPath, $progressPath, $files, $fingerprint);
                return;
            }

            if (!write_book_archive($archivePath, $metaPath, $progressPath, $files, $fingerprint)) {
                return;
            }
        }
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }

    $downloadName = sanitize_archive_filename(basename($feed));
    stream_archive_file($archivePath, $downloadName);
}
