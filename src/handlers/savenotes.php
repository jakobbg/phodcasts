<?php
declare(strict_types=1);

/**
 * POST /?action=save_notes&feed=Books/My+Book
 * Body: content=<markdown text>
 *
 * Writes the posted content to cache/notes/{sha1($feed)}.md inside the app's
 * cache directory (always writable by the web server). The show page reads
 * this path as a fallback when no manual notes.md exists in the feed directory.
 * The content is saved as-is (plain text / Markdown); render_markdown() handles
 * safe HTML conversion on display.
 */
function save_notes_handler(string $feed, string $content): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    send_security_headers('metadata');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        return;
    }

    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown feed']);
        return;
    }

    // Basic sanitisation: strip null bytes, enforce reasonable length.
    $content = str_replace("\0", '', $content);
    if (mb_strlen($content, 'UTF-8') > 50000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Content exceeds 50 000 character limit']);
        return;
    }

    // Write to cache/notes/ — always under the app's writable cache directory.
    // This avoids needing write permission to the NAS media directories.
    $cacheDir  = __DIR__ . '/../../cache/notes/';
    if (!ensure_cache_dir(rtrim($cacheDir, '/'))) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Cannot create notes cache directory — check permissions on cache/']);
        return;
    }
    $notesPath = $cacheDir . sha1($feed) . '.md';
    if (file_put_contents($notesPath, $content) === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Write failed — check directory permissions']);
        return;
    }

    echo json_encode(['ok' => true]);
}
