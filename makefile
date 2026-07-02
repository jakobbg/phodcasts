.PHONY: smoke lint-smoke

lint-smoke:
	php -l tests/smoke.php
	php -l tests/media_stream_smoke.php
	php -l tests/web_utils_smoke.php
	php -l tests/structure_smoke.php
	php -l tests/metadata_smoke.php

smoke: lint-smoke
	sh tests/run_smoke.sh
