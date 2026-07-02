#!/usr/bin/env sh
set -eu

php tests/smoke.php
php tests/media_stream_smoke.php
php tests/web_utils_smoke.php
php tests/structure_smoke.php
php tests/metadata_smoke.php
php tests/utils_smoke.php
php tests/markdown_smoke.php
