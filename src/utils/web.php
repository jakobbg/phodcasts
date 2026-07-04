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

function app_base_path(): string {
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($path === '' || $path[0] !== '/') {
        $path = '/index.php';
    }
    $dir = rtrim(dirname($path), '/');
    return $dir === '' ? '/' : $dir . '/';
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
    return $scheme . '://' . $host . app_base_path();
}

/**
 * Path to a small on-disk flag that records whether we've ever actually
 * observed a rewritten "show/" or "feed/" URL reach this application.
 */
function rewrite_confirmed_flag_path(): string {
    return __DIR__ . '/../../cache/.rewrite_confirmed';
}

/**
 * Detect if the server environment supports "clean" URLs via URL rewriting.
 *
 * The absence of 'index.php' from the request URI is NOT reliable proof that
 * mod_rewrite/.htaccess is active: Apache's DirectoryIndex serves index.php
 * for the plain "/" request regardless of whether rewriting (and therefore
 * "/show/..." or "/feed/..." routes) actually works. Relying on that alone
 * previously produced clean-looking links on the index page that 404'd on
 * servers where .htaccess is ignored (e.g. AllowOverride None).
 *
 * Instead, only trust clean URLs when we have real evidence: either the
 * current request itself arrived via a rewritten "show/"/"feed/" path, or a
 * previous request already proved rewriting works (recorded in a cache
 * flag). Everything else safely falls back to query-string URLs, which work
 * unconditionally on any server.
 */
function use_clean_urls(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');

    if (!str_contains($path, 'index.php')) {
        $base = app_base_path();
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, '/');
        if (str_starts_with($relative, 'show/') || str_starts_with($relative, 'feed/')) {
            // Direct proof: this request only could have reached us through
            // an active rewrite rule.
            $flag = rewrite_confirmed_flag_path();
            if (!is_file($flag)) {
                @touch($flag);
            }
            return true;
        }
    }

    // No direct proof from the current request (e.g. we're rendering the
    // index page). Fall back to previously-confirmed evidence, if any.
    return is_file(rewrite_confirmed_flag_path());
}

/**
 * Generate a URL to the show details page.
 */
function show_url(string $feedId, array $backParams = []): string {
    $base = app_base_path();

    if (use_clean_urls()) {
        $encodedId = implode('/', array_map('rawurlencode', explode('/', $feedId)));
        $url = $base . 'show/' . $encodedId;
    } else {
        // Fallback for environments without rewriting.
        $url = $base . 'index.php?show=' . rawurlencode($feedId);
    }

    if ($backParams) {
        // Build the return_to URL. If we are not using clean URLs,
        // the return_to should probably also point to index.php.
        if (use_clean_urls()) {
            $returnTo = app_base_path() . ($backParams ? '?' . http_build_query($backParams) : '');
        } else {
            $returnTo = app_base_path() . 'index.php' . ($backParams ? '?' . http_build_query($backParams) : '');
        }
        $url .= (str_contains($url, '?') ? '&' : '?') . 'return_to=' . rawurlencode($returnTo);
    }

    return $url;
}

/**
 * Generate a URL to a feed's RSS XML.
 */
