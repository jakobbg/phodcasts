<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$failures = [];
$passes = 0;

$assertSame = static function (string $label, $actual, $expected) use (&$failures, &$passes): void {
    if ($actual !== $expected) {
        $failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
        return;
    }
    $passes++;
};

// parse_author_and_title cases
$assertSame(
    'standard author - title split',
    parse_author_and_title('Haruki Murakami - Kafka på stranden'),
    ['author' => 'Haruki Murakami', 'title' => 'Kafka på stranden']
);
$assertSame(
    'norwegian author split',
    parse_author_and_title('Jo Nesbø - Blod på snø'),
    ['author' => 'Jo Nesbø', 'title' => 'Blod på snø']
);
$assertSame(
    'multi-segment title: splits at first dash',
    parse_author_and_title('Arthur C. Clarke - 2001 - A Space Odyssey'),
    ['author' => 'Arthur C. Clarke', 'title' => '2001 - A Space Odyssey']
);
$assertSame(
    'no separator: author is null',
    parse_author_and_title('Kafka på stranden'),
    ['author' => null, 'title' => 'Kafka på stranden']
);
$assertSame(
    'leading dash edge case: no split',
    parse_author_and_title(' - No Author'),
    ['author' => null, 'title' => ' - No Author']
);

// metadata_cache_path: path is inside project cache dir
$cachePath = metadata_cache_path('Podcasts/Test Show');
$assertSame(
    'cache path is inside cache/metadata/',
    str_contains($cachePath, 'cache/metadata/'),
    true
);
$assertSame(
    'cache path ends in .json',
    str_ends_with($cachePath, '.json'),
    true
);

// ── added_ts (books use real mtime, not synthetic pub_ts) ──────────────────
//
// Books without a date in their filenames get synthetic, position-based
// pub_ts/sort_ts values anchored to year 2000 (see find_media_files()). That
// synthetic value must never leak into 'added_ts' — otherwise the age shown
// on the index/show pages would wrongly read "~26 years ago" instead of
// reflecting when the book was actually copied onto disk.
$tmpBookDir = sys_get_temp_dir() . '/fablr_test_book_' . bin2hex(random_bytes(6));
mkdir($tmpBookDir, 0777, true);
$testFeedId = BOOKS_SUBDIR . '/__smoke_test_added_ts_' . bin2hex(random_bytes(6));

try {
    file_put_contents($tmpBookDir . '/01-chapter.mp3', 'not a real mp3, just needs the right extension');
    file_put_contents($tmpBookDir . '/02-chapter.mp3', 'not a real mp3, just needs the right extension');

    $now = time();
    $feedMeta = get_feed_metadata($testFeedId, $tmpBookDir);
    $stats    = $feedMeta['stats'] ?? [];

    $assertSame('added_ts stat is present', array_key_exists('added_ts', $stats), true);

    $addedTs = $stats['added_ts'] ?? null;
    $assertSame('added_ts is close to now (within 5s), not a synthetic year-2000 date',
        $addedTs !== null && abs($now - $addedTs) <= 5,
        true
    );

    $humanAge = $addedTs !== null ? human_age($addedTs) : null;
    $assertSame('human_age(added_ts) does not say "years ago"',
        $humanAge !== null && !str_contains($humanAge, 'year'),
        true
    );

    // Meanwhile the underlying per-episode sort_ts still gets the (unrelated,
    // pre-existing) synthetic year-2000-anchored value for dateless books.
    $episodes = $feedMeta['episodes'] ?? [];
    $assertSame('two episodes found', count($episodes), 2);
    foreach ($episodes as $ep) {
        $assertSame('episode sort_ts is the synthetic year-2000 value (unaffected by this fix)',
            (int)date('Y', $ep['sort_ts']) < 2010,
            true
        );
    }
} finally {
    @unlink($tmpBookDir . '/01-chapter.mp3');
    @unlink($tmpBookDir . '/02-chapter.mp3');
    @rmdir($tmpBookDir);
    @unlink(metadata_cache_path($testFeedId));
    @unlink(episode_cache_path($testFeedId));
}

// ── podcast newest_ts (dateless files should use mtime, not synthetic) ─────
$tmpPodcastDir = sys_get_temp_dir() . '/fablr_test_podcast_newest_' . bin2hex(random_bytes(6));
mkdir($tmpPodcastDir, 0777, true);
$podcastFeedId = PODCASTS_SUBDIR . '/__smoke_test_podcast_newest_' . bin2hex(random_bytes(6));

