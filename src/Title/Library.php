<?php
declare(strict_types=1);

/**
 * Strips a feed-name prefix from an episode title base string.
 * Tries both the full feed name and the short title after "Author - ".
 * Separators (spaces, dots, hyphens, underscores, commas) are treated
 * interchangeably when matching.
 */
function strip_feed_prefix(string $base, string $feedName): string {
    $candidates = [$feedName];
    // Also try just the title part after "Author - " (e.g. "Kafka på stranden"
    // extracted from "Haruki Murakami - Kafka på stranden").
    if (preg_match('/^[^-]+-\s*(.+)$/u', $feedName, $sm)) {
        $candidates[] = trim($sm[1]);
    }

    foreach ($candidates as $cand) {
        $words = preg_split('/[\s.\-_,]+/u', trim($cand), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) continue;
        // Build a pattern that allows any separator run between words.
        $pattern = '/^' . implode('[\s.\-_]+', array_map(fn($w) => preg_quote($w, '/'), $words))
                 . '[\s.\-_]+(.*)/ui';
        if (preg_match($pattern, $base, $m)) {
            $stripped = ltrim((string)$m[1], " \t\-–_.,");
            if ($stripped !== '') return $stripped;
        }
    }
    return $base;
}

/**
 * Derives a human-readable episode title from a relative file path.
 */
