<?php
declare(strict_types=1);

function first_header_value(string $value): string {
    $parts = explode(',', $value, 2);
    return trim((string)$parts[0]);
}

function normalize_host(string $host): string {
    $host = trim(str_replace(["\r", "\n"], '', $host));
    if ($host === '') {
        return '';
    }
    // Allow hostname/IP literals with optional port.
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        return '';
    }
    return $host;
}

function ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') {
        return false;
    }

    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }

    [$subnet, $prefix] = explode('/', $cidr, 2);
    $subnetBin = @inet_pton($subnet);
    $ipBin = @inet_pton($ip);
    if ($subnetBin === false || $ipBin === false) {
        return false;
    }
    if (strlen($subnetBin) !== strlen($ipBin)) {
        return false;
    }

    $prefixLen = (int)$prefix;
    $maxBits = strlen($subnetBin) * 8;
    if ($prefixLen < 0 || $prefixLen > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefixLen, 8);
    $remainingBits = $prefixLen % 8;

    if ($fullBytes > 0) {
        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = ((0xFF00 >> $remainingBits) & 0xFF);
    $ipByte = ord($ipBin[$fullBytes]);
    $subnetByte = ord($subnetBin[$fullBytes]);
    return ($ipByte & $mask) === ($subnetByte & $mask);
}

function is_trusted_proxy_request(): bool {
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remoteAddr === '') {
        return false;
    }

    foreach (TRUSTED_PROXY_CIDRS as $cidr) {
        if (ip_in_cidr($remoteAddr, (string)$cidr)) {
            return true;
        }
    }
    return false;
}

function base_url(): string {
    $trustedProxy = is_trusted_proxy_request();

    $https = false;
    if ($trustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(first_header_value((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        $https = $proto === 'https';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $https = true;
    }

    $host = '';
    if ($trustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = normalize_host(first_header_value((string)$_SERVER['HTTP_X_FORWARDED_HOST']));
    }
    if ($host === '' && !empty($_SERVER['HTTP_HOST'])) {
        $host = normalize_host((string)$_SERVER['HTTP_HOST']);
    }
    if ($host === '') {
        $host = normalize_host((string)($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($host === '') {
        $host = 'localhost';
    }

    $scheme = $https ? 'https' : 'http';
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($path === '' || $path[0] !== '/') {
        $path = '/index.php';
    }
    return $scheme . '://' . $host . $path;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function human_age(?int $ts): ?string {
    if (empty($ts) || $ts <= 0) {
        return null;
    }

    $now = time();
    $delta = $now - $ts;
    if ($delta < 0) {
        $delta = 0;
    }

    $days = (int)floor($delta / 86400);

    if ($days <= 0) {
        return 'today';
    }
    if ($days === 1) {
        return 'yesterday';
    }
    if ($days < 14) {
        return $days . ' days ago';
    }

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

function media_url(string $feed, string $relPath): string {
    return base_url() . '?' . http_build_query([
        'action' => 'media',
        'feed' => $feed,
        'file' => $relPath,
    ]);
}

/**
 * Emit shared security headers appropriate for a given content type context.
 * $context: 'html' | 'rss' | 'media' | 'asset'
 */
function send_security_headers(string $context = 'html'): void {
    // Prevent MIME-type sniffing on all responses.
    header('X-Content-Type-Options: nosniff');

    if ($context === 'html') {
        // Disallow framing by other origins.
        header('X-Frame-Options: SAMEORIGIN');
        // Minimal CSP: page uses only inline styles + inline script,
        // same-origin images and fetch targets, no plugins or objects.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self'; media-src 'self'; connect-src 'self'; form-action 'none'; base-uri 'self'");
        header('Referrer-Policy: same-origin');
        // Suppress search-engine indexing for a private media server.
        header('X-Robots-Tag: noindex, nofollow');
    }

    if ($context === 'rss') {
        // RSS feeds should not be indexed as web pages.
        header('X-Robots-Tag: noindex, nofollow');
    }
}
