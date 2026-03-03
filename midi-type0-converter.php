<?php
/**
 * Plugin Name: MIDI Type 1 → Type 0 Converter (Frontend)
 * Description: Upload many MIDI files asynchronously, converts Type 1 → Type 0, provides per-file and ZIP downloads.
 * Version: 1.1.7
 * Author: Alexander Peppe
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-mtc-midi-converter.php';

final class MTC_Plugin {
    const VERSION = '1.1.7';
    const TABLE   = 'mtc_jobs';
    const DIR_SLUG = 'mtc-private';

    public static function init(): void {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);

        add_shortcode('midi_type0_converter', [__CLASS__, 'shortcode']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        add_action('wp_ajax_nopriv_mtc_upload', [__CLASS__, 'ajax_upload']);
        add_action('wp_ajax_mtc_upload',       [__CLASS__, 'ajax_upload']);

        add_action('wp_ajax_nopriv_mtc_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_mtc_status',       [__CLASS__, 'ajax_status']);

        add_action('wp_ajax_nopriv_mtc_refresh_nonce', [__CLASS__, 'ajax_refresh_nonce']);
        add_action('wp_ajax_mtc_refresh_nonce',       [__CLASS__, 'ajax_refresh_nonce']);

        add_action('mtc_process_job', [__CLASS__, 'process_job'], 10, 1);
        add_action('mtc_cleanup',     [__CLASS__, 'cleanup']);

        add_action('template_redirect', [__CLASS__, 'maybe_handle_downloads']);
    }

    public static function activate(): void {
        self::create_table();
        self::ensure_storage_dir();

        if (!wp_next_scheduled('mtc_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mtc_cleanup');
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('mtc_cleanup');
        // Note: we do not delete the DB table automatically to avoid data loss surprises.
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    private static function create_table(): void {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id VARCHAR(64) NOT NULL,
            original_name TEXT NOT NULL,
            original_path TEXT NOT NULL,
            converted_path TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            error_msg TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

private static function bool01_from_array(array $arr, string $key): int {
    if (!isset($arr[$key])) return 0;
    $v = (string) $arr[$key];
    return ($v === '1' || strtolower($v) === 'true' || $v === 'on') ? 1 : 0;
}

private static function rate_limit_or_die(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'mtc_rl_' . md5($ip);
    $window = (int) apply_filters('mtc_rate_limit_window_seconds', 10 * MINUTE_IN_SECONDS);
    $limit  = (int) apply_filters('mtc_rate_limit_max_uploads_per_window', 80);

    $count = (int) get_transient($key);
    if ($count >= $limit) {
        wp_send_json_error(['message' => 'Rate limit exceeded. Please try again later.'], 429);
    }
    set_transient($key, $count + 1, $window);
}

private static function normalize_batch_id(string $batch_id): string {
    $batch_id = strtolower(trim($batch_id));
    // allow uuid-style / hex-ish tokens with hyphens
    if (!preg_match('/^[a-f0-9\-]{16,64}$/', $batch_id)) {
        return '';
    }
    return $batch_id;
}

private static function ini_size_to_bytes(string $raw): int {
    $raw = trim($raw);
    if ($raw === '') return 0;

    $unit = strtolower(substr($raw, -1));
    $num = (float) $raw;
    if ($num <= 0) return 0;

    switch ($unit) {
        case 'g':
            $num *= 1024;
            // fallthrough
        case 'm':
            $num *= 1024;
            // fallthrough
        case 'k':
            $num *= 1024;
            break;
    }

    if (!is_finite($num) || $num <= 0) return 0;
    return (int) round($num);
}

private static function request_exceeds_post_max(): bool {
    $postMax = self::ini_size_to_bytes((string) ini_get('post_max_size'));
    if ($postMax <= 0) return false;

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    return $contentLength > 0 && $contentLength > $postMax;
}

private static function verify_ajax_nonce_or_error(): void {
    if (check_ajax_referer('mtc_nonce', '_ajax_nonce', false)) {
        return;
    }

    wp_send_json_error([
        'message' => 'Request denied (expired security token). Please refresh and try again.',
        'code' => 'bad_nonce',
    ], 403);
}

private static function today_midnight_mysql(): string {
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $tz);
    return $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
}

private static function purge_stale_jobs_for_batch(string $batch_id): void {
    global $wpdb;
    $table = self::table_name();
    $midnight = self::today_midnight_mysql();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, status, original_path, converted_path, created_at
         FROM {$table}
         WHERE batch_id = %s",
        $batch_id
    ));

    if (!$rows) {
        return;
    }

    $purgeIds = [];
    foreach ($rows as $r) {
        $jobId = (int) ($r->id ?? 0);
        if ($jobId <= 0) continue;

        $status = (string) ($r->status ?? '');
        $createdAt = (string) ($r->created_at ?? '');
        $origPath = (string) ($r->original_path ?? '');
        $convPath = (string) ($r->converted_path ?? '');

        $expiredByMidnight = ($createdAt !== '' && $createdAt < $midnight);
        $missingRequiredFile = false;

        if ($status === 'done') {
            $missingRequiredFile = ($convPath === '' || !is_file($convPath));
        } elseif ($status === 'queued' || $status === 'processing') {
            $missingRequiredFile = ($origPath === '' || !is_file($origPath));
        }

        if (!$expiredByMidnight && !$missingRequiredFile) {
            continue;
        }

        $purgeIds[] = $jobId;

        if ($origPath !== '' && is_file($origPath)) {
            @unlink($origPath);
        }
        if ($convPath !== '' && is_file($convPath)) {
            @unlink($convPath);
        }
    }

    if (!$purgeIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($purgeIds), '%d'));
    $sql = $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$purgeIds);
    if (is_string($sql) && $sql !== '') {
        $wpdb->query($sql);
    }
}


private static function dos_sanitize_base(string $name): string {
    $name = trim($name);

    // Attempt to transliterate to ASCII when possible
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($t !== false) $name = $t;
    }

    $name = strtoupper($name);

    // Keep A–Z and 0–9 only for a clean 8.3 stem
    $name = preg_replace('/[^A-Z0-9]/', '', $name) ?? '';

    if ($name === '') $name = 'FILE';
    return $name;
}

/**
 * Build a deterministic 8.3 mapping for ALL uploads in the batch.
 * Returns: [job_id => "STEM.MID"]
 */
