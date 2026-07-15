<?php
declare(strict_types=1);

/**
 * Returns the duration of an audio file in fractional seconds, or null when
 * the format is unsupported or the header cannot be parsed.
 */
function audio_duration(string $path): ?float {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'm4a', 'm4b', 'mp4' => mp4_duration($path),
        'mp3'               => mp3_duration($path),
        'flac'              => flac_duration($path),
        'ogg', 'oga', 'opus' => ogg_duration($path),
        default             => null,
    };
}

function format_duration(?float $secs): string {
    if ($secs === null) return '—';
    $s = (int)round($secs);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $r = $s % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $r)
        : sprintf('%d:%02d', $m, $r);
}

function format_filesize(int $bytes): string {
    if ($bytes >= 1_073_741_824) return number_format($bytes / 1_073_741_824, 1) . ' GB';
    if ($bytes >= 1_048_576)     return number_format($bytes / 1_048_576, 1) . ' MB';
    if ($bytes >= 1_024)         return number_format($bytes / 1_024, 1) . ' KB';
    return $bytes . ' B';
}

function estimate_bitrate_kbps(int $bytes, ?float $duration): ?int {
    if ($duration === null || $duration <= 0) return null;
    return (int)ceil($bytes * 8 / $duration / 1000);
}

// ── MP4 / M4A / M4B ──────────────────────────────────────────────────────────

/**
 * Read the next ISO base media box header at the current file position.
 * Returns ['pos', 'size', 'type', 'hlen', 'data_pos'] or null on failure.
 */
function mp4_next_box(mixed $fh): ?array {
    $pos = ftell($fh);
    $raw = @fread($fh, 8);
    if ($raw === false || strlen($raw) < 8) return null;
    $size = (float)unpack('N', substr($raw, 0, 4))[1];
    $type = substr($raw, 4, 4);
    $hlen = 8;
    if ((int)$size === 1) { // 64-bit extended size
        $ext = @fread($fh, 8);
        if ($ext === false || strlen($ext) < 8) return null;
        $hi   = (float)unpack('N', substr($ext, 0, 4))[1];
        $lo   = (float)unpack('N', substr($ext, 4, 4))[1];
        $size = $hi * 4294967296.0 + $lo;
        $hlen = 16;
    }
    if ($size === 0.0 || $size < $hlen) return null;
    return ['pos' => $pos, 'size' => $size, 'type' => $type, 'hlen' => $hlen, 'data_pos' => $pos + $hlen];
}

/**
 * Scan forward through boxes within $limit bytes looking for $target type.
 * Returns the file position of the found box, or null.
 */
function mp4_find_box(mixed $fh, float $limit, string $target): ?int {
    $start = (float)ftell($fh);
    $end   = $start + $limit;
    while ((float)ftell($fh) < $end) {
        $box = mp4_next_box($fh);
        if ($box === null) return null;
        if ($box['type'] === $target) return (int)$box['pos'];
        $next = $box['pos'] + $box['size'];
        if ($next <= $box['pos']) return null; // infinite loop guard
        if (@fseek($fh, (int)$next) !== 0) return null;
    }
    return null;
}

function mp4_duration(string $path): ?float {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    try {
        $fileSize = (float)(filesize($path) ?: 0);
        if ($fileSize === 0.0) return null;

        // Navigate top-level boxes to find 'moov'.
        $moovPos = mp4_find_box($fh, $fileSize, 'moov');
        if ($moovPos === null) return null;

        // Read moov header to learn its size, then find 'mvhd' inside it.
        fseek($fh, $moovPos);
        $moov = mp4_next_box($fh);
        if ($moov === null) return null;
        $mvhdPos = mp4_find_box($fh, $moov['size'] - $moov['hlen'], 'mvhd');
        if ($mvhdPos === null) return null;

        fseek($fh, $mvhdPos);
        mp4_next_box($fh); // skip header, position at mvhd data
        $version = ord(@fread($fh, 1) ?: "\0");
        @fread($fh, 3); // flags

        if ($version === 1) {
            @fread($fh, 16); // creation + modification time (8+8 bytes)
            $timeScale = (float)unpack('N', @fread($fh, 4) ?: "\0\0\0\0")[1];
            $durHi     = (float)unpack('N', @fread($fh, 4) ?: "\0\0\0\0")[1];
            $durLo     = (float)unpack('N', @fread($fh, 4) ?: "\0\0\0\0")[1];
            $dur       = $durHi * 4294967296.0 + $durLo;
        } else {
            @fread($fh, 8); // creation + modification time (4+4 bytes)
            $timeScale = (float)unpack('N', @fread($fh, 4) ?: "\0\0\0\0")[1];
            $dur       = (float)unpack('N', @fread($fh, 4) ?: "\0\0\0\0")[1];
        }
        if ($timeScale === 0.0) return null;
        return $dur / $timeScale;
    } finally {
        fclose($fh);
    }
}

