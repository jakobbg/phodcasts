<?php
declare(strict_types=1);

function send_image_asset(string $baseDir, string $name): void {
    // Serve known static image assets (fallback if the web server doesn't handle .png directly)
    $allowed = ['logo.png', 'og.png', 'apple-touch-icon.png', 'favicon.png'];
    $safeName = basename($name);
    if (!in_array($safeName, $allowed, true)) {
        http_response_code(404);
        return;
    }

    $imgPath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($imgPath) || !is_readable($imgPath)) {
        http_response_code(404);
        return;
    }

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . hash_file('xxh32', $imgPath) . '"');
    readfile($imgPath);
}