try {
    file_put_contents($tmpPodcastDir . '/episode-a.mp3', 'not a real mp3, just needs the right extension');
    file_put_contents($tmpPodcastDir . '/episode-b.mp3', 'not a real mp3, just needs the right extension');

    $now = time();
    $feedMeta = get_feed_metadata($podcastFeedId, $tmpPodcastDir);
    $stats    = $feedMeta['stats'] ?? [];
    $episodes = $feedMeta['episodes'] ?? [];

    $newestTs = $stats['newest_ts'] ?? null;
    $assertSame('podcast newest_ts is present', $newestTs !== null, true);
    $assertSame('podcast newest_ts is close to now (within 5s), not synthetic year-2000',
        $newestTs !== null && abs($now - (int)$newestTs) <= 5,
        true
    );

    $assertSame('podcast episodes found', count($episodes), 2);
    foreach ($episodes as $ep) {
        $assertSame('podcast episode pub_ts stays null when filename has no date',
            array_key_exists('pub_ts', $ep) && $ep['pub_ts'] === null,
            true
        );
        $assertSame('podcast episode sort_ts falls back to real mtime',
            isset($ep['mtime'], $ep['sort_ts']) && (int)$ep['sort_ts'] === (int)$ep['mtime'],
            true
        );
    }
} finally {
    @unlink($tmpPodcastDir . '/episode-a.mp3');
    @unlink($tmpPodcastDir . '/episode-b.mp3');
    @rmdir($tmpPodcastDir);
    @unlink(metadata_cache_path($podcastFeedId));
    @unlink(episode_cache_path($podcastFeedId));
}

// ── Cache refresh throttling (only rescan disk once every 30 minutes) ──────
//
// index.phtml / show.phtml trigger a background "refresh=1" request on
// every page load. Without a minimum interval, that would rescan the (often
// slow) feed directory every single time a page is opened.
$tmpThrottleDir = sys_get_temp_dir() . '/fablr_test_throttle_' . bin2hex(random_bytes(6));
mkdir($tmpThrottleDir, 0777, true);
$throttleFeedId = PODCASTS_SUBDIR . '/__smoke_test_refresh_throttle_' . bin2hex(random_bytes(6));

try {
    file_put_contents($tmpThrottleDir . '/episode1.mp3', 'not a real mp3, just needs the right extension');

    // Build the initial cache (one episode).
    $initial = get_feed_metadata($throttleFeedId, $tmpThrottleDir);
    $assertSame('throttle test: initial episode count is 1', (int)$initial['stats']['count'], 1);

    // Add a second file, then force a refresh right away: since the cache is
    // brand new (well within 30 minutes), the rescan must be skipped and the
    // stale count returned unchanged.
    file_put_contents($tmpThrottleDir . '/episode2.mp3', 'not a real mp3, just needs the right extension');
    $tooSoon = get_feed_metadata($throttleFeedId, $tmpThrottleDir, true);
    $assertSame('forceRefresh within 30 minutes does not rescan disk',
        (int)$tooSoon['stats']['count'],
        1
    );

    // Now simulate the cache being older than 30 minutes: forceRefresh must
    // rescan and pick up the second file.
    $cacheData = load_metadata_cache($throttleFeedId);
    $cacheData['stats_fetched_at'] = time() - CACHE_MIN_REFRESH_INTERVAL - 1;
    save_metadata_cache($throttleFeedId, $cacheData);

    $stale = get_feed_metadata($throttleFeedId, $tmpThrottleDir, true);
    $assertSame('forceRefresh after 30 minutes does rescan disk',
        (int)$stale['stats']['count'],
        2
    );
} finally {
    @unlink($tmpThrottleDir . '/episode1.mp3');
    @unlink($tmpThrottleDir . '/episode2.mp3');
    @rmdir($tmpThrottleDir);
    @unlink(metadata_cache_path($throttleFeedId));
    @unlink(episode_cache_path($throttleFeedId));
}

if (!empty($failures)) {
    fwrite(STDERR, "Metadata smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $exp = var_export($f['expected'], true);
        $act = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n  expected: {$exp}\n  actual:   {$act}\n");
    }
    exit(1);
}

echo "Metadata smoke tests passed: {$passes}\n";