function episode_title(string $rel, string $feedName): string {
    static $months = [
        1 => 'januar', 2 => 'februar', 3 => 'mars',     4 => 'april',
        5 => 'mai',    6 => 'juni',    7 => 'juli',      8 => 'august',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'desember',
    ];

    $base = preg_replace('/\.[^.]+$/u', '', basename($rel));

    // When files sit in a "CD 1" / "cd01" / "Hodejegerne CD1" sub-folder, we
    // can attach the disc number to titles that lack it.
    $dirPart   = dirname($rel);
    $parentDir = ($dirPart !== '.' && $dirPart !== '') ? basename($dirPart) : '';
    $parentCdNum = null;
    if ($parentDir !== '' && preg_match('/[Cc][Dd]\s*0*(\d+)/u', $parentDir, $pm)) {
        $parentCdNum = (int)$pm[1];
    }

    // Dot-separated filenames (no spaces): replace dots with spaces.
    if (!str_contains($base, ' ') && str_contains($base, '.')) {
        $base = str_replace('.', ' ', $base);
    }
    // Underscore-separated filenames (no spaces): replace underscores with spaces.
    if (!str_contains($base, ' ') && str_contains($base, '_')) {
        $base = str_replace('_', ' ', $base);
    }

    $base = strip_feed_prefix($base, $feedName);
    $t    = trim($base);

    // Season/episode code anywhere in the string: s09e10 -> "Season 9 – Episode 10"
    if (preg_match('/\bs(\d{1,4})e(\d{1,4})\b/i', $t, $m)) {
        return 'Season ' . (int)$m[1] . ' – Episode ' . (int)$m[2];
    }

    // Standalone ISO date after prefix strip: "2026-01-19" -> "19. januar 2026"
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/u', $t, $m)) {
        $mo = (int)$m[2];
        if (isset($months[$mo])) {
            return (int)$m[3] . '. ' . $months[$mo] . ' ' . $m[1];
        }
    }

    if (preg_match('/^avsnitt\s*0*(\d+)$/iu', $t, $m)) {
        return 'Avsnitt ' . (int)$m[1];
    }

    if (preg_match('/^\d+x[Kk]apittelx(\d+)/u', $t, $m)) {
        return 'Kapittel ' . (int)$m[1];
    }

    if (!str_contains($t, ' ') && preg_match('/^\d+x[A-ZÆØÅ]/iu', $t)) {
        $decoded = preg_replace('/^\d+x/u', '', $t);
        $decoded = str_replace('xx', ' – ', $decoded);
        $decoded = str_replace('x', ' ', $decoded);
        $decoded = preg_replace('/\s+/u', ' ', trim($decoded));
        return mb_strtoupper(mb_substr($decoded, 0, 1)) . mb_substr($decoded, 1);
    }

    if (preg_match('/^CD\s*0*(\d+)\s*T\s*0*(\d+)$/iu', $t, $m)) {
        return 'CD ' . (int)$m[1] . ', Spor ' . (int)$m[2];
    }

    if (preg_match('/^CD-(\d{3,4})$/u', $t, $m)) {
        $n  = $m[1];
        [$cd, $tr] = strlen($n) === 3
            ? [(int)substr($n, 0, 1), (int)substr($n, 1, 2)]
            : [(int)substr($n, 0, 2), (int)substr($n, 2, 2)];
        return 'CD ' . $cd . ', Spor ' . $tr;
    }

    if (preg_match('/^(\d+)-Track-([A-Za-z0-9])(\d+)$/iu', $t, $m)) {
        return 'CD ' . (int)$m[3] . ', Track ' . strtoupper($m[2]);
    }

    if (preg_match('/^\d+[\s.\-]+Track\s+(\d+)$/iu', $t, $m)) {
        $tr = (int)$m[1];
        return $parentCdNum !== null ? 'CD ' . $parentCdNum . ', Track ' . $tr : 'Track ' . $tr;
    }

    if (preg_match('/^(\d+)-\d+\s+Spor\s+(\d+)$/iu', $t, $m)) {
        return 'CD ' . (int)$m[1] . ', Spor ' . (int)$m[2];
    }

    if (preg_match('/^\d+\s+Spor\s+(\d+)$/iu', $t, $m)) {
        $sp = (int)$m[1];
        return $parentCdNum !== null ? 'CD ' . $parentCdNum . ', Spor ' . $sp : 'Spor ' . $sp;
    }

    if (preg_match('/^CD\s*0*(\d+)\s*[-–]\s*Spor\s+0*(\d+)$/iu', $t, $m)) {
        return 'CD ' . (int)$m[1] . ', Spor ' . (int)$m[2];
    }

    if (preg_match('/^[Kk]ass\s*0*(\d+)\s*[Ss]ide\s*[aA]?([AaBb])$/iu', $t, $m)) {
        return 'Kassett ' . (int)$m[1] . ', Side ' . strtoupper($m[2]);
    }

    if (preg_match('/^(\d{2})(\d{2})$/u', $t, $m)) {
        $cd = (int)$m[1];
        $tr = (int)$m[2];
        if ($cd > 0 && $tr > 0) {
            return 'CD ' . $cd . ', Spor ' . $tr;
        }
    }

    if (preg_match('/^0*(\d+)$/u', $t, $m)) {
        $n = (int)$m[1];
        return $parentCdNum !== null
            ? 'CD ' . $parentCdNum . ', Spor ' . $n
            : 'Episode ' . $n;
    }

    $base = preg_replace('/^\d+\s*[-.\s]+\s*/u', '', $base);

    if ($parentCdNum !== null) {
        $normParent  = mb_strtolower(preg_replace('/[\s.\-_,]+/u', ' ', $parentDir));
        $normTrimmed = mb_strtolower(preg_replace('/[\s.\-_,]+/u', ' ', trim($base)));
        if (trim($normTrimmed) === trim($normParent)
            || str_starts_with(trim($normTrimmed), trim($normParent))
        ) {
            $origBase = preg_replace('/\.[^.]+$/u', '', basename($rel));
            if (preg_match('/^0*(\d+)/u', $origBase, $pm)) {
                return 'CD ' . $parentCdNum . ', Spor ' . (int)$pm[1];
            }
        }
    }

    $base = trim($base);

    if ($base !== '') {
        $base = mb_strtoupper(mb_substr($base, 0, 1)) . mb_substr($base, 1);
    }

    if ($base === '') {
        $base = preg_replace('/\.[^.]+$/u', '', basename($rel));
    }

    return $base;
}
