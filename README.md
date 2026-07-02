# phodcasts

<p align="center">
  <img src="logo.png" alt="phodcasts logo" width="160">
</p>

A lightweight PHP server that turns a folder of audio files into podcast-app-compatible RSS feeds. Point it at a directory, and every subfolder becomes a subscribable podcast feed — no database, no dependencies, and minimal configuration.

Intended for self-hosters who have downloaded podcasts or ripped audiobooks to a NAS and want to re-subscribe to them in a standard podcast app (Apple Podcasts, Overcast, Pocket Casts, etc.).

## What it does

- Scans two directories — one for **podcasts**, one for **audiobooks** — and generates an RSS 2.0 + iTunes feed per subfolder
- Serves a web index listing all feeds with cover art, episode count, and newest-episode age
- **Feed detail page** — click *Details* on any card to open a page showing the full episode list with file format, size, duration, and bitrate per track, plus a built-in audio player so you can play any episode directly in the browser
- Streams audio files with HTTP range-request support (seekable playback, resumable downloads)
- **Podcasts** sort newest-first; **audiobooks** sort ascending by filename (chapter 1 first) — enforced via `pubDate` so podcast apps respect it regardless of their default sort
- Picks up `cover.jpg` / `cover.png` / `folder.jpg` / `folder.png` as podcast artwork
- Works correctly behind a reverse proxy (respects `X-Forwarded-Proto` / `X-Forwarded-Host` from trusted IPs only)
- **Cleans up episode titles** automatically — raw filenames are transformed into readable labels before they appear in your podcast app (see [Episode title cleanup](#episode-title-cleanup))
- **Audiobook metadata enrichment** *(opt-in)* — fetches book descriptions from Open Library and uses them as feed summaries in your podcast app (see [Audiobook metadata](#audiobook-metadata))
- **Rich link previews** — Open Graph and Twitter Card meta tags make shared links look great in iMessage, Slack, Discord, etc. Includes an `apple-touch-icon` for adding the page to the iOS home screen
- **Subscribe in Apple Podcasts** — one-click button uses a clean path URL (`podcast://host/feed/…`) with no query string, so it works reliably in Chrome and Safari alike
- **Accessible** — semantic landmarks, skip link, visible focus rings, reduced-motion support, emoji hidden from screen readers, `<time>` elements for dates (see [Accessibility](#accessibility))

## Requirements

- PHP 8.1+
- Apache with `mod_rewrite` enabled (for the clean feed URL paths)

## Setup

1. Copy the full project (`index.php`, `.htaccess`, `config/`, `src/`, `views/`, `cache/`, and image assets) to your web root (or virtual host directory).
2. Edit the constants in `config/constants.php`:

```php
const PODCAST_ROOT        = '/mnt/torrents/Podcasts';
const PODCASTS_SUBDIR     = 'Podcasts';
const BOOKS_SUBDIR        = 'Books';
const FEED_LANGUAGE       = 'no';
const TRUSTED_PROXY_CIDRS = ['127.0.0.1/32', '::1/128'];
const FETCH_BOOK_METADATA = false;
```

`TRUSTED_PROXY_CIDRS` controls when `X-Forwarded-Proto` and `X-Forwarded-Host` are trusted. Only requests from those proxy IP ranges may set the public scheme/host used in generated links and RSS enclosure URLs. Add your reverse proxy IP/CIDR to this list when running behind a proxy.

`FETCH_BOOK_METADATA` enables optional audiobook description enrichment — see [Audiobook metadata](#audiobook-metadata).

3. Organise your audio files into subfolders:

```
PODCAST_ROOT/
├── Podcasts/
│   └── My Show/
│       ├── cover.jpg
│       ├── notes.md          ← optional custom description (Markdown)
│       └── episode.2024-01-01.mp3
└── Books/
    └── Some Audiobook/
        ├── notes.md          ← optional custom description (Markdown)
        └── 01-chapter.m4b
```

4. Place the provided image files (`logo.png`, `og.png`, `apple-touch-icon.png`,
   `favicon.png`) in the same directory as `index.php` so that link previews
   and browser icons work out of the box. The web server serves them directly.

Each immediate subfolder becomes one feed, accessible at `?feed=Podcasts/My+Show`
or via the clean path `feed/Podcasts/My+Show`.

Hidden (dot-prefixed) feed folders are not listed and are also rejected by
direct feed resolution.

## Subscribing in a podcast app

Each card on the index page has three buttons:

| Button | What it does |
|---|---|
| **Details** | Opens the feed detail page — full episode list, per-track metadata, and built-in browser player |
| **Copy RSS** | Copies the raw RSS URL to the clipboard — paste it into any podcast app's "Add by URL" dialog |
| **Podcasts** | Opens Apple Podcasts and subscribes immediately (works in Chrome and Safari on macOS and iOS) |

The Apple Podcasts link uses the `podcast://` URL scheme with a clean path (no query string). An Apache `mod_rewrite` rule maps `/feed/Show+Name` to the PHP RSS handler, which lets the link work reliably across all browsers. Without this, browsers sometimes strip query strings when handing off custom URL schemes to native apps.

## Feed detail page

Each feed has a detail page at `show/Podcasts/My+Show` (or `?show=Podcasts/My+Show`). It shows:

- Cover art, type badge, and title (with author/year from Open Library when metadata is enabled)
- Stats: episode count, total duration, total file size, newest episode date
- RSS and Apple Podcasts action buttons
- **Description** — rendered from `notes.md` if the file exists in the feed directory, otherwise falls back to the Open Library summary
- **Episode table** — every track listed with cleaned title, duration, file size, format badge, estimated bitrate, and date
- **Built-in player** — a sticky bar slides up from the bottom when you click any episode's play button; supports seek, pause/resume, and close; the active row shows a pause icon while playing. Toggle **⇥ Auto** in the player bar to enable auto-advance: each episode automatically plays the next one when it ends, so you can listen to a whole show without touching the screen
- **Rich link previews** — the show page includes Open Graph and Twitter Card meta tags so sharing a show URL in iMessage, X, Facebook, Slack, Discord, etc. shows the show's cover art, title, and a summary (episode count, total duration, author for audiobooks). Falls back to the site `og.png` when no cover image is present
- **Cover-art colour theming** — when a cover image is present the page automatically adapts its colour scheme to match: the dominant hue is sampled from the artwork via the Canvas API and applied across backgrounds, gradient buttons, badges, the sticky player, and progress bar, making each show visually distinct
- **Generated cover art placeholder** — shows without a cover image get an inline SVG placeholder on both the index and show pages: the full show name is rendered as wrapped text (up to 3 lines, font-size scaled to fit) over a vivid radial gradient. Hue and gradient stop are derived deterministically from the show name via `crc32`, producing a wide, evenly distributed spread of colours — each show gets a unique but always-consistent appearance

**Adding a custom description**

Drop a `notes.md` file into any feed folder and it will appear on the detail page rendered as Markdown. Supports headings, bold/italic, code, lists, blockquotes, and links.

## Smoke tests

Run lightweight no-dependency smoke tests from the project root:

```bash
make smoke
```

Alternative (if you do not want to use `make`):

```bash
sh tests/run_smoke.sh
```

They validate high-risk logic such as episode title normalization, feed path safety checks, media stream/range/ETag behavior, reverse-proxy URL generation, audiobook metadata parsing, and required project structure.

## Audiobook metadata

When `FETCH_BOOK_METADATA` is set to `true` in `config/constants.php`, audiobook RSS feeds are enriched with a book description fetched from the [Open Library](https://openlibrary.org) API. The description appears as the feed summary in your podcast app.

**How it works**

1. On the first request to an audiobook feed, the app splits the folder name into author and title using the `"Author - Title"` convention (e.g. `Haruki Murakami - Kafka på stranden`).
2. It queries the Open Library search API, then fetches the full work record to retrieve the description.
3. The result — including misses — is cached in `cache/metadata/` as a JSON file keyed by the feed ID. Subsequent requests return instantly from cache.
4. If the API is unreachable or returns no match, the feed falls back silently to the default `"Podcast feed for Name"` description.

**Refreshing metadata**

Delete the relevant cache file to force a fresh lookup:

```bash
rm cache/metadata/*.json          # clear all
rm cache/metadata/<sha1hash>.json # clear one feed
```

The cache file for a given feed is `sha1("Books/Feed Name")` — check the `cache/metadata/` directory to identify the right file.

**Privacy note**

Enabling this feature causes the server to make outbound HTTPS requests to `openlibrary.org`, disclosing your audiobook folder names. This is acceptable for most private/LAN setups but is opt-in for this reason.

**Folder naming**

Results are best when folders follow the `"Author - Title"` convention. Folders with bare numbers, CD-only names, or no separator are searched by title only and may not match.

## Security

phodcasts is designed for private self-hosted use. The following controls are in place:

**Path safety**
- Feed directory resolution uses `realpath` and verifies the result is exactly one level inside the media root, preventing directory traversal.
- The media streaming handler confines all file reads to the validated feed directory.
- Dot-prefixed (`hidden`) folders are excluded from both the index listing and direct feed resolution.

**Request headers**
- `X-Forwarded-Proto` and `X-Forwarded-Host` are only trusted when the request comes from an IP in `TRUSTED_PROXY_CIDRS`. Requests from any other IP use `HTTP_HOST` / `SERVER_NAME` directly, preventing host-header injection in generated URLs.
- Forwarded host values are normalised and rejected if they contain characters outside `[A-Za-z0-9.\-:\[\]]`.

**Response headers**

All responses include `X-Content-Type-Options: nosniff` to prevent MIME-type sniffing of streamed audio.

HTML responses additionally include:
- `X-Frame-Options: SAMEORIGIN`
- `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self'; media-src 'self'; connect-src 'self'; form-action 'none'; base-uri 'self'`
- `Referrer-Policy: same-origin`
- `X-Robots-Tag: noindex, nofollow`

RSS responses include `X-Robots-Tag: noindex, nofollow` to suppress search-engine indexing.

**Output encoding**
All dynamic values written into HTML or XML are passed through `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE`.

**Access control**
The app has no built-in authentication. For anything beyond a private LAN, restrict access at the network or reverse-proxy layer (VPN, HTTP basic auth, IP allowlist).

## Episode title cleanup

Raw filenames are rarely podcast-app-friendly. `episode_title()` transforms them
into clean, readable labels:

| Filename (no extension) | Episode title |
|---|---|
| `Papaya.2026-01-19` | `19. januar 2026` |
| `tore.og.haralds.podcast.podme.2026.s09e10` | `Season 9 – Episode 10` |
| `avsnitt042` | `Avsnitt 42` |
| `07xKapittelx2xxFredagx20xxdesember` | `Kapittel 2` |
| `01xMennxsomxhaterxkvinner` | `Menn som hater kvinner` |
| `jo_nesbø-blod_på_snø-0101` | `CD 1, Spor 1` |
| `CD01T05` | `CD 1, Spor 5` |
| `CD-1008` | `CD 10, Spor 8` |
| `07-Track-A07` | `CD 7, Track A` |
| `01 - Track 1` *(in subfolder `CD1/`)* | `CD 1, Track 1` |
| `1-01 Spor 01` | `CD 1, Spor 1` |
| `Kass1sideB` | `Kassett 1, Side B` |
| `Kafka på stranden - Episode 00` | `Episode 00` |
| `Macbeth, Part 1` | `Macbeth, Part 1` *(unchanged)* |

Rules applied in order:

1. **Separator normalisation** — filenames with no spaces but dot- or
   underscore-separated words get those replaced with spaces.
2. **Feed-name prefix stripping** — if the filename starts with the feed/show
   name (or the title part after `"Author – "`), that prefix is removed.
   Separator characters are interchangeable during matching.
3. **Pattern matching** — season/episode codes (`S09E10` → `Season 9 – Episode 10`),
   ISO dates, `avsnitt`, `xKapittel`-encoded chapters, `CD##T##`, `CD-NNN`,
   `NN-Track-XNN`, bare Spor/Track numbers, Kassett sides, and 4-digit `CCTT`
   codes are each detected and reformatted.
4. **Parent-directory CD context** — when a file lives inside a subfolder named
   `CD 1`, `cd01`, `Hodejegerne CD3`, etc., that disc number is attached to
   titles that lack it (e.g. a bare `05.mp3` becomes `CD 1, Spor 5`).
5. **Generic cleanup** — leading track-sequence numbers (`01 - `, `02. `) are
   stripped, and the first letter is capitalised.

## Accessibility

The web index is built to work well for keyboard and screen-reader users:

- **Skip link** — a "Skip to main content" link is the first focusable element on the page; it becomes visible on keyboard focus and lets users jump past the header and filter bar
- **Semantic landmarks** — `<header>`, `<nav>`, `<main>`, and `<footer>` are used correctly so assistive technology can navigate by region
- **Heading structure** — each podcast/audiobook card contains an `<h2>` heading, making it possible to navigate between shows with a screen reader's heading shortcut
- **Emoji** — all decorative emoji (🎙, 📚) are wrapped in `aria-hidden="true"` so screen readers announce the text label only, not the emoji description
- **Dates** — "Newest: 3 days ago" uses a `<time datetime="YYYY-MM-DD">` element so the machine-readable date is available to assistive technology
- **Focus rings** — `:focus-visible` outlines on all interactive elements (links, buttons, filter tabs); `.btn.primary` uses a white outline so it is visible against the gradient background
- **Reduced motion** — `@media (prefers-reduced-motion: reduce)` disables hover transitions and the button lift effect for users with the OS "Reduce Motion" setting enabled
- **Copy RSS feedback** — when the clipboard write succeeds, the button's `aria-label` is updated to "Copied to clipboard" for the 2-second confirmation window, then restored

## License

See [LICENSE](LICENSE).

