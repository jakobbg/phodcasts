# phodcasts

A single-file PHP server that turns a folder of audio files into podcast-app-compatible RSS feeds. Point it at a directory, and every subfolder becomes a subscribable podcast feed — no database, no dependencies, no configuration beyond four constants at the top of the file.

Intended for self-hosters who have downloaded podcasts or ripped audiobooks to a NAS and want to re-subscribe to them in a standard podcast app (Apple Podcasts, Overcast, Pocket Casts, etc.).

## What it does

- Scans two directories — one for **podcasts**, one for **audiobooks** — and generates an RSS 2.0 + iTunes feed per subfolder
- Serves a web index listing all feeds with cover art, episode count, and newest-episode age
- Streams audio files with HTTP range-request support (seekable playback, resumable downloads)
- **Podcasts** sort newest-first; **audiobooks** sort ascending by filename (chapter order)
- Picks up `cover.jpg` / `cover.png` / `folder.jpg` / `folder.png` as podcast artwork
- Works correctly behind a reverse proxy (respects `X-Forwarded-Proto` / `X-Forwarded-Host`)

## Requirements

- PHP 8.1+
- Any web server (Apache, nginx, Caddy, …)

## Setup

1. Copy `index.php` to your web root.
2. Edit the constants at the top:

```php
const PODCAST_ROOT    = '/mnt/torrents/Podcasts';
const PODCASTS_SUBDIR = 'Podcasts';
const BOOKS_SUBDIR    = 'Books';
const FEED_LANGUAGE   = 'no';
```

3. Organise your audio files into subfolders:

```
PODCAST_ROOT/
├── Podcasts/
│   └── My Show/
│       ├── cover.jpg
│       └── episode.2024-01-01.mp3
└── Books/
    └── Some Audiobook/
        └── 01-chapter.m4b
```

Each immediate subfolder becomes one feed, accessible at `?feed=Podcasts/My+Show`.

## License

See [LICENSE](LICENSE).
