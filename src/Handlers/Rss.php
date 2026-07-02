<?php
declare(strict_types=1);

function send_rss(string $feed, string $feedDir, string $type = 'podcast'): void {
    $base = base_url();
    $self = $base . '?' . http_build_query(['feed' => $feed]);
    $name = basename($feed);

    $items = find_media_files($feedDir, $type);

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
    echo "    <language>" . h(FEED_LANGUAGE) . "</language>\n";
    echo "    <lastBuildDate>" . gmdate(DATE_RSS, $lastBuild) . "</lastBuildDate>\n";
    echo "    <generator>index.php</generator>\n";
    echo "    <atom:link href=\"" . h($self) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";
    // Required by Apple Podcasts
    echo "    <itunes:author>" . h($name) . "</itunes:author>\n";
    echo "    <itunes:explicit>false</itunes:explicit>\n";
    echo "    <itunes:category text=\"" . h($type === 'book' ? 'Fiction' : 'Society &amp; Culture') . "\" />\n";
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
        $title = episode_title($it['rel'], $name);
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
