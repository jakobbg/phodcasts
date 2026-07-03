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
