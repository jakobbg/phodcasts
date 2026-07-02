<?php
declare(strict_types=1);

$requiredPaths = [
    'index.php',
    '.htaccess',
    'config/bootstrap.php',
    'config/constants.php',
    'src/Utils/Web.php',
    'src/Utils/Media.php',
    'src/Feed/Library.php',
    'src/Title/Library.php',
    'src/Handlers/Rss.php',
    'src/Handlers/Media.php',
    'src/Handlers/Assets.php',
    'src/Handlers/IndexPage.php',
    'views/index.phtml',
    'logo.png',
    'og.png',
    'apple-touch-icon.png',
    'favicon.png',
];

$root = dirname(__DIR__);
$missing = [];

foreach ($requiredPaths as $relPath) {
    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($fullPath)) {
        $missing[] = $relPath;
    }
}

if (!empty($missing)) {
    fwrite(STDERR, "Structure smoke tests failed. Missing paths:\n");
    foreach ($missing as $path) {
        fwrite(STDERR, "- {$path}\n");
    }
    exit(1);
}

echo "Structure smoke tests passed: " . count($requiredPaths) . "\n";