private static function build_83_map(string $batch_id): array {
    global $wpdb;
    $table = self::table_name();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, original_name FROM {$table} WHERE batch_id = %s ORDER BY id ASC",
        $batch_id
    ));

    $used = [];
    $map  = [];

    foreach ($rows as $r) {
        $job_id = (int) $r->id;

        $base = pathinfo((string) $r->original_name, PATHINFO_FILENAME);
        $full = self::dos_sanitize_base((string) $base);

        $n = 0;
        while (true) {
            if ($n === 0) {
                $stem = substr($full, 0, 8);
            } else {
                $suffix = '~' . (string) $n;               // ~1, ~2, ...
                $cut = 8 - strlen($suffix);
                if ($cut < 1) $cut = 1;
                $stem = substr($full, 0, $cut) . $suffix;
            }

            if (!isset($used[$stem])) {
                $used[$stem] = true;
                $map[$job_id] = $stem . '.MID';
                break;
            }

            $n++;
            if ($n > 9999) {
                throw new RuntimeException('Unable to generate unique 8.3 names for this batch.');
            }
        }
    }

    return $map;
}


    private static function ensure_storage_dir(): string {
        $u = wp_upload_dir();
        $dir = trailingslashit($u['basedir']) . self::DIR_SLUG;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Try to block direct access on Apache / IIS (Nginx needs server config).
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        $webconfig = $dir . '/web.config';
        if (!file_exists($webconfig)) {
            @file_put_contents($webconfig, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization>
      <deny users="*" />
    </authorization>
  </system.webServer>
</configuration>
XML
            );
        }

        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        return $dir;
    }

    private static function storage_dir(): string {
        return self::ensure_storage_dir();
    }

