<?php
declare(strict_types=1);

// Podcasts: /mnt/torrents/Podcasts/Podcasts/  Audio Books: /mnt/torrents/Podcasts/Books/

const PODCAST_ROOT    = '/mnt/torrents/Podcasts';
const PODCASTS_SUBDIR = 'Podcasts';
const BOOKS_SUBDIR    = 'Books';
const MAX_ITEMS = 200;

function base_url(): string {
    $https = false;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $https = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $https = true;
    }

    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = (string)$_SERVER['HTTP_X_FORWARDED_HOST'];
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    } else {
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    }

    $scheme = $https ? 'https' : 'http';
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    return $scheme . '://' . $host . $path;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function human_age(?int $ts): ?string {
    if (empty($ts) || $ts <= 0) return null;

    $now = time();
    $delta = $now - $ts;
    if ($delta < 0) $delta = 0;

    $days = (int)floor($delta / 86400);

    if ($days <= 0) return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 14) return $days . ' days ago';

    if ($days < 60) {
        $weeks = (int)floor($days / 7);
        return $weeks . ' ' . ($weeks === 1 ? 'week' : 'weeks') . ' ago';
    }

    if ($days < 730) {
        $months = (int)floor($days / 30);
        return $months . ' ' . ($months === 1 ? 'month' : 'months') . ' ago';
    }

    $years = (int)floor($days / 365);
    return $years . ' ' . ($years === 1 ? 'year' : 'years') . ' ago';
}

/**
 * Returns an array of feed entries. Each entry:
 *   id   – feed parameter value, e.g. "Podcasts/My Show"
 *   name – display name (the leaf folder)
 *   type – 'podcast' | 'book'
 *   dir  – absolute path to the feed directory
 *
 * $filter: 'all' | 'podcasts' | 'books'
 */
function list_podcasts(string $filter = 'all'): array {
    $sources = [];
    if ($filter === 'all' || $filter === 'podcasts') {
        $sources[] = ['subdir' => PODCASTS_SUBDIR, 'type' => 'podcast'];
    }
    if ($filter === 'all' || $filter === 'books') {
        $sources[] = ['subdir' => BOOKS_SUBDIR, 'type' => 'book'];
    }

    $out = [];
    foreach ($sources as $s) {
        $parent = PODCAST_ROOT . DIRECTORY_SEPARATOR . $s['subdir'];
        if (!is_dir($parent) || !is_readable($parent)) continue;

        foreach (scandir($parent) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if ($name[0] === '.') continue;
            $full = $parent . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full) || !is_readable($full)) continue;
            $out[] = [
                'id'   => $s['subdir'] . '/' . $name,
                'name' => $name,
                'type' => $s['type'],
                'dir'  => $full,
            ];
        }
    }

    usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

function resolve_feed_dir(string $feed): ?string {
    // Feed IDs have the form "Podcasts/Name" or "Books/Name".
    $feed = trim($feed);
    if ($feed === '') return null;

    $slash = strpos($feed, '/');
    if ($slash === false || $slash === 0) return null;

    $subdir = substr($feed, 0, $slash);
    $name   = substr($feed, $slash + 1);

    // Validate the category segment.
    if (!in_array($subdir, [PODCASTS_SUBDIR, BOOKS_SUBDIR], true)) return null;

    // Validate the name segment.
    if ($name === '' || $name === '.' || $name === '..') return null;
    if (str_contains($name, '/') || str_contains($name, "\\")) return null;

    $dir = PODCAST_ROOT . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . $name;

    $realRoot   = realpath(PODCAST_ROOT);
    $realParent = realpath(PODCAST_ROOT . DIRECTORY_SEPARATOR . $subdir);
    $realDir    = realpath($dir);

    if ($realRoot === false || $realParent === false || $realDir === false) return null;
    if (!is_dir($realDir) || !is_readable($realDir)) return null;

    // Confirm realDir is exactly one level under realParent.
    if (dirname($realDir) !== $realParent) return null;

    // Confirm realDir is still inside PODCAST_ROOT (defence-in-depth).
    $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $dirCheck   = rtrim($realDir,  DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($dirCheck, $rootPrefix)) return null;

    return $realDir;
}

