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