public static function enqueue_assets(): void {
    // Only load on pages/posts that contain our shortcode.
    if (!is_singular()) return;
    global $post;
    if (!is_object($post) || !has_shortcode($post->post_content ?? '', 'midi_type0_converter')) {
        return;
    }

    $base = plugin_dir_url(__FILE__);

    // Font
    wp_enqueue_style(
        'mtc-font-chakra-petch',
        'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600&display=swap',
        [],
        null
    );

    wp_enqueue_style(
        'mtc-css',
        $base . 'assets/mtc.css',
        ['mtc-font-chakra-petch'],
        self::VERSION
    );

    wp_enqueue_script(
        'mtc-js',
        $base . 'assets/mtc.js',
        [],
        self::VERSION,
        true
    );

    wp_localize_script('mtc-js', 'MTC_CONFIG', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('mtc_nonce'),
        'maxUploadBytes' => (int) apply_filters('mtc_max_upload_bytes', 10 * 1024 * 1024),
        'maxFilesPerBatch' => (int) apply_filters('mtc_max_files_per_batch', 200),
        'downloadTtlSeconds' => (int) apply_filters('mtc_download_ttl_seconds', DAY_IN_SECONDS),
        'maxQueuedUiMs' => (int) apply_filters('mtc_max_queued_ui_ms', 6000),
        'pollIntervalMs' => (int) apply_filters('mtc_poll_interval_ms', 2000),
        'pollMaxBackoffMs' => (int) apply_filters('mtc_poll_max_backoff_ms', 15000),
        'proactiveNonceRefreshMs' => (int) apply_filters('mtc_proactive_nonce_refresh_ms', 15 * MINUTE_IN_SECONDS * 1000),
    ]);
}

private static function sanitize_ip(string $ip): string {
    $ip = trim($ip);
    if ($ip === '') return '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

private static function ip_matches_range(string $ip, string $range): bool {
    $range = trim($range);
    if ($range === '') return false;

    if (strpos($range, '/') === false) {
        $candidate = self::sanitize_ip($range);
        return $candidate !== '' && $candidate === $ip;
    }

    [$subnet, $bitsRaw] = explode('/', $range, 2);
    $subnet = self::sanitize_ip($subnet);
    if ($subnet === '' || !is_numeric($bitsRaw)) return false;

    $bits = (int) $bitsRaw;

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;

    $fullBytes = intdiv($bits, 8);
    $partialBits = $bits % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }

    if ($partialBits === 0) return true;

    $mask = (0xFF << (8 - $partialBits)) & 0xFF;
    return ((ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask));
}

private static function is_trusted_proxy(string $ip): bool {
    $trusted = apply_filters('mtc_trusted_proxies', ['127.0.0.1', '::1']);
    if (!is_array($trusted)) return false;

    foreach ($trusted as $range) {
        if (!is_string($range)) continue;
        if (self::ip_matches_range($ip, $range)) return true;
    }
    return false;
}

private static function extract_forwarded_ip(string $raw): string {
    if ($raw === '') return '';
    $parts = explode(',', $raw);
    foreach ($parts as $part) {
        $ip = self::sanitize_ip($part);
        if ($ip !== '') {
            return $ip;
        }
    }
    return '';
}