function allowed_media_mimes(): array {
    return [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'm4b' => 'audio/mp4',
        'mp4' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
    ];
}

function podcast_stats(string $feedDir): array {
    $allowed = allowed_media_mimes();
    $count = 0;
    $newestTs = null;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) continue;
        $ext = strtolower((string)$fi->getExtension());
        if (!isset($allowed[$ext])) continue;

        $count++;

        $path = $fi->getPathname();
        $rel = substr($path, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

        $mtime = @filemtime($path);
        if ($mtime === false) $mtime = 0;

        $pubTs = pubdate_from_filename($rel);
        $sortTs = $pubTs ?? $mtime;
        if ($sortTs > 0 && ($newestTs === null || $sortTs > $newestTs)) {
            $newestTs = $sortTs;
        }
    }

    return [
        'count' => $count,
        'newest_ts' => $newestTs,
    ];
}

function find_media_files(string $feedDir): array {
    $allowed = allowed_media_mimes();

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($feedDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fi) {
        /** @var SplFileInfo $fi */
        if (!$fi->isFile()) continue;
        $ext = strtolower((string)$fi->getExtension());
        if (!isset($allowed[$ext])) continue;

        $path = $fi->getPathname();
        $rel = substr($path, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path);
        if ($size === false) $size = 0;

        $pubTs = pubdate_from_filename($rel);
        $sortTs = $pubTs ?? $mtime;

        $files[] = [
            'path' => $path,
            'rel' => $rel,
            'mtime' => $mtime,
            'pub_ts' => $pubTs,
            'sort_ts' => $sortTs,
            'size' => (int)$size,
            'mime' => $allowed[$ext],
        ];
    }

    usort($files, fn($a, $b) => strnatcasecmp($a['rel'], $b['rel']));
    if (count($files) > MAX_ITEMS) {
        $files = array_slice($files, 0, MAX_ITEMS);
    }

    // For files without a date in the filename, assign synthetic pub dates
    // based on their natural sort position so episode ordering is preserved.
    // Use a base date far in the past and step forward one day per episode.
    $syntheticBase = mktime(12, 0, 0, 1, 1, 2000);
    $syntheticStep = 86400; // one day per episode
    foreach ($files as $i => &$f) {
        if ($f['pub_ts'] === null) {
            $f['pub_ts'] = $syntheticBase + $i * $syntheticStep;
            $f['sort_ts'] = $f['pub_ts'];
        }
    }
    unset($f);

    usort($files, fn($a, $b) => $b['sort_ts'] <=> $a['sort_ts']);

    return $files;
}

function discover_image(string $feedDir): ?string {
    // Per-podcast artwork: check common filenames in priority order.
    foreach (['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png'] as $candidate) {
        $p = $feedDir . DIRECTORY_SEPARATOR . $candidate;
        if (is_file($p) && is_readable($p)) return $p;
    }
    return null;
}

function pubdate_from_filename(string $relPath): ?int {
    // Look for a YYYY-MM-DD date in the filename (e.g. Papaya.2026-02-04.mp3).
    $base = preg_replace('/\.[^.]+$/', '', basename($relPath));
    if (!preg_match('/(\d{4})[-_.](\d{2})[-_.](\d{2})/', $base, $m)) {
        return null;
    }

    $y = (int)$m[1];
    $mo = (int)$m[2];
    $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        sprintf('%04d-%02d-%02d 12:00:00', $y, $mo, $d),
        new DateTimeZone('UTC')
    );
    if ($dt === false) {
        return null;
    }
    return $dt->getTimestamp();
}

function media_url(string $feed, string $relPath): string {
    return base_url() . '?' . http_build_query([
        'action' => 'media',
        'feed' => $feed,
        'file' => $relPath,
    ]);
}

