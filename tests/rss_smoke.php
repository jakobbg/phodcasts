<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$failures = [];
$passes = 0;

$assertTrue = static function (string $label, bool $ok, $actual = null, $expected = true) use (&$failures, &$passes): void {
    if (!$ok) {
        $failures[] = ['label' => $label, 'expected' => $expected, 'actual' => $actual];
        return;
    }
    $passes++;
};

$assertContains = static function (string $label, string $haystack, string $needle) use (&$assertTrue): void {
    $assertTrue($label, str_contains($haystack, $needle), $haystack, $needle);
};

$rmTree = static function (string $dir) use (&$rmTree): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) {
            $rmTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fablr-rss-smoke-' . bin2hex(random_bytes(4));
$feedDir = $tmpRoot . DIRECTORY_SEPARATOR . 'feed';

if (!mkdir($feedDir, 0777, true) && !is_dir($feedDir)) {
    fwrite(STDERR, "RSS smoke tests failed: could not create temp dir\n");
    exit(1);
}

try {
    file_put_contents($feedDir . DIRECTORY_SEPARATOR . 'notes.md', 'Custom smoke description');
    file_put_contents($feedDir . DIRECTORY_SEPARATOR . 'episode-001.mp3', "ID3\0\0\0\0");
    $episodePath = $feedDir . DIRECTORY_SEPARATOR . 'episode-001.mp3';
    $episodeMtime = @filemtime($episodePath) ?: time();
    $expectedItemSummary = 'Added ' . gmdate('Y-m-d', $episodeMtime);

    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    ob_start();
    send_rss('Podcasts/Smoke Feed', $feedDir, 'podcast');
    $xml = (string)ob_get_clean();

    $rssQuip     = APP_NAME . ': ' . APP_QUIP;
    $expectedDesc = 'Custom smoke description. ' . $rssQuip;
    // Channel <description> uses rendered HTML inside CDATA.
    $expectedDescHtml = rtrim(render_markdown('Custom smoke description')) . "\n<p>" . h($rssQuip) . "</p>\n";
    $cdataWrap = static fn(string $s): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';

    $assertContains('channel description carries HTML in CDATA', $xml, '<description>' . $cdataWrap($expectedDescHtml) . '</description>');
    $assertContains('channel itunes subtitle is plain text in CDATA', $xml, '<itunes:subtitle>' . $cdataWrap(mb_substr($expectedDesc, 0, 255, 'UTF-8')) . '</itunes:subtitle>');
    $assertContains('channel itunes summary is plain text in CDATA', $xml, '<itunes:summary>' . $cdataWrap($expectedDesc) . '</itunes:summary>');
    $assertContains('generator uses app name and version', $xml, '<generator>' . h(APP_NAME) . ' ' . h(APP_VERSION) . '</generator>');

    $assertContains('item itunes summary uses Added date in CDATA', $xml, '<itunes:summary>' . $cdataWrap($expectedItemSummary) . '</itunes:summary>');
    $assertContains('item description uses Added date in CDATA', $xml, '<description>' . $cdataWrap($expectedItemSummary) . '</description>');
} finally {
    $rmTree($tmpRoot);
}

if (!empty($failures)) {
    fwrite(STDERR, "RSS smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $exp = var_export($f['expected'], true);
        $act = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n");
        fwrite(STDERR, "  expected: {$exp}\n");
        fwrite(STDERR, "  actual:   {$act}\n");
    }
    exit(1);
}

echo "RSS smoke tests passed: {$passes}\n";