private static function client_id(): string {
    $remote = self::sanitize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') return 'unknown';

    // Only trust forwarding headers if the immediate peer is one of our proxies.
    if (!self::is_trusted_proxy($remote)) {
        return $remote;
    }

    $cfIp = self::sanitize_ip((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cfIp !== '') return $cfIp;

    $xffIp = self::extract_forwarded_ip((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xffIp !== '') return $xffIp;

    $xRealIp = self::sanitize_ip((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($xRealIp !== '') return $xRealIp;

    return $remote;
}

private static function rl(string $bucket, int $limit, int $windowSeconds): void {
    $key = 'mtc_rl_' . $bucket . '_' . md5(self::client_id());
    self::rl_ajax(
        $key,
        $limit,
        $windowSeconds,
        'Too many requests. Please try again shortly.'
    );
}


public static function shortcode(): string {
    ob_start();
    ?>
    <div class="mtc-wrap" id="mtc-app">
	<div class="mtc-panel">
            <input id="mtc-file" aria-label="Choose MIDI files to upload" type="file" multiple accept=".mid,.midi,audio/midi,audio/x-midi" />
            <button class="button" id="mtc-upload-btn" type="button">Upload &amp; Convert</button>
            <button class="button" id="mtc-reset-btn" type="button">Reset List</button>

            <p class="mtc-hint">
                Upload one or many files. Download links appear as they're ready.
            </p>
        </div>

        <div class="mtc-table">
            <div class="mtc-row mtc-head">
                <div>File</div>
                <div>Status</div>
                <div>Download</div>
            </div>
            <div id="mtc-rows"></div>
        </div>

        <div class="mtc-actions">
            <a href="#" id="mtc-download-all" class="button button-primary" style="display:none;">Download All (ZIP)</a>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}


public static function ajax_refresh_nonce(): void {
    self::rl('nonce', 120, 60);
    wp_send_json_success([
        'nonce' => wp_create_nonce('mtc_nonce'),
    ]);
}


    public static function ajax_upload(): void {
        if (self::request_exceeds_post_max()) {
            wp_send_json_error([
                'message' => 'Upload exceeds the server request size limit. Please upload a smaller file.',
                'code' => 'post_too_large',
            ], 413);
        }
	self::verify_ajax_nonce_or_error();
        self::rl('upload', 10, 60);          // 10/min
    	self::rl('upload10', 60, 600);       // 60/10min

        self::rate_limit_or_die();

        $batch_id = isset($_POST['batch_id']) ? self::normalize_batch_id((string) $_POST['batch_id']) : '';
        if ($batch_id === '') {
            wp_send_json_error(['message' => 'Invalid batch id.'], 400);
        }

        if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['message' => 'No file uploaded.'], 400);
        }

        $max_files = (int) apply_filters('mtc_max_files_per_batch', 200);
        $max_bytes = (int) apply_filters('mtc_max_upload_bytes', 10 * 1024 * 1024);

        global $wpdb;
        $table = self::table_name();

        $existing_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id = %s", $batch_id)
        );
        if ($existing_count >= $max_files) {
            wp_send_json_error(['message' => 'Batch file limit reached.'], 400);
        }

        $f = $_FILES['file'];

        if (!is_uploaded_file($f['tmp_name'])) {
            wp_send_json_error(['message' => 'Upload failed validation.'], 400);
        }

        if (!empty($f['error'])) {
            wp_send_json_error(['message' => 'Upload error code: ' . (int) $f['error']], 400);
        }

        if ((int) $f['size'] <= 0 || (int) $f['size'] > $max_bytes) {
            wp_send_json_error(['message' => 'File too large (or empty).'], 400);
        }

        $orig_name = sanitize_text_field((string) $f['name']);
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mid', 'midi'], true)) {
            wp_send_json_error(['message' => 'Only .mid/.midi files are allowed.'], 400);
        }

        // Validate MIDI header magic ("MThd") before storing.
        $fh = @fopen($f['tmp_name'], 'rb');
        if (!$fh) wp_send_json_error(['message' => 'Unable to read uploaded file.'], 400);
        $magic = @fread($fh, 4);
        @fclose($fh);

        if ($magic !== 'MThd') {
            wp_send_json_error(['message' => 'Not a valid MIDI file (missing MThd header).'], 400);
        }

        $dir = self::storage_dir();
        $rand = bin2hex(random_bytes(16));
        $base = sanitize_file_name(pathinfo($orig_name, PATHINFO_FILENAME));
        if ($base === '') $base = 'midi';
        $stored_path = $dir . '/' . $rand . '-' . $base . '.mid';

$scan = self::clamav_scan($f['tmp_name']);


$failOpen = (bool) apply_filters('mtc_clamav_fail_open', false); // default: fail closed
if (!$scan['ok'] && !$failOpen) {
    wp_send_json_error(['message' => "Antivirus failed."], 400);
}

if (!empty($scan['infected'])) {
    wp_send_json_error(['message' => 'Virus detected.'], 400);
}

        if (!@move_uploaded_file($f['tmp_name'], $stored_path)) {
            wp_send_json_error(['message' => 'Failed to move uploaded file.'], 500);
        }

        $now = current_time('mysql');
        $wpdb->insert($table, [
            'batch_id'       => $batch_id,
            'original_name'  => $orig_name,
            'original_path'  => $stored_path,
            'converted_path' => null,
            'status'         => 'queued',
            'error_msg'      => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], [
            '%s','%s','%s','%s','%s','%s','%s','%s'
        ]);

        $job_id = (int) $wpdb->insert_id;

        // Background conversion via WP-Cron single event.
        wp_schedule_single_event(time() + 1, 'mtc_process_job', [$job_id]);
        if (function_exists('spawn_cron')) {
            @spawn_cron(time());
        }

        wp_send_json_success([
            'job_id' => $job_id,
            'name'   => $orig_name,
	    'status' => 'queued',
            'created_at_ms' => (int) (current_time('timestamp') * 1000),
        ]);
    }