function send_rss(string $feed, string $feedDir): void {
    $base = base_url();
    $self = $base . '?' . http_build_query(['feed' => $feed]);
    $name = basename($feed);

    $items = find_media_files($feedDir);

    // Use the newest sort_ts across all items, not just the first (alphabetical) one.
    $lastBuild = time();
    if (!empty($items)) {
        $lastBuild = max(array_column($items, 'sort_ts'));
    }

    $imgPath = discover_image($feedDir);
    $imgUrl = null;
    if ($imgPath !== null) {
        $rel = substr($imgPath, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $imgUrl = media_url($feed, $rel);
    }

    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";
    echo "  <channel>\n";
    echo "    <title>" . h($name) . "</title>\n";
    echo "    <link>" . h($base) . "</link>\n";
    echo "    <description>" . h("Podcast feed for {$name}") . "</description>\n";
    echo "    <language>no</language>\n";
    echo "    <lastBuildDate>" . gmdate(DATE_RSS, $lastBuild) . "</lastBuildDate>\n";
    echo "    <generator>index.php</generator>\n";
    echo "    <atom:link href=\"" . h($self) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";
    // Required by Apple Podcasts
    echo "    <itunes:author>" . h($name) . "</itunes:author>\n";
    echo "    <itunes:explicit>false</itunes:explicit>\n";
    echo "    <itunes:category text=\"Fiction\" />\n";
    echo "    <itunes:summary>" . h("Podcast feed for {$name}") . "</itunes:summary>\n";
    if ($imgUrl !== null) {
        // Standard RSS image block
        echo "    <image>\n";
        echo "      <url>" . h($imgUrl) . "</url>\n";
        echo "      <title>" . h($name) . "</title>\n";
        echo "      <link>" . h($base) . "</link>\n";
        echo "    </image>\n";
        echo "    <itunes:image href=\"" . h($imgUrl) . "\" />\n";
    }

    foreach ($items as $it) {
        $title = preg_replace('/\\.[^.]+$/', '', basename($it['rel']));
        $enclosure = media_url($feed, $it['rel']);
        // Stable GUID: based only on feed name + relative path, not mtime/size.
        $guid = sha1($feed . '|' . $it['rel']);
        $pubTs = (int)($it['pub_ts'] ?? $it['mtime']);

        echo "    <item>\n";
        echo "      <title>" . h($title) . "</title>\n";
        echo "      <pubDate>" . gmdate(DATE_RSS, $pubTs) . "</pubDate>\n";
        echo "      <guid isPermaLink=\"false\">" . h($guid) . "</guid>\n";
        echo "      <link>" . h($enclosure) . "</link>\n";
        echo "      <description>" . h($title) . "</description>\n";
        echo "      <itunes:title>" . h($title) . "</itunes:title>\n";
        echo "      <itunes:explicit>false</itunes:explicit>\n";
        if ($imgUrl !== null) {
            echo "      <itunes:image href=\"" . h($imgUrl) . "\" />\n";
        }
        echo "      <enclosure url=\"" . h($enclosure) . "\" length=\"" . (int)$it['size'] . "\" type=\"" . h($it['mime']) . "\" />\n";
        echo "    </item>\n";
    }

    echo "  </channel>\n";
    echo "</rss>\n";
}

function guess_mime(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'mp3' => 'audio/mpeg',
        'm4a', 'm4b', 'mp4' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg', 'oga', 'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => 'application/octet-stream',
    };
}

