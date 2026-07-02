<?php
declare(strict_types=1);

const PODCAST_ROOT    = '/mnt/torrents/Podcasts';
const PODCASTS_SUBDIR = 'Podcasts';
const BOOKS_SUBDIR    = 'Books';
const MAX_ITEMS       = 200;
const FEED_LANGUAGE   = 'no';

// Only trust X-Forwarded-* headers when requests come from these proxy CIDRs.
// Keep this list strict; add your reverse proxy IP/CIDR when needed.
const TRUSTED_PROXY_CIDRS = ['127.0.0.1/32', '::1/128'];

// Set to true to fetch book summaries from Open Library for audiobook feeds.
// Requires outbound HTTPS access from PHP. Results are cached indefinitely in
// cache/metadata/. Delete a cache file to force a refresh for that feed.
const FETCH_BOOK_METADATA = true;

// Number of feeds shown per page on the index.
const FEEDS_PER_PAGE = 9;

const APP_VERSION = 'v1.1';
const REPO_URL    = 'https://github.com/jakobbg/phodcasts';