private static function rl_download(string $bucket, int $limit, int $windowSeconds): void {
    $key = 'mtc_rl_' . $bucket . '_' . md5(self::client_id());
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $count = (int) get_transient($key);
    if ($count >= $limit) {
        header('Retry-After: ' . $windowSeconds);
        status_header(429);
        exit;
    }
    set_transient($key, $count + 1, $windowSeconds);
}

private static function rl_ajax(string $key, int $limit, int $windowSeconds, string $message): void {
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);

    $count = (int) get_transient($key);
    if ($count >= $limit) {
        header('Retry-After: ' . $windowSeconds);
        wp_send_json_error(['message' => $message], 429);
    }
    set_transient($key, $count + 1, $windowSeconds);
}

private static function rl_status(string $batch_id): void {
    $client = self::client_id();

    $per_batch_window = (int) apply_filters('mtc_status_rate_limit_per_batch_window_seconds', 60);
    $per_batch_limit = (int) apply_filters('mtc_status_rate_limit_per_batch_per_window', 240);

    $global_window = (int) apply_filters('mtc_status_rate_limit_ip_window_seconds', 600);
    $global_limit = (int) apply_filters('mtc_status_rate_limit_ip_per_window', 1000);

    $batch_key = 'mtc_rl_status_batch_' . md5($client . '|' . $batch_id);
    $global_key = 'mtc_rl_status_ip_' . md5($client);

    self::rl_ajax(
        $batch_key,
        $per_batch_limit,
        $per_batch_window,
        'Too many status requests for this batch. Please slow down.'
    );

    self::rl_ajax(
        $global_key,
        $global_limit,
        $global_window,
        'Too many status requests. Please try again shortly.'
    );
}

private static function nudge_batch_queue(string $batch_id): void {
    global $wpdb;
    $table = self::table_name();

    $nudge_after = (int) apply_filters('mtc_queue_nudge_after_seconds', 3);
    if ($nudge_after < 0) $nudge_after = 0;

    $inline_after = (int) apply_filters('mtc_inline_process_after_seconds', 12);
    if ($inline_after < $nudge_after) $inline_after = $nudge_after;

    $now = current_time('timestamp');
    $nudge_cutoff = date('Y-m-d H:i:s', $now - $nudge_after);
    $inline_cutoff = date('Y-m-d H:i:s', $now - $inline_after);

    $queued_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id
         FROM {$table}
         WHERE batch_id = %s
           AND status = 'queued'
           AND updated_at <= %s
         ORDER BY id ASC
         LIMIT 5",
        $batch_id,
        $nudge_cutoff
    ));

    if (!$queued_ids) {
        return;
    }

    foreach ($queued_ids as $qid) {
        $job_id = absint($qid);
        if ($job_id <= 0) continue;
        if (!wp_next_scheduled('mtc_process_job', [$job_id])) {
            wp_schedule_single_event(time(), 'mtc_process_job', [$job_id]);
        }
    }

    $spawned = false;
    if (function_exists('spawn_cron')) {
        $spawned = (bool) @spawn_cron(time());
    }

    if ($spawned) {
        return;
    }

    $inline_job_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id
         FROM {$table}
         WHERE batch_id = %s
           AND status = 'queued'
           AND updated_at <= %s
         ORDER BY id ASC
         LIMIT 1",
        $batch_id,
        $inline_cutoff
    ));

    if ($inline_job_id > 0) {
        self::process_job($inline_job_id);
    }
}