// ── MP3 ──────────────────────────────────────────────────────────────────────

function mp3_parse_frame_header(string $bytes): ?array {
    if (strlen($bytes) < 4) return null;
    $h = (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    if (($h & 0xFFE00000) !== 0xFFE00000) return null;
    $version       = ($h >> 19) & 0x3; // 3=MPEG1, 2=MPEG2, 0=MPEG2.5, 1=reserved
    $layer         = ($h >> 17) & 0x3; // 1=LayerIII, 2=LayerII, 3=LayerI
    if ($version === 1 || $layer !== 1) return null; // only MPEG Layer III
    $bitrateIdx    = ($h >> 12) & 0xF;
    $sampleRateIdx = ($h >> 10) & 0x3;
    $padding       = ($h >> 9) & 0x1;
    $chanMode      = ($h >> 6) & 0x3; // 3 = mono

    static $br = [
        [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320,0], // MPEG1
        [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160,0],     // MPEG2/2.5
    ];
    static $sr = [
        [44100, 48000, 32000, 0], // MPEG1
        [22050, 24000, 16000, 0], // MPEG2
        [11025, 12000,  8000, 0], // MPEG2.5
    ];

    $vIdx = match ($version) { 3 => 0, 2 => 1, 0 => 2, default => null };
    if ($vIdx === null) return null;

    $bitrate    = ($version === 3 ? $br[0] : $br[1])[$bitrateIdx] ?? 0;
    $sampleRate = $sr[$vIdx][$sampleRateIdx] ?? 0;
    if ($bitrate === 0 || $sampleRate === 0) return null;

    $samplesPerFrame = ($version === 3) ? 1152 : 576;
    $frameSize       = intdiv(144 * $bitrate * 1000, $sampleRate) + $padding;

    // Byte offset from sync word to Xing/VBRI header (4 bytes frame header + side info).
    $sideInfoLen = match (true) {
        $version === 3 && $chanMode !== 3 => 32, // MPEG1, stereo
        $version === 3 && $chanMode === 3 => 17, // MPEG1, mono
        $version !== 3 && $chanMode !== 3 => 17, // MPEG2/2.5, stereo
        default                           => 9,  // MPEG2/2.5, mono
    };

    return [
        'bitrate'           => $bitrate,
        'sample_rate'       => $sampleRate,
        'samples_per_frame' => $samplesPerFrame,
        'frame_size'        => $frameSize,
        'side_info_len'     => $sideInfoLen,
    ];
}

function mp3_duration(string $path): ?float {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    try {
        // Skip ID3v2 tag if present.
        $id3Header = @fread($fh, 10);
        if ($id3Header !== false && strlen($id3Header) === 10 && substr($id3Header, 0, 3) === 'ID3') {
            $b    = unpack('C4', substr($id3Header, 6, 4));
            $id3Size = (($b[1] & 0x7F) << 21) | (($b[2] & 0x7F) << 14) | (($b[3] & 0x7F) << 7) | ($b[4] & 0x7F);
            fseek($fh, 10 + $id3Size);
        } else {
            fseek($fh, 0);
        }
        $audioStart = ftell($fh);

        // Search first 64 KB for a valid MPEG frame sync word.
        $buf = @fread($fh, 65536);
        if ($buf === false) return null;
        $len   = strlen($buf);
        $frame = null;
        $syncOff = null;
        for ($i = 0; $i < $len - 4; $i++) {
            if ((ord($buf[$i]) & 0xFF) === 0xFF && (ord($buf[$i + 1]) & 0xE0) === 0xE0) {
                $candidate = mp3_parse_frame_header(substr($buf, $i, 4));
                if ($candidate !== null) {
                    $frame   = $candidate;
                    $syncOff = $i;
                    break;
                }
            }
        }
        if ($frame === null || $syncOff === null) return null;

        // Check for Xing/Info VBR header (total frame count → accurate duration).
        $xingOff = $syncOff + 4 + $frame['side_info_len'];
        if ($xingOff + 8 <= $len) {
            $tag = substr($buf, $xingOff, 4);
            if ($tag === 'Xing' || $tag === 'Info') {
                $flags       = unpack('N', substr($buf, $xingOff + 4, 4))[1];
                if (($flags & 0x1) && $xingOff + 12 <= $len) {
                    $totalFrames = unpack('N', substr($buf, $xingOff + 8, 4))[1];
                    if ($totalFrames > 0 && $frame['sample_rate'] > 0) {
                        return (float)$totalFrames * $frame['samples_per_frame'] / $frame['sample_rate'];
                    }
                }
            }
        }

        // Fallback: CBR size estimate.
        $fileSize = filesize($path);
        if ($fileSize === false) return null;
        $audioBytes = $fileSize - $audioStart - $syncOff;
        if ($audioBytes <= 0) return null;
        return (float)$audioBytes * 8.0 / ((float)$frame['bitrate'] * 1000.0);
    } finally {
        fclose($fh);
    }
}

// ── FLAC ─────────────────────────────────────────────────────────────────────

function flac_duration(string $path): ?float {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    try {
        if (@fread($fh, 4) !== 'fLaC') return null;
        while (!feof($fh)) {
            $hdr = @fread($fh, 4);
            if ($hdr === false || strlen($hdr) < 4) break;
            $isLast   = (ord($hdr[0]) >> 7) & 0x1;
            $blockType = ord($hdr[0]) & 0x7F;
            $blockLen  = (ord($hdr[1]) << 16) | (ord($hdr[2]) << 8) | ord($hdr[3]);
            if ($blockType === 0 && $blockLen >= 18) { // STREAMINFO
                $data = @fread($fh, $blockLen);
                if ($data === false || strlen($data) < 18) break;
                // sample_rate: bits 80–99 (bytes 10–12, top 20 bits)
                $sr = (ord($data[10]) << 12) | (ord($data[11]) << 4) | (ord($data[12]) >> 4);
                // total_samples: bits 108–143 (36 bits straddling bytes 13–17)
                $tsHi = (float)(ord($data[13]) & 0xF);
                $tsLo = (float)((ord($data[14]) << 24) | (ord($data[15]) << 16) | (ord($data[16]) << 8) | ord($data[17]));
                $total = $tsHi * 4294967296.0 + $tsLo;
                if ($sr === 0) return null;
                return $total / $sr;
            }
            if ($isLast) break;
            @fseek($fh, $blockLen, SEEK_CUR);
        }
        return null;
    } finally {
        fclose($fh);
    }
}

// ── OGG / OPUS / OGA ────────────────────────────────────────────────────────

function ogg_uint64_le(string $bytes): ?float {
    if (strlen($bytes) < 8) return null;
    $parts = unpack('Vlo/Vhi', $bytes);
    if (!is_array($parts)) return null;
    $lo = (float)($parts['lo'] ?? 0);
    $hi = (float)($parts['hi'] ?? 0);
    return $hi * 4294967296.0 + $lo;
}

function ogg_duration(string $path): ?float {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    try {
        $lastGranule = null;
        $opusPreSkip = null;
        $vorbisRate  = null;

        while (!feof($fh)) {
            $header = @fread($fh, 27);
            if ($header === false || strlen($header) === 0) break;
            if (strlen($header) < 27) return null;
            if (substr($header, 0, 4) !== 'OggS') return null;

            $granule = ogg_uint64_le(substr($header, 6, 8));
            if ($granule !== null) {
                $lastGranule = $granule;
            }

            $segCount = ord($header[26]);
            $lacing   = @fread($fh, $segCount);
            if ($lacing === false || strlen($lacing) < $segCount) return null;

            $bodyLen = 0;
            for ($i = 0; $i < $segCount; $i++) {
                $bodyLen += ord($lacing[$i]);
            }

            $body = $bodyLen > 0 ? @fread($fh, $bodyLen) : '';
            if ($body === false || strlen($body) < $bodyLen) return null;

            // Opus identification header. Pre-skip is LE uint16 at bytes 10-11.
            if ($opusPreSkip === null && strlen($body) >= 19 && substr($body, 0, 8) === 'OpusHead') {
                $opusPreSkip = unpack('v', substr($body, 10, 2))[1] ?? 0;
            }

            // Vorbis identification header. Sample rate is LE uint32 at bytes 12-15.
            if ($vorbisRate === null && strlen($body) >= 30 && ord($body[0]) === 1 && substr($body, 1, 6) === 'vorbis') {
                $vorbisRate = unpack('V', substr($body, 12, 4))[1] ?? 0;
            }
        }

        if ($lastGranule === null || $lastGranule <= 0) return null;

        if ($opusPreSkip !== null) {
            $pcm = $lastGranule - (float)$opusPreSkip;
            return $pcm > 0 ? $pcm / 48000.0 : null;
        }

        if ($vorbisRate !== null && $vorbisRate > 0) {
            return $lastGranule / (float)$vorbisRate;
        }

        return null;
    } finally {
        fclose($fh);
    }
}
