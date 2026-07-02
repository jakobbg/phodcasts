<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$failures = [];
$passes   = 0;

$assertSame = static function (string $label, $actual, $expected) use (&$failures, &$passes): void {
    if ($actual !== $expected) {
        $failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
        return;
    }
    $passes++;
};

$assertTrue = static function (string $label, bool $actual) use (&$failures, &$passes): void {
    if (!$actual) {
        $failures[] = ['label' => $label, 'expected' => true, 'actual' => false];
        return;
    }
    $passes++;
};

// ── ip_in_cidr ───────────────────────────────────────────────────────────────

$assertSame('localhost in 127.0.0.1/32',      ip_in_cidr('127.0.0.1', '127.0.0.1/32'),    true);
$assertSame('127.0.0.2 not in 127.0.0.1/32', ip_in_cidr('127.0.0.2', '127.0.0.1/32'),    false);
$assertSame('192.168.1.5 in 192.168.1.0/24', ip_in_cidr('192.168.1.5', '192.168.1.0/24'), true);
$assertSame('192.168.2.5 not in 192.168.1.0/24', ip_in_cidr('192.168.2.5', '192.168.1.0/24'), false);
$assertSame('10.0.0.1 in 10.0.0.0/8',        ip_in_cidr('10.0.0.1', '10.0.0.0/8'),       true);
$assertSame('172.16.5.1 in 172.16.0.0/12',   ip_in_cidr('172.16.5.1', '172.16.0.0/12'),  true);
$assertSame('172.32.0.1 not in 172.16.0.0/12', ip_in_cidr('172.32.0.1', '172.16.0.0/12'), false);
$assertSame('IPv6 ::1 in ::1/128',            ip_in_cidr('::1', '::1/128'),               true);
$assertSame('IPv6 ::2 not in ::1/128',        ip_in_cidr('::2', '::1/128'),               false);
$assertSame('IPv4 not in IPv6 range',         ip_in_cidr('127.0.0.1', '::1/128'),         false);
$assertSame('empty cidr returns false',       ip_in_cidr('127.0.0.1', ''),                false);
$assertSame('invalid ip returns false',       ip_in_cidr('not-an-ip', '127.0.0.0/8'),     false);
$assertSame('exact IP match without prefix',  ip_in_cidr('10.0.0.1', '10.0.0.1'),         true);
$assertSame('exact IP mismatch without prefix', ip_in_cidr('10.0.0.2', '10.0.0.1'),       false);

// ── normalize_host ───────────────────────────────────────────────────────────

$assertSame('valid hostname passes',           normalize_host('example.com'),      'example.com');
$assertSame('hostname with port passes',       normalize_host('example.com:8080'), 'example.com:8080');
$assertSame('IPv6 literal passes',             normalize_host('[::1]:443'),        '[::1]:443');
$assertSame('newline injection rejected',      normalize_host("evil\nHost: x"),    '');
$assertSame('carriage return injection rejected', normalize_host("evil\rHost: x"), '');
$assertSame('space in host rejected',          normalize_host('evil host.com'),    '');
$assertSame('empty string returns empty',      normalize_host(''),                 '');
$assertSame('leading space trimmed and validated', normalize_host(' example.com'), 'example.com');

// ── first_header_value ───────────────────────────────────────────────────────

$assertSame('single value returned as-is',    first_header_value('https'),          'https');
$assertSame('first of comma list returned',   first_header_value('https, http'),    'https');
$assertSame('whitespace trimmed',             first_header_value(' https , http'),  'https');

// ── h() HTML escaping ────────────────────────────────────────────────────────

$assertSame('ampersand escaped',      h('a & b'),     'a &amp; b');
$assertSame('less-than escaped',      h('<script>'),  '&lt;script&gt;');
$assertSame('double quote escaped',   h('"hello"'),   '&quot;hello&quot;');
$assertSame('single quote escaped',   h("it's"),      'it&#039;s');
$assertSame('clean string unchanged', h('hello'),     'hello');

// ── human_age ────────────────────────────────────────────────────────────────

$now = time();
$assertSame('zero ts returns null',       human_age(0),                 null);
$assertSame('negative ts returns null',   human_age(-1),                null);
$assertSame('today',                      human_age($now - 30),         'today');
$assertSame('yesterday',                  human_age($now - 86400),      'yesterday');
$assertSame('three days ago',             human_age($now - 3 * 86400),  '3 days ago');
$assertSame('two weeks ago',              human_age($now - 14 * 86400), '2 weeks ago');
$assertSame('two months ago',             human_age($now - 60 * 86400), '2 months ago');
$assertSame('two years ago',              human_age($now - 730 * 86400),'2 years ago');