public static function ajax_status(): void {
	self::verify_ajax_nonce_or_error();

    $batch_id = isset($_POST['batch_id']) ? self::normalize_batch_id((string) $_POST['batch_id']) : '';
    if ($batch_id === '') {
        wp_send_json_error(['message' => 'Invalid batch id.'], 400);
    }

    self::rl_status($batch_id);

    self::purge_stale_jobs_for_batch($batch_id);

    self::nudge_batch_queue($batch_id);

    global $wpdb;
    $table = self::table_name();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, original_name, status, error_msg
                   , created_at
             FROM {$table}
             WHERE batch_id = %s
             ORDER BY id ASC",
            $batch_id
        )
    );

    $ttl = (int) apply_filters('mtc_download_ttl_seconds', DAY_IN_SECONDS);
    $exp = time() + max(300, $ttl);

    $jobs = [];
    $done_count = 0;

    foreach ($rows as $r) {
        $jobId = (int) $r->id;
        $status = (string) $r->status;

        $job = [
            'job_id' => $jobId,
            'name'   => (string) $r->original_name,
            'status' => $status,
            'error'  => (string) ($r->error_msg ?? ''),
            'created_at_ms' => null,
            'download_url' => null,
        ];

        $createdAtTs = strtotime((string) ($r->created_at ?? ''));
        if ($createdAtTs !== false) {
            $job['created_at_ms'] = (int) ($createdAtTs * 1000);
        }

        if ($status === 'done') {
            $done_count++;
            $job['download_url'] = self::signed_url([
                'mtc_download' => 1,
                'job' => $jobId,
                'batch' => $batch_id,
                'exp' => $exp,
            ]);
        }

        $jobs[] = $job;
    }

    // Only show ZIP link if ZIP support exists
    $zip_url = null;
    if ($done_count > 0 && class_exists('ZipArchive')) {
        $zip_url = self::signed_url([
            'mtc_zip' => 1,
            'batch' => $batch_id,
            'exp' => $exp,
        ]);
    }

    wp_send_json_success([
        'jobs' => $jobs,
        'zip_url' => $zip_url,
        'done_count' => $done_count,
    ]);
}

private static function clamav_scan(string $filePath): array {
    $enabled = (bool) apply_filters('mtc_clamav_enabled', true);
    if (!$enabled) {
        return ['ok' => true, 'infected' => false, 'signature' => ''];
    }

    // Use clamdscan (daemon) not clamscan (slow)
    $clamdscan = (string) apply_filters('mtc_clamdscan_path', '/usr/bin/clamdscan');

    if (!is_file($clamdscan) || !is_executable($clamdscan)) {
        return ['ok' => false, 'infected' => false, 'signature' => '', 'error' => 'clamdscan not executable'];
    }

    // Basic safety checks
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'infected' => false, 'signature' => '', 'error' => 'file not readable'];
    }

    // Optional: hard timeout to prevent hanging scans
    $timeout = (int) apply_filters('mtc_clamav_cmd_timeout_seconds', 6);
    $escaped = escapeshellarg($filePath);

    // --fdpass is needed when PHP user lacks read access to the file but clamd can read it.
    // --no-summary keeps output small.
    // Use /usr/bin/timeout if present.
    $timeoutBin = (string) apply_filters('mtc_timeout_bin', '/usr/bin/timeout');
    if (is_file($timeoutBin) && is_executable($timeoutBin) && $timeout > 0) {
        $cmd = $timeoutBin . ' ' . (int)$timeout . ' ' . $clamdscan . ' --fdpass --no-summary ' . $escaped . ' 2>&1';
    } else {
        $cmd = $clamdscan . ' --fdpass --no-summary ' . $escaped . ' 2>&1';
    }

    $outputLines = [];
    $exitCode = 0;
    @exec($cmd, $outputLines, $exitCode);

    $output = trim(implode("\n", $outputLines));

    // clamdscan: 0 clean, 1 infected, 2 error
    if ($exitCode === 0) {
        return ['ok' => true, 'infected' => false, 'signature' => ''];
    }
    if ($exitCode === 1) {
        // Try to extract signature: "file: Eicar-Test-Signature FOUND"
        $sig = '';
        if (preg_match('/:\s*(.+)\s+FOUND$/i', $output, $m)) {
            $sig = trim($m[1]);
        }
        return ['ok' => true, 'infected' => true, 'signature' => $sig];
    }

    // Timeout returns 124 typically; treat as error.
    return ['ok' => false, 'infected' => false, 'signature' => '', 'error' => $output ?: ('clamdscan error code ' . $exitCode)];
}


