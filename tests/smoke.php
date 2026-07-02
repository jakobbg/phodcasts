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

$assertNull = static function (string $label, $actual) use (&$failures, &$passes): void {
    if ($actual !== null) {
        $failures[] = [
            'label' => $label,
            'expected' => null,
            'actual' => $actual,
        ];
        return;
    }
    $passes++;
};

// Episode title normalization (README examples + a few guardrails)
$assertSame('iso date title', episode_title('Papaya.2026-01-19.mp3', 'Papaya'), '19. januar 2026');
$assertSame('season episode title', episode_title('tore.og.haralds.podcast.podme.2026.s09e10.mp3', 'Tore og Harald'), 'Season 9 – Episode 10');
$assertSame('avsnitt title', episode_title('avsnitt042.mp3', 'Show'), 'Avsnitt 42');
$assertSame('compact cd track', episode_title('CD01T05.m4b', 'Book'), 'CD 1, Spor 5');
$assertSame('cd-nnnn title', episode_title('CD-1008.m4b', 'Book'), 'CD 10, Spor 8');
$assertSame('kassett side title', episode_title('Kass1sideB.mp3', 'Tape Show'), 'Kassett 1, Side B');
$assertSame('feed prefix stripping', episode_title('Kafka pa stranden - Episode 00.mp3', 'Kafka pa stranden'), 'Episode 00');
$assertSame('parent cd context track', episode_title('CD1/01 - Track 1.mp3', 'Book'), 'CD 1, Track 1');

// Feed path safety validation (does not depend on actual media folders)
$assertNull('reject empty feed', resolve_feed_dir(''));
$assertNull('reject unknown category', resolve_feed_dir('Movies/Anything'));
$assertNull('reject path traversal style feed', resolve_feed_dir('Podcasts/../secret'));
$assertNull('reject nested slash in name', resolve_feed_dir('Podcasts/a/b'));

if (!empty($failures)) {
    fwrite(STDERR, "Smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $expected = var_export($f['expected'], true);
        $actual = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n");
        fwrite(STDERR, "  expected: {$expected}\n");
        fwrite(STDERR, "  actual:   {$actual}\n");
    }
    exit(1);
}

echo "Smoke tests passed: {$passes}\n";