// ── format_duration ──────────────────────────────────────────────────────────

$assertSame('null returns dash',          format_duration(null),   '—');
$assertSame('zero seconds',               format_duration(0.0),    '0:00');
$assertSame('90 seconds → 1:30',          format_duration(90.0),   '1:30');
$assertSame('3600 seconds → 1:00:00',     format_duration(3600.0), '1:00:00');
$assertSame('3661 seconds → 1:01:01',     format_duration(3661.0), '1:01:01');
$assertSame('rounding: 89.6 → 1:30',      format_duration(89.6),   '1:30');

// ── format_filesize ──────────────────────────────────────────────────────────

$assertSame('bytes under 1 KB',           format_filesize(512),         '512 B');
$assertSame('kilobytes',                  format_filesize(2048),        '2.0 KB');
$assertSame('megabytes',                  format_filesize(5 * 1048576), '5.0 MB');
$assertSame('gigabytes',                  format_filesize(1073741824),  '1.0 GB');

// ── estimate_bitrate_kbps ────────────────────────────────────────────────────

$assertSame('null duration returns null',  estimate_bitrate_kbps(1000000, null), null);
$assertSame('zero duration returns null',  estimate_bitrate_kbps(1000000, 0.0),  null);
$assertSame('128 kbps CBR estimate',       estimate_bitrate_kbps(16000000, 1000.0), 128); // 16MB/1000s * 8 = 128kbps

// ── pubdate_from_filename ────────────────────────────────────────────────────

$assertSame('valid date extracted',
    pubdate_from_filename('Show.2026-01-15.mp3'),
    (int)(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')))->getTimestamp()
);
$assertSame('no date returns null',        pubdate_from_filename('episode42.mp3'),         null);
$assertSame('invalid date returns null',   pubdate_from_filename('show.2026-13-01.mp3'),   null);
$assertSame('underscore separator works',  pubdate_from_filename('ep.2025_06_30.mp3'),
    (int)(new DateTimeImmutable('2025-06-30 12:00:00', new DateTimeZone('UTC')))->getTimestamp()
);

// ── allowed_media_mimes / guess_mime ─────────────────────────────────────────

$mimes = allowed_media_mimes();
$assertSame('mp3 in allowed mimes',  isset($mimes['mp3']),  true);
$assertSame('m4b in allowed mimes',  isset($mimes['m4b']),  true);
$assertSame('flac in allowed mimes', isset($mimes['flac']), true);
$assertSame('php not in mimes',      isset($mimes['php']),  false);
$assertSame('guess mp3 mime',        guess_mime('track.mp3'),  'audio/mpeg');
$assertSame('guess m4b mime',        guess_mime('book.m4b'),   'audio/mp4');
$assertSame('guess png mime',        guess_mime('cover.png'),  'image/png');
$assertSame('unknown ext fallback',  guess_mime('file.xyz'),   'application/octet-stream');

// ── audio_duration dispatcher ────────────────────────────────────────────────

$assertSame('ogg returns null (unsupported)', audio_duration('/nonexistent/file.ogg'), null);
$assertSame('wav returns null (unsupported)', audio_duration('/nonexistent/file.wav'), null);
$assertSame('mp3 of nonexistent returns null', audio_duration('/nonexistent/file.mp3'), null);
$assertSame('m4a of nonexistent returns null', audio_duration('/nonexistent/file.m4a'), null);

// ── dot-prefixed feed name rejection ─────────────────────────────────────────

$assertSame('dot-prefixed name rejected', resolve_feed_dir('Podcasts/.hidden'), null);
$assertSame('dot-only name rejected',     resolve_feed_dir('Podcasts/.'),       null);
$assertSame('dot-dot name rejected',      resolve_feed_dir('Podcasts/..'),      null);

// ── episode_cache_path ───────────────────────────────────────────────────────

$cachePath = episode_cache_path('Books/My Book');
$assertSame('episode cache path in cache/episodes/', str_contains($cachePath, 'cache/episodes/'), true);
$assertSame('episode cache path ends in .json',      str_ends_with($cachePath, '.json'),          true);
$assertSame('different feeds have different paths',
    episode_cache_path('Books/Book A') !== episode_cache_path('Books/Book B'),
    true
);

if (!empty($failures)) {
    fwrite(STDERR, "Utils smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $exp = var_export($f['expected'], true);
        $act = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n  expected: {$exp}\n  actual:   {$act}\n");
    }
    exit(1);
}

echo "Utils smoke tests passed: {$passes}\n";