function stream_file(string $feed, string $feedDir, string $rel): void {
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, "..")) {
        http_response_code(400);
        echo "Bad file";
        return;
    }

    $abs = $feedDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $realFeed = realpath($feedDir);
    $realAbs = realpath($abs);
    if ($realFeed === false || $realAbs === false || !is_file($realAbs) || !is_readable($realAbs)) {
        http_response_code(404);
        echo "Not found";
        return;
    }
    $realFeed = rtrim($realFeed, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realAbs, $realFeed)) {
        http_response_code(403);
        echo "Forbidden";
        return;
    }

    $size = filesize($realAbs);
    if ($size === false) {
        http_response_code(500);
        echo "Cannot stat file";
        return;
    }

    $mime = guess_mime($realAbs);
    $mtime = filemtime($realAbs) ?: time();

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');
    $etag = '"' . sha1($realAbs . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);

    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && $inm === $etag) {
        http_response_code(304);
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\\d*)-(\\d*)/i', $range, $m)) {
        $rStart = $m[1] === '' ? null : (int)$m[1];
        $rEnd = $m[2] === '' ? null : (int)$m[2];

        if ($rStart === null && $rEnd !== null) {
            // suffix bytes: last N bytes
            $len = max(0, $rEnd);
            if ($len > 0) {
                $start = max(0, $size - $len);
            }
        } elseif ($rStart !== null) {
            $start = $rStart;
            if ($rEnd !== null) $end = $rEnd;
        }

        if ($start > $end || $start < 0 || $end >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $status = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if ($status === 206) {
        http_response_code(206);
    }

    if ($method === 'HEAD') {
        return;
    }

    $fp = fopen($realAbs, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo "Cannot open";
        return;
    }

    if ($start > 0) {
        fseek($fp, $start);
    }

    $chunk = 1024 * 1024;
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        $buf = fread($fp, $read);
        if ($buf === false) break;
        $remaining -= strlen($buf);
        echo $buf;
        if (function_exists('fastcgi_finish_request')) {
            // no-op; keep streaming
        }
        flush();
    }
    fclose($fp);
}

$action = (string)($_GET['action'] ?? '');
$feed = (string)($_GET['feed'] ?? '');

if ($action === 'media') {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        echo "Unknown feed";
        exit;
    }
    $file = (string)($_GET['file'] ?? '');
    stream_file($feed, $feedDir, $file);
    exit;
}

if ($feed !== '') {
    $feedDir = resolve_feed_dir($feed);
    if ($feedDir === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Unknown feed";
        exit;
    }
    send_rss($feed, $feedDir);
    exit;
}

// Index page
$allowedFilters = ['all', 'podcasts', 'books'];
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, $allowedFilters, true)) $filter = 'all';

