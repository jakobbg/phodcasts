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

$assertContains = static function (string $label, string $haystack, string $needle) use (&$failures, &$passes): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = ['label' => $label, 'expected' => "(contains) {$needle}", 'actual' => $haystack];
        return;
    }
    $passes++;
};

$assertNotContains = static function (string $label, string $haystack, string $needle) use (&$failures, &$passes): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = ['label' => $label, 'expected' => "(not contains) {$needle}", 'actual' => $haystack];
        return;
    }
    $passes++;
};

// ── Headings ─────────────────────────────────────────────────────────────────

$assertSame('h1 heading', render_markdown("# Hello"), "<h1>Hello</h1>\n");
$assertSame('h2 heading', render_markdown("## World"), "<h2>World</h2>\n");
$assertSame('h3 heading', render_markdown("### Sub"), "<h3>Sub</h3>\n");

// ── Paragraphs ───────────────────────────────────────────────────────────────

$assertSame('single paragraph', render_markdown("Hello world"), "<p>Hello world</p>\n");
$assertSame('two paragraphs', render_markdown("First\n\nSecond"), "<p>First</p>\n<p>Second</p>\n");

// ── Inline formatting ─────────────────────────────────────────────────────────

$assertContains('bold', render_markdown("**bold**"), '<strong>bold</strong>');
$assertContains('italic', render_markdown("*italic*"), '<em>italic</em>');
$assertContains('bold italic', render_markdown("***both***"), '<strong><em>both</em></strong>');
$assertContains('inline code', render_markdown("`code`"), '<code>code</code>');

// ── Fenced code block ─────────────────────────────────────────────────────────

$codeBlock = render_markdown("```\necho \"hello\";\n```");
$assertContains('code block has pre', $codeBlock, '<pre><code>');
$assertContains('code block content escaped', $codeBlock, 'echo &quot;hello&quot;');

// ── Lists ─────────────────────────────────────────────────────────────────────

$ulOut = render_markdown("- One\n- Two");
$assertContains('unordered list ul tag', $ulOut, '<ul>');
$assertContains('unordered list li tag', $ulOut, '<li>One</li>');

$olOut = render_markdown("1. First\n2. Second");
$assertContains('ordered list ol tag', $olOut, '<ol>');
$assertContains('ordered list li tag', $olOut, '<li>First</li>');

// ── Blockquote ────────────────────────────────────────────────────────────────

$bqOut = render_markdown("> Quote here");
$assertContains('blockquote tag', $bqOut, '<blockquote>');
$assertContains('blockquote content', $bqOut, 'Quote here');

// ── Horizontal rule ───────────────────────────────────────────────────────────

$assertContains('hr from dashes', render_markdown("---"), '<hr>');
$assertContains('hr from stars',  render_markdown("***"), '<hr>');

// ── Links ─────────────────────────────────────────────────────────────────────

$assertContains('valid https link',
    render_markdown('[site](https://example.com)'),
    '<a href="https://example.com">site</a>'
);
$assertContains('relative link allowed',
    render_markdown('[back](/)'),
    '<a href="/">'
);
// javascript: scheme must be stripped
$assertNotContains('javascript link blocked',
    render_markdown('[x](javascript:alert(1))'),
    'href='
);
$assertNotContains('data: link blocked',
    render_markdown('[x](data:text/html,<h1>)'),
    'href='
);

// ── XSS safety ───────────────────────────────────────────────────────────────

$xssInput = "<script>alert('xss')</script>";
$xssOut   = render_markdown($xssInput);
$assertNotContains('script tag stripped from paragraph', $xssOut, '<script>');
$assertContains('script tag escaped in paragraph', $xssOut, '&lt;script&gt;');

$xssHeading = render_markdown("# <img src=x onerror=alert(1)>");
$assertNotContains('img tag stripped from heading', $xssHeading, '<img');
$assertContains('img tag escaped in heading', $xssHeading, '&lt;img');

// ── markdown_safe_url ─────────────────────────────────────────────────────────

$assertSame('https url allowed',       markdown_safe_url('https://example.com'), 'https://example.com');
$assertSame('http url allowed',        markdown_safe_url('http://x.com'),        'http://x.com');
$assertSame('mailto allowed',          markdown_safe_url('mailto:a@b.com'),      'mailto:a@b.com');
$assertSame('absolute path allowed',   markdown_safe_url('/path/to'),            '/path/to');
$assertSame('hash anchor allowed',     markdown_safe_url('#section'),            '#section');
$assertSame('javascript blocked',      markdown_safe_url('javascript:void(0)'),  null);
$assertSame('data uri blocked',        markdown_safe_url('data:text/html,x'),    null);
$assertSame('empty string blocked',    markdown_safe_url(''),                    null);
$assertSame('bare word blocked',       markdown_safe_url('vbscript:x'),          null);

if (!empty($failures)) {
    fwrite(STDERR, "Markdown smoke tests failed: " . count($failures) . "\n");
    foreach ($failures as $f) {
        $exp = var_export($f['expected'], true);
        $act = var_export($f['actual'], true);
        fwrite(STDERR, "- {$f['label']}\n  expected: {$exp}\n  actual:   {$act}\n");
    }
    exit(1);
}

echo "Markdown smoke tests passed: {$passes}\n";
