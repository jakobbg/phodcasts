<?php
declare(strict_types=1);

function send_rss(string $feed, string $feedDir, string $type = 'podcast'): void {
    $base = base_url();
    $self = $base . '?' . http_build_query(['feed' => $feed]);
    $name = basename($feed);

    // Use cached metadata to avoid slow filesystem scans on every RSS request.
    $feedMeta = get_feed_metadata($feed, $feedDir);
    $items    = $feedMeta['episodes'] ?? [];
    $imgPath  = $feedMeta['covers'][0] ?? null;

    // Use the newest sort_ts across all items, not just the first (alphabetical) one.
    $lastBuild = time();
    if (!empty($items)) {
        $lastBuild = max(array_column($items, 'sort_ts'));
    }

    $imgUrl = null;
    if ($imgPath !== null) {
        $rel = substr($imgPath, strlen(rtrim($feedDir, DIRECTORY_SEPARATOR)) + 1);
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $imgUrl = media_url($feed, $rel);
    }

    header('Content-Type: application/rss+xml; charset=UTF-8');
    // 5-minute TTL: podcast clients can cache briefly; stale-while-revalidate
    // avoids blocking the client while the fresh feed is fetched in background.
    header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
    send_security_headers('rss');

    // Build feed description: user notes (highest priority) → Open Library → default.
    $feedDesc     = "Podcast feed for {$name}";
    $feedDescHtml = null; // HTML version for <description>; null = fall back to plain text.

    $notesFound = load_feed_notes($feed, $feedDir);

    if ($notesFound !== null) {
        // HTML for <description>: Apple Podcasts renders basic HTML inside CDATA.
        $feedDescHtml = render_markdown($notesFound);
        // Plain text for <itunes:summary>/<itunes:subtitle>: strip Markdown syntax.
        $plain = preg_replace('/^#{1,6}\s+/m', '', $notesFound);
        $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $plain);
        $plain = preg_replace('/[*_`~>]+/', '', $plain);
        $plain = preg_replace('/^[-*+]\s+/m', '', $plain);
        $plain = trim(preg_replace('/\s{2,}/', ' ', str_replace(["\r\n", "\n"], ' ', $plain)));
        if ($plain !== '') $feedDesc = $plain;
    } elseif ($type === 'book' && FETCH_BOOK_METADATA) {
        $bookMeta = fetch_book_metadata($feed, $name);
        if ($bookMeta !== null && !empty($bookMeta['description'])) {
            $feedDesc = (string)$bookMeta['description'];
        }
    }

    // Keep the app quip visible in feed apps without duplicating it.
    $rssQuip = APP_NAME . ': ' . APP_QUIP;
    if (stripos($feedDesc, APP_QUIP) === false && stripos($feedDesc, $rssQuip) === false) {
        $feedDesc = rtrim($feedDesc, ". \t\n\r\0\x0B") . '. ' . $rssQuip;
    }
    if ($feedDescHtml !== null && stripos($feedDescHtml, APP_QUIP) === false) {
        $feedDescHtml = rtrim($feedDescHtml) . "\n<p>" . h($rssQuip) . "</p>\n";
    }
    $feedSubtitle = mb_substr($feedDesc, 0, 255, 'UTF-8');

    // Apple Podcasts requires description fields to be wrapped in CDATA to display
    // correctly. Entity-encoded text (htmlspecialchars) is valid XML but is silently
    // dropped by Apple Podcasts. Escapes any literal "]]>" to keep CDATA well-formed.
    $cdata = static fn(string $s): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";
    echo "  <channel>\n";
    echo "    <title>" . h($name) . "</title>\n";
    echo "    <link>" . h($base) . "</link>\n";
    echo "    <description>" . $cdata($feedDescHtml ?? $feedDesc) . "</description>\n";
    echo "    <language>" . h(FEED_LANGUAGE) . "</language>\n";
    echo "    <lastBuildDate>" . gmdate(DATE_RSS, $lastBuild) . "</lastBuildDate>\n";
    echo "    <generator>" . h(APP_NAME) . " " . h(APP_VERSION) . "</generator>\n";
    echo "    <atom:link href=\"" . h($self) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";
    // Required by Apple Podcasts
    echo "    <itunes:author>" . h($name) . "</itunes:author>\n";
    echo "    <itunes:explicit>false</itunes:explicit>\n";
    echo "    <itunes:category text=\"" . h($type === 'book' ? 'Fiction' : 'Society &amp; Culture') . "\" />\n";
    echo "    <itunes:subtitle>" . $cdata($feedSubtitle) . "</itunes:subtitle>\n";
    echo "    <itunes:summary>" . $cdata($feedDesc) . "</itunes:summary>\n";
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
        $title = episode_title($it['rel'], $name);
        $enclosure = media_url($feed, $it['rel']);
        $itemMtime = (int)($it['mtime'] ?? 0);
        if ($itemMtime <= 0) {
            $itemMtime = (int)($it['pub_ts'] ?? 0);
        }
        if ($itemMtime > 0) {
            $itemSummary = 'Added ' . gmdate('Y-m-d', $itemMtime);
        } else {
            $itemSummary = 'Added: Unknown';
        }
        // Stable GUID: based only on feed name + relative path, not mtime/size.
        $guid = sha1($feed . '|' . $it['rel']);
        $pubTs = (int)($it['pub_ts'] ?? $it['mtime']);

        echo "    <item>\n";
        echo "      <title>" . h($title) . "</title>\n";
        echo "      <pubDate>" . gmdate(DATE_RSS, $pubTs) . "</pubDate>\n";
        echo "      <guid isPermaLink=\"false\">" . h($guid) . "</guid>\n";
        echo "      <link>" . h($enclosure) . "</link>\n";
        echo "      <description>" . $cdata($itemSummary) . "</description>\n";
        echo "      <itunes:title>" . h($title) . "</itunes:title>\n";
        echo "      <itunes:summary>" . $cdata($itemSummary) . "</itunes:summary>\n";
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