function feed_url(string $feedId, bool $cleanOnly = false): string {
    $base = base_url();

    if ($cleanOnly || use_clean_urls()) {
        $encodedId = implode('/', array_map('rawurlencode', explode('/', $feedId)));
        return $base . 'feed/' . $encodedId;
    }

    return $base . 'index.php?feed=' . rawurlencode($feedId);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_quip_sentence(): string {
    return trim(APP_QUIP);
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

function app_cookie_path(): string {
    return app_base_path();
}

function main_page_auth_cookie_name(): string {
    return APP_NAME . '_main_page_auth';
}

function main_page_auth_cookie_value(string $requiredPassword): string {
    return hash('sha256', APP_NAME . '|main-page|' . $requiredPassword);
}

function is_main_page_authenticated(string $requiredPassword): bool {
    $cookie = (string)($_COOKIE[main_page_auth_cookie_name()] ?? '');
    if ($cookie === '') {
        return false;
    }
    return hash_equals(main_page_auth_cookie_value($requiredPassword), $cookie);
}

function set_main_page_auth_cookie(string $requiredPassword): void {
    setcookie(main_page_auth_cookie_name(), main_page_auth_cookie_value($requiredPassword), [
        'expires' => time() + (86400 * 30),
        'path' => app_cookie_path(),
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function render_main_page_login(string $errorMessage = ''): void {
    $base = base_url();
    $ogImageUrl = $base . 'og.png';
    $iconUrl = $base . 'apple-touch-icon.png';
    $faviconUrl = $base . 'favicon.png';
    $errorMessage = trim($errorMessage);

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    send_security_headers('html');
    require __DIR__ . '/../../views/login.phtml';
}

/**
 * Protect only the main index page using a shared password.
 */
function require_main_page_password(): void {
    $required = trim((string)MAIN_PAGE_PASSWORD);
    if ($required === '') {
        return;
    }

    if (is_main_page_authenticated($required)) {
        return;
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $provided = (string)($_POST['main_page_password'] ?? '');
        if ($provided !== '' && hash_equals($required, $provided)) {
            set_main_page_auth_cookie($required);

            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            if ($requestUri === '' || $requestUri[0] !== '/') {
                $requestUri = app_cookie_path();
            }
            header('Location: ' . $requestUri);
            exit;
        }

        render_main_page_login('Wrong password. Please try again.');
        exit;
    }

    render_main_page_login();
    exit;
}

/**
 * Returns the full SHA of the current git HEAD, or '' if .git is unavailable.
 * Result is cached per-process.
 */
function app_commit_hash(): string {
    static $hash = null;
    if ($hash !== null) return $hash;
    $git = __DIR__ . '/../../.git';
    if (!is_dir($git)) return $hash = '';
    $headFile = $git . '/HEAD';
    if (!is_readable($headFile)) return $hash = '';
    $head = trim((string)file_get_contents($headFile));
    if (str_starts_with($head, 'ref: ')) {
        $refFile = $git . '/' . substr($head, 5);
        if (!is_readable($refFile)) return $hash = '';
        $head = trim((string)file_get_contents($refFile));
    }
    return $hash = (preg_match('/^[0-9a-f]{40}$/', $head) ? $head : '');
}

/**
 * Return an inline SVG that acts as a cover-art placeholder when no image is
 * available.  The full show name is rendered as wrapped text over a vivid
 * radial gradient.  Both hue and the second gradient stop are derived
 * deterministically from $title, so each show gets a unique, stable colour
 * while the spread of hues across all shows is wide and evenly distributed.
 *
 * $cssClass  — space-separated CSS classes applied to the <svg> element.
 *              Defaults to "cover cover-placeholder" so it inherits the same
 *              sizing and border rules as a real <img class="cover">.
 */
function cover_placeholder_svg(string $title, string $altName, string $cssClass = 'cover cover-placeholder'): string
{
    // Deterministic hue from title; second stop shifted +55° for contrast.
    $hue    = abs(crc32($title)) % 360;
    $hue2   = ($hue + 55) % 360;
    $gradId = 'ph-' . abs(crc32($title)); // unique per title, safe for multi-card pages

    // Wrap title into up to 4 lines targeting ~16 chars each.
    // Line count auto-scales with title length so long names always get
    // enough lines instead of overflowing onto one massive last line.
    $words      = preg_split('/\s+/', trim($title), -1, PREG_SPLIT_NO_EMPTY);
    $totalLen   = mb_strlen(implode(' ', $words));
    $lineTarget = 16;
    $maxLines   = min(4, max(1, (int)ceil($totalLen / $lineTarget)));

    $lines   = [];
    $current = '';
    foreach ($words as $word) {
        if ($current === '') {
            $current = $word;
        } elseif (mb_strlen($current . ' ' . $word) <= $lineTarget) {
            $current .= ' ' . $word;
        } elseif (count($lines) < $maxLines - 1) {
            $lines[]  = $current;
            $current  = $word;
        } else {
            $current .= ' ' . $word; // last line: append remaining words
        }
    }
    if ($current !== '') $lines[] = $current;

    // Font size scales with the longest line.
    $maxLen   = max(array_map('mb_strlen', $lines));
    $fontSize = min(34, max(11, (int)round(248 / max($maxLen, 1))));
    $lineH    = (int)round($fontSize * 1.28);
    $n        = count($lines);
    $startY   = (int)round(90 - ($n - 1) * $lineH / 2);

    // Apply SVG textLength on lines that would overflow the usable viewport
    // width (180px − 2×15px padding = 150), regardless of font metrics.
    $safeWidth  = 150;
    $charWRatio = 0.60; // conservative sans-serif bold estimate

    $tspans = '';
    foreach ($lines as $i => $line) {
        $y    = $startY + $i * $lineH;
        $estW = (int)round(mb_strlen($line) * $charWRatio * $fontSize);
        $attr = 'x="90" y="' . $y . '"';
        if ($estW > $safeWidth) {
            $attr .= ' textLength="' . $safeWidth . '" lengthAdjust="spacingAndGlyphs"';
        }
        $tspans .= '<tspan ' . $attr . '>' . h($line) . '</tspan>';
    }

    return '<svg class="' . h($cssClass) . '" viewBox="0 0 180 180"'
         . ' xmlns="http://www.w3.org/2000/svg" role="img"'
         . ' aria-label="' . h('No cover art for ' . $altName) . '">'
         . '<defs>'
         . '<radialGradient id="' . $gradId . '" cx="30%" cy="25%" r="85%">'
         . '<stop offset="0%"   stop-color="hsl(' . $hue  . ',72%,40%)"/>'
         . '<stop offset="100%" stop-color="hsl(' . $hue2 . ',65%,16%)"/>'
         . '</radialGradient>'
         . '</defs>'
         . '<rect width="180" height="180" fill="url(#' . $gradId . ')"/>'
         . '<text x="90" dominant-baseline="central" text-anchor="middle"'
         . ' font-family="ui-sans-serif,system-ui,-apple-system,sans-serif"'
         . ' font-size="' . $fontSize . '" font-weight="700" fill="rgba(255,255,255,0.92)">'
         . $tspans
         . '</text>'
         . '</svg>';
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
        // HSTS: tell browsers to always use HTTPS for 1 year.
        // includeSubDomains omitted intentionally — only covers this origin.
        header('Strict-Transport-Security: max-age=31536000');
        // Minimal CSP: page uses inline styles, inline bootstrap script,
        // and one same-origin external script (js/theme.js).
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self'; media-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'");
        header('Referrer-Policy: same-origin');
        // Suppress search-engine indexing for a private media server.
        header('X-Robots-Tag: noindex, nofollow');
    } elseif ($context === 'rss' || $context === 'media' || $context === 'metadata' || $context === 'asset') {
        // RSS feeds, media and assets should not be indexed as web pages.
        header('X-Robots-Tag: noindex, nofollow');
    }
}
