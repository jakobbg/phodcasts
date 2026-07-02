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

$originalServer = $_SERVER;

$runBaseUrl = static function (array $server): string {
    $_SERVER = $server;
    return base_url();
};

try {
    $assertSame(
        'trusted proxy uses forwarded proto and host',
        $runBaseUrl([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'proxy.example.com',
            'HTTP_HOST' => 'ignored.example.com',
            'SCRIPT_NAME' => '/index.php',
        ]),
        'https://proxy.example.com/'
    );

    $assertSame(
        'untrusted proxy headers are ignored',
        $runBaseUrl([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'proxy.example.com',
            'HTTP_HOST' => 'public.example.com',
            'SCRIPT_NAME' => '/index.php',
        ]),
        'http://public.example.com/'
    );

    $assertSame(
        'https server var respected when no forwarded proto',
        $runBaseUrl([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'local.example.test',
            'SCRIPT_NAME' => '/app/index.php',
        ]),
        'https://local.example.test/app/'
    );

    $assertSame(
        'fallback to server name and default script path',
        $runBaseUrl([
            'SERVER_NAME' => 'fallback.local',
        ]),
        'http://fallback.local/'
    );

    $_SERVER = [
        'HTTP_HOST' => 'pod.local',
        'SCRIPT_NAME' => '/index.php',
    ];
    $assertSame(
        'media url includes expected query fields',
        media_url('Podcasts/My Show', 'episode 01.mp3'),
        'http://pod.local/?action=media&feed=Podcasts%2FMy+Show&file=episode+01.mp3'
    );
} finally {
    $_SERVER = $originalServer;
}

if (!empty($failures)) {
    fwrite(STDERR, "Web utils smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $expected = var_export($f['expected'], true);
        $actual = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n");
        fwrite(STDERR, "  expected: {$expected}\n");
        fwrite(STDERR, "  actual:   {$actual}\n");
    }
    exit(1);
}

echo "Web utils smoke tests passed: {$passes}\n";