private static function clear_output_buffers(): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}


    private static function signed_url(array $params): string {
        $sig = self::sign_params($params);
        $params['sig'] = $sig;
        return add_query_arg($params, home_url('/'));
    }

    private static function sign_params(array $params): string {
        ksort($params);
        $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return hash_hmac('sha256', $qs, wp_salt('auth'));
    }

    private static function verify_sig(array $params, string $sig): bool {
        $expected = self::sign_params($params);
        return hash_equals($expected, $sig);
    }

    public static function maybe_handle_downloads(): void {
        if (isset($_GET['mtc_download'])) {
            self::handle_file_download();
        } elseif (isset($_GET['mtc_zip'])) {
            self::handle_zip_download();
        }
    }

    private static function handle_file_download(): void {
    self::rl_download('dl', 300, 600);       // 300 per 10 minutes
    $job_id  = isset($_GET['job'])   ? absint($_GET['job']) : 0;
    $batch   = isset($_GET['batch']) ? self::normalize_batch_id((string) $_GET['batch']) : '';
    $exp     = isset($_GET['exp'])   ? (int) $_GET['exp'] : 0;
    $sig     = isset($_GET['sig'])   ? (string) $_GET['sig'] : '';

    if ($job_id <= 0 || $batch === '' || $exp <= 0 || $sig === '') {
        status_header(400); exit('Bad request.');
    }
    if (time() > $exp) {
        status_header(403); exit('Link expired.');
    }

    $params = ['mtc_download' => 1, 'job' => $job_id, 'batch' => $batch, 'exp' => $exp];
    if (!self::verify_sig($params, $sig)) {
        status_header(403); exit('Invalid signature.');
    }

    global $wpdb;
    $table = self::table_name();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT original_name, converted_path, status, batch_id FROM {$table} WHERE id = %d",
        $job_id
    ));

    if (!$row || (string) $row->batch_id !== $batch) {
        status_header(404); exit('Not found.');
    }
    if ((string) $row->status !== 'done' || empty($row->converted_path)) {
        status_header(409); exit('Not ready.');
    }

    $path = (string) $row->converted_path;
    if (!is_file($path)) {
        status_header(404); exit('File missing.');
    }

    $base = sanitize_file_name(pathinfo((string) $row->original_name, PATHINFO_FILENAME));
    if ($base === '') $base = 'converted';
    $download_name = $base . '-type0.mid';

    @set_time_limit(0);
    self::clear_output_buffers();

    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: audio/midi');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . (string) filesize($path));
    @readfile($path);
    exit;
}

private static function handle_zip_download(): void {
	self::rl_download('zip', 5, 600);        // 5 per 10 minutes
    $batch = isset($_GET['batch']) ? self::normalize_batch_id((string) $_GET['batch']) : '';
    $exp   = isset($_GET['exp'])   ? (int) $_GET['exp'] : 0;
    $sig   = isset($_GET['sig'])   ? (string) $_GET['sig'] : '';

    if ($batch === '' || $exp <= 0 || $sig === '') {
        status_header(400); exit('Bad request.');
    }
    if (time() > $exp) {
        status_header(403); exit('Link expired.');
    }

    $params = ['mtc_zip' => 1, 'batch' => $batch, 'exp' => $exp];
    if (!self::verify_sig($params, $sig)) {
        status_header(403); exit('Invalid signature.');
    }

    if (!class_exists('ZipArchive')) {
        status_header(500); exit('ZIP support is not available on this host.');
    }

    if (!function_exists('wp_tempnam')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $tmp = function_exists('wp_tempnam')
        ? wp_tempnam('mtc-batch')
        : tempnam(sys_get_temp_dir(), 'mtc-');

    if (!$tmp) {
        status_header(500); exit('Unable to create a temporary file.');
    }

    global $wpdb;
    $table = self::table_name();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, original_name, converted_path
         FROM {$table}
         WHERE batch_id = %s AND status = 'done'
         ORDER BY id ASC",
        $batch
    ));

    $cacheTtl = (int) apply_filters('mtc_zip_cache_ttl', 900); // 15 minutes
