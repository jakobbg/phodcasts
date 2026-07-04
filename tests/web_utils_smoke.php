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
$originalCookie = $_COOKIE;

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

    $_SERVER = ['SCRIPT_NAME' => '/index.php'];
    $assertSame('cookie path defaults to root', app_cookie_path(), '/');

    $_SERVER = ['SCRIPT_NAME' => '/fablr/index.php'];
    $assertSame('cookie path follows app subdir', app_cookie_path(), '/fablr/');

    $_COOKIE = [];
    $assertSame('missing auth cookie means not authenticated', is_main_page_authenticated('incorrect'), false);

    $_COOKIE = [main_page_auth_cookie_name() => main_page_auth_cookie_value('incorrect')];
    $assertSame('matching auth cookie validates', is_main_page_authenticated('incorrect'), true);

    $_COOKIE = [main_page_auth_cookie_name() => main_page_auth_cookie_value('different')];
    $assertSame('non-matching auth cookie rejects', is_main_page_authenticated('incorrect'), false);

    $_SERVER = [
        'HTTP_HOST' => 'pod.local',
        'SCRIPT_NAME' => '/index.php',
    ];
    $assertSame(
        'media url includes expected query fields',
        media_url('Podcasts/My Show', 'episode 01.mp3'),
        'http://pod.local/?action=media&feed=Podcasts%2FMy+Show&file=episode+01.mp3'
    );

    $_SERVER = [
        'HTTP_HOST' => 'pod.local',
        'SCRIPT_NAME' => '/index.php',
        'REQUEST_URI' => '/index.php',
    ];
    $assertSame('show_url uses fallback when index.php in URI', show_url('Podcasts/Show'), '/index.php?show=Podcasts%2FShow');
    $assertSame('show_url with back params', show_url('Podcasts/Show', ['q' => 'test']), '/index.php?show=Podcasts%2FShow&return_to=%2Findex.php%3Fq%3Dtest');
    $assertSame('feed_url uses fallback when index.php in URI', feed_url('Podcasts/Show'), 'http://pod.local/index.php?feed=Podcasts%2FShow');

    // Absence of 'index.php' from the URI alone is NOT proof that rewriting
    // works (Apache's DirectoryIndex serves index.php for "/" either way), so
    // ambiguous requests like the plain index page must stay on the safe
    // fallback until rewriting has actually been confirmed.
    $flagPath = rewrite_confirmed_flag_path();
    $hadFlag = is_file($flagPath);
    if ($hadFlag) {
        @rename($flagPath, $flagPath . '.smoketest-bak');
    }

    try {
        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/',
        ];
        $assertSame('show_url stays on fallback for ambiguous URI without proof', show_url('Podcasts/Show'), '/index.php?show=Podcasts%2FShow');
        $assertSame('feed_url stays on fallback for ambiguous URI without proof', feed_url('Podcasts/Show'), 'http://pod.local/index.php?feed=Podcasts%2FShow');
        $assertSame('no proof recorded yet', is_file($flagPath), false);

        // A request that actually arrives via a rewritten "/show/..." path is
        // direct proof that mod_rewrite + .htaccess are active.
        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/show/Podcasts/Show',
        ];
        $assertSame('use_clean_urls is true for a confirmed rewritten show request', use_clean_urls(), true);
        $assertSame('confirmation flag is recorded after a proven rewrite', is_file($flagPath), true);

        // Now even the ambiguous index page request can safely offer pretty URLs.
        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/',
        ];
        $assertSame('show_url uses clean path once rewriting is confirmed', show_url('Podcasts/Show'), '/show/Podcasts/Show');
        $assertSame('feed_url uses clean path once rewriting is confirmed', feed_url('Podcasts/Show'), 'http://pod.local/feed/Podcasts/Show');
    } finally {
        @unlink($flagPath);
        if ($hadFlag) {
            @rename($flagPath . '.smoketest-bak', $flagPath);
        }
    }

    // --- .htaccess <-> show_url()/feed_url() round-trip coupling ---
    // The real guarantee that "Details" and RSS links work in production is
    // that the paths generated here are actually matched — and decoded back
    // to the exact same feed id — by the RewriteRule patterns in .htaccess.
    // Testing each side in isolation would not catch a mismatch between them
    // (e.g. a change to the encoding scheme here, or an edited regex there).
    $htaccessPath = __DIR__ . '/../.htaccess';
    $htaccessContent = (string)@file_get_contents($htaccessPath);

    $assertSame(
        '.htaccess still declares the show/ RewriteRule',
        str_contains($htaccessContent, 'RewriteRule ^show/(.+)$ index.php?show=$1'),
        true
    );
    $assertSame(
        '.htaccess still declares the feed/ RewriteRule',
        str_contains($htaccessContent, 'RewriteRule ^feed/(.+)$ index.php?feed=$1'),
        true
    );
    $assertSame(
        '.htaccess still declares the index.php -> / redirect',
        str_contains($htaccessContent, 'RewriteRule ^index\.php$ / [R=301'),
        true
    );

    $roundTripFeedIds = [
        'Podcasts/Show',
        'Podcasts/My Show With Spaces',
        "Books/Author's Novel & Friends",
        'Podcasts/Ünïcödé Show',
    ];

    $hadFlag2 = is_file($flagPath);
    if (!$hadFlag2) {
        @touch($flagPath);
    }
    try {
        foreach ($roundTripFeedIds as $feedId) {
            $_SERVER = [
                'HTTP_HOST' => 'pod.local',
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_URI' => '/',
            ];
            $showPath = ltrim((string)parse_url(show_url($feedId), PHP_URL_PATH), '/');
            $assertSame(
                "show_url({$feedId}) round-trips through the .htaccess show/ pattern",
                (function () use ($showPath, $feedId): string {
                    if (!preg_match('#^show/(.+)$#', $showPath, $m)) {
                        return '<no match>';
                    }
                    return implode('/', array_map('rawurldecode', explode('/', $m[1])));
                })(),
                $feedId
            );

            $feedPath = ltrim((string)parse_url(feed_url($feedId), PHP_URL_PATH), '/');
            $assertSame(
                "feed_url({$feedId}) round-trips through the .htaccess feed/ pattern",
                (function () use ($feedPath, $feedId): string {
                    if (!preg_match('#^feed/(.+)$#', $feedPath, $m)) {
                        return '<no match>';
                    }
                    return implode('/', array_map('rawurldecode', explode('/', $m[1])));
                })(),
                $feedId
            );
        }
    } finally {
        if (!$hadFlag2) {
            @unlink($flagPath);
        }
    }

    // podcast:// links (feed_url with $cleanOnly = true) must always resolve
    // to a bare path with no query string, even when clean-URL rewriting has
    // not been confirmed yet, because native podcast apps drop query strings.
    $_SERVER = [
        'HTTP_HOST' => 'pod.local',
        'SCRIPT_NAME' => '/index.php',
        'REQUEST_URI' => '/index.php',
    ];
    $assertSame(
        'feed_url(cleanOnly=true) ignores fallback mode for podcast:// links',
        feed_url('Podcasts/Show', true),
        'http://pod.local/feed/Podcasts/Show'
    );

    // --- Subdirectory install coverage ---
    // Apps hosted under a subpath (e.g. http://host/fablr/) must keep clean
    // URL detection and generation correctly scoped to that subpath.
    $hadFlag3 = is_file($flagPath);
    if ($hadFlag3) {
        @rename($flagPath, $flagPath . '.smoketest-bak3');
    }
    try {
        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/fablr/index.php',
        ];
        $assertSame('app_base_path resolves subdirectory install', app_base_path(), '/fablr/');

        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/fablr/index.php',
            'REQUEST_URI' => '/fablr/index.php',
        ];
        $assertSame(
            'show_url uses subdirectory fallback when index.php in URI',
            show_url('Podcasts/Show'),
            '/fablr/index.php?show=Podcasts%2FShow'
        );

        // A rewritten request under the subdirectory is still direct proof.
        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/fablr/index.php',
            'REQUEST_URI' => '/fablr/show/Podcasts/Show',
        ];
        $assertSame('use_clean_urls recognizes a confirmed rewrite under a subdirectory', use_clean_urls(), true);
        $assertSame('confirmation flag recorded for subdirectory rewrite', is_file($flagPath), true);

        $_SERVER = [
            'HTTP_HOST' => 'pod.local',
            'SCRIPT_NAME' => '/fablr/index.php',
            'REQUEST_URI' => '/fablr/',
        ];
        $assertSame(
            'show_url uses subdirectory clean path once rewriting is confirmed',
            show_url('Podcasts/Show'),
            '/fablr/show/Podcasts/Show'
        );
        $assertSame(
            'feed_url uses subdirectory clean path once rewriting is confirmed',
            feed_url('Podcasts/Show'),
            'http://pod.local/fablr/feed/Podcasts/Show'
        );
    } finally {
        @unlink($flagPath);
        if ($hadFlag3) {
            @rename($flagPath . '.smoketest-bak3', $flagPath);
        }
    }
} finally {
    $_SERVER = $originalServer;
    $_COOKIE = $originalCookie;
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