$feeds = list_podcasts($filter);
$base = base_url();
header('Content-Type: text/html; charset=UTF-8');

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Podcast &amp; Audiobook Feeds</title>
  <style>
    :root {
      --bg: #0b1220;
      --bg2: #0d1b2e;
      --card: rgba(255,255,255,.08);
      --cardBorder: rgba(255,255,255,.14);
      --text: rgba(255,255,255,.92);
      --muted: rgba(255,255,255,.70);
      --shadow: rgba(0,0,0,.28);
      --accent: #7c3aed;
      --accent2: #06b6d4;
      --radius: 18px;

      --font-sans: ui-sans-serif, system-ui, -apple-system, "SF Pro Text", "Segoe UI Variable", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      --font-serif: ui-serif, "New York", "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
    }

    @media (prefers-color-scheme: light) {
      :root {
        --bg: #f6f7fb;
        --bg2: #eef2ff;
        --card: rgba(255,255,255,.75);
        --cardBorder: rgba(15,23,42,.12);
        --text: rgba(15,23,42,.92);
        --muted: rgba(15,23,42,.66);
        --shadow: rgba(15,23,42,.14);
      }
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: var(--font-sans);
      line-height: 1.45;
      color: var(--text);
      background:
        radial-gradient(1200px 500px at 10% 0%, rgba(124,58,237,.35), transparent 60%),
        radial-gradient(900px 500px at 90% 10%, rgba(6,182,212,.25), transparent 55%),
        linear-gradient(180deg, var(--bg), var(--bg2));
    }

    a { color: inherit; text-decoration: none; }
    a:focus { outline: 2px solid rgba(124,58,237,.55); outline-offset: 3px; border-radius: 10px; }

    .wrap {
      max-width: 980px;
      margin: 0 auto;
      padding: 40px 20px 56px;
    }

    .hero {
      margin-bottom: 22px;
      padding: 22px 22px 18px;
      border: 1px solid var(--cardBorder);
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.06));
      box-shadow: 0 18px 60px var(--shadow);
      border-radius: var(--radius);
      backdrop-filter: blur(10px);
    }

    h1 {
      margin: 0 0 8px;
      font-family: var(--font-serif);
      font-weight: 700;
      letter-spacing: .2px;
      font-size: clamp(28px, 4vw, 40px);
    }

    .sub {
      margin: 0;
      color: var(--muted);
      font-size: 15px;
    }

    code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.95em;
      padding: 0.18rem 0.42rem;
      border-radius: 10px;
      border: 1px solid var(--cardBorder);
      background: rgba(255,255,255,.08);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }

    .card {
      border: 1px solid var(--cardBorder);
      background: var(--card);
      border-radius: var(--radius);
      padding: 16px 16px 14px;
      box-shadow: 0 16px 44px var(--shadow);
      backdrop-filter: blur(10px);
    }

    .cover {
      width: 100%;
      aspect-ratio: 1 / 1;
      object-fit: cover;
      border-radius: 14px;
      border: 1px solid var(--cardBorder);
      display: block;
      margin: 0 0 12px;
      background: rgba(255,255,255,.06);
    }

    .pod-title {
      font-family: var(--font-sans);
      font-weight: 700;
      font-size: 18px;
      margin: 0 0 10px;
      letter-spacing: .2px;
    }

    .meta {
      margin: -4px 0 12px;
      color: var(--muted);
      font-size: 13px;
    }

    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      border-radius: 12px;
      border: 1px solid var(--cardBorder);
      background: rgba(255,255,255,.06);
      color: var(--text);
      font-weight: 650;
      font-size: 14px;
      transition: transform .06s ease, background .15s ease, border-color .15s ease;
      user-select: none;
    }

    .btn:hover { transform: translateY(-1px); background: rgba(255,255,255,.10); }

    .btn.primary {
      border-color: rgba(124,58,237,.55);
      background: linear-gradient(135deg, rgba(124,58,237,.92), rgba(6,182,212,.72));
      color: white;
    }

    .btn.primary:hover { background: linear-gradient(135deg, rgba(124,58,237,1), rgba(6,182,212,.85)); }

    .footer {
      margin-top: 18px;
      color: var(--muted);
      font-size: 14px;
    }

    .divider {
      height: 1px;
      margin: 18px 0 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.22), transparent);
    }

    .filter-bar {
      display: flex;
      gap: 6px;
      margin-bottom: 16px;
    }

    .filter-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 999px;
      border: 1px solid var(--cardBorder);
      background: rgba(255,255,255,.06);
      color: var(--muted);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: background .15s ease, color .15s ease, border-color .15s ease;
    }

    .filter-btn:hover {
      background: rgba(255,255,255,.12);
      color: var(--text);
    }

    .filter-btn.active {
      background: linear-gradient(135deg, rgba(124,58,237,.85), rgba(6,182,212,.65));
      border-color: transparent;
      color: #fff;
    }

    .type-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .4px;
      text-transform: uppercase;
      padding: 2px 8px;
      border-radius: 999px;
      margin-bottom: 8px;
    }

    .type-badge.podcast {
      background: rgba(124,58,237,.22);
      color: #a78bfa;
      border: 1px solid rgba(124,58,237,.35);
    }

    .type-badge.book {
      background: rgba(6,182,212,.18);
      color: #67e8f9;
      border: 1px solid rgba(6,182,212,.30);
    }

    @media (prefers-color-scheme: light) {
      .divider { background: linear-gradient(90deg, transparent, rgba(15,23,42,.18), transparent); }
      .hero { background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.75)); }
      code { background: rgba(15,23,42,.04); }
      .btn { background: rgba(255,255,255,.75); }
      .type-badge.podcast { background: rgba(124,58,237,.10); color: #6d28d9; border-color: rgba(124,58,237,.25); }
      .type-badge.book    { background: rgba(6,182,212,.10);  color: #0e7490; border-color: rgba(6,182,212,.25); }
      .filter-btn         { background: rgba(255,255,255,.75); }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header class="hero">
      <h1>Podcast &amp; Audiobook Feeds</h1>
      <p class="sub">Podcasts in <code><?php echo h(PODCAST_ROOT . '/' . PODCASTS_SUBDIR); ?></code> &middot; Audio Books in <code><?php echo h(PODCAST_ROOT . '/' . BOOKS_SUBDIR); ?></code></p>
    </header>

    <?php
      $filterLinks = [
        'all'      => ['label' => '🎙+📚 All',         'title' => 'Show everything'],
        'podcasts' => ['label' => '🎙 Podcasts',        'title' => 'Show podcasts only'],
        'books'    => ['label' => '📚 Audio Books',     'title' => 'Show audio books only'],
      ];
    ?>
    <nav class="filter-bar" aria-label="Content type filter">
      <?php foreach ($filterLinks as $val => $info):
        $href = $base . '?' . http_build_query(['filter' => $val]);
        $active = ($filter === $val) ? ' active' : '';
        $aria = $filter === $val ? ' aria-current="page"' : '';
      ?>
        <a class="filter-btn<?php echo $active; ?>" href="<?php echo h($href); ?>" title="<?php echo h($info['title']); ?>"<?php echo $aria; ?>><?php echo $info['label']; ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if (empty($feeds)): ?>
      <div class="card">No subfolders found (or not readable) for the selected filter.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($feeds as $f):
          $rss = $base . '?' . http_build_query(['feed' => $f['id']]);
          $podcastUrl = preg_replace('#^https?://#i', 'podcast://', $rss);

          $stats = podcast_stats($f['dir']);
          $episodeCount = (int)$stats['count'];

          $newestTs = !empty($stats['newest_ts']) ? (int)$stats['newest_ts'] : null;
          $newestIso = $newestTs !== null ? gmdate('Y-m-d', $newestTs) : null;
          $newestHuman = $newestTs !== null ? human_age($newestTs) : null;

          $coverImgPath = discover_image($f['dir']);
          $coverUrl = $coverImgPath !== null
              ? media_url($f['id'], basename($coverImgPath))
              : null;
          $badgeLabel = $f['type'] === 'book' ? '📚 Audio Book' : '🎙 Podcast';
          $badgeClass = $f['type'] === 'book' ? 'book' : 'podcast';
        ?>
          <section class="card" aria-label="<?php echo h($f['name']); ?>">
            <?php if ($coverUrl !== null): ?>
              <img class="cover" src="<?php echo h($coverUrl); ?>" alt="Cover art for <?php echo h($f['name']); ?>" loading="lazy" />
            <?php endif; ?>
            <span class="type-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
            <div class="pod-title"><?php echo h($f['name']); ?></div>
            <div class="meta">
              <?php echo (int)$episodeCount; ?> episodes<?php if ($newestHuman !== null): ?> &middot; <span title="<?php echo h((string)$newestIso); ?>">Newest: <?php echo h($newestHuman); ?></span><?php endif; ?>
            </div>
            <div class="actions">
              <a class="btn" href="<?php echo h($rss); ?>" aria-label="RSS feed for <?php echo h($f['name']); ?>">Feed</a>
              <a class="btn primary" href="<?php echo h($podcastUrl); ?>" aria-label="Open <?php echo h($f['name']); ?> in Apple Podcasts">Apple Podcasts</a>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="divider"></div>
    <p class="footer">Tip: On desktop, right-click “Feed” → copy link address, then paste into your podcast app’s “Add by URL”. On iOS, tap “Apple Podcasts”.</p>
  </div>
</body>
</html>
