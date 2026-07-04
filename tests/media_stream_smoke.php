<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$failures = [];
$passes = 0;

$assertSame = static function (string $label, $actual, $expected) use (&$failures, &$passes): void {
    if ($actual !== $expected) {
        $failures[] = [
            'label' => $label,
            'expected' => $expected,
            'actual' => $actual,
        ];
        return;
    }
    $passes++;
};

$runStream = static function (string $feedDir, string $rel, ?string $range = null, string $method = 'GET', ?string $ifNoneMatch = null): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($range !== null) {
        $_SERVER['HTTP_RANGE'] = $range;
    } else {
        unset($_SERVER['HTTP_RANGE']);
    }
    if ($ifNoneMatch !== null) {
        $_SERVER['HTTP_IF_NONE_MATCH'] = $ifNoneMatch;
    } else {
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    http_response_code(200);
    ob_start();
    stream_file('test-feed', $feedDir, $rel);
    $body = (string)ob_get_clean();

    return [
        'code' => http_response_code(),
        'body' => $body,
    ];
};

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fablr_smoke_' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Could not create temp directory\n");
    exit(1);
}

$fileContent = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
$fileName = 'sample.bin';
$filePath = $tmpDir . DIRECTORY_SEPARATOR . $fileName;
file_put_contents($filePath, $fileContent);

try {
    $full = $runStream($tmpDir, $fileName);
    $assertSame('full request code', $full['code'], 200);
    $assertSame('full request body', $full['body'], $fileContent);

    $rangeMiddle = $runStream($tmpDir, $fileName, 'bytes=5-9');
    $assertSame('range middle code', $rangeMiddle['code'], 206);
    $assertSame('range middle body', $rangeMiddle['body'], substr($fileContent, 5, 5));

    $rangeSuffix = $runStream($tmpDir, $fileName, 'bytes=-4');
    $assertSame('range suffix code', $rangeSuffix['code'], 206);
    $assertSame('range suffix body', $rangeSuffix['body'], substr($fileContent, -4));

    $realFilePath = realpath($filePath);
    $mtime = $realFilePath !== false ? filemtime($realFilePath) : false;
    $size = $realFilePath !== false ? filesize($realFilePath) : false;
    $etag = '"' . sha1((string)$realFilePath . '|' . (int)$mtime . '|' . (int)$size) . '"';
    $notModified = $runStream($tmpDir, $fileName, null, 'GET', $etag);
    $assertSame('if-none-match code', $notModified['code'], 304);
    $assertSame('if-none-match body empty', $notModified['body'], '');

    $headReq = $runStream($tmpDir, $fileName, null, 'HEAD');
    $assertSame('head request code', $headReq['code'], 200);
    $assertSame('head request body empty', $headReq['body'], '');

    $invalidRange = $runStream($tmpDir, $fileName, 'bytes=999-1000');
    $assertSame('invalid range code', $invalidRange['code'], 416);
    $assertSame('invalid range body empty', $invalidRange['body'], '');

    $badRel = $runStream($tmpDir, '../escape.bin');
    $assertSame('bad rel code', $badRel['code'], 400);
    $assertSame('bad rel body', $badRel['body'], 'Bad file');

    $missing = $runStream($tmpDir, 'missing.bin');
    $assertSame('missing file code', $missing['code'], 404);
    $assertSame('missing file body', $missing['body'], 'Not found');
} finally {
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    @rmdir($tmpDir);
}

// --- Local cover cache (cache/covers/) ---
// Cover images must be copied into cache/covers/ on first read from feed
// storage, then served straight from there on every later request, without
// touching the (possibly slow) feed directory again.
$coverFeedId = 'test-feed-cover-' . bin2hex(random_bytes(4));
$coverTmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fablr_smoke_cover_' . bin2hex(random_bytes(4));
if (!mkdir($coverTmpDir, 0700, true) && !is_dir($coverTmpDir)) {
    fwrite(STDERR, "Could not create cover temp directory\n");
    exit(1);
}
$coverName = 'cover.jpg';
$coverPath = $coverTmpDir . DIRECTORY_SEPARATOR . $coverName;
$coverContentV1 = str_repeat('JPEGDATA-v1-', 10);
file_put_contents($coverPath, $coverContentV1);
$cachedCoverPath = cover_cache_path($coverFeedId, $coverName);

try {
    $assertSame('cover not cached yet', serve_cached_cover($coverFeedId, $coverName), false);
    $assertSame('no cache file before first read', is_file($cachedCoverPath), false);

    // First read from "storage" populates the local cache as a side effect.
    ob_start();
    stream_file($coverFeedId, $coverTmpDir, $coverName);
    ob_end_clean();

    $assertSame('cover cache file created after first read', is_file($cachedCoverPath), true);
    $assertSame('cached cover content matches source', (string)@file_get_contents($cachedCoverPath), $coverContentV1);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_RANGE'], $_SERVER['HTTP_IF_NONE_MATCH']);
    http_response_code(200);
    ob_start();
    $hit = serve_cached_cover($coverFeedId, $coverName);
    $hitBody = (string)ob_get_clean();
    $assertSame('cover served from cache', $hit, true);
    $assertSame('cover cache hit code', http_response_code(), 200);
    $assertSame('cover cache hit body matches source', $hitBody, $coverContentV1);

    // A conditional request with a matching ETag against the cached copy
    // must return 304, same as the direct-storage path.
    $cachedSize  = (int)filesize($cachedCoverPath);
    $cachedMtime = (int)filemtime($cachedCoverPath);
    $coverEtag = '"' . sha1($cachedCoverPath . '|' . $cachedMtime . '|' . $cachedSize) . '"';
    $_SERVER['HTTP_IF_NONE_MATCH'] = $coverEtag;
    http_response_code(200);
    ob_start();
    $notModifiedHit = serve_cached_cover($coverFeedId, $coverName);
    ob_end_clean();
    unset($_SERVER['HTTP_IF_NONE_MATCH']);
    $assertSame('cover cache 304 on matching etag', $notModifiedHit, true);
    $assertSame('cover cache 304 code', http_response_code(), 304);

    // If the source image changes (different size), the next storage read
    // must refresh the local cache rather than keep serving a stale copy.
    $coverContentV2 = str_repeat('JPEGDATA-v2-longer-', 10);
    file_put_contents($coverPath, $coverContentV2);
    ob_start();
    stream_file($coverFeedId, $coverTmpDir, $coverName);
    ob_end_clean();
    $assertSame('cover cache refreshed after source change', (string)@file_get_contents($cachedCoverPath), $coverContentV2);

    // Non-image files must never be written into the cover cache.
    $binFeedId = 'test-feed-bin-' . bin2hex(random_bytes(4));
    $binName = 'sample.bin';
    $binPath = $coverTmpDir . DIRECTORY_SEPARATOR . $binName;
    file_put_contents($binPath, 'not-an-image');
    ob_start();
    stream_file($binFeedId, $coverTmpDir, $binName);
    ob_end_clean();
    $assertSame('non-cover extension is never cached', is_file(cover_cache_path($binFeedId, $binName)), false);
    @unlink($binPath);
} finally {
    @unlink($cachedCoverPath);
    if (is_file($coverPath)) {
        @unlink($coverPath);
    }
    @rmdir($coverTmpDir);
}

if (!empty($failures)) {
    fwrite(STDERR, "Media smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $expected = var_export($f['expected'], true);
        $actual = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n");
        fwrite(STDERR, "  expected: {$expected}\n");
        fwrite(STDERR, "  actual:   {$actual}\n");
    }
    exit(1);
}

echo "Media smoke tests passed: {$passes}\n";