$cachePath = self::storage_dir() . '/zip-' . $batch . '.zip';

if (is_file($cachePath) && (time() - filemtime($cachePath)) < $cacheTtl) {
    self::clear_output_buffers();
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="midi-type0-batch-' . $batch . '.zip"');
    header('Content-Length: ' . (string) filesize($cachePath));
    @readfile($cachePath);
    exit;
}

    if (!$rows) {
        @unlink($tmp);
        status_header(404); exit('No converted files.');
    }

    @set_time_limit(0);

    $zipPath = $tmp; // no extension required
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        status_header(500); exit('Unable to open ZIP.');
    }

    $usedNames = [];

    foreach ($rows as $r) {
        $jobId = (int) $r->id;
        $path = (string) $r->converted_path;
        if (!is_file($path)) continue;

        $base = sanitize_file_name(pathinfo((string) $r->original_name, PATHINFO_FILENAME));
        if ($base === '') $base = 'converted-' . $jobId;

        $zipName = $base . '-type0.mid';

        $i = 2;
        $candidate = $zipName;
        while (isset($usedNames[$candidate])) {
            $candidate = $base . "-type0-{$i}.mid";
            $i++;
        }
        $zipName = $candidate;
        $usedNames[$zipName] = true;

        $zip->addFile($path, $zipName);
    }

    $zip->close();

    $download_name = 'midi-type0-batch-' . $batch . '.zip';

    self::clear_output_buffers();

    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . (string) filesize($zipPath));
    @readfile($zipPath);
    @unlink($zipPath);
    exit;
}

public static function process_job(int $job_id): void {
    $job_id = absint($job_id);
    if ($job_id <= 0) return;

    global $wpdb;
    $table = self::table_name();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d",
        $job_id
    ));
    if (!$row) return;

    // Claim the job (only one runner can flip queued -> processing)
    $now = current_time('mysql');
    $claimed = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = 'processing', updated_at = %s
         WHERE id = %d AND status = 'queued'",
        $now, $job_id
    ));
    if ($claimed !== 1) return;

    try {
        @set_time_limit(0);

        $src = (string) $row->original_path;
        if (!is_file($src)) {
            throw new RuntimeException('Original file missing.');
        }

        $dir = self::storage_dir();
        $rand = bin2hex(random_bytes(16));
        $base = sanitize_file_name(pathinfo((string) $row->original_name, PATHINFO_FILENAME));
        if ($base === '') $base = 'converted';

        $dest = $dir . '/converted-' . $rand . '-' . $base . '.mid';

        MTC_MidiConverter::convert_file($src, $dest);

        $wpdb->update($table, [
            'converted_path' => $dest,
            'status' => 'done',
            'error_msg' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s','%s'], ['%d']);

    } catch (Throwable $e) {
        $wpdb->update($table, [
            'status' => 'error',
            'error_msg' => substr($e->getMessage(), 0, 2000),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s'], ['%d']);
    }
}

public static function cleanup(): void {
    $days = (int) apply_filters('mtc_cleanup_days', 2);

    $cutoff_ts = current_time('timestamp') - ($days * DAY_IN_SECONDS);
    $cutoff = date('Y-m-d H:i:s', $cutoff_ts);

    global $wpdb;
    $table = self::table_name();

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT id, original_path, converted_path FROM {$table} WHERE created_at < %s", $cutoff)
    );

    if ($rows) {
        foreach ($rows as $r) {
            if (!empty($r->original_path) && is_file((string) $r->original_path)) {
                @unlink((string) $r->original_path);
            }
            if (!empty($r->converted_path) && is_file((string) $r->converted_path)) {
                @unlink((string) $r->converted_path);
            }
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }
}
}

MTC_Plugin::init();
