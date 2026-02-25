# MIDI Type 1 -> Type 0 Converter (WordPress Plugin)

Frontend WordPress plugin that lets users upload `.mid/.midi` files, converts MIDI Type 1 to Type 0 asynchronously, and provides per-file or batch ZIP downloads.

## Features

- Frontend upload UI via shortcode: `[midi_type0_converter]`
- Multi-file upload with per-job status polling
- Asynchronous conversion pipeline (`queued -> processing -> done/error`)
- Per-file signed download links
- Batch ZIP download link (when `ZipArchive` is available)
- Private storage under `wp-content/uploads/mtc-private`
- Rate limits for upload/status/download endpoints
- Optional ClamAV scan before storing uploads

## Requirements

- WordPress (plugin architecture + AJAX + cron APIs)
- PHP 8.0+ recommended (uses modern PHP features like `match`)
- `ZipArchive` extension for "Download All (ZIP)"
- Optional: `clamdscan` for antivirus scanning

## Installation

1. Copy this project folder into `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Add shortcode `[midi_type0_converter]` to a page/post.
4. Open that page and upload MIDI files.

## Usage

1. Select one or more `.mid/.midi` files.
2. Click `Upload & Convert`.
3. Polling starts automatically and status updates appear in the table.
4. Download files individually as they complete, or use `Download All (ZIP)` when available.

## How It Works

- Upload endpoint: `mtc_upload` (`wp_ajax_*`)
  - Validates nonce, extension, MIDI header (`MThd`), size limits, and optional ClamAV scan.
  - Persists job row in DB table with initial `queued` status.
  - Schedules background job via `wp_schedule_single_event`.
- Status endpoint: `mtc_status` (`wp_ajax_*`)
  - Returns jobs for a `batch_id`, statuses, and signed download URLs.
  - Nudges stale queued jobs (cron spawn + fallback inline processing for very stale items).
- Conversion worker: `mtc_process_job`
  - Claims queued jobs atomically and runs pure-PHP conversion (`includes/class-mtc-midi-converter.php`).
- Download handlers:
  - `?mtc_download=1...` for a single converted MIDI
  - `?mtc_zip=1...` for batch ZIP
  - Links are HMAC-signed and time-limited.

## Data Model

Table: `{wp_prefix}mtc_jobs`

- `id`
- `batch_id`
- `original_name`
- `original_path`
- `converted_path`
- `status` (`queued`, `processing`, `done`, `error`)
- `error_msg`
- `created_at`
- `updated_at`

## Configuration (Filters)

Add filters in a must-use plugin or theme `functions.php`.

```php
add_filter('mtc_max_upload_bytes', fn() => 10 * 1024 * 1024);
add_filter('mtc_max_files_per_batch', fn() => 200);
add_filter('mtc_download_ttl_seconds', fn() => DAY_IN_SECONDS);
add_filter('mtc_max_queued_ui_ms', fn() => 6000);

add_filter('mtc_rate_limit_window_seconds', fn() => 10 * MINUTE_IN_SECONDS);
add_filter('mtc_rate_limit_max_uploads_per_window', fn() => 80);

add_filter('mtc_queue_nudge_after_seconds', fn() => 3);
add_filter('mtc_inline_process_after_seconds', fn() => 12);

add_filter('mtc_clamav_enabled', fn() => true);
add_filter('mtc_clamav_fail_open', fn() => false);
add_filter('mtc_clamdscan_path', fn() => '/usr/bin/clamdscan');
add_filter('mtc_clamav_cmd_timeout_seconds', fn() => 6);
add_filter('mtc_timeout_bin', fn() => '/usr/bin/timeout');

add_filter('mtc_zip_cache_ttl', fn() => 900);
add_filter('mtc_cleanup_days', fn() => 2);
```

## Security Notes

- Nonce-protected AJAX requests (`mtc_nonce`)
- Signed, expiring download URLs (HMAC with `wp_salt('auth')`)
- Uploaded files stored in a non-public uploads subdirectory
- Direct-access blocks for Apache/IIS (`.htaccess`, `web.config`)
- MIME/header checks plus binary MIDI magic check (`MThd`)
- Optional AV scanning through `clamdscan`

## Operational Notes

- This plugin relies on WP-Cron for background processing.
- If your site has low traffic, cron events may fire late.
- Recent changes include queue nudging and inline fallback to reduce long `queued` waits.

## Development

Project layout:

```text
.
├── midi-type0-converter.php         # Main plugin bootstrap + AJAX/cron handlers
├── includes/
│   └── class-mtc-midi-converter.php # Pure PHP MIDI parser/merger
└── assets/
    ├── mtc.js                       # Frontend upload + polling UI
    └── mtc.css                      # Frontend styles
```

Syntax checks:

```bash
php -l midi-type0-converter.php
php -l includes/class-mtc-midi-converter.php
```

## License

GPLv2 or later.
