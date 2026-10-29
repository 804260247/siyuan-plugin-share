<?php
declare(strict_types=1);

$tz = (string)(getenv('TZ') ?: 'Asia/Shanghai');
if (!@date_default_timezone_set($tz)) {
    @date_default_timezone_set('Asia/Shanghai');
}

$exampleVersion = null;
$exampleCentralStatsUrl = null;
if (file_exists(__DIR__ . '/config.example.php')) {
    $exampleConfig = require __DIR__ . '/config.example.php';
    if (is_array($exampleConfig)) {
        if (array_key_exists('site_version', $exampleConfig)) {
            $exampleVersion = trim((string)($exampleConfig['site_version'] ?? ''));
        }
        if (array_key_exists('central_stats_url', $exampleConfig)) {
            $exampleCentralStatsUrl = trim((string)($exampleConfig['central_stats_url'] ?? ''));
        }
    }
}

$config = [
    'app_name' => '思源笔记分享',
    'site_version' => '',
    'central_stats_url' => '',
    'db_path' => __DIR__ . '/storage/app.db',
    'uploads_dir' => __DIR__ . '/uploads',
    'allow_registration' => true,
    'default_storage_limit_mb' => 1024,
    'session_lifetime_days' => 30,
    'chunk_ttl_seconds' => 7200,
    'chunk_cleanup_probability' => 0.05,
    'chunk_cleanup_limit' => 20,
    'min_chunk_size_kb' => 256,
    'max_chunk_size_mb' => 8,
    'captcha_enabled' => true,
    'email_verification_enabled' => false,
    'email_from' => 'no-reply@example.com',
    'email_from_name' => '思源笔记分享',
    'email_subject' => '邮箱验证码',
    'email_reset_subject' => '重置密码验证码',
    'smtp_enabled' => false,
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => '',
    'smtp_pass' => '',
];

if (file_exists(__DIR__ . '/config.php')) {
    $local = require __DIR__ . '/config.php';
    if (is_array($local)) {
        $config = array_merge($config, $local);
    }
}
if ($exampleVersion !== null) {
    $config['site_version'] = $exampleVersion;
}
if ($exampleCentralStatsUrl !== null) {
    $config['central_stats_url'] = $exampleCentralStatsUrl;
}

if (PHP_SAPI !== 'cli') {
    $sessionDays = (int)($config['session_lifetime_days'] ?? 30);
    if ($sessionDays <= 0) {
        $sessionDays = 30;
    }
    $sessionLifetime = $sessionDays * 86400;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    session_set_cookie_params($sessionLifetime, '/', '', false, true);
    session_start();
}

date_default_timezone_set('UTC');

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Shanghai');
}

require_once __DIR__ . '/vendor/Parsedown.php';

class ShareParsedown extends Parsedown {
    private const CALLOUT_META = [
        'note' => ['label' => 'Note', 'icon' => '🖊️'],
        'tip' => ['label' => 'Tip', 'icon' => '💡'],
        'important' => ['label' => 'Important', 'icon' => '❗'],
        'warning' => ['label' => 'Warning', 'icon' => '⚠️'],
        'caution' => ['label' => 'Caution', 'icon' => '🚨'],
        'info' => ['label' => 'Info', 'icon' => 'ℹ️'],
    ];

    protected function blockQuote($Line) {
        if (!preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches)) {
            return null;
        }
        $content = (string)$matches[1];
        if (preg_match('/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION|INFO)\]\s*(.*)$/i', $content, $cm)) {
            $typeKey = strtolower($cm[1]);
            return [
                'element' => [
                    'name' => 'div',
                    'attributes' => ['class' => 'md-alert md-alert--' . $typeKey],
                    'handler' => [
                        'function' => 'calloutAlertElements',
                        'argument' => (array)$content,
                        'destination' => 'elements',
                    ],
                ],
            ];
        }
        return [
            'element' => [
                'name' => 'blockquote',
                'handler' => [
                    'function' => 'linesElements',
                    'argument' => (array)$content,
                    'destination' => 'elements',
                ],
            ],
        ];
    }

    protected function calloutAlertElements(array $lines, array $nonNestables = []) {
        $bodyLines = $lines;
        $header = (string)array_shift($bodyLines);
        if (!preg_match('/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION|INFO)\]\s*(.*)$/i', $header, $m)) {
            return $this->linesElements($lines, $nonNestables);
        }
        $typeKey = strtolower($m[1]);
        $meta = self::CALLOUT_META[$typeKey] ?? self::CALLOUT_META['note'];
        $titleRaw = trim($m[2]);
        $iconElement = ['name' => 'span', 'attributes' => ['class' => 'md-alert__icon'], 'text' => $meta['icon']];
        if ($titleRaw !== '') {
            $labelElements = $this->lineElements($titleRaw, $nonNestables);
            $labelElement = is_array($labelElements)
                ? ['name' => 'span', 'attributes' => ['class' => 'md-alert__label'], 'elements' => $labelElements]
                : ['name' => 'span', 'attributes' => ['class' => 'md-alert__label'], 'text' => $titleRaw];
        } else {
            $labelElement = ['name' => 'span', 'attributes' => ['class' => 'md-alert__label'], 'text' => $meta['label']];
        }
        $titleElement = [
            'name' => 'div',
            'attributes' => ['class' => 'md-alert__title'],
            'elements' => [$iconElement, $labelElement],
        ];
        $bodyElements = $this->linesElements($bodyLines, $nonNestables);
        return array_merge([$titleElement], $bodyElements);
    }
}

function base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = rtrim($dir, '/');
    return $dir === '/' ? '' : $dir;
}

function asset_url(string $path): string {
    $mtime = @filemtime(__DIR__ . $path);
    return base_path() . $path . '?v=' . ($mtime ?: 0);
}

function base_url(): string {
    static $customBaseUrl = null;
    if ($customBaseUrl === null) {
        $customBaseUrl = trim((string)get_setting('site_base_url', ''));
        if ($customBaseUrl !== '') {
            $customBaseUrl = rtrim($customBaseUrl, '/');
        }
    }
    if ($customBaseUrl !== null && $customBaseUrl !== '') {
        return $customBaseUrl;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_path();
}

function redirect(string $path): void {
    header('Location: ' . base_path() . $path);
    exit;
}

function enqueue_background_task(callable $task): void {
    register_shutdown_function(function () use ($task) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $task();
    });
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo) {
        return $pdo;
    }
    $dbDir = dirname($config['db_path']);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }
    $dsn = 'sqlite:' . $config['db_path'];
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 30000;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "user",
        api_key_hash TEXT,
        api_key_prefix TEXT,
        api_key_last4 TEXT,
        disabled INTEGER NOT NULL DEFAULT 0,
        storage_limit_bytes INTEGER NOT NULL DEFAULT 0,
        storage_used_bytes INTEGER NOT NULL DEFAULT 0,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        email_verified INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS shares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        doc_id TEXT,
        notebook_id TEXT,
        password_hash TEXT,
        expires_at INTEGER,
        access_count INTEGER NOT NULL DEFAULT 0,
        visitor_limit INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        deleted_at TEXT,
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        doc_id TEXT NOT NULL,
        title TEXT NOT NULL,
        icon TEXT,
        hpath TEXT,
        parent_id TEXT,
        sort_index INTEGER NOT NULL DEFAULT 0,
        markdown TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        content_hash TEXT,
        meta_hash TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        doc_id TEXT,
        asset_path TEXT NOT NULL,
        file_path TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        asset_hash TEXT,
        created_at TEXT NOT NULL,
        UNIQUE(share_id, asset_path),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        upload_id TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        share_id INTEGER,
        type TEXT NOT NULL,
        doc_id TEXT,
        notebook_id TEXT,
        slug TEXT,
        title TEXT,
        password_hash TEXT,
        expires_at INTEGER,
        visitor_limit INTEGER NOT NULL DEFAULT 0,
        asset_manifest TEXT,
        doc_manifest TEXT,
        upload_mode TEXT,
        patch_manifest TEXT,
        status TEXT NOT NULL DEFAULT "pending",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_upload_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        upload_id TEXT NOT NULL,
        doc_id TEXT NOT NULL,
        title TEXT NOT NULL,
        icon TEXT,
        hpath TEXT,
        parent_id TEXT,
        sort_index INTEGER NOT NULL DEFAULT 0,
        markdown TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        content_hash TEXT,
        meta_hash TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER NOT NULL,
        key TEXT NOT NULL,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY(user_id, key),
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_access_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        share_id INTEGER NOT NULL,
        doc_id TEXT,
        doc_title TEXT,
        visitor_id TEXT,
        ip TEXT,
        ip_country TEXT,
        ip_country_code TEXT,
        ip_region TEXT,
        ip_city TEXT,
        referer TEXT,
        created_at TEXT NOT NULL,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_user_time ON share_access_logs (user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_share_time ON share_access_logs (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_access_visitor_time ON share_access_logs (visitor_id, created_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        parent_id INTEGER,
        user_id INTEGER,
        visitor_id TEXT,
        email TEXT NOT NULL,
        content TEXT NOT NULL,
        ip TEXT,
        size_bytes INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_comments_share ON share_comments (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_comments_parent ON share_comments (parent_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        visitor_id TEXT NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE(share_id, visitor_id),
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_visitors_share ON share_visitors (share_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_access_geo_cache (
        ip TEXT PRIMARY KEY,
        country TEXT,
        country_code TEXT,
        region TEXT,
        city TEXT,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS share_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        share_title TEXT NOT NULL,
        share_slug TEXT NOT NULL,
        share_user_id INTEGER NOT NULL,
        reporter_user_id INTEGER,
        report_email TEXT,
        visitor_id TEXT,
        ip TEXT,
        reason_type TEXT NOT NULL,
        reason_detail TEXT,
        created_at TEXT NOT NULL,
        handled_at TEXT,
        handled_by INTEGER,
        FOREIGN KEY(share_id) REFERENCES shares(id)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_reports_share ON share_reports (share_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_reports_handled ON share_reports (handled_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS instance_heartbeats (
        instance_id TEXT PRIMARY KEY,
        first_seen TEXT NOT NULL,
        last_seen TEXT NOT NULL,
        version TEXT,
        ip TEXT
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_instance_heartbeats_seen ON instance_heartbeats (last_seen)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS recycled_share_ids (
        share_id INTEGER PRIMARY KEY,
        created_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS email_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        used_at TEXT,
        ip TEXT
    );');

    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        used_at TEXT,
        ip TEXT,
        FOREIGN KEY(user_id) REFERENCES users(id)
    );');

    ensure_column($pdo, 'users', 'storage_limit_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'storage_used_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'email_verified', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'last_active_at', 'TEXT');
    ensure_column($pdo, 'shares', 'password_hash', 'TEXT');
    ensure_column($pdo, 'shares', 'expires_at', 'INTEGER');
    ensure_column($pdo, 'shares', 'access_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'visitor_limit', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'comment_notify', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'announcement_mode', 'TEXT NOT NULL DEFAULT \'none\'');
    ensure_column($pdo, 'shares', 'announcement_title', 'TEXT');
    ensure_column($pdo, 'shares', 'announcement_content', 'TEXT');
    ensure_column($pdo, 'shares', 'announcement_doc_id', 'TEXT');
    ensure_column($pdo, 'share_reports', 'report_email', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'visitor_limit', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_uploads', 'upload_mode', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'patch_manifest', 'TEXT');
    ensure_column($pdo, 'share_uploads', 'doc_manifest', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'icon', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'content_hash', 'TEXT');
    ensure_column($pdo, 'share_upload_docs', 'meta_hash', 'TEXT');
    ensure_column($pdo, 'share_docs', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'icon', 'TEXT');
    ensure_column($pdo, 'share_docs', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'parent_id', 'TEXT');
    ensure_column($pdo, 'share_docs', 'sort_index', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_docs', 'content_hash', 'TEXT');
    ensure_column($pdo, 'share_docs', 'meta_hash', 'TEXT');
    ensure_column($pdo, 'share_assets', 'size_bytes', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'share_assets', 'asset_hash', 'TEXT');

    // Netdisk (Cloudreve) integration: per-share settings
    ensure_column($pdo, 'shares', 'netdisk_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'shares', 'netdisk_root', 'TEXT');
    ensure_column($pdo, 'shares', 'netdisk_permission', 'TEXT NOT NULL DEFAULT "view"');
    ensure_column($pdo, 'shares', 'netdisk_config_id', 'INTEGER NOT NULL DEFAULT 0');

    // Netdisk: multiple Cloudreve connection configs
    $pdo->exec('CREATE TABLE IF NOT EXISTS cloudreve_configs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        base_url TEXT NOT NULL,
        email TEXT NOT NULL,
        password TEXT NOT NULL,
        viewer_id TEXT NOT NULL DEFAULT "",
        default_root TEXT NOT NULL DEFAULT "cloudreve://my",
        office_engine TEXT NOT NULL DEFAULT "auto",
        office_ext_map TEXT NOT NULL DEFAULT "{}",
        office_collabora_url TEXT NOT NULL DEFAULT "",
        office_onlyoffice_url TEXT NOT NULL DEFAULT "",
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');
    ensure_column($pdo, 'cloudreve_configs', 'office_engine', 'TEXT NOT NULL DEFAULT "auto"');
    ensure_column($pdo, 'cloudreve_configs', 'office_ext_map', 'TEXT NOT NULL DEFAULT "{}"');
    ensure_column($pdo, 'cloudreve_configs', 'office_collabora_url', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'office_onlyoffice_url', 'TEXT NOT NULL DEFAULT ""');
    // 连接级全文索引配置（空字符串 = 未配置，走全局默认；与分享绑定该连接时生效）
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_enabled', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_concurrency', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_dpi_start', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_dpi_max', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_quality_threshold', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_max_mb', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_ocr_low_max_mb', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_refresh_ttl', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'cloudreve_configs', 'index_skip_exts', 'TEXT NOT NULL DEFAULT ""');

    // WOPI 应用（文件预览）全局表：与分享绑定无关
    $pdo->exec('CREATE TABLE IF NOT EXISTS office_apps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        engine TEXT NOT NULL,
        base_url TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );');
    ensure_column($pdo, 'office_apps', 'url_template', 'TEXT NOT NULL DEFAULT ""');

    // 下载规则：某个分享允许下载的文件/文件夹（is_folder=1 表示包含其下所有文件）
    $pdo->exec('CREATE TABLE IF NOT EXISTS share_download_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        uri TEXT NOT NULL,
        is_folder INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_share_dl_rules_share ON share_download_rules(share_id)');

    // 网盘目录列表 / 搜索结果短期缓存表
    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_cache (
        cache_key TEXT PRIMARY KEY,
        payload TEXT NOT NULL,
        expires INTEGER NOT NULL
    );');

    // 网盘全文索引表：本地保存文件名/路径/抽取正文，加速检索并摆脱对 Cloudreve FTS 的依赖
    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_index (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        uri TEXT NOT NULL,
        name TEXT NOT NULL,
        is_folder INTEGER NOT NULL DEFAULT 0,
        ext TEXT NOT NULL DEFAULT "",
        size INTEGER NOT NULL DEFAULT 0,
        parent_path TEXT NOT NULL DEFAULT "",
        text TEXT NOT NULL DEFAULT "",
        indexed_at INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT "",
        UNIQUE(share_id, uri)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_netdisk_index_share ON netdisk_index(share_id)');
    // 旧库补齐 updated_at（Cloudreve 文件更新时间，用于增量重建跳过未变化文件）
    $ndIndexCols = $pdo->query('PRAGMA table_info(netdisk_index)')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('updated_at', array_column($ndIndexCols, 'name'), true)) {
        $pdo->exec('ALTER TABLE netdisk_index ADD COLUMN updated_at TEXT NOT NULL DEFAULT ""');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_index_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        files INTEGER NOT NULL DEFAULT 0,
        folders INTEGER NOT NULL DEFAULT 0,
        texts INTEGER NOT NULL DEFAULT 0,
        errors INTEGER NOT NULL DEFAULT 0,
        ocr_skipped INTEGER NOT NULL DEFAULT 0,
        processed_dirs INTEGER NOT NULL DEFAULT 0,
        total_dirs INTEGER NOT NULL DEFAULT 0,
        total_files INTEGER NOT NULL DEFAULT 0,
        current_dir TEXT NOT NULL DEFAULT "",
        current_uri TEXT NOT NULL DEFAULT "",
        current_name TEXT NOT NULL DEFAULT "",
        current_detail TEXT NOT NULL DEFAULT "",
        ocr_done_count INTEGER NOT NULL DEFAULT 0,
        ocr_total INTEGER NOT NULL DEFAULT 0,
        queue_remaining INTEGER NOT NULL DEFAULT 0,
        ocr_files_total INTEGER NOT NULL DEFAULT 0,
        ocr_files_done INTEGER NOT NULL DEFAULT 0,
        heartbeat_at INTEGER NOT NULL DEFAULT 0,
        notes TEXT NOT NULL DEFAULT "",
        error TEXT NOT NULL DEFAULT "",
        attempts INTEGER NOT NULL DEFAULT 0,
        cancel_requested INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0,
        started_at INTEGER NOT NULL DEFAULT 0,
        finished_at INTEGER NOT NULL DEFAULT 0
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nd_jobs_share_status ON netdisk_index_jobs(share_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nd_jobs_status_created ON netdisk_index_jobs(status, created_at)');

    // 兼容旧库：为 netdisk_index_jobs 补齐 OCR 统计列
    $jobCols = $pdo->query('PRAGMA table_info(netdisk_index_jobs)')->fetchAll(PDO::FETCH_ASSOC);
    $jobColNames = array_column($jobCols, 'name');
    if (!in_array('ocr_done', $jobColNames, true)) {
        $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN ocr_done INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('ocr_queued', $jobColNames, true)) {
        $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN ocr_queued INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('total_files', $jobColNames, true)) {
        $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN total_files INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('attempts', $jobColNames, true)) {
        $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('cancel_requested', $jobColNames, true)) {
        $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN cancel_requested INTEGER NOT NULL DEFAULT 0');
    }
    // 细粒度进度字段（当前文件/页/排队/心跳）
    $extraJobCols = [
        'current_uri' => 'TEXT NOT NULL DEFAULT ""',
        'current_name' => 'TEXT NOT NULL DEFAULT ""',
        'current_detail' => 'TEXT NOT NULL DEFAULT ""',
        'ocr_done_count' => 'INTEGER NOT NULL DEFAULT 0',
        'ocr_total' => 'INTEGER NOT NULL DEFAULT 0',
        'queue_remaining' => 'INTEGER NOT NULL DEFAULT 0',
        'ocr_files_total' => 'INTEGER NOT NULL DEFAULT 0',
        'ocr_files_done' => 'INTEGER NOT NULL DEFAULT 0',
        'heartbeat_at' => 'INTEGER NOT NULL DEFAULT 0',
        'force_ocr' => 'INTEGER NOT NULL DEFAULT 0',
        // 0019: worker 崩溃循环保护计数（fatal 退出 +1，成功完成清零；≥2 → job 标 failed 不再自动重拾）
        'crash_count' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($extraJobCols as $col => $def) {
        if (!in_array($col, $jobColNames, true)) {
            $pdo->exec('ALTER TABLE netdisk_index_jobs ADD COLUMN ' . $col . ' ' . $def);
        }
    }

    // 低优先级 OCR 队列：>max_mb 且 ≤low_max_mb 的图片/扫描件，由 worker 空闲时处理
    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_ocr_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        share_id INTEGER NOT NULL,
        config_id INTEGER NOT NULL DEFAULT 0,
        uri TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT "",
        ext TEXT NOT NULL DEFAULT "",
        size INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "pending",
        attempts INTEGER NOT NULL DEFAULT 0,
        error TEXT NOT NULL DEFAULT "",
        created_at INTEGER NOT NULL DEFAULT 0,
        started_at INTEGER NOT NULL DEFAULT 0,
        finished_at INTEGER NOT NULL DEFAULT 0
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ocr_queue_status ON netdisk_ocr_queue(status, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ocr_queue_share ON netdisk_ocr_queue(share_id)');
    // 旧库补齐 config_id（按 share 绑定连接回填，未绑定为 0）
    $ocrQCols = $pdo->query('PRAGMA table_info(netdisk_ocr_queue)')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('config_id', array_column($ocrQCols, 'name'), true)) {
        $pdo->exec('ALTER TABLE netdisk_ocr_queue ADD COLUMN config_id INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE netdisk_ocr_queue SET config_id = (SELECT netdisk_config_id FROM shares WHERE shares.id = netdisk_ocr_queue.share_id) WHERE config_id = 0');
    }

    // OCR 文件级状态表（增量索引）：记录每个 OCR 候选文件的最近一次识别结果，
    // 重建时据此跳过已达标文件（status=done 且 quality>=0.6 且 chars>=20 且云端未更新），
    // 失败/无效文件 attempts>=3 后不再重试；强制重跑（force_ocr=1）忽略全部跳过判定。
    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_ocr_files (
        share_id INTEGER NOT NULL,
        uri TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT "",
        size INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "pending",
        quality REAL NOT NULL DEFAULT 0,
        chars INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT "",
        dpi INTEGER NOT NULL DEFAULT 0,
        pages_total INTEGER NOT NULL DEFAULT 0,
        pages_done INTEGER NOT NULL DEFAULT 0,
        engine TEXT NOT NULL DEFAULT "",
        elapsed_ms INTEGER NOT NULL DEFAULT 0,
        pages_per_min REAL NOT NULL DEFAULT 0,
        first_at INTEGER NOT NULL DEFAULT 0,
        finished_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (share_id, uri)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ocr_files_status ON netdisk_ocr_files(share_id, status)');
    // 0018: 旧库补列（CREATE IF NOT EXISTS 不给已存在表加列）
    $ocrFCols = array_column($pdo->query('PRAGMA table_info(netdisk_ocr_files)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('elapsed_ms', $ocrFCols, true)) {
        $pdo->exec('ALTER TABLE netdisk_ocr_files ADD COLUMN elapsed_ms INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('pages_per_min', $ocrFCols, true)) {
        $pdo->exec('ALTER TABLE netdisk_ocr_files ADD COLUMN pages_per_min REAL NOT NULL DEFAULT 0');
    }

    // 0024: OCR 结果内容寻址表——识别正文按 (content_hash, params_key) 绑定文件内容而非路径。
    // 改名/移动/重命名父目录后内容哈希不变 → 直接复用旧正文，不再重新 OCR；
    // params_key 变化（DPI/max_side/profile/引擎换代）→ 视为未命中重扫，旧行保留（改回可复用）。
    // PC 端（tools/ocr-win）扫描结果经 POST /admin/netdisk/ocr/import 写入本表（source='win'），
    // NAS 遍历到同哈希文件时命中复用（source='nas' 为 NAS 直扫）。
    $pdo->exec('CREATE TABLE IF NOT EXISTS netdisk_ocr_results (
        content_hash TEXT NOT NULL,
        params_key   TEXT NOT NULL,
        size         INTEGER NOT NULL DEFAULT 0,
        pages        INTEGER NOT NULL DEFAULT 0,
        chars        INTEGER NOT NULL DEFAULT 0,
        quality      REAL    NOT NULL DEFAULT 0,
        engine       TEXT    NOT NULL DEFAULT "",
        text         TEXT    NOT NULL DEFAULT "",
        source       TEXT    NOT NULL DEFAULT "",
        created_at   INTEGER NOT NULL DEFAULT 0,
        last_hit_at  INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(content_hash, params_key)
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ocr_results_hit ON netdisk_ocr_results(last_hit_at)');
    // 0024: 两张路径键老表各补 content_hash 列（走 ensure_column，对已有库无损；不写无条件 ALTER）
    ensure_column($pdo, 'netdisk_index', 'content_hash', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'netdisk_ocr_files', 'content_hash', 'TEXT NOT NULL DEFAULT ""');

    // 迁移旧版每配置的 office 引擎地址到全局 office_apps（去重）
    $officeCount = (int)$pdo->query('SELECT COUNT(*) FROM office_apps')->fetchColumn();
    if ($officeCount === 0) {
        $legacyApps = $pdo->query('SELECT name, office_collabora_url, office_onlyoffice_url, enabled FROM cloudreve_configs')->fetchAll(PDO::FETCH_ASSOC);
        $insOffice = $pdo->prepare('INSERT OR IGNORE INTO office_apps (name, engine, base_url, enabled, created_at, updated_at) VALUES (:n, :e, :b, 1, :t, :t)');
        foreach ($legacyApps as $la) {
            $legacyName = trim((string)($la['name'] ?? ''));
            $cb = rtrim(trim((string)($la['office_collabora_url'] ?? '')), '/');
            $oo = rtrim(trim((string)($la['office_onlyoffice_url'] ?? '')), '/');
            if ($cb !== '') {
                $insOffice->execute([':n' => ($legacyName !== '' ? $legacyName : '默认') . ' Collabora', ':e' => 'collabora', ':b' => $cb, ':t' => now()]);
            }
            if ($oo !== '') {
                $insOffice->execute([':n' => ($legacyName !== '' ? $legacyName : '默认') . ' OnlyOffice', ':e' => 'onlyoffice', ':b' => $oo, ':t' => now()]);
            }
        }
    }

    // 迁移旧版“站点设置”里的单条 Cloudreve 连接为一个默认配置
    $configCount = (int)$pdo->query('SELECT COUNT(*) FROM cloudreve_configs')->fetchColumn();
    if ($configCount === 0) {
        $legacyBase = (string)fetch_setting($pdo, 'cloudreve_base_url');
        $legacyEmail = (string)fetch_setting($pdo, 'cloudreve_email');
        $legacyPass = (string)fetch_setting($pdo, 'cloudreve_password');
        $legacyViewer = (string)fetch_setting($pdo, 'cloudreve_viewer_id');
        if ($legacyBase !== '' && $legacyEmail !== '') {
            $legacyPassEnc = netdisk_encrypt_secret($legacyPass);
            $ins = $pdo->prepare('INSERT INTO cloudreve_configs (name, base_url, email, password, viewer_id, default_root, enabled, created_at, updated_at)
                VALUES (:n, :b, :e, :p, :v, :r, 1, :t, :t)');
            $ins->execute([
                ':n' => '默认配置',
                ':b' => $legacyBase,
                ':e' => $legacyEmail,
                ':p' => $legacyPassEnc !== '' ? $legacyPassEnc : $legacyPass,
                ':v' => $legacyViewer,
                ':r' => 'cloudreve://my',
                ':t' => now(),
            ]);
        }
    }

    // 一次性把历史明文密码加密为 enc:v1（密钥见 storage/.netdisk_secret）
    if (fetch_setting($pdo, 'cloudreve_pw_enc_v1') !== '1') {
        $allOk = true;
        $pwRows = $pdo->query('SELECT id, password FROM cloudreve_configs')->fetchAll(PDO::FETCH_ASSOC);
        $pwUpd = $pdo->prepare('UPDATE cloudreve_configs SET password = :p WHERE id = :id');
        foreach ($pwRows as $pwRow) {
            $pw = (string)($pwRow['password'] ?? '');
            if ($pw === '' || str_starts_with($pw, 'enc:v1:')) {
                continue;
            }
            $enc = netdisk_encrypt_secret($pw);
            if ($enc === '') {
                $allOk = false;
                continue;
            }
            $pwUpd->execute([':p' => $enc, ':id' => (int)$pwRow['id']]);
        }
        // 旧版单连接密码已被迁移到 cloudreve_configs，清除设置表中的明文副本
        $pdo->exec("UPDATE settings SET value = '' WHERE key = 'cloudreve_password'");
        if ($allOk) {
            ensure_setting($pdo, 'cloudreve_pw_enc_v1', '1');
        }
    }

    // 旧版已开启网盘、但未绑定连接配置的分享，自动绑定到默认连接
    $defaultCfg = (int)$pdo->query('SELECT id FROM cloudreve_configs WHERE enabled = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($defaultCfg > 0) {
        $pdo->prepare('UPDATE shares SET netdisk_config_id = :cfg WHERE netdisk_enabled = 1 AND netdisk_config_id = 0')
            ->execute([':cfg' => $defaultCfg]);
    }

    seed_default_settings($pdo);
    seed_default_admin($pdo);
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($cols as $col) {
        if (isset($col['name']) && $col['name'] === $column) {
            return true;
        }
    }
    return false;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!table_has_column($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

function fetch_setting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : null;
}

function ensure_setting(PDO $pdo, string $key, string $value): void {
    $existing = fetch_setting($pdo, $key);
    if ($existing !== null) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
    ]);
}

function seed_default_settings(PDO $pdo): void {
    global $config;
    $defaultLimit = (int)$config['default_storage_limit_mb'] * 1024 * 1024;
    ensure_setting($pdo, 'allow_registration', $config['allow_registration'] ? '1' : '0');
    ensure_setting($pdo, 'default_storage_limit_bytes', (string)$defaultLimit);
    ensure_setting($pdo, 'captcha_enabled', $config['captcha_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'email_verification_enabled', $config['email_verification_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'email_from', (string)$config['email_from']);
    ensure_setting($pdo, 'email_from_name', (string)$config['email_from_name']);
    ensure_setting($pdo, 'email_subject', (string)$config['email_subject']);
    ensure_setting($pdo, 'email_reset_subject', (string)$config['email_reset_subject']);
    ensure_setting($pdo, 'smtp_enabled', $config['smtp_enabled'] ? '1' : '0');
    ensure_setting($pdo, 'smtp_host', (string)$config['smtp_host']);
    ensure_setting($pdo, 'smtp_port', (string)$config['smtp_port']);
    ensure_setting($pdo, 'smtp_secure', (string)$config['smtp_secure']);
    ensure_setting($pdo, 'smtp_user', (string)$config['smtp_user']);
    ensure_setting($pdo, 'smtp_pass', (string)$config['smtp_pass']);
    ensure_setting($pdo, 'banned_words', '');
    ensure_setting($pdo, 'site_icp', '');
    ensure_setting($pdo, 'site_contact_email', '');
    ensure_setting($pdo, 'site_base_url', '');
    ensure_setting($pdo, 'site_head_html', '');
    ensure_setting($pdo, 'site_custom_css_enabled', '1');
    ensure_setting($pdo, 'site_custom_css', '');
    ensure_setting($pdo, 'site_custom_js_enabled', '0');
    ensure_setting($pdo, 'site_custom_js', '');
    ensure_setting($pdo, 'access_stats_default_enabled', '1');
    ensure_setting($pdo, 'access_stats_default_retention_days', '7');
    ensure_setting($pdo, 'site_tips', '');
    // Cloudreve 网盘连接配置
    ensure_setting($pdo, 'cloudreve_base_url', '');
    ensure_setting($pdo, 'cloudreve_email', '');
    ensure_setting($pdo, 'cloudreve_password', '');
    ensure_setting($pdo, 'cloudreve_viewer_id', '');
    ensure_setting($pdo, 'cloudreve_access_token', '');
    ensure_setting($pdo, 'cloudreve_refresh_token', '');
    ensure_setting($pdo, 'cloudreve_token_expires', '0');
}

function seed_default_admin(PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) AS cnt FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $now = now();
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
        VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
    $stmt->execute([
        ':username' => 'admin',
        ':email' => '',
        ':password_hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':api_key_hash' => null,
        ':api_key_prefix' => null,
        ':api_key_last4' => null,
        ':disabled' => 0,
        ':storage_limit_bytes' => 0,
        ':storage_used_bytes' => 0,
        ':must_change_password' => 1,
        ':email_verified' => 1,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        echo 'CSRF 校验失败。';
        exit;
    }
}

function flash(string $key, ?string $value = null): ?string {
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    if (isset($_SESSION['flash'][$key])) {
        unset($_SESSION['flash'][$key]);
    }
    return $val;
}

function get_setting(string $key, ?string $default = null): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && array_key_exists('value', $row)) {
        return $row['value'];
    }
    return $default;
}

function set_setting(string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
        ON CONFLICT(key) DO UPDATE SET value = :value_update, updated_at = :updated_at_update');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
        ':value_update' => $value,
        ':updated_at_update' => now(),
    ]);
}

function get_bool_setting(string $key, bool $default = false): bool {
    $value = get_setting($key, $default ? '1' : '0');
    return (int)$value === 1;
}

function escape_inline_style_content(string $css): string {
    return str_ireplace('</style', '<\/style', $css);
}

function escape_inline_script_content(string $js): string {
    return str_ireplace('</script', '<\/script', $js);
}

function get_user_setting(int $userId, string $key, ?string $default = null): ?string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM user_settings WHERE user_id = :uid AND key = :key LIMIT 1');
    $stmt->execute([
        ':uid' => $userId,
        ':key' => $key,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && array_key_exists('value', $row)) {
        return $row['value'];
    }
    return $default;
}

function set_user_setting(int $userId, string $key, string $value): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, key, value, updated_at)
        VALUES (:uid, :key, :value, :updated_at)
        ON CONFLICT(user_id, key) DO UPDATE SET value = :value_update, updated_at = :updated_at_update');
    $stmt->execute([
        ':uid' => $userId,
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => now(),
        ':value_update' => $value,
        ':updated_at_update' => now(),
    ]);
}

function access_stats_default_enabled(): bool {
    return get_bool_setting('access_stats_default_enabled', true);
}

function access_stats_default_retention_days(): int {
    $raw = (int)get_setting('access_stats_default_retention_days', '7');
    return max(1, min(365, $raw));
}

function access_stats_enabled(int $userId): bool {
    $value = get_user_setting($userId, 'access_stats_enabled', null);
    if ($value === null) {
        return access_stats_default_enabled();
    }
    return (int)$value === 1;
}

function access_stats_retention_days(int $userId): int {
    $value = get_user_setting($userId, 'access_stats_retention_days', null);
    if ($value === null) {
        return access_stats_default_retention_days();
    }
    $days = (int)$value;
    return max(1, min(365, $days));
}

function allow_registration(): bool {
    return get_bool_setting('allow_registration', true);
}

function captcha_enabled(): bool {
    return get_bool_setting('captcha_enabled', true);
}

function email_verification_enabled(): bool {
    return get_bool_setting('email_verification_enabled', false);
}

function smtp_enabled(): bool {
    return get_bool_setting('smtp_enabled', false);
}

function email_verification_available(): bool {
    return email_verification_enabled() && smtp_enabled();
}

function default_storage_limit_bytes(): int {
    $value = (int)get_setting('default_storage_limit_bytes', '0');
    return max(0, $value);
}

function get_banned_words_raw(): string {
    return (string)get_setting('banned_words', '');
}

function get_banned_words(): array {
    $raw = get_banned_words_raw();
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode('|', $raw));
    return array_values(array_filter($parts, fn($word) => $word !== ''));
}

function string_pos_ci(string $text, string $needle): ?int {
    if (function_exists('mb_stripos')) {
        $pos = mb_stripos($text, $needle, 0, 'UTF-8');
        return $pos === false ? null : (int)$pos;
    }
    $pos = stripos($text, $needle);
    return $pos === false ? null : (int)$pos;
}

function string_len(string $text): int {
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($text, 'UTF-8');
    }
    return strlen($text);
}

function string_sub(string $text, int $start, int $length): string {
    if (function_exists('mb_substr')) {
        return (string)mb_substr($text, $start, $length, 'UTF-8');
    }
    return substr($text, $start, $length);
}

function find_banned_word(string $text, array $words): ?array {
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $pos = string_pos_ci($text, $word);
        if ($pos !== null) {
            return ['word' => $word, 'pos' => $pos];
        }
    }
    return null;
}

function normalize_plain_text(string $text): string {
    $text = preg_replace('/\R+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function extract_snippet(string $text, string $word, int $radius = 40): string {
    $plain = normalize_plain_text($text);
    if ($plain === '') {
        return '';
    }
    $pos = string_pos_ci($plain, $word);
    if ($pos === null) {
        return string_sub($plain, 0, min(120, string_len($plain)));
    }
    $start = max(0, $pos - $radius);
    $length = min(string_len($plain) - $start, $radius * 2 + string_len($word));
    return string_sub($plain, $start, $length);
}

function extract_snippet_at(string $text, int $pos, int $radius = 40): string {
    $len = mb_strlen($text);
    $start = max(0, $pos - $radius);
    $end = min($len, $pos + $radius);
    $seg = mb_substr($text, $start, $end - $start);
    return ($start > 0 ? '…' : '') . $seg . ($end < $len ? '…' : '');
}

function extract_snippets_multi(string $text, string $q, int $maxCount = 30): array {
    $out = [];
    $searchFrom = 0;
    $textLen = mb_strlen($text);
    $qLen = mb_strlen($q);
    $count = 0;
    while ($searchFrom < $textLen && $count < $maxCount) {
        $pos = mb_stripos($text, $q, $searchFrom);
        if ($pos === false) {
            break;
        }
        $out[] = [
            'offset' => $pos,
            'text' => extract_snippet_at($text, $pos),
        ];
        $searchFrom = $pos + $qLen;
        $count++;
    }
    return $out;
}

function parse_expires_at($raw): ?int {
    if ($raw === null || $raw === '' || $raw === false) {
        return null;
    }
    if (is_numeric($raw)) {
        $ts = (int)$raw;
        if ($ts > 1000000000000) {
            $ts = (int)floor($ts / 1000);
        }
        return $ts > 0 ? $ts : null;
    }
    $ts = strtotime((string)$raw);
    return $ts ? $ts : null;
}

function parse_visitor_limit($raw): ?int {
    if ($raw === null || $raw === '' || $raw === false) {
        return null;
    }
    if (is_numeric($raw)) {
        return max(0, (int)$raw);
    }
    return null;
}

function extract_front_matter(string $markdown): array {
    $meta = [];
    $body = $markdown;
    if (preg_match('/\A---\s*\R(.*?)\R---\s*\R/s', $markdown, $matches)) {
        $raw = trim((string)$matches[1]);
        $body = substr($markdown, strlen($matches[0]));
        foreach (preg_split('/\R/', $raw) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '') {
                continue;
            }
            $meta[strtolower($key)] = trim($value, " \t\"'");
        }
    }
    return ['meta' => $meta, 'body' => $body];
}

function share_doc_updated_at(string $markdown): string {
    $front = extract_front_matter($markdown);
    $raw = (string)($front['meta']['lastmod'] ?? $front['meta']['updated'] ?? $front['meta']['modified'] ?? $front['meta']['last_modified'] ?? '');
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return '';
}

function format_meta_date(?string $raw): string {
    if (!$raw) {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts) {
        return date('Y-m-d H:i', $ts);
    }
    return $raw;
}

function render_meta_chips(array $meta): string {
    $created = $meta['date'] ?? $meta['created'] ?? $meta['created_at'] ?? '';
    $updated = $meta['lastmod'] ?? $meta['updated'] ?? $meta['modified'] ?? $meta['last_modified'] ?? '';
    $chips = [];
    if ($created !== '') {
        $chips[] = ['label' => '创建', 'value' => format_meta_date($created)];
    }
    if ($updated !== '') {
        $chips[] = ['label' => '更新', 'value' => format_meta_date($updated)];
    }
    if (empty($chips)) {
        return '';
    }
    $html = '<div class="kb-meta">';
    foreach ($chips as $chip) {
        $label = htmlspecialchars($chip['label']);
        $value = htmlspecialchars($chip['value']);
        $html .= "<span class=\"kb-chip\"><strong>{$label}</strong> {$value}</span>";
    }
    $html .= '</div>';
    return $html;
}

function format_share_datetime(?string $raw): string {
    if (!$raw) {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts) {
        return date('Y-m-d H:i', $ts);
    }
    return $raw;
}

function mask_email(string $email): string {
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return str_repeat('*', max(2, strlen($email)));
    }
    [$local, $domain] = $parts;
    $local = trim($local);
    if ($local === '') {
        return str_repeat('*', 3) . '@' . $domain;
    }
    $len = strlen($local);
    if ($len <= 1) {
        $masked = $local . '**';
    } else {
        $maskLen = max(1, (int)floor($len / 2));
        $keepLen = $len - $maskLen;
        $keepStart = max(1, (int)ceil($keepLen / 2));
        $keepEnd = max(1, $keepLen - $keepStart);
        if ($keepStart + $keepEnd >= $len) {
            $keepStart = 1;
            $keepEnd = $len > 1 ? 1 : 0;
        }
        $masked = substr($local, 0, $keepStart)
            . str_repeat('*', $len - $keepStart - $keepEnd)
            . substr($local, $len - $keepEnd);
    }
    return $masked . '@' . $domain;
}

function calculate_comment_size(string $email, string $content): int {
    $size = strlen($email) + strlen($content);
    return max(1, $size);
}

function share_comment_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_comments WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    return (int)$stmt->fetchColumn();
}

function comment_asset_prefix(): string {
    return 'comment-files/';
}

function share_comment_asset_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = :share_id AND asset_path LIKE :prefix');
    $stmt->execute([
        ':share_id' => $shareId,
        ':prefix' => comment_asset_prefix() . '%',
    ]);
    return (int)$stmt->fetchColumn();
}

function extract_comment_asset_paths(string $content, int $shareId): array {
    $content = trim($content);
    if ($content === '') {
        return [];
    }
    $prefix = comment_asset_prefix() . $shareId . '/';
    $paths = [];
    $patterns = [
        '#(?:https?://[^\s\)\"\'<>]+)?[^\s\)\"\'<>]*?(?:/uploads/|uploads/)' . preg_quote($prefix, '#') . '([^\s\)\"\'<>]+)#i',
        '#(?:https?://[^\s\)\"\'<>]+)?[^\s\)\"\'<>]*?(?:/res/|res/)' . preg_quote($prefix, '#') . '([^\s\)\"\'<>]+)#i',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $content, $matches)) {
            continue;
        }
        foreach ($matches[1] as $suffix) {
            $suffix = preg_replace('/[?#].*$/', '', (string)$suffix);
            $decoded = rawurldecode((string)$suffix);
            $path = sanitize_asset_path($prefix . $decoded);
            if ($path === '') {
                continue;
            }
            $paths[$path] = true;
        }
    }
    return array_keys($paths);
}

function comment_asset_reference_needles(string $path): array {
    $normalized = ltrim(sanitize_asset_path($path), '/');
    if ($normalized === '') {
        return [];
    }
    $encoded = encode_path_segments($normalized);
    $needles = [
        'uploads/' . $normalized,
        'uploads/' . $encoded,
        '/res/' . $normalized,
        '/res/' . $encoded,
    ];
    $out = [];
    foreach ($needles as $needle) {
        if ($needle === '' || isset($out[$needle])) {
            continue;
        }
        $out[$needle] = true;
    }
    return array_keys($out);
}

function filter_unused_comment_assets(int $shareId, array $paths, array $excludeIds): array {
    if (empty($paths)) {
        return [];
    }
    $pdo = db();
    $unused = [];
    foreach ($paths as $path) {
        $needles = comment_asset_reference_needles((string)$path);
        if (empty($needles)) {
            continue;
        }
        $likeSql = [];
        $params = [$shareId];
        foreach ($needles as $needle) {
            $likeSql[] = 'content LIKE ?';
            $params[] = '%' . $needle . '%';
        }
        $sql = 'SELECT COUNT(*) FROM share_comments WHERE share_id = ? AND (' . implode(' OR ', $likeSql) . ')';
        if (!empty($excludeIds)) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            $params = array_merge($params, $excludeIds);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();
        if ($count <= 0) {
            $unused[] = $path;
        }
    }
    return $unused;
}

function sum_share_asset_sizes(int $shareId, array $paths): int {
    if (empty($paths)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $params = $paths;
    array_unshift($params, $shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function delete_comment_assets(int $shareId, array $paths): int {
    if (empty($paths)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($paths), '?'));
    $params = $paths;
    array_unshift($params, $shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT asset_path, file_path, size_bytes FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return 0;
    }
    global $config;
    $total = 0;
    foreach ($rows as $row) {
        $filePath = (string)($row['file_path'] ?? '');
        $size = (int)($row['size_bytes'] ?? 0);
        if ($filePath !== '') {
            $fullPath = $config['uploads_dir'] . '/' . ltrim($filePath, '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
        $total += $size;
    }
    $del = $pdo->prepare('DELETE FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
    $del->execute($params);
    return $total;
}

function adjust_share_size(int $shareId, int $delta): void {
    if ($delta === 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE shares SET size_bytes = CASE
        WHEN size_bytes + :delta < 0 THEN 0
        ELSE size_bytes + :delta
    END WHERE id = :id');
    $stmt->execute([
        ':delta' => $delta,
        ':id' => $shareId,
    ]);
}

function render_share_stats(array $share, string $extraHtml = ''): string {
    $count = (int)($share['access_count'] ?? 0);
    $created = format_share_datetime((string)($share['created_at'] ?? ''));
    $expiresAt = (int)($share['expires_at'] ?? 0);
    $visitorLimit = (int)($share['visitor_limit'] ?? 0);
    $chips = [];
    $chips[] = ['label' => '访问次数', 'value' => $count . ' 次'];
    if ($created !== '') {
        $chips[] = ['label' => '创建时间', 'value' => $created];
    }
    if ($expiresAt > 0) {
        $chips[] = ['label' => '到期时间', 'value' => date('Y-m-d H:i', $expiresAt)];
    }
    if ($visitorLimit > 0) {
        $chips[] = ['label' => '访客上限', 'value' => $visitorLimit . ' 人'];
    }
    if (empty($chips) && $extraHtml === '') {
        return '';
    }
    $html = '<div class="kb-meta">';
    foreach ($chips as $chip) {
        $label = htmlspecialchars($chip['label']);
        $value = htmlspecialchars($chip['value']);
        $html .= "<span class=\"kb-chip\"><strong>{$label}</strong> {$value}</span>";
    }
    if ($extraHtml !== '') {
        $html .= $extraHtml;
    }
    $html .= '</div>';
    return $html;
}

function format_bytes(float $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $idx = 0;
    $val = (float)$bytes;
    while ($val >= 1024 && $idx < count($units) - 1) {
        $val /= 1024;
        $idx++;
    }
    return sprintf('%.2f %s', $val, $units[$idx]);
}

function normalize_page_size($raw, int $default = 10): int {
    $allowed = [10, 50, 200, 1000];
    $size = (int)$raw;
    if (!in_array($size, $allowed, true)) {
        $size = $default;
    }
    return $size;
}

function paginate(int $total, int $page, int $size): array {
    $pages = max(1, (int)ceil($total / max(1, $size)));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $size;
    return [$page, $size, $pages, $offset];
}

function build_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/admin' . ($qs ? '?' . $qs : '');
}

function build_admin_query_url(string $hash, array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    $hashPart = $hash !== '' ? '#' . $hash : '';
    return base_path() . '/admin' . ($qs ? '?' . $qs : '') . $hashPart;
}

function build_dashboard_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/dashboard' . ($qs ? '?' . $qs : '') . '#shares';
}

function build_access_stats_query_url(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return base_path() . '/dashboard' . ($qs ? '?' . $qs : '') . '#access-stats';
}

function render_hidden_inputs(array $values): string {
    $html = '';
    foreach ($values as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= '<input type="hidden" name="' . htmlspecialchars((string)$key) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html;
}

function bytes_from_mb(int $mb): int {
    if ($mb <= 0) {
        return 0;
    }
    return $mb * 1024 * 1024;
}

function mb_from_bytes(int $bytes): int {
    if ($bytes <= 0) {
        return 0;
    }
    return (int)floor($bytes / 1024 / 1024);
}

function render_share_icon_defs(): string {
    return <<<'SVG'
<svg class="kb-icon-defs" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">
  <symbol id="sps-tree-arrow-collapsed" viewBox="0 0 24 24" fill="none">
    <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-arrow-expanded" viewBox="0 0 24 24" fill="none">
    <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-collapse-all" viewBox="0 0 24 24" fill="none">
    <path d="M4 20L11 13M11 13V17M11 13H7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M20 4L13 11M13 11V7M13 11H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="sps-tree-expand-all" viewBox="0 0 24 24" fill="none">
    <path d="M11 13L4 20M4 20V16M4 20H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M13 11L20 4M20 4V8M20 4H16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
</svg>
SVG;
}

function render_page(string $title, string $content, ?array $user = null, string $baseHref = '', array $options = []): void {
    global $config;
    $base = base_path();
    $app = htmlspecialchars($config['app_name']);
    $pageTitle = htmlspecialchars($title);
    $userName = $user ? htmlspecialchars($user['username']) : '';
    $layout = isset($options['layout']) ? (string)$options['layout'] : ($user ? 'app' : 'public');
    $titleHtml = $pageTitle;
    if (!empty($options['title_html'])) {
        $titleHtml = (string)$options['title_html'];
    }
    $layoutClass = 'layout-' . preg_replace('/[^a-z0-9_-]+/i', '', $layout);
    $includeMarkdown = !empty($options['markdown']);
    $navKey = (string)($options['nav'] ?? '');
    if ($user && $layout !== 'auth' && $layout !== 'share') {
        maybe_send_instance_heartbeat();
    }
    echo "<!doctype html>";
    echo "<html lang='zh-CN'>";
    echo "<head>";
    echo "<meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1'>";
    echo "<title>{$pageTitle} - {$app}</title>";
    if ($baseHref !== '') {
        $safeBase = htmlspecialchars($baseHref);
        echo "<base href='{$safeBase}'>";
    }
    if ($includeMarkdown) {
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/github-markdown.min.css'>";
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/katex.min.css'>";
        echo "<link rel='stylesheet' href='{$base}/assets/vendor/highlight.min.css'>";
    }
    echo "<link rel='stylesheet' href='" . asset_url('/assets/style.css') . "'>";
    $siteCustomCssEnabled = get_bool_setting('site_custom_css_enabled', true);
    $siteCustomCss = (string)get_setting('site_custom_css', '');
    if ($siteCustomCssEnabled && trim($siteCustomCss) !== '') {
        $safeCustomCss = escape_inline_style_content($siteCustomCss);
        echo "<style id='site-custom-css'>\n{$safeCustomCss}\n</style>";
    }
    $siteHeadHtml = (string)get_setting('site_head_html', '');
    if ($siteHeadHtml !== '') {
        echo $siteHeadHtml;
    }
    echo "</head>";
    echo "<body class='{$layoutClass}'>";

    if ($layout === 'auth') {
        echo "<div class='auth-shell'>";
        echo $content;
        echo "</div>";
    } elseif ($layout === 'share') {
        echo "<div class='share-page'>";
        echo render_share_icon_defs();
        if (!empty($options['announcement'])) {
            echo $options['announcement'];
        }
        echo $content;
        echo "<footer class='share-footer'>由 <a href='https://github.com/804260247/siyuan-plugin-share' target='_blank' rel='noopener noreferrer'>804260247</a> 提供支持</footer>";
        echo "<button class='share-side-trigger' type='button' data-share-drawer-open aria-label='打开侧边栏'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M4 6h16v2H4zM4 11h16v2H4zM4 16h16v2H4z'/></svg><span>导航</span></button>";
        echo "<div class='share-side-backdrop' data-share-drawer-close></div>";
        echo "<button class='scroll-top' type='button' data-scroll-top data-tip='回到顶部' aria-label='回到顶部'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M12 2c-2.76 0-5 2.24-5 5v2.5L4 13l4.5 1L12 22l3.5-8L20 13l-3-3.5V7c0-2.76-2.24-5-5-5zm0 3a2 2 0 0 1 2 2v1.5l-2 2-2-2V7a2 2 0 0 1 2-2z'/></svg></button>";
        echo "</div>";
    } elseif ($user) {
        $navItems = [
            ['key' => 'dashboard', 'label' => '控制台', 'href' => $base . '/dashboard'],
            ['key' => 'shares', 'label' => '分享记录', 'href' => $base . '/dashboard#shares'],
            ['key' => 'access-stats', 'label' => '访问统计', 'href' => $base . '/dashboard#access-stats'],
            ['key' => 'account', 'label' => '账号设置', 'href' => $base . '/account'],
        ];
        if (($user['role'] ?? '') === 'admin') {
            $reportBadge = pending_report_count();
            $navItems = [
                ['key' => 'dashboard', 'label' => '控制台', 'href' => $base . '/dashboard'],
                ['key' => 'account', 'label' => '账号设置', 'href' => $base . '/account'],
                ['key' => 'admin-home', 'label' => '数据统计', 'href' => $base . '/admin-home'],
                ['key' => 'admin-settings', 'label' => '站点设置', 'href' => $base . '/admin#settings'],
                ['key' => 'admin-announcements', 'label' => '公告管理', 'href' => $base . '/admin#announcements'],
                ['key' => 'admin-reports', 'label' => '举报管理', 'href' => $base . '/admin#reports', 'badge' => $reportBadge],
                ['key' => 'admin-users', 'label' => '用户管理', 'href' => $base . '/admin#users'],
                ['key' => 'admin-shares', 'label' => '分享管理', 'href' => $base . '/admin#shares'],
                ['key' => 'admin-netdisk', 'label' => '网盘配置', 'href' => $base . '/admin#netdisk'],
                ['key' => 'admin-chunks', 'label' => '分片清理', 'href' => $base . '/admin#chunks'],
                ['key' => 'admin-scan', 'label' => '违禁词扫描', 'href' => $base . '/admin#scan'],
            ];
        }
        echo "<div class='app-shell'>";
        echo "<aside class='app-sidebar'>";
        echo "<div class='app-logo'>{$app}</div>";
        echo "<nav class='app-nav'>";
        foreach ($navItems as $item) {
            $active = $navKey === $item['key'] ? ' is-active' : '';
            $label = htmlspecialchars($item['label']);
            $badge = (int)($item['badge'] ?? 0);
            $hrefRaw = (string)$item['href'];
            $hashPos = strpos($hrefRaw, '#');
            $pathOnly = $hashPos === false ? $hrefRaw : substr($hrefRaw, 0, $hashPos);
            $hash = $hashPos === false ? '' : substr($hrefRaw, $hashPos + 1);
            $href = htmlspecialchars($hrefRaw);
            $dataAttrs = ' data-nav-key="' . htmlspecialchars((string)$item['key']) . '"';
            $dataAttrs .= ' data-nav-path="' . htmlspecialchars($pathOnly) . '"';
            if ($hash !== '') {
                $dataAttrs .= ' data-nav-hash="' . htmlspecialchars($hash) . '"';
            }
            echo "<a class='nav-item{$active}' href='{$href}'{$dataAttrs}><span class='nav-dot'></span><span class='nav-label'>{$label}</span>";
            if ($badge > 0) {
                echo "<span class='nav-badge'>{$badge}</span>";
            }
            echo "</a>";
        }
        echo "</nav>";
        echo "</aside>";
        echo "<div class='app-main'>";
        echo "<header class='app-topbar'>";
        echo "<div class='topbar-left'>";
        echo "<button class='app-side-trigger' type='button' data-app-drawer-open aria-label='打开导航'><svg viewBox='0 0 24 24' aria-hidden='true'><path fill='currentColor' d='M4 6h16v2H4zM4 11h16v2H4zM4 16h16v2H4z'/></svg></button>";
        echo "<div class='topbar-title'>{$titleHtml}</div>";
        echo "</div>";
        echo "<div class='topbar-right'>";
        echo "<span class='user-pill'>{$userName}</span>";
        echo "<form method='post' action='{$base}/logout' class='inline-form'>";
        echo "<input type='hidden' name='csrf' value='" . csrf_token() . "'>";
        echo "<button class='button ghost' type='submit'>退出</button>";
        echo "</form>";
        echo "</div>";
        echo "</header>";
        echo "<main class='app-content'>";
        echo $content;
        echo "</main>";
        echo "<footer class='app-footer'>由 <a href='https://github.com/804260247/siyuan-plugin-share' target='_blank' rel='noopener noreferrer'>804260247</a> 提供支持</footer>";
        echo "</div>";
        echo "</div>";
        echo "<div class='app-side-backdrop' data-app-drawer-close></div>";
    } else {
        echo "<div class='public-shell'>";
        echo $content;
        echo "</div>";
    }

    if ($user && $layout === 'app') {
        $announcements = get_active_announcements();
        if (should_show_announcement_modal($announcements)) {
            echo render_announcement_modal($announcements);
        }
    }

    echo "<script defer src='" . asset_url('/assets/app.js') . "'></script>";
    if ($includeMarkdown) {
        echo "<script defer src='{$base}/assets/vendor/markdown-it.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-task-lists.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-emoji.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-footnote.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-deflist.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-mark.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-sub.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-sup.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-abbr.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-ins.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-container.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/markdown-it-anchor.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/katex.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/highlight.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/mermaid.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/pako.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/echarts.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/abcjs-basic-min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/raphael.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/flowchart.min.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/viz.js'></script>";
        echo "<script defer src='{$base}/assets/vendor/full.render.js' onload=\"window.dispatchEvent(new Event('sps:markdown-ready'))\"></script>";
    }
    $siteCustomJsEnabled = get_bool_setting('site_custom_js_enabled', false);
    $siteCustomJs = (string)get_setting('site_custom_js', '');
    if ($siteCustomJsEnabled && trim($siteCustomJs) !== '') {
        $safeCustomJs = escape_inline_script_content($siteCustomJs);
        echo "<script id='site-custom-js'>\nwindow.addEventListener('DOMContentLoaded', function () {\n{$safeCustomJs}\n});\n</script>";
    }
    echo "</body>";
    echo "</html>";
    exit;
}

function render_auth_mascot(): string {
    return <<<'HTML'
<div class="auth-mascot-pair" aria-hidden="true">
  <div class="auth-mascot auth-mascot--left" data-auth-mascot data-mascot-state="idle">
    <div class="auth-mascot__halo"></div>
    <div class="auth-mascot__head">
      <span class="auth-mascot__ear auth-mascot__ear--left"></span>
      <span class="auth-mascot__ear auth-mascot__ear--right"></span>
      <span class="auth-mascot__eye auth-mascot__eye--left"><span class="auth-mascot__pupil"></span></span>
      <span class="auth-mascot__eye auth-mascot__eye--right"><span class="auth-mascot__pupil"></span></span>
      <span class="auth-mascot__blush auth-mascot__blush--left"></span>
      <span class="auth-mascot__blush auth-mascot__blush--right"></span>
      <span class="auth-mascot__nose"></span>
      <span class="auth-mascot__mouth"></span>
      <span class="auth-mascot__hand auth-mascot__hand--left"></span>
      <span class="auth-mascot__hand auth-mascot__hand--right"></span>
    </div>
  </div>
  <div class="auth-mascot auth-mascot--right" data-auth-mascot data-mascot-state="idle">
    <div class="auth-mascot__halo"></div>
    <div class="auth-mascot__head">
      <span class="auth-mascot__ear auth-mascot__ear--left"></span>
      <span class="auth-mascot__ear auth-mascot__ear--right"></span>
      <span class="auth-mascot__eye auth-mascot__eye--left"><span class="auth-mascot__pupil"></span></span>
      <span class="auth-mascot__eye auth-mascot__eye--right"><span class="auth-mascot__pupil"></span></span>
      <span class="auth-mascot__blush auth-mascot__blush--left"></span>
      <span class="auth-mascot__blush auth-mascot__blush--right"></span>
      <span class="auth-mascot__nose"></span>
      <span class="auth-mascot__mouth"></span>
      <span class="auth-mascot__hand auth-mascot__hand--left"></span>
      <span class="auth-mascot__hand auth-mascot__hand--right"></span>
    </div>
  </div>
</div>
HTML;
}

function render_404_page(): void {
    $base = base_path();
    $content = '<div class="error-page">';
    $content .= '<div class="error-page__scene">';
    $content .= '<div class="error-page__stars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>';
    $content .= '<div class="error-page__planet"><div class="error-page__planet-body"></div><div class="error-page__planet-ring"></div></div>';
    $content .= '<div class="error-page__satellite"></div>';
    $content .= '<div class="error-page__comet"></div>';
    $content .= '</div>';
    $content .= '<h1 class="error-page__code">404</h1>';
    $content .= '<h2 class="error-page__title">页面飞走了</h2>';
    $content .= '<p class="error-page__desc">你访问的页面不存在，可能已被移除或者地址有误。</p>';
    $content .= '<a class="error-page__back" href="' . $base . '/">';
    $content .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
    $content .= '返回首页</a>';
    $content .= '</div>';
    render_page('页面未找到', $content, null);
}

function render_share_not_found_page(): void {
    $base = base_path();
    $content = '<div class="error-page">';
    $content .= '<div class="error-page__scene">';
    $content .= '<div class="error-page__stars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>';
    $content .= '<div class="error-page__signal"><i></i><i></i><i></i><i></i><div class="error-page__signal-core"></div></div>';
    $content .= '</div>';
    $content .= '<h2 class="error-page__title">找不到这个分享</h2>';
    $content .= '<p class="error-page__desc">该分享链接不存在或已被创建者删除。</p>';
    $content .= '<a class="error-page__back" href="' . $base . '/">';
    $content .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';
    $content .= '返回首页</a>';
    $content .= '</div>';
    render_page('分享不存在', $content, null);
}

function generate_captcha_code(int $length = 5): string {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_at'] = time();
    return $code;
}

function captcha_url(): string {
    $base = base_path();
    return $base . '/captcha?ts=' . time();
}

function check_captcha(string $input): bool {
    if (!captcha_enabled()) {
        return true;
    }
    $expected = $_SESSION['captcha_code'] ?? '';
    $input = strtoupper(trim($input));
    $ok = $expected !== '' && $input === strtoupper($expected);
    if (!$ok) {
        generate_captcha_code();
    }
    return $ok;
}

function render_captcha_image(): void {
    if (!captcha_enabled()) {
        http_response_code(404);
        exit;
    }
    if (!function_exists('imagecreatetruecolor')) {
        http_response_code(500);
        echo 'GD extension required.';
        exit;
    }
    $code = generate_captcha_code();
    $width = 120;
    $height = 40;
    $scale = 1.2;
    $outWidth = (int)round($width * $scale);
    $outHeight = (int)round($height * $scale);
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        http_response_code(500);
        exit;
    }
    $bg = imagecolorallocate($image, 245, 248, 255);
    $fg = imagecolorallocate($image, 50, 88, 160);
    $noise = imagecolorallocate($image, 200, 210, 230);
    imagefilledrectangle($image, 0, 0, $width, $height, $bg);
    for ($i = 0; $i < 6; $i++) {
        imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $noise);
    }
    for ($i = 0; $i < 60; $i++) {
        imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noise);
    }
    $x = 10;
    $y = 10;
    $chars = str_split($code);
    foreach ($chars as $char) {
        imagestring($image, 5, $x, $y + random_int(-3, 3), $char, $fg);
        $x += 18;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $output = $image;
    if ($outWidth !== $width || $outHeight !== $height) {
        $scaled = imagecreatetruecolor($outWidth, $outHeight);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
        imagedestroy($image);
        $output = $scaled;
    }
    imagepng($output);
    imagedestroy($output);
    exit;
}

const SHARE_SLUG_MIN_LENGTH = 6;
const SHARE_SLUG_MAX_LENGTH = 32;

function sanitize_slug(string $slug): string {
    return strtolower(trim($slug));
}

function parse_requested_share_slug_or_fail($raw): string {
    $slug = sanitize_slug((string)$raw);
    if ($slug === '') {
        return '';
    }
    if (!preg_match('/^[a-z0-9]+$/', $slug)) {
        api_response(400, [
            'errorKey' => 'share.slug.invalid_chars',
            'min' => SHARE_SLUG_MIN_LENGTH,
            'max' => SHARE_SLUG_MAX_LENGTH,
        ], 'Invalid share link suffix');
    }
    $len = strlen($slug);
    if ($len < SHARE_SLUG_MIN_LENGTH || $len > SHARE_SLUG_MAX_LENGTH) {
        api_response(400, [
            'errorKey' => 'share.slug.invalid_length',
            'min' => SHARE_SLUG_MIN_LENGTH,
            'max' => SHARE_SLUG_MAX_LENGTH,
        ], 'Invalid share link suffix length');
    }
    return $slug;
}

function share_slug_exists(PDO $pdo, string $slug, int $excludeShareId = 0): bool {
    if ($slug === '') {
        return false;
    }
    if ($excludeShareId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM shares WHERE slug = :slug AND deleted_at IS NULL AND id != :id LIMIT 1');
        $stmt->execute([
            ':slug' => $slug,
            ':id' => $excludeShareId,
        ]);
        return !!$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT id FROM shares WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    return !!$stmt->fetchColumn();
}

function ensure_share_slug_available_or_fail(PDO $pdo, string $slug, int $excludeShareId = 0): void {
    if (!share_slug_exists($pdo, $slug, $excludeShareId)) {
        return;
    }
    api_response(409, [
        'errorKey' => 'share.slug.conflict',
        'min' => SHARE_SLUG_MIN_LENGTH,
        'max' => SHARE_SLUG_MAX_LENGTH,
    ], 'Share link already exists');
}

function allocate_random_share_slug_or_fail(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $slug = sanitize_slug(bin2hex(random_bytes(4)));
        if (!share_slug_exists($pdo, $slug)) {
            return $slug;
        }
    }
    api_response(500, [
        'errorKey' => 'share.slug.generate_failed',
        'min' => SHARE_SLUG_MIN_LENGTH,
        'max' => SHARE_SLUG_MAX_LENGTH,
    ], 'Unable to allocate unique share link');
}

function generate_api_key(): array {
    $raw = bin2hex(random_bytes(24));
    $hash = password_hash($raw, PASSWORD_DEFAULT);
    $prefix = substr($raw, 0, 8);
    $last4 = substr($raw, -4);
    return [$raw, $hash, $prefix, $last4];
}

function get_user_limit_bytes(array $user): int {
    $userLimit = isset($user['storage_limit_bytes']) ? (int)$user['storage_limit_bytes'] : 0;
    if ($userLimit > 0) {
        return $userLimit;
    }
    return default_storage_limit_bytes();
}

// 单行存在性检查的统一写法：取值后立即关游标，避免连接持读快照期间再发写语句
// （SQLite WAL 下的"读升级写"死锁：database is locked 立即返回，busy_timeout 不生效）。
function pdo_fetch_exists(PDOStatement $stmt): bool {
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $row !== false;
}

function recalculate_user_storage(int $userId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM shares WHERE user_id = :uid AND deleted_at IS NULL');
    $stmt->execute([':uid' => $userId]);
    $shareTotal = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    // 单行 fetch 后必须关游标：连接保持读快照时再发 UPDATE 属"读升级写"，
    // 若此刻 nd-worker 等其它写者持锁，SQLite 立即报 database is locked，busy_timeout 不生效。
    $stmt->closeCursor();
    $logStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $logStmt->execute([':uid' => $userId]);
    $logTotal = (int)($logStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $logStmt->closeCursor();
    $total = $shareTotal + $logTotal;
    try {
        netdisk_exec_retry(function () use ($pdo, $total, $userId) {
            $update = $pdo->prepare('UPDATE users SET storage_used_bytes = :total WHERE id = :id');
            $update->execute([':total' => $total, ':id' => $userId]);
            $update->closeCursor();
        }, 6);
    } catch (Throwable $e) {
        // 重试耗尽（多为页面渲染路径撞后台写锁）：降级返回库中已存值，绝不向上抛，
        // 存储数显示可能短暂滞后，下一次成功重算即校正。
        $fallback = $pdo->prepare('SELECT storage_used_bytes FROM users WHERE id = :id');
        $fallback->execute([':id' => $userId]);
        $stored = (int)($fallback->fetchColumn() ?: 0);
        $fallback->closeCursor();
        return $stored;
    }
    return $total;
}

// 批量重算多个用户的已用存储：整批 1 次 UNION ALL 聚合 + 1 次已存值查询，
// 仅对值发生变化的用户补 1 次 CASE UPDATE（数字正确时零写入）。
// 供控制台用户列表等渲染场景使用，避免逐用户"读后写"在 SQLite WAL 下触发升级死锁。
// 返回 [userId => total]；UPDATE 重试耗尽时降级读取库中已存值，绝不向上抛。
function recalculate_users_storage_batch(array $userIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($v) => $v > 0)));
    if (empty($ids)) {
        return [];
    }
    $pdo = db();
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $totals = [];
    $agg = $pdo->prepare("SELECT user_id, COALESCE(SUM(size_bytes), 0) AS total FROM (
            SELECT user_id, size_bytes FROM shares WHERE deleted_at IS NULL AND user_id IN ($ph)
            UNION ALL
            SELECT user_id, size_bytes FROM share_access_logs WHERE user_id IN ($ph)
        ) GROUP BY user_id");
    $agg->execute(array_merge($ids, $ids));
    foreach ($agg->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $totals[(int)$row['user_id']] = (int)$row['total'];
    }
    $agg->closeCursor();
    // 已存列值：用于跳过无变化的 UPDATE（控制台每次刷新都重算，大多数用户值不变，
    // 跳过写入才能从根源上减少渲染路径与 nd-worker 撞锁的机会）；写锁耗尽时也用它降级。
    $stored = [];
    $st = $pdo->prepare("SELECT id, storage_used_bytes FROM users WHERE id IN ($ph)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stored[(int)$row['id']] = (int)$row['storage_used_bytes'];
    }
    $st->closeCursor();
    foreach ($ids as $uid) {
        if (!isset($totals[$uid])) {
            $totals[$uid] = 0;
        }
    }
    $dirty = [];
    foreach ($totals as $uid => $total) {
        if ($total !== ($stored[$uid] ?? $total)) {
            $dirty[$uid] = $total;
        }
    }
    if ($dirty) {
        // id/total 均为 int 强转后拼接，无注入面
        $cases = [];
        foreach ($dirty as $uid => $total) {
            $cases[] = "WHEN $uid THEN $total";
        }
        $sql = 'UPDATE users SET storage_used_bytes = CASE id ' . implode(' ', $cases)
            . ' ELSE storage_used_bytes END WHERE id IN (' . implode(',', array_keys($dirty)) . ')';
        try {
            netdisk_exec_retry(function () use ($pdo, $sql) {
                $upd = $pdo->prepare($sql);
                $upd->execute();
                $upd->closeCursor();
            }, 6);
        } catch (Throwable $e) {
            // 写锁耗尽：降级为库中已存值（渲染路径，不允许抛错中断页面；下次成功重算自校正）
            foreach ($dirty as $uid => $total) {
                $totals[$uid] = $stored[$uid] ?? $total;
            }
        }
    }
    return $totals;
}

function adjust_user_storage(int $userId, int $delta): void {
    if ($delta === 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET storage_used_bytes = CASE
        WHEN storage_used_bytes + :delta < 0 THEN 0
        ELSE storage_used_bytes + :delta
    END WHERE id = :id');
    $stmt->execute([
        ':delta' => $delta,
        ':id' => $userId,
    ]);
}

function get_user_by_id(int $userId): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_client_ip(): string {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];
    foreach ($candidates as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
            $value = $parts[0] ?? '';
        }
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function get_visitor_id(): string {
    $existing = trim((string)($_COOKIE['sps_uv'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }
    $id = bin2hex(random_bytes(12));
    setcookie('sps_uv', $id, time() + 86400 * 365, '/', '', false, true);
    $_COOKIE['sps_uv'] = $id;
    return $id;
}

function http_get_json(string $url): ?array {
    $context = stream_context_create([
        'http' => [
            'timeout' => 2,
            'header' => "User-Agent: SiyuanShareAccessStats\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $converted = null;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GBK,GB2312,GB18030');
    }
    if ($converted === null && function_exists('iconv')) {
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
        if ($converted === false || $converted === '') {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
        }
    }
    if ($converted) {
        $json = json_decode($converted, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

function http_post_json(string $url, array $payload): ?array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 2,
            'header' => "User-Agent: SiyuanShareAccessStats\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    $converted = null;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GBK,GB2312,GB18030');
    }
    if ($converted === null && function_exists('iconv')) {
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
        if ($converted === false || $converted === '') {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $raw);
        }
    }
    if ($converted) {
        $json = json_decode($converted, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return null;
}

/* ============================================================
 * Cloudreve 网盘集成（Netdisk）
 * ------------------------------------------------------------
 * 将 Cloudreve 作为“网盘”嵌入到分享页。管理员在后台配置
 * Cloudreve 连接（服务账号），并为指定分享开启网盘、指定根目录
 * 与权限（view/edit）。访客在分享页浏览/预览，office/PDF 通过
 * Cloudreve 的 WOPI（OnlyOffice / Collabora）在线编辑。
 * ============================================================ */

function cloudreve_configs_all(?PDO $pdo = null): array {
    if ($pdo === null) {
        $pdo = db();
    }
    $stmt = $pdo->query('SELECT * FROM cloudreve_configs ORDER BY id ASC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function cloudreve_config_get(int $id, ?PDO $pdo = null): ?array {
    if ($pdo === null) {
        $pdo = db();
    }
    $stmt = $pdo->prepare('SELECT * FROM cloudreve_configs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $row ?: null;
}

// 网盘密钥：优先环境变量，其次 storage/.netdisk_secret（0600，首次自动生成）。
// 密钥与 SQLite 分离存放，避免数据库备份泄露时连带泄露账号密码。
function netdisk_secret_key(): string {
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $env = getenv('NETDISK_SECRET');
    if (is_string($env) && strlen($env) >= 32) {
        $key = substr(hash('sha256', $env, true), 0, 32);
        return $key;
    }
    $storage = dirname((string)($GLOBALS['config']['db_path'] ?? (__DIR__ . '/storage/app.db')));
    $path = $storage . '/.netdisk_secret';
    $raw = @file_get_contents($path);
    if (is_string($raw) && strlen($raw) >= 32) {
        $key = substr($raw, 0, 32);
        return $key;
    }
    if (!is_dir($storage)) {
        @mkdir($storage, 0770, true);
    }
    $new = random_bytes(32);
    if (@file_put_contents($path, $new, LOCK_EX) === false) {
        error_log('[netdisk] 无法写入密钥文件 ' . $path . '，网盘密码将无法加密保存');
        $key = '';
        return $key;
    }
    @chmod($path, 0600);
    $key = $new;
    return $key;
}

function netdisk_encrypt_secret(string $plain): string {
    if ($plain === '') {
        return '';
    }
    $key = netdisk_secret_key();
    if ($key === '' || !function_exists('openssl_encrypt')) {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        return '';
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

function netdisk_decrypt_secret(string $value): string {
    if ($value === '' || !str_starts_with($value, 'enc:v1:')) {
        return $value;
    }
    $raw = base64_decode(substr($value, 7), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        return '';
    }
    $key = netdisk_secret_key();
    if ($key === '' || !function_exists('openssl_decrypt')) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return is_string($plain) ? $plain : '';
}

function cloudreve_conn(array $cfg): array {
    return [
        'id' => (int)($cfg['id'] ?? 0),
        'base_url' => rtrim((string)($cfg['base_url'] ?? ''), '/'),
        'email' => (string)($cfg['email'] ?? ''),
        'password' => netdisk_decrypt_secret((string)($cfg['password'] ?? '')),
        'viewer_id' => (string)($cfg['viewer_id'] ?? ''),
    ];
}

function cloudreve_enabled(): bool {
    $cfgs = cloudreve_configs_all();
    foreach ($cfgs as $cfg) {
        if ((int)($cfg['enabled'] ?? 0) === 1 && trim((string)($cfg['base_url'] ?? '')) !== '' && trim((string)($cfg['email'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

// 网盘标签渲染状态：与分享页侧边栏门槛完全同口径（netdisk_share_config + cloudreve_enabled），
// 并额外细分「为什么没按钮」，供控制台「分享绑定 → 网盘标签」列直接展示诊断结果，
// 避免分享页静默隐藏（既不渲染按钮也不报错）让用户自己猜。
// $docCounts: 可选的 share_id => 文档数 映射（多分享场景批量预取，避免循环内逐条查询）。
function netdisk_tab_state(array $share, ?array $docCounts = null, ?PDO $pdo = null): array {
    $base = netdisk_share_config($share, $pdo);
    if (!$base['enabled']) {
        return ['show' => false, 'reason' => '未开启'];
    }
    if (!cloudreve_enabled()) {
        return ['show' => false, 'reason' => '无可用 Cloudreve 连接（需启用且填写地址与邮箱）'];
    }
    if (!$base['cfg']) {
        if ((int)($base['config_id'] ?? 0) <= 0) {
            return ['show' => false, 'reason' => '未绑定连接，且启用中的连接不唯一，请在「绑定连接」中选择'];
        }
        return ['show' => false, 'reason' => '绑定的连接不存在，请重新绑定'];
    }
    if ((int)($base['cfg']['enabled'] ?? 0) !== 1) {
        return ['show' => false, 'reason' => '绑定的连接已停用'];
    }
    if (trim((string)($base['cfg']['base_url'] ?? '')) === '' || trim((string)($base['cfg']['email'] ?? '')) === '') {
        return ['show' => false, 'reason' => '绑定连接缺少地址或邮箱'];
    }
    if ((string)($share['type'] ?? '') === 'doc' && $docCounts !== null) {
        $count = (int)($docCounts[(int)($share['id'] ?? 0)] ?? 0);
        if ($count <= 1) {
            return ['show' => false, 'reason' => '单文档分享页不显示网盘标签'];
        }
    }
    return ['show' => true, 'reason' => '可显示'];
}

function cloudreve_http_request(string $method, string $url, ?array $json = null, array $headers = []): ?array {
    cloudreve_http_set_last_error('', 0);
    $headerLines = array_merge([
        'User-Agent: SiyuanShareNetdisk',
        'Content-Type: application/json',
    ], $headers);
    // 用 curl 实现，保证连接/传输可靠超时（file_get_contents 的 http.timeout 在 DNS/SSL 阶段可能不生效，
    // 曾导致 worker 在遍历目录时永久挂起、心跳停止）。
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE),
        ]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $raw = @curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            if ($errno !== 0 || $err !== '') {
                // curl 错误文案归一化成 netdisk_office_http_classify 认识的关键词
                $norm = $err;
                if ($errno === 6) { $norm = 'Could not resolve host: ' . $err; }
                elseif ($errno === 7) { $norm = 'Connection refused: ' . $err; }
                elseif ($errno === 28) { $norm = 'Connection timed out: ' . $err; }
                elseif ($errno === 35 || $errno === 60 || $errno === 51 || $errno === 58 || $errno === 59 || $errno === 53) { $norm = 'SSL certificate problem: ' . $err; }
                cloudreve_http_set_last_error($norm !== '' ? $norm : ('curl 错误号 ' . $errno), $httpCode);
            } elseif ($httpCode >= 400) {
                cloudreve_http_set_last_error('HTTP 状态码异常：' . $httpCode, $httpCode);
            }
            return null;
        }
        if ($httpCode >= 400) {
            cloudreve_http_set_last_error('HTTP 状态码异常：' . $httpCode, $httpCode);
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    $ctx = [
        'http' => [
            'method' => $method,
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines) . "\r\n",
            'content' => $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE),
        ],
    ];
    $lastErr = '';
    set_error_handler(function ($no, $msg) use (&$lastErr) { $lastErr = $msg; return true; });
    $raw = @file_get_contents($url, false, stream_context_create($ctx));
    restore_error_handler();
    $statusLine = isset($http_response_header[0]) && is_string($http_response_header[0]) ? $http_response_header[0] : '';
    if ($raw === false) {
        if ($lastErr !== '') {
            cloudreve_http_set_last_error($lastErr, 0);
        }
        return null;
    }
    if (preg_match('#HTTP/\S+\s+([1-5]\d\d)#', $statusLine, $sm) && (int)$sm[1] >= 400) {
        cloudreve_http_set_last_error('HTTP 状态码异常：' . $statusLine, (int)$sm[1]);
        $decoded4 = json_decode($raw, true);
        if (is_array($decoded4)) { return $decoded4; }
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

// 最近一次 cloudreve_http_request 的传输层错误（连接/DNS/超时/TLS），成功或未捕获时为空串
function cloudreve_http_last_error(): string {
    return (string)($GLOBALS['__cloudreve_http_last_error'] ?? '');
}

function cloudreve_http_set_last_error(string $msg, int $httpCode = 0): void {
    $GLOBALS['__cloudreve_http_last_error'] = $msg;
    $GLOBALS['__cloudreve_http_last_code'] = $httpCode;
}

// 最近一次 cloudreve_http_request 失败的 HTTP 状态码（0 表示未拿到响应）
function cloudreve_http_last_code(): int {
    return (int)($GLOBALS['__cloudreve_http_last_code'] ?? 0);
}

// 对应某条连接配置的 token 缓存键
function cloudreve_token_key(int $id, string $part): string {
    return 'cloudreve_token_' . $id . '_' . $part;
}

function cloudreve_login(array $cfg): bool {
    $id = (int)($cfg['id'] ?? 0);
    $c = cloudreve_conn($cfg);
    if ($c['base_url'] === '' || $c['email'] === '' || $c['password'] === '') {
        return false;
    }
    $resp = cloudreve_http_request('POST', $c['base_url'] . '/api/v4/session/token', [
        'email' => $c['email'],
        'password' => $c['password'],
    ]);
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return false;
    }
    $token = $resp['data']['token'] ?? null;
    if (!is_array($token) || empty($token['access_token'])) {
        return false;
    }
    cloudreve_store_login_tokens($id, $token);
    return true;
}

// cloudreve_login 的带原因版本：返回 ['ok'=>bool, 'reason'=>string]，reason 供控制台「测试连接」透传，
// 绝不包含密码或 token。细分四类：解密失败（口令字段无法还原）/ 服务不可访问 / 账号或密码错误（带 Cloudreve msg 与状态码）/ 响应异常。
function cloudreve_login_detailed(array $cfg): array {
    $id = (int)($cfg['id'] ?? 0);
    $stored = trim((string)($cfg['password'] ?? ''));
    $c = cloudreve_conn($cfg);
    if ($stored !== '' && $c['password'] === '') {
        return ['ok' => false, 'reason' => '已存密码解密失败（storage/.netdisk_secret 与加密时不一致），请编辑连接后重新输入密码'];
    }
    if ($c['base_url'] === '' || $c['email'] === '' || $c['password'] === '') {
        return ['ok' => false, 'reason' => '地址/账号/密码不能为空'];
    }
    $resp = cloudreve_http_request('POST', $c['base_url'] . '/api/v4/session/token', [
        'email' => $c['email'],
        'password' => $c['password'],
    ]);
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        $code = (int)cloudreve_http_last_code();
        $transport = cloudreve_http_last_error();
        $srvMsg = is_array($resp) ? trim((string)($resp['msg'] ?? '')) : '';
        // 401/403：服务可达但凭据被拒 —— 优先按账号密码错误报，不要被传输层文案掩盖
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'reason' => '账号或密码错误（HTTP ' . $code . '）' . ($srvMsg !== '' ? '：' . $srvMsg : '')];
        }
        if ($transport !== '') {
            return ['ok' => false, 'reason' => '服务不可访问：' . netdisk_office_http_classify($transport)];
        }
        if (is_array($resp)) {
            return ['ok' => false, 'reason' => '登录被拒绝' . ($code ? '（HTTP ' . $code . '）' : '') . ($srvMsg !== '' ? '：' . $srvMsg : '')];
        }
        return ['ok' => false, 'reason' => '登录请求失败：未能收到有效 JSON 响应'];
    }
    $token = $resp['data']['token'] ?? null;
    if (!is_array($token) || empty($token['access_token'])) {
        return ['ok' => false, 'reason' => '登录响应异常：未返回 access_token'];
    }
    cloudreve_store_login_tokens($id, $token);
    return ['ok' => true, 'reason' => ''];
}

// 登录成功后写入某条连接的 access/refresh token 缓存（cloudreve_login 与 cloudreve_login_detailed 共用）
function cloudreve_store_login_tokens(int $id, array $token): void {
    set_setting(cloudreve_token_key($id, 'access'), netdisk_encrypt_secret((string)$token['access_token']));
    set_setting(cloudreve_token_key($id, 'refresh'), netdisk_encrypt_secret((string)($token['refresh_token'] ?? '')));
    $exp = strtotime((string)($token['access_expires'] ?? ''));
    set_setting(cloudreve_token_key($id, 'expires'), (string)($exp ? $exp : 0));
}

function cloudreve_access_token(array $cfg): string {
    $id = (int)($cfg['id'] ?? 0);
    $token = netdisk_decrypt_secret((string)get_setting(cloudreve_token_key($id, 'access'), ''));
    $expires = (int)get_setting(cloudreve_token_key($id, 'expires'), '0');
    if ($token !== '' && ($expires === 0 || $expires > time() + 120)) {
        return $token;
    }
    $c = cloudreve_conn($cfg);
    if ($c['base_url'] !== '') {
        $refresh = netdisk_decrypt_secret((string)get_setting(cloudreve_token_key($id, 'refresh'), ''));
        if ($refresh !== '') {
            $resp = cloudreve_http_request('POST', $c['base_url'] . '/api/v4/session/token/refresh', ['refresh_token' => $refresh]);
            if ($resp && (int)($resp['code'] ?? 1) === 0) {
                $tok = $resp['data']['token'] ?? $resp['data'] ?? null;
                if (is_array($tok) && !empty($tok['access_token'])) {
                    set_setting(cloudreve_token_key($id, 'access'), netdisk_encrypt_secret((string)$tok['access_token']));
                    if (!empty($tok['refresh_token'])) {
                        set_setting(cloudreve_token_key($id, 'refresh'), netdisk_encrypt_secret((string)$tok['refresh_token']));
                    }
                    $e = strtotime((string)($tok['access_expires'] ?? ''));
                    set_setting(cloudreve_token_key($id, 'expires'), (string)($e ? $e : 0));
                    return (string)$tok['access_token'];
                }
            }
        }
        if (cloudreve_login($cfg)) {
            return netdisk_decrypt_secret((string)get_setting(cloudreve_token_key($id, 'access'), ''));
        }
    }
    return '';
}

function cloudreve_api(array $cfg, string $method, string $path, ?array $json = null): ?array {
    $c = cloudreve_conn($cfg);
    $token = cloudreve_access_token($cfg);
    if ($token === '' || $c['base_url'] === '') {
        return null;
    }
    return cloudreve_http_request($method, $c['base_url'] . '/api/v4' . $path, $json, [
        'Authorization: Bearer ' . $token,
    ]);
}

function netdisk_share_config(array $share, ?PDO $pdo = null): array {
    $configId = (int)($share['netdisk_config_id'] ?? 0);
    $cfg = $configId > 0 ? cloudreve_config_get($configId, $pdo) : null;
    // 未绑定连接时，若恰好只有一个启用中的连接，则自动使用它（单连接场景免绑定）
    if (!$cfg) {
        $all = cloudreve_configs_all($pdo);
        $enabledList = array_values(array_filter($all, fn($c) => (int)($c['enabled'] ?? 0) === 1));
        if (count($enabledList) === 1) {
            $cfg = $enabledList[0];
            $configId = (int)$cfg['id'];
        }
    }
    $root = trim((string)($share['netdisk_root'] ?? ''));
    if ($root === '' && $cfg) {
        $root = trim((string)($cfg['default_root'] ?? ''));
    }
    return [
        'enabled' => (int)($share['netdisk_enabled'] ?? 0) === 1,
        'config_id' => $configId,
        'root' => $root,
        'permission' => ((string)($share['netdisk_permission'] ?? 'view') === 'edit') ? 'edit' : 'view',
        'cfg' => $cfg,
    ];
}

function netdisk_default_root_uri(): string {
    return 'cloudreve://my';
}

function netdisk_normalize_root(?string $root): string {
    $root = trim((string)$root);
    if ($root === '') {
        return netdisk_default_root_uri();
    }
    return rtrim($root, '/');
}

function cloudreve_list_folder(array $cfg, string $uri): ?array {
    $resp = cloudreve_api($cfg, 'GET', '/file?uri=' . rawurlencode($uri) . '&page_size=1000&order_by=name&order_direction=asc');
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return null;
    }
    return $resp['data'] ?? null;
}

// 网盘短期缓存：目录列表 / 搜索结果 / 文件名-uri 解析（SQLite，TTL 由调用方指定）
function netdisk_cache_get(string $key): ?string {
    $stmt = db()->prepare('SELECT payload FROM netdisk_cache WHERE cache_key = :k AND expires > :now');
    $stmt->execute([':k' => $key, ':now' => time()]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

function netdisk_cache_set(string $key, string $payload, int $ttl): void {
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO netdisk_cache (cache_key, payload, expires) VALUES (:k, :p, :e)
        ON CONFLICT(cache_key) DO UPDATE SET payload = :p2, expires = :e2');
    $stmt->execute([':k' => $key, ':p' => $payload, ':e' => time() + $ttl, ':p2' => $payload, ':e2' => time() + $ttl]);
}

function netdisk_cache_clear(): void {
    db()->exec('DELETE FROM netdisk_cache');
}

// 目录列表带缓存：同一分享/配置的目录 60s 内复用，避免重复检索时反复请求 Cloudreve
function cloudreve_list_folder_cached(array $cfg, string $uri, int $ttl = 60): ?array {
    $connId = (int)($cfg['cfg'] ? ($cfg['cfg']['id'] ?? 0) : ($cfg['id'] ?? 0));
    $key = 'nd_dir_' . $connId . '_' . md5($uri);
    $cached = netdisk_cache_get($key);
    if ($cached !== null) {
        $data = json_decode($cached, true);
        if (is_array($data)) {
            return $data;
        }
    }
    $data = cloudreve_list_folder($cfg['cfg'] ?? $cfg, $uri);
    if (is_array($data)) {
        netdisk_cache_set($key, json_encode($data, JSON_UNESCAPED_UNICODE), $ttl);
    }
    return $data;
}

// 批量并行拉取多个目录（curl_multi），返回 uri => data|null
function cloudreve_list_folder_multi(array $cfg, array $uris, int $ttl = 60): array {
    $out = [];
    if (empty($uris)) {
        return $out;
    }
    $conn = $cfg['cfg'] ?? $cfg;
    $connId = (int)($conn['id'] ?? 0);
    $base = (string)($conn['base_url'] ?? '');
    $token = cloudreve_access_token($conn);
    if ($base === '' || $token === '') {
        foreach ($uris as $u) {
            $out[$u] = null;
        }
        return $out;
    }
    // 先查缓存
    $todo = [];
    foreach ($uris as $u) {
        $key = 'nd_dir_' . $connId . '_' . md5($u);
        $cached = netdisk_cache_get($key);
        if ($cached !== null) {
            $data = json_decode($cached, true);
            $out[$u] = is_array($data) ? $data : null;
        } else {
            $todo[$u] = $key;
        }
    }
    if (empty($todo)) {
        return $out;
    }
    // curl_multi 并发拉取未命中目录
    $mh = curl_multi_init();
    $handles = [];
    foreach ($todo as $u => $key) {
        $ch = curl_init($base . '/api/v4/file?uri=' . rawurlencode($u) . '&page_size=1000&order_by=name&order_direction=asc');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'User-Agent: SiyuanShareNetdisk',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch' => $ch, 'uri' => $u, 'key' => $key];
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);
    foreach ($handles as $h) {
        $raw = curl_multi_getcontent($h['ch']);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $data = (is_array($decoded) && (int)($decoded['code'] ?? 1) === 0) ? ($decoded['data'] ?? null) : null;
        $out[$h['uri']] = is_array($data) ? $data : null;
        if (is_array($data)) {
            netdisk_cache_set($h['key'], json_encode($data, JSON_UNESCAPED_UNICODE), $ttl);
        }
    }
    curl_multi_close($mh);
    return $out;
}

// 判断是否为可本地抓取正文的纯文本类型
function netdisk_is_local_text_ext(string $name): bool {
    return netdisk_is_text_ext(netdisk_file_ext($name));
}

function netdisk_is_text_ext(string $ext): bool {
    $ext = strtolower(ltrim($ext, '.'));
    $textExts = ['txt', 'md', 'markdown', 'csv', 'log', 'json', 'xml', 'yaml', 'yml', 'ini', 'conf', 'cfg', 'srt', 'vtt',
        'py', 'js', 'ts', 'html', 'htm', 'css', 'sql', 'sh', 'bash', 'java', 'c', 'cpp', 'h', 'go', 'rb', 'php', 'env', 'properties'];
    return in_array($ext, $textExts, true);
}

// 抓取单个纯文本文件正文（供本地文本兜底检索），失败返回 null
function netdisk_fetch_text_content(array $cfg, string $uri, string $name): ?string {
    $urls = cloudreve_get_file_urls($cfg, [$uri]);
    if (empty($urls) || empty($urls[0]['url'])) {
        return null;
    }
    $fileUrl = (string)$urls[0]['url'];
    if (function_exists('curl_init')) {
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = @curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 1],
        ]);
        $raw = @file_get_contents($fileUrl, false, $ctx);
    }
    if ($raw === false) {
        return null;
    }
    return netdisk_text_body_to_utf8((string)$raw);
}

// 把原始字节按文本解码为 UTF-8；非文本/无法识别返回 null
function netdisk_text_body_to_utf8(string $raw): ?string {
    if ($raw === '') {
        return null;
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $detected = mb_detect_encoding($raw, ['UTF-8', 'GB18030', 'GBK', 'BIG-5', 'ASCII'], true);
        if ($detected !== false) {
            $raw = mb_convert_encoding($raw, 'UTF-8', $detected);
        } else {
            return null;
        }
    }
    return $raw;
}

// 批量并行抓取多个纯文本文件正文（一次 /file/url + curl_multi 并发），返回 uri => body|null
function netdisk_fetch_text_content_multi(array $cfg, array $uris): array {
    $out = [];
    if (empty($uris)) {
        return $out;
    }
    $uris = array_values($uris);
    $urls = cloudreve_get_file_urls($cfg, $uris);
    if (!is_array($urls)) {
        foreach ($uris as $u) {
            $out[$u] = null;
        }
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($uris as $i => $u) {
        $fileUrl = (string)($urls[$i]['url'] ?? '');
        if ($fileUrl === '') {
            $out[$u] = null;
            continue;
        }
        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['ch' => $ch, 'uri' => $u];
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);
    foreach ($handles as $h) {
        $raw = curl_multi_getcontent($h['ch']);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);
        $out[$h['uri']] = is_string($raw) ? netdisk_text_body_to_utf8($raw) : null;
    }
    curl_multi_close($mh);
    return $out;
}

// 下载单文件原始字节（供正文抽取/OCR），失败返回 null
function netdisk_fetch_raw_bytes(array $cfg, string $uri): ?string {
    $urls = cloudreve_get_file_urls($cfg, [$uri]);
    if (empty($urls) || empty($urls[0]['url'])) {
        return null;
    }
    // 用 curl 下载：连接/总超时比 file_get_contents 更可靠，避免单文件下载挂起拖死整个遍历
    $ch = curl_init((string)$urls[0]['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => 'SiyuanShareNetdisk',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return null;
    }
    return is_string($raw) ? $raw : null;
}

// 从 docx/xlsx/pptx/odt（ZipArchive）抽取纯文本
function netdisk_extract_office_text(string $ext, string $raw): string {
    $ext = strtolower(ltrim($ext, '.'));
    $out = [];
    $tmp = tempnam(sys_get_temp_dir(), 'ndx_');
    if ($tmp === false) {
        return '';
    }
    @file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if (@$zip->open($tmp) !== true) {
        @unlink($tmp);
        return '';
    }
    try {
        if ($ext === 'docx' || $ext === 'docm' || $ext === 'dotx') {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml !== false) {
                $out[] = strip_tags($xml);
            }
        } elseif ($ext === 'xlsx' || $ext === 'xlsm') {
            // 共享字符串 + 单元格
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($ss !== false) {
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $ss, $m);
                $out[] = implode(' ', array_map('html_entity_decode', $m[1]));
            }
            for ($i = 1; $i <= 32; $i++) {
                $sheet = $zip->getFromName('xl/worksheets/sheet' . $i . '.xml');
                if ($sheet === false) {
                    break;
                }
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $sheet, $m);
                $out[] = implode(' ', array_map('html_entity_decode', $m[1]));
            }
        } elseif ($ext === 'pptx' || $ext === 'pptm') {
            for ($i = 1; $i <= 64; $i++) {
                $slide = $zip->getFromName('ppt/slides/slide' . $i . '.xml');
                if ($slide === false) {
                    break;
                }
                $out[] = strip_tags($slide);
            }
        } elseif ($ext === 'odt' || $ext === 'ods' || $ext === 'odp') {
            $content = $zip->getFromName('content.xml');
            if ($content !== false) {
                $out[] = strip_tags($content);
            }
        }
    } finally {
        $zip->close();
        @unlink($tmp);
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $out)));
}

// 从 PDF（文本层）用 pdftotext 抽取；返回 '' 表示无文本或工具不可用
function netdisk_extract_pdf_text(string $raw): string {
    $bin = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') {
        return '';
    }
    $tmpPdf = tempnam(sys_get_temp_dir(), 'ndp_');
    $tmpTxt = tempnam(sys_get_temp_dir(), 'ndt_');
    if ($tmpPdf === false || $tmpTxt === false) {
        @unlink($tmpPdf);
        @unlink($tmpTxt);
        return '';
    }
    @file_put_contents($tmpPdf, $raw);
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($tmpPdf) . ' ' . escapeshellarg($tmpTxt) . ' 2>/dev/null';
    @shell_exec($cmd);
    $text = '';
    if (is_file($tmpTxt)) {
        $text = (string)@file_get_contents($tmpTxt);
    }
    @unlink($tmpPdf);
    @unlink($tmpTxt);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

// OCR 渲染分辨率（pdftoppm DPI）：PaddleOCR 识别精度对 DPI 不敏感，200 足够且降低内存峰值，
// 避免 300 整页大图在低内存 NAS 上触发 onnxruntime 推理崩溃。
const NETDISK_OCR_DPI = 200;
// OCR 外部命令超时（秒）：防止 pdftoppm/tesseract 挂起拖死 worker
const NETDISK_OCR_CMD_TIMEOUT = 60;

// 用 proc_open 执行外部命令并设超时；超时强制终止。返回 true 表示命令已结束（成功与否见文件），false 表示超时终止。
function netdisk_run_cmd_timeout(string $cmd, int $timeoutSec): bool {
    $proc = proc_open($cmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    $started = time();
    while (true) {
        $st = @proc_get_status($proc);
        if (!$st || !$st['running']) {
            break;
        }
        if (time() - $started >= $timeoutSec) {
            @proc_terminate($proc);
            usleep(100000);
            @proc_terminate($proc, 9);
            @proc_close($proc);
            return false;
        }
        usleep(100000);
    }
    @proc_close($proc);
    return true;
}

// OCR 候选扩展名
function netdisk_is_ocr_candidate(string $ext): bool {
    $ext = strtolower(ltrim($ext, '.'));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'pdf'], true);
}

// ---- OCR sidecar（rapidocr/PP-OCR）集成 ----
// 独立容器常驻 HTTP 服务识别共享 volume 中的页 PNG，精度远高于 tesseract。
// 可用性检测缓存；sidecar 不可用时自动回退本地 tesseract，保证功能不中断。

const NETDISK_OCR_SIDECAR_WORKDIR = '/ocr';

// PaddleOCR 官方 serving 地址：优先环境变量，默认 http://ocr:8080（服务名/端口与 compose 的 ocr 服务、deploy/ocr/init.sh 的 OCR_PORT 统一；也可通过 NETDISK_OCR_SIDECAR_URL 显式指定）
function netdisk_ocr_sidecar_base(): string {
    return rtrim((string)(getenv('NETDISK_OCR_SIDECAR_URL') ?: 'http://ocr:8080'), '/');
}

function netdisk_ocr_sidecar_enabled(): bool {
    return (string)(getenv('NETDISK_OCR_SIDECAR') ?: '') === '1';
}

function netdisk_ocr_sidecar_available(): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $ok = false;
    if (function_exists('curl_init')) {
        // PaddleOCR 官方 serving（paddlex --serve）基于 FastAPI，无 /health；
        // 用批量探测：root（200）或 /openapi.json（FastAPI 默认）均视为存活。
        foreach (['/openapi.json', '/docs', ''] as $path) {
            $code = 0;
            $ch = curl_init(netdisk_ocr_sidecar_base() . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CUSTOMREQUEST => 'GET',
            ]);
            @curl_exec($ch);
            $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code > 0 && $code < 500) {
                $ok = true;
                break;
            }
        }
    }
    $cache = $ok;
    return $ok;
}

// ===== 0019: OCR 内存预算守卫 =====
// 背景：worker 在真机三次 `Allowed memory size of 1073741824 bytes exhausted`——
// 旧 netdisk_ocr_image_resize_b64() 的"超预算图先降采样"分支会先把整幅图 imagecreatefromstring，
// 巨图（≈1.23 亿像素、真彩解码 ≈470MB）恰好在"防 OOM"那一步触发 PHP Fatal。
// GD 没有流式解码，唯一安全做法：解码前先算解码需求，超预算拒绝解码并记 invalid。

// 解析 php.ini 简写字节（"1G"/"256M"/"-1"）；-1/0 返回 -1（不限语义），空/非法返回 0。
function netdisk_ocr_parse_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (int)$v;
    if ($n <= 0 && !is_numeric($v)) {
        return 0;
    }
    if ($n < 0) {
        return -1;
    }
    $unit = strtolower(substr($v, -1));
    if ($unit === 'g') {
        $n *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $n *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $n *= 1024;
    }
    return $n;
}

// 读 cgroup 内存上限（字节）；0 = 未知或不限。v2 优先，v1 兜底（v1 无限制时为近 2^63 哨兵值）。
function netdisk_ocr_cgroup_limit(): int {
    if (isset($GLOBALS['ND_OCR_CGROUP_LIMIT'])) { // 桩测钩子：生产环境不设该 GLOBALS
        return (int)$GLOBALS['ND_OCR_CGROUP_LIMIT'];
    }
    foreach (['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes'] as $f) {
        if (is_readable($f)) {
            $s = trim((string)@file_get_contents($f));
            if ($s === 'max' || $s === '') {
                continue;
            }
            $n = (int)$s;
            if ($n > 0 && $n < (1 << 50)) {
                return $n;
            }
        }
    }
    return 0;
}

// 读 cgroup 当前内存用量（字节）；0 = 未知。
function netdisk_ocr_cgroup_current(): int {
    if (isset($GLOBALS['ND_OCR_CGROUP_CURRENT'])) { // 桩测钩子：生产环境不设该 GLOBALS
        return (int)$GLOBALS['ND_OCR_CGROUP_CURRENT'];
    }
    foreach (['/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory/memory.usage_in_bytes'] as $f) {
        if (is_readable($f)) {
            $n = (int)trim((string)@file_get_contents($f));
            if ($n > 0) {
                return $n;
            }
        }
    }
    return 0;
}

// OCR 解码可用内存预算（字节）：
//  - memory_limit > 0  → limit − 当前进程已用；
//  - memory_limit = -1 → cgroup 上限 − max(进程已用, cgroup 用量)（worker 解除 1G 上限后
//    容器仍受 compose mem_limit 约束，不能再按"无限"或 256MB 兜底乱算）；
//  - 两者都拿不到 → 保守 256MB 兜底。
// 最后乘 60% 安全系数：imagecopyresampled 目标副本、JPEG 编码与 base64 峰值都要留余量。
function netdisk_ocr_memory_budget(): int {
    $lim = netdisk_ocr_parse_bytes((string)ini_get('memory_limit'));
    $used = memory_get_usage(true);
    $avail = 0;
    if ($lim > 0) {
        $avail = $lim - $used;
    } elseif ($lim < 0) {
        $cg = netdisk_ocr_cgroup_limit();
        if ($cg > 0) {
            $avail = $cg - max($used, netdisk_ocr_cgroup_current());
        }
    }
    if ($avail <= 0) {
        $avail = 268435456;
    }
    return (int)max(1048576, floor($avail * 0.6));
}

// 记录"该图片无法安全解码"的原因（按文件路径为键；文件删除后仍可查）。
// 状态行落库时读取：status='invalid' + last_error=原因，不再笼统记 failed。
function netdisk_ocr_invalid_mark(string $path, string $reason): void {
    if (!isset($GLOBALS['ND_OCR_INVALID']) || !is_array($GLOBALS['ND_OCR_INVALID'])) {
        $GLOBALS['ND_OCR_INVALID'] = [];
    }
    $GLOBALS['ND_OCR_INVALID'][$path] = $reason;
}

function netdisk_ocr_invalid_reason(string $path): string {
    return (string)($GLOBALS['ND_OCR_INVALID'][$path] ?? '');
}

// ===== 0020: sidecar 服务端失败归因 =====
// 与 ND_OCR_INVALID（文件自身问题 → status='invalid'）区分：sidecar 5xx/超时/连接失败/解析失败
// 是服务端问题，文件本身不坏 → 状态行仍落 failed（交 0018 的连续失败计数管，≥3 熔断），
// last_error 记具体原因（状态码 + body 摘要）替代笼统的「OCR 返回空文本」。
function netdisk_ocr_fail_mark(string $path, string $reason): void {
    if (!isset($GLOBALS['ND_OCR_FAIL']) || !is_array($GLOBALS['ND_OCR_FAIL'])) {
        $GLOBALS['ND_OCR_FAIL'] = [];
    }
    $GLOBALS['ND_OCR_FAIL'][$path] = $reason;
}

function netdisk_ocr_fail_reason(string $path): string {
    return (string)($GLOBALS['ND_OCR_FAIL'][$path] ?? '');
}

// 0020: body/异常信息单行化 + 按字符截断——防日志注入（body 里的 \r\n 会伪造日志行）
// 与超长 body 刷爆状态表。口径比任务书的「\r\n→\n」更严：\n 同样断行，一律换成空格。
function netdisk_ocr_log_oneline(string $s, int $maxChars = 200): string {
    $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
    $s = trim((string)preg_replace('/ {2,}/', ' ', $s));
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, max(1, $maxChars), 'UTF-8');
    } else {
        $s = substr($s, 0, max(1, $maxChars) * 3);
    }
    return $s;
}

// 0019: pdftoppm 栅格化 DPI 源头夹取——按 pdfinfo 页面尺寸(pts)估算单页真彩解码需求
// （像素×4 字节），超预算则按 50 步长下调 DPI，下限 150（再低识别率显著下降，宁可交给
// 预算守卫跳过）。不让 GD 事后处理超预算数据；同时是 0021 DPI 自动定档的实现基础。
function netdisk_ocr_pdf_clamp_dpi(string $pdfPath, int $dpi): int {
    $req = max(1, (int)$dpi);
    $wPt = 595.0;
    $hPt = 842.0; // pdfinfo 缺失/不可解析时按 A4 兜底
    $info = @shell_exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
    if (is_string($info) && preg_match('/Page size:\s+([\d.]+)\s+x\s+([\d.]+)/', $info, $m)) {
        $wPt = max(1.0, (float)$m[1]);
        $hPt = max(1.0, (float)$m[2]);
    }
    $budget = netdisk_ocr_memory_budget();
    $out = $req;
    while ($out > 150) {
        $pxW = $wPt / 72.0 * $out;
        $pxH = $hPt / 72.0 * $out;
        if ($pxW * $pxH * 4 <= $budget) {
            break;
        }
        $out -= 50;
    }
    $out = max(150, $out);
    if ($out !== $req) {
        @error_log('[nd-ocr] pdf page dpi clamped ' . $req . '->' . $out . ' (' . basename($pdfPath) . ')');
    }
    return $out;
}

// 0019: 统一的 pdftoppm 栅格化入口：DPI 源头夹取 + 超时保护 + 超时删除半截产物。
// 超时（旧实现直接 glob 半截 PNG 送 sidecar，触发 libpng "PNG input buffer is incomplete"
// → serving 500）后本轮所有页 PNG 一律删除并返回 false，绝不把半截文件喂给下游。
// $timeoutSec<=0 时按页数自适应：max(NETDISK_OCR_CMD_TIMEOUT, 页数×2s)（整本大 PDF 60s 不够）。
function netdisk_ocr_pdf_render(string $ppm, string $pdfPath, string $outPrefix, int $dpi, string $pageOpt = '', int $timeoutSec = 0): bool {
    $dpi = netdisk_ocr_pdf_clamp_dpi($pdfPath, $dpi);
    $timeout = $timeoutSec;
    if ($timeout <= 0) {
        $timeout = NETDISK_OCR_CMD_TIMEOUT;
        $info = @shell_exec('pdfinfo ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
        if (is_string($info) && preg_match('/Pages:\s+(\d+)/', $info, $m)) {
            $timeout = max(NETDISK_OCR_CMD_TIMEOUT, ((int)$m[1]) * 2);
        }
    }
    $ok = netdisk_run_cmd_timeout(escapeshellarg($ppm) . $pageOpt . ' -r ' . (int)$dpi . ' -png ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($outPrefix) . ' 2>/dev/null', $timeout);
    if (!$ok) {
        foreach (glob($outPrefix . '-*.png') ?: [] as $part) {
            @unlink($part);
        }
    }
    return $ok;
}

// 将图片缩放(长边≤阈值)并返回 base64：PDF 页 200DPI 大图(1654×2339)直接传 serving 会触发
// onnxruntime 内存峰值崩溃，缩放后体积/边长下降，识别精度几乎不损。
function netdisk_ocr_image_resize_b64(string $imgPath, int $maxSide = 960, int $quality = 85): string {
    // 0019: 解码前预算守卫——先算"整幅真彩解码需要多少字节"，超预算直接拒绝并记 invalid。
    // 旧实现在"超预算先降采样"分支里先整幅 imagecreatefromstring（巨图 ≈470MB 解码需求），
    // 恰好在防 OOM 的保护代码处触发 Allowed memory exhausted，worker 三连崩。
    $budget = netdisk_ocr_memory_budget();
    $fsize = (int)@filesize($imgPath);
    if ($fsize > $budget) {
        netdisk_ocr_invalid_mark($imgPath, 'file too large: ' . round($fsize / 1048576, 1) . 'MB (budget ' . round($budget / 1048576, 1) . 'MB)');
        return '';
    }
    $imgSz = @getimagesize($imgPath);
    $sw = $imgSz ? (int)$imgSz[0] : 0;
    $sh = $imgSz ? (int)$imgSz[1] : 0;
    if ($sw > 0 && $sh > 0 && ($sw * $sh * 4) > $budget) {
        netdisk_ocr_invalid_mark($imgPath, 'image too large: ' . $sw . 'x' . $sh . ' (~' . round($sw * $sh * 4 / 1048576) . 'MB needed, budget ' . round($budget / 1048576, 1) . 'MB)');
        return '';
    }
    if (function_exists('imagecreatefromstring')) {
        $decoded = @file_get_contents($imgPath);
        if (!is_string($decoded) || $decoded === '') {
            return '';
        }
        $src = @imagecreatefromstring($decoded);
        unset($decoded); // 0019: 解码后立即释放原始字节，降低峰值
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = 1.0;
            if ($w > $maxSide || $h > $maxSide) {
                $scale = $w >= $h ? ($maxSide / $w) : ($maxSide / $h);
            }
            $nw = (int)max(1, round($w * $scale));
            $nh = (int)max(1, round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, $quality);
            $jpg = (string)ob_get_clean();
            imagedestroy($dst);
            imagedestroy($src);
            if ($jpg !== '') {
                return base64_encode($jpg);
            }
        }
    }
    // GD 不可用/处理失败时退回原文件
    return base64_encode((string)@file_get_contents($imgPath));
}

// 调用 PaddleOCR 官方 serving 识别一张图片（base64 传 /ocr，fileType=1）。
// 返回 ['text'=>, 'avg_conf'=>, 'low_conf_pct'=>, 'n'=>]；失败返回 null。
function netdisk_ocr_sidecar_recognize(string $imgPath, int $dpi = 0): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }
    $b64 = '';
    if (preg_match('#^data:[a-zA-Z0-9/\-+.]+;base64,#', $imgPath, $m)) {
        $b64 = substr($imgPath, strlen($m[0]));
    } elseif (is_file($imgPath)) {
        $b64 = netdisk_ocr_image_resize_b64($imgPath);
    } else {
        return null;
    }
    if ($b64 === '') {
        // 0019: 入参为空（预算守卫拒绝解码/文件不可读）→ 绝不把空串发给 sidecar；
        // 记录原因供调用方状态行归因 invalid（HTTP/解析逻辑不动）
        if (is_file($imgPath) && netdisk_ocr_invalid_reason($imgPath) === '') {
            netdisk_ocr_invalid_mark($imgPath, 'empty image payload (unreadable or zero-length)');
        }
        return null;
    }
    $payload = json_encode(['file' => $b64, 'fileType' => 1], JSON_UNESCAPED_UNICODE);
    // 0020: sidecar 5xx 与 curl 超时/连接失败自动重试 1 次（间隔 2s，桩测经 GLOBALS 缩短）；
    // 4xx、空 body、JSON 解析失败不重试（客户端/协议侧问题，重试无益）。
    // 任何异常一律就地捕获 → 记 last_error 并 return null，绝不外泄到池循环/逐文件 finalize。
    // 请求构造（curl_setopt_array 各项）保持原样，只改响应处理。
    $raw = false;
    $err = '';
    $httpCode = 0;
    try {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep((int)($GLOBALS['ND_OCR_SIDECAR_RETRY_DELAY_US'] ?? 2000000));
            }
            $ch = curl_init(netdisk_ocr_sidecar_base() . '/ocr');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                // 显式禁用 Expect: 100-continue：PHP curl 对大 body 默认发 100-continue，
                // 与 uvicorn/FastAPI 处理大 base64 时握手异常，导致 Empty reply from server。
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 120,
            ]);
            $raw = @curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 传输层拿到响应且非 5xx → 结束重试（4xx 属客户端问题同样不重试）
            if ($raw !== false && $httpCode > 0 && $httpCode < 500) {
                break;
            }
        }
    } catch (Throwable $e) {
        netdisk_ocr_fail_mark($imgPath, 'sidecar exception: ' . netdisk_ocr_log_oneline($e->getMessage(), 200));
        return null;
    }
    if ($raw === false) {
        // curl 超时/连接失败（含重试后仍失败）
        netdisk_ocr_fail_mark($imgPath, 'sidecar transport error: ' . netdisk_ocr_log_oneline($err !== '' ? $err : 'curl exec failed (timeout or connection refused)', 200));
        return null;
    }
    if ($httpCode >= 400) {
        // 4xx/5xx 最终失败：状态码 + body 摘要（单行化截断，防日志注入）
        netdisk_ocr_fail_mark($imgPath, 'sidecar HTTP ' . $httpCode . ': ' . netdisk_ocr_log_oneline((string)$raw, 200));
        return null;
    }
    if ($raw === '') {
        netdisk_ocr_fail_mark($imgPath, 'sidecar HTTP ' . $httpCode . ': (empty body)');
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || (int)($j['errorCode'] ?? 0) !== 0) {
        // JSON 解析失败 / 业务错误码非 0：不重试（循环已保证 2xx 才会走到这里）
        netdisk_ocr_fail_mark($imgPath, 'sidecar HTTP ' . $httpCode . ': ' . netdisk_ocr_log_oneline((string)$raw, 200));
        return null;
    }
    // 官方 serving 响应：result.ocrResults[i].prunedResult.{rec_texts[], rec_scores[]}
    $recs = [];
    $scores = [];
    $ors = $j['result']['ocrResults'] ?? [];
    foreach ((array)$ors as $or) {
        $pr = $or['prunedResult'] ?? $or;
        $rt = $pr['rec_texts'] ?? [];
        $rs = $pr['rec_scores'] ?? [];
        foreach ((array)$rt as $t) {
            $recs[] = (string)$t;
        }
        foreach ((array)$rs as $s) {
            if (is_numeric($s)) {
                $scores[] = (float)$s * 100.0; // 官方 0~1，统一为 0~100
            }
        }
    }
    $text = implode(' ', $recs);
    $n = count($scores);
    if ($n === 0) {
        return ['text' => trim($text), 'avg_conf' => 0.0, 'low_conf_pct' => 0.0, 'n' => 0];
    }
    $sum = 0.0;
    $low = 0;
    foreach ($scores as $s) {
        $sum += $s;
        if ($s < 60.0) {
            $low++;
        }
    }
    return [
        'text' => trim($text),
        'avg_conf' => round($sum / $n, 1),
        'low_conf_pct' => round($low * 100 / $n, 1),
        'n' => $n,
    ];
}

// 将一张任意路径图片复制进共享 volume，返回 sidecar 视角的容器内路径；失败返回 null。
function netdisk_ocr_sidecar_stage(string $srcImg): ?string {
    $work = NETDISK_OCR_SIDECAR_WORKDIR;
    if (!is_dir($work) || !is_writable($work)) {
        return null;
    }
    $name = 'nd_' . bin2hex(random_bytes(6)) . '.png';
    $dst = rtrim($work, '/') . '/' . $name;
    if (!@copy($srcImg, $dst)) {
        return null;
    }
    return $dst;
}

// 将任意路径文件复制进共享 volume（供 /ocr/pdf 整本识别），返回容器内路径；失败 null。
function netdisk_ocr_sidecar_stage_file(string $srcFile): ?string {
    $work = NETDISK_OCR_SIDECAR_WORKDIR;
    if (!is_dir($work) || !is_writable($work)) {
        return null;
    }
    $ext = strtolower(pathinfo($srcFile, PATHINFO_EXTENSION));
    $ext = $ext === '' ? 'bin' : $ext;
    $name = 'nd_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dst = rtrim($work, '/') . '/' . $name;
    if (!@copy($srcFile, $dst)) {
        return null;
    }
    return $dst;
}

// 统一"单张图片 → 识别文本+置信度"入口：优先 PaddleOCR serving，回退 tesseract TSV。
// 返回 ['text'=>, 'avg'=>, 'low_pct'=>, 'n'=>]（与 tesseract 口径统一，置信度 0-100）。
function netdisk_ocr_recognize_image(string $imgPath, int $dpi = 0): ?array {
    // 1) 官方 serving 优先（base64 POST /ocr，fileType=1）
    if (netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available()) {
        $res = netdisk_ocr_sidecar_recognize($imgPath, $dpi);
        if ($res !== null) {
            return [
                'text' => $res['text'],
                'avg' => $res['avg_conf'],
                'low_pct' => $res['low_conf_pct'],
                'n' => $res['n'],
            ];
        }
    }
    // 2) tesseract fallback
    $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    if ($tess === '') {
        return null;
    }
    $outTxt = tempnam(sys_get_temp_dir(), 'ndor_');
    $cmd = escapeshellarg($tess) . ' ' . escapeshellarg($imgPath) . ' ' . escapeshellarg($outTxt) . ' -l chi_sim+eng -c tessedit_create_txt=1 -c tessedit_create_tsv=1 2>/dev/null';
    @shell_exec($cmd);
    $text = '';
    $tsv = '';
    if ($outTxt !== false && is_file($outTxt . '.txt')) {
        $text = (string)@file_get_contents($outTxt . '.txt');
        @unlink($outTxt . '.txt');
    }
    if ($outTxt !== false && is_file($outTxt . '.tsv')) {
        $tsv = (string)@file_get_contents($outTxt . '.tsv');
        @unlink($outTxt . '.tsv');
    }
    @unlink($outTxt);
    $stats = netdisk_ocr_tsv_parse($tsv);
    return [
        'text' => $text,
        'avg' => $stats['avg'],
        'low_pct' => $stats['low_pct'],
        'n' => $stats['n'],
    ];
}

// 统一"整本 PDF → 识别文本+置信度"入口：优先 PaddleOCR serving（PHP 侧 pdftoppm 逐页转图，
// 每页 base64 POST /ocr），回退 pdftoppm+tesseract。
// $f/$l：页范围（1-based；l<=0 表示到末页）。返回 ['text'=>, 'avg'=>, 'low_pct'=>, 'n'=>, 'pages'=>]；失败返回 null。
function netdisk_ocr_recognize_pdf(string $pdfPath, int $dpi = 300, int $f = 1, int $l = 0): ?array {
    $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($ppm === '') {
        return null;
    }
    $tmpPdf = $pdfPath . '.scan.pdf';
    if (!is_file($tmpPdf)) {
        @copy($pdfPath, $tmpPdf);
    }
    $pageOpt = ($f > 1 || $l > 0) ? (' -f ' . max(1, (int)$f) . ' -l ' . (($l > 0) ? (int)$l : 9999)) : '';
    // 0019: DPI 源头夹取 + 超时删半截产物；超时返回 false 时 glob 必为空（旧实现会把半截 PNG 送 sidecar）
    $renderOk = netdisk_ocr_pdf_render($ppm, $tmpPdf, $tmpPdf . '_pg', $dpi, $pageOpt);
    $pages = $renderOk ? (glob($tmpPdf . '_pg-*.png') ?: []) : [];
    @unlink($tmpPdf);
    // 0019: 页级进度（成功识别页数）按原始路径记入 GLOBALS，供状态行 pages_done 落库（0021 定档/断点观测）
    $pagesDone = 0;
    $sidecarActive = netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available();
    $text = '';
    $tsvAll = '';
    $avgAcc = 0.0;
    $lowAcc = 0;
    $nAcc = 0;
    foreach ($pages as $pg) {
        if ($sidecarActive) {
            // 官方 serving 每页 base64；页间短暂间隔避免连续大图导致 onnxruntime 内存峰值崩溃。
            // 0021: 页级外层重试收敛为单层——sidecar_recognize 内部已对 5xx/超时/连接失败
            // 重试 1 次并记 fail 原因，外层再叠一次会放大到单页最多 4 次 /ocr 请求；
            // sidecar 挂死（接受连接但不响应，非拒连）时单页最坏 4×300s≈20 分钟，
            // 20 页 PDF 可拖到 6 小时。仅当内层未记 fail 原因（空入参守卫等非服务端
            // 失败，函数内层重试帮不上、外层隔 300ms 再试仍可能有效）才保留外层重试。
            $res = netdisk_ocr_sidecar_recognize($pg, $dpi);
            if ($res === null && netdisk_ocr_fail_reason($pg) === '') {
                usleep(300000); // 300ms 后重试一次（仅内层无 fail 记录时）
                $res = netdisk_ocr_sidecar_recognize($pg, $dpi);
            }
            if ($res !== null) {
                $pagesDone++;
                $text .= ' ' . $res['text'];
                $nAcc += $res['n'];
                $avgAcc += $res['avg_conf'] * $res['n'];
                $lowAcc += round($res['low_conf_pct'] / 100 * $res['n']);
                @unlink($pg);
                usleep(150000); // 页间 150ms 喘息
                continue;
            }
            // 0020: 本页 sidecar 两次均失败 → 失败原因上移到原始 PDF 路径
            //（页 PNG 随即删除，状态行 last_error 按 tmp/pdf 路径读取）
            $pgFail = netdisk_ocr_fail_reason($pg);
            if ($pgFail !== '') {
                netdisk_ocr_fail_mark($pdfPath, $pgFail);
            }
        }
        // tesseract fallback：TSV 每页
        $pageText = ''; // 0020: 本页文本（增量计数用，不再拿累计 $text 判空）
        $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
        if ($tess !== '') {
            $outTxt = tempnam(sys_get_temp_dir(), 'ndor_');
            $cmd = escapeshellarg($tess) . ' ' . escapeshellarg($pg) . ' ' . escapeshellarg($outTxt) . ' -l chi_sim+eng -c tessedit_create_txt=1 -c tessedit_create_tsv=1 2>/dev/null';
            @shell_exec($cmd);
            if ($outTxt !== false && is_file($outTxt . '.txt')) {
                $pageText = (string)@file_get_contents($outTxt . '.txt');
                $text .= ' ' . $pageText;
                @unlink($outTxt . '.txt');
            }
            if ($outTxt !== false && is_file($outTxt . '.tsv')) {
                $tsvAll .= "\n" . (string)@file_get_contents($outTxt . '.tsv');
                @unlink($outTxt . '.tsv');
            }
            @unlink($outTxt);
        }
        // 0020: 本页增量计数——仅本页文本非空才 +1。旧实现拿累计 $text 判空：
        // 首个非空页之后所有空页也会被计入，pages_done 虚高。
        if (trim($pageText) !== '') {
            $pagesDone++;
        }
        @unlink($pg);
    }
    if (!isset($GLOBALS['ND_OCR_PAGES_DONE']) || !is_array($GLOBALS['ND_OCR_PAGES_DONE'])) {
        $GLOBALS['ND_OCR_PAGES_DONE'] = [];
    }
    $GLOBALS['ND_OCR_PAGES_DONE'][$pdfPath] = max((int)($GLOBALS['ND_OCR_PAGES_DONE'][$pdfPath] ?? 0), $pagesDone);
    if ($sidecarActive && $nAcc > 0) {
        $stats = netdisk_ocr_tsv_parse($tsvAll); // 仅占位（空）；用 sidecar 聚合统计
        return [
            'text' => trim($text),
            'avg' => round($avgAcc / $nAcc, 1),
            'low_pct' => round($lowAcc * 100 / $nAcc, 1),
            'n' => $nAcc,
            'pages' => count($pages),
            'pages_done' => $pagesDone,
        ];
    }
    $stats = netdisk_ocr_tsv_parse($tsvAll);
    return [
        'text' => trim($text),
        'avg' => $stats['avg'],
        'low_pct' => $stats['low_pct'],
        'n' => $stats['n'],
        'pages' => count($pages),
        'pages_done' => $pagesDone,
    ];
}

// sidecar /ocr/pdf 调用
// OCR 文本规范化：合并空白；去除 CJK/全角字符之间的空格（tesseract 对扫描中文常在字间插入空格，
// 导致 LIKE 检索匹配不到）。保留英文单词间的空格（如 "GJB 150.11A"、数字型号）。
function netdisk_normalize_ocr_text(string $t): string {
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = preg_replace('/([\x{4e00}-\x{9fff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}])\s+(?=[\x{4e00}-\x{9fff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}])/u', '$1', $t);
    return trim($t);
}

// OCR 形近错字容错匹配：在 $text 中查找与 $q 相近（编辑距离 ≤ 阈值）的所有位置。
// tesseract 对扫描件的中文常整体替换错字（如"退刀槽"→"退刀模/退刀枝/退刀槐"），
// 也可能漏字（"退刀槽"→"退刀"）或多字（"退刀槽的"→"退刀摸的X"）。
// 此处使用 Levenshtein 编辑距离（支持替换/插入/删除），比纯逐字符差异（Hamming，仅替换）
// 更通用；阈值按"每 3 个字符允许 1 次编辑"自动计算，Windows/CLI 均有 mb 字符级支持。
function netdisk_ocr_fuzzy_positions(string $text, string $q, int $maxEdits = 0): array {
    $qLen = mb_strlen($q);
    if ($qLen < 2 || $qLen > 12) {
        return [];
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $query = preg_split('//u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    if ($n < $qLen - 1) {
        return [];
    }
    // 默认编辑次数：每 3 字允许 1 次编辑（2 字至少允许 1），最多 3 次
    $maxEdits = $maxEdits > 0 ? $maxEdits : (int)max(1, min(3, (int)ceil($qLen / 3)));
    $res = [];
    // 滑动窗口：窗口长度允许 qLen±maxEdits（覆盖插删），用 Levenshtein 计算编辑距离
    $winBase = max(1, $qLen - 1);
    $winMax = $qLen + 2 <= 40 ? $qLen + 2 : $qLen + $maxEdits;
    $prev = [];
    $stripPunct = function (string $c): bool {
        // 忽略中英文标点/空白对距离的影响（OCR 易在字间插入标点或空格）
        return (bool)preg_match('/[\x{3000}-\x{303f}\x{ff00}-\x{ffef}\x{2000}-\x{206f}\s,.;:!?，。；：！？、()（）\/\\-]/u', $c);
    };
    for ($winLen = $winBase; $winLen <= $winMax; $winLen++) {
        for ($i = 0; $i + $winLen <= $n; $i++) {
            // 跳过已覆盖区域（近似去重：若起始位置已被更短窗口命中则不再重复）
            $startIn = $i;
            $skip = false;
            foreach ($res as $rp) {
                if ($rp === $startIn) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $window = array_slice($chars, $i, $winLen);
            // 快速剪枝：若窗口与 query 共有字符太少则直接跳过（避免大量全量 DP）
            $common = 0;
            foreach ($query as $qc) {
                if (in_array($qc, $window, true)) {
                    $common++;
                }
            }
            if ((int)$common < max(1, $qLen - $maxEdits)) {
                continue;
            }
            // Levenshtein（字符级，忽略标点空格差异按相等处理）
            $dp = array_fill(0, $winLen + 1, 0);
            for ($j = 0; $j <= $winLen; $j++) {
                $dp[$j] = $j;
            }
            for ($r = 1; $r <= count($query); $r++) {
                $prevRow = $dp;
                $dp[0] = $r;
                for ($j = 1; $j <= $winLen; $j++) {
                    $cost = ($query[$r - 1] === $window[$j - 1] || ($stripPunct($query[$r - 1]) && $stripPunct($window[$j - 1]))) ? 0 : 1;
                    $dp[$j] = min($prevRow[$j] + 1, $dp[$j - 1] + 1, $prevRow[$j - 1] + $cost);
                }
            }
            if ($dp[$winLen] <= $maxEdits) {
                $res[] = $i;
                if (count($res) >= 60) {
                    return $res;
                }
            }
        }
    }
    // 排序去重（滑动可能产生相邻重叠，保留起始位置即可）
    $res = array_values(array_unique($res));
    sort($res);
    return $res;
}

// OCR 形近错字容错检索：对 $text 中所有与 $q 相近的位置生成 snippet（与精确检索同构），
// 用于 contentHits 在精确命中不足时补充召回。返回 ['uri'=>, 'snippets'=>...] 追加进 contentHits 的逻辑由调用方处理。
function netdisk_ocr_fuzzy_snippets(string $text, string $q, int $limit = 8): array {
    $positions = netdisk_ocr_fuzzy_positions($text, $q);
    $qLen = mb_strlen($q);
    $textLen = mb_strlen($text);
    $snippets = [];
    // 相邻窗口归并：滑动窗口会在同一处产生多个接近的起始偏移（如 55/56、150/151/152），
    // 若两处 offset 相差 <qLen 视为同一处命中，只保留最先一个，避免 snippet 重复。
    $merged = [];
    foreach ($positions as $pos) {
        if ($merged === [] || $pos - end($merged) >= $qLen) {
            $merged[] = $pos;
        }
    }
    foreach (array_slice($merged, 0, $limit) as $pos) {
        $sStart = max(0, $pos - 30);
        $sLen = min(120, $textLen - $sStart);
        $snippets[] = [
            'offset' => $pos,
            'text' => '…' . mb_substr($text, $sStart, $sLen) . '…',
            'fuzzy_terms' => [mb_substr($text, $pos, $qLen)],
        ];
    }
    return $snippets;
}

// OCR 识别质量评估：返回 0~1 的有效中文比率。
// 统计识别文本中的 CJK 字符数占"总可读字符（CJK+ASCII字母数字+常见标点）"的比例；
// 乱码/噪声字符（孤立符号、畸形序列）不计数，用于判断是否值得升级 DPI 重试。
function netdisk_ocr_quality(string $text): float {
    $text = trim($text);
    if ($text === '') {
        return 0.0;
    }
    $cjk = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
    $alnum = preg_match_all('/[A-Za-z0-9]/u', $text);
    $punct = preg_match_all('/[\x{3000}-\x{303f}\x{ff00}-\x{ffef}\x{2000}-\x{206f},.。，、；：！？（）()\/\\-]/u', $text);
    $readable = $cjk + $alnum + $punct;
    $total = mb_strlen($text);
    if ($total <= 0) {
        return 0.0;
    }
    $ratio = $readable / $total;
    // 中文为绝对主体的文档：可读率主要由 CJK 决定；英文/数字混合文档也计入 alnum。
    return (float)max(0.0, min(1.0, $ratio));
}

function netdisk_ocr_index_quality_ok(?string $text, int $minChars = 20, float $minRatio = 0.6): bool {
    $text = trim((string)$text);
    if ($text === '') {
        return false;
    }
    $cjk = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
    $alnum = preg_match_all('/[A-Za-z0-9]/u', $text);
    if ($cjk + $alnum < $minChars) {
        return false;
    }
    return netdisk_ocr_quality($text) >= $minRatio;
}

// 从 tesseract TSV 输出解析 word 级识别文本与置信度统计。
// 输入：多页 tesseract -c tessedit_create_tsv=1 生成的原始 TSV 文本（可跨页拼接）。
// 返回 ['text'=>合并后的原始文本, 'avg'=>平均置信度0-100, 'low_pct'=>置信<60 的 word 占比(0-100), 'n'=>有效 word 数]。
// word 级 confidence 反映"识别确定度"：低 DPI 错字识别不确定 → conf 低、低置信占比高，
// 比"字符是否 CJK"更能区分错别字文本（错别字也是 CJK，纯占比判定会误判达标）。
function netdisk_ocr_tsv_parse(string $tsv): array {
    $text = '';
    $sum = 0.0;
    $n = 0;
    $low = 0;
    foreach (explode("\n", $tsv) as $line) {
        $cols = explode("\t", $line);
        // 结构：level page block par line word left top width height conf text
        if (count($cols) < 12 || (int)$cols[0] !== 5) {
            continue;
        }
        $conf = (float)$cols[10];
        if ($conf < 0) {
            continue;
        }
        $n++;
        $sum += $conf;
        if ($conf < 60) {
            $low++;
        }
        $text .= ' ' . $cols[11];
    }
    if ($n === 0) {
        return ['text' => trim($text), 'avg' => 0.0, 'low_pct' => 0.0, 'n' => 0];
    }
    return [
        'text' => trim($text),
        'avg' => round($sum / $n, 1),
        'low_pct' => round($low * 100 / $n, 1),
        'n' => $n,
    ];
}

// confidence 版质量判定：返回 0~1 质量分。
// avg<55 → 0（很差）；avg>=80 且低置信占比<=20% → 1（很好）；中间线性插值，低置信占比高时扣分。
function netdisk_ocr_quality_conf(array $stats): float {
    $avg = (float)($stats['avg'] ?? 0);
    $lowPct = (float)($stats['low_pct'] ?? 100);
    $n = (int)($stats['n'] ?? 0);
    if ($n === 0 || $avg <= 0) {
        return 0.0;
    }
    $score = ($avg - 55) / 25;          // avg 55→0.0, 80→1.0
    $score -= $lowPct * 0.012;          // 低置信占比 20%→扣0.24, 40%→扣0.48
    return (float)max(0.0, min(1.0, $score));
}

// 连接级索引配置值：configId>0 时优先取该连接的 index_* 列（非空），否则回退全局 settings。
function netdisk_index_cfg_value(int $configId, string $cfgCol, string $settingKey, string $default): string {
    if ($configId > 0) {
        try {
            $cfg = netdisk_index_cfg_cached($configId);
            if ($cfg !== null) {
                $v = (string)($cfg[$cfgCol] ?? '');
                if ($v !== '' && $v !== null) {
                    return $v;
                }
            }
        } catch (Throwable $e) {
            // fallthrough
        }
    }
    // 已取消全局默认设置：未配置的连接级字段一律使用硬编码默认值
    return $default;
}

// 连接配置行缓存（进程内，避免同函数内多次查库）
function netdisk_index_cfg_cached(int $configId): ?array {
    static $cache = [];
    if (isset($cache[$configId])) {
        return $cache[$configId];
    }
    try {
        $row = cloudreve_config_get($configId);
        $cache[$configId] = $row;
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

// OCR 起点/上限 DPI 与质量阈值（可后台调整；configId>0 时按连接独立）
function netdisk_index_ocr_dpi_start(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_dpi_start', 'netdisk_index_ocr_dpi_start', '200');
    return max(50, (int)$v);
}
function netdisk_index_ocr_dpi_max(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_dpi_max', 'netdisk_index_ocr_dpi_max', '600');
    return max(100, (int)$v);
}
function netdisk_index_ocr_quality_threshold(?int $configId = 0): float {
    $v = (float)netdisk_index_cfg_value((int)$configId, 'index_ocr_quality_threshold', 'netdisk_index_ocr_quality_threshold', '0.3');
    return max(0.0, min(1.0, $v));
}

// 自适应 DPI 重试：对 OCR 文本质量不足的文件，用更高 DPI 重新栅格化 + OCR。
// 输入 $task = ['uri'=>, 'tmp'=>已落盘文件, 'ext'=>]；返回规范化后的识别文本（失败/无识别返回 ''）。
// 注意：此函数用于图片与 PDF 的统一重试；PDF 通过 pdftoppm 按 DPI 栅格化，图片直接喂 tesseract
// （低质量时可在重试阶段用 tesseract 的分辨率参数放缩，这里统一交给调用方决定 DPI 语义）。
function netdisk_ocr_retry_upgrade(string $ext, string $tmpIn, int $dpi): string {
    $ext = strtolower(ltrim($ext, '.'));
    $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    if ($tess === '') {
        return '';
    }
    $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    $out = '';
    $doOcr = function (string $imgFile) use ($tess, &$out) {
        $outTxt = tempnam(sys_get_temp_dir(), 'ndor_');
        $cmd = escapeshellarg($tess) . ' ' . escapeshellarg($imgFile) . ' ' . escapeshellarg($outTxt) . ' -l chi_sim+eng 2>/dev/null';
        @shell_exec($cmd);
        if ($outTxt !== false && is_file($outTxt . '.txt')) {
            $out .= ' ' . (string)@file_get_contents($outTxt . '.txt');
            @unlink($outTxt . '.txt');
        }
        @unlink($outTxt);
    };
    if ($ext === 'pdf') {
        if ($ppm === '') {
            return '';
        }
        // 0019: 统一渲染入口（夹 DPI + 超时删半截产物）
        netdisk_ocr_pdf_render($ppm, $tmpIn, $tmpIn . '_pg' . $dpi, $dpi);
        foreach (glob($tmpIn . '_pg' . $dpi . '-*.png') ?: [] as $png) {
            $doOcr($png);
            @unlink($png);
        }
    } else {
        $doOcr($tmpIn);
    }
    return netdisk_normalize_ocr_text($out);
}

// 单文件 OCR（图片或扫描 PDF）。输入为已落盘的临时文件路径，返回识别文本。
function netdisk_ocr_extract_file(string $ext, string $tmpIn): string {
    $ext = strtolower(ltrim($ext, '.'));
    $out = '';
    $doOcr = function (string $imgFile) use (&$out) {
        $res = netdisk_ocr_recognize_image($imgFile);
        if ($res !== null && trim($res['text']) !== '') {
            $out .= ' ' . $res['text'];
        }
    };
    if ($ext === 'pdf') {
        $res = netdisk_ocr_recognize_pdf($tmpIn, NETDISK_OCR_DPI, 1, 0);
        if ($res !== null) {
            return netdisk_normalize_ocr_text($res['text']);
        }
        // sidecar 不可用时回退 tesseract
        $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
        if ($ppm === '') {
            return '';
        }
        // 超时保护：防止 pdftoppm 对异常/超大 PDF 挂起；0019: 统一渲染入口（夹 DPI + 超时删半截产物）
        netdisk_ocr_pdf_render($ppm, $tmpIn, $tmpIn . '_pg', NETDISK_OCR_DPI);
        foreach (glob($tmpIn . '_pg-*.png') ?: [] as $png) {
            $doOcr($png);
            @unlink($png);
        }
    } else {
        $doOcr($tmpIn);
    }
    return netdisk_normalize_ocr_text($out);
}

// 并发 OCR 进程池：同时最多运行 $concurrency 个 tesseract（图片 1 槽；扫描 PDF 先 pdftoppm
// 逐页转 PNG，每页 1 槽）。输入为 ['uri'=>..., 'tmp'=>临时文件路径, 'ext'=>...]。
// 采用惰性栅格化：每次只栅格化一个 PDF 并逐页消费，页 PNG 用完即删，避免 /tmp 累积。
// PDF 首次仅栅格化前 5 页作质量样本（finalize 预判 DPI 后整本终扫）；原始 PDF 字节保留给 finalize。
// $jobId>0 时上报页级进度与心跳（节流 ≥2s），并对单进程加超时（180s）防止挂死。
// $jobPdo 可选：传入则复用该连接写 job 进度（与调用方共用单一连接，避免同进程多连接交叉写锁）。
// 返回 ['results'=>uri=>text, 'done'=>int, 'texts'=>int, 'errors'=>int]。
function netdisk_ocr_run_pool(array $jobs, int $concurrency, int $jobId = 0, ?PDO $jobPdo = null): array {
    $results = [];
    $done = 0;
    $texts = 0;
    $errors = 0;
    $lastHb = 0;
    if (empty($jobs) || $concurrency < 1) {
        return ['results' => $results, 'done' => 0, 'texts' => 0, 'errors' => 0];
    }
    // sidecar 优先：模型常驻、识别精度高。sidecar 自身串行，无需 PHP 侧并发；
    // PDF 最终由 finalize 用 /ocr/pdf 整本重扫（更准），此处仅同步识别图片与 PDF 前 5 页样本。
    if (netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available()) {
        $ocrPoolCfgId = 0;
        foreach ($jobs as $jj) {
            if (isset($jj['config_id'])) {
                $ocrPoolCfgId = (int)$jj['config_id'];
                break;
            }
        }
        $dpiStart = netdisk_index_ocr_dpi_start($ocrPoolCfgId);
        foreach ($jobs as $j) {
            if ($jobId > 0 && netdisk_index_job_cancelled($jobId, $jobPdo)) {
                foreach ($jobs as $jj) {
                    if (isset($jj['tmp']) && is_file($jj['tmp'])) {
                        @unlink($jj['tmp']);
                    }
                }
                break;
            }
            $uri = (string)($j['uri'] ?? '');
            $ext = strtolower(ltrim((string)($j['ext'] ?? ''), '.'));
            $tmp = (string)($j['tmp'] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                $errors++;
                continue;
            }
            if ($jobId > 0 && ($nowHb = time()) - $lastHb >= 2) {
                $lastHb = $nowHb;
                netdisk_index_job_update($jobId, [
                    'current_uri' => $uri,
                    'current_name' => (string)($j['name'] ?? ''),
                    'current_detail' => 'OCR 处理中',
                    'queue_remaining' => max(0, count($jobs) - (int)array_search($j, $jobs, true) - 1),
                    'heartbeat_at' => $nowHb,
                ], $jobPdo);
            }
            if ($ext === 'pdf') {
                // 前 5 页样本识别（finalize 会整本重扫，这里只保证 finalize 有初始 quality 依据）
                $res = netdisk_ocr_recognize_pdf($tmp, $dpiStart, 1, 5);
                // PDF：保留原始 tmp 给 finalize 做整本重扫（OCR 全部页），不删除
            } else {
                $res = netdisk_ocr_recognize_image($tmp, $dpiStart);
                @unlink($tmp);
            }
            $done++;
            if ($res !== null && trim($res['text']) !== '') {
                $results[$uri] = trim(preg_replace('/\s+/u', ' ', $res['text']));
                $texts++;
            } else {
                $errors++;
            }
        }
        return ['results' => $results, 'done' => $done, 'texts' => $texts, 'errors' => $errors];
    }
    $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    if ($tess === '') {
        return ['results' => $results, 'done' => count($jobs), 'texts' => 0, 'errors' => count($jobs)];
    }
    $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    // 自适应 DPI：起点、上限、质量阈值（每次调用读取配置，默认 100/600/0.3）。
    // configId 取自任务数组（同池任务归属同一连接；无则全局默认）。
    $ocrPoolCfgId = 0;
    foreach ($jobs as $jj) {
        if (isset($jj['config_id'])) {
            $ocrPoolCfgId = (int)$jj['config_id'];
            break;
        }
    }
    $dpiStart = netdisk_index_ocr_dpi_start($ocrPoolCfgId);
    $dpiMax = netdisk_index_ocr_dpi_max($ocrPoolCfgId);
    $qualityThreshold = netdisk_index_ocr_quality_threshold($ocrPoolCfgId);
    // 每次调用固定用起点 DPI 栅格化（惰性逐页）；池结束后由 netdisk_ocr_finalize_results
    // 做质量评估 + 自适应升级重试 + 文本规范化。
    $curDpi = $dpiStart;

    $jobIdx = 0;
    $totalJobs = count($jobs);
    $activePages = null; // ['uri'=>, 'pages'=>[png,...], 'pi'=>int] 当前正在消费页的 PDF
    $toClean = [];       // 每个 job 结束清理：图片 tmp / PDF 副本

    // 取下一个待 tesseract 的输入文件；返回 null 表示没有更多
    $nextInput = function () use (&$jobIdx, &$totalJobs, $jobs, $ppm, &$activePages, &$errors, &$toClean, &$curDpi) {
        if ($activePages !== null && $activePages['pi'] < count($activePages['pages'])) {
            $pg = $activePages['pages'][$activePages['pi']];
            $activePages['pi']++;
            return ['uri' => $activePages['uri'], 'file' => $pg, 'temp' => false];
        }
        // 当前 PDF 页耗尽，删除其页 PNG
        if ($activePages !== null) {
            foreach ($activePages['pages'] as $pg) {
                @unlink($pg);
            }
            $activePages = null;
        }
        while ($jobIdx < $totalJobs) {
            $j = $jobs[$jobIdx++];
            $uri = (string)($j['uri'] ?? '');
            $ext = strtolower(ltrim((string)($j['ext'] ?? ''), '.'));
            $tmp = (string)($j['tmp'] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                $errors++;
                continue;
            }
            if ($ext === 'pdf') {
                if ($ppm === '') {
                    $errors++;
                    $toClean[] = $tmp;
                    continue;
                }
                $tmpPdf = $tmp . '.pdf';
                if (!is_file($tmpPdf)) {
                    @copy($tmp, $tmpPdf);
                }
                // 首次仅栅格化前 5 页作质量样本（页数不足自动截断）；最终 DPI 由 finalize 预判后整本重扫
                // 0019: 统一渲染入口（夹 DPI + 超时删半截产物）
                netdisk_ocr_pdf_render($ppm, $tmpPdf, $tmpPdf . '_pg', $curDpi, ' -f 1 -l 5');
                $pages = glob($tmpPdf . '_pg-*.png') ?: [];
                // 只删栅格化副本；原始 PDF 字节保留（$toClean），供 finalize 用最终 DPI 整本重扫
                @unlink($tmpPdf);
                if (empty($pages)) {
                    $errors++;
                    $toClean[] = $tmp;
                    continue;
                }
                $toClean[] = $tmp;
                $activePages = ['uri' => $uri, 'pages' => $pages, 'pi' => 1];
                return ['uri' => $uri, 'file' => $pages[0], 'temp' => false];
            }
            $toClean[] = $tmp;
            return ['uri' => $uri, 'file' => $tmp, 'temp' => true];
        }
        return null;
    };

    $running = []; // ['proc'=>resource, 'uri'=>, 'outTxt'=>, 'file'=>, 'temp'=>bool, 'started'=>int]
    while (true) {
        // 填满并发槽
        while (count($running) < $concurrency) {
            $next = $nextInput();
            if ($next === null) {
                break;
            }
            $outTxt = tempnam(sys_get_temp_dir(), 'ndor_');
            if ($outTxt === false) {
                $errors++;
                continue;
            }
            $cmd = escapeshellarg($tess) . ' ' . escapeshellarg($next['file']) . ' ' . escapeshellarg($outTxt) . ' -l chi_sim+eng 2>/dev/null';
            $proc = proc_open($cmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);
            if (!is_resource($proc)) {
                $errors++;
                @unlink($outTxt);
                continue;
            }
            $running[] = ['proc' => $proc, 'uri' => $next['uri'], 'outTxt' => $outTxt, 'file' => $next['file'], 'temp' => $next['temp'], 'started' => time()];
        }
        if (count($running) === 0) {
            break;
        }
        usleep(200000);
        $nowT = time();
        // 心跳 + 页级进度上报（节流 ≥2s）
        if ($jobId > 0 && ($nowT - $lastHb) >= 2) {
            $lastHb = $nowT;
            $detail = 'OCR 处理中';
            $ocrTotal = 0;
            $ocrDoneCount = 0;
            $curUri = '';
            $curName = '';
            if ($activePages !== null) {
                $curUri = (string)$activePages['uri'];
                $ocrTotal = count($activePages['pages']);
                $ocrDoneCount = $activePages['pi'] - 1;
                $detail = 'OCR 第 ' . max(1, $activePages['pi']) . '/' . $ocrTotal . ' 页';
            } elseif (!empty($running)) {
                $curUri = (string)$running[0]['uri'];
                $detail = 'OCR 处理中';
            }
            foreach ($jobs as $jj) {
                if (isset($jj['uri']) && (string)$jj['uri'] === $curUri && isset($jj['name'])) {
                    $curName = (string)$jj['name'];
                    break;
                }
            }
            netdisk_index_job_update($jobId, [
                'current_uri' => $curUri,
                'current_name' => $curName,
                'current_detail' => $detail,
                'ocr_done_count' => $ocrDoneCount,
                'ocr_total' => $ocrTotal,
                'queue_remaining' => max(0, $totalJobs - $jobIdx),
                'heartbeat_at' => $nowT,
            ], $jobPdo);
        }
        // 终止响应：用户请求取消 → 终止所有运行中的 tesseract 子进程并中断池
        if ($jobId > 0 && netdisk_index_job_cancelled($jobId, $jobPdo)) {
            foreach ($running as $rr) {
                @proc_terminate($rr['proc']);
                usleep(50000);
                @proc_terminate($rr['proc'], 9);
                @proc_close($rr['proc']);
                if ($rr['outTxt'] !== '') {
                    @unlink($rr['outTxt'] . '.txt');
                    @unlink($rr['outTxt']);
                }
                if ($rr['temp'] && is_file($rr['file'])) {
                    @unlink($rr['file']);
                }
            }
            if ($activePages !== null) {
                foreach ($activePages['pages'] as $pg) {
                    @unlink($pg);
                }
            }
            $running = [];
            $activePages = null;
            break;
        }
        foreach ($running as $i => $r) {
            $st = @proc_get_status($r['proc']);
            // 单进程超时保护：超过 180s 强制终止（先 SIGTERM，再升级 SIGKILL），避免永久挂死
            if ($st && $st['running'] && ($nowT - $r['started']) > 180) {
                @proc_terminate($r['proc']);
                usleep(50000);
                $st = @proc_get_status($r['proc']);
                if ($st && $st['running']) {
                    @proc_terminate($r['proc'], 9);
                    usleep(50000);
                    $st = @proc_get_status($r['proc']);
                }
            }
            if (!$st || !$st['running']) {
                @proc_close($r['proc']);
                $txt = '';
                if (is_file($r['outTxt'] . '.txt')) {
                    $txt = (string)@file_get_contents($r['outTxt'] . '.txt');
                    @unlink($r['outTxt'] . '.txt');
                }
                @unlink($r['outTxt']);
                // 图片输入用完即删（PDF 页由 activePages 页耗尽时统一删）
                if ($r['temp']) {
                    @unlink($r['file']);
                }
                $uri = $r['uri'];
                $trimmed = trim(preg_replace('/\s+/u', ' ', $txt));
                if ($trimmed !== '') {
                    $results[$uri] = isset($results[$uri]) ? $results[$uri] . ' ' . $trimmed : $trimmed;
                }
                unset($running[$i]);
            }
        }
        $running = array_values($running);
    }
    if ($activePages !== null) {
        foreach ($activePages['pages'] as $pg) {
            @unlink($pg);
        }
    }
    // 注意：$toClean 中的原始 tmp（图片/PDF 字节）在此保留，交给 netdisk_ocr_finalize_results
    // 做自适应 DPI 重试后统一清理；仅 PDF 栅格化产生的页 PNG 已在上方清理。
    $done = count($jobs);
    foreach ($results as $t) {
        if ($t !== '') {
            $texts++;
        }
    }
    return ['results' => $results, 'done' => $done, 'texts' => $texts, 'errors' => $errors];
}

// 判断 OCR 结果是否含"汉字+空格+汉字"模式（旧版 tesseract 未做字间空格归一化，
// 此类文本检索匹配不到，增量重建时应视为需重跑）。
function netdisk_ocr_text_needs_reread(string $text): bool {
    return (bool)preg_match('/\p{Han}\s+\p{Han}/u', $text);
}

// 用指定 DPI 扫描 PDF（$maxPages>0 时仅栅格化前 N 页作样本；null/0 表示整本），返回识别文本。
// 供"前 5 页样本预判 DPI → 整本终扫"复用。超时由 NETDISK_OCR_CMD_TIMEOUT 保护。
// $confStats（可选，引用）：返回 ['avg'=>, 'low_pct'=>, 'n'=>] 识别置信度统计，用于质量判定。
function netdisk_ocr_pdf_scan(string $tmp, int $dpi, ?int $maxPages = null, ?array &$confStats = null): string {
    // sidecar 优先：整本/前 N 页交给 rapidocr，精度高且一次性返回置信度
    if (netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available()) {
        $res = netdisk_ocr_recognize_pdf($tmp, $dpi, 1, ($maxPages !== null && $maxPages > 0) ? $maxPages : 0);
        if ($res !== null) {
            $confStats = ['avg' => $res['avg'], 'low_pct' => $res['low_pct'], 'n' => $res['n']];
            return $res['text'];
        }
    }
    $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($tess === '' || $ppm === '') {
        return '';
    }
    $tmpPdf = $tmp . '.pdf';
    if (!is_file($tmpPdf)) {
        @copy($tmp, $tmpPdf);
    }
    $pageOpt = ($maxPages !== null && $maxPages > 0) ? (' -f 1 -l ' . (int)$maxPages) : '';
    // 0019: 统一渲染入口（夹 DPI + 超时删半截产物）
    netdisk_ocr_pdf_render($ppm, $tmpPdf, $tmpPdf . '_pg', $dpi, $pageOpt);
    $pages = glob($tmpPdf . '_pg-*.png') ?: [];
    @unlink($tmpPdf);
    $out = '';
    $tsvAll = '';
    foreach ($pages as $pg) {
        $outTxt = tempnam(sys_get_temp_dir(), 'ndor_');
        // 同时输出文本与 TSV（word 级置信度）：需显式开启两个配置，否则 tesseract 只输出其一
        $cmd = escapeshellarg($tess) . ' ' . escapeshellarg($pg) . ' ' . escapeshellarg($outTxt) . ' -l chi_sim+eng -c tessedit_create_txt=1 -c tessedit_create_tsv=1 2>/dev/null';
        @shell_exec($cmd);
        if ($outTxt !== false && is_file($outTxt . '.txt')) {
            $out .= ' ' . (string)@file_get_contents($outTxt . '.txt');
            @unlink($outTxt . '.txt');
        }
        if ($outTxt !== false && is_file($outTxt . '.tsv')) {
            $tsvAll .= "\n" . (string)@file_get_contents($outTxt . '.tsv');
            @unlink($outTxt . '.tsv');
        }
        @unlink($outTxt);
        @unlink($pg);
    }
    // 调用方传入引用即代表需要置信度统计，无论传入值是否为 null 都回填
    $parsed = netdisk_ocr_tsv_parse($tsvAll);
    $confStats = ['avg' => $parsed['avg'], 'low_pct' => $parsed['low_pct'], 'n' => $parsed['n']];
    return $out;
}

// 统一后处理 OCR 池结果：文本规范化 → 质量评估 → 自适应 DPI（PDF 前 5 页样本预判 → 整本终扫；
// 页数 ≤5 直接全扫；图片放大重试）→ 清理临时文件。
// $jobs: ['uri'=>, 'tmp'=>原始文件字节路径, 'ext'=>, 'name'=>]（tmp 未清理，供重试用）
// $results: uri => 池内 OCR 原始文本（含字间空格）。
// 返回规范化后的 results；同时清理每个 job 的 tmp。
function netdisk_ocr_finalize_results(array $jobs, array $results): array {
    $fcId = 0;
    foreach ($jobs as $jj) {
        if (isset($jj['config_id'])) {
            $fcId = (int)$jj['config_id'];
            break;
        }
    }
    $dpiStart = netdisk_index_ocr_dpi_start($fcId);
    $dpiMax = netdisk_index_ocr_dpi_max($fcId);
    $qualityThreshold = netdisk_index_ocr_quality_threshold($fcId);
    $tess = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
    $ppm = trim((string)shell_exec('command -v pdftoppm 2>/dev/null'));
    $final = [];
    // 0018: 逐文件耗时经 GLOBALS 透出（uri => ms），供状态表 elapsed_ms/pages_per_min 落库（0021 自动定档样本）。
    // 不改函数签名：调用方读 $GLOBALS['ND_OCR_FINALIZE_TIMINGS']，缺失时退回池累计计时。
    $GLOBALS['ND_OCR_FINALIZE_TIMINGS'] = [];
    foreach ($jobs as $j) {
        $fT0 = microtime(true);
        $uri = (string)($j['uri'] ?? '');
        $tmp = (string)($j['tmp'] ?? '');
        $ext = strtolower(ltrim((string)($j['ext'] ?? ''), '.'));
        $rawTxt = (string)($results[$uri] ?? '');
        // 规范化（去字间空格）
        $norm = netdisk_normalize_ocr_text($rawTxt);
        $quality = netdisk_ocr_quality($norm);
        $sidecarActive = netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available();
        if ($tmp !== '' && is_file($tmp) && ($tess !== '' || $sidecarActive)) {
            if ($ext === 'pdf' && ($ppm !== '' || $sidecarActive)) {
                // 检测总页数：≤5 时 run_pool 的前 5 页样本即全文，可直接采用/整本重扫；
                // >5 或未知时采用"前 5 页样本预判 DPI → 整本终扫"。
                $pagesCount = 0;
                $pdfInfo = @shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
                if (is_string($pdfInfo) && preg_match('/Pages:\s+(\d+)/', $pdfInfo, $pm)) {
                    $pagesCount = (int)$pm[1];
                }
                if ($pagesCount > 0 && $pagesCount <= 5) {
                    // 页数少：起点 DPI 扫描即全文。sidecar（PaddleOCR）一次识别质量高，直接采用；
                    // tesseract 才需要按置信度逐级升 DPI 重扫。
                    $dpi = $dpiStart;
                    $sampleStats = null;
                    $fullTxt = netdisk_ocr_pdf_scan($tmp, $dpi, null, $sampleStats);
                    $fullNorm = netdisk_normalize_ocr_text($fullTxt);
                    if ($sidecarActive) {
                        $norm = $fullNorm;
                    } elseif (trim($fullTxt) === '' || netdisk_ocr_quality_conf($sampleStats ?? []) < $qualityThreshold) {
                        while ($dpi < $dpiMax) {
                            $nextDpi = min($dpiMax, $dpi + 100);
                            $upStats = null;
                            $fullTxt = netdisk_ocr_pdf_scan($tmp, $nextDpi, null, $upStats);
                            if (trim($fullTxt) === '') {
                                break;
                            }
                            $fullNorm = netdisk_normalize_ocr_text($fullTxt);
                            if (netdisk_ocr_quality_conf($upStats ?? []) >= $qualityThreshold || $nextDpi >= $dpiMax) {
                                $norm = $fullNorm;
                                break;
                            }
                            $dpi = $nextDpi;
                        }
                    } else {
                        $norm = $fullNorm;
                    }
                } else {
                    // 前 5 页样本预判 DPI：sidecar 直接用起点 DPI 整本终扫；tesseract 才逐级升档预判
                    $finalDpi = $dpiStart;
                    if (!$sidecarActive) {
                        $sampleStats = null;
                        $sampleTxt = netdisk_ocr_pdf_scan($tmp, $dpiStart, 5, $sampleStats);
                        $sampleNorm = netdisk_normalize_ocr_text($sampleTxt);
                        if (trim($sampleTxt) === '' || netdisk_ocr_quality_conf($sampleStats ?? []) < $qualityThreshold) {
                            $dpi = $dpiStart;
                            while ($dpi < $dpiMax) {
                                $nextDpi = min($dpiMax, $dpi + 100);
                                $upStats = null;
                                $sampleTxt = netdisk_ocr_pdf_scan($tmp, $nextDpi, 5, $upStats);
                                if (trim($sampleTxt) === '') {
                                    // 0 页（假 PDF/损坏）→ 无需更高 DPI，直接用当前空结果
                                    $finalDpi = $nextDpi;
                                    break;
                                }
                                $sampleNorm = netdisk_normalize_ocr_text($sampleTxt);
                                $finalDpi = $nextDpi;
                                if (netdisk_ocr_quality_conf($upStats ?? []) >= $qualityThreshold || $nextDpi >= $dpiMax) {
                                    break;
                                }
                                $dpi = $nextDpi;
                            }
                        }
                    }
                    // 整本终扫（用预判确定的 DPI），得到完整全文
                    $finalStats = null;
                    $fullTxt = netdisk_ocr_pdf_scan($tmp, $finalDpi, null, $finalStats);
                    $norm = netdisk_normalize_ocr_text($fullTxt);
                }
            } elseif ($ext !== 'pdf' && !$sidecarActive && $quality < $qualityThreshold) {
                // 图片：sidecar 模式下 run_pool 已完整识别（PaddleOCR 质量高），此处不再重复；
                // tesseract 才需要按置信度重试/ImageMagick 放大。
                // 质量判定用置信度（比字符占比更能反映识别确定度）。
                $dpi = $dpiStart;
                while ($dpi < $dpiMax) {
                    $nextDpi = min($dpiMax, $dpi + 100);
                    $retryTxt = '';
                    $retryStats = null;
                    $imgFile = $tmp;
                    if (trim((string)shell_exec('command -v convert 2>/dev/null')) !== '') {
                        $bigger = $tmp . '_big.png';
                        @exec("convert " . escapeshellarg($tmp) . " -density " . (int)$nextDpi . " -resize " . (int)$nextDpi . "% " . escapeshellarg($bigger) . ' 2>/dev/null');
                        if (is_file($bigger)) {
                            $imgFile = $bigger;
                        }
                    }
                    $rec = netdisk_ocr_recognize_image($imgFile, $nextDpi);
                    if (isset($bigger) && is_file($bigger)) {
                        @unlink($bigger);
                    }
                    if ($rec !== null) {
                        $retryTxt = $rec['text'];
                        $retryStats = ['avg' => $rec['avg'], 'low_pct' => $rec['low_pct'], 'n' => $rec['n']];
                    }
                    $retryNorm = netdisk_normalize_ocr_text($retryTxt);
                    if (netdisk_ocr_quality_conf($retryStats) >= $qualityThreshold || $nextDpi >= $dpiMax) {
                        $norm = $retryNorm;
                        break;
                    }
                    $dpi = $nextDpi;
                }
            }
        }
        if ($norm !== '') {
            $final[$uri] = $norm;
        }
        // 清理 tmp（池内已保留至此，重试完成后删除）
        if ($tmp !== '' && is_file($tmp)) {
            @unlink($tmp);
        }
        $GLOBALS['ND_OCR_FINALIZE_TIMINGS'][$uri] = (int)round((microtime(true) - $fT0) * 1000);
    }
    return $final;
}

// 统一从文件字节抽取正文：按扩展名分层（文本/Office/PDF 文本层）。OCR 走并发池，不走本函数。
function netdisk_extract_text_from_bytes(string $ext, string $raw, bool $allowOcr): string {
    $ext = strtolower(ltrim($ext, '.'));
    if (netdisk_is_text_ext($ext)) {
        $t = netdisk_text_body_to_utf8($raw);
        return $t !== null ? $t : '';
    }
    $officeExts = ['docx', 'docm', 'dotx', 'xlsx', 'xlsm', 'pptx', 'pptm', 'odt', 'ods', 'odp'];
    if (in_array($ext, $officeExts, true)) {
        $t = netdisk_extract_office_text($ext, $raw);
        if ($t !== '') {
            return $t;
        }
    }
    if ($ext === 'pdf') {
        return netdisk_extract_pdf_text($raw);
    }
    return '';
}

// 连接级全文索引配置（空字符串 = 使用全局默认）
function netdisk_index_cfg_sql_assign(array $fields): string {
    $out = '';
    foreach ($fields as $col => $val) {
        $out .= $col . '=:' . $col . ', ';
    }
    return $out;
}
function netdisk_index_cfg_params(array $fields): array {
    $params = [];
    foreach ($fields as $col => $val) {
        $params[':' . $col] = $val;
    }
    return $params;
}

// 管理端：返回所有启用连接的索引配置快照（config_id => 关联数组，供前端展示/管理）
function netdisk_admin_index_cfg_map(PDO $pdo): array {
    $out = [];
    try {
        $rows = $pdo->query('SELECT id, name, index_ocr_enabled, index_ocr_concurrency, index_ocr_dpi_start, index_ocr_dpi_max, index_ocr_quality_threshold, index_ocr_max_mb, index_ocr_low_max_mb, index_refresh_ttl, index_skip_exts FROM cloudreve_configs ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out[$id] = [
                'id' => $id,
                'name' => (string)$r['name'],
                'ocr_enabled' => trim((string)($r['index_ocr_enabled'] ?? '')) === '' ? netdisk_index_ocr_enabled(0) : (trim((string)$r['index_ocr_enabled']) !== '0'),
                'concurrency' => (int)netdisk_index_cfg_value($id, 'index_ocr_concurrency', 'netdisk_index_ocr_concurrency', '4'),
                'dpi_start' => (int)netdisk_index_cfg_value($id, 'index_ocr_dpi_start', 'netdisk_index_ocr_dpi_start', '200'),
                'dpi_max' => (int)netdisk_index_cfg_value($id, 'index_ocr_dpi_max', 'netdisk_index_ocr_dpi_max', '600'),
                'quality_threshold' => (float)netdisk_index_cfg_value($id, 'index_ocr_quality_threshold', 'netdisk_index_ocr_quality_threshold', '0.3'),
                'max_mb' => (int)netdisk_index_cfg_value($id, 'index_ocr_max_mb', 'netdisk_index_ocr_max_mb', '10'),
                'low_max_mb' => (int)netdisk_index_cfg_value($id, 'index_ocr_low_max_mb', 'netdisk_index_ocr_low_max_mb', '50'),
                'refresh_ttl' => (int)netdisk_index_cfg_value($id, 'index_refresh_ttl', 'netdisk_index_refresh_ttl', '3600'),
                'skip_exts' => implode(',', array_keys(netdisk_index_skip_exts($id))),
                'uses_default' => trim((string)($r['index_ocr_enabled'] ?? '')) === ''
                    && trim((string)($r['index_ocr_concurrency'] ?? '')) === ''
                    && trim((string)($r['index_ocr_dpi_start'] ?? '')) === ''
                    && trim((string)($r['index_ocr_dpi_max'] ?? '')) === ''
                    && trim((string)($r['index_ocr_quality_threshold'] ?? '')) === ''
                    && trim((string)($r['index_ocr_max_mb'] ?? '')) === ''
                    && trim((string)($r['index_ocr_low_max_mb'] ?? '')) === ''
                    && trim((string)($r['index_refresh_ttl'] ?? '')) === ''
                    && trim((string)($r['index_skip_exts'] ?? '')) === '',
            ];
        }
    } catch (Throwable $e) {
        // ignore: 迁移尚未完成时
    }
    return $out;
}

// 管理端：某个连接的索引配置快照（单条，语义同 map）
function netdisk_admin_index_cfg_snapshot(PDO $pdo, int $configId): ?array {
    $map = netdisk_admin_index_cfg_map($pdo);
    return $map[$configId] ?? null;
}

// 是否启用 OCR（扫描 PDF/图片），默认开启；configId>0 时按连接独立
function netdisk_index_ocr_enabled(?int $configId = 0): bool {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_enabled', 'netdisk_index_ocr', '1');
    return trim((string)$v) !== '0';
}

// OCR 并发进程数：同时最多运行 N 个 tesseract（避免 NAS 满载），可后台调整
function netdisk_index_ocr_concurrency(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_concurrency', 'netdisk_index_ocr_concurrency', '4');
    return max(1, (int)$v);
}

// 正常优先级 OCR 的单文件体积上限（MB）：≤该值在重建时立即入池 OCR
function netdisk_index_ocr_max_mb(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_max_mb', 'netdisk_index_ocr_max_mb', '10');
    return max(1, (int)$v);
}

// 低优先级 OCR 兜底上限（MB）：>max_mb 且 ≤该值的文件写入 netdisk_ocr_queue，
// 由后台 worker 在空闲时 OCR；超过该值则跳过 OCR（仍按文件名索引）。
function netdisk_index_ocr_low_max_mb(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_ocr_low_max_mb', 'netdisk_index_ocr_low_max_mb', '50');
    return max(1, (int)$v);
}

// 索引刷新 TTL（秒）：索引建好后长期有效；超过该时长后，搜索仍返回索引结果，
// 同时后台排队一次刷新任务（增量）。默认 3600（1 小时）。
function netdisk_index_refresh_ttl(?int $configId = 0): int {
    $v = netdisk_index_cfg_value((int)$configId, 'index_refresh_ttl', 'netdisk_index_refresh_ttl', '3600');
    return max(60, (int)$v);
}

// 索引黑名单扩展名（跳过下载正文/OCR，仍按文件名索引）。逗号分隔，默认 zip,dwg,rar。
function netdisk_index_skip_exts(?int $configId = 0): array {
    $raw = trim((string)netdisk_index_cfg_value((int)$configId, 'index_skip_exts', 'netdisk_index_skip_exts', 'zip,dwg,rar'));
    $out = [];
    foreach (preg_split('/[,，\s]+/', $raw) as $e) {
        $e = strtolower(ltrim(trim($e), '.'));
        if ($e !== '') {
            $out[$e] = true;
        }
    }
    return $out;
}

// 创建/追加一个后台重建 job。若该分享已有 running 或 pending 任务则复用返回其 id。
function netdisk_index_job_create(int $shareId, bool $forceOcr = false): int {
    $pdo = db();
    $ex = $pdo->prepare('SELECT id FROM netdisk_index_jobs WHERE share_id=:s AND status IN ("pending","running") ORDER BY id DESC LIMIT 1');
    $ex->execute([':s' => $shareId]);
    $existing = (int)($ex->fetchColumn() ?: 0);
    $ex->closeCursor();
    if ($existing > 0) {
        // 0018: 复用未开始/进行中的 job 时，本次请求带强制重跑则升级标记（worker 拾取时读取）
        // 0023: 复用 pending 行时一并刷新 created_at——其口径为「最后一次被触发的时间」，
        //       worker 的「pending 超 6 小时判死」即「触发后 6 小时仍未被拾取」。旧实现不刷新，
        //       隔日重触发会复用昨天的 created_at，worker 一进回收循环就误判「队列等待超时」。
        //       running 行有心跳、语义是已开跑，不得刷新（仅 status="pending" 的行会被本 UPDATE 命中）。
        if ($forceOcr) {
            $pdo->prepare('UPDATE netdisk_index_jobs SET created_at=:t, force_ocr=1 WHERE id=:id AND status="pending"')->execute([':t' => time(), ':id' => $existing]);
        } else {
            $pdo->prepare('UPDATE netdisk_index_jobs SET created_at=:t WHERE id=:id AND status="pending"')->execute([':t' => time(), ':id' => $existing]);
        }
        return $existing;
    }
    $pdo->prepare('INSERT INTO netdisk_index_jobs (share_id, status, created_at, force_ocr) VALUES (:s, "pending", :t, :f)')
        ->execute([':s' => $shareId, ':t' => time(), ':f' => $forceOcr ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

// 通用锁重试执行：SQLite 在 WAL 下偶发 "database is locked"，重试数次并短暂等待。
// 总等待窗口应覆盖所用连接的 busy_timeout：
//   - db() 静态单例连接 = 30s（见 :214 PRAGMA busy_timeout = 30000）
//   - netdisk_index_job_update 等自建的独立连接 = 15s（PRAGMA busy_timeout = 15000）
// 默认 40×500ms=20s 对独立连接够用；走 db() 的关键写入应显式传更大 attempts。
// 返回受影响行数；重试耗尽后抛出异常（由调用方决定降级）。
// 注意：同连接"读快照未关游标 → 再写"的升级死锁会立即报错、busy_timeout 不生效，
// 重试对该场景无效，调用方须先 closeCursor()。
function netdisk_exec_retry(callable $fn, int $attempts = 40): mixed {
    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $fn();
        } catch (Throwable $e) {
            if ($i === $attempts - 1) {
                throw $e;
            }
            usleep(500000); // 500ms，总窗口 40×500ms=20s
        }
    }
    return null;
}

// 更新 job 进度（进程内使用）。遇到数据库锁等瞬时错误时静默忽略，避免中断重建主流程。
// $pdo 可选：传入则复用该连接执行 UPDATE（与调用方共用单一连接，避免同进程多连接交叉写锁）。
function netdisk_index_job_update(int $jobId, array $patch, ?PDO $pdo = null): void {
    if ($jobId <= 0) {
        return;
    }
    $allowed = ['status', 'files', 'folders', 'texts', 'errors', 'ocr_skipped', 'ocr_done', 'ocr_queued', 'processed_dirs', 'total_dirs', 'total_files', 'current_dir', 'current_uri', 'current_name', 'current_detail', 'ocr_done_count', 'ocr_total', 'queue_remaining', 'heartbeat_at', 'notes', 'error', 'started_at', 'finished_at', 'attempts', 'cancel_requested', 'ocr_files_total', 'ocr_files_done', 'crash_count'];
    $sets = [];
    $params = [':id' => $jobId];
    foreach ($patch as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            continue;
        }
        $sets[] = "$k = :p_$k";
        $params[":p_$k"] = $v;
    }
    if (empty($sets)) {
        return;
    }
    if ($pdo !== null) {
        try {
            $pdo->prepare('UPDATE netdisk_index_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        } catch (Throwable $e) {
            // 瞬时锁/写冲突：忽略本次进度上报，下次循环继续
        }
        return;
    }
    // 未传连接：用独立连接执行 UPDATE，避免与 db() 静态连接上其它活动语句（缓存/插入）冲突导致 bad parameter
    try {
        $updPdo = new PDO('sqlite:' . $GLOBALS['config']['db_path']);
        $updPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $updPdo->exec('PRAGMA busy_timeout = 15000;');
        $updPdo->prepare('UPDATE netdisk_index_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    } catch (Throwable $e) {
        // 瞬时锁/写冲突：忽略本次进度上报，下次循环继续
    }
}

// 取给定分享当前最 “活跃” 的 job 汇总；无则 null
function netdisk_index_job_latest(int $shareId): ?array {
    $stmt = db()->prepare('SELECT * FROM netdisk_index_jobs WHERE share_id=:s ORDER BY id DESC LIMIT 1');
    $stmt->execute([':s' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

// 检查 job 是否被请求终止。复用调用方连接查询（避免同进程多连接）。
// 返回 true 表示应中断当前重建。
function netdisk_index_job_cancelled(int $jobId, ?PDO $pdo = null): bool {
    if ($jobId <= 0) {
        return false;
    }
    try {
        if ($pdo === null) {
            $pdo = db();
        }
        $v = (int)$pdo->query('SELECT cancel_requested FROM netdisk_index_jobs WHERE id=' . (int)$jobId)->fetchColumn();
        return $v === 1;
    } catch (Throwable $e) {
        return false;
    }
}

// 预扫描分享目录树：只计数目录/文件数量（复用目录缓存，正式遍历直接命中缓存）。用于进度总量。
function netdisk_scan_share_tree(array $cfg, string $root): array {
    $conn = $cfg['cfg'] ?? $cfg;
    $visited = 0;
    $files = 0;
    $folders = 0;
    $currentLevel = [$root];
    $seenDirs = [$root => true];
    for ($depth = 0; $depth <= 5; $depth++) {
        if (empty($currentLevel) || $visited >= 300) {
            break;
        }
        $batch = [];
        foreach ($currentLevel as $dirUri) {
            if ($visited >= 300) {
                break;
            }
            $visited++;
            $batch[] = $dirUri;
        }
        $batchData = cloudreve_list_folder_multi($cfg, $batch, 300);
        $nextLevel = [];
        foreach ($batch as $dirUri) {
            $data = $batchData[$dirUri] ?? null;
            if (!is_array($data)) {
                continue;
            }
            netdisk_register_redirect_aliases($conn, $root, $data);
            foreach (($data['files'] ?? []) as $f) {
                $isFolder = (int)($f['type'] ?? 0) === 1;
                if ($isFolder) {
                    $folders++;
                    $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
                    $childUri = $redirect !== '' ? $redirect : (string)($f['path'] ?? '');
                    if ($childUri !== '' && netdisk_uri_within_root($root, $childUri, $conn) && !isset($seenDirs[$childUri])) {
                        $seenDirs[$childUri] = true;
                        $nextLevel[] = $childUri;
                    }
                } else {
                    $files++;
                }
            }
        }
        $currentLevel = $nextLevel;
    }
    return ['files' => $files, 'folders' => $folders, 'dirs' => $visited];
}

// ===== 0024: OCR 结果内容寻址（content-addressed）=====
// 识别正文按 sha256(原始文件字节) + params_key 绑定，而非 (share_id, uri) 路径键。
// 价值：改名/移动/重命名父目录后内容不变 → 命中复用，不重新 OCR；同一内容挂多个分享只扫一次。
// params_key 是 NAS 侧渲染/引擎参数指纹：任何预处理变更必须先动 render_ver 或相关字段，
// 让旧结果自然失效（不做数据迁移）。PC 端导入须原样回传 params_key，不一致即拒收。

// params_key 规格（单一来源；0024 写库与 0025 GET /admin/netdisk/ocr/params 必须调本函数）：
//   engine_tag | profile | dpi | max_side | render_ver
//   例：paddle5|high|300|960|r1
// - engine_tag 固定 'paddle5'（引擎换代时才改）
// - profile 取 OCR_PROFILE 环境变量（0022 解析后为 low/high；web 容器未设时记 'low'，
//   与 ocr 容器实际档位可能不同——params_key 只需 NAS 侧自洽且稳定，PC 端原样回传即可）
// - dpi 取连接级 index_ocr_dpi_start 实际值；max_side 取 netdisk_ocr_image_resize_b64 默认 960
// - render_ver v1 固定 'r1'；缺失字段一律空串占位，不许省略（否则两侧拼出的字符串不一致且极难排查）
function netdisk_ocr_params_key(?int $configId = 0): string {
    $profile = strtolower(trim((string)(getenv('OCR_PROFILE') ?: 'low')));
    if ($profile === '') {
        $profile = 'low';
    }
    $dpi = netdisk_index_ocr_dpi_start((int)$configId);
    $maxSide = 960; // 与 netdisk_ocr_image_resize_b64() 默认 maxSide 一致
    return 'paddle5|' . $profile . '|' . $dpi . '|' . $maxSide . '|r1';
}

// 结果表查询：命中且达标（netdisk_ocr_index_quality_ok 同口径：chars>=20 且 quality>=0.6）返回行，
// 未命中/不达标/异常返回 null。params_key 不一致视为未命中（不得静默复用旧参数结果）。
// 达标门槛与 0018 状态行跳过判定同口径：不达标结果不传播，走重扫路径（重试语义与 0018 一致）。
function netdisk_ocr_result_lookup(string $hash, string $paramsKey, ?PDO $pdo = null): ?array {
    if ($hash === '' || $paramsKey === '') {
        return null;
    }
    try {
        $pdo = $pdo ?? db();
        $st = $pdo->prepare('SELECT content_hash, params_key, size, pages, chars, quality, engine, text, source, created_at, last_hit_at
            FROM netdisk_ocr_results WHERE content_hash=:h AND params_key=:k LIMIT 1');
        $st->execute([':h' => $hash, ':k' => $paramsKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false || !netdisk_ocr_index_quality_ok((string)$row['text'])) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

// 结果表写入（upsert）：created_at 仅首次插入置值，重导入不覆盖；last_hit_at 每次刷新。
// text 入库前过 netdisk_normalize_ocr_text()（与 NAS 直扫同口径）；chars/quality 服务端重算。
function netdisk_ocr_result_save(string $hash, string $paramsKey, int $size, int $pages, string $text,
    string $engine, string $source, ?PDO $pdo = null): bool {
    if ($hash === '' || $paramsKey === '') {
        return false;
    }
    $text = netdisk_normalize_ocr_text($text);
    if ($text === '') {
        return false;
    }
    try {
        $pdo = $pdo ?? db();
        $chars = string_len($text);
        $quality = netdisk_ocr_quality($text);
        $now = time();
        $st = $pdo->prepare('INSERT INTO netdisk_ocr_results
            (content_hash, params_key, size, pages, chars, quality, engine, text, source, created_at, last_hit_at)
            VALUES (:h, :k, :z, :pg, :c, :q, :e, :t, :src, :now, :now)
            ON CONFLICT(content_hash, params_key) DO UPDATE SET
                size=excluded.size, pages=excluded.pages, chars=excluded.chars, quality=excluded.quality,
                engine=excluded.engine, text=excluded.text, source=excluded.source, last_hit_at=excluded.last_hit_at');
        $st->execute([':h' => $hash, ':k' => $paramsKey, ':z' => $size, ':pg' => $pages, ':c' => $chars,
            ':q' => $quality, ':e' => $engine, ':t' => $text, ':src' => $source, ':now' => $now]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// GC（孤儿保留策略）：仍被任一 netdisk_index.content_hash 引用的结果行永不删除；
// 孤儿行（无任何引用）按 last_hit_at 淘汰——超过 TTL 天删除，或孤儿总字节超上限时按 last_hit_at ASC 淘汰到阈值以下。
// 触发点：索引重建收尾跑一次（不新增 cron、不加 worker 任务）。单次打一行 [nd-index] gc results deleted=N kept=M。
// 孤儿策略的意义：PC 先扫后传场景下，导入结果在 NAS 遍历到该文件前没有引用，绝不能被当垃圾清掉。
function netdisk_ocr_results_gc(?PDO $pdo = null): void {
    try {
        $pdo = $pdo ?? db();
        $ttlDays = (int)(getenv('NETDISK_OCR_RESULTS_TTL') ?: '90');
        if ($ttlDays <= 0) {
            $ttlDays = 90;
        }
        $maxMb = (int)(getenv('NETDISK_OCR_RESULTS_MAX_MB') ?: '5120');
        if ($maxMb <= 0) {
            $maxMb = 5120;
        }
        $now = time();
        $cutoff = $now - $ttlDays * 86400;
        // 孤儿 = 不被任何 netdisk_index.content_hash 引用的结果行
        $orphanCond = 'NOT EXISTS (SELECT 1 FROM netdisk_index i WHERE i.content_hash = netdisk_ocr_results.content_hash AND i.content_hash != "")';
        // TTL 淘汰
        $delTtl = $pdo->prepare('DELETE FROM netdisk_ocr_results WHERE ' . $orphanCond . ' AND last_hit_at < :cut');
        $delTtl->execute([':cut' => $cutoff]);
        $deleted = $delTtl->rowCount();
        // 容量淘汰：孤儿总字节（LENGTH(text)）超上限 → 按 last_hit_at ASC 删到阈值以下
        $capBytes = $maxMb * 1048576;
        $sumSt = $pdo->query('SELECT COALESCE(SUM(LENGTH(CAST(text AS BLOB))),0) FROM netdisk_ocr_results WHERE ' . $orphanCond);
        $orphanBytes = (int)$sumSt->fetchColumn();
        $sumSt->closeCursor();
        if ($orphanBytes > $capBytes) {
            $selOrph = $pdo->query('SELECT content_hash, params_key, LENGTH(CAST(text AS BLOB)) AS tlen FROM netdisk_ocr_results
                WHERE ' . $orphanCond . ' ORDER BY last_hit_at ASC');
            $orphRows = $selOrph->fetchAll(PDO::FETCH_ASSOC);
            $selOrph->closeCursor();
            $delCap = $pdo->prepare('DELETE FROM netdisk_ocr_results WHERE content_hash=:h AND params_key=:k');
            foreach ($orphRows as $or) {
                if ($orphanBytes <= $capBytes) {
                    break;
                }
                $delCap->execute([':h' => $or['content_hash'], ':k' => $or['params_key']]);
                $deleted += $delCap->rowCount();
                $orphanBytes -= (int)$or['tlen'];
            }
        }
        $keptSt = $pdo->query('SELECT COUNT(*) FROM netdisk_ocr_results');
        $kept = (int)$keptSt->fetchColumn();
        $keptSt->closeCursor();
        @error_log('[nd-index] gc results deleted=' . $deleted . ' kept=' . $kept);
    } catch (Throwable $e) {
        // GC 失败不影响本轮索引
    }
}

// 从 Cloudreve 文件项 metadata 探测 sha256（大小写归一 + 去 'sha256:' 前缀 + 64hex 校验）。
// 候选键名按优先级探测；命中返回小写 hex 并打 source=metadata 日志，未命中返回 ''（打降级日志带 reason）。
// 注意：只在快速路径未命中后才调用（判定顺序①优先——快速路径命中时哈希函数零调用）。
function netdisk_ocr_file_hash_meta(array $f, string $uri, string &$reason = ''): string {
    if (isset($GLOBALS['ND_CA_HASH_CALLS'])) {
        $GLOBALS['ND_CA_HASH_CALLS']++;
    }
    $md = $f['metadata'] ?? null;
    $reason = 'metadata-absent';
    if (!is_array($md) || empty($md)) {
        @error_log('[nd-index] hash source=download reason=metadata-absent uri=' . netdisk_ocr_log_oneline($uri, 200));
        return '';
    }
    foreach (['sys:sha256', 'sha256', 'sys:hash', 'hash', 'sys:checksum', 'checksum'] as $k) {
        if (!array_key_exists($k, $md)) {
            continue;
        }
        $v = strtolower(trim((string)$md[$k]));
        if (str_starts_with($v, 'sha256:')) {
            $v = substr($v, 7);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $v)) {
            @error_log('[nd-index] hash source=metadata uri=' . netdisk_ocr_log_oneline($uri, 200));
            return $v;
        }
        $reason = 'metadata-algo-unknown';
    }
    @error_log('[nd-index] hash source=download reason=' . $reason . ' uri=' . netdisk_ocr_log_oneline($uri, 200));
    return '';
}

// 降级路径：对已下载字节算 sha256（不为算哈希单独多下一次文件——复用调用方已持有的 $raw）。
function netdisk_ocr_file_hash_bytes(string $raw, string $uri): string {
    if (isset($GLOBALS['ND_CA_HASH_CALLS'])) {
        $GLOBALS['ND_CA_HASH_CALLS']++;
    }
    $h = hash('sha256', $raw);
    @error_log('[nd-index] hash source=download uri=' . netdisk_ocr_log_oneline($uri, 200));
    return $h;
}

// 重建/刷新某分享的网盘本地全文索引（增量：按 Cloudreve updated_at 跳过未变化文件，不重复下载）
// 返回 ['files'=>int, 'folders'=>int, 'texts'=>int, 'errors'=>int, 'skipped'=>int, 'notes'=>string,
//        'ocr_done'=>int, 'ocr_queued'=>int, 'ocr_oversize'=>int]
function netdisk_build_index_share(array $share, array $cfg, string $root, bool $allowOcr, int $jobId = 0, bool $forceOcr = false): array {
    // 独立连接：避免与 db() 静态 PDO / web 端并发语句冲突（SQLite 同连接仅允许一个活动语句）
    $pdo = new PDO('sqlite:' . $GLOBALS['config']['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 30000;');
    $shareId = (int)$share['id'];
    $files = 0;
    $folders = 0;
    $texts = 0;
    $errors = 0;
    $skipped = 0;       // 未变化、未重复下载的文件数
    $skippedByExt = 0;  // 黑名单扩展名跳过的文件数
    $notes = [];
    $visited = 0;
    $currentLevel = [$root];
    $seenDirs = [$root => true];
    $contCfg = $cfg['cfg'];
    $configId = (int)($cfg['config_id'] ?? 0); // 连接级索引/OCR 配置归属
    // 0024: 本轮内容寻址参数指纹（单一来源函数 netdisk_ocr_params_key）；results 表查询/写入均用此 key
    $caParamsKey = netdisk_ocr_params_key($configId);
    $ocrReuseHit = 0;     // 0024: 内容寻址命中复用（未重新 OCR）的文件数
    $lastHb = 0; // 心跳节流：距上次上报至少 1s

    // 预扫描总量（目录缓存 300s，正式遍历直接命中，不增加 API 请求）
    $scan = netdisk_scan_share_tree($cfg, $root);
    if ($jobId > 0) {
        netdisk_index_job_update($jobId, [
            'status' => 'running', 'started_at' => time(), 'heartbeat_at' => time(),
            'total_dirs' => $scan['dirs'], 'total_files' => $scan['files'],
            'processed_dirs' => 0, 'current_dir' => (string)$root, 'error' => '',
            'current_detail' => '预扫描目录',
        ], $pdo);
    }
    // 0018: 不再整表清空低优先级 OCR 队列——改在遍历结束后仅清理 uri 已消失的行，
    // 保留 pending/failed 任务可续跑，避免"改并发→重建→队列重排"导致已完成/排队任务重复下载。
    $ocrMaxBytes = $allowOcr ? (netdisk_index_ocr_max_mb($configId) * 1048576) : 0;
    $ocrLowBytes = $allowOcr ? (netdisk_index_ocr_low_max_mb($configId) * 1048576) : 0;
    $ocrTasks = [];       // 高优先级（≤max_mb）：重建时入池
    $ocrQueued = 0;       // 低优先级（>max_mb ≤low_max_mb）：入队空闲 OCR
    $ocrOversize = 0;     // >low_max_mb：跳过 OCR
    $ocrDone = 0;         // 高优先级池实际 OCR 完成的文件数
    $ocrSkipInc = 0;      // 0018: 状态表达标、增量跳过的 OCR 文件数
    $ocrGaveUp = 0;       // 0018: failed/invalid 且 attempts>=3、放弃重试的 OCR 文件数
    $ocrBackfilled = 0;   // 0018: 旧索引 text 已达标、回填状态行的文件数
    $ocrFailedNow = 0;    // 0018: 本轮 OCR 失败（结果为空）的文件数
    $ocrStarvedMark = 0;  // 0018: 本轮新入低优队列、状态标记 starved 的文件数
    $ocrSkipQueued = 0;   // 0018 必改: 已在低优队列（pending/running）且 updated_at 未变、按增量跳过的文件数（不再重复下载）
    $ocrQueueDup = 0;     // 0018 必改: 队列已有 pending/running 行时抑制的重复入队次数
    $insQueue = $pdo->prepare('INSERT INTO netdisk_ocr_queue (share_id, config_id, uri, name, ext, size, status, created_at)
        VALUES (:s, :c, :u, :n, :e, :z, "pending", :t)');

    // 载入现有索引（增量依据：uri => [updated_at, text, is_folder]）
    $existing = [];
    $sel = $pdo->prepare('SELECT uri, updated_at, text, is_folder, content_hash FROM netdisk_index WHERE share_id = :s');
    $sel->execute([':s' => $shareId]);
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[(string)$row['uri']] = [
            'updated_at' => (string)$row['updated_at'],
            'text' => (string)$row['text'],
            'is_folder' => (int)$row['is_folder'],
            // 0024: fast-path 复用轮不重算哈希，写回时保留既有 content_hash（不被空串覆盖）
            'content_hash' => (string)($row['content_hash'] ?? ''),
        ];
    }
    $seenUris = [];

    // 0018: 载入 OCR 文件级状态（uri => 行），增量判定与 attempts 计数依据
    $ocrState = [];
    $selOcr = $pdo->prepare('SELECT uri, updated_at, status, quality, chars, attempts, first_at, elapsed_ms, pages_per_min FROM netdisk_ocr_files WHERE share_id = :s');
    $selOcr->execute([':s' => $shareId]);
    foreach ($selOcr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ocrState[(string)$row['uri']] = $row;
    }
    $selOcr->closeCursor();
    // 0018 必改: 载入「已排队」集合——队列中 pending/running 行的 uri；
    // updated_at 未变时按增量跳过（$isUnchanged=true、复用旧正文、不下载），
    // 避免大文件每轮重建都完整重新下载再排队。
    $ocrQueueMap = [];
    try {
        $selQm = $pdo->prepare('SELECT uri FROM netdisk_ocr_queue WHERE share_id = :s AND status IN ("pending","running")');
        $selQm->execute([':s' => $shareId]);
        foreach ($selQm->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ocrQueueMap[(string)$row['uri']] = true;
        }
        $selQm->closeCursor();
    } catch (Throwable $ign) {
        // 队列映射读取失败不影响本轮索引（退化为原下载路径）
    }
    // 状态行 upsert：冲突时保留 first_at，其余字段覆盖为本轮结果
    $upOcrFile = $pdo->prepare('INSERT INTO netdisk_ocr_files (share_id, uri, updated_at, size, status, quality, chars, attempts, last_error, dpi, pages_total, pages_done, engine, elapsed_ms, pages_per_min, first_at, finished_at)
        VALUES (:sid, :uri, :u, :z, :st, :q, :c, :a, :e, :dpi, :pt, :pd, :eng, :ems, :ppm, :first, :t)
        ON CONFLICT(share_id, uri) DO UPDATE SET updated_at=excluded.updated_at, size=excluded.size, status=excluded.status,
            quality=excluded.quality, chars=excluded.chars, attempts=excluded.attempts, last_error=excluded.last_error,
            dpi=excluded.dpi, pages_total=excluded.pages_total, pages_done=excluded.pages_done, engine=excluded.engine,
            elapsed_ms=excluded.elapsed_ms, pages_per_min=excluded.pages_per_min, finished_at=excluded.finished_at');
    // 0020: starved 标记专用 upsert——与 worker 让位处同口径 CASE 保护：
    // done/failed/invalid 行不被降级为 starved（0018 的两处裸 upsert 会把 done-不达标/failed-未熔断
    // 的行覆盖成 starved），仅刷新 updated_at/size；attempts 对受保护行原样保留。
    $upOcrFileStarved = $pdo->prepare('INSERT INTO netdisk_ocr_files (share_id, uri, updated_at, size, status, quality, chars, attempts, last_error, dpi, pages_total, pages_done, engine, elapsed_ms, pages_per_min, first_at, finished_at)
        VALUES (:sid, :uri, :u, :z, "starved", 0, 0, :a, "starved", 0, 0, 0, "", 0, 0, :first, :t)
        ON CONFLICT(share_id, uri) DO UPDATE SET updated_at=excluded.updated_at, size=excluded.size,
            status=CASE WHEN netdisk_ocr_files.status IN ("done", "failed", "invalid") THEN netdisk_ocr_files.status ELSE "starved" END,
            last_error=CASE WHEN netdisk_ocr_files.status IN ("done", "failed", "invalid") THEN netdisk_ocr_files.last_error ELSE "starved" END,
            attempts=CASE WHEN netdisk_ocr_files.status IN ("done", "failed", "invalid") THEN netdisk_ocr_files.attempts ELSE excluded.attempts END');

    $ins = $pdo->prepare('INSERT INTO netdisk_index (share_id, uri, name, is_folder, ext, size, parent_path, text, indexed_at, updated_at, content_hash)
        VALUES (:sid, :uri, :name, :isf, :ext, :size, :parent, :text, :t, :u, :ch)
        ON CONFLICT(share_id, uri) DO UPDATE SET name=:name2, is_folder=:isf2, ext=:ext2, size=:size2, parent_path=:parent2, text=:text2, indexed_at=:t2, updated_at=:u2, content_hash=:ch2');
    $updText = $pdo->prepare('UPDATE netdisk_index SET text=:text WHERE share_id=:sid AND uri=:uri');
    for ($depth = 0; $depth <= 5; $depth++) {
        if (empty($currentLevel) || $visited >= 300) {
            break;
        }
        $batch = [];
        foreach ($currentLevel as $dirUri) {
            if ($visited >= 300) {
                break;
            }
            $visited++;
            $batch[] = $dirUri;
        }
        $batchData = cloudreve_list_folder_multi($cfg, $batch, 300);
        $nextLevel = [];
        foreach ($batch as $dirUri) {
            $data = $batchData[$dirUri] ?? null;
            if (!is_array($data)) {
                $errors++;
                continue;
            }
            netdisk_register_redirect_aliases($contCfg, $root, $data);
            $parent = $dirUri;
            foreach (($data['files'] ?? []) as $f) {
                $isFolder = (int)($f['type'] ?? 0) === 1;
                $name = (string)($f['name'] ?? '');
                $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
                $navigateUri = $redirect !== '' ? $redirect : (string)($f['path'] ?? '');
                if ($navigateUri === '') {
                    continue;
                }
                $ext = $isFolder ? '' : netdisk_file_ext($name);
                $size = (int)($f['size'] ?? 0);
                $cloudUpdatedAt = (string)($f['updated_at'] ?? '');
                $seenUris[$navigateUri] = true;
                // 0024: 内容寻址哈希惰性求值——快速路径（判定顺序①）命中时保持 ''，哈希函数零调用；
                // 未命中才在 F 分支（metadata 探测）或下载分支（字节顺带算）里赋值。
                $caHash = '';

$text = '';
                $skipExts = netdisk_index_skip_exts($configId);
                $isSkipExt = !$isFolder && isset($skipExts[$ext]);

                // 增量判定：已索引且 uri/更新标记一致 → 复用旧正文，不下载。
                // 但正文为空（上次 OCR 未完成/失败）的文件不跳过，本次重试。
                $prev = $existing[$navigateUri] ?? null;
                // 0018: OCR 候选不再"始终重扫"，改按 netdisk_ocr_files 状态表增量判定：
                //  - status=done 且 quality>=0.6、chars>=20、updated_at 与云端一致 → 跳过（计入增量）；
                //  - status IN (failed,invalid) 且 attempts>=3 → 跳过（不再无限重试坏文件）；
                //  - 无状态行但旧 netdisk_index.text 已达标且 updated_at 一致 → 回填 done 行，不送 OCR（老索引平滑升级）；
                //  - 已在低优队列（pending/running）且 updated_at 未变（无状态行或状态为 starved）→ 按增量跳过，不再重复下载；
                //  - 其余（无记录 / updated_at 变化 / 质量不达标且未达重试上限）→ 照旧下载、入池。
                // force_ocr=1（强制重跑）忽略上述全部跳过判定。netdisk_ocr_index_quality_ok() 仍作达标门槛。
                $isOcrCandFile = !$isFolder && !$isSkipExt && netdisk_is_ocr_candidate($ext);
                $ocrSkipDone = false;
                $ocrSkipGaveUp = false;
                $ocrBackfillRow = false;
                $ocrSkipQueuedRow = false;
                if ($isOcrCandFile && !$forceOcr) {
                    $st = $ocrState[$navigateUri] ?? null;
                    if ($st !== null) {
                        $stUpdatedOk = ((string)$st['updated_at'] === $cloudUpdatedAt)
                            || ((string)$st['updated_at'] === '' && $prev !== null && (string)$prev['updated_at'] === $cloudUpdatedAt);
                        if ((string)$st['status'] === 'done' && (float)$st['quality'] >= 0.6 && (int)$st['chars'] >= 20 && $stUpdatedOk) {
                            $ocrSkipDone = true;
                        } elseif (in_array((string)$st['status'], ['failed', 'invalid'], true) && (int)$st['attempts'] >= 3) {
                            $ocrSkipGaveUp = true;
                        }
                    } elseif ($prev !== null && (string)$prev['updated_at'] !== '' && (string)$prev['updated_at'] === $cloudUpdatedAt
                        && netdisk_ocr_index_quality_ok((string)($prev['text'] ?? ''))) {
                        $ocrBackfillRow = true;
                    }
                    // 0018 必改: 已排队（pending/running）且 updated_at 未变 → 按增量跳过，不再重复下载。
                    // 仅在无状态行或状态为 starved 时生效：done-不达标 / failed-未熔断的文件仍需重扫，
                    // 不能被遗留队列行掩盖（否则质量不达标的文件永远等不到重扫）。
                    if (!$ocrSkipDone && !$ocrSkipGaveUp && !$ocrBackfillRow
                        && isset($ocrQueueMap[$navigateUri])
                        && ($st === null || (string)$st['status'] === 'starved')
                        && $prev !== null && (string)$prev['updated_at'] !== '' && (string)$prev['updated_at'] === $cloudUpdatedAt) {
                        $ocrSkipQueuedRow = true;
                    }
                }
                $ocrSkipAny = $ocrSkipDone || $ocrSkipGaveUp || $ocrBackfillRow || $ocrSkipQueuedRow;
                // OCR 候选：有状态行时完全以状态表为准（done+达标才跳，quality 不达标即使 text 看似达标也重扫）；
                // 无状态行时才用 netdisk_ocr_index_quality_ok 判定旧 text 是否可回填。
                $isUnchanged = !$isFolder && $prev !== null
                    && $prev['updated_at'] !== '' && $prev['updated_at'] === $cloudUpdatedAt
                    && (int)$prev['is_folder'] === 0
                    && (netdisk_is_ocr_candidate($ext)
                        ? (!$forceOcr && $ocrSkipAny)
                        : $prev['text'] !== '');

                if ($isFolder) {
                    $text = '';
                } elseif ($isUnchanged) {
                    $text = $prev['text'];
                    $skipped++;
                    if ($isOcrCandFile) {
                        // 0018: OCR 候选被增量跳过 → 分类计数；回填场景补写状态行（下次直接命中）
                        if ($ocrSkipGaveUp) {
                            $ocrGaveUp++;
                        } elseif ($ocrSkipQueuedRow) {
                            $ocrSkipQueued++;
                        } else {
                            $ocrSkipInc++;
                        }
                        if ($ocrBackfillRow) {
                            $bfQ = netdisk_ocr_quality((string)$prev['text']);
                            $bfChars = string_len((string)$prev['text']);
                            $bfNow = time();
                            netdisk_exec_retry(fn() => $upOcrFile->execute([
                                ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                                ':st' => 'done', ':q' => $bfQ, ':c' => $bfChars, ':a' => 0, ':e' => '',
                                ':dpi' => 0, ':pt' => 0, ':pd' => 0, ':eng' => '', ':ems' => 0, ':ppm' => 0.0, ':first' => $bfNow, ':t' => $bfNow,
                            ]));
                            $ocrState[$navigateUri] = ['uri' => $navigateUri, 'updated_at' => $cloudUpdatedAt, 'status' => 'done',
                                'quality' => $bfQ, 'chars' => $bfChars, 'attempts' => 0, 'first_at' => $bfNow];
                            $ocrBackfilled++;
                        }
                    }
                } elseif ($isSkipExt) {
                    // 黑名单扩展名：跳过下载正文/OCR，仅索引文件名
                    $skippedByExt++;
                } elseif ($isOcrCandFile && !$forceOcr
                    && ($caHash = netdisk_ocr_file_hash_meta($f, $navigateUri)) !== ''
                    && ($caRow = netdisk_ocr_result_lookup($caHash, $caParamsKey, $pdo)) !== null) {
                    // 0024 判定顺序②：metadata 已带 sha256 且结果表命中达标 → 直接复用正文，不下载、不进 OCR 队列。
                    // 改名/移动/重命名父目录后内容不变即走此路（uri 变、updated_at 可能变，但哈希不变）。
                    $text = (string)$caRow['text'];
                    $skipped++;
                    $ocrReuseHit++;
                    $rhNow = time();
                    $rhQ = netdisk_ocr_quality($text);
                    $rhChars = string_len($text);
                    netdisk_exec_retry(fn() => $upOcrFile->execute([
                        ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                        ':st' => 'done', ':q' => $rhQ, ':c' => $rhChars, ':a' => 0, ':e' => '',
                        ':dpi' => 0, ':pt' => (int)$caRow['pages'], ':pd' => (int)$caRow['pages'], ':eng' => 'reuse-' . (string)$caRow['source'],
                        ':ems' => 0, ':ppm' => 0.0, ':first' => $rhNow, ':t' => $rhNow,
                    ]));
                    netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                        ->execute([':h' => $caHash, ':s' => $shareId, ':u' => $navigateUri]));
                    netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_results SET last_hit_at=:t WHERE content_hash=:h AND params_key=:k')
                        ->execute([':t' => $rhNow, ':h' => $caHash, ':k' => $caParamsKey]));
                    if ($text !== '') {
                        $texts++;
                    }
                } elseif (!$isFolder && $size > 0 && $size <= 5242880) {
                    // ≤5MB：下载取正文（文本/Office/PDF 文本层/OCR 高优）
                    $isOcr = $allowOcr && netdisk_is_ocr_candidate($ext);
                    $raw = netdisk_fetch_raw_bytes($contCfg, $navigateUri);
                    if ($raw === null) {
                        $errors++;
                    } else {
                        // 0024: 已下载字节顺带算哈希（OCR 候选才算；不为算哈希单独多下一次文件）
                        if ($caHash === '' && $isOcr) {
                            $caHash = netdisk_ocr_file_hash_bytes($raw, $navigateUri);
                        }
                        $text = netdisk_extract_text_from_bytes($ext, $raw, false);
                        // 0024 判定顺序②：文本层为空需 OCR 时，先查内容寻址结果表；命中达标即复用，不进池/队
                        $caHitG = ($isOcr && $text === '' && $caHash !== '' && !$forceOcr)
                            ? netdisk_ocr_result_lookup($caHash, $caParamsKey, $pdo) : null;
                        if ($caHitG !== null) {
                            $text = (string)$caHitG['text'];
                            $skipped++;
                            $ocrReuseHit++;
                            $rhNow = time();
                            $rhQ = netdisk_ocr_quality($text);
                            $rhChars = string_len($text);
                            netdisk_exec_retry(fn() => $upOcrFile->execute([
                                ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                                ':st' => 'done', ':q' => $rhQ, ':c' => $rhChars, ':a' => 0, ':e' => '',
                                ':dpi' => 0, ':pt' => (int)$caHitG['pages'], ':pd' => (int)$caHitG['pages'], ':eng' => 'reuse-' . (string)$caHitG['source'],
                                ':ems' => 0, ':ppm' => 0.0, ':first' => $rhNow, ':t' => $rhNow,
                            ]));
                            netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                                ->execute([':h' => $caHash, ':s' => $shareId, ':u' => $navigateUri]));
                            netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_results SET last_hit_at=:t WHERE content_hash=:h AND params_key=:k')
                                ->execute([':t' => $rhNow, ':h' => $caHash, ':k' => $caParamsKey]));
                        } elseif ($isOcr && $text === '' && $size <= $ocrLowBytes) {
                            if ($size <= $ocrMaxBytes) {
                                $tmp = tempnam(sys_get_temp_dir(), 'ndo_');
                                if ($tmp !== false) {
                                    @file_put_contents($tmp, $raw);
                                    $ocrTasks[] = ['uri' => $navigateUri, 'tmp' => $tmp, 'ext' => $ext, 'name' => $name, 'config_id' => $configId, 'updated_at' => $cloudUpdatedAt, 'size' => $size, 'content_hash' => $caHash];
                                } else {
                                    $errors++;
                                }
                            } else {
                                // 0018 必改: 已有 pending/running 队列行则不重复入队（多轮重建不再堆积重复行、同一文件不被 OCR 多遍）
                                $qDup = false;
                                try {
                                    $qDupSt = $pdo->prepare('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE share_id=:s AND uri=:u AND status IN ("pending","running")');
                                    $qDupSt->execute([':s' => $shareId, ':u' => $navigateUri]);
                                    $qDup = (int)$qDupSt->fetchColumn() > 0;
                                    $qDupSt->closeCursor();
                                } catch (Throwable $ign) {}
                                if (!$qDup) {
                                    netdisk_exec_retry(fn() => $insQueue->execute([':s' => $shareId, ':c' => $configId, ':u' => $navigateUri, ':n' => $name, ':e' => $ext, ':z' => $size, ':t' => time()]));
                                    $ocrQueued++;
                                } else {
                                    $ocrQueueDup++;
                                }
                                // 0018: 低优队列文件标记 starved（排队待空闲处理；让位/排队不计失败、不计入 attempts>=3 永久跳过）
                                // 0020: 改用 CASE 保护 upsert——done/failed/invalid 行不被降级，仅刷新 updated_at/size
                                $qNow = time();
                                $qPrev = $ocrState[$navigateUri] ?? null;
                                $qPrevSt = (string)($qPrev['status'] ?? '');
                                netdisk_exec_retry(fn() => $upOcrFileStarved->execute([
                                    ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                                    ':a' => (int)($qPrev['attempts'] ?? 0), ':first' => $qNow, ':t' => $qNow,
                                ]));
                                if (in_array($qPrevSt, ['done', 'failed', 'invalid'], true)) {
                                    // 受保护行：DB 保持原 status/last_error/attempts，内存态同步只刷新 updated_at/size
                                    if (is_array($qPrev)) {
                                        $ocrState[$navigateUri]['updated_at'] = $cloudUpdatedAt;
                                        $ocrState[$navigateUri]['size'] = $size;
                                    }
                                } else {
                                    $ocrState[$navigateUri] = ['uri' => $navigateUri, 'updated_at' => $cloudUpdatedAt, 'status' => 'starved',
                                        'quality' => 0, 'chars' => 0, 'attempts' => (int)($qPrev['attempts'] ?? 0), 'first_at' => $qNow];
                                    $ocrStarvedMark++;
                                }
                            }
                        }
                        if ($text !== '') {
                            $texts++;
                        }
                    }
                } elseif (!$isFolder && $size > 5242880 && $size > 0) {
                    // 5MB~上限 的 OCR 候选：≤max_mb 取字节入池，否则入低优队列/跳过
                    $isOcr = $allowOcr && netdisk_is_ocr_candidate($ext);
                    if ($isOcr && $size > $ocrLowBytes) {
                        $ocrOversize++;
                    } elseif ($isOcr && $size <= $ocrMaxBytes) {
                        $raw = netdisk_fetch_raw_bytes($contCfg, $navigateUri);
                        if ($raw === null) {
                            $errors++;
                        } else {
                            // 0024: 已下载字节顺带算哈希
                            if ($caHash === '') {
                                $caHash = netdisk_ocr_file_hash_bytes($raw, $navigateUri);
                            }
                            $caHitH = (!$forceOcr) ? netdisk_ocr_result_lookup($caHash, $caParamsKey, $pdo) : null;
                            if ($caHitH !== null) {
                                // 0024 判定顺序②：内容寻址命中达标 → 复用正文，不进高优池
                                $text = (string)$caHitH['text'];
                                $skipped++;
                                $ocrReuseHit++;
                                $rhNow = time();
                                $rhQ = netdisk_ocr_quality($text);
                                $rhChars = string_len($text);
                                netdisk_exec_retry(fn() => $upOcrFile->execute([
                                    ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                                    ':st' => 'done', ':q' => $rhQ, ':c' => $rhChars, ':a' => 0, ':e' => '',
                                    ':dpi' => 0, ':pt' => (int)$caHitH['pages'], ':pd' => (int)$caHitH['pages'], ':eng' => 'reuse-' . (string)$caHitH['source'],
                                    ':ems' => 0, ':ppm' => 0.0, ':first' => $rhNow, ':t' => $rhNow,
                                ]));
                                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                                    ->execute([':h' => $caHash, ':s' => $shareId, ':u' => $navigateUri]));
                                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_results SET last_hit_at=:t WHERE content_hash=:h AND params_key=:k')
                                    ->execute([':t' => $rhNow, ':h' => $caHash, ':k' => $caParamsKey]));
                                if ($text !== '') {
                                    $texts++;
                                }
                            } else {
                                $tmp = tempnam(sys_get_temp_dir(), 'ndo_');
                                if ($tmp !== false) {
                                    @file_put_contents($tmp, $raw);
                                    $ocrTasks[] = ['uri' => $navigateUri, 'tmp' => $tmp, 'ext' => $ext, 'name' => $name, 'config_id' => $configId, 'updated_at' => $cloudUpdatedAt, 'size' => $size, 'content_hash' => $caHash];
                                } else {
                                    $errors++;
                                }
                            }
                        }
                    } elseif ($isOcr) {
                        // 0018 必改: 已有 pending/running 队列行则不重复入队（多轮重建不再堆积重复行、同一文件不被 OCR 多遍）
                        $qDup = false;
                        try {
                            $qDupSt = $pdo->prepare('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE share_id=:s AND uri=:u AND status IN ("pending","running")');
                            $qDupSt->execute([':s' => $shareId, ':u' => $navigateUri]);
                            $qDup = (int)$qDupSt->fetchColumn() > 0;
                            $qDupSt->closeCursor();
                        } catch (Throwable $ign) {}
                        if (!$qDup) {
                            netdisk_exec_retry(fn() => $insQueue->execute([':s' => $shareId, ':c' => $configId, ':u' => $navigateUri, ':n' => $name, ':e' => $ext, ':z' => $size, ':t' => time()]));
                            $ocrQueued++;
                        } else {
                            $ocrQueueDup++;
                        }
                        // 0018: 低优队列文件标记 starved（排队待空闲处理；让位/排队不计失败、不计入 attempts>=3 永久跳过）
                        // 0020: 改用 CASE 保护 upsert——done/failed/invalid 行不被降级，仅刷新 updated_at/size
                        $qNow = time();
                        $qPrev = $ocrState[$navigateUri] ?? null;
                        $qPrevSt = (string)($qPrev['status'] ?? '');
                        netdisk_exec_retry(fn() => $upOcrFileStarved->execute([
                            ':sid' => $shareId, ':uri' => $navigateUri, ':u' => $cloudUpdatedAt, ':z' => $size,
                            ':a' => (int)($qPrev['attempts'] ?? 0), ':first' => $qNow, ':t' => $qNow,
                        ]));
                        if (in_array($qPrevSt, ['done', 'failed', 'invalid'], true)) {
                            // 受保护行：DB 保持原 status/last_error/attempts，内存态同步只刷新 updated_at/size
                            if (is_array($qPrev)) {
                                $ocrState[$navigateUri]['updated_at'] = $cloudUpdatedAt;
                                $ocrState[$navigateUri]['size'] = $size;
                            }
                        } else {
                            $ocrState[$navigateUri] = ['uri' => $navigateUri, 'updated_at' => $cloudUpdatedAt, 'status' => 'starved',
                                'quality' => 0, 'chars' => 0, 'attempts' => (int)($qPrev['attempts'] ?? 0), 'first_at' => $qNow];
                            $ocrStarvedMark++;
                        }
                    }
                }
                $now = time();
                // 0024: content_hash 写回——本轮算出/命中即用新值；快速路径等未算哈希的轮次保留既有值（不被空串覆盖）
                $caHashW = $caHash !== '' ? $caHash : (string)($prev['content_hash'] ?? '');
                netdisk_exec_retry(fn() => $ins->execute([
                    ':sid' => $shareId, ':uri' => $navigateUri, ':name' => $name, ':isf' => $isFolder ? 1 : 0,
                    ':ext' => $ext, ':size' => $size, ':parent' => $parent, ':text' => $text, ':t' => $now, ':u' => $cloudUpdatedAt, ':ch' => $caHashW,
                    ':name2' => $name, ':isf2' => $isFolder ? 1 : 0, ':ext2' => $ext, ':size2' => $size,
                    ':parent2' => $parent, ':text2' => $text, ':t2' => $now, ':u2' => $cloudUpdatedAt, ':ch2' => $caHashW,
                ]));
                // 细粒度进度：按文件上报当前处理对象 + 心跳（节流 ≥1s）
                if ($jobId > 0 && ($now - $lastHb) >= 1) {
                    $lastHb = $now;
                    netdisk_index_job_update($jobId, [
                        'files' => $files + 1,
                        'processed_dirs' => $visited,
                        'current_uri' => $navigateUri,
                        'current_name' => $name,
                        'current_detail' => $isFolder ? '遍历目录' : '解析内容',
                        'queue_remaining' => max(0, $scan['files'] - $files - 1),
                        'heartbeat_at' => $now,
                    ], $pdo);
                }
                if ($isFolder) {
                    $folders++;
                } else {
                    $files++;
                }
                if ($isFolder) {
                    $childUri = $redirect !== '' ? $redirect : $navigateUri;
                    if ($childUri !== '' && netdisk_uri_within_root($root, $childUri, $contCfg) && !isset($seenDirs[$childUri])) {
                        $seenDirs[$childUri] = true;
                        $nextLevel[] = $childUri;
                    }
                }
            }
            if ($jobId > 0) {
                netdisk_index_job_update($jobId, [
                    'processed_dirs' => $visited,
                    'files' => $files,
                    'folders' => $folders,
                    'texts' => $texts,
                    'errors' => $errors,
                    'ocr_queued' => $ocrQueued,
                ], $pdo);
            }
            // 终止响应：用户请求取消 → 中断遍历（跳过 OCR 池）
            if ($jobId > 0 && netdisk_index_job_cancelled($jobId, $pdo)) {
                // 清理已入队的低优任务与临时文件由外层统一处理；此处直接跳出遍历
                unset($currentLevel);
                break 2;
            }
        }
        $currentLevel = $nextLevel;
    }
    // 高优先级 OCR 并发池：遍历结束后统一执行（同时 ≤concurrency 个 tesseract）
    if (!empty($ocrTasks) && $allowOcr) {
        // 记录本次高优 OCR 文件总数（供前端 OCR 文件级进度条）
        if ($jobId > 0) {
            netdisk_index_job_update($jobId, ['ocr_files_total' => count($ocrTasks), 'ocr_files_done' => 0], $pdo);
        }
        $poolRes = netdisk_ocr_run_pool($ocrTasks, netdisk_index_ocr_concurrency($configId), $jobId, $pdo);
        $stEngine = (netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available()) ? 'paddleocr-sidecar'
            : (trim((string)shell_exec('command -v tesseract 2>/dev/null')) !== '' ? 'tesseract' : '');
        // 0018: 池起点计时——elapsed_ms/pages_per_min 供 0021 自动定档做历史样本
        $poolT0 = microtime(true);
        // 0019: 逐文件 finalize（旧实现对全部任务一次性 netdisk_ocr_finalize_results，
        // 整本重扫+逐级升 DPI 期间零心跳（前端 300s 判死），一次 Fatal 全部结果尽丢）。
        // 现在每文件独立 finalize：完成即写正文+状态行+心跳；中途崩溃时已完成文件全部保留，
        // 配合 0018 增量判定，重启后天然按文件粒度续跑。
        $finTotal = count($ocrTasks);
        $finIdx = 0;
        foreach ($ocrTasks as $task) {
            $uri = $task['uri'];
            $finIdx++;
            // 0018: finalize 会删除 tmp，先抓该文件 PDF 总页数供状态表落库（尽力而为，取不到记 0）
            $stPages = 0;
            if (strtolower(ltrim((string)($task['ext'] ?? ''), '.')) === 'pdf' && isset($task['tmp']) && is_file($task['tmp'])) {
                $pi = @shell_exec('pdfinfo ' . escapeshellarg($task['tmp']) . ' 2>/dev/null');
                if (is_string($pi) && preg_match('/Pages:\s+(\d+)/', $pi, $pm)) {
                    $stPages = (int)$pm[1];
                }
            }
            // 该文件的池内原始结果单独交给 finalize（签名不变，单元素数组即逐文件模式）
            $oneRaw = isset($poolRes['results'][$uri]) ? [$uri => $poolRes['results'][$uri]] : [];
            $oneFinal = netdisk_ocr_finalize_results([$task], $oneRaw);
            // 0019: 每文件 finalize 完成即写心跳——长 PDF 后处理期间前端不再误判 worker 已死
            if ($jobId > 0) {
                netdisk_index_job_update($jobId, [
                    'heartbeat_at' => time(),
                    'current_detail' => 'OCR 后处理 ' . $finIdx . '/' . $finTotal . '：' . (string)($task['name'] ?? ''),
                ], $pdo);
            }
            $txt = trim((string)($oneFinal[$uri] ?? ''));
            if ($txt !== '') {
                netdisk_exec_retry(fn() => $updText->execute([':text' => $txt, ':sid' => $shareId, ':uri' => $uri]));
                $texts++;
                $ocrDone++;
                // 0024 判定顺序③：OCR 成功后额外写一份进内容寻址结果表（source='nas'），供改名/移动/多分享复用
                $caTaskHash = (string)($task['content_hash'] ?? '');
                if ($caTaskHash !== '') {
                    netdisk_ocr_result_save($caTaskHash, $caParamsKey, (int)($task['size'] ?? 0), $stPages, $txt, $stEngine, 'nas', $pdo);
                    netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_index SET content_hash=:h WHERE share_id=:s AND uri=:u')
                        ->execute([':h' => $caTaskHash, ':s' => $shareId, ':u' => $uri]));
                }
            } else {
                $prevText = $existing[$uri]['text'] ?? '';
                if ($prevText !== '') {
                    netdisk_exec_retry(fn() => $updText->execute([':text' => $prevText, ':sid' => $shareId, ':uri' => $uri]));
                }
            }
            // 0018: 无论成败都落状态行——下轮重建的增量判定据此进行；
            // 失败仅 attempts++ 并计入 notes，不中断整轮（连续 3 次失败后不再重试）。
            $stQ = $txt !== '' ? netdisk_ocr_quality($txt) : 0.0;
            $stChars = $txt !== '' ? string_len($txt) : 0;
            $prevSt = $ocrState[$uri] ?? null;
            // 0018 必改: attempts = 连续失败计数——成功归零；失败 +1；force_ocr 轮失败从 1 重计（不被 3 次上限挡住）。
            // 否则成功多轮的文件累计 attempts>=3 后，一次偶发失败即被永久放弃。
            $stAtt = ($txt !== '') ? 0 : ($forceOcr ? 1 : ((int)($prevSt['attempts'] ?? 0) + 1));
            $stFirst = (int)($prevSt['first_at'] ?? 0) > 0 ? (int)$prevSt['first_at'] : time();
            // 0019: 失败归因——预算守卫标记过的文件（巨图/超大文件）落 invalid + 具体原因，
            // 不再笼统记 failed；invalid 同样计入 attempts>=3 熔断（巨图重试三次也不会变小）。
            $invReason = isset($task['tmp']) ? netdisk_ocr_invalid_reason((string)$task['tmp']) : '';
            // 0020: sidecar 服务端失败（5xx/超时/解析失败）→ 状态行仍 failed（文件本身不坏，
            // 交连续失败计数管），last_error 记具体原因替代笼统文案
            $failReason = isset($task['tmp']) ? netdisk_ocr_fail_reason((string)$task['tmp']) : '';
            $stStatus = $txt !== '' ? 'done' : ($invReason !== '' ? 'invalid' : 'failed');
            $stErr = $txt !== '' ? '' : ($invReason !== '' ? $invReason : ($failReason !== '' ? $failReason : 'OCR 返回空文本（图片损坏/格式不支持/OCR 服务异常）'));
            // 0019: 页级进度——成功记总页数；失败记已成功识别页数（GLOBALS 由 recognize_pdf 维护）
            $stPagesDone = $txt !== '' ? $stPages : (int)($GLOBALS['ND_OCR_PAGES_DONE'][(string)($task['tmp'] ?? '')] ?? 0);
            // 0018: 耗时指标——优先 finalize 逐文件真实耗时；桩/异常缺失时退回池累计。
            // pages_per_min 仅 PDF（页数>0）有意义，图片记 0。
            $stElapsed = (int)($GLOBALS['ND_OCR_FINALIZE_TIMINGS'][$uri] ?? 0);
            if ($stElapsed <= 0) {
                $stElapsed = (int)round((microtime(true) - $poolT0) * 1000);
            }
            $stPpm = ($stPages > 0 && $stElapsed > 0) ? round($stPages / ($stElapsed / 60000.0), 2) : 0.0;
            netdisk_exec_retry(fn() => $upOcrFile->execute([
                ':sid' => $shareId, ':uri' => $uri, ':u' => (string)($task['updated_at'] ?? ''), ':z' => (int)($task['size'] ?? 0),
                ':st' => $stStatus, ':q' => $stQ, ':c' => $stChars, ':a' => $stAtt, ':e' => $stErr,
                ':dpi' => $txt !== '' ? netdisk_index_ocr_dpi_start($configId) : 0,
                ':pt' => $stPages, ':pd' => $stPagesDone,
                ':eng' => $stEngine, ':ems' => $stElapsed, ':ppm' => $stPpm, ':first' => $stFirst, ':t' => time(),
            ]));
            $ocrState[$uri] = ['uri' => $uri, 'updated_at' => (string)($task['updated_at'] ?? ''), 'status' => $stStatus,
                'quality' => $stQ, 'chars' => $stChars, 'attempts' => $stAtt, 'first_at' => $stFirst];
            // 0024: content_hash 回填必须在状态行 upsert 之后——upsert 语句不含该列，
            // 若在 upsert 前 UPDATE 会命中 0 行，状态行 content_hash 永远为空（快速路径①因此永不命中）。
            if ($txt !== '' && (string)($task['content_hash'] ?? '') !== '') {
                $caRtHash = (string)$task['content_hash'];
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                    ->execute([':h' => $caRtHash, ':s' => $shareId, ':u' => $uri]));
            }
            if ($txt === '') {
                $ocrFailedNow++;
            }
            // 每个文件完成（含页级 + 自适应 DPI 重试）后递增文件级 OCR 进度
            if ($jobId > 0) {
                netdisk_index_job_update($jobId, [
                    'ocr_files_done' => $ocrDone,
                    'heartbeat_at' => time(),
                    'current_detail' => 'OCR 完成 ' . $ocrDone . '/' . count($ocrTasks) . ' 个文件',
                ], $pdo);
            }
        }
    } elseif (!empty($ocrTasks)) {
        // OCR 未启用：清理临时文件，不处理
        foreach ($ocrTasks as $task) {
            @unlink($task['tmp']);
        }
    }
    // 清理索引中已不存在的条目（删除的文件）
    // 0024: 此处只删路径行（netdisk_index 与 netdisk_ocr_files）；netdisk_ocr_results 结果行一行都不跟着删——
    // 路径行删除后对应结果行变孤儿，交收尾 GC 按 last_hit_at + TTL/容量淘汰
    // （PC 先扫后传的结果在 NAS 遍历到该文件之前就是孤儿，绝不能被当垃圾清掉）。
    $allUris = netdisk_exec_retry(fn() => $pdo->query("SELECT uri FROM netdisk_index WHERE share_id=$shareId")->fetchAll(PDO::FETCH_COLUMN)) ?: [];
    $toDelete = array_values(array_diff($allUris, array_keys($seenUris)));
    if (!empty($toDelete)) {
        $del = $pdo->prepare('DELETE FROM netdisk_index WHERE share_id=:s AND uri=:u');
        $delOcrF = $pdo->prepare('DELETE FROM netdisk_ocr_files WHERE share_id=:s AND uri=:u');
        foreach ($toDelete as $uri) {
            netdisk_exec_retry(fn() => $del->execute([':s' => $shareId, ':u' => $uri]));
            netdisk_exec_retry(fn() => $delOcrF->execute([':s' => $shareId, ':u' => $uri]));
        }
    }
    // 0018: 替代原"重建开头整表清空"——仅删除本轮遍历中已消失（云端删除/移出 root）的队列行，
    // pending/failed 任务保留续跑；与 netdisk_index 的清理同处执行。
    try {
        $qSel = $pdo->prepare('SELECT id, uri FROM netdisk_ocr_queue WHERE share_id = :s');
        $qSel->execute([':s' => $shareId]);
        $qRows = $qSel->fetchAll(PDO::FETCH_ASSOC);
        $qSel->closeCursor();
        $delQ = $pdo->prepare('DELETE FROM netdisk_ocr_queue WHERE id = :id');
        foreach ($qRows as $qr) {
            if (!isset($seenUris[(string)$qr['uri']])) {
                netdisk_exec_retry(fn() => $delQ->execute([':id' => (int)$qr['id']]));
            }
        }
    } catch (Throwable $ign) {
        // 队列清理失败不影响本轮索引结果
    }
    // 0024: 重建收尾跑一次结果表 GC（孤儿按 last_hit_at + TTL/容量淘汰；被引用行永不删）
    netdisk_ocr_results_gc($pdo);
    if ($visited >= 300) {
        $notes[] = '达到目录上限(300)，大目录可能未完整索引';
    }
    if ($skipped > 0) {
        $notes[] = $skipped . ' 个文件未变化已跳过（增量）';
    }
    if ($skippedByExt > 0) {
        $notes[] = $skippedByExt . ' 个黑名单扩展名文件跳过正文（仅文件名索引）';
    }
    if ($ocrDone > 0) {
        $notes[] = 'OCR 完成 ' . $ocrDone . ' 个文件（并发 ' . netdisk_index_ocr_concurrency() . '）';
    }
    if ($ocrQueued > 0) {
        $notes[] = $ocrQueued . ' 个较大文件（>' . netdisk_index_ocr_max_mb() . 'MB）已加入低优先级 OCR 队列，将后台空闲时处理';
    }
    if ($ocrStarvedMark > 0) {
        $notes[] = $ocrStarvedMark . ' 个低优队列文件标记为待处理（starved）——排队/让位不计入失败次数，不会触发≥3 次永久跳过';
    }
    if ($ocrOversize > 0) {
        $notes[] = $ocrOversize . ' 个超大文件（>' . netdisk_index_ocr_low_max_mb() . 'MB）跳过 OCR，仍按文件名索引';
    }
    if ($ocrReuseHit > 0) {
        $notes[] = $ocrReuseHit . ' 个 OCR 文件按内容哈希命中复用（改名/移动/多分享共享，未重新识别）';
    }
    if ($ocrSkipInc > 0) {
        $notes[] = $ocrSkipInc . ' 个 OCR 文件已跳过（增量：结果达标未重扫' . ($ocrBackfilled > 0 ? '，含 ' . $ocrBackfilled . ' 个旧索引回填' : '') . '）';
    }
    if ($ocrSkipQueued > 0) {
        $notes[] = $ocrSkipQueued . ' 个 OCR 文件已跳过（已在低优先级队列排队，未重复下载）';
    }
    if ($ocrQueueDup > 0) {
        $notes[] = $ocrQueueDup . ' 个文件已在低优先级队列中，本轮抑制重复入队';
    }
    if ($ocrGaveUp > 0) {
        $notes[] = $ocrGaveUp . ' 个文件 OCR 失败≥3 次不再重试（可在重建时勾选「强制重跑 OCR」重试）';
    }
    if ($ocrFailedNow > 0) {
        $notes[] = $ocrFailedNow . ' 个文件 OCR 失败已跳过（状态已记录，下轮重建自动重试）';
    }
    if ($jobId > 0) {
        $cancelled = netdisk_index_job_cancelled($jobId, $pdo);
        netdisk_index_job_update($jobId, [
            'status' => $cancelled ? 'cancelled' : 'done',
            'finished_at' => time(),
            'files' => $files,
            'folders' => $folders,
            'texts' => $texts,
            'errors' => $errors,
            'ocr_done' => $ocrDone,
            'ocr_queued' => $ocrQueued,
            'processed_dirs' => $visited,
            'cancel_requested' => 0,
            'notes' => implode('；', $notes),
        ], $pdo);
    }
    return ['files' => $files, 'folders' => $folders, 'texts' => $texts, 'errors' => $errors, 'skipped' => $skipped,
        'notes' => implode('；', $notes), 'ocr_done' => $ocrDone, 'ocr_queued' => $ocrQueued, 'ocr_oversize' => $ocrOversize,
        'cancelled' => isset($cancelled) ? (bool)$cancelled : false];
}

// 低优先级 OCR 队列：worker 空闲时处理一批（时间盒 maxSeconds，最长一次跑完），
// 返回本批处理完成的项数。处理结果回填 netdisk_index.text。遇到新的 pending 重建 job 时提前让位。
// 注意：不在此处用全局 OCR 开关拦截——队列项按各自 config_id 的连接级 OCR 配置处理。
function netdisk_process_ocr_queue_batch(int $maxSeconds = 120): int {
    // 独立连接：避免与 db() 静态 PDO 上其它活动语句冲突（SQLite 同连接仅允许一个活动语句）
    $pdo = new PDO('sqlite:' . $GLOBALS['config']['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 30000;');
    $concurrency = netdisk_index_ocr_concurrency(0);
    $start = time();
    $totalDone = 0;
    $markRunning = $pdo->prepare('UPDATE netdisk_ocr_queue SET status="running", started_at=:t, error="" WHERE id=:id');
    $markFailed = $pdo->prepare('UPDATE netdisk_ocr_queue SET status="failed", error=:e, finished_at=:t WHERE id=:id');
    $markDone = $pdo->prepare('UPDATE netdisk_ocr_queue SET status="done", finished_at=:t, error="" WHERE id=:id');
    $updIndex = $pdo->prepare('UPDATE netdisk_index SET text=:t WHERE share_id=:s AND uri=:u');
    // 0018: 队列批处理的结果同样落 netdisk_ocr_files 状态表（否则队列完成的重建仍会重扫）
    $upOcrFileQ = $pdo->prepare('INSERT INTO netdisk_ocr_files (share_id, uri, updated_at, size, status, quality, chars, attempts, last_error, dpi, pages_total, pages_done, engine, elapsed_ms, pages_per_min, first_at, finished_at)
        VALUES (:sid, :uri, :u, :z, :st, :q, :c, :a, :e, :dpi, :pt, :pd, :eng, :ems, :ppm, :first, :t)
        ON CONFLICT(share_id, uri) DO UPDATE SET updated_at=excluded.updated_at, size=excluded.size, status=excluded.status,
            quality=excluded.quality, chars=excluded.chars, attempts=excluded.attempts, last_error=excluded.last_error,
            dpi=excluded.dpi, pages_total=excluded.pages_total, pages_done=excluded.pages_done, engine=excluded.engine,
            elapsed_ms=excluded.elapsed_ms, pages_per_min=excluded.pages_per_min, finished_at=excluded.finished_at');
    $shareStmt = $pdo->prepare('SELECT * FROM shares WHERE id=:id AND deleted_at IS NULL');
    // 自愈：回收 running 超时（>10 分钟）的队列项——worker 崩溃/卡死后遗留，避免永久卡住
    $pdo->prepare('UPDATE netdisk_ocr_queue SET status="failed", error="处理超时已重置", finished_at=:now
        WHERE status="running" AND started_at>0 AND started_at<:cut')
        ->execute([':now' => time(), ':cut' => time() - 600]);

    $qYielded = false; // 0018: 是否因 pending 重建 job 让位（让位时把仍 pending 的队列项标记 starved）
    while (time() - $start < $maxSeconds) {
        $rows = netdisk_exec_retry(function () use ($pdo): array {
            $stmt = $pdo->query('SELECT id, share_id, config_id, uri, ext, size FROM netdisk_ocr_queue WHERE status="pending" ORDER BY id ASC LIMIT 1');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $rows;
        }) ?: [];
        if (empty($rows)) {
            break;
        }
        $jobs = [];
        $meta = [];
        foreach ($rows as $row) {
            $qid = (int)$row['id'];
            $shareId = (int)$row['share_id'];
            $uri = (string)$row['uri'];
            netdisk_exec_retry(fn() => $markRunning->execute([':t' => time(), ':id' => $qid]));
            $shareStmt->execute([':id' => $shareId]);
            $shareRow = $shareStmt->fetch(PDO::FETCH_ASSOC);
            $shareStmt->closeCursor();
            if (!$shareRow) {
                netdisk_exec_retry(fn() => $markFailed->execute([':e' => '分享不存在', ':t' => time(), ':id' => $qid]));
                continue;
            }
            $base = netdisk_share_config($shareRow, $pdo);
            if (!$base['enabled'] || !$base['cfg']) {
                netdisk_exec_retry(fn() => $markFailed->execute([':e' => '分享未启用网盘', ':t' => time(), ':id' => $qid]));
                continue;
            }
            $rowCfgId = (int)($row['config_id'] ?? $base['config_id'] ?? 0);
            if (!netdisk_index_ocr_enabled($rowCfgId)) {
                // 该连接级 OCR 已关闭：此队列项不再处理（标记完成，避免永久堆积）
                netdisk_exec_retry(fn() => $markDone->execute([':t' => time(), ':id' => $qid]));
                continue;
            }
            $size = (int)($row['size'] ?? 0);
            $rowLowBytes = netdisk_index_ocr_low_max_mb($rowCfgId) * 1048576;
            if ($size > $rowLowBytes) {
                netdisk_exec_retry(fn() => $markFailed->execute([':e' => '超过低优先级上限', ':t' => time(), ':id' => $qid]));
                continue;
            }
            $raw = netdisk_fetch_raw_bytes($base['cfg'], $uri);
            if ($raw === null) {
                
                // 下载失败：attempts<3 自动重试，否则永久失败（不中断索引）
                $dlAtt = 0;
                try { $dlAtt = (int)$pdo->query('SELECT attempts FROM netdisk_ocr_queue WHERE id=' . (int)$qid)->fetchColumn(); } catch (Throwable $ign) {}
                if ($dlAtt < 3) {
                    $pdo->prepare('UPDATE netdisk_ocr_queue SET status="pending", started_at=0, attempts="' . ($dlAtt + 1) . '", error="下载失败，待重试" WHERE id=:id')->execute([':id' => $qid]);
                } else {
                    netdisk_exec_retry(fn() => $markFailed->execute([':e' => '下载失败（已重试3次）', ':t' => time(), ':id' => $qid]));
                }
                continue;
            }
            // 0024: 已下载字节顺带算哈希；内容寻址命中达标则直接复用正文、标记队列完成，不进 OCR 池
            $caHashQ = netdisk_ocr_file_hash_bytes($raw, $uri);
            $caParamsKeyQ = netdisk_ocr_params_key($rowCfgId);
            $caHitQ = netdisk_ocr_result_lookup($caHashQ, $caParamsKeyQ, $pdo);
            if ($caHitQ !== null) {
                $caTxtQ = (string)$caHitQ['text'];
                netdisk_exec_retry(fn() => $updIndex->execute([':t' => $caTxtQ, ':s' => $shareId, ':u' => $uri]));
                netdisk_exec_retry(fn() => $markDone->execute([':t' => time(), ':id' => $qid]));
                $caNowQ = time();
                $caQQ = netdisk_ocr_quality($caTxtQ);
                $caCQ = string_len($caTxtQ);
                netdisk_exec_retry(fn() => $upOcrFileQ->execute([
                    ':sid' => $shareId, ':uri' => $uri, ':u' => '', ':z' => $size,
                    ':st' => 'done', ':q' => $caQQ, ':c' => $caCQ, ':a' => 0, ':e' => '',
                    ':dpi' => 0, ':pt' => (int)$caHitQ['pages'], ':pd' => (int)$caHitQ['pages'], ':eng' => 'reuse-' . (string)$caHitQ['source'],
                    ':ems' => 0, ':ppm' => 0.0, ':first' => $caNowQ, ':t' => $caNowQ,
                ]));
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                    ->execute([':h' => $caHashQ, ':s' => $shareId, ':u' => $uri]));
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_index SET content_hash=:h WHERE share_id=:s AND uri=:u')
                    ->execute([':h' => $caHashQ, ':s' => $shareId, ':u' => $uri]));
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_results SET last_hit_at=:t WHERE content_hash=:h AND params_key=:k')
                    ->execute([':t' => $caNowQ, ':h' => $caHashQ, ':k' => $caParamsKeyQ]));
                $totalDone++;
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ndo_');
            if ($tmp === false) {
                netdisk_exec_retry(fn() => $markFailed->execute([':e' => '临时文件失败', ':t' => time(), ':id' => $qid]));
                continue;
            }
            @file_put_contents($tmp, $raw);
            $jobs[] = ['uri' => $uri, 'tmp' => $tmp, 'ext' => (string)($row['ext'] ?? ''), 'name' => (string)($row['name'] ?? ''), 'config_id' => $rowCfgId];
            $meta[$uri] = ['qid' => $qid, 'share_id' => $shareId, 'tmp' => $tmp, 'content_hash' => $caHashQ, 'params_key' => $caParamsKeyQ, 'size' => $size];
        }
        if (empty($jobs)) {
            break;
        }
        // 0018: finalize 会删除 tmp，先抓 PDF 总页数供状态表落库
        $pdfPagesQ = [];
        foreach ($jobs as $j) {
            if (strtolower(ltrim((string)($j['ext'] ?? ''), '.')) === 'pdf' && isset($j['tmp']) && is_file($j['tmp'])) {
                $pi = @shell_exec('pdfinfo ' . escapeshellarg($j['tmp']) . ' 2>/dev/null');
                if (is_string($pi) && preg_match('/Pages:\s+(\d+)/', $pi, $pm)) {
                    $pdfPagesQ[(string)$j['uri']] = (int)$pm[1];
                }
            }
        }
        $stEngineQ = (netdisk_ocr_sidecar_enabled() && netdisk_ocr_sidecar_available()) ? 'paddleocr-sidecar'
            : (trim((string)shell_exec('command -v tesseract 2>/dev/null')) !== '' ? 'tesseract' : '');
        // 0018: 批处理计时起点（elapsed_ms/pages_per_min 供 0021 自动定档）
        $qT0 = microtime(true);
        // 0018: 载入既有状态行——attempts 跨轮累计（真实失败 3 次才熔断，starved 轮不计数）
        $qUris = array_map(fn($j) => (string)$j['uri'], $jobs);
        $qPrevState = [];
        if (!empty($qUris)) {
            $qSelSt = $pdo->prepare('SELECT share_id, uri, attempts, first_at FROM netdisk_ocr_files WHERE (share_id, uri) IN (VALUES ' . implode(',', array_fill(0, count($qUris), '(?,?)')) . ')');
            $qSelParams = [];
            foreach ($jobs as $j) {
                $qSelParams[] = (int)$meta[(string)$j['uri']]['share_id'];
                $qSelParams[] = (string)$j['uri'];
            }
            $qSelSt->execute($qSelParams);
            foreach ($qSelSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qPrevState[(int)$r['share_id'] . '|' . (string)$r['uri']] = $r;
            }
            $qSelSt->closeCursor();
        }
        $res = netdisk_ocr_run_pool($jobs, $concurrency, 0, $pdo);
        // 统一后处理：规范化 + 自适应 DPI 重试 + 清理 tmp
        $finalResults = netdisk_ocr_finalize_results($jobs, $res['results']);
        foreach ($jobs as $j) {
            $uri = $j['uri'];
            $m = $meta[$uri];
            $txt = trim((string)($finalResults[$uri] ?? ''));
            netdisk_exec_retry(fn() => $updIndex->execute([':t' => $txt, ':s' => $m['share_id'], ':u' => $uri]));
            netdisk_exec_retry(fn() => $markDone->execute([':t' => time(), ':id' => $m['qid']]));
            // 0024 判定顺序③：队列 OCR 成功后写内容寻址结果表（source='nas'）+ 两张路径表 content_hash 列
            if ($txt !== '' && (string)($m['content_hash'] ?? '') !== '') {
                netdisk_ocr_result_save((string)$m['content_hash'], (string)$m['params_key'], (int)($m['size'] ?? 0), (int)($pdfPagesQ[$uri] ?? 0), $txt, $stEngineQ, 'nas', $pdo);
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_index SET content_hash=:h WHERE share_id=:s AND uri=:u')
                    ->execute([':h' => (string)$m['content_hash'], ':s' => (int)$m['share_id'], ':u' => $uri]));
            }
            // 0018: 落状态行（updated_at 队列不存，写空串——重建侧判定允许空串回退比对 netdisk_index.updated_at）
            $qQ = $txt !== '' ? netdisk_ocr_quality($txt) : 0.0;
            $qChars = $txt !== '' ? string_len($txt) : 0;
            $qPages = (int)($pdfPagesQ[$uri] ?? 0);
            $qNow = time();
            // 0018 必改: attempts = 连续失败计数——成功归零；失败跨轮累计 +1（此前恒写 1，熔断永远触发不了）；starved 轮不加计数
            $qPrev = $qPrevState[(int)$m['share_id'] . '|' . $uri] ?? null;
            $qAtt = $txt !== '' ? 0 : ((int)($qPrev['attempts'] ?? 0) + 1);
            // 0019: 失败归因（预算守卫标记 → invalid + 原因）与页级进度
            $qInv = netdisk_ocr_invalid_reason((string)($j['tmp'] ?? ''));
            // 0020: sidecar 服务端失败归因（同 build 侧口径：failed + 具体原因）
            $qFail = netdisk_ocr_fail_reason((string)($j['tmp'] ?? ''));
            $qSt = $txt !== '' ? 'done' : ($qInv !== '' ? 'invalid' : 'failed');
            $qErr = $txt !== '' ? '' : ($qInv !== '' ? $qInv : ($qFail !== '' ? $qFail : 'OCR 返回空文本（图片损坏/格式不支持/OCR 服务异常）'));
            $qPagesDone = $txt !== '' ? $qPages : (int)($GLOBALS['ND_OCR_PAGES_DONE'][(string)($j['tmp'] ?? '')] ?? 0);
            $qFirst = (int)($qPrev['first_at'] ?? 0) > 0 ? (int)$qPrev['first_at'] : $qNow;
            // 0018: 耗时指标——优先 finalize 逐文件真实耗时，缺失退回批累计（PDF 记 pages_per_min，图片记 0）
            $qElapsed = (int)($GLOBALS['ND_OCR_FINALIZE_TIMINGS'][$uri] ?? 0);
            if ($qElapsed <= 0) {
                $qElapsed = (int)round((microtime(true) - $qT0) * 1000);
            }
            $qPpm = ($qPages > 0 && $qElapsed > 0) ? round($qPages / ($qElapsed / 60000.0), 2) : 0.0;
            netdisk_exec_retry(fn() => $upOcrFileQ->execute([
                ':sid' => (int)$m['share_id'], ':uri' => $uri, ':u' => '', ':z' => 0,
                ':st' => $qSt, ':q' => $qQ, ':c' => $qChars, ':a' => $qAtt,
                ':e' => $qErr,
                ':dpi' => 0, ':pt' => $qPages, ':pd' => $qPagesDone,
                ':eng' => $stEngineQ, ':ems' => $qElapsed, ':ppm' => $qPpm, ':first' => $qFirst, ':t' => $qNow,
            ]));
            // 0024: content_hash 回填在 upsert 之后（同 build 侧，upsert 不含该列）
            if ($txt !== '' && (string)($m['content_hash'] ?? '') !== '') {
                $caRtHashQ = (string)$m['content_hash'];
                netdisk_exec_retry(fn() => $pdo->prepare('UPDATE netdisk_ocr_files SET content_hash=:h WHERE share_id=:s AND uri=:u')
                    ->execute([':h' => $caRtHashQ, ':s' => (int)$m['share_id'], ':u' => $uri]));
            }
            $totalDone++;
        }
        // 出现新的 pending 重建 job → 让位（重建优先）
        $pendingJob = (int)$pdo->query('SELECT COUNT(*) FROM netdisk_index_jobs WHERE status="pending"')->fetchColumn();
        if ($pendingJob > 0) {
            $qYielded = true;
            break;
        }
    }
    // 0018: 让位（starved）与失败区分——仍 pending 的队列项在状态表标记 starved（attempts 原样保留、不加计数），
    // 不计入 attempts>=3 的永久跳过，避免大文件因反复让位被判死。
    if ($qYielded) {
        try {
            $qNowY = time();
            $starvedSel = $pdo->query('SELECT share_id, uri, size FROM netdisk_ocr_queue WHERE status="pending"');
            $starvedRows = $starvedSel->fetchAll(PDO::FETCH_ASSOC);
            $starvedSel->closeCursor();
            $upStarved = $pdo->prepare('INSERT INTO netdisk_ocr_files (share_id, uri, updated_at, size, status, quality, chars, attempts, last_error, dpi, pages_total, pages_done, engine, elapsed_ms, pages_per_min, first_at, finished_at)
                VALUES (:sid, :uri, "", :z, "starved", 0, 0, :a, "starved", 0, 0, 0, "", 0, 0, :first, :t)
                ON CONFLICT(share_id, uri) DO UPDATE SET status=CASE WHEN netdisk_ocr_files.status IN ("done", "failed", "invalid") THEN netdisk_ocr_files.status ELSE "starved" END,
                    last_error=CASE WHEN netdisk_ocr_files.status IN ("done", "failed", "invalid") THEN netdisk_ocr_files.last_error ELSE "starved" END,
                    size=excluded.size');
            foreach ($starvedRows as $sr) {
                $srSid = (int)$sr['share_id'];
                $srUri = (string)$sr['uri'];
                $srAtt = 0;
                $srFirst = $qNowY;
                try {
                    $pv = $pdo->prepare('SELECT attempts, first_at FROM netdisk_ocr_files WHERE share_id=? AND uri=?');
                    $pv->execute([$srSid, $srUri]);
                    $pvr = $pv->fetch(PDO::FETCH_ASSOC);
                    $pv->closeCursor();
                    if ($pvr !== false) {
                        $srAtt = (int)$pvr['attempts'];
                        $srFirst = (int)$pvr['first_at'] > 0 ? (int)$pvr['first_at'] : $qNowY;
                    }
                } catch (Throwable $ign) {}
                netdisk_exec_retry(fn() => $upStarved->execute([
                    ':sid' => $srSid, ':uri' => $srUri, ':z' => (int)($sr['size'] ?? 0),
                    ':a' => $srAtt, ':first' => $srFirst, ':t' => $qNowY,
                ]));
            }
        } catch (Throwable $ign) {
            // starved 标记失败不影响队列处理结果
        }
    }
    return $totalDone;
}

// 本地索引检索：文件名 / 路径 / 正文 LIKE。返回内容同 handle_netdisk_search 的 nameHits/contentHits
function netdisk_index_search(array $share, array $cfg, string $root, string $q, string $rootLabel): array {

    $pdo = db();
    $shareId = (int)$share['id'];
    $likeVal = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $stmt = $pdo->prepare('SELECT uri, name, is_folder, ext, size, parent_path, text FROM netdisk_index
        WHERE share_id = :sid AND (name LIKE :like ESCAPE "\\" OR text LIKE :like2 ESCAPE "\\")
        ORDER BY is_folder ASC, name ASC LIMIT 80');
    $stmt->execute([':sid' => $shareId, ':like' => $likeVal, ':like2' => $likeVal]);
    $nameHits = [];
    $contentHits = [];
    $fuzzyDone = []; // uri => true，精确命中过的文件不再重复模糊判定（模糊在补充阶段统一做）
    $aliasDenied = false; // 索引来自旧的更宽根目录时，@share 结果会被当前白名单拦下
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uri = (string)$row['uri'];
        // 索引可能早于分享根目录/连接的调整，返回前必须按当前授权范围复核
        if (!netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
            if (strpos($uri, '@share') !== false) {
                $aliasDenied = true;
            }
            continue;
        }
        // 用该文件 uri 解析分享根名（@share → /测试）
        $shareLabel = netdisk_share_label($cfg['cfg'], $root, $uri);
        $isFolder = (int)$row['is_folder'] === 1;
        $name = (string)$row['name'];
        $ext = (string)$row['ext'];
        // 展示路径：去掉根前缀；@share 命中去掉 cloudreve://<uid>@share 前缀
        $rel = netdisk_display_path($root, $uri, $shareLabel);
        $baseInfo = [
            'uri' => $uri,
            'name' => $name,
            'is_folder' => $isFolder,
            'path' => $rel,
            'ext' => $ext,
            'size' => (int)($row['size'] ?? 0),
            'can_download' => !$isFolder && netdisk_download_allowed($share, $uri),
        ];
        $nameHit = mb_stripos($name, $q) !== false;
        $text = (string)$row['text'];
        $textPos = $text !== '' ? mb_stripos($text, $q) : false;
        if ($nameHit) {
            $nameHits[] = $baseInfo;
        }
        if ($textPos !== false) {
            // 收集同一文件中的所有匹配位置（最多 8 处），生成多段 snippet 便于展开浏览
            $snippets = [];
            $searchFrom = 0;
            $qLen = mb_strlen($q);
            $textLen = mb_strlen($text);
            $snippetCount = 0;
            $snippetCap = 30;
            while ($searchFrom < $textLen && $snippetCount < $snippetCap) {
                $pos = mb_stripos($text, $q, $searchFrom);
                if ($pos === false) {
                    break;
                }
                $sStart = max(0, $pos - 30);
                $sLen = min(120, $textLen - $sStart);
                $snippets[] = [
                    'offset' => $pos,
                    'text' => '…' . mb_substr($text, $sStart, $sLen) . '…',
                ];
                $searchFrom = $pos + $qLen;
                $snippetCount++;
            }
            // OCR 形近错字容错补充：同一文件还有"退刀槽"的形近误识别（如"退刀模/退刀枝"）
            // 时，把模糊命中也合入 snippets，让扫描件中的变体也能被浏览到（仍封顶 8 处）。
            if (netdisk_is_ocr_candidate($ext) && $snippetCount < $snippetCap) {
                $fuzzySnips = netdisk_ocr_fuzzy_snippets($text, $q, $snippetCap);
                foreach ($fuzzySnips as $fs) {
                    $dup = false;
                    foreach ($snippets as $ex) {
                        if (abs((int)$ex['offset'] - (int)$fs['offset']) < mb_strlen($q)) {
                            $dup = true;
                            break;
                        }
                    }
                    if (!$dup) {
                        $snippets[] = $fs;
                        if (count($snippets) >= $snippetCap) {
                            break;
                        }
                    }
                }
                usort($snippets, function ($a, $b) {
                    return (int)$a['offset'] <=> (int)$b['offset'];
                });
            }
            $contentHits[] = [
                'uri' => $uri,
                'name' => $name,
                'snippet' => $snippets[0]['text'], // 兼容字段：第一处
                'snippets' => $snippets,
                'source' => 'index',
                'path' => $rel,
                'ext' => $ext,
                'size' => (int)($row['size'] ?? 0),
                'can_download' => $baseInfo['can_download'],
                'is_folder' => $isFolder,
            ];
            $fuzzyDone[$uri] = true;
        }
    }
    // OCR 形近错字容错补充：精确 LIKE 命中不足时，扫描该分享下所有 OCR 候选文件正文，
    // 对"整词逐字符差异 ≤每3字1错"相近的词做模糊召回（针对 tesseract 将"退刀槽"识别成
    // "退刀模/退刀枝/退刀槐"等形近错字的场景）。'fuzzy'=>true 供前端区分展示来源。
    if (count($contentHits) < 20) {
        $fuzzyStmt = $pdo->prepare('SELECT uri, name, is_folder, ext, size, parent_path, text FROM netdisk_index
            WHERE share_id = :sid AND is_folder=0 AND text <> "" AND ext IN ("pdf","png","jpg","jpeg","webp","bmp")');
        $fuzzyStmt->execute([':sid' => $shareId]);
        foreach ($fuzzyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uri = (string)$row['uri'];
            if (isset($fuzzyDone[$uri])) {
                continue;
            }
            if (!netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
                if (strpos($uri, '@share') !== false) {
                    $aliasDenied = true;
                }
                continue;
            }
            $name = (string)$row['name'];
            $text = (string)$row['text'];
            $fuzzySnips = netdisk_ocr_fuzzy_snippets($text, $q, 30);
            if (!empty($fuzzySnips)) {
                $ext = (string)$row['ext'];
                $rel = netdisk_display_path($root, $uri, netdisk_share_label($cfg['cfg'], $root, $uri));
                $contentHits[] = [
                    'uri' => $uri,
                    'name' => $name,
                    'snippet' => $fuzzySnips[0]['text'],
                    'snippets' => $fuzzySnips,
                    'source' => 'index',
                    'fuzzy' => true,
                    'path' => $rel,
                    'ext' => $ext,
                    'size' => (int)($row['size'] ?? 0),
                    'can_download' => netdisk_download_allowed($share, $uri),
                    'is_folder' => 0,
                ];
                $fuzzyDone[$uri] = true;
                if (count($contentHits) >= 40) {
                    break;
                }
            }
        }
    }
    $contentHits = array_slice($contentHits, 0, 40);
    // 仅返回精确 + OCR 容错结果
    return [
        'nameHits' => array_slice($nameHits, 0, 40),
        'contentHits' => $contentHits,
        'aliasDenied' => $aliasDenied,
    ];
}

function cloudreve_get_thumb_url(array $cfg, string $uri): ?string {
    $resp = cloudreve_api($cfg, 'GET', '/file/thumb?uri=' . rawurlencode($uri));
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return null;
    }
    return (string)($resp['data']['url'] ?? '');
}

function cloudreve_get_file_urls(array $cfg, array $uris): ?array {
    $resp = cloudreve_api($cfg, 'POST', '/file/url', ['uris' => array_values($uris), 'download' => false]);
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return null;
    }
    return $resp['data']['urls'] ?? null;
}

function cloudreve_discover_wopi_viewer(array $cfg, string $ext): ?string {
    $c = cloudreve_conn($cfg);
    if ($c['viewer_id'] !== '') {
        return $c['viewer_id'];
    }
    $ext = strtolower(ltrim($ext, '.'));
    $resp = cloudreve_api($cfg, 'GET', '/site/config/explorer');
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return null;
    }
    $groups = $resp['data']['file_viewers'] ?? [];
    if (!is_array($groups)) {
        return null;
    }
    foreach ($groups as $group) {
        foreach (($group['viewers'] ?? []) as $viewer) {
            if (($viewer['type'] ?? '') !== 'wopi' || !empty($viewer['disabled'])) {
                continue;
            }
            $viewerExts = $viewer['exts'] ?? [];
            if (is_array($viewerExts) && in_array($ext, array_map('strtolower', $viewerExts), true)) {
                return (string)($viewer['id'] ?? '');
            }
        }
    }
    return null;
}

function cloudreve_create_viewer_session(array $cfg, string $uri, string $viewerId, string $action): ?array {
    $c = cloudreve_conn($cfg);
    $token = cloudreve_access_token($cfg);
    if ($token === '' || $c['base_url'] === '') {
        return null;
    }
    $url = $c['base_url'] . '/api/v4/file/viewerSession';
    $payload = json_encode([
        'uri' => $uri,
        'viewer_id' => $viewerId,
        'preferred_action' => $action,
    ], JSON_UNESCAPED_UNICODE);
    $headers = [
        'User-Agent: SiyuanShareNetdisk',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return [
            'error' => 'HTTP ' . (string)($http_response_header[0] ?? 'error') . ' from Cloudreve (WOPI 后端未就绪或服务端错误)',
        ];
    }
    $resp = json_decode($raw, true);
    if (!is_array($resp) || (int)($resp['code'] ?? 1) !== 0) {
        return [
            'error' => is_array($resp) ? (string)($resp['msg'] ?? '') : (string)$raw,
        ];
    }
    return $resp['data'] ?? null;
}

function netdisk_file_ext(string $name): string {
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : '';
}

function netdisk_is_office(string $name): bool {
    return in_array(netdisk_file_ext($name), [
        'doc', 'docx', 'ppt', 'pptx', 'odt', 'odp', 'rtf',
    ], true);
}

// PDF / Excel / 纯文本 走”内容代理“在浏览器本地预览，不依赖 Cloudreve WOPI
function netdisk_is_pdf(string $name): bool {
    return in_array(netdisk_file_ext($name), ['pdf'], true);
}

function netdisk_is_excel(string $name): bool {
    return in_array(netdisk_file_ext($name), ['xls', 'xlsx', 'ods', 'csv', 'tsv'], true);
}

function netdisk_is_text(string $name): bool {
    return in_array(netdisk_file_ext($name), ['txt', 'md', 'log', 'ini', 'conf', 'srt', 'json', 'xml', 'yaml', 'yml'], true);
}

function netdisk_is_image(string $name): bool {
    return in_array(netdisk_file_ext($name), [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'avif',
    ], true);
}

function netdisk_is_media(string $name): bool {
    return in_array(netdisk_file_ext($name), [
        'mp4', 'webm', 'ogg', 'ogv', 'mov', 'mkv', 'avi', 'mp3', 'wav', 'flac', 'm4a', 'aac', 'opus',
    ], true);
}

// 共享重定向（@share）白名单：只有从已授权目录列表中实际发现的链接才可访问。
// 记录值：d=目录链接（允许其子路径），f=文件链接（仅精确匹配）。
function netdisk_redirect_alias_key(int $connId, string $root, string $uri): string {
    return 'nd_alias_' . $connId . '_' . md5($root) . '_' . md5($uri);
}

function netdisk_register_redirect_alias(array $conn, string $root, string $uri, bool $isFolder, int $ttl = 604800): void {
    if ($uri === '') {
        return;
    }
    netdisk_cache_set(
        netdisk_redirect_alias_key((int)($conn['id'] ?? 0), netdisk_normalize_root($root), $uri),
        $isFolder ? 'd' : 'f',
        $ttl
    );
}

function netdisk_register_redirect_aliases(array $conn, string $root, ?array $data, int $ttl = 604800): void {
    if (!is_array($data)) {
        return;
    }
    foreach (($data['files'] ?? []) as $f) {
        $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
        if ($redirect !== '') {
            netdisk_register_redirect_alias($conn, $root, $redirect, (int)($f['type'] ?? 0) === 1, $ttl);
        }
    }
}

function netdisk_redirect_alias_kind(int $connId, string $root, string $uri): string {
    $kind = netdisk_cache_get(netdisk_redirect_alias_key($connId, $root, $uri));
    return is_string($kind) ? $kind : '';
}

// 判断文件 URI 是否允许访问：
//  1. 在授权根目录内（含根目录自身）
//  2. 或命中从授权目录中发现的 @share 共享链接（含目录链接的子路径）
function netdisk_uri_within_root(string $root, string $uri, ?array $conn = null): bool {
    $root = netdisk_normalize_root($root);
    if ($root === '' || $uri === '') {
        return false;
    }
    if (preg_match('#(?:^|/)\.\.(?:/|$)#', $uri) === 1 || str_contains($uri, '\\')) {
        return false;
    }
    if ($uri === $root || str_starts_with($uri, rtrim($root, '/') . '/')) {
        return true;
    }
    if ($conn === null) {
        return false;
    }
    $connId = (int)($conn['id'] ?? 0);
    if (netdisk_redirect_alias_kind($connId, $root, $uri) !== '') {
        return true;
    }
    $pos = strpos($uri, '@share');
    if ($pos === false) {
        return false;
    }
    $prefix = substr($uri, 0, $pos + strlen('@share'));
    $tail = substr($uri, $pos + strlen('@share'));
    if ($tail === '' || $tail[0] !== '/') {
        return false;
    }
    $acc = $prefix;
    foreach (array_filter(explode('/', $tail), 'strlen') as $seg) {
        $acc .= '/' . $seg;
        if (netdisk_redirect_alias_kind($connId, $root, $acc) === 'd') {
            return true;
        }
    }
    return false;
}

// 生成用于前端展示的相对路径：
//  - 根内 URI：去掉 $root 前缀
//  - @share 命中：去掉 cloudreve://<uid>@share 前缀，仅保留共享目录内部的相对路径
//  - 返回空字符串表示就是根自身（前端据此不显示路径行）
function netdisk_display_path(string $root, string $uri, string $shareLabel = ''): string {

    $rel = '';

    // 优先按 @share 前缀解析：返回 @share 之后的完整路径（含 root 目录名，如 /测试/文件.pdf）。
    if (preg_match('#^cloudreve://[^/@]+@share(?:/|$)#i', $uri, $m)) {

        $pos = strpos($uri, '@share');

        if ($pos !== false) {

            $rel = '/' . ($shareLabel !== '' ? $shareLabel . '/' : '') . ltrim(substr($uri, $pos + strlen('@share')), '/');

        }

    } else {

        $rootN = rtrim(netdisk_normalize_root($root), '/');

        if ($rootN !== '' && str_starts_with($uri, $rootN . '/')) {

            $rel = '/' . substr($uri, strlen($rootN) + 1);

        } elseif ($uri !== $rootN) {

            $rel = $uri;

        }

    }

    if ($rel === '') {

        return '';

    }

    // Cloudreve 返回的 path 是 URL 编码态，解码为可读路径（如 2-采购&外协&试验/…）

    $decoded = rawurldecode($rel);

    return $decoded !== '' ? $decoded : $rel;

}

function netdisk_gate_share(string $slug): ?array {
    $share = find_share_by_slug($slug);
    if (!$share) {
        return null;
    }
    if (share_is_expired($share)) {
        return null;
    }
    if (share_visitor_limit_reached($share)) {
        return null;
    }
    $cfg = netdisk_share_config($share);
    if (!$cfg['enabled']) {
        return null;
    }
    if (!$cfg['cfg']) {
        return null;
    }
    if ((int)($cfg['cfg']['enabled'] ?? 0) !== 1) {
        return null;
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        return null;
    }
    return [$share, $cfg];
}

function netdisk_download_rules(int $shareId): array {
    $stmt = db()->prepare('SELECT id, uri, is_folder FROM share_download_rules WHERE share_id = :sid ORDER BY id ASC');
    $stmt->execute([':sid' => $shareId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 判断某文件 URI 是否命中该分享的下载规则（文件精确匹配 / 文件夹前缀匹配，含其下所有文件）
function netdisk_download_allowed(array $share, string $uri): bool {
    $uri = (string)$uri;
    $rules = netdisk_download_rules((int)$share['id']);
    foreach ($rules as $rule) {
        $ruleUri = (string)($rule['uri'] ?? '');
        if ($ruleUri === '') {
            continue;
        }
        if ((int)($rule['is_folder'] ?? 0) === 1) {
            if ($uri === $ruleUri || strpos($uri, rtrim($ruleUri, '/') . '/') === 0) {
                return true;
            }
        } elseif ($uri === $ruleUri) {
            return true;
        }
    }
    return false;
}

function handle_netdisk_list(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        api_response(403, null, '无权访问网盘');
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = trim((string)($_GET['uri'] ?? ''));
    if ($uri === '') {
        $uri = $root;
    }
    if (!netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        api_response(400, null, '目录不在授权范围内');
    }
    $data = cloudreve_list_folder($cfg['cfg'], $uri);
    if ($data === null) {
        api_response(502, null, 'Cloudreve 连接失败');
    }
    netdisk_register_redirect_aliases($cfg['cfg'], $root, $data);
    $files = [];
    foreach (($data['files'] ?? []) as $f) {
        $isFolder = (int)($f['type'] ?? 0) === 1;
        $name = (string)($f['name'] ?? '');
        // 跟随符号链接（Cloudreve 共享目录/文件）：若列表项带 sys:shared_redirect，
        // 则导航/打开目标应指向该重定向 URI。
        $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
        $navigateUri = $redirect !== '' ? $redirect : (string)($f['path'] ?? '');
        $files[] = [
            'name' => $name,
            'is_folder' => $isFolder,
            'size' => (int)($f['size'] ?? 0),
            'uri' => $navigateUri,
            'ext' => $isFolder ? '' : netdisk_file_ext($name),
            'can_download' => !$isFolder && netdisk_download_allowed($share, $navigateUri),
        ];
    }
    api_response(200, [
        'root' => $root,
        'parent' => (string)($data['parent']['path'] ?? ''),
        'files' => $files,
        'permission' => $cfg['permission'],
    ]);
}

// 网盘文件搜索：文件名递归匹配 + 可选 Cloudreve 全文检索(FTS)内容命中
// 返回 ['nameHits'=>[['uri','name','is_folder','path','ext','size','can_download']],
//        'contentHits'=>[['uri','name','snippet']], 'diag'=>(string|null)]
function handle_netdisk_search(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        api_response(403, null, '无权访问网盘');
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1) {
        api_response(200, ['nameHits' => [], 'contentHits' => [], 'diag' => null]);
    }
    $nameHits = [];
    $contentHits = [];
    $diag = null;

    // 0) 结果缓存：同一分享 + 关键词 60s 内直接返回，超快二次检索
    $shareId = (int)$share['id'];
    $cacheKey = 'nd_search_' . $shareId . '_' . md5($q . '|' . $root);
    $cached = netdisk_cache_get($cacheKey);
    if ($cached !== null && is_array(json_decode($cached, true))) {
        $hit = json_decode($cached, true);
        api_response(200, ['nameHits' => $hit['nameHits'] ?? [], 'contentHits' => $hit['contentHits'] ?? [], 'diag' => ($hit['diag'] ?? null)]);
    }

    // 1) 本地全文索引优先（毫秒级 SQL）——若存在且“不太旧”直接检索
    $pdo = db();
    $idxRow = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(MAX(indexed_at),0) AS latest FROM netdisk_index WHERE share_id=:sid');
    $idxRow->execute([':sid' => $shareId]);
    $idxStat = $idxRow->fetch(PDO::FETCH_ASSOC);
    $idxCount = (int)($idxStat['c'] ?? 0);
    $idxLatest = (int)($idxStat['latest'] ?? 0);
    $idxRefresh = $idxLatest > 0 && $idxLatest < time() - netdisk_index_refresh_ttl((int)($cfg['config_id'] ?? 0)); // 超过 TTL 视为待刷新
    $allowOcr = netdisk_index_ocr_enabled((int)($cfg['config_id'] ?? 0));
    $jobId = 0;

    // 1) 本地索引长期有效：只要存在就优先直接检索（毫秒级），不因“过期”而走慢速在线兜底。
    if ($idxCount > 0) {
        $idxRes = netdisk_index_search($share, $cfg, $root, $q, netdisk_share_label($cfg['cfg'], $root, $root));
        $nameHits = $idxRes['nameHits'];
        $contentHits = $idxRes['contentHits'];
        // 索引偏旧（超 TTL）：本次仍返回索引结果，后台排队一次增量刷新（非阻塞）。
        if ($idxRefresh) {
            $jobId = netdisk_index_job_create($shareId);
            $diag = '本地索引命中（' . $idxCount . ' 个文件/目录）；索引已超刷新周期，后台正在增量刷新。';
        } else {
            $diag = '本地索引命中（' . $idxCount . ' 个文件/目录）';
        }
        if (!empty($idxRes['aliasDenied'])) {
            $diag .= '；部分共享目录结果因当前分享根目录授权范围已变化而隐藏，索引刷新后恢复';
        }
        error_log('[netdisk_search] q=' . $q . ' (index) nameHits=' . count($nameHits) . ' contentHits=' . count($contentHits) . ' diag=' . $diag);
        netdisk_cache_set($cacheKey, json_encode(['nameHits' => $nameHits, 'contentHits' => $contentHits, 'diag' => $diag], JSON_UNESCAPED_UNICODE), 60);
        api_response(200, ['nameHits' => $nameHits, 'contentHits' => $contentHits, 'diag' => $diag]);
    }

    // 2) 索引缺失（从未建立）→ 异步排队重建，当前请求退化为在线遍历兜底。
    if ($idxCount === 0) {
        $jobId = netdisk_index_job_create($shareId);
        if ($jobId > 0) {
            $diag = '本地索引正在后台初次建立，当前结果来自在线检索，稍后重试将命中本地索引。';
        }
    }

    // 3) 在线遍历兜底（仅无索引时走到这里）
    $visited = 0;
    $sawShared = false;
    $textBudget = 20;
    $textCandidates = [];
    $contCfg = $cfg['cfg'];

    // …沿用在线遍历（文件名 + @share + 文本兜底）作为兜底，逻辑不变…
    $collectNameHits = function (array $data) use (&$nameHits, &$textCandidates, $q, $share, $textBudget, $root) {
        foreach (($data['files'] ?? []) as $f) {
            if (count($nameHits) >= 40) {
                break;
            }
            $isFolder = (int)($f['type'] ?? 0) === 1;
            $name = (string)($f['name'] ?? '');
            $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
            $navigateUri = $redirect !== '' ? $redirect : (string)($f['path'] ?? '');
            if (mb_stripos($name, $q) !== false) {
                $nameHits[] = [
                    'uri' => $navigateUri,
                    'name' => $name,
                    'is_folder' => $isFolder,
                    'path' => netdisk_display_path($root, $navigateUri),
                    'ext' => $isFolder ? '' : netdisk_file_ext($name),
                    'size' => (int)($f['size'] ?? 0),
                    'can_download' => !$isFolder && netdisk_download_allowed($share, $navigateUri),
                ];
            }
            if (
                !$isFolder
                && count($textCandidates) < $textBudget
                && !isset($textCandidates[$navigateUri])
                && netdisk_is_local_text_ext($name)
                && (int)($f['size'] ?? 0) <= 1048576
            ) {
                $textCandidates[$navigateUri] = $name;
            }
        }
    };

    $currentLevel = [$root];
    $seenDirs = [$root => true];
    for ($depth = 0; $depth <= 5; $depth++) {
        if (empty($currentLevel) || $visited >= 120 || count($nameHits) >= 40) {
            break;
        }
        $batch = [];
        foreach ($currentLevel as $dirUri) {
            if ($visited >= 120) {
                break;
            }
            $visited++;
            $batch[] = $dirUri;
        }
        $batchData = cloudreve_list_folder_multi($cfg, $batch, 60);
        $nextLevel = [];
        foreach ($batch as $dirUri) {
            $data = $batchData[$dirUri] ?? null;
            if (!is_array($data)) {
                continue;
            }
            netdisk_register_redirect_aliases($contCfg, $root, $data);
            $collectNameHits($data);
            foreach (($data['files'] ?? []) as $f) {
                if ((int)($f['type'] ?? 0) === 1) {
                    $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
                    if ($redirect !== '') {
                        $sawShared = true;
                    }
                    $childUri = $redirect !== '' ? $redirect : (string)($f['path'] ?? '');
                    if ($childUri !== '' && netdisk_uri_within_root($root, $childUri, $contCfg) && !isset($seenDirs[$childUri])) {
                        $seenDirs[$childUri] = true;
                        $nextLevel[] = $childUri;
                    }
                }
            }
        }
        $currentLevel = $nextLevel;
    }
    $fts = cloudreve_api($cfg['cfg'], 'GET', '/file/search?query=' . rawurlencode($q));
    if (is_array($fts) && (int)($fts['code'] ?? 1) === 0 && is_array($fts['data']['hits'] ?? null)) {
        $hits = $fts['data']['hits'];
        $diag = 'FTS OK，命中 ' . count($hits) . ' 条';
        $dropped = 0;
        foreach ($hits as $hit) {
            if (count($contentHits) >= 40) {
                break;
            }
            $file = $hit['file'] ?? null;
            if (!is_array($file)) {
                continue;
            }
            $uri = (string)($file['path'] ?? '');
            if ($uri === '') {
                continue;
            }
            if (!netdisk_uri_within_root($root, $uri, $contCfg)) {
                $dropped++;
                continue;
            }
            $contentHits[] = [
                'uri' => $uri,
                'name' => (string)($file['name'] ?? ''),
                'snippet' => (string)($hit['content'] ?? ''),
                'source' => 'fts',
                'path' => netdisk_display_path($root, $uri, netdisk_share_label($contCfg, $root, $uri)),
                'ext' => netdisk_file_ext((string)($file['name'] ?? '')),
                'size' => (int)($file['size'] ?? 0),
                'can_download' => netdisk_download_allowed($share, $uri),
                'is_folder' => false,
            ];
        }
        if ($dropped > 0) {
            $diag .= '，' . $dropped . ' 条因不在分享根目录内被过滤';
        }
        if (empty($contentHits)) {
            $diag .= '；Cloudreve 全文检索返回 0 条（索引可能为空/未配置 Tika 文本抽取/文件归属非服务账号）';
            if ($sawShared) {
                $diag .= '；遍历发现共享目录（@share），其中的文件属于其他账号，Cloudreve 全文索引按账号隔离无法覆盖';
            }
            $diag .= '。已尝试本地文本匹配。';
        }
    } elseif (is_array($fts)) {
        $diag = 'FTS 返回 code=' . (int)($fts['code'] ?? -1) . ' msg=' . (string)($fts['msg'] ?? '') . '。已尝试本地文本匹配。';
    }

    if (!empty($textCandidates) && count($contentHits) < 40) {
        $toFetch = [];
        if (empty($contentHits)) {
            $toFetch = array_keys($textCandidates);
        } else {
            foreach ($textCandidates as $uri => $nm) {
                foreach ($nameHits as $nh) {
                    if (isset($nh['uri']) && $nh['uri'] === $uri) {
                        $toFetch[] = $uri;
                        break;
                    }
                }
            }
        }
        if (!empty($toFetch)) {
            $bodies = netdisk_fetch_text_content_multi($contCfg, $toFetch);
            foreach ($toFetch as $candUri) {
                if (count($contentHits) >= 40) {
                    break;
                }
                $body = $bodies[$candUri] ?? null;
                if ($body === null || mb_stripos($body, $q) === false) {
                    continue;
                }
                $pos = mb_stripos($body, $q);
                $start = max(0, $pos - 30);
                $len = min(120, mb_strlen($body) - $start);
                $contentHits[] = [
                    'uri' => $candUri,
                    'name' => $textCandidates[$candUri],
                    'snippet' => '…' . mb_substr($body, $start, $len) . '…',
                    'source' => 'text',
                    'path' => netdisk_display_path($root, $candUri),
                    'ext' => netdisk_file_ext($textCandidates[$candUri]),
                    'size' => 0,
                    'can_download' => netdisk_download_allowed($share, $candUri),
                    'is_folder' => false,
                ];
            }
        }
    }

    // 去重 contentHits
    $seenUris = [];
    $dedupContent = [];
    foreach (array_reverse($contentHits) as $ch) {
        if (!isset($seenUris[$ch['uri']])) {
            $seenUris[$ch['uri']] = true;
            $dedupContent[] = $ch;
        }
    }
    $contentHits = array_reverse($dedupContent);

    error_log('[netdisk_search] q=' . $q . ' (fallback) nameHits=' . count($nameHits) . ' contentHits=' . count($contentHits) . ' diag=' . $diag);
    netdisk_cache_set($cacheKey, json_encode(['nameHits' => $nameHits, 'contentHits' => $contentHits, 'diag' => $diag], JSON_UNESCAPED_UNICODE), 60);
    api_response(200, ['nameHits' => $nameHits, 'contentHits' => $contentHits, 'diag' => $diag]);
}

// WOPI 回调基础地址：office 服务器会回调该地址拉取文件，必须能被 office 服务器公网访问。
// 优先使用站点设置的「网站地址」（site_base_url），否则回退到请求主机。
function netdisk_wopi_base_url(): string {
    $custom = trim((string)get_setting('site_base_url', ''));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return rtrim(base_url(), '/');
}

// 判断地址主机是否为仅本机/内网可见（office 服务器无法回调）
function netdisk_wopi_host_reachable_by_office(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return true;
}

// 尝试通过本地 WOPI 宿主打开 office 文档（只读预览）；
// 返回 null 表示未配置 office 引擎；返回 ['type'=>'unsupported','message'=>...] 表示配置/可达性诊断错误。
function netdisk_try_open_office(array $share, array $cfg, string $slug, string $uri, string $name): ?array {
    $conn = $cfg['cfg'] ?? null;
    if (!$conn || !netdisk_office_configured()) {
        return null;
    }
    $ext = netdisk_file_ext($name);
    $engine = netdisk_office_engine($ext);
    if ($engine === null) {
        return null;
    }
    // 引擎回退：所选引擎无可用应用（被停用/未配置）时，尝试另一引擎
    if (netdisk_office_base_url($engine) === '') {
        $alt = $engine === 'collabora' ? 'onlyoffice' : 'collabora';
        if (netdisk_office_has_view($alt, $ext)) {
            $engine = $alt;
        } else {
            return [
                'type' => 'unsupported',
                'message' => '预览失败：所选预览引擎（' . ($engine === 'collabora' ? 'Collabora' : 'OnlyOffice') . '）未配置可用服务，且另一引擎不支持该文件类型',
            ];
        }
    }
    $wopiBase = netdisk_wopi_base_url();
    if (!netdisk_wopi_host_reachable_by_office($wopiBase)) {
        return [
            'type' => 'unsupported',
            'message' => '预览失败：本站网站地址（' . $wopiBase . '）为本地/内网地址，office 服务器无法回调拉取文件。请在「站点设置 → 网站地址」配置公网可访问的域名（如 https://siyuan-share-test.projuser.xyz）。',
        ];
    }
    $fileId = substr(md5($slug . ':' . $uri), 0, 20);
    $token = netdisk_wopi_sign_token((int)$share['id'], $uri, (int)$conn['id'], 7200);
    // WOPISrc 必须是 office 服务器可回调的绝对 URL
    $wopiUrl = $wopiBase . '/s/' . rawurlencode($slug) . '/netdisk/wopi/' . $fileId;
    $viewerUrl = netdisk_office_viewer_url($engine, $ext, $wopiUrl, $token);
    if ($viewerUrl === '') {
        return ['type' => 'unsupported', 'message' => '预览失败：无法为该引擎构建预览地址'];
    }
    return ['type' => 'office', 'url' => $viewerUrl, 'engine' => $engine];
}
function handle_netdisk_open(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        api_response(403, null, '无权访问网盘');
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = (string)($_GET['uri'] ?? '');
    if ($uri === '' || !netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        api_response(400, null, '文件不在授权范围内');
    }
    $name = rawurldecode((string)($_GET['name'] ?? ''));
    $base = base_path();
    $contentUrl = $base . '/s/' . rawurlencode($slug) . '/netdisk/content?uri=' . rawurlencode($uri) . '&name=' . rawurlencode($name);
    $thumbUrl = $base . '/s/' . rawurlencode($slug) . '/netdisk/thumb?uri=' . rawurlencode($uri) . '&name=' . rawurlencode($name);

    if (netdisk_is_image($name)) {
        api_response(200, ['type' => 'image', 'src' => $contentUrl, 'thumb' => $thumbUrl]);
    }
    if (netdisk_is_media($name)) {
        api_response(200, ['type' => 'media', 'src' => $contentUrl]);
    }
// PDF：优先 office 引擎（本地 WOPI 宿主只读预览）；未配置 office 时退回浏览器原生 iframe
    if (netdisk_is_pdf($name)) {
        $office = netdisk_try_open_office($share, $cfg, $slug, $uri, $name);
        if ($office) {
            api_response(200, $office);
        }
        api_response(200, ['type' => 'pdf', 'src' => $contentUrl]);
    }
    // Excel / CSV：优先 office 引擎；否则前端 SheetJS 只读表格
    if (netdisk_is_excel($name)) {
        $office = netdisk_try_open_office($share, $cfg, $slug, $uri, $name);
        if ($office) {
            api_response(200, $office);
        }
        api_response(200, ['type' => 'excel', 'src' => $contentUrl, 'ext' => netdisk_file_ext($name)]);
    }
    // 纯文本：直接内联展示
    if (netdisk_is_text($name)) {
        api_response(200, ['type' => 'text', 'src' => $contentUrl]);
    }
    // 其它 office 文档：通过本地 WOPI 宿主（Collabora / OnlyOffice）只读预览
    if (netdisk_is_office($name)) {
        $office = netdisk_try_open_office($share, $cfg, $slug, $uri, $name);
        if ($office) {
            api_response(200, $office);
        }
        api_response(200, ['type' => 'unsupported', 'message' => '未配置 office 预览引擎（请在“网盘配置 → 文件预览”里添加 OnlyOffice / Collabora 应用）']);
    }
    api_response(200, ['type' => 'unsupported', 'message' => '该文件类型不支持在线预览']);
}

// 网盘文件全屏预览页（新标签页打开）：复用 /netdisk/open 返回结果，整页渲染
function handle_netdisk_preview_page(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = (string)($_GET['uri'] ?? '');
    if ($uri === '' || !netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }
    $name = rawurldecode((string)($_GET['name'] ?? basename($uri)));
    $base = base_path();
    $openUrl = $base . '/s/' . rawurlencode($slug) . '/netdisk/open?uri=' . rawurlencode($uri) . '&name=' . rawurlencode($name);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($name) . ' - 预览</title>';
    echo '<style>html,body{margin:0;padding:0;height:100%;background:#fff}';
    echo '.pv-body{position:fixed;inset:0}.pv-err{display:flex;align-items:center;justify-content:center;height:100%;color:#c00;font-size:14px;padding:24px;text-align:center}';
    echo 'iframe.pv-frame{width:100%;height:100%;border:none;display:block}';
    echo '.pv-media{width:100%;height:100%;object-fit:contain;display:block}';
    echo 'video.pv-media{background:#000}textarea.pv-text{width:100%;height:100%;border:none;padding:16px;box-sizing:border-box;font-size:13px;resize:none}';
    echo '</style></head><body>';
    echo '<script src="' . htmlspecialchars($base . '/assets/vendor/xlsx.full.min.js') . '"></script>';
    echo '<div class="pv-body" data-pv-body><div class="pv-err">加载中…</div></div>';
    echo '<script>fetch(' . json_encode($openUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',{credentials:"same-origin"})';
    echo '.then(function(r){return r.json()}).then(function(p){';
    echo 'var body=document.querySelector("[data-pv-body]");';
    echo 'if(!p||p.code!==0||!p.data){body.innerHTML=\'<div class="pv-err">无法打开文件</div>\';return;}';
    echo 'var d=p.data;';
    echo 'if(d.type==="office"){body.innerHTML=\'<iframe class="pv-frame" src="\'+encodeURI(d.url)+\'" allowfullscreen></iframe>\';}';
    echo 'else if(d.type==="image"){body.innerHTML=\'<img class="pv-media" src="\'+d.src+\'">\';}';
    echo 'else if(d.type==="media"){body.innerHTML=\'<video class="pv-media" controls autoplay src="\'+d.src+\'"></video>\';}';
    echo 'else if(d.type==="pdf"){body.innerHTML=\'<iframe class="pv-frame" src="\'+d.src+\'"></iframe>\';}';
    echo 'else if(d.type==="text"){fetch(d.src).then(function(r){return r.text()}).then(function(t){body.innerHTML=\'<textarea class="pv-text" readonly>\'+t.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;")+\'</textarea>\';});}';
    echo 'else if(d.type==="excel"){body.innerHTML=\'<div class="pv-sheet" style="overflow:auto;height:100%"></div>\';fetch(d.src).then(function(r){return r.arrayBuffer()}).then(function(b){';
    echo 'var wb=XLSX.read(b,{type:"array"});var ws=wb.Sheets[wb.SheetNames[0]];';
    echo 'var html=XLSX.utils.sheet_to_html(ws,{id:"pv-sheet-tbl",editable:false});';
    echo 'body.querySelector(".pv-sheet").innerHTML=html;}).catch(function(){body.innerHTML=\'<div class="pv-err">表格解析失败</div>\';});}';
    echo 'else{body.innerHTML=\'<div class="pv-err">\'+(d.message||"该文件类型不支持在线预览")+\'</div>\';}';
    echo '}).catch(function(){document.querySelector("[data-pv-body]").innerHTML=\'<div class="pv-err">加载出错</div>\';});';
    echo '</script></body></html>';
    exit;
}

// 从 URL 剥掉 query/fragment，只保留 scheme://host:port/path —— 报错透传时防止泄露签名 token
function netdisk_url_sanitized(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) { return $url; }
    $u = strtolower((string)($parts['scheme'] ?? 'http')) . '://'
       . (string)$parts['host']
       . (isset($parts['port']) ? ':' . $parts['port'] : '')
       . (string)($parts['path'] ?? '');
    return $u;
}

// 服务端代理拉取网盘内容（文件字节/缩略图）统一收口（curl 实现）：
// - 显式跟随重定向（≤5 次），协议白名单仅 http/https（CURLOPT_PROTOCOLS/REDIR_PROTOCOLS），防重定向落点穿越 file:// 等；
// - 用 header 回调收集重定向链，失败时每一跳都过 netdisk_url_sanitized()（剥 query，防签名 token 泄露）拼进错误 msg；
// - 失败跳主机 ≠ 入参主机时，追加「请检查 Cloudreve 站点 URL / 存储节点直链配置」提示；
// - 复用 netdisk_office_http_classify 五分类归一化；https 失败按第 5 提交同口径仅关证书校验降级重试一次（不改协议）；
// - 「自签证书降级重试仍失败」后缀仅在最终失败确属 TLS 类时拼接（修掉此前无条件拼接的误导）。
// 注意：CURLOPT_TIMEOUT 为整次请求（含全部重定向跳）的总时长上限，与旧 PHP 流实现的逐跳 timeout 语义不同。
function netdisk_proxy_fetch_curl_once(string $url, int $timeout, bool $insecure): array {
    $locations = [];
    $statusLine = '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$locations, &$statusLine) {
            if (stripos($header, 'location:') === 0) {
                $loc = trim(substr($header, 9));
                if ($loc !== '') { $locations[] = $loc; }
            } elseif (preg_match('#^HTTP/\S+\s+[1-5]\d\d#', $header)) {
                $statusLine = trim($header); // 多跳时保留最后一个状态行
            }
            return strlen($header);
        },
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $errmsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    // 证书类失败（可通过关校验绕过）与「端口不是 HTTPS」类握手失败（关校验也绕不过）分开：
    // 前者才配「自签证书降级重试仍失败」后缀，后者应把真实原因（协议不匹配）报出去。
    $certFail = (in_array($errno, [51, 58, 59, 60, 77], true)
        || stripos($errmsg, 'certificate') !== false || stripos($errmsg, 'self-signed') !== false);
    if ($raw === false || $errno !== 0) {
        // 失败跳：最后一个重定向落点；没有发生重定向时即入参 URL 本身
        $failTarget = $locations !== [] ? end($locations) : $url;
        $chain = implode(' → ', array_map('netdisk_url_sanitized', array_merge([$url], $locations)));
        // curl 错误文案归一化成 netdisk_office_http_classify 认识的关键词（与 cloudreve_http_request 同口径）
        $norm = '';
        if ($errno === 6) { $norm = 'Could not resolve host: ' . $errmsg; }
        elseif ($errno === 7) { $norm = 'Connection refused: ' . $errmsg; }
        elseif ($errno === 28) { $norm = 'Connection timed out: ' . $errmsg; }
        elseif ($errno === 60 || $errno === 51 || $errno === 58 || $errno === 59 || $errno === 53 || $errno === 77) { $norm = 'SSL certificate problem: ' . $errmsg; }
        elseif ($errno === 35) { $norm = 'SSL connect error: ' . $errmsg; }
        else { $norm = $errmsg !== '' ? $errmsg : ('curl 错误号 ' . $errno); }
        $error = netdisk_office_http_classify($norm) . '（目标：' . $chain . '）';
        $failHost = strtolower((string)(parse_url($failTarget, PHP_URL_HOST) ?: ''));
        $baseHost = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($failHost !== '' && $baseHost !== '' && $failHost !== $baseHost) {
            $error .= '；失败跳主机与配置地址不一致，请检查 Cloudreve 站点 URL / 存储节点直链配置';
        }
        return ['ok' => false, 'raw' => '', 'error' => $error, 'ssl_fail' => $certFail];
    }
    if ($status >= 400) {
        $statusText = $statusLine !== '' ? $statusLine : ('HTTP ' . $status);
        $chain = implode(' → ', array_map('netdisk_url_sanitized', array_merge([$url], $locations)));
        return ['ok' => false, 'raw' => (string)$raw,
                'error' => 'HTTP 状态码异常：' . $statusText . '（目标：' . $chain . '）', 'ssl_fail' => false];
    }
    return ['ok' => true, 'raw' => (string)$raw, 'error' => '', 'ssl_fail' => false];
}

function netdisk_proxy_fetch(string $url, int $timeout): array {
    $first = netdisk_proxy_fetch_curl_once($url, $timeout, false);
    if ($first['ok']) { return $first; }
    if (strpos($url, 'https://') !== 0) {
        return ['ok' => false, 'raw' => $first['raw'], 'error' => $first['error'], 'ssl_fail' => false];
    }
    // https 失败：绕过证书校验降级重试一次（局域网自签证书常见场景，只关校验不改协议）
    $retry = netdisk_proxy_fetch_curl_once($url, $timeout, true);
    if ($retry['ok']) { return $retry; }
    // 两次都失败：绕过证书后仍失败且原因不是证书 => 真实原因是 DNS/拒连/超时/状态码；否则回退首次的证书错误
    $final = (!$retry['ssl_fail'] && $retry['error'] !== '') ? $retry : $first;
    $suffix = $final['ssl_fail'] ? '；自签证书降级重试仍失败' : '';
    return ['ok' => false, 'raw' => $final['raw'], 'error' => $final['error'] . $suffix, 'ssl_fail' => false];
}

function handle_netdisk_thumb(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        api_response(403, null, '无权访问网盘');
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = (string)($_GET['uri'] ?? '');
    if ($uri === '' || !netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        api_response(400, null, '文件不在授权范围内');
    }
    $thumb = cloudreve_get_thumb_url($cfg['cfg'], $uri);
    if ($thumb === null || $thumb === '') {
        api_response(404, null, '缩略图不存在');
    }
    // Cloudreve Pro 可能返回混淆/相对路径的反盗链 URL（obfuscated: true），
    // 无法在服务端直接代理，此时优雅降级为 404（前端会回退到内容代理预览）。
    if (!preg_match('#^https?://#i', $thumb)) {
        api_response(404, null, '缩略图不可直接代理');
    }
    $resp = netdisk_proxy_fetch($thumb, 20);
    if (!$resp['ok']) {
        api_response(502, null, '获取缩略图失败：' . $resp['error']);
    }
    $raw = $resp['raw'];
    $imgInfo = @getimagesizefromstring($raw);
    $imgMime = is_array($imgInfo) ? (string)($imgInfo['mime'] ?? '') : '';
    if (!preg_match('#^image/(png|jpeg|gif|webp|bmp|avif)$#', $imgMime)) {
        $imgMime = 'image/jpeg';
    }
    header('Content-Type: ' . $imgMime);
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, max-age=300');
    echo $raw;
    exit;
}

function handle_netdisk_content(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        api_response(403, null, '无权访问网盘');
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = (string)($_GET['uri'] ?? '');
    if ($uri === '' || !netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        api_response(400, null, '文件不在授权范围内');
    }
    $info = cloudreve_file_info($cfg['cfg'], $uri);
    $name = is_array($info) ? (string)($info['name'] ?? '') : '';
    if ($name === '') {
        $name = rawurldecode((string)($_GET['name'] ?? ''));
    }
    $urls = cloudreve_get_file_urls($cfg['cfg'], [$uri]);
    if (empty($urls) || empty($urls[0]['url'])) {
        api_response(502, null, '获取文件地址失败');
    }
    $fileUrl = (string)$urls[0]['url'];
    $resp = netdisk_proxy_fetch($fileUrl, 30);
    if (!$resp['ok']) {
        api_response(502, null, '获取文件内容失败：' . $resp['error']);
    }
    $raw = $resp['raw'];
    $ext = netdisk_file_ext($name);
    $mime = detect_share_asset_mime_by_ext($ext);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . strlen($raw));
    header('X-Content-Type-Options: nosniff');
    // SVG 是主动内容：直接导航时用 CSP sandbox 禁止脚本执行
    if ($mime === 'image/svg+xml') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
    echo $raw;
    exit;
}

// 网盘下载：必须命中该分享的下载规则，否则 403
function handle_netdisk_download(string $slug): void {
    $gated = netdisk_gate_share($slug);
    if (!$gated) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    [$share, $cfg] = $gated;
    $root = netdisk_normalize_root($cfg['root']);
    $uri = (string)($_GET['uri'] ?? '');
    if ($uri === '' || !netdisk_uri_within_root($root, $uri, $cfg['cfg'])) {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }
    if (!netdisk_download_allowed($share, $uri)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $info = cloudreve_file_info($cfg['cfg'], $uri);
    $name = is_array($info) ? (string)($info['name'] ?? '') : '';
    if ($name === '') {
        $name = rawurldecode((string)($_GET['name'] ?? basename($uri)));
    }
    $urls = cloudreve_get_file_urls($cfg['cfg'], [$uri]);
    if (empty($urls) || empty($urls[0]['url'])) {
        http_response_code(502);
        echo '获取文件地址失败';
        exit;
    }
    $fileUrl = (string)$urls[0]['url'];
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'ignore_errors' => true, 'follow_location' => 1],
    ]);
    $raw = @file_get_contents($fileUrl, false, $ctx);
    if ($raw === false) {
        http_response_code(502);
        echo '下载文件失败';
        exit;
    }
    $ext = netdisk_file_ext($name);
    $mime = detect_share_asset_mime_by_ext($ext);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . strlen($raw));
    echo $raw;
    exit;
}

function detect_share_asset_mime_by_ext(string $ext): string {
    $map = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'avif' => 'image/avif',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'audio/ogg',
        'ogv' => 'video/ogg', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'opus' => 'audio/ogg', 'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=UTF-8', 'md' => 'text/markdown; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8', 'json' => 'application/json; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation', 'rtf' => 'application/rtf',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function netdisk_test_connection(array $cfg): array {
    $c = cloudreve_conn($cfg);
    if ($c['base_url'] === '' || $c['email'] === '' || $c['password'] === '') {
        return ['ok' => false, 'message' => '地址/账号/密码不能为空'];
    }
    $login = cloudreve_login_detailed($cfg);
    if (!$login['ok']) {
        return ['ok' => false, 'message' => '登录失败：' . $login['reason']];
    }
    $token = cloudreve_access_token($cfg);
    if ($token === '') {
        return ['ok' => false, 'message' => '未能获取访问令牌'];
    }
    $root = netdisk_normalize_root((string)($cfg['default_root'] ?? ''));
    $data = cloudreve_list_folder($cfg, $root);
    if ($data === null) {
        return ['ok' => false, 'message' => '连接成功，但无法访问根目录「' . $root . '」'];
    }
    $count = count($data['files'] ?? []);
    return ['ok' => true, 'message' => '连接成功，根目录「' . $root . '」可访问，共 ' . $count . ' 项'];
}

// ============================================================
// WOPI 只读宿主：把 php-site 变成 WOPI Host，
// 让 Collabora / OnlyOffice 服务器直接从我们这里拉取文件字节，
// 实现文档只读预览（放弃在线编辑）。
// ============================================================

function netdisk_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function netdisk_base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function netdisk_wopi_secret(): string {
    $sec = (string)get_setting('netdisk_wopi_secret', '');
    if ($sec === '') {
        $sec = bin2hex(random_bytes(32));
        set_setting('netdisk_wopi_secret', $sec);
    }
    return $sec;
}

function netdisk_wopi_sign_token(int $shareId, string $uri, int $cfgId, int $ttl = 3600): string {
    $secret = netdisk_wopi_secret();
    $exp = time() + $ttl;
    $payload = netdisk_base64url_encode(json_encode([
        'sid' => $shareId,
        'uri' => $uri,
        'cid' => $cfgId,
        'exp' => $exp,
    ]));
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

function netdisk_wopi_verify_token(string $token): ?array {
    $secret = netdisk_wopi_secret();
    $pos = strrpos($token, '.');
    if ($pos === false) {
        return null;
    }
    $payload = substr($token, 0, $pos);
    $sig = substr($token, $pos + 1);
    if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
        return null;
    }
    $data = json_decode(netdisk_base64url_decode($payload), true);
    if (!is_array($data)) {
        return null;
    }
    if ((int)($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

// WOPI 共享访问守门：token 自身已授权，不再校验分享密码 cookie
function netdisk_wopi_gate(array $share, array $data, int $cfgId): ?array {
    if (share_is_expired($share)) {
        return null;
    }
    if (share_visitor_limit_reached($share)) {
        return null;
    }
    $cfg = netdisk_share_config($share);
    if (!$cfg['enabled']) {
        return null;
    }
    if ((int)$share['id'] !== (int)($data['sid'] ?? 0)) {
        return null;
    }
    if ($cfgId !== (int)($data['cid'] ?? 0)) {
        return null;
    }
    $conn = cloudreve_config_get($cfgId);
    if (!$conn || (int)($conn['enabled'] ?? 0) !== 1) {
        return null;
    }
    $myshareCfg = netdisk_share_config($share);
    $root = netdisk_normalize_root($myshareCfg['root']);
    if (!netdisk_uri_within_root($root, (string)($data['uri'] ?? ''), $conn)) {
        return null;
    }
    return $conn;
}

function cloudreve_file_info(array $cfg, string $uri): ?array {
    $resp = cloudreve_api($cfg, 'GET', '/file/info?uri=' . rawurlencode($uri));
    if (!$resp || (int)($resp['code'] ?? 1) !== 0) {
        return null;
    }
    return $resp['data'] ?? null;
}

function netdisk_share_label(array $cfg, string $root, string $uri): string {
    // 从 @share URI 提取分享根 URI（cloudreve://<uid>@share）
    $pos = strpos($uri, '@share');
    if ($pos === false) { return ''; }
    $shareRoot = substr($uri, 0, $pos + strlen('@share'));
    static $cache = [];
    $k = md5($root . '|' . $shareRoot);
    if (array_key_exists($k, $cache)) { return $cache[$k]; }
    $name = '';
    // 列出分享根目录，找 redirect == shareRoot 的文件夹，取其 name（如 测试）
    $rootN = rtrim(netdisk_normalize_root($root), '/');
    if ($rootN !== '') {
        $data = cloudreve_list_folder($cfg, $rootN);
        foreach (($data['files'] ?? []) as $f) {
            $r = (string)($f['metadata']['sys:shared_redirect'] ?? '');
            if ($r !== '' && str_starts_with($shareRoot, $r)) {
                $name = (string)($f['name'] ?? '');
                break;
            }
        }
    }
    $cache[$k] = $name;
    return $name;
}




function handle_netdisk_wopi(string $slug, string $fileId): void {
    $token = (string)($_GET['access_token'] ?? '');
    $data = netdisk_wopi_verify_token($token);
    if (!$data) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $conn = netdisk_wopi_gate($share, $data, (int)($data['cid'] ?? 0));
    if (!$conn) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $uri = (string)$data['uri'];
    if (substr(md5($slug . ':' . $uri), 0, 20) !== $fileId) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $info = cloudreve_file_info($conn, $uri);
    if (!$info) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $cfg = netdisk_share_config($share);
    $name = (string)($info['name'] ?? basename($uri));
    header('Content-Type: application/json');
    echo json_encode([
        'BaseFileName' => $name,
        'OwnerId' => (string)($share['user_id'] ?? ''),
        'Size' => (int)($info['size'] ?? 0),
        'UserId' => 'visitor',
        'Version' => '1.0',
        'ReadOnly' => true,
        'UserCanWrite' => false,
        'UserCanNotWriteRelative' => true,
        'UserCanRename' => false,
        'UserCanShare' => false,
        'SupportsLocks' => false,
        'SupportsUpdate' => false,
        'SupportsRename' => false,
        'SupportsDeleteFile' => false,
        'CloseButton' => true,
        'Permission' => 'read',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_netdisk_wopi_getfile(string $slug, string $fileId): void {
    $token = (string)($_GET['access_token'] ?? '');
    $data = netdisk_wopi_verify_token($token);
    if (!$data) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $conn = netdisk_wopi_gate($share, $data, (int)($data['cid'] ?? 0));
    if (!$conn) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $uri = (string)$data['uri'];
    if (substr(md5($slug . ':' . $uri), 0, 20) !== $fileId) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    $urls = cloudreve_get_file_urls($conn, [$uri]);
    if (empty($urls) || empty($urls[0]['url'])) {
        http_response_code(502);
        echo 'Failed to fetch file';
        exit;
    }
    $fileUrl = (string)$urls[0]['url'];
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    $headers = "User-Agent: SiyuanShareNetdisk\r\n";
    if ($range !== '') {
        $headers .= 'Range: ' . $range . "\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'ignore_errors' => true,
            'follow_location' => 1,
            'header' => $headers,
        ],
    ]);
    $raw = @file_get_contents($fileUrl, false, $ctx);

    if ($raw === false) {
        http_response_code(502);
        echo 'Failed to fetch file content';
        exit;
    }
    $statusCode = 200;
    $contentRange = '';
    if (is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $mm)) {
                $statusCode = (int)$mm[1];
            } elseif (stripos($h, 'Content-Range:') === 0) {
                $contentRange = trim(substr($h, 14));
            }
        }
    }
    if ($statusCode === 206 && $contentRange !== '') {
        header('Content-Range: ' . $contentRange);
    }
    http_response_code($statusCode === 206 ? 206 : 200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($raw));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $raw;
    exit;
}

// ---- Office 引擎（Collabora / OnlyOffice）全局配置（office_apps 表）与 URL 构建 ----

// 读取全部 office 应用
function netdisk_office_apps(bool $enabledOnly = false): array {
    $pdo = db();
    $sql = 'SELECT id, name, engine, base_url, enabled, created_at, updated_at FROM office_apps';
    if ($enabledOnly) {
        $sql .= ' WHERE enabled=1';
    }
    $sql .= ' ORDER BY id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// 取某引擎应用的 base_url（仅已启用）
function netdisk_office_base_url(string $engine): string {
    foreach (netdisk_office_apps(true) as $app) {
        if (strtolower((string)$app['engine']) === $engine) {
            return rtrim((string)$app['base_url'], '/');
        }
    }
    return '';
}

// 统一抓取 WOPI 引擎地址：先带证书校验抓取；https 失败时自动绕过证书校验降级重试一次。
// 返回 ['ok'=>bool, 'raw'=>string, 'error'=>string, 'note'=>string]；error 区分 DNS / 拒连 / 超时 / 证书 / HTTP 状态码。
function netdisk_office_http_classify(string $msg): string {
    $m = strtolower($msg);
    if (strpos($m, 'getaddrinfo') !== false || strpos($m, 'could not resolve') !== false || strpos($m, 'name or service not known') !== false || strpos($m, 'nodename nor servname') !== false) {
        return 'DNS 解析失败（主机名不存在或容器内 DNS 不可用）';
    }
    if (strpos($m, 'connection refused') !== false) {
        return '连接被拒绝（端口未监听或被防火墙拦截）';
    }
    if (strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false) {
        return '连接超时（网络不通或服务未在该端口监听）';
    }
    if (strpos($m, 'wrong version number') !== false || strpos($m, 'packet length too long') !== false || strpos($m, 'unsupported protocol') !== false || strpos($m, 'ssl connect error') !== false) {
        return 'TLS 握手失败：目标端口可能并不是 HTTPS 服务（wrong version number / packet length too long），若该服务为明文端口请改用 http:// 地址';
    }
    if (strpos($m, 'ssl') !== false || strpos($m, 'certificate') !== false) {
        return 'TLS 证书校验失败（自签名/过期/主机名不匹配）';
    }
    if (strpos($m, 'could not find') !== false || strpos($m, 'unable to') !== false) {
        return '网络不可达：' . $msg;
    }
    return $msg !== '' ? $msg : '未知错误';
}

function netdisk_office_http_get_once(string $url, bool $insecure): array {
    $options = ['http' => ['timeout' => 15, 'follow_location' => 1, 'ignore_errors' => true, 'max_redirects' => 5]];
    if ($insecure) {
        $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
    }
    $lastError = '';
    set_error_handler(function ($no, $msg) use (&$lastError) { $lastError = $msg; return true; });
    $raw = @file_get_contents($url, false, stream_context_create($options));
    restore_error_handler();
    $status = '';
    if (isset($http_response_header[0]) && is_string($http_response_header[0])) {
        $status = $http_response_header[0];
    }
    if ($raw === false) {
        return ['ok' => false, 'raw' => '', 'status' => '',
                'error' => netdisk_office_http_classify($lastError),
                'ssl_fail' => (stripos($lastError, 'ssl') !== false || stripos($lastError, 'certificate') !== false)];
    }
    if ($status !== '' && preg_match('#HTTP/\S+\s+([1-5]\d\d)#', $status, $sm) && (int)$sm[1] >= 400) {
        return ['ok' => false, 'raw' => $raw, 'status' => $status,
                'error' => 'HTTP 状态码异常：' . $status, 'ssl_fail' => false];
    }
    return ['ok' => true, 'raw' => $raw, 'status' => $status, 'error' => '', 'ssl_fail' => false];
}

function netdisk_office_fetch(string $url): array {
    $first = netdisk_office_http_get_once($url, false);
    if ($first['ok']) {
        return ['ok' => true, 'raw' => $first['raw'], 'error' => '', 'note' => ''];
    }
    if (strpos($url, 'https://') !== 0) {
        return ['ok' => false, 'raw' => $first['raw'], 'error' => $first['error'], 'note' => ''];
    }
    // https 失败：绕过证书校验降级重试一次（局域网自签证书常见场景）
    $retry = netdisk_office_http_get_once($url, true);
    if ($retry['ok']) {
        return ['ok' => true, 'raw' => $retry['raw'], 'error' => '',
                'note' => '（证书校验未通过，已自动绕过证书校验重试成功；建议为服务配置受信证书或局域网内改走 http）'];
    }
    // 两次都失败：绕过证书后仍失败且原因不是证书 => 真实原因是 DNS/拒连/超时/状态码；否则回退首次的证书错误
    $final = (!$retry['ssl_fail'] && $retry['error'] !== '') ? $retry : $first;
    return ['ok' => false, 'raw' => $final['raw'], 'error' => $final['error'], 'note' => ''];
}

// 从引擎的 /hosting/discovery 解析 ext -> view 的 action urlsrc（缓存 1 小时）
// 若该引擎当前没有已启用的应用，直接返回空（避免误用旧缓存/已删除应用的地址）
function netdisk_office_discovery(string $engine): array {
    $base = netdisk_office_base_url($engine);
    if ($base === '') {
        return [];
    }
    $cacheKey = 'netdisk_office_disc_' . $engine . '_' . md5($base);
    $cached = (string)get_setting($cacheKey, '');
    if ($cached !== '') {
        $arr = json_decode($cached, true);
        if (is_array($arr) && (int)($arr['ts'] ?? 0) > time() - 3600) {
            return is_array($arr['map'] ?? null) ? $arr['map'] : [];
        }
    }
    $url = $base . '/hosting/discovery';
    $resp = netdisk_office_fetch($url);
    $raw = $resp['ok'] ? $resp['raw'] : '';
    $map = [];
    if ($raw !== false && strpos($raw, '<') === 0) {
        preg_match_all('#<action[^>]*name="([^"]+)"[^>]*ext="([^"]+)"[^>]*urlsrc="([^"]+)"#i', $raw, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            if (strtolower($row[1]) === 'view') {
                $map[strtolower($row[2])] = html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5);
            }
        }
        preg_match_all('#<action[^>]*ext="([^"]+)"[^>]*name="([^"]+)"[^>]*urlsrc="([^"]+)"#i', $raw, $m2, PREG_SET_ORDER);
        foreach ($m2 as $row) {
            if (strtolower($row[2]) === 'view') {
                $map[strtolower($row[1])] = html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5);
            }
        }
    }
    set_setting($cacheKey, json_encode(['ts' => time(), 'map' => $map]));
    return $map;
}

// 校验 office 服务器地址：抓取 /hosting/discovery 并解析应用名与可预览扩展名
// 返回 ['ok'=>bool, 'app'=>(string)服务器应用名, 'exts'=>array(可view扩展名的去重列表), 'error'=>(string)]
function netdisk_office_validate_base(string $base): array {
    $base = rtrim(trim($base), '/');
    if ($base === '') {
        return ['ok' => false, 'app' => '', 'exts' => [], 'error' => '地址不能为空'];
    }
    if (strpos($base, 'http://') !== 0 && strpos($base, 'https://') !== 0) {
        return ['ok' => false, 'app' => '', 'exts' => [], 'error' => '地址需以 http(s):// 开头'];
    }
    $url = $base . '/hosting/discovery';
    $resp = netdisk_office_fetch($url);
    $raw = $resp['ok'] ? $resp['raw'] : '';
    if (!$resp['ok'] || $raw === '') {
        $reason = $resp['ok'] ? '返回内容为空' : $resp['error'];
        return ['ok' => false, 'app' => '', 'exts' => [], 'error' => '无法访问 ' . $url . '（' . $reason . '）' . $resp['note']];
    }
    if (strpos($raw, '<') !== 0) {
        $snip = mb_substr(trim(strip_tags((string)$raw)), 0, 80);
        return ['ok' => false, 'app' => '', 'exts' => [], 'error' => '返回内容不是企业文档服务（WOPI discovery）' . ($snip !== '' ? '；实际返回：' . $snip : '') . $resp['note']];
    }
    preg_match('#<app[^>]*name="([^"]+)"#i', $raw, $am);
    $appName = isset($am[1]) ? html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5) : '(未知)';
    $exts = [];
    preg_match_all('#<action[^>]*name="([^"]+)"[^>]*ext="([^"]+)"#i', $raw, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        if (strtolower($row[1]) === 'view') {
            $exts[strtolower($row[2])] = true;
        }
    }
    preg_match_all('#<action[^>]*ext="([^"]+)"[^>]*name="([^"]+)"#i', $raw, $m2, PREG_SET_ORDER);
    foreach ($m2 as $row) {
        if (strtolower($row[2]) === 'view') {
            $exts[strtolower($row[1])] = true;
        }
    }
    $extList = array_map('strval', array_keys($exts));
    sort($extList, SORT_STRING);
    return [
        'ok' => count($extList) > 0,
        'app' => $appName,
        'exts' => $extList,
        'error' => count($extList) > 0 ? '' : '未在 discovery 中发现可预览的扩展名',
    ];
}

function netdisk_office_has_view(string $engine, string $ext): bool {
    $map = netdisk_office_discovery($engine);
    return isset($map[strtolower(ltrim($ext, '.'))]);
}

// OnlyOffice 应用映射（与 discovery 中 /hosting/wopi/{app}/view 对应）
function netdisk_onlyoffice_app(string $ext): string {
    $ext = strtolower(ltrim($ext, '.'));
    if (in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'dot', 'dotx', 'wps'], true)) {
        return 'word';
    }
    if (in_array($ext, ['xls', 'xlsx', 'ods', 'csv', 'tsv'], true)) {
        return 'cell';
    }
    if (in_array($ext, ['ppt', 'pptx', 'odp', 'pps', 'ppsx'], true)) {
        return 'slide';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }
    return 'word';
}

// 引擎显示名
function netdisk_office_engine_label(string $engine): string {
    $e = strtolower(trim($engine));
    if ($e === 'collabora') {
        return 'Collabora';
    }
    if ($e === 'onlyoffice') {
        return 'OnlyOffice';
    }
    return $e !== '' ? $engine : '未知';
}

// 取某引擎启用应用的 url_template（泛化引擎用；返回 '' 表示无模板）
function netdisk_office_app_url_template(string $engine): string {
    $stmt = db()->prepare('SELECT url_template FROM office_apps WHERE engine = :e AND enabled = 1 AND url_template != "" ORDER BY id ASC LIMIT 1');
    $stmt->execute([':e' => $engine]);
    $v = $stmt->fetchColumn();
    return $v === false ? '' : (string)$v;
}

// 解析当前应使用哪个引擎预览某扩展名（全局：netdisk_office_ext_map + netdisk_office_engine，auto 优先 Collabora）
function netdisk_office_engine(string $ext): ?string {
    $ext = strtolower(ltrim($ext, '.'));
    $map = json_decode((string)get_setting('netdisk_office_ext_map', '{}'), true);
    if (is_array($map) && !empty($map[$ext])) {
        $m = strtolower((string)$map[$ext]);
        if (netdisk_office_base_url($m) !== '') {
            return $m;
        }
    }
    $def = strtolower((string)get_setting('netdisk_office_engine', 'auto'));
    if ($def !== 'auto' && netdisk_office_base_url($def) !== '') {
        return $def;
    }
    if (netdisk_office_has_view('collabora', $ext)) {
        return 'collabora';
    }
    if (netdisk_office_has_view('onlyoffice', $ext)) {
        return 'onlyoffice';
    }
    // 其它自定义引擎：若存在支持该扩展的模板也返回之
    foreach (netdisk_office_apps(true) as $app) {
        $eng = strtolower((string)$app['engine']);
        if (in_array($eng, ['collabora', 'onlyoffice'], true)) {
            continue;
        }
        if ((string)($app['url_template'] ?? '') !== '' && netdisk_office_has_view($eng, $ext)) {
            return $eng;
        }
    }
    return null;
}

// 构建 office viewer 的 iframe URL
function netdisk_office_viewer_url(string $engine, string $ext, string $wopiBaseUrl, string $token): string {
    $base = netdisk_office_base_url($engine);
    if ($base === '') {
        return '';
    }
    if ($engine === 'collabora') {
        $map = netdisk_office_discovery($engine);
        $src = $map[strtolower(ltrim($ext, '.'))] ?? '';
        // Collabora: 所有扩展共用同一 cool.html 路径（含版本 hash）；取一个 view urlsrc 的“? 之前”部分
        if ($src === '') {
            $src = count($map) > 0 ? (string)reset($map) : '';
        }
        if ($src === '') {
            $src = $base . '/browser/1ec9f2b9e7/cool.html?';
        }
        $path = strtok($src, '?');
        if ($path === false || $path === '') {
            $path = $src;
        }
        return rtrim($path, '/') . '?WOPISrc=' . rawurlencode($wopiBaseUrl) . '&access_token=' . rawurlencode($token) . '&lang=zh-CN&closebutton=1';
    }
    if ($engine === 'onlyoffice') {
        // OnlyOffice：直接构建 /hosting/wopi/{app}/view
        $app = netdisk_onlyoffice_app($ext);
        return $base . '/hosting/wopi/' . $app . '/view?WOPISrc=' . rawurlencode($wopiBaseUrl) . '&access_token=' . rawurlencode($token) . '&lang=zh-CN';
    }
    // 其它自定义引擎：用保存的 url_template 替换占位符
    $template = netdisk_office_app_url_template($engine);
    if ($template !== '') {
        $replaced = str_replace(
            ['{WOPISrc}', '{access_token}', '{lang}'],
            [rawurlencode($wopiBaseUrl), rawurlencode($token), 'zh-CN'],
            $template
        );
        // 兼容部分服务器使用 <WOPISrc> 这类占位
        $replaced = str_replace(['<WOPISrc>', '<wopisrc=WOPI_SOURCE&>', 'WOPI_SOURCE'], [rawurlencode($wopiBaseUrl), 'WOPISrc=' . rawurlencode($wopiBaseUrl) . '&', rawurlencode($wopiBaseUrl)], $replaced);
        if ($replaced !== '' && strpos($replaced, 'WOPISrc=') === false) {
            $replaced .= (strpos($replaced, '?') === false ? '?' : '&') . 'WOPISrc=' . rawurlencode($wopiBaseUrl) . '&access_token=' . rawurlencode($token);
        }
        return $replaced;
    }
    return '';
}

// 判断是否已配置 office 引擎（存在任一启用应用）
function netdisk_office_configured(): bool {
    return count(netdisk_office_apps(true)) > 0;
}

function render_netdisk_tab_button(): string {
    return '<button class="kb-side-tab" type="button" data-share-tab="netdisk">网盘</button>';
}

function render_netdisk_panel(string $slug): string {
    $base = base_path();
    $html = '<div class="kb-side-panel" data-share-panel="netdisk" data-netdisk-slug="' . htmlspecialchars($slug) . '" hidden>';
    $html .= '<div class="kb-side-body netdisk-body">';
    $html .= '<div class="netdisk-toolbar">';
    $html .= '<button class="button ghost" type="button" data-netdisk-up title="刷新"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 4v6h6M20 20v-6h-6"/><path fill="none" stroke="currentColor" stroke-width="2" d="M20 10a8 8 0 0 0-14.4-3M4 14a8 8 0 0 0 14.4 3"/></svg></button>';
    $html .= '<div class="netdisk-crumbs" data-netdisk-crumbs></div>';
    $html .= '</div>';
    $html .= '<div class="netdisk-list" data-netdisk-list><div class="netdisk-empty">加载中…</div></div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}
function min_chunk_size_bytes(): int {
    global $config;
    $kb = (int)($config['min_chunk_size_kb'] ?? 256);
    if ($kb <= 0) {
        $kb = 256;
    }
    return $kb * 1024;
}

function max_chunk_size_bytes(): int {
    global $config;
    $mb = (int)($config['max_chunk_size_mb'] ?? 8);
    if ($mb <= 0) {
        $mb = 8;
    }
    return $mb * 1024 * 1024;
}

function chunk_size_limits(): array {
    $min = min_chunk_size_bytes();
    $max = max_chunk_size_bytes();
    if ($max < $min) {
        $max = $min;
    }
    return [$min, $max];
}

function site_version(): string {
    global $config;
    return trim((string)($config['site_version'] ?? ''));
}

function central_stats_url(): string {
    global $config;
    $raw = trim((string)($config['central_stats_url'] ?? ''));
    return rtrim($raw, '/');
}

function get_instance_id(): string {
    try {
        $existing = trim((string)get_setting('instance_id', ''));
        if ($existing !== '') {
            return $existing;
        }
        $generated = bin2hex(random_bytes(16));
        set_setting('instance_id', $generated);
        return $generated;
    } catch (Throwable $e) {
        return bin2hex(random_bytes(16));
    }
}

function maybe_send_instance_heartbeat(): void {
    $base = central_stats_url();
    if ($base === '') {
        return;
    }
    $interval = 10800;
    $lastAttempt = (int)get_setting('instance_heartbeat_attempt', '0');
    if ($lastAttempt > 0 && (time() - $lastAttempt) < $interval) {
        return;
    }
    set_setting('instance_heartbeat_attempt', (string)time());
    $payload = [
        'instance_id' => get_instance_id(),
        'version' => site_version(),
        'timestamp' => time(),
    ];
    $resp = http_post_json($base . '/api/instances/heartbeat', $payload);
    if (is_array($resp) && (int)($resp['code'] ?? 1) === 0) {
        set_setting('instance_heartbeat_at', (string)time());
    }
}

function fetch_latest_release_info(): ?array {
    $cachePath = __DIR__ . '/storage/release_cache.json';
    $ttl = 3600;
    if (is_file($cachePath) && (time() - filemtime($cachePath) < $ttl)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $latest = [
        'version' => '',
        'url' => '',
        'has_php_site' => false,
        'fetched_at' => time(),
    ];
    $perPage = 30;
    $page = 1;
    while (true) {
        $url = 'https://api.github.com/repos/b8l8u8e8/siyuan-plugin-share/releases?per_page=' . $perPage . '&page=' . $page;
        $data = http_get_json($url);
        if (!$data || !is_array($data)) {
            if ($page === 1) {
                return null;
            }
            break;
        }
        if (empty($data)) {
            break;
        }
        $keys = array_keys($data);
        if ($keys !== range(0, count($keys) - 1)) {
            if ($page === 1) {
                return null;
            }
            break;
        }
        foreach ($data as $release) {
            if (!is_array($release)) {
                continue;
            }
            $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
            $version = '';
            $hasPhpSite = false;
            foreach ($assets as $asset) {
                $name = (string)($asset['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (stripos($name, 'php-site') === false) {
                    continue;
                }
                $hasPhpSite = true;
                if (preg_match('/php-site-v(.+)\.zip/i', $name, $matches)) {
                    $version = trim((string)($matches[1] ?? ''));
                }
                break;
            }
            if ($hasPhpSite) {
                if ($version === '') {
                    $version = trim((string)($release['tag_name'] ?? $release['name'] ?? ''));
                }
                $latest['version'] = $version;
                $latest['url'] = (string)($release['html_url'] ?? '');
                $latest['has_php_site'] = true;
                $latest['fetched_at'] = time();
                break 2;
            }
        }
        if (count($data) < $perPage) {
            break;
        }
        $page++;
    }
    try {
        ensure_dir(dirname($cachePath));
        file_put_contents($cachePath, json_encode($latest, JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // ignore cache failure
    }
    return $latest;
}

function site_update_info(): ?array {
    $current = site_version();
    maybe_send_instance_heartbeat();
    $latest = fetch_latest_release_info();
    if (!$latest || empty($latest['has_php_site']) || ($latest['version'] ?? '') === '') {
        return null;
    }
    $latestVersion = trim((string)$latest['version']);
    $currentVersion = trim((string)$current);
    $latestComparable = ltrim($latestVersion, 'vV');
    $currentComparable = ltrim($currentVersion, 'vV');
    $isNewer = false;
    if ($currentComparable === '') {
        $isNewer = true;
    } else {
        $isNewer = version_compare($latestComparable, $currentComparable, '>');
    }
    if (!$isNewer) {
        return null;
    }
    return [
        'version' => $latestVersion,
        'url' => (string)($latest['url'] ?? ''),
    ];
}

function fetch_central_instance_stats(): ?array {
    $base = central_stats_url();
    if ($base === '') {
        return null;
    }
    $cached = null;
    $cachedRaw = (string)get_setting('central_stats_cache', '');
    if ($cachedRaw !== '') {
        $cached = json_decode($cachedRaw, true);
        if (!is_array($cached)) {
            $cached = null;
        }
    }
    $resp = http_get_json($base . '/api/instances/stats');
    if (!is_array($resp) || (int)($resp['code'] ?? 1) !== 0) {
        return $cached;
    }
    $data = $resp['data'] ?? null;
    if (!is_array($data)) {
        return $cached;
    }
    $data['fetched_at'] = now();
    set_setting('central_stats_cache', json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

function build_date_range(int $days): array {
    $days = max(1, $days);
    $dates = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $dates[] = date('Y-m-d', strtotime("-{$i} days"));
    }
    return $dates;
}

function fill_series(array $dates, array $rows, string $field): array {
    $map = [];
    foreach ($rows as $row) {
        $day = (string)($row['day'] ?? '');
        if ($day === '') {
            continue;
        }
        $map[$day] = (float)($row[$field] ?? 0);
    }
    $series = [];
    foreach ($dates as $day) {
        $series[] = (float)($map[$day] ?? 0);
    }
    return $series;
}

function build_chart_paths(array $values, int $width, int $height, int $padding, float $maxValue): array {
    $count = count($values);
    if ($count === 0) {
        return ['line' => '', 'area' => ''];
    }
    $step = $count > 1 ? ($width - $padding * 2) / ($count - 1) : 0;
    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $val = (float)$values[$i];
        $ratio = $maxValue > 0 ? ($val / $maxValue) : 0;
        $x = $padding + ($step * $i);
        $y = $height - $padding - ($ratio * ($height - $padding * 2));
        $points[] = [$x, $y];
    }
    $line = 'M ' . implode(' L ', array_map(fn($pt) => round($pt[0], 2) . ' ' . round($pt[1], 2), $points));
    $areaPoints = $points;
    $areaPoints[] = [$padding + ($step * ($count - 1)), $height - $padding];
    $areaPoints[] = [$padding, $height - $padding];
    $area = 'M ' . implode(' L ', array_map(fn($pt) => round($pt[0], 2) . ' ' . round($pt[1], 2), $areaPoints)) . ' Z';
    return ['line' => $line, 'area' => $area];
}

function render_chart_svg(array $seriesList, int $width = 360, int $height = 220): string {
    $maxValue = 0;
    foreach ($seriesList as $series) {
        foreach ($series['values'] as $value) {
            $maxValue = max($maxValue, (float)$value);
        }
    }
    if ($maxValue <= 0) {
        $maxValue = 1;
    }
    $padding = 12;
    $gridLines = [];
    foreach ([0.33, 0.66] as $ratio) {
        $y = $height - $padding - ($ratio * ($height - $padding * 2));
        $gridLines[] = '<line x1="' . $padding . '" y1="' . round($y, 2) . '" x2="' . ($width - $padding) . '" y2="' . round($y, 2) . '"></line>';
    }
    $paths = '';
    foreach ($seriesList as $series) {
        $pathsData = build_chart_paths($series['values'], $width, $height, $padding, $maxValue);
        $areaClass = $series['area_class'] ?? '';
        if ($areaClass !== '') {
            $paths .= '<path class="' . $areaClass . '" d="' . $pathsData['area'] . '"></path>';
        }
    }
    foreach ($seriesList as $series) {
        $lineClass = $series['line_class'] ?? '';
        $pathsData = build_chart_paths($series['values'], $width, $height, $padding, $maxValue);
        $paths .= '<path class="' . $lineClass . '" d="' . $pathsData['line'] . '"></path>';
    }
    return '<svg class="admin-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">'
        . '<g class="admin-chart__grid">' . implode('', $gridLines) . '</g>'
        . $paths
        . '</svg>';
}

function render_admin_chart_holder(array $labels, array $series, string $unit, string $fallbackSvg = ''): string {
    $payload = [
        'labels' => $labels,
        'series' => $series,
        'unit' => $unit,
    ];
    $data = htmlspecialchars(
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES
    );
    return '<div class="admin-chart-holder" data-admin-chart="' . $data . '">' . $fallbackSvg . '</div>';
}

function render_kpi_card(string $label, string $value, string $meta, string $iconSvg, string $extraHtml = ''): string {
    return '<div class="admin-kpi">'
        . '<div class="admin-kpi__head"><div><div class="admin-kpi__label">' . $label . '</div>'
        . '<div class="admin-kpi__value">' . $value . '</div></div>'
        . '<div class="admin-kpi__icon">' . $iconSvg . '</div></div>'
        . '<div class="admin-kpi__meta">' . $meta . '</div>'
        . $extraHtml
        . '</div>';
}

function build_topbar_title(string $title, ?array $user): string {
    $titleHtml = htmlspecialchars($title);
    $versionText = site_version();
    if ($versionText !== '') {
        $versionLabel = $versionText;
        if (stripos($versionLabel, 'v') !== 0) {
            $versionLabel = 'v' . $versionLabel;
        }
        $titleHtml .= ' <span class="topbar-version">' . htmlspecialchars($versionLabel) . '</span>';
    }
    if ($user && ($user['role'] ?? '') === 'admin') {
        $update = site_update_info();
        if ($update) {
            $updateVersion = trim((string)($update['version'] ?? ''));
            if ($updateVersion !== '' && stripos($updateVersion, 'v') !== 0) {
                $updateVersion = 'v' . $updateVersion;
            }
            $updateLabel = htmlspecialchars('有新版 ' . $updateVersion);
            $updateUrl = trim((string)($update['url'] ?? ''));
            if ($updateUrl !== '') {
                $titleHtml .= ' <a class="topbar-version is-update" href="' . htmlspecialchars($updateUrl) . '" target="_blank" rel="noopener noreferrer">' . $updateLabel . '</a>';
            } else {
                $titleHtml .= ' <span class="topbar-version is-update">' . $updateLabel . '</span>';
            }
        }
    }
    return $titleHtml;
}

function country_code_zh_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'AF' => '阿富汗',
        'AL' => '阿尔巴尼亚',
        'DZ' => '阿尔及利亚',
        'AS' => '美属萨摩亚',
        'AD' => '安道尔',
        'AO' => '安哥拉',
        'AI' => '安圭拉',
        'AQ' => '南极洲',
        'AG' => '安提瓜和巴布达',
        'AR' => '阿根廷',
        'AM' => '亚美尼亚',
        'AW' => '阿鲁巴',
        'AU' => '澳大利亚',
        'AT' => '奥地利',
        'AZ' => '阿塞拜疆',
        'BS' => '巴哈马',
        'BH' => '巴林',
        'BD' => '孟加拉国',
        'BB' => '巴巴多斯',
        'BY' => '白俄罗斯',
        'BE' => '比利时',
        'BZ' => '伯利兹',
        'BJ' => '贝宁',
        'BM' => '百慕大',
        'BT' => '不丹',
        'BO' => '玻利维亚',
        'BA' => '波斯尼亚和黑塞哥维那',
        'BW' => '博茨瓦纳',
        'BR' => '巴西',
        'IO' => '英属印度洋领地',
        'BN' => '文莱',
        'BG' => '保加利亚',
        'BF' => '布基纳法索',
        'BI' => '布隆迪',
        'KH' => '柬埔寨',
        'CM' => '喀麦隆',
        'CA' => '加拿大',
        'CV' => '佛得角',
        'KY' => '开曼群岛',
        'CF' => '中非共和国',
        'TD' => '乍得',
        'CL' => '智利',
        'CN' => '中国',
        'CX' => '圣诞岛',
        'CC' => '科科斯（基林）群岛',
        'CO' => '哥伦比亚',
        'KM' => '科摩罗',
        'CG' => '刚果（布）',
        'CD' => '刚果（金）',
        'CK' => '库克群岛',
        'CR' => '哥斯达黎加',
        'CI' => '科特迪瓦',
        'HR' => '克罗地亚',
        'CU' => '古巴',
        'CY' => '塞浦路斯',
        'CZ' => '捷克',
        'DK' => '丹麦',
        'DJ' => '吉布提',
        'DM' => '多米尼克',
        'DO' => '多米尼加共和国',
        'EC' => '厄瓜多尔',
        'EG' => '埃及',
        'SV' => '萨尔瓦多',
        'GQ' => '赤道几内亚',
        'ER' => '厄立特里亚',
        'EE' => '爱沙尼亚',
        'SZ' => '斯威士兰',
        'ET' => '埃塞俄比亚',
        'FK' => '福克兰群岛',
        'FO' => '法罗群岛',
        'FJ' => '斐济',
        'FI' => '芬兰',
        'FR' => '法国',
        'GF' => '法属圭亚那',
        'PF' => '法属波利尼西亚',
        'TF' => '法属南部领地',
        'GA' => '加蓬',
        'GM' => '冈比亚',
        'GE' => '格鲁吉亚',
        'DE' => '德国',
        'GH' => '加纳',
        'GI' => '直布罗陀',
        'GR' => '希腊',
        'GL' => '格陵兰',
        'GD' => '格林纳达',
        'GP' => '瓜德罗普',
        'GU' => '关岛',
        'GT' => '危地马拉',
        'GG' => '根西',
        'GN' => '几内亚',
        'GW' => '几内亚比绍',
        'GY' => '圭亚那',
        'HT' => '海地',
        'HM' => '赫德岛和麦克唐纳群岛',
        'HN' => '洪都拉斯',
        'HK' => '中国香港',
        'HU' => '匈牙利',
        'IS' => '冰岛',
        'IN' => '印度',
        'ID' => '印度尼西亚',
        'IR' => '伊朗',
        'IQ' => '伊拉克',
        'IE' => '爱尔兰',
        'IM' => '马恩岛',
        'IL' => '以色列',
        'IT' => '意大利',
        'JM' => '牙买加',
        'JP' => '日本',
        'JE' => '泽西',
        'JO' => '约旦',
        'KZ' => '哈萨克斯坦',
        'KE' => '肯尼亚',
        'KI' => '基里巴斯',
        'KP' => '朝鲜',
        'KR' => '韩国',
        'KW' => '科威特',
        'KG' => '吉尔吉斯斯坦',
        'LA' => '老挝',
        'LV' => '拉脱维亚',
        'LB' => '黎巴嫩',
        'LS' => '莱索托',
        'LR' => '利比里亚',
        'LY' => '利比亚',
        'LI' => '列支敦士登',
        'LT' => '立陶宛',
        'LU' => '卢森堡',
        'MO' => '中国澳门',
        'MK' => '北马其顿',
        'MG' => '马达加斯加',
        'MW' => '马拉维',
        'MY' => '马来西亚',
        'MV' => '马尔代夫',
        'ML' => '马里',
        'MT' => '马耳他',
        'MH' => '马绍尔群岛',
        'MQ' => '马提尼克',
        'MR' => '毛里塔尼亚',
        'MU' => '毛里求斯',
        'YT' => '马约特',
        'MX' => '墨西哥',
        'FM' => '密克罗尼西亚',
        'MD' => '摩尔多瓦',
        'MC' => '摩纳哥',
        'MN' => '蒙古',
        'ME' => '黑山',
        'MS' => '蒙特塞拉特',
        'MA' => '摩洛哥',
        'MZ' => '莫桑比克',
        'MM' => '缅甸',
        'NA' => '纳米比亚',
        'NR' => '瑙鲁',
        'NP' => '尼泊尔',
        'NL' => '荷兰',
        'NC' => '新喀里多尼亚',
        'NZ' => '新西兰',
        'NI' => '尼加拉瓜',
        'NE' => '尼日尔',
        'NG' => '尼日利亚',
        'NU' => '纽埃',
        'NF' => '诺福克岛',
        'MP' => '北马里亚纳群岛',
        'NO' => '挪威',
        'OM' => '阿曼',
        'PK' => '巴基斯坦',
        'PW' => '帕劳',
        'PS' => '巴勒斯坦',
        'PA' => '巴拿马',
        'PG' => '巴布亚新几内亚',
        'PY' => '巴拉圭',
        'PE' => '秘鲁',
        'PH' => '菲律宾',
        'PN' => '皮特凯恩群岛',
        'PL' => '波兰',
        'PT' => '葡萄牙',
        'PR' => '波多黎各',
        'QA' => '卡塔尔',
        'RE' => '留尼汪',
        'RO' => '罗马尼亚',
        'RU' => '俄罗斯',
        'RW' => '卢旺达',
        'BL' => '圣巴泰勒米',
        'SH' => '圣赫勒拿',
        'KN' => '圣基茨和尼维斯',
        'LC' => '圣卢西亚',
        'MF' => '法属圣马丁',
        'PM' => '圣皮埃尔和密克隆',
        'VC' => '圣文森特和格林纳丁斯',
        'WS' => '萨摩亚',
        'SM' => '圣马力诺',
        'ST' => '圣多美和普林西比',
        'SA' => '沙特阿拉伯',
        'SN' => '塞内加尔',
        'RS' => '塞尔维亚',
        'SC' => '塞舌尔',
        'SL' => '塞拉利昂',
        'SG' => '新加坡',
        'SX' => '荷属圣马丁',
        'SK' => '斯洛伐克',
        'SI' => '斯洛文尼亚',
        'SB' => '所罗门群岛',
        'SO' => '索马里',
        'ZA' => '南非',
        'GS' => '南乔治亚和南桑威奇群岛',
        'SS' => '南苏丹',
        'ES' => '西班牙',
        'LK' => '斯里兰卡',
        'SD' => '苏丹',
        'SR' => '苏里南',
        'SJ' => '斯瓦尔巴和扬马延',
        'SE' => '瑞典',
        'CH' => '瑞士',
        'SY' => '叙利亚',
        'TW' => '中国台湾',
        'TJ' => '塔吉克斯坦',
        'TZ' => '坦桑尼亚',
        'TH' => '泰国',
        'TL' => '东帝汶',
        'TG' => '多哥',
        'TK' => '托克劳',
        'TO' => '汤加',
        'TT' => '特立尼达和多巴哥',
        'TN' => '突尼斯',
        'TR' => '土耳其',
        'TM' => '土库曼斯坦',
        'TC' => '特克斯和凯科斯群岛',
        'TV' => '图瓦卢',
        'UG' => '乌干达',
        'UA' => '乌克兰',
        'AE' => '阿联酋',
        'GB' => '英国',
        'US' => '美国',
        'UM' => '美国本土外小岛屿',
        'UY' => '乌拉圭',
        'UZ' => '乌兹别克斯坦',
        'VU' => '瓦努阿图',
        'VA' => '梵蒂冈',
        'VE' => '委内瑞拉',
        'VN' => '越南',
        'VG' => '英属维尔京群岛',
        'VI' => '美属维尔京群岛',
        'WF' => '瓦利斯和富图纳',
        'EH' => '西撒哈拉',
        'YE' => '也门',
        'ZM' => '赞比亚',
        'ZW' => '津巴布韦',
        'AX' => '奥兰群岛',
        'BQ' => '荷兰加勒比区',
        'CW' => '库拉索',
        'XK' => '科索沃',
    ];
    return $map;
}

function has_non_ascii(string $value): bool {
    return (bool)preg_match('/[\x80-\xFF]/', $value);
}

function normalize_country_name(string $country, string $countryCode): string {
    $code = strtoupper(trim($countryCode));
    if ($code !== '') {
        $map = country_code_zh_map();
        if (isset($map[$code])) {
            return $map[$code];
        }
    }
    return $country;
}

function normalize_us_region(string $region): string {
    $raw = trim($region);
    if ($raw === '') {
        return '';
    }
    $abbrMap = [
        'AL' => '阿拉巴马州',
        'AK' => '阿拉斯加州',
        'AZ' => '亚利桑那州',
        'AR' => '阿肯色州',
        'CA' => '加利福尼亚州',
        'CO' => '科罗拉多州',
        'CT' => '康涅狄格州',
        'DE' => '特拉华州',
        'FL' => '佛罗里达州',
        'GA' => '佐治亚州',
        'HI' => '夏威夷州',
        'ID' => '爱达荷州',
        'IL' => '伊利诺伊州',
        'IN' => '印第安纳州',
        'IA' => '爱荷华州',
        'KS' => '堪萨斯州',
        'KY' => '肯塔基州',
        'LA' => '路易斯安那州',
        'ME' => '缅因州',
        'MD' => '马里兰州',
        'MA' => '马萨诸塞州',
        'MI' => '密歇根州',
        'MN' => '明尼苏达州',
        'MS' => '密西西比州',
        'MO' => '密苏里州',
        'MT' => '蒙大拿州',
        'NE' => '内布拉斯加州',
        'NV' => '内华达州',
        'NH' => '新罕布什尔州',
        'NJ' => '新泽西州',
        'NM' => '新墨西哥州',
        'NY' => '纽约州',
        'NC' => '北卡罗来纳州',
        'ND' => '北达科他州',
        'OH' => '俄亥俄州',
        'OK' => '俄克拉何马州',
        'OR' => '俄勒冈州',
        'PA' => '宾夕法尼亚州',
        'RI' => '罗得岛州',
        'SC' => '南卡罗来纳州',
        'SD' => '南达科他州',
        'TN' => '田纳西州',
        'TX' => '德克萨斯州',
        'UT' => '犹他州',
        'VT' => '佛蒙特州',
        'VA' => '弗吉尼亚州',
        'WA' => '华盛顿州',
        'WV' => '西弗吉尼亚州',
        'WI' => '威斯康星州',
        'WY' => '怀俄明州',
        'DC' => '哥伦比亚特区',
    ];
    $upper = strtoupper($raw);
    if (isset($abbrMap[$upper])) {
        return $abbrMap[$upper];
    }
    $nameMap = [
        'alabama' => '阿拉巴马州',
        'alaska' => '阿拉斯加州',
        'arizona' => '亚利桑那州',
        'arkansas' => '阿肯色州',
        'california' => '加利福尼亚州',
        'colorado' => '科罗拉多州',
        'connecticut' => '康涅狄格州',
        'delaware' => '特拉华州',
        'florida' => '佛罗里达州',
        'georgia' => '佐治亚州',
        'hawaii' => '夏威夷州',
        'idaho' => '爱达荷州',
        'illinois' => '伊利诺伊州',
        'indiana' => '印第安纳州',
        'iowa' => '爱荷华州',
        'kansas' => '堪萨斯州',
        'kentucky' => '肯塔基州',
        'louisiana' => '路易斯安那州',
        'maine' => '缅因州',
        'maryland' => '马里兰州',
        'massachusetts' => '马萨诸塞州',
        'michigan' => '密歇根州',
        'minnesota' => '明尼苏达州',
        'mississippi' => '密西西比州',
        'missouri' => '密苏里州',
        'montana' => '蒙大拿州',
        'nebraska' => '内布拉斯加州',
        'nevada' => '内华达州',
        'new hampshire' => '新罕布什尔州',
        'new jersey' => '新泽西州',
        'new mexico' => '新墨西哥州',
        'new york' => '纽约州',
        'north carolina' => '北卡罗来纳州',
        'north dakota' => '北达科他州',
        'ohio' => '俄亥俄州',
        'oklahoma' => '俄克拉何马州',
        'oregon' => '俄勒冈州',
        'pennsylvania' => '宾夕法尼亚州',
        'rhode island' => '罗得岛州',
        'south carolina' => '南卡罗来纳州',
        'south dakota' => '南达科他州',
        'tennessee' => '田纳西州',
        'texas' => '德克萨斯州',
        'utah' => '犹他州',
        'vermont' => '佛蒙特州',
        'virginia' => '弗吉尼亚州',
        'washington' => '华盛顿州',
        'west virginia' => '西弗吉尼亚州',
        'wisconsin' => '威斯康星州',
        'wyoming' => '怀俄明州',
        'district of columbia' => '哥伦比亚特区',
        'washington, d.c.' => '哥伦比亚特区',
    ];
    $lower = strtolower($raw);
    return $nameMap[$lower] ?? $region;
}

function normalize_ip_location(array $location): array {
    $country = trim((string)($location['country'] ?? ''));
    $countryCode = strtoupper(trim((string)($location['country_code'] ?? '')));
    $region = trim((string)($location['region'] ?? ''));
    $city = trim((string)($location['city'] ?? ''));
    $country = normalize_country_name($country, $countryCode);
    if ($countryCode === 'US') {
        $region = normalize_us_region($region);
    }
    return [
        'country' => $country,
        'country_code' => $countryCode,
        'region' => $region,
        'city' => $city,
    ];
}

function lookup_ip_location_cn(string $ip): array {
    $data = http_get_json('http://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true');
    if (!is_array($data)) {
        return ['country' => '', 'country_code' => '', 'region' => '', 'city' => ''];
    }
    $pro = trim((string)($data['pro'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $addr = trim((string)($data['addr'] ?? ''));
    $country = '';
    $region = '';
    $cityOut = '';
    $countryCode = '';
    if ($pro !== '' || $city !== '') {
        $country = '中国';
        $countryCode = 'CN';
        $region = $pro;
        $cityOut = $city;
    } elseif ($addr !== '') {
        $country = preg_replace('/\s+/', ' ', $addr);
        if (strpos($country, '中国') !== false) {
            $countryCode = 'CN';
        }
    }
    return [
        'country' => $country,
        'country_code' => $countryCode,
        'region' => $region,
        'city' => $cityOut,
    ];
}

function lookup_ip_location(string $ip): array {
    $ip = trim($ip);
    if ($ip === '') {
        return ['country' => '', 'country_code' => '', 'region' => '', 'city' => ''];
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_access_geo_cache WHERE ip = :ip LIMIT 1');
    $stmt->execute([':ip' => $ip]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if ($cached) {
        $updatedAt = strtotime((string)($cached['updated_at'] ?? ''));
        if ($updatedAt && (time() - $updatedAt) < 86400 * 30) {
            $cachedLocation = [
                'country' => (string)($cached['country'] ?? ''),
                'country_code' => (string)($cached['country_code'] ?? ''),
                'region' => (string)($cached['region'] ?? ''),
                'city' => (string)($cached['city'] ?? ''),
            ];
            $text = ($cachedLocation['country'] ?? '') . ($cachedLocation['region'] ?? '') . ($cachedLocation['city'] ?? '');
            if ($text === '' || has_non_ascii($text)) {
                return normalize_ip_location($cachedLocation);
            }
        }
    }
    $location = lookup_ip_location_cn($ip);
    if (trim((string)($location['country'] ?? '')) === '') {
        $primary = http_get_json('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city&lang=zh-CN');
        if (is_array($primary) && ($primary['status'] ?? '') === 'success') {
            $location = [
                'country' => (string)($primary['country'] ?? ''),
                'country_code' => (string)($primary['countryCode'] ?? ''),
                'region' => (string)($primary['regionName'] ?? ''),
                'city' => (string)($primary['city'] ?? ''),
            ];
        }
    }
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_access_geo_cache (ip, country, country_code, region, city, updated_at)
        VALUES (:ip, :country, :code, :region, :city, :updated_at)');
    $location = normalize_ip_location($location);
    $stmt->execute([
        ':ip' => $ip,
        ':country' => $location['country'],
        ':code' => $location['country_code'],
        ':region' => $location['region'],
        ':city' => $location['city'],
        ':updated_at' => now(),
    ]);
    return $location;
}

function format_ip_location(array $location): string {
    $location = normalize_ip_location($location);
    $parts = [];
    foreach (['country', 'region', 'city'] as $key) {
        $val = trim((string)($location[$key] ?? ''));
        if ($val !== '') {
            $parts[] = $val;
        }
    }
    return implode(' / ', $parts);
}

function calculate_access_log_size(array $fields): int {
    $total = 32;
    foreach ($fields as $value) {
        $total += strlen((string)$value);
    }
    return $total;
}

function purge_share_access_logs(int $shareId): ?int {
    if ($shareId <= 0) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id, COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $userId = $row ? (int)$row['user_id'] : null;
    $total = $row ? (int)$row['total'] : 0;
    $pdo->prepare('DELETE FROM share_access_logs WHERE share_id = :sid')->execute([':sid' => $shareId]);
    if ($userId) {
        adjust_user_storage($userId, -$total);
    }
    return $userId;
}

function purge_user_access_logs(int $userId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $stmt->closeCursor();
    $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = :uid')->execute([':uid' => $userId]);
    adjust_user_storage($userId, -$total);
    return $total;
}

function cleanup_user_access_logs(int $userId, int $days): void {
    $days = max(1, $days);
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid AND created_at < :cutoff');
    $stmt->execute([
        ':uid' => $userId,
        ':cutoff' => $cutoff,
    ]);
    $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $stmt->closeCursor();
    if ($total <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = :uid AND created_at < :cutoff')->execute([
        ':uid' => $userId,
        ':cutoff' => $cutoff,
    ]);
    adjust_user_storage($userId, -$total);
}

function record_share_access(array $share, ?string $docId = null, ?string $docTitle = null): void {
    $userId = (int)($share['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    if (!access_stats_enabled($userId)) {
        return;
    }
    $visitorId = get_visitor_id();
    $ip = get_client_ip();
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $location = lookup_ip_location($ip);
    $size = calculate_access_log_size([
        $docId,
        $docTitle,
        $visitorId,
        $ip,
        $referer,
        $location['country'] ?? '',
        $location['region'] ?? '',
        $location['city'] ?? '',
    ]);
    $user = get_user_by_id($userId);
    if (!$user) {
        return;
    }
    $limit = get_user_limit_bytes($user);
    $used = (int)($user['storage_used_bytes'] ?? 0);
    if ($limit > 0 && ($used + $size) > $limit) {
        set_user_setting($userId, 'access_stats_enabled', '0');
        purge_user_access_logs($userId);
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_access_logs
        (user_id, share_id, doc_id, doc_title, visitor_id, ip, ip_country, ip_country_code, ip_region, ip_city, referer, created_at, size_bytes)
        VALUES (:uid, :sid, :doc_id, :doc_title, :visitor_id, :ip, :country, :country_code, :region, :city, :referer, :created_at, :size_bytes)');
    $stmt->execute([
        ':uid' => $userId,
        ':sid' => (int)($share['id'] ?? 0),
        ':doc_id' => $docId,
        ':doc_title' => $docTitle,
        ':visitor_id' => $visitorId,
        ':ip' => $ip,
        ':country' => (string)($location['country'] ?? ''),
        ':country_code' => (string)($location['country_code'] ?? ''),
        ':region' => (string)($location['region'] ?? ''),
        ':city' => (string)($location['city'] ?? ''),
        ':referer' => $referer,
        ':created_at' => now(),
        ':size_bytes' => $size,
    ]);
    adjust_user_storage($userId, $size);
    cleanup_user_access_logs($userId, access_stats_retention_days($userId));
}

function fetch_share_comments(int $shareId): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE share_id = :share_id ORDER BY created_at DESC, id DESC');
    $stmt->execute([':share_id' => $shareId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

function build_comment_tree(array $comments): array {
    $items = [];
    foreach ($comments as $comment) {
        $comment['children'] = [];
        $items[(int)$comment['id']] = $comment;
    }
    $roots = [];
    foreach ($items as $id => &$comment) {
        $parentId = (int)($comment['parent_id'] ?? 0);
        if ($parentId > 0 && isset($items[$parentId])) {
            $items[$parentId]['children'][] = &$comment;
        } else {
            $roots[] = &$comment;
        }
    }
    unset($comment);
    $sorter = static function (array $a, array $b): int {
        $aTime = (string)($a['created_at'] ?? '');
        $bTime = (string)($b['created_at'] ?? '');
        if ($aTime === $bTime) {
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        }
        return strcmp($bTime, $aTime);
    };
    $sortTree = static function (array &$nodes) use (&$sortTree, $sorter): void {
        usort($nodes, $sorter);
        foreach ($nodes as &$node) {
            if (!empty($node['children'])) {
                $sortTree($node['children']);
            }
        }
        unset($node);
    };
    $sortTree($roots);
    return $roots;
}

function can_delete_share_comment(array $comment, array $share, ?array $user): bool {
    $viewerId = $user ? (int)($user['id'] ?? 0) : 0;
    if ($viewerId > 0 && $viewerId === (int)$share['user_id']) {
        return true;
    }
    $commentUserId = (int)($comment['user_id'] ?? 0);
    if ($viewerId > 0 && $commentUserId > 0 && $viewerId === $commentUserId) {
        return true;
    }
    $visitorId = get_visitor_id();
    if ($visitorId !== '' && $visitorId === (string)($comment['visitor_id'] ?? '')) {
        return true;
    }
    return false;
}

function render_comment_markdown(string $markdown): string {
    static $parser = null;
    if (!$parser) {
        $parser = new Parsedown();
        if (method_exists($parser, 'setSafeMode')) {
            $parser->setSafeMode(true);
        }
        if (method_exists($parser, 'setBreaksEnabled')) {
            $parser->setBreaksEnabled(true);
        }
    }
    return $parser->text($markdown);
}

function format_comment_content(string $content): string {
    return render_comment_markdown($content);
}

function render_comment_emoji_picker(): string {
    $emojis = ['😀', '😁', '😂', '😅', '😊', '😍', '😘', '😎', '🤔', '😴', '😭', '😡', '👍', '🙏', '🎉', '❤️'];
    $html = '<div class="comment-emoji-panel" data-emoji-panel hidden>';
    foreach ($emojis as $emoji) {
        $html .= '<button class="comment-emoji" type="button" data-emoji="' . htmlspecialchars($emoji, ENT_QUOTES) . '">' . htmlspecialchars($emoji) . '</button>';
    }
    $html .= '</div>';
    return $html;
}

function render_comment_editor_fields(string $content = '', string $textareaName = 'content'): string {
    $html = '<div class="comment-editor" data-comment-editor>';
    $html .= '<div class="comment-toolbar">';
    $html .= '<button class="comment-tool" type="button" data-emoji-toggle aria-label="表情" title="表情">';
    $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"></circle><circle cx="9" cy="10" r="1.2" fill="currentColor"></circle><circle cx="15" cy="10" r="1.2" fill="currentColor"></circle><path d="M8 14c1.2 1.2 2.5 1.8 4 1.8 1.5 0 2.8-.6 4-1.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg>';
    $html .= '</button>';
    $html .= '<button class="comment-tool" type="button" data-image-insert aria-label="图片" title="图片">';
    $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"></rect><circle cx="9" cy="11" r="2" fill="currentColor"></circle><path d="M21 16l-5-5-4 4-2-2-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
    $html .= '</button>';
    $html .= '<input class="comment-image-input" type="file" accept="image/*" data-image-input hidden>';
    $html .= render_comment_emoji_picker();
    $html .= '</div>';
    $html .= '<textarea class="input comment-input" name="' . htmlspecialchars($textareaName) . '" rows="4" placeholder="写下你的评论..." required>' . htmlspecialchars($content) . '</textarea>';
    $html .= '</div>';
    return $html;
}

function render_comment_form(string $action, ?string $docId, string $emailValue, ?int $parentId, string $buttonLabel, string $contentValue = ''): string {
    $html = '<form method="post" action="' . htmlspecialchars($action) . '" class="comment-form">';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($docId !== null && $docId !== '') {
        $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docId) . '">';
    }
    if ($parentId) {
        $html .= '<input type="hidden" name="parent_id" value="' . (int)$parentId . '">';
    }
    $html .= '<div class="comment-grid">';
    $html .= '<div><label>邮箱</label><input class="input" name="email" type="email" value="' . htmlspecialchars($emailValue) . '" placeholder="name@example.com" required></div>';
    $html .= '<div class="comment-wide"><label>评论</label>' . render_comment_editor_fields($contentValue, 'content') . '</div>';
    if (captcha_enabled()) {
        $html .= '<div class="comment-captcha"><label>验证码</label><div class="comment-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="验证码" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<button class="button primary" type="submit">' . htmlspecialchars($buttonLabel) . '</button>';
    $html .= '</form>';
    return $html;
}

function render_comment_node(array $comment, array $share, ?array $user, string $slug, ?string $docId, int $depth, array $indexMap): string {
    $commentId = (int)($comment['id'] ?? 0);
    $rawEmail = (string)($comment['email'] ?? '');
    $email = mask_email($rawEmail);
    $created = format_share_datetime((string)($comment['created_at'] ?? ''));
    $rawContent = (string)($comment['content'] ?? '');
    $content = format_comment_content($rawContent);
    $isOwner = (int)($comment['user_id'] ?? 0) === (int)$share['user_id'];
    $viewerIsOwner = $user && (int)($user['id'] ?? 0) === (int)$share['user_id'];
    $avatarSeed = $rawEmail !== '' ? $rawEmail : '访客';
    $avatarLabel = function_exists('mb_substr')
        ? mb_substr($avatarSeed, 0, 1, 'UTF-8')
        : substr($avatarSeed, 0, 1);
    $authorLabel = $email !== '' ? $email : '访客';
    $metaParts = [];
    if ($created !== '') {
        $metaParts[] = $created;
    }
    $metaLabel = $metaParts ? implode(' - ', $metaParts) : '';

    $html = '<div class="comment-item" style="--comment-depth:' . $depth . '" id="comment-' . $commentId . '">';
    $index = $indexMap[$commentId] ?? 0;
    $html .= '<div class="comment-card">';
    $html .= '<div class="comment-body">';
    $html .= '<div class="comment-head">';
    if ($index > 0) {
        $html .= '<span class="comment-index">' . $index . '</span>';
    }
    $html .= '<div class="comment-author">' . htmlspecialchars($authorLabel) . '</div>';
    if ($isOwner) {
        $html .= '<span class="comment-badge">分享者</span>';
    }
    if ($metaLabel !== '') {
        $html .= '<span class="comment-time">' . htmlspecialchars($metaLabel) . '</span>';
    }
    $html .= '<details class="comment-menu">';
    $html .= '<summary class="comment-menu-trigger" aria-label="更多操作">...</summary>';
    $html .= '<div class="comment-menu-list">';
    $html .= '<button class="comment-menu-item" type="button" data-comment-action="edit" data-comment-id="' . $commentId . '" data-comment-content="' . htmlspecialchars($rawContent, ENT_QUOTES) . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '">编辑</button>';
    $html .= '<button class="comment-menu-item" type="button" data-comment-action="delete" data-comment-id="' . $commentId . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '" data-comment-owner="' . ($viewerIsOwner ? '1' : '0') . '">删除</button>';
    $html .= '</div>';
    $html .= '</details>';
    $html .= '</div>';
    $html .= '<div class="comment-content">' . $content . '</div>';
    $html .= '<div class="comment-footer">';
    $html .= '<button class="comment-reply-btn" type="button" data-comment-action="reply" data-comment-parent-id="' . $commentId . '" data-comment-email="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '" data-comment-author="' . htmlspecialchars($authorLabel, ENT_QUOTES) . '">回复</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    if (!empty($comment['children'])) {
        $html .= '<div class="comment-children">';
        foreach ($comment['children'] as $child) {
            $html .= render_comment_node($child, $share, $user, $slug, $docId, $depth + 1, $indexMap);
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_share_comments(array $share, ?array $user, ?string $docId = null): string {
    $shareId = (int)($share['id'] ?? 0);
    $slug = (string)($share['slug'] ?? '');
    if ($shareId <= 0 || $slug === '') {
        return '';
    }
    $comments = fetch_share_comments($shareId);
    $tree = build_comment_tree($comments);
    $order = $comments;
    usort($order, function ($a, $b) {
        $timeA = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $timeB = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($timeA === $timeB) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        }
        return $timeA <=> $timeB;
    });
    $indexMap = [];
    $i = 1;
    foreach ($order as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $indexMap[$id] = $i;
            $i++;
        }
    }
    $emailValue = $user ? (string)($user['email'] ?? '') : '';
    $error = flash('comment_error');
    $info = flash('comment_info');
    $formStateRaw = flash('comment_form');
    $formState = [];
    if ($formStateRaw) {
        $decoded = json_decode($formStateRaw, true);
        if (is_array($decoded)) {
            $formState = $decoded;
        }
    }
    $formMode = (string)($formState['mode'] ?? '');
    $formEmail = (string)($formState['email'] ?? '');
    $formContent = (string)($formState['content'] ?? '');
    $formParentId = (int)($formState['parent_id'] ?? 0);
    $formNote = (string)($formState['note'] ?? '');
    $commentFormEmail = $emailValue;
    $commentFormContent = '';
    if ($formMode === 'comment') {
        if ($formEmail !== '') {
            $commentFormEmail = $formEmail;
        }
        $commentFormContent = $formContent;
    }
    $modalReopen = $formMode === 'reply';
    $modalEmail = $modalReopen ? $formEmail : '';
    $modalContent = $modalReopen ? $formContent : '';
    $modalParentId = $modalReopen ? $formParentId : 0;
    $modalNote = $modalReopen ? $formNote : '';
    $actionBase = base_path() . '/s/' . $slug;
    $uploadAction = $actionBase . '/comment/upload';
    $docValue = ($docId !== null && $docId !== '') ? (string)$docId : '';
    $viewerIsOwner = $user && (int)($user['id'] ?? 0) === (int)$share['user_id'];
    $html = '<section class="share-comments" id="comments" data-comment-upload="' . htmlspecialchars($uploadAction) . '">';
    $html .= '<div class="comment-header">';
    $html .= '<h2>评论</h2>';
    $html .= '<div class="comment-count">' . count($comments) . ' 条评论</div>';
    $html .= '</div>';
    if ($error) {
        $html .= '<div class="flash comment-flash error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $html .= '<div class="flash comment-flash">' . htmlspecialchars($info) . '</div>';
    }
    if (empty($tree)) {
        $html .= '<p class="muted">暂无评论，欢迎第一个留言。</p>';
    } else {
        $html .= '<div class="comment-list">';
        foreach ($tree as $comment) {
            $html .= render_comment_node($comment, $share, $user, $slug, $docId, 0, $indexMap);
        }
        $html .= '</div>';
    }
    $html .= render_comment_form($actionBase . '/comment', $docId, $commentFormEmail, null, '发表评论', $commentFormContent);
    $html .= '<div class="modal comment-modal" data-comment-modal data-comment-action-base="' . htmlspecialchars($actionBase) . '" data-comment-doc-id="' . htmlspecialchars($docValue) . '" data-comment-owner="' . ($viewerIsOwner ? '1' : '0') . '" data-comment-default-email="' . htmlspecialchars($emailValue) . '" data-comment-reopen="' . ($modalReopen ? '1' : '0') . '" data-comment-reopen-mode="reply" data-comment-reopen-parent="' . (int)$modalParentId . '" data-comment-reopen-email="' . htmlspecialchars($modalEmail, ENT_QUOTES) . '" data-comment-reopen-content="' . htmlspecialchars($modalContent, ENT_QUOTES) . '" data-comment-reopen-note="' . htmlspecialchars($modalNote, ENT_QUOTES) . '" hidden>';
    $html .= '<div class="modal-backdrop" data-modal-close></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header" data-comment-modal-title>回复评论</div>';
    $html .= '<div class="modal-body">';
    $html .= '<form method="post" action="' . htmlspecialchars($actionBase . '/comment') . '" data-comment-form>';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docValue) . '" data-comment-doc>';
    $html .= '<input type="hidden" name="comment_id" value="" data-comment-id>';
    $html .= '<input type="hidden" name="parent_id" value="" data-comment-parent>';
    $html .= '<div class="comment-modal-note" data-comment-modal-note></div>';
    $html .= '<div class="comment-modal-fields" data-comment-verify>';
    $html .= '<div><label>邮箱</label><input class="input" name="email" type="email" value="" placeholder="name@example.com" required></div>';
    if (captcha_enabled()) {
        $html .= '<div class="comment-captcha"><label>验证码</label><div class="comment-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="验证码" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="comment-modal-fields" data-comment-editor-wrapper>';
    $html .= render_comment_editor_fields($modalContent, 'content');
    $html .= '</div>';
    $html .= '<div class="modal-actions">';
    $html .= '<button class="button ghost" type="button" data-modal-close>取消</button>';
    $html .= '<button class="button primary" type="submit" data-comment-submit>提交</button>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</section>';
    return $html;
}

function report_reason_options(): array {
    return [
        ['value' => 'illegal', 'label' => '违法违规'],
        ['value' => 'spam', 'label' => '垃圾广告/引流'],
        ['value' => 'infringe', 'label' => '侵权/盗用'],
        ['value' => 'other', 'label' => '其他'],
    ];
}

function report_reason_label(string $value): string {
    foreach (report_reason_options() as $option) {
        if ($option['value'] === $value) {
            return $option['label'];
        }
    }
    return $value;
}

function share_report_modal_id(string $slug): string {
    return 'report-modal-' . $slug;
}

function render_share_report_trigger(array $share): string {
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return '';
    }
    $modalId = share_report_modal_id($slug);
    return '<button class="kb-chip report-trigger" id="report" type="button" data-report-open data-report-target="' . htmlspecialchars($modalId) . '">举报</button>';
}

function render_share_report_form(array $share, ?array $user, ?string $docId = null): string {
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return '';
    }
    $error = flash('report_error');
    $info = flash('report_info');
    $action = base_path() . '/s/' . $slug . '/report';
    $modalId = share_report_modal_id($slug);
    $emailValue = $user ? (string)($user['email'] ?? '') : '';
    $formStateRaw = flash('report_form');
    $formState = [];
    if ($formStateRaw) {
        $decoded = json_decode($formStateRaw, true);
        if (is_array($decoded)) {
            $formState = $decoded;
        }
    }
    $reportEmailValue = $emailValue;
    $formEmail = (string)($formState['email'] ?? '');
    if ($formEmail !== '') {
        $reportEmailValue = $formEmail;
    }
    $formReason = (string)($formState['reason_type'] ?? '');
    $formDetail = (string)($formState['reason_detail'] ?? '');

    $html = '';
    if ($info) {
        $html .= '<div class="flash report-flash">' . htmlspecialchars($info) . '</div>';
    }
    $modalHidden = $error ? '' : ' hidden';
    $html .= '<div class="modal report-modal" id="' . htmlspecialchars($modalId) . '" data-report-modal' . $modalHidden . '>';
    $html .= '<div class="modal-backdrop" data-modal-close></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header">举报内容</div>';
    $html .= '<div class="modal-body">';
    if ($error) {
        $html .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    $html .= '<form method="post" action="' . htmlspecialchars($action) . '" class="report-form">';
    $html .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($docId !== null && $docId !== '') {
        $html .= '<input type="hidden" name="doc_id" value="' . htmlspecialchars($docId) . '">';
    }
    $html .= '<div class="report-grid">';
    $html .= '<div><label>举报类型</label><select class="input" name="reason_type" required>';
    foreach (report_reason_options() as $option) {
        $value = (string)($option['value'] ?? '');
        $selected = ($formReason !== '' && $formReason === $value) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($option['label']) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div><label>邮箱</label><input class="input" type="email" name="report_email" value="' . htmlspecialchars($reportEmailValue) . '" placeholder="name@example.com" required></div>';
    $html .= '<div class="report-wide"><label>补充说明</label><textarea class="input" name="reason_detail" rows="4" placeholder="请补充说明原因" required>' . htmlspecialchars($formDetail) . '</textarea></div>';
    if (captcha_enabled()) {
        $html .= '<div class="report-captcha">';
        $html .= '<label>验证码</label><div class="report-captcha-row">';
        $html .= '<input class="input" name="captcha" placeholder="验证码" required>';
        $html .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha>';
        $html .= '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="modal-actions">';
    $html .= '<button class="button ghost" type="button" data-modal-close>取消</button>';
    $html .= '<button class="button primary" type="submit">提交举报</button>';
    $html .= '</div>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function pending_report_count(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_reports WHERE handled_at IS NULL');
    return (int)$stmt->fetchColumn();
}

function build_share_redirect_path(string $slug, ?string $docId, string $anchor): string {
    $path = '/s/' . $slug;
    if ($docId !== null && $docId !== '') {
        $path .= '/' . rawurlencode($docId);
    }
    if ($anchor !== '') {
        $path .= '#' . $anchor;
    }
    return $path;
}

function collect_comment_tree_ids(array $rows, int $rootId): array {
    $children = [];
    $sizes = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $parent = (int)($row['parent_id'] ?? 0);
        $children[$parent][] = $id;
        $sizes[$id] = (int)($row['size_bytes'] ?? 0);
    }
    $stack = [$rootId];
    $ids = [];
    $total = 0;
    while (!empty($stack)) {
        $id = array_pop($stack);
        if (!isset($sizes[$id])) {
            continue;
        }
        $ids[] = $id;
        $total += $sizes[$id];
        foreach ($children[$id] ?? [] as $childId) {
            $stack[] = $childId;
        }
    }
    return [$ids, $total];
}

function handle_share_comment_upload(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        api_response(404, null, '分享不存在');
    }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        api_response(403, null, '分享已关闭，无法上传图片');
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        api_response(403, null, '请先输入访问密码');
    }
    $file = $_FILES['image'] ?? null;
    if (!$file || !is_array($file)) {
        api_response(400, null, '请选择图片文件');
    }
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        api_response(400, null, '图片上传失败');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        api_response(400, null, '图片上传失败');
    }
    $info = @getimagesize($tmp);
    if (!$info) {
        api_response(400, null, '仅支持图片文件');
    }
    $type = (int)($info[2] ?? 0);
    $ext = strtolower((string)image_type_to_extension($type, false));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            api_response(400, null, '仅支持图片文件');
        }
    }
    $owner = get_user_by_id((int)$share['user_id']);
    if (!$owner) {
        api_response(400, null, '分享所属用户不存在');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 && is_file($tmp)) {
        $size = (int)filesize($tmp);
    }
    $used = recalculate_user_storage((int)$owner['id']);
    $limit = get_user_limit_bytes($owner);
    if ($limit > 0 && ($used + $size) > $limit) {
        api_response(413, null, '存储空间不足');
    }
    $shareId = (int)$share['id'];
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    global $config;
    $assetPath = comment_asset_prefix() . $shareId . '/' . $filename;
    $dir = $config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/') . '/' . $shareId;
    ensure_dir($dir);
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        api_response(500, null, '图片保存失败');
    }
    $actualSize = $size;
    if ($actualSize <= 0 && is_file($target)) {
        $actualSize = (int)filesize($target);
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, created_at)
        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':doc_id' => null,
        ':asset_path' => $assetPath,
        ':file_path' => $assetPath,
        ':size_bytes' => $actualSize,
        ':created_at' => now(),
    ]);
    adjust_share_size($shareId, $actualSize);
    adjust_user_storage((int)$owner['id'], $actualSize);
    $url = base_path() . '/uploads/' . $assetPath;
    api_response(200, ['url' => $url, 'size' => $actualSize]);
}

function handle_share_search(string $slug): void {
    $share = find_share_by_slug($slug);
    if (!$share) { api_response(404, null, '分享不存在'); }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) { api_response(403, null, '分享已关闭'); }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) { api_response(403, null, '请先输入访问密码'); }
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 1) { api_response(200, ['docResults' => []]); }
    $pdo = db();
    $shareId = (int)$share['id'];
    $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) { api_response(200, ['docResults' => []]); }
    $wordConditions = [];
    $params = [':sid' => $shareId];
    foreach ($words as $i => $word) {
        $pk = ':qw' . $i;
        $likeVal = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $word) . '%';
        $wordConditions[] = "(title LIKE {$pk} ESCAPE \"\\\" OR markdown LIKE {$pk} ESCAPE \"\\\")";
        $params[$pk] = $likeVal;
    }
    $whereWords = implode(' AND ', $wordConditions);
    $stmt = $pdo->prepare('SELECT doc_id, title, markdown, hpath FROM share_docs WHERE share_id = :sid AND (' . $whereWords . ') ORDER BY updated_at DESC LIMIT 30');
    $rows = $stmt->execute($params) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $results = [];
    foreach ($rows as $d) {
        $title = (string)($d['title'] ?? '');
        $markdown = (string)($d['markdown'] ?? '');
        $snippets = [];
        foreach ($words as $wq) {
            foreach (extract_snippets_multi($markdown, $wq, 30) as $sn) {
                $snippets[] = $sn;
            }
        }
        usort($snippets, function ($a, $b) { return (int)$a['offset'] <=> (int)$b['offset']; });
        $merged = [];
        foreach ($snippets as $sn) {
            $dup = false;
            if (!empty($merged)) {
                $lastOff = $merged[count($merged) - 1]['offset'];
                if ($sn['offset'] - $lastOff < 3) { $dup = true; }
            }
            if (!$dup) { $merged[] = $sn; }
            if (count($merged) >= 30) break;
        }
        $titleSnippet = '';
        foreach ($words as $wq) {
            $tp = mb_stripos($title, $wq);
            if ($tp !== false) { $titleSnippet = extract_snippet_at($title, $tp); break; }
        }
        $single = !empty($merged) ? $merged[0]['text'] : $titleSnippet;
        $results[] = [
            'docId' => (string)$d['doc_id'],
            'title' => $title,
            'snippet' => $single,
            'snippets' => $merged,
            'hpath' => (string)($d['hpath'] ?? ''),
        ];
    }
    api_response(200, ['docResults' => $results, 'results' => $results]);
}

function handle_share_comment_submit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo '分享不存在';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        flash('comment_error', '分享已关闭，无法评论');
        redirect($redirectPath);
    }
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', '请先输入访问密码');
        redirect($redirectPath);
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $parentId = max(0, (int)($_POST['parent_id'] ?? 0));
    $shareId = (int)$share['id'];
    $parentEmail = '';
    if ($parentId > 0) {
        $pdo = db();
        $check = $pdo->prepare('SELECT email FROM share_comments WHERE id = :id AND share_id = :share_id');
        $check->execute([':id' => $parentId, ':share_id' => $shareId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        $check->closeCursor();
        if (!$row) {
            flash('comment_error', '回复目标不存在');
            redirect($redirectPath);
        }
        $parentEmail = trim((string)($row['email'] ?? ''));
    }
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            $state = [
                'mode' => $parentId > 0 ? 'reply' : 'comment',
                'email' => $email,
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : 0,
                'note' => $parentEmail !== '' ? mask_email($parentEmail) : '',
            ];
            flash('comment_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('comment_error', '验证码不对');
            redirect($redirectPath);
        }
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('comment_error', '请输入有效邮箱');
        redirect($redirectPath);
    }
    if ($content === '') {
        flash('comment_error', '评论内容不能为空');
        redirect($redirectPath);
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('comment_error', '评论内容过长');
        redirect($redirectPath);
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            $state = [
                'mode' => $parentId > 0 ? 'reply' : 'comment',
                'email' => $email,
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : 0,
                'note' => $parentEmail !== '' ? mask_email($parentEmail) : '',
            ];
            flash('comment_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('comment_error', '触发违禁词：' . $hit['word']);
            redirect($redirectPath);
        }
    }
    $owner = get_user_by_id((int)$share['user_id']);
    if (!$owner) {
        flash('comment_error', '分享所属用户不存在');
        redirect($redirectPath);
    }
    $size = calculate_comment_size($email, $content);
    $used = recalculate_user_storage((int)$owner['id']);
    $limit = get_user_limit_bytes($owner);
    if ($limit > 0 && ($used + $size) > $limit) {
        flash('comment_error', '存储空间不足，无法发表评论');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $userId = $viewer ? (int)$viewer['id'] : null;
    $visitorId = get_visitor_id();
    $ip = get_client_ip();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_comments (share_id, parent_id, user_id, visitor_id, email, content, ip, size_bytes, created_at)
        VALUES (:share_id, :parent_id, :user_id, :visitor_id, :email, :content, :ip, :size_bytes, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':parent_id' => $parentId > 0 ? $parentId : null,
        ':user_id' => $userId,
        ':visitor_id' => $visitorId !== '' ? $visitorId : null,
        ':email' => $email,
        ':content' => $content,
        ':ip' => $ip,
        ':size_bytes' => $size,
        ':created_at' => now(),
    ]);
    $commentId = (int)$pdo->lastInsertId();
    adjust_share_size($shareId, $size);
    adjust_user_storage((int)$owner['id'], $size);
    if ((int)($share['comment_notify'] ?? 0) === 1 && smtp_enabled()) {
        $recipientEmail = '';
        $isReply = false;
        if ($parentId > 0) {
            $recipientEmail = $parentEmail;
            $isReply = true;
        } else {
            $recipientEmail = trim((string)($owner['email'] ?? ''));
        }
        if ($recipientEmail !== '' && $email !== '' && strcasecmp($recipientEmail, $email) === 0) {
            $recipientEmail = '';
        }
        if ($recipientEmail !== '') {
            $commentPayload = [
                'id' => $commentId,
                'email' => $email,
                'content' => $content,
            ];
            enqueue_background_task(function () use ($share, $commentPayload, $recipientEmail, $isReply) {
                send_comment_notification($share, $commentPayload, $recipientEmail, $isReply);
            });
        }
    }
    $anchor = $commentId > 0 ? 'comment-' . $commentId : 'comments';
    flash('comment_info', '评论已提交');
    redirect(build_share_redirect_path($slug, $docId, $anchor));
}

function handle_share_comment_delete(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo '分享不存在';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', '请先输入访问密码');
        redirect($redirectPath);
    }
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    if ($commentId <= 0) {
        flash('comment_error', '缺少评论ID');
        redirect($redirectPath);
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE id = :id AND share_id = :share_id');
    $stmt->execute([':id' => $commentId, ':share_id' => (int)$share['id']]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$comment) {
        flash('comment_error', '评论不存在');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $viewerId = $viewer ? (int)($viewer['id'] ?? 0) : 0;
    $isShareOwner = $viewerId > 0 && $viewerId === (int)$share['user_id'];
    if (!$isShareOwner) {
        if (captcha_enabled()) {
            $captchaInput = (string)($_POST['captcha'] ?? '');
            if (!check_captcha($captchaInput)) {
                flash('comment_error', '验证码错误');
                redirect($redirectPath);
            }
        }
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('comment_error', '请输入有效邮箱');
            redirect($redirectPath);
        }
        $commentEmail = (string)($comment['email'] ?? '');
        if ($commentEmail === '' || strcasecmp($email, $commentEmail) !== 0) {
            flash('comment_error', '邮箱验证失败');
            redirect($redirectPath);
        }
    }
    $shareId = (int)$share['id'];
    $stmt = $pdo->prepare('SELECT id, parent_id, size_bytes, content FROM share_comments WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    [$ids, $totalSize] = collect_comment_tree_ids($rows, $commentId);
    $assetPaths = [];
    if (!empty($ids)) {
        $idLookup = array_fill_keys($ids, true);
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if (!isset($idLookup[$rowId])) {
                continue;
            }
            $content = (string)($row['content'] ?? '');
            $assetPaths = array_merge($assetPaths, extract_comment_asset_paths($content, $shareId));
        }
        if (!empty($assetPaths)) {
            $assetPaths = array_values(array_unique($assetPaths));
            $assetPaths = filter_unused_comment_assets($shareId, $assetPaths, $ids);
        }
    }
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $shareId;
        $del = $pdo->prepare('DELETE FROM share_comments WHERE id IN (' . $placeholders . ') AND share_id = ?');
        $del->execute($params);
    }
    $assetSize = delete_comment_assets($shareId, $assetPaths);
    $delta = -$totalSize - $assetSize;
    if ($delta !== 0) {
        adjust_share_size($shareId, $delta);
        adjust_user_storage((int)$share['user_id'], $delta);
    }
    flash('comment_info', '评论已删除');
    redirect($redirectPath);
}

function handle_share_comment_edit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo '分享不存在';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'comments');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('comment_error', '请先输入访问密码');
        redirect($redirectPath);
    }
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    if ($commentId <= 0) {
        flash('comment_error', '缺少评论ID');
        redirect($redirectPath);
    }
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            flash('comment_error', '验证码错误');
            redirect($redirectPath);
        }
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('comment_error', '请输入有效邮箱');
        redirect($redirectPath);
    }
    if ($content === '') {
        flash('comment_error', '评论内容不能为空');
        redirect($redirectPath);
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('comment_error', '评论内容过长');
        redirect($redirectPath);
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            flash('comment_error', '触发违禁词：' . $hit['word']);
            redirect($redirectPath);
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM share_comments WHERE id = :id AND share_id = :share_id');
    $stmt->execute([':id' => $commentId, ':share_id' => (int)$share['id']]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$comment) {
        flash('comment_error', '评论不存在');
        redirect($redirectPath);
    }
    $commentEmail = (string)($comment['email'] ?? '');
    $commentEmailMasked = $commentEmail !== '' ? mask_email($commentEmail) : '';
    if ($commentEmail === '' || strcasecmp($email, $commentEmail) !== 0) {
        flash('comment_error', '邮箱验证失败');
        redirect($redirectPath);
    }
    $shareId = (int)$share['id'];
    $newSize = calculate_comment_size($commentEmail, $content);
    $oldSize = (int)($comment['size_bytes'] ?? 0);
    $oldAssets = extract_comment_asset_paths((string)($comment['content'] ?? ''), $shareId);
    $newAssets = extract_comment_asset_paths($content, $shareId);
    $removeAssets = array_values(array_diff($oldAssets, $newAssets));
    if (!empty($removeAssets)) {
        $removeAssets = filter_unused_comment_assets($shareId, $removeAssets, [$commentId]);
    }
    $removeAssetSize = sum_share_asset_sizes($shareId, $removeAssets);
    $delta = $newSize - $oldSize;
    $netDelta = $delta - $removeAssetSize;
    if ($netDelta > 0) {
        $owner = get_user_by_id((int)$share['user_id']);
        if (!$owner) {
            flash('comment_error', '分享所属用户不存在');
            redirect($redirectPath);
        }
        $used = recalculate_user_storage((int)$owner['id']);
        $limit = get_user_limit_bytes($owner);
        if ($limit > 0 && ($used + $netDelta) > $limit) {
            flash('comment_error', '存储空间不足，无法保存修改');
            redirect($redirectPath);
        }
    }
    $update = $pdo->prepare('UPDATE share_comments SET content = :content, size_bytes = :size_bytes WHERE id = :id AND share_id = :share_id');
    $update->execute([
        ':content' => $content,
        ':size_bytes' => $newSize,
        ':id' => $commentId,
        ':share_id' => (int)$share['id'],
    ]);
    $deletedAssetSize = delete_comment_assets($shareId, $removeAssets);
    $totalDelta = $delta - $deletedAssetSize;
    if ($totalDelta !== 0) {
        adjust_share_size($shareId, $totalDelta);
        adjust_user_storage((int)$share['user_id'], $totalDelta);
    }
    $anchor = 'comment-' . $commentId;
    flash('comment_info', '评论已更新');
    redirect(build_share_redirect_path($slug, $docId, $anchor));
}

function handle_share_report_submit(string $slug): void {
    check_csrf();
    $share = find_share_by_slug($slug);
    if (!$share) {
        http_response_code(404);
        echo '分享不存在';
        exit;
    }
    $docId = trim((string)($_POST['doc_id'] ?? ''));
    $redirectPath = build_share_redirect_path($slug, $docId, 'report');
    if (share_requires_password($share) && !share_access_granted((int)$share['id'])) {
        flash('report_error', '请先输入访问密码');
        redirect($redirectPath);
    }
    $reportEmail = trim((string)($_POST['report_email'] ?? ''));
    $reasonType = trim((string)($_POST['reason_type'] ?? ''));
    $reasonDetail = trim((string)($_POST['reason_detail'] ?? ''));
    if (captcha_enabled()) {
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (!check_captcha($captchaInput)) {
            $state = [
                'email' => $reportEmail,
                'reason_type' => $reasonType,
                'reason_detail' => $reasonDetail,
            ];
            flash('report_form', json_encode($state, JSON_UNESCAPED_UNICODE));
            flash('report_error', '验证码不对');
            redirect($redirectPath);
        }
    }
    if ($reportEmail === '' || !filter_var($reportEmail, FILTER_VALIDATE_EMAIL)) {
        flash('report_error', '请输入有效邮箱');
        redirect($redirectPath);
    }
    $validReasons = array_column(report_reason_options(), 'value');
    if ($reasonType === '' || !in_array($reasonType, $validReasons, true)) {
        flash('report_error', '请选择举报类型');
        redirect($redirectPath);
    }
    if ($reasonDetail === '') {
        flash('report_error', '请填写举报说明');
        redirect($redirectPath);
    }
    $detailLength = function_exists('mb_strlen') ? mb_strlen($reasonDetail, 'UTF-8') : strlen($reasonDetail);
    if ($detailLength > 1000) {
        flash('report_error', '举报说明过长');
        redirect($redirectPath);
    }
    $viewer = current_user();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO share_reports (share_id, share_title, share_slug, share_user_id, reporter_user_id, report_email, visitor_id, ip, reason_type, reason_detail, created_at)
        VALUES (:share_id, :share_title, :share_slug, :share_user_id, :reporter_user_id, :report_email, :visitor_id, :ip, :reason_type, :reason_detail, :created_at)');
    $stmt->execute([
        ':share_id' => (int)$share['id'],
        ':share_title' => (string)($share['title'] ?? $slug),
        ':share_slug' => (string)($share['slug'] ?? $slug),
        ':share_user_id' => (int)$share['user_id'],
        ':reporter_user_id' => $viewer ? (int)$viewer['id'] : null,
        ':report_email' => $reportEmail,
        ':visitor_id' => get_visitor_id(),
        ':ip' => get_client_ip(),
        ':reason_type' => $reasonType,
        ':reason_detail' => $reasonDetail,
        ':created_at' => now(),
    ]);
    flash('report_info', '举报已提交，感谢反馈');
    redirect($redirectPath);
}

function build_from_header(string $from, string $name): string {
    $name = trim($name);
    if ($name === '') {
        return $from;
    }
    $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';
    return $encodedName . ' <' . $from . '>';
}

function send_mail(string $email, string $subject, string $body): bool {
    $from = get_setting('email_from', 'no-reply@example.com');
    $fromName = get_setting('email_from_name', '思源笔记分享');
    if (smtp_enabled()) {
        return send_smtp_mail($email, $subject, $body, $from, $fromName);
    }
    $headers = [];
    $headers[] = 'From: ' . build_from_header($from, $fromName);
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    return mail($email, $subject, $body, implode("\r\n", $headers));
}

function send_smtp_mail(string $email, string $subject, string $body, string $from, string $fromName): bool {
    $host = trim((string)get_setting('smtp_host', ''));
    if ($host === '') {
        return false;
    }
    $port = (int)get_setting('smtp_port', '587');
    $secure = strtolower(trim((string)get_setting('smtp_secure', 'tls')));
    $user = (string)get_setting('smtp_user', '');
    $pass = (string)get_setting('smtp_pass', '');
    $GLOBALS['smtp_last_error'] = '';
    try {
        require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';
        require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port > 0 ? $port : 587;
        $mailer->SMTPAuth = $user !== '';
        if ($secure === 'ssl') {
            $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPAutoTLS = false;
        }
        if ($user !== '') {
            $mailer->Username = $user;
            $mailer->Password = $pass;
        }
        $resolvedFrom = $from;
        if ($user !== '' && strcasecmp($from, $user) !== 0) {
            $resolvedFrom = $user;
            $mailer->addReplyTo($from, $fromName ?: $from);
        }
        $mailer->setFrom($resolvedFrom, $fromName ?: $resolvedFrom);
        if ($user !== '') {
            $mailer->Sender = $user;
        }
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->isHTML(false);
        if (!$mailer->send()) {
            $GLOBALS['smtp_last_error'] = $mailer->ErrorInfo;
            return false;
        }
        return true;
    } catch (Throwable $e) {
        $GLOBALS['smtp_last_error'] = $e->getMessage();
        return false;
    }
}

function send_email_code(string $email, string $code): bool {
    $subject = get_setting('email_subject', '邮箱验证码');
    $body = "您的邮箱验证码为：{$code}\n有效期 10 分钟，请勿泄露。";
    return send_mail($email, $subject, $body);
}

function send_reset_code(string $email, string $code): bool {
    $subject = get_setting('email_reset_subject', '重置密码验证码');
    $body = "您的密码重置验证码为：{$code}\n有效期 10 分钟，请勿泄露。";
    return send_mail($email, $subject, $body);
}

function send_comment_notification(array $share, array $comment, string $recipientEmail, bool $isReply): void {
    $recipientEmail = trim($recipientEmail);
    if ($recipientEmail === '') {
        return;
    }
    $slug = (string)($share['slug'] ?? '');
    if ($slug === '') {
        return;
    }
    $shareTitle = (string)($share['title'] ?? $slug);
    $commentEmail = (string)($comment['email'] ?? '');
    $commentEmailMasked = $commentEmail !== '' ? mask_email($commentEmail) : '';
    $commentContent = (string)($comment['content'] ?? '');
    $commentId = (int)($comment['id'] ?? 0);
    $url = share_url($slug);
    $anchor = $commentId > 0 ? '#comment-' . $commentId : '#comments';
    $subject = $isReply ? '评论收到回复' : '分享收到新评论';
    $body = $isReply
        ? "您在分享《{$shareTitle}》下的评论收到回复：\n"
        : "您的分享《{$shareTitle}》收到新评论：\n";
    if ($commentEmailMasked !== '') {
        $body .= "评论邮箱：{$commentEmailMasked}\n";
    }
    if ($commentContent !== '') {
        $body .= "评论内容：\n{$commentContent}\n";
    }
    $body .= "查看评论：{$url}{$anchor}\n";
    send_mail($recipientEmail, $subject, $body);
}

function create_email_code(string $email, string $ip): string {
    $pdo = db();
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $stmt = $pdo->prepare('INSERT INTO email_codes (email, code_hash, expires_at, created_at, ip)
        VALUES (:email, :code_hash, :expires_at, :created_at, :ip)');
    $stmt->execute([
        ':email' => $email,
        ':code_hash' => $hash,
        ':expires_at' => $expiresAt,
        ':created_at' => now(),
        ':ip' => $ip,
    ]);
    return $code;
}

function verify_email_code(string $email, string $code): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM email_codes WHERE email = :email AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1');
    $stmt->execute([
        ':email' => $email,
        ':now' => now(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$row) {
        return false;
    }
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $update = $pdo->prepare('UPDATE email_codes SET used_at = :used_at WHERE id = :id');
    $update->execute([':used_at' => now(), ':id' => $row['id']]);
    return true;
}

function create_reset_code(int $userId, string $email, string $ip): string {
    $pdo = db();
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 600);
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, email, code_hash, expires_at, created_at, ip)
        VALUES (:user_id, :email, :code_hash, :expires_at, :created_at, :ip)');
    $stmt->execute([
        ':user_id' => $userId,
        ':email' => $email,
        ':code_hash' => $hash,
        ':expires_at' => $expiresAt,
        ':created_at' => now(),
        ':ip' => $ip,
    ]);
    return $code;
}

function verify_reset_code(int $userId, string $email, string $code): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE user_id = :user_id AND email = :email AND used_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1');
    $stmt->execute([
        ':user_id' => $userId,
        ':email' => $email,
        ':now' => now(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$row) {
        return false;
    }
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $update = $pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
    $update->execute([':used_at' => now(), ':id' => $row['id']]);
    return true;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id']) || empty($_SESSION['password_hash'])) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $sessionHash = (string)($_SESSION['password_hash'] ?? '');
    if ($sessionHash === '' || !hash_equals((string)$row['password_hash'], $sessionHash)) {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    return $row;
}

function touch_user_activity(array $user): void {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    $last = trim((string)($user['last_active_at'] ?? ''));
    if ($last !== '') {
        $lastTs = strtotime($last);
        if ($lastTs && (time() - $lastTs) < 600) {
            return;
        }
    }
    if (!empty($_SESSION['last_active_touch'])) {
        $sessionTs = (int)$_SESSION['last_active_touch'];
        if ($sessionTs > 0 && (time() - $sessionTs) < 600) {
            return;
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET last_active_at = :ts WHERE id = :id');
    $stmt->execute([
        ':ts' => now(),
        ':id' => $userId,
    ]);
    $_SESSION['last_active_touch'] = time();
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    if ((int)$user['disabled'] === 1) {
        session_destroy();
        redirect('/login');
    }
    touch_user_activity($user);
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

function api_user_from_key(string $key): ?array {
    if ($key === '') {
        return null;
    }
    $prefix = substr($key, 0, 8);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE api_key_prefix = :prefix AND disabled = 0');
    $stmt->execute([':prefix' => $prefix]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        if (!empty($user['api_key_hash']) && password_verify($key, $user['api_key_hash'])) {
            return $user;
        }
    }
    return null;
}

function require_api_user(): array {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'bearer ') === 0) {
            $key = trim(substr($auth, 7));
        }
    }
    $user = api_user_from_key($key);
    if (!$user) {
        api_response(401, null, 'API Key 无效或已失效，请前往控制台重新生成');
    }
    touch_user_activity($user);
    return $user;
}

function api_response(int $status, $data, string $msg = ''): void {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code($status);
    echo json_encode([
        'code' => $status === 200 ? 0 : $status,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function share_url(string $slug): string {
    return base_url() . '/s/' . rawurlencode($slug);
}

function find_share_by_slug(string $slug): ?array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM shares WHERE slug = :slug AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_share_by_id(int $shareId): ?array {
    if ($shareId <= 0) {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function find_share_asset_row(int $shareId, string $assetPath): ?array {
    if ($shareId <= 0 || $assetPath === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT asset_path, file_path, size_bytes FROM share_assets WHERE share_id = :sid AND asset_path = :asset_path LIMIT 1');
    $stmt->execute([
        ':sid' => $shareId,
        ':asset_path' => $assetPath,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_active_announcements(): array {
    $pdo = db();
    $stmt = $pdo->query('SELECT * FROM announcements WHERE active = 1 ORDER BY created_at DESC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function render_announcements_html(): string {
    $items = get_active_announcements();
    if (empty($items)) {
        return '';
    }
    $html = '<div class="card"><h2>公告</h2><div class="list">';
    foreach ($items as $item) {
        $title = htmlspecialchars($item['title']);
        $content = nl2br((string)$item['content']);
        $html .= '<div class="list-item"><strong>' . $title . '</strong><div class="muted">' . $content . '</div></div>';
    }
    $html .= '</div></div>';
    return $html;
}

function should_show_announcement_modal(array $items): bool {
    if (empty($items)) {
        return false;
    }
    $today = date('Y-m-d');
    $hidden = $_COOKIE['announcement_hide_date'] ?? '';
    return $hidden !== $today;
}

function render_announcement_modal(array $items): string {
    if (empty($items)) {
        return '';
    }
    $html = '<div class="modal announcement-modal" data-announcement-modal="1">';
    $html .= '<div class="modal-backdrop" data-modal-close="1"></div>';
    $html .= '<div class="modal-card">';
    $html .= '<div class="modal-header">最新公告</div>';
    $html .= '<div class="modal-body">';
    foreach ($items as $item) {
        $title = htmlspecialchars($item['title']);
        $content = nl2br((string)$item['content']);
        $html .= '<div class="announcement-item"><div class="announcement-title">' . $title . '</div><div class="announcement-content">' . $content . '</div></div>';
    }
    $html .= '</div>';
    $html .= '<div class="modal-footer">';
    $html .= '<label class="checkbox"><input type="checkbox" data-announcement-hide> 今天不再弹出</label>';
    $html .= '<button class="button primary" data-modal-close="1">知道了</button>';
    $html .= '</div></div></div>';
    return $html;
}

function build_scan_item_link(array $meta): string {
    $itemType = (string)($meta['item_type'] ?? 'doc');
    $slug = (string)($meta['slug'] ?? '');
    if ($itemType === 'comment') {
        $commentId = (int)($meta['comment_id'] ?? 0);
        $commentEmail = (string)($meta['comment_email'] ?? '');
        $commentCreatedAt = (string)($meta['comment_created_at'] ?? '');
        $commentContent = (string)($meta['comment_content'] ?? '');
        $shareTitle = (string)($meta['share_title'] ?? '');
        $label = $commentId > 0 ? '评论#' . $commentId : '评论';
        $attrs = ' data-admin-comment-edit="1"'
            . ' data-admin-comment-id="' . $commentId . '"'
            . ' data-admin-comment-email="' . htmlspecialchars($commentEmail, ENT_QUOTES) . '"'
            . ' data-admin-comment-created="' . htmlspecialchars(format_share_datetime($commentCreatedAt), ENT_QUOTES) . '"'
            . ' data-admin-comment-share="' . htmlspecialchars($shareTitle, ENT_QUOTES) . '"'
            . ' data-admin-comment-content="' . htmlspecialchars($commentContent, ENT_QUOTES) . '"';
        return '<button type="button" class="scan-comment-link"' . $attrs . '>' . htmlspecialchars($label) . '</button>';
    }
    $docId = (string)($meta['doc_id'] ?? '');
    $docTitle = (string)($meta['doc_title'] ?? '');
    $docLabel = trim($docTitle) !== '' ? $docTitle : $docId;
    $docLabel = $docLabel !== '' ? '文档：' . $docLabel : '文档';
    $docUrl = '';
    if ($slug !== '') {
        $docUrl = $docId !== '' ? base_url() . build_share_redirect_path($slug, $docId, '') : share_url($slug);
    }
    if ($docUrl !== '') {
        return '<a class="scan-comment-link" href="' . htmlspecialchars($docUrl) . '" target="_blank">' . htmlspecialchars($docLabel) . '</a>';
    }
    return htmlspecialchars($docLabel);
}

function build_scan_log_entry(array $meta, ?array $hit): string {
    $prefix = $hit ? '命中违禁词 [' . htmlspecialchars((string)$hit['word']) . ']：' : '未命中：';
    $parts = [];
    $shareTitle = trim((string)($meta['share_title'] ?? ''));
    if ($shareTitle !== '') {
        $parts[] = htmlspecialchars($shareTitle);
    }
    $parts[] = build_scan_item_link($meta);
    $username = trim((string)($meta['username'] ?? ''));
    if ($username !== '') {
        $parts[] = htmlspecialchars($username);
    }
    if ((string)($meta['item_type'] ?? '') === 'comment') {
        $commentEmail = trim((string)($meta['comment_email'] ?? ''));
        if ($commentEmail !== '') {
            $parts[] = '邮箱：' . htmlspecialchars($commentEmail);
        }
        $commentCreatedAt = (string)($meta['comment_created_at'] ?? '');
        if ($commentCreatedAt !== '') {
            $parts[] = '时间：' . htmlspecialchars(format_share_datetime($commentCreatedAt));
        }
    } else {
        $hpath = trim((string)($meta['hpath'] ?? ''));
        if ($hpath !== '') {
            $parts[] = '路径：' . htmlspecialchars($hpath);
        }
    }
    return $prefix . implode(' / ', $parts);
}

function scan_banned_shares(array $words): array {
    if (empty($words)) {
        return [];
    }
    $pdo = db();
    $sql = 'SELECT shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug, shares.user_id,
        users.username, share_docs.doc_id, share_docs.title AS doc_title, share_docs.hpath, share_docs.markdown
        FROM share_docs
        JOIN shares ON share_docs.share_id = shares.id
        JOIN users ON shares.user_id = users.id
        WHERE shares.deleted_at IS NULL
        ORDER BY shares.updated_at DESC';
    $stmt = $pdo->query($sql);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $markdown = (string)($row['markdown'] ?? '');
        if ($markdown === '') {
            continue;
        }
        $hit = find_banned_word($markdown, $words);
        if (!$hit) {
            continue;
        }
        $results[] = [
            'item_type' => 'doc',
            'share_id' => (int)$row['share_id'],
            'share_title' => $row['share_title'],
            'share_type' => $row['type'],
            'slug' => $row['slug'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'doc_id' => $row['doc_id'],
            'doc_title' => $row['doc_title'],
            'hpath' => $row['hpath'],
            'word' => $hit['word'],
            'snippet' => extract_snippet($markdown, $hit['word']),
        ];
    }
    $commentSql = 'SELECT share_comments.id AS comment_id, share_comments.email AS comment_email, share_comments.content AS comment_content,
        share_comments.created_at AS comment_created_at, shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug,
        shares.user_id, users.username
        FROM share_comments
        JOIN shares ON share_comments.share_id = shares.id
        JOIN users ON shares.user_id = users.id
        WHERE shares.deleted_at IS NULL
        ORDER BY share_comments.created_at DESC, share_comments.id DESC';
    $commentStmt = $pdo->query($commentSql);
    foreach ($commentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $commentContent = (string)($row['comment_content'] ?? '');
        if ($commentContent === '') {
            continue;
        }
        $hit = find_banned_word($commentContent, $words);
        if (!$hit) {
            continue;
        }
        $results[] = [
            'item_type' => 'comment',
            'share_id' => (int)$row['share_id'],
            'share_title' => $row['share_title'],
            'share_type' => $row['type'],
            'slug' => $row['slug'],
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'doc_id' => null,
            'doc_title' => null,
            'hpath' => null,
            'comment_id' => (int)$row['comment_id'],
            'comment_email' => $row['comment_email'],
            'comment_created_at' => $row['comment_created_at'],
            'comment_content' => $commentContent,
            'word' => $hit['word'],
            'snippet' => extract_snippet($commentContent, $hit['word']),
        ];
    }
    return $results;
}

function count_scannable_share_docs(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_docs JOIN shares ON share_docs.share_id = shares.id WHERE shares.deleted_at IS NULL');
    return $stmt ? (int)$stmt->fetchColumn() : 0;
}

function count_scannable_comments(): int {
    $pdo = db();
    $stmt = $pdo->query('SELECT COUNT(*) FROM share_comments JOIN shares ON share_comments.share_id = shares.id WHERE shares.deleted_at IS NULL');
    return $stmt ? (int)$stmt->fetchColumn() : 0;
}

function count_scannable_docs(): int {
    return count_scannable_share_docs() + count_scannable_comments();
}

function scan_banned_shares_batch(array $words, int $offset, int $limit): array {
    if (empty($words)) {
        return ['hits' => [], 'logs' => [], 'count' => 0];
    }
    $hits = [];
    $logs = [];
    $count = 0;
    $docCount = count_scannable_share_docs();
    $remaining = $limit;
    $docOffset = $offset;
    if ($docOffset < $docCount) {
        $docLimit = min($remaining, $docCount - $docOffset);
        $pdo = db();
        $sql = 'SELECT shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug, shares.user_id,
            users.username, share_docs.doc_id, share_docs.title AS doc_title, share_docs.hpath, share_docs.markdown
            FROM share_docs
            JOIN shares ON share_docs.share_id = shares.id
            JOIN users ON shares.user_id = users.id
            WHERE shares.deleted_at IS NULL
            ORDER BY share_docs.id ASC
            LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $docLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $docOffset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count += count($rows);
        foreach ($rows as $row) {
            $markdown = (string)($row['markdown'] ?? '');
            $meta = [
                'item_type' => 'doc',
                'share_title' => $row['share_title'],
                'slug' => $row['slug'],
                'doc_id' => $row['doc_id'],
                'doc_title' => $row['doc_title'],
                'hpath' => $row['hpath'],
                'username' => $row['username'],
            ];
            $hit = $markdown !== '' ? find_banned_word($markdown, $words) : null;
            if ($hit) {
                $hits[] = [
                    'item_type' => 'doc',
                    'share_id' => (int)$row['share_id'],
                    'share_title' => $row['share_title'],
                    'share_type' => $row['type'],
                    'slug' => $row['slug'],
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'doc_id' => $row['doc_id'],
                    'doc_title' => $row['doc_title'],
                    'hpath' => $row['hpath'],
                    'word' => $hit['word'],
                    'snippet' => extract_snippet($markdown, $hit['word']),
                ];
                $logs[] = build_scan_log_entry($meta, $hit);
            } else {
                $logs[] = build_scan_log_entry($meta, null);
            }
        }
        $remaining -= count($rows);
    }
    if ($remaining > 0) {
        $commentOffset = max(0, $offset - $docCount);
        $pdo = $pdo ?? db();
        $sql = 'SELECT share_comments.id AS comment_id, share_comments.email AS comment_email, share_comments.content AS comment_content,
            share_comments.created_at AS comment_created_at, shares.id AS share_id, shares.title AS share_title, shares.type, shares.slug,
            shares.user_id, users.username
            FROM share_comments
            JOIN shares ON share_comments.share_id = shares.id
            JOIN users ON shares.user_id = users.id
            WHERE shares.deleted_at IS NULL
            ORDER BY share_comments.id ASC
            LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $remaining, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $commentOffset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count += count($rows);
        foreach ($rows as $row) {
            $commentContent = (string)($row['comment_content'] ?? '');
            $commentId = (int)($row['comment_id'] ?? 0);
            $commentEmail = trim((string)($row['comment_email'] ?? ''));
            $meta = [
                'item_type' => 'comment',
                'share_title' => $row['share_title'],
                'slug' => $row['slug'],
                'username' => $row['username'],
                'comment_id' => $commentId,
                'comment_email' => $commentEmail,
                'comment_created_at' => $row['comment_created_at'],
                'comment_content' => $commentContent,
            ];
            $hit = $commentContent !== '' ? find_banned_word($commentContent, $words) : null;
            if ($hit) {
                $hits[] = [
                    'item_type' => 'comment',
                    'share_id' => (int)$row['share_id'],
                    'share_title' => $row['share_title'],
                    'share_type' => $row['type'],
                    'slug' => $row['slug'],
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'doc_id' => null,
                    'doc_title' => null,
                    'hpath' => null,
                    'comment_id' => $commentId,
                    'comment_email' => $commentEmail,
                    'comment_created_at' => $row['comment_created_at'],
                    'comment_content' => $commentContent,
                    'word' => $hit['word'],
                    'snippet' => extract_snippet($commentContent, $hit['word']),
                ];
                $logs[] = build_scan_log_entry($meta, $hit);
            } else {
                $logs[] = build_scan_log_entry($meta, null);
            }
        }
    }
    return ['hits' => $hits, 'logs' => $logs, 'count' => $count];
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function remove_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            remove_dir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function move_dir(string $source, string $target): bool {
    if (!is_dir($source)) {
        return false;
    }
    if (is_dir($target)) {
        remove_dir($target);
    }
    ensure_dir(dirname($target));
    if (@rename($source, $target)) {
        return true;
    }
    ensure_dir($target);
    $items = scandir($source);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $target . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) {
            move_dir($src, $dst);
        } else {
            @copy($src, $dst);
        }
    }
    remove_dir($source);
    return true;
}

function chunk_cleanup_settings(): array {
    global $config;
    $ttl = (int)($config['chunk_ttl_seconds'] ?? 7200);
    $prob = (float)($config['chunk_cleanup_probability'] ?? 0.05);
    $limit = (int)($config['chunk_cleanup_limit'] ?? 20);
    if ($ttl < 60) {
        $ttl = 60;
    }
    if ($prob < 0) {
        $prob = 0;
    } elseif ($prob > 1) {
        $prob = 1;
    }
    if ($limit < 1) {
        $limit = 20;
    }
    return [$ttl, $prob, $limit];
}

function latest_mtime(string $path): int {
    if (is_file($path)) {
        return (int)(@filemtime($path) ?: 0);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $items = scandir($path);
    if ($items === false) {
        return (int)(@filemtime($path) ?: 0);
    }
    $latest = 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        $mtime = latest_mtime($child);
        if ($mtime > $latest) {
            $latest = $mtime;
        }
    }
    if ($latest <= 0) {
        $latest = (int)(@filemtime($path) ?: 0);
    }
    return $latest;
}

function cleanup_stale_dirs(string $base, int $ttl, int $limit): void {
    if (!is_dir($base)) {
        return;
    }
    $dirs = scandir($base);
    if ($dirs === false) {
        return;
    }
    $now = time();
    $deleted = 0;
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        $path = $base . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $mtime = latest_mtime($path);
        if ($mtime <= 0 || ($now - $mtime) < $ttl) {
            continue;
        }
        remove_dir($path);
        $deleted += 1;
        if ($deleted >= $limit) {
            break;
        }
    }
}

function maybe_cleanup_chunks(): void {
    global $config;
    [$ttl, $prob, $limit] = chunk_cleanup_settings();
    if ($prob <= 0 || $ttl <= 0 || $limit <= 0) {
        return;
    }
    $rand = mt_rand() / mt_getrandmax();
    if ($rand > $prob) {
        return;
    }
    cleanup_stale_dirs($config['uploads_dir'] . '/chunks', $ttl, $limit);
    cleanup_stale_dirs($config['uploads_dir'] . '/staging', $ttl, $limit);
}

function list_stale_chunks(int $ttl): array {
    global $config;
    $base = $config['uploads_dir'] . '/chunks';
    if (!is_dir($base)) {
        return [];
    }
    $dirs = scandir($base);
    if ($dirs === false) {
        return [];
    }
    $now = time();
    $rows = [];
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        $path = $base . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $mtime = latest_mtime($path);
        if ($mtime <= 0) {
            continue;
        }
        $age = $now - $mtime;
        if ($age < $ttl) {
            continue;
        }
        $rows[] = [
            'id' => $dir,
            'mtime' => $mtime,
            'age' => $age,
        ];
    }
    usort($rows, function ($a, $b) {
        return $a['mtime'] <=> $b['mtime'];
    });
    return $rows;
}

function sanitize_asset_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    return $path;
}

function sanitize_upload_id(string $uploadId): string {
    $uploadId = trim($uploadId);
    if ($uploadId === '' || !preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        return '';
    }
    return $uploadId;
}

function collect_asset_entries(array $files, array $paths, array $docIds): array {
    $entries = [];
    $seen = [];
    if (empty($files['name'])) {
        return $entries;
    }
    $count = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        $tmp = $files['tmp_name'][$i] ?? '';
        if ($tmp === '') {
            continue;
        }
        $assetPath = $paths[$i] ?? ($files['name'][$i] ?? '');
        $assetPath = sanitize_asset_path((string)$assetPath);
        if ($assetPath === '' || isset($seen[$assetPath])) {
            continue;
        }
        $seen[$assetPath] = true;
        $entries[] = [
            'tmp' => $tmp,
            'path' => $assetPath,
            'docId' => $docIds[$i] ?? null,
            'size' => (int)($files['size'][$i] ?? 0),
        ];
    }
    return $entries;
}

function allocate_share_id(PDO $pdo): int {
    $stmt = $pdo->query('SELECT share_id FROM recycled_share_ids ORDER BY share_id ASC LIMIT 1');
    $shareId = (int)($stmt ? $stmt->fetchColumn() : 0);
    if ($stmt) {
        $stmt->closeCursor();
    }
    if ($shareId > 0) {
        $del = $pdo->prepare('DELETE FROM recycled_share_ids WHERE share_id = :share_id');
        $del->execute([':share_id' => $shareId]);
        return $shareId;
    }
    return 0;
}

function recycle_share_id(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO recycled_share_ids (share_id, created_at) VALUES (:share_id, :created_at)');
    $stmt->execute([
        ':share_id' => $shareId,
        ':created_at' => now(),
    ]);
}

function purge_share_assets(int $shareId, bool $keepCommentFiles = false): void {
    global $config;
    $pdo = db();
    $dir = $config['uploads_dir'] . '/shares/' . $shareId;
    remove_dir($dir);
    if (!$keepCommentFiles) {
        $commentDir = $config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/') . '/' . $shareId;
        remove_dir($commentDir);
        $stmt = $pdo->prepare('DELETE FROM share_assets WHERE share_id = :share_id');
        $stmt->execute([':share_id' => $shareId]);
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM share_assets WHERE share_id = :share_id AND asset_path NOT LIKE :prefix');
    $stmt->execute([
        ':share_id' => $shareId,
        ':prefix' => comment_asset_prefix() . '%',
    ]);
}

function hard_delete_share(int $shareId): ?int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $stmt->execute([':id' => $shareId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$row) {
        return null;
    }
    $userId = (int)$row['user_id'];
    purge_share_assets($shareId);
    purge_share_chunks($shareId);
    purge_share_access_logs($shareId);
    $uploadIds = $pdo->prepare('SELECT upload_id FROM share_uploads WHERE share_id = :share_id');
    $uploadIds->execute([':share_id' => $shareId]);
    $uploadList = array_values(array_filter($uploadIds->fetchAll(PDO::FETCH_COLUMN)));
    if ($uploadList) {
        $placeholders = implode(',', array_fill(0, count($uploadList), '?'));
        $stmt = $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id IN (' . $placeholders . ')');
        $stmt->execute($uploadList);
    }
    $pdo->prepare('DELETE FROM share_uploads WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_comments WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_reports WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_visitors WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
    $pdo->prepare('DELETE FROM shares WHERE id = :id')->execute([':id' => $shareId]);
    recycle_share_id($shareId);
    recalculate_user_storage($userId);
    return $userId;
}

function delete_user_account(int $userId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT email, role FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return false;
    }
    $shareStmt = $pdo->prepare('SELECT id FROM shares WHERE user_id = :user_id');
    $shareStmt->execute([':user_id' => $userId]);
    $shareIds = $shareStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($shareIds as $shareId) {
        hard_delete_share((int)$shareId);
    }
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')->execute([':user_id' => $userId]);
    $pdo->prepare('UPDATE announcements SET created_by = NULL WHERE created_by = :user_id')->execute([':user_id' => $userId]);
    $email = trim((string)($user['email'] ?? ''));
    if ($email !== '') {
        $pdo->prepare('DELETE FROM email_codes WHERE email = :email')->execute([':email' => $email]);
    }
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
    return true;
}

function reset_database(): void {
    global $config;
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = OFF;');
    $tables = $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!$table) {
            continue;
        }
        $pdo->exec('DELETE FROM "' . $table . '"');
    }
    $pdo->exec('DELETE FROM sqlite_sequence');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    seed_default_settings($pdo);
    seed_default_admin($pdo);
    remove_dir($config['uploads_dir']);
    ensure_dir($config['uploads_dir']);
    ensure_dir($config['uploads_dir'] . '/shares');
    ensure_dir($config['uploads_dir'] . '/' . trim(comment_asset_prefix(), '/'));
}

function handle_asset_uploads(int $shareId, array $entries): int {
    global $config;
    if (empty($entries)) {
        return 0;
    }
    ensure_dir($config['uploads_dir']);
    $pdo = db();
    $total = 0;
    foreach ($entries as $entry) {
        $tmp = $entry['tmp'];
        $assetPath = (string)($entry['path'] ?? '');
        $assetPath = trim(str_replace('\\', '/', $assetPath));
        $assetPath = ltrim($assetPath, '/');
        if ($assetPath === '' || substr($assetPath, -1) === '/') {
            continue;
        }
        if (!is_uploaded_file($tmp)) {
            continue;
        }
        $docId = $entry['docId'] ?? null;
        $size = (int)($entry['size'] ?? 0);
        $targetDir = $config['uploads_dir'] . '/shares/' . $shareId . '/' . dirname($assetPath);
        ensure_dir($targetDir);
        $targetFile = $config['uploads_dir'] . '/shares/' . $shareId . '/' . $assetPath;
        if (!move_uploaded_file($tmp, $targetFile)) {
            continue;
        }
        $actualSize = $size;
        if ($actualSize <= 0 && is_file($targetFile)) {
            $actualSize = (int)filesize($targetFile);
        }
        $assetHash = '';
        if (is_file($targetFile)) {
            $computed = @hash_file('sha256', $targetFile);
            $assetHash = normalize_hash_hex($computed ?: '');
        }
        $total += $actualSize;
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
            VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
        $stmt->execute([
            ':share_id' => $shareId,
            ':doc_id' => $docId,
            ':asset_path' => $assetPath,
            ':file_path' => 'shares/' . $shareId . '/' . $assetPath,
            ':size_bytes' => $actualSize,
            ':asset_hash' => $assetHash !== '' ? $assetHash : null,
            ':created_at' => now(),
        ]);
    }
    return $total;
}

function normalize_hash_hex($value): string {
    $raw = strtolower(trim((string)$value));
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return '';
    }
    return $raw;
}

function normalize_sort_index_value($value): float {
    if (!is_numeric($value)) {
        return 0;
    }
    $num = (float)$value;
    if (is_infinite($num) || is_nan($num)) {
        return 0;
    }
    return round($num, 6);
}

function build_doc_meta_signature(array $row): string {
    $payload = [
        'title' => (string)($row['title'] ?? ''),
        'hPath' => (string)($row['hPath'] ?? $row['hpath'] ?? ''),
        'parentId' => (string)($row['parentId'] ?? $row['parent_id'] ?? ''),
        'sortIndex' => normalize_sort_index_value($row['sortIndex'] ?? $row['sort_index'] ?? 0),
        'sortOrder' => max(0, (int)($row['sortOrder'] ?? $row['sort_order'] ?? 0)),
        'icon' => normalize_doc_icon_value($row['icon'] ?? ''),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

function compute_doc_content_hash(string $markdown): string {
    return hash('sha256', $markdown);
}

function compute_doc_meta_hash(array $row): string {
    return hash('sha256', build_doc_meta_signature($row));
}

function normalize_doc_id_list($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $docId = trim((string)$item);
        if ($docId === '' || isset($seen[$docId])) {
            continue;
        }
        $seen[$docId] = true;
        $out[] = $docId;
    }
    return $out;
}

function normalize_asset_path_list($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $path = sanitize_asset_path((string)$item);
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $out[] = $path;
    }
    return $out;
}

function normalize_incremental_patch($raw): array {
    if (!is_array($raw)) {
        return ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []];
    }
    return [
        'enabled' => !empty($raw['enabled']),
        'deletedDocIds' => normalize_doc_id_list($raw['deletedDocIds'] ?? []),
        'deletedAssetPaths' => normalize_asset_path_list($raw['deletedAssetPaths'] ?? []),
    ];
}

function normalize_asset_manifest($rawAssets): array {
    $assets = [];
    $seen = [];
    if (!is_array($rawAssets)) {
        return $assets;
    }
    foreach ($rawAssets as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = sanitize_asset_path((string)($item['path'] ?? ''));
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $size = (int)($item['size'] ?? 0);
        if ($size < 0) {
            $size = 0;
        }
        $docId = isset($item['docId']) ? trim((string)($item['docId'])) : null;
        $hash = normalize_hash_hex($item['hash'] ?? '');
        $assets[] = [
            'path' => $path,
            'size' => $size,
            'docId' => $docId,
            'hash' => $hash,
        ];
    }
    return $assets;
}

function doc_chunk_prefix(): string {
    return '__sps_docs/';
}

function is_doc_chunk_path(string $path): bool {
    return str_starts_with($path, doc_chunk_prefix());
}

function normalize_doc_manifest($rawDocs): array {
    $docs = [];
    $seenPath = [];
    $seenDoc = [];
    if (!is_array($rawDocs)) {
        return $docs;
    }
    foreach ($rawDocs as $item) {
        if (!is_array($item)) {
            continue;
        }
        $docId = trim((string)($item['docId'] ?? ''));
        $path = sanitize_asset_path((string)($item['path'] ?? ''));
        if ($docId === '' || $path === '' || !is_doc_chunk_path($path)) {
            continue;
        }
        if (isset($seenPath[$path]) || isset($seenDoc[$docId])) {
            continue;
        }
        $seenPath[$path] = true;
        $seenDoc[$docId] = true;
        $size = (int)($item['size'] ?? 0);
        if ($size < 0) {
            $size = 0;
        }
        $docs[] = [
            'docId' => $docId,
            'path' => $path,
            'size' => $size,
            'hash' => normalize_hash_hex($item['hash'] ?? ''),
        ];
    }
    return $docs;
}

function share_chunks_dir(int $shareId): string {
    global $config;
    return $config['uploads_dir'] . '/chunks/' . $shareId;
}

function upload_chunks_dir(string $uploadId): string {
    global $config;
    return $config['uploads_dir'] . '/chunks/' . $uploadId;
}

function upload_staging_dir(string $uploadId): string {
    global $config;
    return $config['uploads_dir'] . '/staging/' . $uploadId;
}

function purge_upload_session_files(string $uploadId): void {
    remove_dir(upload_chunks_dir($uploadId));
    remove_dir(upload_staging_dir($uploadId));
}

function generate_upload_id(): string {
    return bin2hex(random_bytes(16));
}

function purge_share_chunks(int $shareId): void {
    $dir = share_chunks_dir($shareId);
    remove_dir($dir);
}

function recalculate_share_size(int $shareId): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_docs WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $docSize = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_assets WHERE share_id = :share_id');
    $stmt->execute([':share_id' => $shareId]);
    $assetSize = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    $commentSize = share_comment_size($shareId);
    $total = $docSize + $assetSize + $commentSize;
    $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':size_bytes' => $total,
        ':updated_at' => now(),
        ':id' => $shareId,
    ]);
    return $total;
}

function handle_instance_heartbeat(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, ['ok' => true], '');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_response(405, null, 'Method Not Allowed');
    }
    $payload = parse_json_body();
    $instanceId = trim((string)($payload['instance_id'] ?? ''));
    if ($instanceId === '') {
        api_response(400, null, 'Missing instance_id');
    }
    $version = trim((string)($payload['version'] ?? ''));
    $now = now();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO instance_heartbeats (instance_id, first_seen, last_seen, version, ip)
        VALUES (:id, :first_seen, :last_seen, :version, :ip)
        ON CONFLICT(instance_id) DO UPDATE SET last_seen = :last_seen, version = :version, ip = :ip');
    $stmt->execute([
        ':id' => $instanceId,
        ':first_seen' => $now,
        ':last_seen' => $now,
        ':version' => $version,
        ':ip' => get_client_ip(),
    ]);
    api_response(200, ['instance_id' => $instanceId]);
}

function handle_instance_stats(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, ['ok' => true], '');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_response(405, null, 'Method Not Allowed');
    }
    $pdo = db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM instance_heartbeats')->fetchColumn();
    $cutoff30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $cutoff7 = date('Y-m-d H:i:s', strtotime('-7 days'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM instance_heartbeats WHERE last_seen >= :cutoff');
    $stmt->execute([':cutoff' => $cutoff30]);
    $active30 = (int)$stmt->fetchColumn();
    $stmt->execute([':cutoff' => $cutoff7]);
    $active7 = (int)$stmt->fetchColumn();
    api_response(200, [
        'total' => $total,
        'active_30' => $active30,
        'active_7' => $active7,
        'updated_at' => now(),
    ]);
}

function handle_api(string $path): void {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        api_response(200, null, '');
    }
    $user = require_api_user();
    $pdo = db();
    global $config;
    maybe_cleanup_chunks();

    if ($path === '/api/v1/auth/verify') {
        [$minChunk, $maxChunk] = chunk_size_limits();
        api_response(200, ['user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ], 'limits' => [
            'minChunkSize' => $minChunk,
            'maxChunkSize' => $maxChunk,
        ], 'features' => [
            'incrementalShare' => true,
            'docChunkUpload' => true,
        ]]);
    }

    if ($path === '/api/v1/shares' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT shares.*, COALESCE(doc_counts.doc_count, 0) AS doc_count
            FROM shares
            LEFT JOIN (SELECT share_id, COUNT(*) AS doc_count FROM share_docs GROUP BY share_id) doc_counts
                ON shares.id = doc_counts.share_id
            WHERE shares.user_id = :uid AND shares.deleted_at IS NULL
            ORDER BY shares.updated_at DESC');
        $stmt->execute([':uid' => $user['id']]);
        $shares = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $shares[] = [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'type' => $row['type'],
                'title' => $row['title'],
                'docId' => $row['doc_id'],
                'notebookId' => $row['notebook_id'],
                'updatedAt' => strtotime($row['updated_at']) * 1000,
                'createdAt' => strtotime($row['created_at']) * 1000,
                'hasPassword' => !empty($row['password_hash']),
                'expiresAt' => $row['expires_at'] ? ((int)$row['expires_at'] * 1000) : null,
                'visitorLimit' => (int)($row['visitor_limit'] ?? 0),
                'includeChildren' => ((string)($row['type'] ?? '') === 'doc') && ((int)($row['doc_count'] ?? 0) > 1),
                'announcementMode' => (string)($row['announcement_mode'] ?? 'none'),
                'announcementTitle' => (string)($row['announcement_title'] ?? ''),
                'announcementContent' => (string)($row['announcement_content'] ?? ''),
                'announcementDocId' => (string)($row['announcement_doc_id'] ?? ''),
                'path' => '/s/' . $row['slug'],
                'url' => share_url($row['slug']),
            ];
        }
        api_response(200, ['shares' => $shares]);
    }

    if ($path === '/api/v1/shares/snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        if ($shareId <= 0) {
            api_response(400, null, 'Missing share id');
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$share) {
            api_response(404, null, 'Share not found');
        }

        $docs = [];
        $docStmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, size_bytes, content_hash, meta_hash
            FROM share_docs WHERE share_id = :sid ORDER BY sort_order ASC, id ASC');
        $docStmt->execute([':sid' => $shareId]);
        // 先取完并关闭 SELECT 游标再写：循环内持有读快照时执行 UPDATE 属"读升级写"，
        // 撞上其它写者会立即 database is locked（busy_timeout 不生效）。
        $docRows = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        $docStmt->closeCursor();
        $docHashUpdate = $pdo->prepare('UPDATE share_docs SET content_hash = :content_hash, meta_hash = :meta_hash WHERE id = :id');
        $docMarkdownStmt = $pdo->prepare('SELECT markdown FROM share_docs WHERE share_id = :sid AND id = :id LIMIT 1');
        foreach ($docRows as $row) {
            $contentHash = normalize_hash_hex($row['content_hash'] ?? '');
            $metaHash = normalize_hash_hex($row['meta_hash'] ?? '');
            if ($contentHash === '') {
                $docMarkdownStmt->execute([
                    ':sid' => $shareId,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
                $docMarkdown = (string)($docMarkdownStmt->fetchColumn() ?: '');
                $docMarkdownStmt->closeCursor();
                $contentHash = compute_doc_content_hash($docMarkdown);
                unset($docMarkdown);
            }
            if ($metaHash === '') {
                $metaHash = compute_doc_meta_hash($row);
            }
            if (($row['content_hash'] ?? '') !== $contentHash || ($row['meta_hash'] ?? '') !== $metaHash) {
                $docHashUpdate->execute([
                    ':content_hash' => $contentHash,
                    ':meta_hash' => $metaHash,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
            }
            $docs[] = [
                'docId' => (string)($row['doc_id'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'icon' => (string)($row['icon'] ?? ''),
                'hPath' => (string)($row['hpath'] ?? ''),
                'parentId' => (string)($row['parent_id'] ?? ''),
                'sortIndex' => (float)($row['sort_index'] ?? 0),
                'sortOrder' => max(0, (int)($row['sort_order'] ?? 0)),
                'contentHash' => $contentHash,
                'metaHash' => $metaHash,
            ];
            unset($row);
        }

        $assets = [];
        $assetStmt = $pdo->prepare('SELECT id, asset_path, doc_id, file_path, size_bytes, asset_hash FROM share_assets
            WHERE share_id = :sid AND asset_path NOT LIKE :prefix ORDER BY id ASC');
        $assetStmt->execute([
            ':sid' => $shareId,
            ':prefix' => comment_asset_prefix() . '%',
        ]);
        $assetRows = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
        $assetStmt->closeCursor();
        $assetHashUpdate = $pdo->prepare('UPDATE share_assets SET asset_hash = :asset_hash WHERE id = :id');
        foreach ($assetRows as $row) {
            $assetHash = normalize_hash_hex($row['asset_hash'] ?? '');
            if ($assetHash === '') {
                $filePath = (string)($row['file_path'] ?? '');
                $fullPath = $config['uploads_dir'] . '/' . ltrim($filePath, '/');
                if ($filePath !== '' && is_file($fullPath)) {
                    $computed = @hash_file('sha256', $fullPath);
                    $assetHash = normalize_hash_hex($computed ?: '');
                }
            }
            if ($assetHash !== '' && ($row['asset_hash'] ?? '') !== $assetHash) {
                $assetHashUpdate->execute([
                    ':asset_hash' => $assetHash,
                    ':id' => (int)($row['id'] ?? 0),
                ]);
            }
            $assets[] = [
                'path' => (string)($row['asset_path'] ?? ''),
                'docId' => (string)($row['doc_id'] ?? ''),
                'size' => max(0, (int)($row['size_bytes'] ?? 0)),
                'hash' => $assetHash,
            ];
            unset($row);
        }

        api_response(200, [
            'share' => [
                'id' => (int)($share['id'] ?? 0),
                'type' => (string)($share['type'] ?? ''),
                'docId' => (string)($share['doc_id'] ?? ''),
                'notebookId' => (string)($share['notebook_id'] ?? ''),
            ],
            'docs' => $docs,
            'assets' => $assets,
        ]);
    }

    if ($path === '/api/v1/shares/delete') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        $hardDelete = !empty($payload['hardDelete']);
        if (!$shareId) {
            api_response(400, null, '缺少分享 ID');
        }
        if ($hardDelete) {
            $check = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid');
            $check->execute([
                ':id' => $shareId,
                ':uid' => $user['id'],
            ]);
            $hardExists = (bool)$check->fetchColumn();
            $check->closeCursor();
            if (!$hardExists) {
                api_response(404, null, '分享不存在');
            }
            hard_delete_share($shareId);
            api_response(200, ['ok' => true, 'hard' => true]);
        }
        $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND user_id = :uid');
        $stmt->execute([
            ':deleted_at' => now(),
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        purge_share_access_logs($shareId);
        recalculate_user_storage((int)$user['id']);
        api_response(200, ['ok' => true]);
    }

    if ($path === '/api/v1/shares/access/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $shareId = (int)($payload['shareId'] ?? 0);
        if (!$shareId) {
            api_response(400, null, 'Missing share id');
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([
            ':id' => $shareId,
            ':uid' => $user['id'],
        ]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$share) {
            api_response(404, null, 'Share not found');
        }
        $password = trim((string)($payload['password'] ?? ''));
        $clearPassword = !empty($payload['clearPassword']);
        $expiresAt = parse_expires_at($payload['expiresAt'] ?? null);
        $clearExpires = !empty($payload['clearExpires']);
        $visitorLimit = parse_visitor_limit($payload['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($payload['clearVisitorLimit']);
        $slug = parse_requested_share_slug_or_fail($payload['slug'] ?? '');

        $passwordHash = $share['password_hash'] ?? null;
        $expiresValue = isset($share['expires_at']) ? (int)$share['expires_at'] : null;
        $visitorValue = isset($share['visitor_limit']) ? (int)$share['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }
        $newSlug = (string)($share['slug'] ?? '');
        if ($slug !== '' && $slug !== $newSlug) {
            ensure_share_slug_available_or_fail($pdo, $slug, (int)$share['id']);
            $newSlug = $slug;
        }

        $annSql = '';
        $annParams = [];
        $annMode = (string)($share['announcement_mode'] ?? 'none');
        if (array_key_exists('announcementMode', $payload)) {
            $annMode = (string)($payload['announcementMode'] ?? 'none');
            if (!in_array($annMode, ['none', 'manual', 'doc'], true)) {
                $annMode = 'none';
            }
            $annTitle = trim((string)($payload['announcementTitle'] ?? ''));
            $annContent = trim((string)($payload['announcementContent'] ?? ''));
            $annDocId = trim((string)($payload['announcementDocId'] ?? ''));
            if ($annTitle === '__KEEP__') {
                $annTitle = (string)($share['announcement_title'] ?? '');
            }
            if ($annContent === '__KEEP__') {
                $annContent = (string)($share['announcement_content'] ?? '');
            }
            if ($annDocId === '__KEEP__') {
                $annDocId = (string)($share['announcement_doc_id'] ?? '');
            }
            $annSql = ', announcement_mode = :ann_mode, announcement_title = :ann_title, announcement_content = :ann_content, announcement_doc_id = :ann_doc_id';
            $annParams = [
                ':ann_mode' => $annMode,
                ':ann_title' => $annTitle,
                ':ann_content' => $annContent,
                ':ann_doc_id' => $annDocId,
            ];
        }

        $stmt = $pdo->prepare('UPDATE shares SET slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit' . $annSql . ', updated_at = :updated_at WHERE id = :id AND user_id = :uid');
        $stmt->execute(array_merge([
            ':slug' => $newSlug,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':updated_at' => now(),
            ':id' => $shareId,
            ':uid' => $user['id'],
        ], $annParams));
        if ($visitorValue > 0) {
            seed_share_visitors_from_logs($shareId);
        }
        api_response(200, ['share' => [
            'id' => (int)$share['id'],
            'slug' => $newSlug,
            'url' => share_url($newSlug),
            'hasPassword' => !empty($passwordHash),
            'expiresAt' => $expiresValue ? ($expiresValue * 1000) : null,
            'visitorLimit' => $visitorValue,
            'announcementMode' => $annMode,
        ]]);
    }

    if ($path === '/api/v1/shares/doc/init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $meta = $payload['metadata'] ?? $payload;
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid metadata');
        }
        $docId = trim((string)($meta['docId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $markdown = (string)($meta['markdown'] ?? '');
        $hPath = (string)($meta['hPath'] ?? '');
        $sortOrder = max(0, (int)($meta['sortOrder'] ?? 0));
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $docs = $meta['docs'] ?? [];
        $hasDocs = is_array($docs) && count($docs) > 0;
        $incrementalPatch = normalize_incremental_patch($meta['incremental'] ?? []);
        $docChunks = normalize_doc_manifest($payload['docChunks'] ?? []);
        $docChunkById = [];
        foreach ($docChunks as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }
        $hasDocChunks = !empty($docChunkById);
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "doc" AND doc_id = :doc_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':doc_id' => $docId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $useIncremental = !empty($incrementalPatch['enabled']) && !!$existing;
        if ($docId === '' || (!$hasDocs && $markdown === '' && !$hasDocChunks && !$useIncremental)) {
            api_response(400, null, 'Missing document content');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            if ($hasDocs) {
                foreach ($docs as $doc) {
                    $docMarkdown = (string)($doc['markdown'] ?? '');
                    if ($docMarkdown === '') {
                        continue;
                    }
                    $hit = find_banned_word($docMarkdown, $bannedWords);
                    if ($hit) {
                        $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                        api_response(400, null, '触发违禁词：' . $hit['word'] . '（文档：' . $docTitle . '）');
                    }
                }
            } else {
                $hit = find_banned_word($markdown, $bannedWords);
                if ($hit) {
                    api_response(400, null, '触发违禁词：' . $hit['word']);
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $assets = normalize_asset_manifest($payload['assets'] ?? []);
        $assetSize = 0;
        foreach ($assets as $asset) {
            $assetSize += (int)($asset['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        if ($hasDocs) {
            foreach ($docs as $index => $doc) {
                $rowDocId = trim((string)($doc['docId'] ?? ''));
                $rowTitle = trim((string)($doc['title'] ?? ''));
                $rowIcon = trim((string)($doc['icon'] ?? ''));
                $rowHpath = (string)($doc['hPath'] ?? '');
                $rowMarkdown = (string)($doc['markdown'] ?? '');
                $rowSort = max(0, (int)($doc['sortOrder'] ?? $index));
                $rowParent = trim((string)($doc['parentId'] ?? ''));
                $rowSortIndex = (float)($doc['sortIndex'] ?? $index);
                if ($rowDocId === '') {
                    continue;
                }
                $chunkMeta = $docChunkById[$rowDocId] ?? null;
                $size = strlen($rowMarkdown);
                if ($size === 0 && $chunkMeta) {
                    $size = max(0, (int)($chunkMeta['size'] ?? 0));
                }
                $rowContentHash = normalize_hash_hex($doc['contentHash'] ?? '');
                if ($rowContentHash === '' && $chunkMeta) {
                    $rowContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
                }
                if ($rowContentHash === '') {
                    $rowContentHash = ($rowMarkdown !== '' || !$chunkMeta)
                        ? compute_doc_content_hash($rowMarkdown)
                        : '';
                }
                $rowMetaHash = normalize_hash_hex($doc['metaHash'] ?? '');
                if ($rowMetaHash === '') {
                    $rowMetaHash = compute_doc_meta_hash([
                        'title' => $rowTitle ?: $rowDocId,
                        'icon' => $rowIcon,
                        'hPath' => $rowHpath,
                        'parentId' => $rowParent,
                        'sortIndex' => $rowSortIndex,
                        'sortOrder' => $rowSort,
                    ]);
                }
                $docSizeTotal += $size;
                $docRows[] = [
                    'docId' => $rowDocId,
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'markdown' => $rowMarkdown,
                    'sortOrder' => $rowSort,
                    'size' => $size,
                    'contentHash' => $rowContentHash,
                    'metaHash' => $rowMetaHash,
                ];
            }
            if (empty($docRows) && !$useIncremental) {
                api_response(400, null, 'Missing document content');
            }
        } elseif ($markdown !== '') {
            $docSizeTotal = strlen($markdown);
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $docContentHash = normalize_hash_hex($meta['contentHash'] ?? '');
            if ($docContentHash === '') {
                $docContentHash = compute_doc_content_hash($markdown);
            }
            $docMetaHash = normalize_hash_hex($meta['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $title ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $hPath,
                    'parentId' => '',
                    'sortIndex' => 0,
                    'sortOrder' => $sortOrder,
                ]);
            }
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => $markdown,
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        } elseif ($hasDocChunks && isset($docChunkById[$docId])) {
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $chunkMeta = $docChunkById[$docId];
            $docContentHash = normalize_hash_hex($meta['contentHash'] ?? '');
            if ($docContentHash === '') {
                $docContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
            }
            $docMetaHash = normalize_hash_hex($meta['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $title ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $hPath,
                    'parentId' => '',
                    'sortIndex' => 0,
                    'sortOrder' => $sortOrder,
                ]);
            }
            $docSizeTotal = max(0, (int)($chunkMeta['size'] ?? 0));
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => '',
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows) && !$useIncremental) {
            api_response(400, null, 'Missing document content');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if (!$useIncremental && $limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Storage limit reached');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        $finalSlug = $slug;
        if ($existing) {
            if ($finalSlug && $finalSlug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $finalSlug, (int)$existing['id']);
            }
            if (!$finalSlug) {
                $finalSlug = (string)$existing['slug'];
            }
        } else {
            if (!$finalSlug) {
                $finalSlug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $finalSlug);
            }
        }

        $finalTitle = $title ?: ($existing['title'] ?? $docId);
        $uploadMode = $useIncremental ? 'incremental' : 'full';
        $patchManifest = json_encode(
            $useIncremental
                ? $incrementalPatch
                : ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []],
            JSON_UNESCAPED_SLASHES
        );
        $uploadId = generate_upload_id();
        $stmt = $pdo->prepare('INSERT INTO share_uploads (upload_id, user_id, share_id, type, doc_id, slug, title, password_hash, expires_at, visitor_limit, asset_manifest, doc_manifest, upload_mode, patch_manifest, status, created_at, updated_at)
            VALUES (:upload_id, :user_id, :share_id, "doc", :doc_id, :slug, :title, :password_hash, :expires_at, :visitor_limit, :asset_manifest, :doc_manifest, :upload_mode, :patch_manifest, "pending", :created_at, :updated_at)');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':user_id' => $user['id'],
            ':share_id' => $existing ? (int)$existing['id'] : null,
            ':doc_id' => $docId,
            ':slug' => $finalSlug,
            ':title' => $finalTitle,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':asset_manifest' => json_encode($assets, JSON_UNESCAPED_SLASHES),
            ':doc_manifest' => json_encode($docChunks, JSON_UNESCAPED_SLASHES),
            ':upload_mode' => $uploadMode,
            ':patch_manifest' => $patchManifest,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
        $insertDoc = $pdo->prepare('INSERT INTO share_upload_docs (upload_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:upload_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $insertDoc->execute([
                ':upload_id' => $uploadId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }
        api_response(200, ['uploadId' => $uploadId, 'slug' => $finalSlug]);
    }

    if ($path === '/api/v1/shares/notebook/init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $meta = $payload['metadata'] ?? $payload;
        if (!is_array($meta)) {
            api_response(400, null, 'Invalid metadata');
        }
        $notebookId = trim((string)($meta['notebookId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $docs = $meta['docs'] ?? [];
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $incrementalPatch = normalize_incremental_patch($meta['incremental'] ?? []);
        $docChunks = normalize_doc_manifest($payload['docChunks'] ?? []);
        $docChunkById = [];
        foreach ($docChunks as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "notebook" AND notebook_id = :nid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':nid' => $notebookId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $useIncremental = !empty($incrementalPatch['enabled']) && !!$existing;
        if ($notebookId === '' || !is_array($docs) || (count($docs) === 0 && !$useIncremental)) {
            api_response(400, null, 'Missing notebook or documents');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            foreach ($docs as $doc) {
                $docMarkdown = (string)($doc['markdown'] ?? '');
                if ($docMarkdown === '') {
                    continue;
                }
                $hit = find_banned_word($docMarkdown, $bannedWords);
                if ($hit) {
                    $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                    api_response(400, null, '触发违禁词：' . $hit['word'] . '（文档：' . $docTitle . '）');
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $assets = normalize_asset_manifest($payload['assets'] ?? []);
        $assetSize = 0;
        foreach ($assets as $asset) {
            $assetSize += (int)($asset['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        foreach ($docs as $index => $doc) {
            $docId = trim((string)($doc['docId'] ?? ''));
            $docTitle = trim((string)($doc['title'] ?? ''));
            $docIcon = trim((string)($doc['icon'] ?? ''));
            $docHpath = (string)($doc['hPath'] ?? '');
            $docMarkdown = (string)($doc['markdown'] ?? '');
            $docSort = max(0, (int)($doc['sortOrder'] ?? $index));
            $docParent = trim((string)($doc['parentId'] ?? ''));
            $docSortIndex = (float)($doc['sortIndex'] ?? $index);
            if ($docId === '') {
                continue;
            }
            $chunkMeta = $docChunkById[$docId] ?? null;
            $size = strlen($docMarkdown);
            if ($size === 0 && $chunkMeta) {
                $size = max(0, (int)($chunkMeta['size'] ?? 0));
            }
            $docContentHash = normalize_hash_hex($doc['contentHash'] ?? '');
            if ($docContentHash === '' && $chunkMeta) {
                $docContentHash = normalize_hash_hex($chunkMeta['hash'] ?? '');
            }
            if ($docContentHash === '') {
                $docContentHash = ($docMarkdown !== '' || !$chunkMeta)
                    ? compute_doc_content_hash($docMarkdown)
                    : '';
            }
            $docMetaHash = normalize_hash_hex($doc['metaHash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $docTitle ?: $docId,
                    'icon' => $docIcon,
                    'hPath' => $docHpath,
                    'parentId' => $docParent,
                    'sortIndex' => $docSortIndex,
                    'sortOrder' => $docSort,
                ]);
            }
            $docSizeTotal += $size;
            $docRows[] = [
                'docId' => $docId,
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'markdown' => $docMarkdown,
                'sortOrder' => $docSort,
                'size' => $size,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows) && !$useIncremental) {
            api_response(400, null, 'No documents to share');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if (!$useIncremental && $limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, 'Storage limit reached');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        $finalSlug = $slug;
        if ($existing) {
            if ($finalSlug && $finalSlug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $finalSlug, (int)$existing['id']);
            }
            if (!$finalSlug) {
                $finalSlug = (string)$existing['slug'];
            }
        } else {
            if (!$finalSlug) {
                $finalSlug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $finalSlug);
            }
        }

        $finalTitle = $title ?: ($existing['title'] ?? $notebookId);
        $uploadMode = $useIncremental ? 'incremental' : 'full';
        $patchManifest = json_encode(
            $useIncremental
                ? $incrementalPatch
                : ['enabled' => false, 'deletedDocIds' => [], 'deletedAssetPaths' => []],
            JSON_UNESCAPED_SLASHES
        );
        $uploadId = generate_upload_id();
        $stmt = $pdo->prepare('INSERT INTO share_uploads (upload_id, user_id, share_id, type, notebook_id, slug, title, password_hash, expires_at, visitor_limit, asset_manifest, doc_manifest, upload_mode, patch_manifest, status, created_at, updated_at)
            VALUES (:upload_id, :user_id, :share_id, "notebook", :notebook_id, :slug, :title, :password_hash, :expires_at, :visitor_limit, :asset_manifest, :doc_manifest, :upload_mode, :patch_manifest, "pending", :created_at, :updated_at)');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':user_id' => $user['id'],
            ':share_id' => $existing ? (int)$existing['id'] : null,
            ':notebook_id' => $notebookId,
            ':slug' => $finalSlug,
            ':title' => $finalTitle,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresValue,
            ':visitor_limit' => $visitorValue,
            ':asset_manifest' => json_encode($assets, JSON_UNESCAPED_SLASHES),
            ':doc_manifest' => json_encode($docChunks, JSON_UNESCAPED_SLASHES),
            ':upload_mode' => $uploadMode,
            ':patch_manifest' => $patchManifest,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
        $stmt = $pdo->prepare('INSERT INTO share_upload_docs (upload_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:upload_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $stmt->execute([
                ':upload_id' => $uploadId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        }
        api_response(200, ['uploadId' => $uploadId, 'slug' => $finalSlug]);
    }

    if ($path === '/api/v1/shares/asset/chunk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $uploadId = sanitize_upload_id((string)($_POST['uploadId'] ?? ''));
        $assetPath = sanitize_asset_path((string)($_POST['assetPath'] ?? ''));
        $docId = trim((string)($_POST['assetDocId'] ?? ''));
        $chunkIndex = (int)($_POST['chunkIndex'] ?? -1);
        $totalChunks = (int)($_POST['totalChunks'] ?? 0);
        if ($uploadId === '' || $assetPath === '' || $chunkIndex < 0 || $totalChunks <= 0 || $chunkIndex >= $totalChunks) {
            api_response(400, null, 'Invalid chunk request');
        }
        $check = $pdo->prepare('SELECT * FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid AND status = "pending" LIMIT 1');
        $check->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        $upload = $check->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            api_response(404, null, 'Upload not found');
        }
        $manifest = normalize_asset_manifest(json_decode((string)($upload['asset_manifest'] ?? ''), true));
        $docManifest = normalize_doc_manifest(json_decode((string)($upload['doc_manifest'] ?? ''), true));
        $manifestItem = null;
        $isDocChunk = false;
        foreach ($manifest as $item) {
            $path = sanitize_asset_path((string)($item['path'] ?? ''));
            if ($path !== '' && $path === $assetPath) {
                $manifestItem = $item;
                break;
            }
        }
        if (!$manifestItem) {
            foreach ($docManifest as $item) {
                $path = sanitize_asset_path((string)($item['path'] ?? ''));
                if ($path !== '' && $path === $assetPath) {
                    $manifestItem = $item;
                    $isDocChunk = true;
                    break;
                }
            }
        }
        if (!$manifestItem) {
            api_response(400, null, 'Asset not allowed');
        }
        if ($isDocChunk) {
            $expectedDocId = trim((string)($manifestItem['docId'] ?? ''));
            if ($expectedDocId !== '' && $docId !== '' && $expectedDocId !== $docId) {
                api_response(400, null, 'Document chunk mismatch');
            }
        }
        if (empty($_FILES['chunk'])) {
            api_response(400, null, 'Missing chunk file');
        }
        $file = $_FILES['chunk'];
        $chunkSize = (int)($file['size'] ?? 0);
        [$minChunk, $maxChunk] = chunk_size_limits();
        if ($maxChunk > 0 && $chunkSize > $maxChunk) {
            api_response(413, null, 'Chunk too large');
        }
        if ($minChunk > 0 && $chunkIndex < ($totalChunks - 1) && $chunkSize > 0 && $chunkSize < $minChunk) {
            api_response(400, null, 'Chunk too small');
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            api_response(400, null, 'Invalid chunk upload');
        }

        $chunkDir = upload_chunks_dir($uploadId);
        $chunkPath = $chunkDir . '/' . $assetPath . '.part' . $chunkIndex;
        ensure_dir(dirname($chunkPath));
        if (!move_uploaded_file($tmp, $chunkPath)) {
            api_response(500, null, 'Chunk save failed');
        }

        $complete = false;
        if ($chunkIndex === $totalChunks - 1) {
            $missing = [];
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $chunkDir . '/' . $assetPath . '.part' . $i;
                if (!is_file($part)) {
                    $missing[] = $i;
                }
            }
            if (!empty($missing)) {
                api_response(409, [
                    'missingChunks' => $missing,
                    'uploadId' => $uploadId,
                    'assetPath' => $assetPath,
                    'totalChunks' => $totalChunks,
                ], 'Missing chunk');
            }
            $targetFile = upload_staging_dir($uploadId) . '/' . $assetPath;
            ensure_dir(dirname($targetFile));
            $out = fopen($targetFile, 'wb');
            if ($out === false) {
                api_response(500, null, 'Failed to open target file');
            }
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = $chunkDir . '/' . $assetPath . '.part' . $i;
                $in = fopen($part, 'rb');
                if ($in === false) {
                    fclose($out);
                    api_response(500, null, 'Failed to read chunk');
                }
                while (!feof($in)) {
                    $buffer = fread($in, 1048576);
                    if ($buffer === false) {
                        break;
                    }
                    fwrite($out, $buffer);
                }
                fclose($in);
                @unlink($part);
            }
            fclose($out);
            $complete = true;
        }
        $stmt = $pdo->prepare('UPDATE share_uploads SET updated_at = :updated_at WHERE upload_id = :upload_id');
        $stmt->execute([
            ':updated_at' => now(),
            ':upload_id' => $uploadId,
        ]);
        api_response(200, ['complete' => $complete]);
    }

    if ($path === '/api/v1/shares/upload/complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $uploadId = sanitize_upload_id((string)($payload['uploadId'] ?? ''));
        if ($uploadId === '') {
            api_response(400, null, 'Missing upload id');
        }
        $stmt = $pdo->prepare('SELECT * FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid AND status = "pending" LIMIT 1');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            api_response(404, null, 'Upload not found');
        }

        $stagingDir = upload_staging_dir($uploadId);
        $docManifest = normalize_doc_manifest(json_decode((string)($upload['doc_manifest'] ?? ''), true));
        $docChunkById = [];
        foreach ($docManifest as $item) {
            $docChunkById[(string)($item['docId'] ?? '')] = $item;
        }

        $bannedWords = get_banned_words();
        $docStmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, size_bytes, content_hash, meta_hash, LENGTH(markdown) AS markdown_len FROM share_upload_docs WHERE upload_id = :upload_id ORDER BY id ASC');
        $docStmt->execute([':upload_id' => $uploadId]);
        $docRows = [];
        $docSizeTotal = 0;
        $chunkFilesToClean = [];
        while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
            $docIdKey = trim((string)($row['doc_id'] ?? ''));
            $docTitle = (string)($row['title'] ?? '');
            $docIcon = (string)($row['icon'] ?? '');
            $docHpath = (string)($row['hpath'] ?? '');
            $docParent = (string)($row['parent_id'] ?? '');
            $docSortIndex = normalize_sort_index_value($row['sort_index'] ?? 0);
            $docSortOrder = max(0, (int)($row['sort_order'] ?? 0));
            $docChunk = $docIdKey !== '' ? ($docChunkById[$docIdKey] ?? null) : null;
            $chunkHash = '';
            $chunkSize = -1;
            $chunkFilePath = null;
            if ($docChunk) {
                $chunkPath = sanitize_asset_path((string)($docChunk['path'] ?? ''));
                $fullChunkPath = $chunkPath !== '' ? ($stagingDir . '/' . $chunkPath) : '';
                if ($fullChunkPath === '' || !is_file($fullChunkPath)) {
                    api_response(400, null, 'Missing document: ' . $docIdKey);
                }
                $chunkSize = (int)filesize($fullChunkPath);
                if ($chunkSize < 0) {
                    $chunkSize = 0;
                }
                $expectedSize = max(0, (int)($docChunk['size'] ?? 0));
                if ($expectedSize > 0 && $chunkSize !== $expectedSize) {
                    api_response(400, null, 'Document size mismatch: ' . $docIdKey);
                }
                $chunkHash = normalize_hash_hex($docChunk['hash'] ?? '');
                if ($chunkHash !== '') {
                    $computedChunkHash = normalize_hash_hex(hash_file('sha256', $fullChunkPath));
                    if ($computedChunkHash !== $chunkHash) {
                        api_response(400, null, 'Document hash mismatch: ' . $docIdKey);
                    }
                }
                $chunkFilePath = $fullChunkPath;
                $chunkFilesToClean[] = $fullChunkPath;
                if (!empty($bannedWords)) {
                    $chunkContent = @file_get_contents($fullChunkPath);
                    if ($chunkContent !== false && $chunkContent !== '') {
                        $hit = find_banned_word((string)$chunkContent, $bannedWords);
                        if ($hit) {
                            $displayTitle = trim($docTitle) ?: $docIdKey;
                            api_response(400, null, 'Banned word detected: ' . $hit['word'] . ' (doc: ' . $displayTitle . ')');
                        }
                    }
                    unset($chunkContent);
                }
            } else {
                if (!empty($bannedWords)) {
                    $inlineMdStmt = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                    $inlineMdStmt->execute([':id' => (int)$row['id']]);
                    $inlineMarkdown = (string)($inlineMdStmt->fetchColumn() ?: '');
                    if ($inlineMarkdown !== '') {
                        $hit = find_banned_word($inlineMarkdown, $bannedWords);
                        if ($hit) {
                            $displayTitle = trim($docTitle) ?: $docIdKey;
                            api_response(400, null, 'Banned word detected: ' . $hit['word'] . ' (doc: ' . $displayTitle . ')');
                        }
                    }
                    unset($inlineMarkdown);
                }
            }
            $docContentHash = normalize_hash_hex($row['content_hash'] ?? '');
            if ($chunkHash !== '') {
                $docContentHash = $chunkHash;
            }
            if ($docContentHash === '' && $chunkFilePath) {
                $docContentHash = normalize_hash_hex(hash_file('sha256', $chunkFilePath));
            }
            if ($docContentHash === '' && !$chunkFilePath) {
                $inlineMdStmt2 = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                $inlineMdStmt2->execute([':id' => (int)$row['id']]);
                $inlineMd2 = (string)($inlineMdStmt2->fetchColumn() ?: '');
                $docContentHash = compute_doc_content_hash($inlineMd2);
                unset($inlineMd2);
            }
            $docMetaHash = normalize_hash_hex($row['meta_hash'] ?? '');
            if ($docMetaHash === '') {
                $docMetaHash = compute_doc_meta_hash([
                    'title' => $docTitle,
                    'icon' => $docIcon,
                    'hPath' => $docHpath,
                    'parentId' => $docParent,
                    'sortIndex' => $docSortIndex,
                    'sortOrder' => $docSortOrder,
                ]);
            }
            $rowSize = (int)($row['size_bytes'] ?? 0);
            if ($chunkSize >= 0) {
                $rowSize = $chunkSize;
            } elseif ($rowSize <= 0) {
                $rowSize = (int)($row['markdown_len'] ?? 0);
            }
            $docRows[] = [
                'doc_id' => $docIdKey,
                'title' => $docTitle,
                'icon' => $docIcon,
                'hpath' => $docHpath,
                'parent_id' => $docParent !== '' ? $docParent : null,
                'sort_index' => $docSortIndex,
                'sort_order' => $docSortOrder,
                'size_bytes' => $rowSize,
                'content_hash' => $docContentHash,
                'meta_hash' => $docMetaHash,
                '_chunk_file' => $chunkFilePath,
                '_upload_row_id' => (int)$row['id'],
            ];
            $docSizeTotal += $rowSize;
            unset($row);
        }

        $manifest = normalize_asset_manifest(json_decode((string)($upload['asset_manifest'] ?? ''), true));
        $assetSizeTotal = 0;
        $manifestEntries = [];
        foreach ($manifest as $item) {
            $path = sanitize_asset_path((string)($item['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $full = $stagingDir . '/' . $path;
            if (!is_file($full)) {
                api_response(400, null, 'Missing asset: ' . $path);
            }
            $size = (int)filesize($full);
            $assetSizeTotal += $size;
            $assetHash = normalize_hash_hex($item['hash'] ?? '');
            if ($assetHash === '') {
                $computed = @hash_file('sha256', $full);
                $assetHash = normalize_hash_hex($computed ?: '');
            }
            $manifestEntries[] = [
                'path' => $path,
                'docId' => isset($item['docId']) ? trim((string)$item['docId']) : null,
                'size' => $size,
                'hash' => $assetHash,
            ];
        }

        $share = null;
        $shareId = (int)($upload['share_id'] ?? 0);
        if ($shareId > 0) {
            $check = $pdo->prepare('SELECT * FROM shares WHERE id = :id AND user_id = :uid LIMIT 1');
            $check->execute([
                ':id' => $shareId,
                ':uid' => $user['id'],
            ]);
            $share = $check->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$share) {
                $shareId = 0;
            }
        }

        $uploadMode = strtolower(trim((string)($upload['upload_mode'] ?? '')));
        $uploadMode = $uploadMode === 'incremental' ? 'incremental' : 'full';
        if ($uploadMode === 'incremental' && !$share) {
            $uploadMode = 'full';
        }
        if ($uploadMode === 'full' && empty($docRows)) {
            api_response(400, null, 'Missing documents');
        }
        $patchManifest = normalize_incremental_patch(json_decode((string)($upload['patch_manifest'] ?? ''), true));
        $changedDocSet = [];
        foreach ($docRows as $row) {
            $docKey = trim((string)($row['doc_id'] ?? ''));
            if ($docKey !== '') {
                $changedDocSet[$docKey] = true;
            }
        }
        $changedAssetSet = [];
        foreach ($manifestEntries as $entry) {
            $assetKey = sanitize_asset_path((string)($entry['path'] ?? ''));
            if ($assetKey !== '') {
                $changedAssetSet[$assetKey] = true;
            }
        }
        $deletedDocIds = [];
        foreach (($patchManifest['deletedDocIds'] ?? []) as $docKey) {
            $docKey = trim((string)$docKey);
            if ($docKey === '' || isset($changedDocSet[$docKey])) {
                continue;
            }
            $deletedDocIds[] = $docKey;
        }
        $deletedAssetPaths = [];
        foreach (($patchManifest['deletedAssetPaths'] ?? []) as $assetPath) {
            $assetPath = sanitize_asset_path((string)$assetPath);
            if ($assetPath === '' || str_starts_with($assetPath, comment_asset_prefix()) || isset($changedAssetSet[$assetPath])) {
                continue;
            }
            $deletedAssetPaths[] = $assetPath;
        }

        $baseShareSize = $docSizeTotal + $assetSizeTotal;
        $commentSize = share_comment_size($shareId);
        $commentAssetSize = share_comment_asset_size($shareId);
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $existingSize = $share ? (int)($share['size_bytes'] ?? 0) : 0;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($uploadMode === 'full') {
            if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
                api_response(413, null, 'Storage limit reached');
            }
        } elseif ($share) {
            $oldDocSizes = [];
            $stmt = $pdo->prepare('SELECT doc_id, size_bytes FROM share_docs WHERE share_id = :sid');
            $stmt->execute([':sid' => (int)$share['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $oldDocSizes[(string)($row['doc_id'] ?? '')] = (int)($row['size_bytes'] ?? 0);
            }
            $oldAssetSizes = [];
            $stmt = $pdo->prepare('SELECT asset_path, size_bytes FROM share_assets WHERE share_id = :sid AND asset_path NOT LIKE :prefix');
            $stmt->execute([
                ':sid' => (int)$share['id'],
                ':prefix' => comment_asset_prefix() . '%',
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $oldAssetSizes[(string)($row['asset_path'] ?? '')] = (int)($row['size_bytes'] ?? 0);
            }
            $docDelta = 0;
            foreach ($docRows as $row) {
                $key = (string)($row['doc_id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $docDelta += (int)($row['size_bytes'] ?? 0);
                if (isset($oldDocSizes[$key])) {
                    $docDelta -= (int)$oldDocSizes[$key];
                }
            }
            foreach ($deletedDocIds as $key) {
                if (isset($oldDocSizes[$key])) {
                    $docDelta -= (int)$oldDocSizes[$key];
                }
            }
            $assetDelta = 0;
            foreach ($manifestEntries as $entry) {
                $key = (string)($entry['path'] ?? '');
                if ($key === '') {
                    continue;
                }
                $assetDelta += (int)($entry['size'] ?? 0);
                if (isset($oldAssetSizes[$key])) {
                    $assetDelta -= (int)$oldAssetSizes[$key];
                }
            }
            foreach ($deletedAssetPaths as $key) {
                if (isset($oldAssetSizes[$key])) {
                    $assetDelta -= (int)$oldAssetSizes[$key];
                }
            }
            $projectedSize = max(0, $existingSize + $docDelta + $assetDelta);
            if ($limit > 0 && ($usedWithout + $projectedSize) > $limit) {
                api_response(413, null, 'Storage limit reached');
            }
        }

        $slug = sanitize_slug((string)($upload['slug'] ?? ''));
        if ($slug === '') {
            $slug = allocate_random_share_slug_or_fail($pdo);
        } else {
            ensure_share_slug_available_or_fail($pdo, $slug, $share ? (int)$share['id'] : 0);
        }

        $title = trim((string)($upload['title'] ?? ''));
        $passwordHash = $upload['password_hash'] ?? null;
        $expiresValue = isset($upload['expires_at']) ? (int)$upload['expires_at'] : null;
        $visitorValue = isset($upload['visitor_limit']) ? (int)$upload['visitor_limit'] : 0;
        $type = (string)($upload['type'] ?? 'doc');
        $docId = trim((string)($upload['doc_id'] ?? ''));
        $notebookId = trim((string)($upload['notebook_id'] ?? ''));

        $pdo->beginTransaction();
        try {
            if ($share) {
                $update = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
                $update->execute([
                    ':title' => $title !== '' ? $title : ($share['title'] ?? $slug),
                    ':slug' => $slug,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':updated_at' => now(),
                    ':id' => $share['id'],
                ]);
                $shareId = (int)$share['id'];
                if ($uploadMode === 'full') {
                    purge_share_assets($shareId, true);
                    purge_share_chunks($shareId);
                    $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
                }
            } else {
                $shareId = allocate_share_id($pdo);
                if ($shareId > 0) {
                    $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, doc_id, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                        VALUES (:id, :uid, :type, :slug, :title, :doc_id, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                    $stmt->execute([
                        ':id' => $shareId,
                        ':uid' => $user['id'],
                        ':type' => $type,
                        ':slug' => $slug,
                        ':title' => $title !== '' ? $title : $slug,
                        ':doc_id' => $type === 'doc' ? $docId : null,
                        ':notebook_id' => $type === 'notebook' ? $notebookId : null,
                        ':password_hash' => $passwordHash,
                        ':expires_at' => $expiresValue,
                        ':visitor_limit' => $visitorValue,
                        ':created_at' => now(),
                        ':updated_at' => now(),
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, doc_id, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                        VALUES (:uid, :type, :slug, :title, :doc_id, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                    $stmt->execute([
                        ':uid' => $user['id'],
                        ':type' => $type,
                        ':slug' => $slug,
                        ':title' => $title !== '' ? $title : $slug,
                        ':doc_id' => $type === 'doc' ? $docId : null,
                        ':notebook_id' => $type === 'notebook' ? $notebookId : null,
                        ':password_hash' => $passwordHash,
                        ':expires_at' => $expiresValue,
                        ':visitor_limit' => $visitorValue,
                        ':created_at' => now(),
                        ':updated_at' => now(),
                    ]);
                    $shareId = (int)$pdo->lastInsertId();
                }
            }

            $targetDir = $config['uploads_dir'] . '/shares/' . $shareId;
            ensure_dir($targetDir);
            if ($uploadMode === 'full') {
                $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
                    VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
                $fetchMdStmt = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                foreach ($docRows as $row) {
                    $docMarkdown = '';
                    if (!empty($row['_chunk_file']) && is_file($row['_chunk_file'])) {
                        $docMarkdown = (string)@file_get_contents($row['_chunk_file']);
                    } else {
                        $fetchMdStmt->execute([':id' => (int)$row['_upload_row_id']]);
                        $docMarkdown = (string)($fetchMdStmt->fetchColumn() ?: '');
                    }
                    $insertDoc->execute([
                        ':share_id' => $shareId,
                        ':doc_id' => $row['doc_id'],
                        ':title' => $row['title'],
                        ':icon' => isset($row['icon']) && trim((string)$row['icon']) !== '' ? $row['icon'] : null,
                        ':hpath' => $row['hpath'],
                        ':parent_id' => $row['parent_id'] ?? null,
                        ':sort_index' => $row['sort_index'] ?? 0,
                        ':markdown' => $docMarkdown,
                        ':sort_order' => $row['sort_order'] ?? 0,
                        ':size_bytes' => $row['size_bytes'] ?? 0,
                        ':content_hash' => normalize_hash_hex($row['content_hash'] ?? ''),
                        ':meta_hash' => normalize_hash_hex($row['meta_hash'] ?? ''),
                        ':created_at' => now(),
                        ':updated_at' => share_doc_updated_at($docMarkdown) ?: now(),
                    ]);
                    unset($docMarkdown);
                }
                foreach ($chunkFilesToClean as $cf) {
                    if (is_file($cf)) {
                        @unlink($cf);
                    }
                }
                if (is_dir($stagingDir)) {
                    move_dir($stagingDir, $targetDir);
                }
                if (!empty($manifestEntries)) {
                    $assetStmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
                        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
                    foreach ($manifestEntries as $entry) {
                        $assetStmt->execute([
                            ':share_id' => $shareId,
                            ':doc_id' => $entry['docId'] !== '' ? $entry['docId'] : null,
                            ':asset_path' => $entry['path'],
                            ':file_path' => 'shares/' . $shareId . '/' . $entry['path'],
                            ':size_bytes' => $entry['size'],
                            ':asset_hash' => normalize_hash_hex($entry['hash'] ?? ''),
                            ':created_at' => now(),
                        ]);
                    }
                }
                $updateSize = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
                $updateSize->execute([
                    ':size_bytes' => $newShareSize,
                    ':updated_at' => now(),
                    ':id' => $shareId,
                ]);
            } else {
                if (!empty($deletedDocIds)) {
                    $placeholders = implode(',', array_fill(0, count($deletedDocIds), '?'));
                    $params = $deletedDocIds;
                    array_unshift($params, $shareId);
                    $stmt = $pdo->prepare('DELETE FROM share_docs WHERE share_id = ? AND doc_id IN (' . $placeholders . ')');
                    $stmt->execute($params);
                }

                $findDoc = $pdo->prepare('SELECT id FROM share_docs WHERE share_id = :share_id AND doc_id = :doc_id LIMIT 1');
                $updateDoc = $pdo->prepare('UPDATE share_docs SET title = :title, icon = :icon, hpath = :hpath, parent_id = :parent_id, sort_index = :sort_index, markdown = :markdown, sort_order = :sort_order, size_bytes = :size_bytes, content_hash = :content_hash, meta_hash = :meta_hash, updated_at = :updated_at WHERE id = :id');
                $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
                    VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
                $fetchMdStmt2 = $pdo->prepare('SELECT markdown FROM share_upload_docs WHERE id = :id LIMIT 1');
                foreach ($docRows as $row) {
                    $docKey = trim((string)($row['doc_id'] ?? ''));
                    if ($docKey === '') {
                        continue;
                    }
                    $docMarkdown = '';
                    if (!empty($row['_chunk_file']) && is_file($row['_chunk_file'])) {
                        $docMarkdown = (string)@file_get_contents($row['_chunk_file']);
                    } else {
                        $fetchMdStmt2->execute([':id' => (int)$row['_upload_row_id']]);
                        $docMarkdown = (string)($fetchMdStmt2->fetchColumn() ?: '');
                    }
                    $findDoc->execute([
                        ':share_id' => $shareId,
                        ':doc_id' => $docKey,
                    ]);
                    $docRowId = (int)($findDoc->fetchColumn() ?: 0);
                    $bind = [
                        ':title' => $row['title'],
                        ':icon' => isset($row['icon']) && trim((string)$row['icon']) !== '' ? $row['icon'] : null,
                        ':hpath' => $row['hpath'],
                        ':parent_id' => $row['parent_id'] ?? null,
                        ':sort_index' => $row['sort_index'] ?? 0,
                        ':markdown' => $docMarkdown,
                        ':sort_order' => $row['sort_order'] ?? 0,
                        ':size_bytes' => $row['size_bytes'] ?? 0,
                        ':content_hash' => normalize_hash_hex($row['content_hash'] ?? ''),
                        ':meta_hash' => normalize_hash_hex($row['meta_hash'] ?? ''),
                        ':updated_at' => share_doc_updated_at($docMarkdown) ?: now(),
                    ];
                    unset($docMarkdown);
                    if ($docRowId > 0) {
                        $bind[':id'] = $docRowId;
                        $updateDoc->execute($bind);
                    } else {
                        $insertDoc->execute(array_merge($bind, [
                            ':share_id' => $shareId,
                            ':doc_id' => $docKey,
                            ':created_at' => now(),
                        ]));
                    }
                }
                foreach ($chunkFilesToClean as $cf) {
                    if (is_file($cf)) {
                        @unlink($cf);
                    }
                }

                if (!empty($deletedAssetPaths)) {
                    $placeholders = implode(',', array_fill(0, count($deletedAssetPaths), '?'));
                    $params = $deletedAssetPaths;
                    array_unshift($params, $shareId);
                    $select = $pdo->prepare('SELECT file_path FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
                    $select->execute($params);
                    $files = $select->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($files as $filePath) {
                        $fullPath = $config['uploads_dir'] . '/' . ltrim((string)$filePath, '/');
                        if ($fullPath !== '' && is_file($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                    $deleteAsset = $pdo->prepare('DELETE FROM share_assets WHERE share_id = ? AND asset_path IN (' . $placeholders . ')');
                    $deleteAsset->execute($params);
                }

                if (!empty($manifestEntries)) {
                    $assetStmt = $pdo->prepare('INSERT OR REPLACE INTO share_assets (share_id, doc_id, asset_path, file_path, size_bytes, asset_hash, created_at)
                        VALUES (:share_id, :doc_id, :asset_path, :file_path, :size_bytes, :asset_hash, :created_at)');
                    foreach ($manifestEntries as $entry) {
                        $source = $stagingDir . '/' . $entry['path'];
                        if (!is_file($source)) {
                            continue;
                        }
                        $targetFile = $targetDir . '/' . $entry['path'];
                        ensure_dir(dirname($targetFile));
                        if (!@rename($source, $targetFile)) {
                            if (!@copy($source, $targetFile)) {
                                continue;
                            }
                            @unlink($source);
                        }
                        $assetStmt->execute([
                            ':share_id' => $shareId,
                            ':doc_id' => $entry['docId'] !== '' ? $entry['docId'] : null,
                            ':asset_path' => $entry['path'],
                            ':file_path' => 'shares/' . $shareId . '/' . $entry['path'],
                            ':size_bytes' => $entry['size'],
                            ':asset_hash' => normalize_hash_hex($entry['hash'] ?? ''),
                            ':created_at' => now(),
                        ]);
                    }
                }
                recalculate_share_size($shareId);
            }

            $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
            $pdo->prepare('DELETE FROM share_uploads WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
            $pdo->commit();
            foreach ($chunkFilesToClean as $chunkFile) {
                if (is_file($chunkFile)) {
                    @unlink($chunkFile);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Upload finalize failed for uploadId=' . $uploadId . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            api_response(500, null, 'Upload finalize failed: ' . $e->getMessage());
        }

        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        purge_upload_session_files($uploadId);
        recalculate_user_storage((int)$user['id']);
        api_response(200, [
            'shareId' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]);
    }

    if ($path === '/api/v1/shares/upload/cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = parse_json_body();
        $uploadId = sanitize_upload_id((string)($payload['uploadId'] ?? ''));
        if ($uploadId === '') {
            api_response(400, null, 'Missing upload id');
        }
        $stmt = $pdo->prepare('SELECT id FROM share_uploads WHERE upload_id = :upload_id AND user_id = :uid LIMIT 1');
        $stmt->execute([
            ':upload_id' => $uploadId,
            ':uid' => $user['id'],
        ]);
        if (!$stmt->fetchColumn()) {
            api_response(404, null, 'Upload not found');
        }
        $pdo->prepare('DELETE FROM share_upload_docs WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
        $pdo->prepare('DELETE FROM share_uploads WHERE upload_id = :upload_id')->execute([':upload_id' => $uploadId]);
        purge_upload_session_files($uploadId);
        api_response(200, ['ok' => true]);
    }

    if ($path === '/api/v1/shares/doc') {
        $metaRaw = $_POST['metadata'] ?? '';
        if (!$metaRaw) {
            $metaRaw = json_encode(parse_json_body());
        }
        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            api_response(400, null, '数据格式错误');
        }
        $docId = trim((string)($meta['docId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $markdown = (string)($meta['markdown'] ?? '');
        $hPath = (string)($meta['hPath'] ?? '');
        $sortOrder = max(0, (int)($meta['sortOrder'] ?? 0));
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        $docs = $meta['docs'] ?? [];
        $hasDocs = is_array($docs) && count($docs) > 0;
        if ($docId === '' || (!$hasDocs && $markdown === '')) {
            api_response(400, null, '缺少文档内容');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            if ($hasDocs) {
                foreach ($docs as $doc) {
                    $docMarkdown = (string)($doc['markdown'] ?? '');
                    if ($docMarkdown === '') {
                        continue;
                    }
                    $hit = find_banned_word($docMarkdown, $bannedWords);
                    if ($hit) {
                        $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                        api_response(400, null, '触发违禁词：' . $hit['word'] . '（文档：' . $docTitle . '）');
                    }
                }
            } else {
                $hit = find_banned_word($markdown, $bannedWords);
                if ($hit) {
                    api_response(400, null, '触发违禁词：' . $hit['word']);
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $paths = $_POST['assetPaths'] ?? [];
        $docIds = $_POST['assetDocIds'] ?? [];
        $paths = is_array($paths) ? $paths : [$paths];
        $docIds = is_array($docIds) ? $docIds : [$docIds];
        $entries = [];
        if (!empty($_FILES['assets'])) {
            $entries = collect_asset_entries($_FILES['assets'], $paths, $docIds);
        }
        $assetSize = 0;
        foreach ($entries as $entry) {
            $assetSize += (int)($entry['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        if ($hasDocs) {
            foreach ($docs as $index => $doc) {
                $rowDocId = trim((string)($doc['docId'] ?? ''));
                $rowTitle = trim((string)($doc['title'] ?? ''));
                $rowIcon = trim((string)($doc['icon'] ?? ''));
                $rowHpath = (string)($doc['hPath'] ?? '');
                $rowMarkdown = (string)($doc['markdown'] ?? '');
                $rowSort = max(0, (int)($doc['sortOrder'] ?? $index));
                $rowParent = trim((string)($doc['parentId'] ?? ''));
                $rowSortIndex = (float)($doc['sortIndex'] ?? $index);
                if ($rowDocId === '') {
                    continue;
                }
                $size = strlen($rowMarkdown);
                $rowContentHash = compute_doc_content_hash($rowMarkdown);
                $rowMetaHash = compute_doc_meta_hash([
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'sortOrder' => $rowSort,
                ]);
                $docSizeTotal += $size;
                $docRows[] = [
                    'docId' => $rowDocId,
                    'title' => $rowTitle ?: $rowDocId,
                    'icon' => $rowIcon,
                    'hPath' => $rowHpath,
                    'parentId' => $rowParent,
                    'sortIndex' => $rowSortIndex,
                    'markdown' => $rowMarkdown,
                    'sortOrder' => $rowSort,
                    'size' => $size,
                    'contentHash' => $rowContentHash,
                    'metaHash' => $rowMetaHash,
                ];
            }
            if (empty($docRows)) {
                api_response(400, null, '缺少文档内容');
            }
        } else {
            $docSizeTotal = strlen($markdown);
            $docIcon = trim((string)($meta['icon'] ?? ''));
            $docContentHash = compute_doc_content_hash($markdown);
            $docMetaHash = compute_doc_meta_hash([
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => '',
                'sortIndex' => 0,
                'sortOrder' => $sortOrder,
            ]);
            $docRows[] = [
                'docId' => $docId,
                'title' => $title ?: $docId,
                'icon' => $docIcon,
                'hPath' => $hPath,
                'parentId' => null,
                'sortIndex' => 0,
                'markdown' => $markdown,
                'sortOrder' => $sortOrder,
                'size' => $docSizeTotal,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "doc" AND doc_id = :doc_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':doc_id' => $docId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, '存储空间不足，请清理后重试');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        if ($existing) {
            if ($slug && $slug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $slug, (int)$existing['id']);
            }
            $newSlug = $slug ?: $existing['slug'];
            $stmt = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
            $stmt->execute([
                ':title' => $title ?: $existing['title'],
                ':slug' => $newSlug,
                ':password_hash' => $passwordHash,
                ':expires_at' => $expiresValue,
                ':visitor_limit' => $visitorValue,
                ':updated_at' => now(),
                ':id' => $existing['id'],
            ]);
            $shareId = (int)$existing['id'];
            $slug = $newSlug;
        } else {
            if (!$slug) {
                $slug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $slug);
            }
            $shareId = allocate_share_id($pdo);
            if ($shareId > 0) {
                $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, doc_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:id, :uid, "doc", :slug, :title, :doc_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':id' => $shareId,
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $docId,
                    ':doc_id' => $docId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, doc_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:uid, "doc", :slug, :title, :doc_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $docId,
                    ':doc_id' => $docId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
                $shareId = (int)$pdo->lastInsertId();
            }
        }

        if ($existing) {
            purge_share_assets($shareId, true);
        }
        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
        $insertDoc = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $insertDoc->execute([
                ':share_id' => $shareId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => share_doc_updated_at((string)($row['markdown'] ?? '')) ?: now(),
            ]);
        }

        $actualAssets = handle_asset_uploads($shareId, $entries);
        $finalSize = $docSizeTotal + $actualAssets + share_comment_size($shareId) + share_comment_asset_size($shareId);
        $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':size_bytes' => $finalSize,
            ':updated_at' => now(),
            ':id' => $shareId,
        ]);
        recalculate_user_storage((int)$user['id']);

        api_response(200, ['share' => [
            'id' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]]);
    }

    if ($path === '/api/v1/shares/notebook') {
        $metaRaw = $_POST['metadata'] ?? '';
        if (!$metaRaw) {
            $metaRaw = json_encode(parse_json_body());
        }
        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            api_response(400, null, '数据格式错误');
        }
        $notebookId = trim((string)($meta['notebookId'] ?? ''));
        $title = trim((string)($meta['title'] ?? ''));
        $docs = $meta['docs'] ?? [];
        $password = trim((string)($meta['password'] ?? ''));
        $clearPassword = !empty($meta['clearPassword']);
        $expiresAt = parse_expires_at($meta['expiresAt'] ?? null);
        $clearExpires = !empty($meta['clearExpires']);
        $visitorLimit = parse_visitor_limit($meta['visitorLimit'] ?? null);
        $clearVisitorLimit = !empty($meta['clearVisitorLimit']);
        if ($notebookId === '' || !is_array($docs) || count($docs) === 0) {
            api_response(400, null, '缺少笔记本 ID 或文档数据');
        }
        $bannedWords = get_banned_words();
        if (!empty($bannedWords)) {
            foreach ($docs as $doc) {
                $docMarkdown = (string)($doc['markdown'] ?? '');
                if ($docMarkdown === '') {
                    continue;
                }
                $hit = find_banned_word($docMarkdown, $bannedWords);
                if ($hit) {
                    $docTitle = trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['docId'] ?? ''));
                    api_response(400, null, '触发违禁词：' . $hit['word'] . '（文档：' . $docTitle . '）');
                }
            }
        }
        $slug = parse_requested_share_slug_or_fail($meta['slug'] ?? '');
        $paths = $_POST['assetPaths'] ?? [];
        $docIds = $_POST['assetDocIds'] ?? [];
        $paths = is_array($paths) ? $paths : [$paths];
        $docIds = is_array($docIds) ? $docIds : [$docIds];
        $entries = [];
        if (!empty($_FILES['assets'])) {
            $entries = collect_asset_entries($_FILES['assets'], $paths, $docIds);
        }
        $assetSize = 0;
        foreach ($entries as $entry) {
            $assetSize += (int)($entry['size'] ?? 0);
        }
        $docRows = [];
        $docSizeTotal = 0;
        foreach ($docs as $index => $doc) {
            $docId = trim((string)($doc['docId'] ?? ''));
            $docTitle = trim((string)($doc['title'] ?? ''));
            $docIcon = trim((string)($doc['icon'] ?? ''));
            $docHpath = (string)($doc['hPath'] ?? '');
            $docMarkdown = (string)($doc['markdown'] ?? '');
            $docSort = max(0, (int)($doc['sortOrder'] ?? $index));
            $docParent = trim((string)($doc['parentId'] ?? ''));
            $docSortIndex = (float)($doc['sortIndex'] ?? $index);
            if ($docId === '') {
                continue;
            }
            $size = strlen($docMarkdown);
            $docContentHash = compute_doc_content_hash($docMarkdown);
            $docMetaHash = compute_doc_meta_hash([
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'sortOrder' => $docSort,
            ]);
            $docSizeTotal += $size;
            $docRows[] = [
                'docId' => $docId,
                'title' => $docTitle ?: $docId,
                'icon' => $docIcon,
                'hPath' => $docHpath,
                'parentId' => $docParent,
                'sortIndex' => $docSortIndex,
                'markdown' => $docMarkdown,
                'sortOrder' => $docSort,
                'size' => $size,
                'contentHash' => $docContentHash,
                'metaHash' => $docMetaHash,
            ];
        }
        if (empty($docRows)) {
            api_response(400, null, '没有可用的文档');
        }
        $baseShareSize = $docSizeTotal + $assetSize;
        $stmt = $pdo->prepare('SELECT * FROM shares WHERE user_id = :uid AND type = "notebook" AND notebook_id = :nid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':uid' => $user['id'], ':nid' => $notebookId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingSize = $existing ? (int)($existing['size_bytes'] ?? 0) : 0;
        $commentSize = $existing ? share_comment_size((int)$existing['id']) : 0;
        $commentAssetSize = $existing ? share_comment_asset_size((int)$existing['id']) : 0;
        $newShareSize = $baseShareSize + $commentSize + $commentAssetSize;
        $used = recalculate_user_storage((int)$user['id']);
        $limit = get_user_limit_bytes($user);
        $usedWithout = max(0, $used - $existingSize);
        if ($limit > 0 && ($usedWithout + $newShareSize) > $limit) {
            api_response(413, null, '存储空间不足，请清理后重试');
        }

        $passwordHash = $existing['password_hash'] ?? null;
        $expiresValue = isset($existing['expires_at']) ? (int)$existing['expires_at'] : null;
        $visitorValue = isset($existing['visitor_limit']) ? (int)$existing['visitor_limit'] : 0;
        if ($clearPassword) {
            $passwordHash = null;
        } elseif ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($clearExpires) {
            $expiresValue = null;
        } elseif ($expiresAt !== null) {
            $expiresValue = $expiresAt;
        }
        if ($clearVisitorLimit) {
            $visitorValue = 0;
        } elseif ($visitorLimit !== null) {
            $visitorValue = $visitorLimit;
        }

        if ($existing) {
            if ($slug && $slug !== $existing['slug']) {
                ensure_share_slug_available_or_fail($pdo, $slug, (int)$existing['id']);
            }
            $newSlug = $slug ?: $existing['slug'];
            $stmt = $pdo->prepare('UPDATE shares SET title = :title, slug = :slug, password_hash = :password_hash, expires_at = :expires_at, visitor_limit = :visitor_limit, updated_at = :updated_at, deleted_at = NULL WHERE id = :id');
            $stmt->execute([
                ':title' => $title ?: $existing['title'],
                ':slug' => $newSlug,
                ':password_hash' => $passwordHash,
                ':expires_at' => $expiresValue,
                ':visitor_limit' => $visitorValue,
                ':updated_at' => now(),
                ':id' => $existing['id'],
            ]);
            $shareId = (int)$existing['id'];
            $slug = $newSlug;
        } else {
            if (!$slug) {
                $slug = allocate_random_share_slug_or_fail($pdo);
            } else {
                ensure_share_slug_available_or_fail($pdo, $slug);
            }
            $shareId = allocate_share_id($pdo);
            if ($shareId > 0) {
                $stmt = $pdo->prepare('INSERT INTO shares (id, user_id, type, slug, title, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:id, :uid, "notebook", :slug, :title, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':id' => $shareId,
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $notebookId,
                    ':notebook_id' => $notebookId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO shares (user_id, type, slug, title, notebook_id, password_hash, expires_at, visitor_limit, created_at, updated_at)
                    VALUES (:uid, "notebook", :slug, :title, :notebook_id, :password_hash, :expires_at, :visitor_limit, :created_at, :updated_at)');
                $stmt->execute([
                    ':uid' => $user['id'],
                    ':slug' => $slug,
                    ':title' => $title ?: $notebookId,
                    ':notebook_id' => $notebookId,
                    ':password_hash' => $passwordHash,
                    ':expires_at' => $expiresValue,
                    ':visitor_limit' => $visitorValue,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
                $shareId = (int)$pdo->lastInsertId();
            }
        }

        if ($existing) {
            purge_share_assets($shareId, true);
        }
        if (!empty($visitorValue)) {
            seed_share_visitors_from_logs($shareId);
        }
        $pdo->prepare('DELETE FROM share_docs WHERE share_id = :share_id')->execute([':share_id' => $shareId]);
        $stmt = $pdo->prepare('INSERT INTO share_docs (share_id, doc_id, title, icon, hpath, parent_id, sort_index, markdown, sort_order, size_bytes, content_hash, meta_hash, created_at, updated_at)
            VALUES (:share_id, :doc_id, :title, :icon, :hpath, :parent_id, :sort_index, :markdown, :sort_order, :size_bytes, :content_hash, :meta_hash, :created_at, :updated_at)');
        foreach ($docRows as $row) {
            $stmt->execute([
                ':share_id' => $shareId,
                ':doc_id' => $row['docId'],
                ':title' => $row['title'],
                ':icon' => $row['icon'] !== '' ? $row['icon'] : null,
                ':hpath' => $row['hPath'],
                ':parent_id' => $row['parentId'] !== '' ? $row['parentId'] : null,
                ':sort_index' => $row['sortIndex'],
                ':markdown' => $row['markdown'],
                ':sort_order' => $row['sortOrder'],
                ':size_bytes' => $row['size'],
                ':content_hash' => normalize_hash_hex($row['contentHash'] ?? ''),
                ':meta_hash' => normalize_hash_hex($row['metaHash'] ?? ''),
                ':created_at' => now(),
                ':updated_at' => share_doc_updated_at((string)($row['markdown'] ?? '')) ?: now(),
            ]);
        }

        $actualAssets = handle_asset_uploads($shareId, $entries);
        $finalSize = $docSizeTotal + $actualAssets + share_comment_size($shareId) + share_comment_asset_size($shareId);
        $stmt = $pdo->prepare('UPDATE shares SET size_bytes = :size_bytes, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':size_bytes' => $finalSize,
            ':updated_at' => now(),
            ':id' => $shareId,
        ]);
        recalculate_user_storage((int)$user['id']);

        api_response(200, ['share' => [
            'id' => $shareId,
            'slug' => $slug,
            'url' => share_url($slug),
        ]]);
    }

    api_response(404, null, '接口不存在');
}

function rewrite_asset_links(string $markdown, string $assetBase = ''): string {
    $prefix = $assetBase;
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    $markdown = str_replace('](/assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](./assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](assets/', '](' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](<assets/', '](<' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](</assets/', '](<' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="/assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="./assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('src="assets/', 'src="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="/assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="./assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('href="assets/', 'href="' . $prefix . 'assets/', $markdown);
    $markdown = str_replace('](/emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](./emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](<emojis/', '](<' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](</emojis/', '](<' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="/emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="./emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="/emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="./emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](/emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](./emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('](emojis/', '](' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="/emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="./emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('src="emojis/', 'src="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="/emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="./emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    $markdown = str_replace('href="emojis/', 'href="' . $prefix . 'emojis/', $markdown);
    return $markdown;
}

function encode_path_segments(string $path): string {
    $parts = explode('/', $path);
    $encoded = array_map(static fn($part) => rawurlencode($part), $parts);
    return implode('/', $encoded);
}

function sanitize_emoji_token_name(string $token): string {
    $token = trim($token);
    if ($token === '' || $token[0] !== ':' || substr($token, -1) !== ':') {
        return '';
    }
    $name = trim(substr($token, 1, -1));
    if ($name === '' || strpos($name, "\n") !== false || strpos($name, "\r") !== false) {
        return '';
    }
    if (strpos($name, ':') !== false) {
        return '';
    }
    return $name;
}

function normalize_emoji_key(string $name): string {
    $value = strtolower(trim($name));
    if ($value === '') {
        return '';
    }
    $normalized = preg_replace('/[\s_-]+/', '', $value);
    return $normalized ?? '';
}

function resolve_custom_emoji_src(string $name, int $shareId, string $assetBasePath): string {
    global $config;
    static $cache = [];
    $key = $shareId . '|' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $clean = str_replace('\\', '/', $name);
    $clean = ltrim($clean, '/');
    if ($clean === '' || strpos($clean, '..') !== false) {
        $cache[$key] = '';
        return '';
    }
    $decoded = rawurldecode($clean);
    $candidates = [$clean];
    if ($decoded !== '' && $decoded !== $clean) {
        $candidates[] = $decoded;
    }
    $compact = preg_replace('/\s+/', '', $clean);
    if ($compact !== $clean && $compact !== '') {
        $candidates[] = $compact;
    }
    $extensions = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];
    $uploadsDir = (string)($config['uploads_dir'] ?? (__DIR__ . '/uploads'));
    $uploadsDir = rtrim($uploadsDir, '/\\');
    $fsBase = $uploadsDir . '/shares/' . $shareId . '/emojis/';
    foreach ($candidates as $candidate) {
        $hasExt = (bool)preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $candidate);
        $names = $hasExt ? [$candidate] : array_merge([$candidate], array_map(
            static fn($ext) => $candidate . '.' . $ext,
            $extensions
        ));
        foreach ($names as $file) {
            $fsPath = $fsBase . $file;
            if (is_file($fsPath)) {
                $rel = 'emojis/' . $file;
                $cache[$key] = $assetBasePath . encode_path_segments($rel);
                return $cache[$key];
            }
        }
    }
    $index = build_share_emoji_index($shareId, $uploadsDir);
    if (!empty($index)) {
        $normalizedIndex = [];
        foreach ($index as $nameKey => $rel) {
            $norm = normalize_emoji_key($nameKey);
            if ($norm !== '' && !isset($normalizedIndex[$norm])) {
                $normalizedIndex[$norm] = $rel;
            }
        }
        foreach ($candidates as $candidate) {
            if (isset($index[$candidate])) {
                $cache[$key] = $assetBasePath . encode_path_segments($index[$candidate]);
                return $cache[$key];
            }
            $lower = strtolower($candidate);
            foreach ($index as $nameKey => $rel) {
                if (strtolower($nameKey) === $lower) {
                    $cache[$key] = $assetBasePath . encode_path_segments($rel);
                    return $cache[$key];
                }
            }
            foreach ($index as $nameKey => $rel) {
                if (str_starts_with($nameKey, $candidate . '-')) {
                    $cache[$key] = $assetBasePath . encode_path_segments($rel);
                    return $cache[$key];
                }
            }
            if (!empty($normalizedIndex)) {
                $normalized = normalize_emoji_key($candidate);
                if ($normalized !== '' && isset($normalizedIndex[$normalized])) {
                    $cache[$key] = $assetBasePath . encode_path_segments($normalizedIndex[$normalized]);
                    return $cache[$key];
                }
            }
        }
    }
    $cache[$key] = '';
    return '';
}

function build_share_emoji_index(int $shareId, string $uploadsDir): array {
    static $cache = [];
    $key = $uploadsDir . '|' . $shareId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $map = [];
    if ($shareId <= 0 || $uploadsDir === '') {
        $cache[$key] = $map;
        return $map;
    }
    $root = rtrim($uploadsDir, '/\\') . '/shares/' . $shareId . '/emojis';
    if (!is_dir($root)) {
        $cache[$key] = $map;
        return $map;
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $fullPath = str_replace('\\', '/', $file->getPathname());
            if (!preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $fullPath)) {
                continue;
            }
            $relative = substr($fullPath, strlen(rtrim($root, '/\\')) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            $name = preg_replace('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', '', $relative);
            if ($name === '') {
                continue;
            }
            $map[$name] = 'emojis/' . $relative;
            $baseName = basename($name);
            if ($baseName !== '' && !isset($map[$baseName])) {
                $map[$baseName] = 'emojis/' . $relative;
            }
        }
    } catch (Throwable $err) {
        // ignore
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT asset_path FROM share_assets WHERE share_id = :sid AND asset_path LIKE :prefix');
        $stmt->execute([
            ':sid' => $shareId,
            ':prefix' => 'emojis/%',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $path) {
            $path = sanitize_asset_path((string)$path);
            if ($path === '' || !str_starts_with($path, 'emojis/')) {
                continue;
            }
            $relative = substr($path, strlen('emojis/'));
            if ($relative === '' || substr($relative, -1) === '/') {
                continue;
            }
            $name = preg_replace('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', '', $relative);
            if ($name === '' || isset($map[$name])) {
                continue;
            }
            $map[$name] = $path;
            $baseName = basename($name);
            if ($baseName !== '' && !isset($map[$baseName])) {
                $map[$baseName] = $path;
            }
        }
    } catch (Throwable $err) {
        // ignore
    }
    $cache[$key] = $map;
    return $map;
}

function get_fence_marker(string $text, int $index): string {
    $ch = $text[$index] ?? '';
    if ($ch !== '`' && $ch !== '~') {
        return '';
    }
    $marker = substr($text, $index, 3);
    if ($marker !== '```' && $marker !== '~~~') {
        return '';
    }
    $i = $index - 1;
    while ($i >= 0 && $text[$i] === ' ') {
        $i--;
    }
    if ($i >= 0 && $text[$i] !== "\n") {
        return '';
    }
    return $marker;
}

function get_emoji_token_name_at(string $text, int $index): string {
    $len = strlen($text);
    if ($index < 0 || $index >= $len) {
        return '';
    }
    if ($text[$index] !== ':') {
        return '';
    }
    $end = strpos($text, ':', $index + 1);
    if ($end === false || $end <= $index + 1) {
        return '';
    }
    return sanitize_emoji_token_name(substr($text, $index, $end - $index + 1));
}

function replace_custom_emoji_tokens(string $markdown, int $shareId, string $assetBasePath): string {
    $source = (string)$markdown;
    if ($source === '') {
        return $source;
    }
    $len = strlen($source);
    $out = '';
    $inFence = false;
    $fenceMarker = '';
    $inInline = false;
    $inTag = false;
    $i = 0;
    while ($i < $len) {
        $fence = $inTag ? '' : get_fence_marker($source, $i);
        if (!$inFence && $fence !== '') {
            $inFence = true;
            $fenceMarker = $fence;
            $out .= $fence;
            $i += 3;
            continue;
        }
        if ($inFence && $fence !== '' && $fence === $fenceMarker) {
            $inFence = false;
            $fenceMarker = '';
            $out .= $fence;
            $i += 3;
            continue;
        }
        $ch = $source[$i];
        if (!$inFence) {
            if ($ch === '<') {
                $next = $source[$i + 1] ?? '';
                if ($next === '!' || $next === '/' || ctype_alpha($next)) {
                    $inTag = true;
                    $out .= $ch;
                    $i++;
                    continue;
                }
            }
            if ($inTag) {
                $out .= $ch;
                if ($ch === '>') {
                    $inTag = false;
                }
                $i++;
                continue;
            }
            if ($ch === '`') {
                $inInline = !$inInline;
                $out .= $ch;
                $i++;
                continue;
            }
            if ($ch === "\n") {
                $inInline = false;
            }
            if (!$inInline && $ch === ':') {
                $end = strpos($source, ':', $i + 1);
                if ($end !== false && $end > $i + 1) {
                    $token = substr($source, $i, $end - $i + 1);
                    $name = sanitize_emoji_token_name($token);
                    if ($name !== '') {
                        $src = resolve_custom_emoji_src($name, $shareId, $assetBasePath);
                        if ($src !== '') {
                            $out .= '![](<';
                            $out .= $src;
                            $out .= '>)';
                            $nextName = get_emoji_token_name_at($source, $end + 1);
                            if ($nextName !== '') {
                                $nextSrc = resolve_custom_emoji_src($nextName, $shareId, $assetBasePath);
                                if ($nextSrc !== '') {
                                    $out .= ' ';
                                }
                            }
                            $i = $end + 1;
                            continue;
                        }
                    }
                    $out .= $token;
                    $i = $end + 1;
                    continue;
                }
            }
        }
        $out .= $ch;
        $i++;
    }
    return $out;
}

function insert_adjacent_emoji_image_spacing(string $markdown): string {
    $source = (string)$markdown;
    if ($source === '') {
        return $source;
    }
    return preg_replace(
        '/(!\[[^\]]*]\((?:<)?[^)\s]*emojis\/[^)\s>]+(?:>)?\))(?=!\[[^\]]*]\((?:<)?[^)\s]*emojis\/)/',
        '$1 ',
        $source,
    ) ?? $source;
}

function strip_duplicate_title_heading(string $markdown, string $title): string {
    $title = trim($title);
    if ($title === '') {
        return $markdown;
    }
    $lines = preg_split("/\r\n|\r|\n/", $markdown);
    if (!is_array($lines)) {
        return $markdown;
    }
    $count = count($lines);
    $idx = 0;
    while ($idx < $count && trim($lines[$idx]) === '') {
        $idx++;
    }
    if ($idx >= $count) {
        return $markdown;
    }
    $line = trim($lines[$idx]);
    $next = $idx + 1 < $count ? trim($lines[$idx + 1]) : '';
    $headingText = '';
    $removeLines = 0;
    if (preg_match('/^#{1,6}\s+(.*)$/', $line, $match)) {
        $headingText = trim($match[1]);
        $removeLines = 1;
    } elseif ($line === $title && $next !== '' && preg_match('/^=+$/', $next)) {
        $headingText = $line;
        $removeLines = 2;
    }
    if ($headingText === '' || $headingText !== $title) {
        return $markdown;
    }
    $start = $idx;
    $end = $idx + $removeLines;
    while ($end < $count && trim($lines[$end]) === '') {
        $end++;
    }
    array_splice($lines, $start, $end - $start);
    return implode("\n", $lines);
}

function share_style_is_dangerous(string $style): bool {
    $value = strtolower((string)$style);
    $compact = preg_replace('/[\x00-\x1f\x7f]+/u', '', $value);
    if (!is_string($compact)) {
        $compact = $value;
    }
    return (bool)preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|url\s*\(\s*[\'"]?\s*data\s*:\s*text\/html/i', $compact);
}

function is_safe_share_url(string $value, string $tag = '', string $attr = ''): bool {
    $raw = trim((string)$value);
    if ($raw === '') {
        return true;
    }
    $compact = preg_replace('/[\x00-\x20\x7f\s]+/u', '', $raw);
    if (!is_string($compact) || $compact === '') {
        $compact = $raw;
    }
    $tag = strtolower($tag);
    $attr = strtolower($attr);

    if (preg_match('/^(?:javascript|vbscript)\s*:/i', $compact)) {
        return false;
    }
    if (preg_match('/^data\s*:/i', $compact)) {
        if ($tag === 'img' && $attr === 'src') {
            return (bool)preg_match('/^data:image\/(?:png|gif|jpe?g|webp|bmp|avif);base64,[a-z0-9+\/=\r\n]+$/i', $compact);
        }
        return false;
    }
    if ($tag === 'iframe' && $attr === 'src') {
        if ($compact === '') {
            return true;
        }
        if (preg_match('/^(?:https?:)?\/\//i', $compact)) {
            return true;
        }
        if (str_starts_with($compact, '/') || str_starts_with($compact, './') || str_starts_with($compact, '../')) {
            return true;
        }
        if (preg_match('/^about:blank(?:[?#].*)?$/i', $compact)) {
            return true;
        }
        return false;
    }
    if ($compact[0] === '#') {
        return true;
    }
    if (str_starts_with($compact, '/') && !str_starts_with($compact, '//')) {
        return true;
    }
    if (str_starts_with($compact, './') || str_starts_with($compact, '../')) {
        return true;
    }
    if (str_starts_with($compact, '//')) {
        return true;
    }
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $compact)) {
        return (bool)preg_match('/^(?:https?|mailto|tel):/i', $compact);
    }
    return true;
}

function sanitize_share_html(string $html): string {
    $source = (string)$html;
    if ($source === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $libxmlBackup = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapperId = '__sps_share_html_root__';
    $loaded = $dom->loadHTML(
        '<?xml encoding="UTF-8"><!doctype html><html><body><div id="' . $wrapperId . '">' . $source . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($libxmlBackup);
    if (!$loaded) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $root = null;
    $xpath = new DOMXPath($dom);
    $rootNodes = $xpath->query('//*[@id="' . $wrapperId . '"]');
    if ($rootNodes && $rootNodes->length > 0 && $rootNodes->item(0) instanceof DOMElement) {
        $root = $rootNodes->item(0);
    }
    if (!$root) {
        return htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $blockedTags = ['script', 'object', 'embed', 'svg', 'math', 'base', 'meta', 'link'];
    $katexSvgAllowedTags = ['svg', 'path', 'line', 'g'];
    $katexSvgAllowedAttrs = [
        'svg' => ['class', 'style', 'xmlns', 'xmlns:xlink', 'width', 'height', 'viewbox', 'preserveaspectratio', 'role', 'aria-hidden', 'focusable'],
        'path' => ['class', 'style', 'd', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'transform', 'opacity'],
        'line' => ['class', 'style', 'x1', 'y1', 'x2', 'y2', 'fill', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'transform', 'opacity'],
        'g' => ['class', 'style', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity'],
    ];
    $urlAttrs = ['href', 'src', 'xlink:href', 'formaction', 'action', 'poster'];
    $iframeAllowedAttrs = [
        'src',
        'width',
        'height',
        'title',
        'allow',
        'allowfullscreen',
        'loading',
        'referrerpolicy',
        'sandbox',
        'frameborder',
    ];

    $elements = [];
    foreach ($root->getElementsByTagName('*') as $node) {
        $elements[] = $node;
    }

    foreach ($elements as $element) {
        if (!($element instanceof DOMElement)) {
            continue;
        }

        $tag = strtolower($element->tagName);
        $insideSvg = false;
        $insideKatex = false;
        $cursor = $element;
        while ($cursor instanceof DOMElement) {
            if (strtolower($cursor->tagName) === 'svg') {
                $insideSvg = true;
            }
            if (preg_match('/(?:^|\s)katex(?:\s|$)/i', (string)$cursor->getAttribute('class'))) {
                $insideKatex = true;
            }
            $parent = $cursor->parentNode;
            $cursor = $parent instanceof DOMElement ? $parent : null;
        }
        $isKatexSvgNode = $insideSvg && $insideKatex && in_array($tag, $katexSvgAllowedTags, true);
        if (in_array($tag, $blockedTags, true)) {
            if (!($tag === 'svg' && $isKatexSvgNode)) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
                continue;
            }
        } elseif ($insideSvg && !$isKatexSvgNode) {
            if ($element->parentNode) {
                $element->parentNode->removeChild($element);
            }
            continue;
        }

        $attrNames = [];
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                $attrNames[] = $attribute->name;
            }
        }
        $allowedSvgAttrs = $isKatexSvgNode ? ($katexSvgAllowedAttrs[$tag] ?? []) : [];

        foreach ($attrNames as $attrNameRaw) {
            $attrName = strtolower($attrNameRaw);
            $attrValue = (string)$element->getAttribute($attrNameRaw);

            if (str_starts_with($attrName, 'on')) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($attrName === 'srcdoc') {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($isKatexSvgNode && !in_array($attrName, $allowedSvgAttrs, true)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($attrName === 'style' && share_style_is_dangerous($attrValue)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if ($isKatexSvgNode) {
                continue;
            }
            if ($tag === 'iframe' && !in_array($attrName, $iframeAllowedAttrs, true)) {
                $element->removeAttribute($attrNameRaw);
                continue;
            }
            if (in_array($attrName, $urlAttrs, true) && !is_safe_share_url($attrValue, $tag, $attrName)) {
                $element->removeAttribute($attrNameRaw);
            }
        }

        if ($tag === 'iframe') {
            $iframeSrc = trim((string)$element->getAttribute('src'));
            if ($iframeSrc !== '' && !is_safe_share_url($iframeSrc, 'iframe', 'src')) {
                if ($element->parentNode) {
                    $element->parentNode->removeChild($element);
                }
            }
        }
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $rendered = $dom->saveHTML($child);
        if (is_string($rendered)) {
            $output .= $rendered;
        }
    }

    return $output;
}

function render_markdown(string $markdown): string {
    static $parser = null;
    if (!$parser) {
        $parser = new ShareParsedown();
        if (method_exists($parser, 'setSafeMode')) {
            $parser->setSafeMode(false);
        }
    }
    return sanitize_share_html($parser->text($markdown));
}

function share_requires_password(array $share): bool {
    return !empty($share['password_hash']);
}

function share_is_expired(array $share): bool {
    if (empty($share['expires_at'])) {
        return false;
    }
    return time() > (int)$share['expires_at'];
}

function share_password_hash_by_id(int $shareId): string {
    static $cache = [];
    if ($shareId <= 0) {
        return '';
    }
    if (array_key_exists($shareId, $cache)) {
        return (string)$cache[$shareId];
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT password_hash FROM shares WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    $value = $stmt->fetchColumn();
    $hash = is_string($value) ? trim($value) : '';
    $cache[$shareId] = $hash;
    return $hash;
}

function share_access_signature(string $passwordHash): string {
    $raw = trim($passwordHash);
    if ($raw === '') {
        return '';
    }
    return hash('sha256', $raw);
}

function share_access_granted(int $shareId): bool {
    if ($shareId <= 0) {
        return false;
    }
    $entry = $_SESSION['share_access'][$shareId] ?? null;
    if (empty($entry)) {
        return false;
    }
    $passwordHash = share_password_hash_by_id($shareId);
    if ($passwordHash === '') {
        // No password now: old session entries are irrelevant.
        return true;
    }
    $expected = share_access_signature($passwordHash);
    if ($expected === '') {
        return false;
    }
    if ($entry === true) {
        // Legacy boolean session format; force one re-verification for protected shares.
        return false;
    }
    if (is_string($entry) && $entry !== '') {
        return hash_equals($expected, $entry);
    }
    if (is_array($entry)) {
        $token = trim((string)($entry['token'] ?? ''));
        if ($token !== '') {
            return hash_equals($expected, $token);
        }
    }
    return false;
}

function grant_share_access(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    if (!isset($_SESSION['share_access']) || !is_array($_SESSION['share_access'])) {
        $_SESSION['share_access'] = [];
    }
    $passwordHash = share_password_hash_by_id($shareId);
    if ($passwordHash === '') {
        $_SESSION['share_access'][$shareId] = true;
        return;
    }
    $_SESSION['share_access'][$shareId] = share_access_signature($passwordHash);
}

function share_visitor_limit(array $share): int {
    return max(0, (int)($share['visitor_limit'] ?? 0));
}

function seed_share_visitors_from_logs(int $shareId): void {
    if ($shareId <= 0) {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    $visitorCount = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    if ($visitorCount > 0) {
        return;
    }
    $pdo->prepare('INSERT OR IGNORE INTO share_visitors (share_id, visitor_id, created_at)
        SELECT share_id, visitor_id, MIN(created_at) FROM share_access_logs
        WHERE share_id = :sid AND visitor_id IS NOT NULL AND visitor_id != ""
        GROUP BY visitor_id')->execute([':sid' => $shareId]);
}

function share_visitor_count(int $shareId): int {
    if ($shareId <= 0) {
        return 0;
    }
    seed_share_visitors_from_logs($shareId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    return (int)$stmt->fetchColumn();
}

function share_visitor_limit_reached(array $share): bool {
    $limit = share_visitor_limit($share);
    if ($limit <= 0) {
        return false;
    }
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        return false;
    }
    seed_share_visitors_from_logs($shareId);
    $visitorId = get_visitor_id();
    $pdo = db();
    if ($visitorId !== '') {
        $stmt = $pdo->prepare('SELECT 1 FROM share_visitors WHERE share_id = :sid AND visitor_id = :vid LIMIT 1');
        $stmt->execute([
            ':sid' => $shareId,
            ':vid' => $visitorId,
        ]);
        if ($stmt->fetchColumn()) {
            return false;
        }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM share_visitors WHERE share_id = :sid');
    $stmt->execute([':sid' => $shareId]);
    return (int)$stmt->fetchColumn() >= $limit;
}

function register_share_visitor(int $shareId, string $visitorId): void {
    if ($shareId <= 0 || $visitorId === '') {
        return;
    }
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO share_visitors (share_id, visitor_id, created_at)
        VALUES (:sid, :vid, :created_at)')->execute([
        ':sid' => $shareId,
        ':vid' => $visitorId,
        ':created_at' => now(),
    ]);
}

function share_inline_asset_extensions(): array {
    return [
        // Images
        'apng',
        'avif',
        'bmp',
        'gif',
        'ico',
        'jpg',
        'jpeg',
        'jxl',
        'png',
        'svg',
        'tif',
        'tiff',
        'webp',
        // Audio
        'aac',
        'flac',
        'm4a',
        'mp3',
        'oga',
        'wav',
        'ogg',
        'opus',
        'weba',
        // Video
        'avi',
        'm4v',
        'mkv',
        'mov',
        'mp4',
        'ogv',
        'webm',
        // Text / documents
        'csv',
        'json',
        'log',
        'srt',
        'txt',
        'md',
        'pdf',
        'vtt',
        'xml',
        'yaml',
        'yml',
    ];
}

function share_executable_asset_extensions(): array {
    return [
        'bat',
        'cgi',
        'cmd',
        'com',
        'cpl',
        'dll',
        'exe',
        'hta',
        'htm',
        'html',
        'jar',
        'js',
        'jse',
        'mjs',
        'msi',
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phar',
        'phtml',
        'pl',
        'ps1',
        'psd1',
        'psm1',
        'py',
        'rb',
        'scr',
        'sh',
        'ts',
        'tsx',
        'vb',
        'vbe',
        'vbs',
        'wsf',
        'wsh',
        'xhtml',
    ];
}

function share_asset_is_executable(string $assetPath): bool {
    $ext = share_asset_extension($assetPath);
    if ($ext === '') {
        return false;
    }
    return in_array($ext, share_executable_asset_extensions(), true);
}

function share_asset_extension(string $assetPath): string {
    $ext = strtolower((string)pathinfo($assetPath, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : '';
}

function share_asset_allow_inline(string $assetPath): bool {
    if (share_asset_is_executable($assetPath)) {
        return false;
    }
    $ext = share_asset_extension($assetPath);
    if ($ext === '') {
        return false;
    }
    return in_array($ext, share_inline_asset_extensions(), true);
}

function detect_share_asset_mime(string $fullPath, string $assetPath): string {
    $ext = share_asset_extension($assetPath);
    $map = [
        'csv' => 'text/csv; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'log' => 'text/plain; charset=UTF-8',
        'srt' => 'text/plain; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8',
        'vtt' => 'text/vtt; charset=UTF-8',
        'xml' => 'application/xml; charset=UTF-8',
        'yaml' => 'text/yaml; charset=UTF-8',
        'yml' => 'text/yaml; charset=UTF-8',
        'pdf' => 'application/pdf',
        'apng' => 'image/apng',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jxl' => 'image/jxl',
        'svg' => 'image/svg+xml',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'weba' => 'audio/webm',
        'avi' => 'video/x-msvideo',
        'm4v' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'mp4' => 'video/mp4',
        'ogv' => 'video/ogg',
        'webm' => 'video/webm',
    ];
    $fallback = $map[$ext] ?? 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = (string)@finfo_file($finfo, $fullPath);
            @finfo_close($finfo);
            if ($detected !== '' && strtolower($detected) !== 'application/octet-stream') {
                return $detected;
            }
        }
    }
    return $fallback;
}

function render_share_asset_not_found(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Resource not found';
    exit;
}

function build_share_asset_public_path(array $share, string $assetPath): string {
    $shareId = (int)($share['id'] ?? 0);
    $assetPath = sanitize_asset_path($assetPath);
    if ($shareId <= 0 || $assetPath === '') {
        return '';
    }
    if (str_starts_with($assetPath, comment_asset_prefix())) {
        return '/uploads/' . $assetPath;
    }
    return '/uploads/shares/' . $shareId . '/' . $assetPath;
}

function resolve_asset_resume_redirect_path(array $share): string {
    $raw = trim((string)($_GET['asset'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        return '';
    }
    $assetPath = sanitize_asset_path(rawurldecode($raw));
    if ($assetPath === '') {
        return '';
    }
    $asset = find_share_asset_row($shareId, $assetPath);
    if (!$asset) {
        return '';
    }
    return build_share_asset_public_path($share, $assetPath);
}

function redirect_to_share_page(array $share, string $assetPath = ''): void {
    $slug = trim((string)($share['slug'] ?? ''));
    if ($slug === '') {
        render_share_asset_not_found();
    }
    $path = '/s/' . rawurlencode($slug);
    $cleanAsset = sanitize_asset_path($assetPath);
    if ($cleanAsset !== '') {
        $path .= '?asset=' . rawurlencode($cleanAsset);
    }
    redirect($path);
}

function serve_share_asset(array $share, string $assetPath): void {
    global $config;
    $shareId = (int)($share['id'] ?? 0);
    if ($shareId <= 0) {
        render_share_asset_not_found();
    }
    $assetPath = sanitize_asset_path(rawurldecode($assetPath));
    if ($assetPath === '') {
        render_share_asset_not_found();
    }
    if (share_is_expired($share) || share_visitor_limit_reached($share)) {
        redirect_to_share_page($share, $assetPath);
    }
    if (share_requires_password($share) && !share_access_granted($shareId)) {
        redirect_to_share_page($share, $assetPath);
    }
    if (share_visitor_limit($share) > 0) {
        register_share_visitor($shareId, get_visitor_id());
    }
    $asset = find_share_asset_row($shareId, $assetPath);
    if (!$asset) {
        render_share_asset_not_found();
    }
    $filePath = sanitize_asset_path((string)($asset['file_path'] ?? ''));
    if ($filePath === '') {
        render_share_asset_not_found();
    }
    $uploadsDir = rtrim((string)($config['uploads_dir'] ?? (__DIR__ . '/uploads')), '/\\');
    $fullPath = $uploadsDir . '/' . ltrim($filePath, '/');
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        render_share_asset_not_found();
    }
    $inline = share_asset_allow_inline($assetPath);
    $mime = $inline ? detect_share_asset_mime($fullPath, $assetPath) : 'application/octet-stream';
    $disposition = $inline ? 'inline' : 'attachment';
    $filename = basename($assetPath);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'download.bin';
    }
    $escapedFilename = str_replace(
        ['\\', '"', "\r", "\n"],
        ['\\\\', '\\"', '', ''],
        $filename
    );
    $size = @filesize($fullPath);
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . $escapedFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');
    if (is_int($size) && $size >= 0) {
        header('Content-Length: ' . (string)$size);
    }
    @readfile($fullPath);
    exit;
}

function parse_legacy_upload_asset_request(string $path): ?array {
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    if ($normalized === '' || !str_starts_with($normalized, 'uploads/')) {
        return null;
    }
    $relative = substr($normalized, strlen('uploads/'));
    if (!is_string($relative) || $relative === '') {
        return null;
    }
    if (preg_match('#^shares/(\d+)/(.*)$#', $relative, $matches)) {
        $shareId = (int)($matches[1] ?? 0);
        $assetSuffix = rawurldecode((string)($matches[2] ?? ''));
        $assetPath = sanitize_asset_path($assetSuffix);
        if ($shareId > 0 && $assetPath !== '') {
            return [
                'shareId' => $shareId,
                'assetPath' => $assetPath,
            ];
        }
    }
    $commentPrefix = trim(comment_asset_prefix(), '/');
    if ($commentPrefix !== '' && preg_match('#^' . preg_quote($commentPrefix, '#') . '/(\d+)/(.*)$#', $relative, $matches)) {
        $shareId = (int)($matches[1] ?? 0);
        $assetSuffix = rawurldecode((string)($matches[2] ?? ''));
        $assetPath = sanitize_asset_path($commentPrefix . '/' . $shareId . '/' . $assetSuffix);
        if ($shareId > 0 && $assetPath !== '') {
            return [
                'shareId' => $shareId,
                'assetPath' => $assetPath,
            ];
        }
    }
    return null;
}

function handle_share_asset_request(string $slug, string $assetPath): void {
    $share = find_share_by_slug($slug);
    if (!$share) {
        render_share_asset_not_found();
    }
    serve_share_asset($share, $assetPath);
}

function handle_legacy_upload_asset_request(string $path): void {
    $parsed = parse_legacy_upload_asset_request($path);
    if (!$parsed) {
        render_share_asset_not_found();
    }
    $shareId = (int)($parsed['shareId'] ?? 0);
    $assetPath = (string)($parsed['assetPath'] ?? '');
    $share = find_share_by_id($shareId);
    if (!$share) {
        render_share_asset_not_found();
    }
    serve_share_asset($share, $assetPath);
}

function build_doc_tree(array $docs, ?string $activeId = null): array {
    $useParent = false;
    foreach ($docs as $doc) {
        if (isset($doc['parent_id']) && trim((string)$doc['parent_id']) !== '') {
            $useParent = true;
            break;
        }
    }
    if ($useParent) {
        $nodes = [];
        foreach ($docs as $docIndex => $doc) {
            $docId = trim((string)($doc['doc_id'] ?? ''));
            if ($docId === '') {
                continue;
            }
            $nodes[$docId] = [
                'title' => (string)($doc['title'] ?? $docId),
                'children' => [],
                'doc' => $doc,
                'contains_active' => false,
                'order' => is_numeric($doc['sort_index'] ?? null) ? (float)$doc['sort_index'] : $docIndex,
                'order_index' => $docIndex,
            ];
        }
        $tree = [];
        foreach ($nodes as $docId => &$node) {
            $parentId = trim((string)($node['doc']['parent_id'] ?? ''));
            if ($parentId !== '' && isset($nodes[$parentId]) && $parentId !== $docId) {
                $nodes[$parentId]['children'][$docId] =& $node;
            } else {
                $tree[$docId] =& $node;
            }
        }
        unset($node);
        mark_doc_tree_active($tree, $activeId);
        sort_doc_tree($tree);
        return $tree;
    }
    $root = ['children' => []];
    foreach ($docs as $docIndex => $doc) {
        $hpath = trim((string)($doc['hpath'] ?? ''), '/');
        $parts = $hpath !== '' ? array_values(array_filter(explode('/', $hpath))) : [];
        if (empty($parts)) {
            $parts = [trim((string)($doc['title'] ?? '')) ?: trim((string)($doc['doc_id'] ?? ''))];
        }
        $docOrder = isset($doc['sort_order']) ? (int)$doc['sort_order'] : $docIndex;
        $docOrderIndex = $docIndex;
        $node =& $root['children'];
        foreach ($parts as $idx => $part) {
            $isLast = $idx === count($parts) - 1;
            $key = $part;
            if (!isset($node[$key])) {
                $node[$key] = [
                    'title' => $part,
                    'children' => [],
                    'doc' => null,
                    'contains_active' => false,
                    'order' => $docOrder,
                    'order_index' => $docOrderIndex,
                ];
            } else {
                $existingOrder = $node[$key]['order'] ?? $docOrder;
                $existingIndex = $node[$key]['order_index'] ?? $docOrderIndex;
                if ($docOrder < $existingOrder || ($docOrder === $existingOrder && $docOrderIndex < $existingIndex)) {
                    $node[$key]['order'] = $docOrder;
                    $node[$key]['order_index'] = $docOrderIndex;
                }
            }
            if ($isLast) {
                $node[$key]['doc'] = $doc;
                $node[$key]['order'] = min($node[$key]['order'] ?? $docOrder, $docOrder);
                $node[$key]['order_index'] = min($node[$key]['order_index'] ?? $docOrderIndex, $docOrderIndex);
            }
            $node =& $node[$key]['children'];
        }
    }
    $tree = $root['children'];
    mark_doc_tree_active($tree, $activeId);
    sort_doc_tree($tree);
    return $tree;
}

function mark_doc_tree_active(array &$nodes, ?string $activeId = null): bool {
    $hasActive = false;
    foreach ($nodes as &$node) {
        $nodeHas = false;
        $doc = $node['doc'] ?? null;
        if ($activeId && $doc && (string)$doc['doc_id'] === (string)$activeId) {
            $nodeHas = true;
        }
        if (!empty($node['children'])) {
            $childHas = mark_doc_tree_active($node['children'], $activeId);
            $nodeHas = $nodeHas || $childHas;
        }
        $node['contains_active'] = $nodeHas;
        if ($nodeHas) {
            $hasActive = true;
        }
    }
    unset($node);
    return $hasActive;
}

function sort_doc_tree(array &$nodes): void {
    foreach ($nodes as &$node) {
        if (!empty($node['children'])) {
            sort_doc_tree($node['children']);
        }
    }
    unset($node);
    uasort($nodes, function ($a, $b) {
        $orderA = $a['order'] ?? PHP_INT_MAX;
        $orderB = $b['order'] ?? PHP_INT_MAX;
        if ($orderA === $orderB) {
            $idxA = $a['order_index'] ?? PHP_INT_MAX;
            $idxB = $b['order_index'] ?? PHP_INT_MAX;
            if ($idxA === $idxB) {
                return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            }
            return $idxA <=> $idxB;
        }
        return $orderA <=> $orderB;
    });
}

function decode_hex_emoji_string(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return $raw;
    }
    $raw = preg_replace('/^u\+/i', '', $raw);
    $raw = str_replace(' ', '', $raw);
    if (!preg_match('/^(?:0x)?[0-9a-f]{4,6}(?:-(?:0x)?[0-9a-f]{4,6})*$/i', $raw)) {
        return $value;
    }
    $parts = explode('-', strtolower($raw));
    $out = '';
    foreach ($parts as $part) {
        $part = preg_replace('/^0x/', '', $part);
        if ($part === '') {
            continue;
        }
        $out .= html_entity_decode('&#x' . $part . ';', ENT_NOQUOTES, 'UTF-8');
    }
    return $out !== '' ? $out : $value;
}

function normalize_doc_icon_value($value): string {
    if ($value === null) {
        return '';
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) ||
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        ) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return normalize_doc_icon_value($decoded);
            }
        }
        return decode_hex_emoji_string($trimmed);
    }
    if (is_numeric($value)) {
        return decode_hex_emoji_string((string)$value);
    }
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (is_array($value)) {
        $keys = ['icon', 'value', 'emoji', 'iconEmoji', 'iconValue', 'path', 'file', 'asset', 'assetPath', 'src', 'url'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = normalize_doc_icon_value($value[$key]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        foreach ($value as $item) {
            $candidate = normalize_doc_icon_value($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }
    return '';
}

function is_doc_icon_image_value(string $icon): bool {
    if ($icon === '') {
        return false;
    }
    if (preg_match('/^data:image\//i', $icon)) {
        return true;
    }
    if (preg_match('/^https?:\/\//i', $icon)) {
        return true;
    }
    if (strpos($icon, '/') !== false || preg_match('/\.(svg|png|jpe?g|gif|webp|bmp)$/i', $icon)) {
        return true;
    }
    return false;
}

function build_doc_icon_src(string $icon, string $assetBasePath): string {
    if ($icon === '') {
        return '';
    }
    if (preg_match('/^data:image\//i', $icon) || preg_match('/^https?:\/\//i', $icon)) {
        return $icon;
    }
    $path = sanitize_asset_path($icon);
    if ($path === '') {
        return '';
    }
    $prefix = $assetBasePath;
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    return $prefix . encode_path_segments($path);
}

function render_doc_tree_icon(?array $doc, string $assetBasePath): string {
    $icon = $doc ? normalize_doc_icon_value($doc['icon'] ?? '') : '';
    if ($icon !== '' && is_doc_icon_image_value($icon)) {
        $src = build_doc_icon_src($icon, $assetBasePath);
        if ($src !== '') {
            return '<img class="kb-tree-icon kb-tree-icon--image" src="' . htmlspecialchars($src) . '" alt="">';
        }
    }
    if ($icon !== '' && !is_doc_icon_image_value($icon)) {
        return '<span class="kb-tree-icon kb-tree-icon--emoji">' . htmlspecialchars($icon) . '</span>';
    }
    return '';
}

function render_share_search_box(string $slug): string {
    $html  = '<div class="kb-search" data-share-search>';
    $html .= '<div class="kb-search-input-wrap">';
    $html .= '<svg class="kb-search-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35"/></svg>';
    $html .= '<input class="kb-search-input" type="search" placeholder="搜索..." data-share-search-input autocomplete="off" spellcheck="false">';
    $html .= '<button class="kb-search-clear" type="button" data-share-search-clear hidden aria-label="清除搜索">&times;</button>';
    $html .= '</div>';
    $html .= '<div class="kb-search-results" data-share-search-results hidden></div>';
    $html .= '</div>';
    return $html;
}

function render_share_recent_list(array $docs, string $slug, int $limit = 5): string {
    $recent = [];
    foreach ($docs as $doc) {
        $recent[] = [
            'doc_id' => (string)($doc['doc_id'] ?? ''),
            'title' => trim((string)($doc['title'] ?? '')),
            'updated_at' => (string)($doc['updated_at'] ?? ''),
        ];
    }
    usort($recent, function (array $a, array $b): int {
        $ta = strtotime($a['updated_at']) ?: 0;
        $tb = strtotime($b['updated_at']) ?: 0;
        return $tb <=> $ta;
    });
    $recent = array_slice($recent, 0, max(1, $limit));
    $base = base_path();
    $items = '';
    foreach ($recent as $row) {
        $title = $row['title'] !== '' ? $row['title'] : $row['doc_id'];
        $time = format_share_datetime($row['updated_at']);
        $href = $base . '/s/' . rawurlencode($slug) . '/' . rawurlencode($row['doc_id']);
        $items .= '<a class="share-recent-item" href="' . htmlspecialchars($href) . '" data-doc-id="' . htmlspecialchars($row['doc_id']) . '" data-share-nav="doc">';
        $items .= '<span class="share-recent-title">' . htmlspecialchars($title) . '</span>';
        if ($time !== '') {
            $items .= '<span class="share-recent-time">' . htmlspecialchars($time) . '</span>';
        }
        $items .= '</a>';
    }
    if ($items === '') {
        return '<div class="share-recent-empty">暂无更新文档</div>';
    }
    return '<div class="share-recent-list">' . $items . '</div>';
}

function render_share_gutter(string $side, string $label): string {
    $html = '<div class="share-gutter share-gutter--' . $side . '" data-share-gutter="' . $side . '" role="separator" aria-orientation="vertical" aria-label="' . htmlspecialchars($label) . '">';
    $html .= '<button class="share-gutter-toggle" type="button" data-share-gutter-toggle="' . $side . '" title="收起/展开" aria-label="收起/展开">';
    $html .= '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59 10.83 12l4.58-4.59L14 6l-6 6 6 6z"/></svg>';
    $html .= '</button>';
    $html .= '<span class="share-gutter-label" aria-hidden="true">' . htmlspecialchars($label) . '</span>';
    $html .= '</div>';
    return $html;
}

function render_share_right_rail(array $docs, string $slug, bool $includeRecent = true): string {
    $html = render_share_gutter('right', '目录');
    $html .= '<div class="share-right">';
    $html .= '<div class="share-panel share-panel--toc" data-share-toc="doc">';
    $html .= '<div class="share-panel-head"><span class="share-panel-title">目录</span></div>';
    $html .= '<div class="share-panel-body share-toc-body"></div>';
    $html .= '</div>';
    if ($includeRecent) {
        $html .= '<div class="share-panel share-panel--recent">';
        $html .= '<div class="share-panel-head">';
        $html .= '<span class="share-panel-title">最近更新</span>';
        $html .= '<select class="share-recent-limit" data-share-recent-limit aria-label="显示数量">';
        $html .= '<option value="5" selected>5</option>';
        $html .= '<option value="10">10</option>';
        $html .= '<option value="20">20</option>';
        $html .= '<option value="50">50</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div class="share-panel-body share-recent-body">' . render_share_recent_list($docs, $slug, 50) . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_share_sidebar_info(int $total, bool $withHint = true): string {
    $html  = '<div class="kb-side-info">';
    $html .= '<div class="kb-side-count">共 ' . $total . ' 篇文档</div>';
    if ($withHint) {
        $html .= '<div class="kb-side-hint">拖拽边缘调宽度 · 圆钮收起/展开</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_share_announcement(array $share, array $docs, string $assetBasePath, int $shareId, callable $loadDocMarkdown, string $slug = ''): string {
    $mode = (string)($share['announcement_mode'] ?? 'none');
    $announceTitle = '';
    $announceBody = '';
    $announceSource = '';
    $hasAnnounce = false;
    if (in_array($mode, ['manual', 'doc'], true)) {
        $title = '';
        $bodyHtml = '';
        if ($mode === 'manual') {
            $content = trim((string)($share['announcement_content'] ?? ''));
            if ($content !== '') {
                $title = trim((string)($share['announcement_title'] ?? ''));
                $announceSource = $content;
                $bodyHtml = render_markdown($content);
            }
        } else {
            $docId = (string)($share['announcement_doc_id'] ?? '');
            $doc = null;
            if ($docId !== '') {
                foreach ($docs as $item) {
                    if ((string)($item['doc_id'] ?? '') === $docId) {
                        $doc = $item;
                        break;
                    }
                }
            }
            if ($doc) {
                $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $docId;
                $front = extract_front_matter($loadDocMarkdown($doc));
                $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
                $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
                $markdown = replace_custom_emoji_tokens($markdown, $shareId, $assetBasePath);
                $markdown = insert_adjacent_emoji_image_spacing($markdown);
                $announceSource = $markdown;
                $bodyHtml = render_markdown($markdown);
                $title = $docTitleRaw;
            }
        }
        if ($bodyHtml !== '') {
            $hasAnnounce = true;
            $announceTitle = $title;
            $announceBody = $bodyHtml;
        }
    }
    $tipsRaw = trim((string)get_setting('site_tips', ''));
    $hasTips = $tipsRaw !== '';
    if (!$hasAnnounce && !$hasTips) {
        return '';
    }
    $megaphoneIcon = '<svg class="share-announce-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 9a1 1 0 0 1 1-1h2l8-4v16l-8-4H5a1 1 0 0 1-1-1V9zm14.5-.5a5 5 0 0 1 0 7M18 6.2a8 8 0 0 1 0 11.6"/></svg>';
    $lightbulbIcon = '<svg class="share-announce-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2C8.13 2 5 5.13 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.87-3.13-7-7-7zM9 21h6v-2H9v2z"/></svg>';
    $closeIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3 10.6 10.6 16.9 4.3z"/></svg>';
    $html = '<div class="share-announce" data-share-announce data-announce-slug="' . htmlspecialchars($slug) . '">';
    $html .= '<div class="share-announce-backdrop" data-share-announce-close></div>';
    if ($hasAnnounce) {
        $announceLabel = htmlspecialchars($announceTitle !== '' ? $announceTitle : '公告');
        $html .= '<div class="share-announce-modal share-announce-modal--announce" role="dialog" aria-modal="true" aria-label="' . $announceLabel . '" data-share-announce-panel="announce">';
        $html .= '<div class="share-announce-modal__header">';
        $html .= $megaphoneIcon;
        $html .= '<span class="share-announce-modal__title">' . $announceLabel . '</span>';
        $html .= '<button class="share-announce-modal__close" type="button" data-share-announce-close aria-label="关闭公告">' . $closeIcon . '</button>';
        $html .= '</div>';
        $html .= '<div class="share-announce-modal__layout" data-announce-layout>';
        $tocChevronIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8.6 4.6 15 12l-6.4 7.4-1.4-1.2L12.2 12 7.2 5.8z"/></svg>';
        $html .= '<aside class="share-announce-toc" data-announce-toc hidden>';
        $html .= '<div class="share-announce-toc__head">';
        $html .= '<span class="share-announce-toc__title">目录</span>';
        $html .= '<button class="share-announce-toc__collapse" type="button" data-announce-toc-collapse aria-label="收起目录" title="收起目录" aria-expanded="true">' . $tocChevronIcon . '</button>';
        $html .= '</div>';
        $html .= '<div class="share-announce-toc__body" data-announce-toc-body></div>';
        $html .= '</aside>';
        $html .= '<button class="share-announce-toc-reopen" type="button" data-announce-toc-reopen aria-label="展开目录" title="展开目录" hidden>' . $tocChevronIcon . '</button>';
        $html .= '<div class="share-announce-toc__resizer" data-announce-toc-resize aria-hidden="true" hidden></div>';
        $html .= '<div class="share-announce-modal__body markdown-body" data-share-announce-body data-md-id="share-announce">' . $announceBody . '</div>';
        $html .= '</div>';
        $html .= '<textarea class="markdown-source" data-md-id="share-announce" hidden>' . htmlspecialchars($announceSource) . '</textarea>';
        $html .= '</div>';
    }
    if ($hasTips) {
        $html .= '<div class="share-announce-modal share-announce-modal--tips" role="dialog" aria-modal="true" aria-label="使用技巧" data-share-announce-panel="tips">';
        $html .= '<div class="share-announce-modal__header">';
        $html .= $lightbulbIcon;
        $html .= '<span class="share-announce-modal__title">使用技巧</span>';
        $html .= '<button class="share-announce-modal__close" type="button" data-share-announce-close aria-label="关闭使用技巧">' . $closeIcon . '</button>';
        $html .= '</div>';
        $html .= '<div class="share-announce-modal__body markdown-body" data-share-announce-body>' . render_markdown($tipsRaw) . '</div>';
        $html .= '</div>';
    }
    if ($hasAnnounce) {
        $html .= '<button class="share-announce-ball" type="button" data-share-announce-toggle="announce" data-tip="打开公告" aria-label="打开公告" aria-expanded="false">' . $megaphoneIcon . '</button>';
    }
    if ($hasTips) {
        $html .= '<button class="share-tips-ball" type="button" data-share-announce-toggle="tips" data-tip="使用技巧" aria-label="使用技巧" aria-expanded="false">' . $lightbulbIcon . '</button>';
    }
    $html .= '</div>';
    return $html;
}

function render_doc_tree(array $nodes, string $slug, ?string $activeId = null, int $level = 0, string $path = '', string $assetBasePath = ''): string {
    if (empty($nodes)) {
        return '';
    }
    $html = '<ul class="kb-tree kb-tree-children" data-level="' . $level . '">';
    foreach ($nodes as $node) {
        $doc = $node['doc'] ?? null;
        $hasChildren = !empty($node['children']);
        $isActive = $doc && $activeId && (string)$doc['doc_id'] === (string)$activeId;
        $shouldOpen = false;
        $nodeLabel = $doc ? (string)($doc['doc_id'] ?? '') : (string)($node['title'] ?? '');
        $pathKey = trim($path . '/' . $nodeLabel, '/');
        $nodeKey = $doc ? ('doc:' . $nodeLabel) : ('folder:' . $pathKey);
        $nodeClass = 'kb-tree-node';
        if ($hasChildren) {
            $nodeClass .= $shouldOpen ? ' is-open' : ' is-collapsed';
        }
        $html .= '<li class="' . $nodeClass . '" data-tree-key="' . htmlspecialchars($nodeKey) . '">';
        $html .= '<div class="kb-tree-row">';
        if ($hasChildren) {
            $html .= '<button class="kb-tree-toggle" type="button" aria-expanded="' . ($shouldOpen ? 'true' : 'false') . '">';
            $html .= '<svg class="kb-tree-toggle-icon kb-tree-toggle-icon--collapsed" viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-arrow-collapsed"></use></svg>';
            $html .= '<svg class="kb-tree-toggle-icon kb-tree-toggle-icon--open" viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-arrow-expanded"></use></svg>';
            $html .= '</button>';
        } else {
            $html .= '<span class="kb-tree-spacer"></span>';
        }
        if ($doc) {
            $docId = (string)$doc['doc_id'];
            $docTitle = htmlspecialchars($doc['title'] ?? $docId);
            $docPath = base_path() . '/s/' . $slug . '/' . rawurlencode($docId);
            $activeClass = $isActive ? ' is-active' : '';
            $docAttrs = ' href="' . $docPath . '" data-doc-id="' . htmlspecialchars($docId) . '" data-share-nav="doc"';
            $html .= '<a class="kb-tree-item' . $activeClass . '"' . $docAttrs . '>';
            $html .= render_doc_tree_icon($doc, $assetBasePath);
            $html .= '<span class="kb-tree-label">' . $docTitle . '</span></a>';
        } else {
            $html .= '<div class="kb-tree-folder">';
            $html .= render_doc_tree_icon(null, $assetBasePath);
            $html .= '<span class="kb-tree-label">' . htmlspecialchars((string)$node['title']) . '</span></div>';
        }
        $html .= '</div>';
        if ($hasChildren) {
            $html .= render_doc_tree($node['children'], $slug, $activeId, $level + 1, $pathKey, $assetBasePath);
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function build_breadcrumbs(string $hpath): array {
    $trimmed = trim($hpath, '/');
    if ($trimmed === '') {
        return [];
    }
    return array_values(array_filter(explode('/', $trimmed)));
}

function is_share_partial_request(): bool {
    if (!empty($_GET['partial']) && (string)$_GET['partial'] === '1') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_SPS_PARTIAL']) && (string)$_SERVER['HTTP_X_SPS_PARTIAL'] === '1') {
        return true;
    }
    return false;
}

function route_share(string $slug, ?string $docId = null): void {
    $share = find_share_by_slug($slug);
    if (!$share) {
        render_share_not_found_page();
        return;
    }
    $shareId = (int)$share['id'];
    $viewer = current_user();
    $shareTitleRaw = (string)$share['title'];
    $shareTitle = htmlspecialchars($shareTitleRaw);
    $redirectPath = '/s/' . $slug . ($docId ? '/' . rawurlencode($docId) : '');
    $resumeAssetPath = resolve_asset_resume_redirect_path($share);
    $isPartial = is_share_partial_request();

    if (share_is_expired($share)) {
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-empty">该分享已过期，内容不可见。</div>';
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    if (share_visitor_limit_reached($share)) {
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-empty">访客数已达上限，分享已关闭。</div>';
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    if (share_requires_password($share) && !share_access_granted($shareId)) {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = trim((string)($_POST['share_password'] ?? ''));
            if ($input !== '' && password_verify($input, (string)$share['password_hash'])) {
                grant_share_access($shareId);
                if ($resumeAssetPath !== '') {
                    redirect($resumeAssetPath);
                }
                redirect($redirectPath);
            }
            $error = '访问密码错误';
        }
        $content = '<div class="share-shell share-shell--single">';
        $content .= '<div class="share-content">';
        $content .= '<div class="share-header"><h1>' . $shareTitle . '</h1></div>';
        $content .= '<div class="share-gate">';
        $content .= '<div class="share-gate-note">该分享设置了访问密码</div>';
        if ($error) {
            $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
        }
        $content .= '<form method="post" class="share-gate-form">';
        $content .= '<input class="input" type="password" name="share_password" placeholder="请输入访问密码" required>';
        $content .= '<button class="button primary" type="submit">验证</button>';
        $content .= '</form></div></div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
        return;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, doc_id, title, icon, hpath, parent_id, sort_index, sort_order, updated_at FROM share_docs WHERE share_id = :sid ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':sid' => $shareId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $docMarkdownStmt = $pdo->prepare('SELECT markdown FROM share_docs WHERE share_id = :sid AND id = :id LIMIT 1');
    $loadDocMarkdown = function (array $doc) use ($docMarkdownStmt, $shareId): string {
        $rowId = (int)($doc['id'] ?? 0);
        if ($rowId <= 0) {
            return '';
        }
        $docMarkdownStmt->execute([
            ':sid' => $shareId,
            ':id' => $rowId,
        ]);
        $markdown = $docMarkdownStmt->fetchColumn();
        $docMarkdownStmt->closeCursor();
        return is_string($markdown) ? $markdown : '';
    };
    if (empty($docs)) {
        render_share_not_found_page();
        return;
    }
    $accessCountRecorded = false;
    $touchShareAccess = function () use (&$accessCountRecorded, &$share, $shareId, $pdo): void {
        if ($accessCountRecorded) {
            return;
        }
        if (share_visitor_limit($share) > 0) {
            register_share_visitor($shareId, get_visitor_id());
        }
        $pdo->prepare('UPDATE shares SET access_count = access_count + 1 WHERE id = :id')
            ->execute([':id' => $shareId]);
        $share['access_count'] = (int)($share['access_count'] ?? 0) + 1;
        $accessCountRecorded = true;
    };

    $assetBasePath = base_path() . '/uploads/shares/' . $shareId . '/';
    $announcementHtml = render_share_announcement($share, $docs, $assetBasePath, (int)$shareId, $loadDocMarkdown, $slug);

    if ($share['type'] === 'doc') {
        $hasMultipleDocs = count($docs) > 1;
        $activeDocId = $docId ?: (string)($share['doc_id'] ?? ($docs[0]['doc_id'] ?? ''));
        if ($hasMultipleDocs) {
            $doc = null;
            foreach ($docs as $item) {
                if ((string)($item['doc_id'] ?? '') === (string)$activeDocId) {
                    $doc = $item;
                    break;
                }
            }
            if (!$doc && !$docId && !empty($docs)) {
                $doc = $docs[0];
                $activeDocId = (string)($doc['doc_id'] ?? '');
            }
            if (!$doc) {
                render_share_not_found_page();
                return;
            }
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
            $docTitle = htmlspecialchars($docTitleRaw);
            $front = extract_front_matter($loadDocMarkdown($doc));
            $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
            $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
            $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
            $markdown = insert_adjacent_emoji_image_spacing($markdown);
            $touchShareAccess();
            $reportTrigger = render_share_report_trigger($share);
            $shareMetaHtml = render_share_stats($share, $reportTrigger);
            record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
            $commentHtml = render_share_comments($share, $viewer, (string)$activeDocId);
            $reportModalHtml = render_share_report_form($share, $viewer, (string)$activeDocId);
            $treeHtml = render_doc_tree(build_doc_tree($docs, $activeDocId), $slug, $activeDocId, 0, '', $assetBasePath);
            $sidebar = '<aside class="kb-sidebar" data-share-sidebar data-share-slug="' . htmlspecialchars($slug) . '">';
            $sidebar .= render_share_sidebar_info(count($docs), true);
            $sidebar .= render_share_search_box($slug);
            $sidebar .= '<div class="kb-side-tabs" data-share-tabs data-share-default="tree">';
            $sidebar .= '<button class="kb-side-tab is-active" type="button" data-share-tab="tree">文档树</button>';
            $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="toc">目录</button>';
            $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="recent">最近更新</button>';
            if (netdisk_tab_state($share)['show']) {
                $sidebar .= render_netdisk_tab_button();
            }
            $sidebar .= '<div class="kb-side-actions" data-share-tree-actions>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-collapse title="折叠所有" aria-label="折叠所有"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-collapse-all"></use></svg></button>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-expand title="展开所有" aria-label="展开所有"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-expand-all"></use></svg></button>';
            $sidebar .= '</div>';
            $sidebar .= '</div>';
            $sidebar .= '<div class="kb-side-panel" data-share-panel="tree">';
            $sidebar .= '<div class="kb-side-body">' . $treeHtml . '</div>';
            $sidebar .= '</div>';
            $sidebar .= '<div class="kb-side-panel" data-share-panel="toc" data-share-toc="doc" hidden>';
            $sidebar .= '<div class="kb-side-body share-toc-body"></div>';
            $sidebar .= '</div>';
            $sidebar .= '<div class="kb-side-panel" data-share-panel="recent" hidden>';
            $sidebar .= '<div class="kb-side-body share-recent-body">' . render_share_recent_list($docs, $slug, 5) . '</div>';
            $sidebar .= '</div>';
            if (netdisk_tab_state($share)['show']) {
                $sidebar .= render_netdisk_panel($slug);
            }
            $sidebar .= '</aside>';
            $base = base_path();
            $crumbs = build_breadcrumbs((string)($doc['hpath'] ?? ''));
            if (!empty($crumbs)) {
                array_pop($crumbs);
            }
            if ($shareTitleRaw !== '' && !empty($crumbs)) {
                while (!empty($crumbs) && $crumbs[0] === $shareTitleRaw) {
                    array_shift($crumbs);
                }
            }
            $filteredCrumbs = [];
            $prevCrumb = null;
            foreach ($crumbs as $crumb) {
                if ($crumb === $prevCrumb) {
                    continue;
                }
                $filteredCrumbs[] = $crumb;
                $prevCrumb = $crumb;
            }
            $crumbs = $filteredCrumbs;
            $breadcrumbsHtml = '<div class="kb-breadcrumbs"><a class="kb-back" href="' . $base . '/s/' . $slug . '" data-doc-id="" data-share-nav="doc">' . htmlspecialchars($shareTitleRaw) . '</a>';
            foreach ($crumbs as $crumb) {
                $breadcrumbsHtml .= '<span>' . htmlspecialchars($crumb) . '</span>';
            }
            $breadcrumbsHtml .= '</div>';
            $mainHtml = '<div class="kb-main">';
            $mainHtml .= '<div class="share-article" data-share-view="preview">';
            $mainHtml .= '<div class="kb-header">' . $breadcrumbsHtml;
            $mainHtml .= '<div class="kb-title-row">';
            $mainHtml .= '<h1 class="kb-title">' . $docTitle . '</h1>';
            $mainHtml .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">源码</button>';
            $mainHtml .= '</div>';
            $mainHtml .= $shareMetaHtml;
            $mainHtml .= '</div>';
            $mainHtml .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
            $mainHtml .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown 源码">' . htmlspecialchars($markdown) . '</textarea>';
            $mainHtml .= $commentHtml;
            $mainHtml .= $reportModalHtml;
            $mainHtml .= '</div></div>';
            if ($isPartial) {
                api_response(200, [
                    'title' => $docTitleRaw,
                    'docId' => $activeDocId,
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="' . htmlspecialchars($activeDocId) . '">';
            $content .= $sidebar;
            $content .= render_share_gutter('left', '文档树');
            $content .= $mainHtml;
            $content .= render_share_right_rail($docs, $slug, true);
            $content .= '</div>';
            render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true, 'announcement' => $announcementHtml]);
            return;
        }

        $doc = $docs[0];
        $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
        $docTitle = htmlspecialchars($docTitleRaw);
        $front = extract_front_matter($loadDocMarkdown($doc));
        $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
        $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
        $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
        $markdown = insert_adjacent_emoji_image_spacing($markdown);
        $touchShareAccess();
        $reportTrigger = render_share_report_trigger($share);
        $shareMetaHtml = render_share_stats($share, $reportTrigger);
        record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
        $commentHtml = render_share_comments($share, $viewer, null);
        $reportModalHtml = render_share_report_form($share, $viewer, null);
        $content = '<div class="share-shell share-shell--notebook share-shell--single-doc">';
        $content .= '<div class="kb-main">';
        $content .= '<div class="share-article" data-share-view="preview">';
        $content .= '<div class="kb-header">';
        $content .= '<div class="kb-title-row">';
        $content .= '<h1 class="kb-title">' . $docTitle . '</h1>';
        $content .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">源码</button>';
        $content .= '</div>';
        $content .= $shareMetaHtml;
        $content .= '</div>';
        $content .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
        $content .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown 源码">' . htmlspecialchars($markdown) . '</textarea>';
        $content .= $commentHtml;
        $content .= $reportModalHtml;
        $content .= '</div></div>';
        $content .= render_share_right_rail($docs, $slug, false);
        $content .= '</div>';
        render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true, 'announcement' => $announcementHtml]);
    }

    if ($share['type'] === 'notebook') {
        if (!$docId
            && (string)($share['announcement_mode'] ?? 'none') === 'doc'
            && (string)($share['announcement_doc_id'] ?? '') !== ''
        ) {
            $announceDocId = (string)$share['announcement_doc_id'];
            foreach ($docs as $item) {
                if ((string)($item['doc_id'] ?? '') === $announceDocId) {
                    $docId = $announceDocId;
                    break;
                }
            }
        }
        $treeHtml = render_doc_tree(build_doc_tree($docs, $docId), $slug, $docId, 0, '', $assetBasePath);
        $sidebar = '<aside class="kb-sidebar" data-share-sidebar data-share-slug="' . htmlspecialchars($slug) . '">';
        $sidebar .= render_share_sidebar_info(count($docs), (bool)$docId);
        $sidebar .= render_share_search_box($slug);
        $sidebar .= '<div class="kb-side-tabs" data-share-tabs data-share-default="tree">';
            $sidebar .= '<button class="kb-side-tab is-active" type="button" data-share-tab="tree">文档树</button>';
        $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="toc">目录</button>';
        $sidebar .= '<button class="kb-side-tab" type="button" data-share-tab="recent">最近更新</button>';
        if (netdisk_tab_state($share)['show']) {
            $sidebar .= render_netdisk_tab_button();
        }
        $sidebar .= '<div class="kb-side-actions" data-share-tree-actions>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-collapse title="折叠所有" aria-label="折叠所有"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-collapse-all"></use></svg></button>';
            $sidebar .= '<button class="kb-side-action" type="button" data-tree-expand title="展开所有" aria-label="展开所有"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#sps-tree-expand-all"></use></svg></button>';
        $sidebar .= '</div>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="tree">';
        $sidebar .= '<div class="kb-side-body">' . $treeHtml . '</div>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="toc" data-share-toc="doc" hidden>';
        $sidebar .= '<div class="kb-side-body share-toc-body"></div>';
        $sidebar .= '</div>';
        $sidebar .= '<div class="kb-side-panel" data-share-panel="recent" hidden>';
        $sidebar .= '<div class="kb-side-body share-recent-body">' . render_share_recent_list($docs, $slug, 5) . '</div>';
        $sidebar .= '</div>';
        if (netdisk_tab_state($share)['show']) {
            $sidebar .= render_netdisk_panel($slug);
        }
        $sidebar .= '</aside>';
        $base = base_path();

        if ($docId) {
            $doc = null;
            foreach ($docs as $item) {
                if ((string)$item['doc_id'] === (string)$docId) {
                    $doc = $item;
                    break;
                }
            }
            if (!$doc) {
                render_share_not_found_page();
                return;
            }
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: $shareTitleRaw;
            $docTitle = htmlspecialchars($docTitleRaw);
            $front = extract_front_matter($loadDocMarkdown($doc));
            $markdown = rewrite_asset_links((string)$front['body'], $assetBasePath);
            $markdown = strip_duplicate_title_heading($markdown, $docTitleRaw);
            $markdown = replace_custom_emoji_tokens($markdown, (int)$shareId, $assetBasePath);
            $markdown = insert_adjacent_emoji_image_spacing($markdown);
            $touchShareAccess();
            $reportTrigger = render_share_report_trigger($share);
            $shareMetaHtml = render_share_stats($share, $reportTrigger);
            record_share_access($share, (string)($doc['doc_id'] ?? ''), $docTitleRaw);
            $commentHtml = render_share_comments($share, $viewer, (string)$docId);
            $reportModalHtml = render_share_report_form($share, $viewer, (string)$docId);
            $crumbs = build_breadcrumbs((string)($doc['hpath'] ?? ''));
            if (!empty($crumbs)) {
                array_pop($crumbs);
            }
            $breadcrumbsHtml = '<div class="kb-breadcrumbs"><a class="kb-back" href="' . $base . '/s/' . $slug . '" data-doc-id="" data-share-nav="doc">目录</a>';
            foreach ($crumbs as $crumb) {
                $breadcrumbsHtml .= '<span>' . htmlspecialchars($crumb) . '</span>';
            }
            $breadcrumbsHtml .= '</div>';
            $mainHtml = '<div class="kb-main">';
            $mainHtml .= '<div class="share-article" data-share-view="preview">';
            $mainHtml .= '<div class="kb-header">' . $breadcrumbsHtml;
            $mainHtml .= '<div class="kb-title-row">';
            $mainHtml .= '<h1 class="kb-title">' . $docTitle . '</h1>';
            $mainHtml .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">源码</button>';
            $mainHtml .= '</div>';
            $mainHtml .= $shareMetaHtml;
            $mainHtml .= '</div>';
            $mainHtml .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($markdown) . '</div>';
            $mainHtml .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown 源码">' . htmlspecialchars($markdown) . '</textarea>';
            $mainHtml .= $commentHtml;
            $mainHtml .= $reportModalHtml;
            $mainHtml .= '</div></div>';
            if ($isPartial) {
                api_response(200, [
                    'title' => $docTitleRaw,
                    'docId' => $docId,
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="' . htmlspecialchars((string)$docId) . '">';
            $content .= $sidebar;
            $content .= render_share_gutter('left', '文档树');
            $content .= $mainHtml;
            $content .= render_share_right_rail($docs, $slug, true);
            $content .= '</div>';
            render_page($docTitleRaw, $content, null, '', ['layout' => 'share', 'markdown' => true, 'announcement' => $announcementHtml]);
        }

        if (!$docId) {
            $touchShareAccess();
            $reportTrigger = render_share_report_trigger($share);
            $shareMetaHtml = render_share_stats($share, $reportTrigger);
            $announceTitle = '';
            $announceMarkdown = '';
            if ((string)($share['announcement_mode'] ?? 'none') === 'manual') {
                $announceMarkdown = trim((string)($share['announcement_content'] ?? ''));
                if ($announceMarkdown !== '') {
                    $announceTitle = trim((string)($share['announcement_title'] ?? '')) ?: '公告';
                }
            }
            if ($announceMarkdown !== '') {
                $announceTitleEsc = htmlspecialchars($announceTitle);
                $mainHtml = '<div class="kb-main">';
                $mainHtml .= '<div class="share-article" data-share-view="preview">';
                $mainHtml .= '<div class="kb-header">';
                $mainHtml .= '<div class="kb-breadcrumbs"><a class="kb-back" href="' . $base . '/s/' . $slug . '" data-doc-id="" data-share-nav="doc">目录</a><span>公告</span></div>';
                $mainHtml .= '<div class="kb-title-row">';
                $mainHtml .= '<h1 class="kb-title">' . $announceTitleEsc . '</h1>';
                $mainHtml .= '<button class="button ghost share-view-toggle" type="button" data-share-toggle aria-pressed="false">源码</button>';
                $mainHtml .= '</div>';
                $mainHtml .= $shareMetaHtml;
                $mainHtml .= '</div>';
                $mainHtml .= '<div class="markdown-body" data-md-id="doc">' . render_markdown($announceMarkdown) . '</div>';
                $mainHtml .= '<textarea class="markdown-source" data-md-id="doc" readonly spellcheck="false" aria-label="Markdown 源码">' . htmlspecialchars($announceMarkdown) . '</textarea>';
                $mainHtml .= '</div></div>';
            } else {
                $mainHtml = '<div class="kb-main"><div class="share-empty">请先在文档树里面先打开一个文档</div></div>';
            }
            if ($isPartial) {
                api_response(200, [
                    'title' => $announceMarkdown !== '' ? $announceTitle : $shareTitleRaw,
                    'docId' => '',
                    'html' => $mainHtml,
                ]);
            }
            $content = '<div class="share-shell share-shell--notebook" data-share-doc-id="">';
            $content .= $sidebar;
            $content .= render_share_gutter('left', '文档树');
            $content .= $mainHtml;
            $content .= render_share_right_rail($docs, $slug, true);
            $content .= '</div>';
            $pageTitle = $announceMarkdown !== '' ? $announceTitle : $shareTitleRaw;
            record_share_access($share, null, $pageTitle);
            render_page($pageTitle, $content, null, '', ['layout' => 'share', 'markdown' => true, 'announcement' => $announcementHtml]);
        }

        $rows = '';
        foreach ($docs as $doc) {
            $docTitleRaw = trim((string)($doc['title'] ?? '')) ?: (string)$doc['doc_id'];
            $docTitle = htmlspecialchars($docTitleRaw);
            $docPath = $base . '/s/' . $slug . '/' . rawurlencode((string)$doc['doc_id']);
            $hPath = trim((string)($doc['hpath'] ?? ''), '/');
            $pathLabel = $hPath !== '' ? $hPath : '/';
            $front = extract_front_matter($loadDocMarkdown($doc));
            $meta = (array)$front['meta'];
            $updatedRaw = $meta['lastmod'] ?? $meta['updated'] ?? $meta['modified'] ?? '';
            $updated = $updatedRaw ? format_meta_date((string)$updatedRaw) : '';
            $rows .= '<a class="kb-dir-row" href="' . $docPath . '" data-doc-id="' . htmlspecialchars((string)$doc['doc_id']) . '" data-share-nav="doc">';
            $rows .= '<div class="kb-dir-title">' . $docTitle . '</div>';
            $rows .= '<div class="kb-dir-path">' . htmlspecialchars($pathLabel) . '</div>';
            $rows .= '<div class="kb-dir-time">' . htmlspecialchars($updated) . '</div>';
            $rows .= '</a>';
        }
        if ($rows === '') {
            $rows = '<div class="share-empty">暂无文档。</div>';
        } else {
            $rows = '<div class="kb-directory"><div class="kb-dir-head"><div>标题</div><div>路径</div><div>更新</div></div>' . $rows . '</div>';
        }
        $reportTrigger = render_share_report_trigger($share);
        $reportModalHtml = render_share_report_form($share, $viewer, null);
        $content = '<div class="share-shell share-shell--notebook">';
        $content .= $sidebar;
        $content .= '<div class="kb-main">';
        $content .= '<div class="kb-header">';
        $content .= '<div class="kb-breadcrumbs"><span>目录</span></div>';
        $content .= '<div class="kb-title-row">';
        $content .= '<h1 class="kb-title">' . $shareTitle . '</h1>';
        $content .= '</div>';
        $content .= '<div class="kb-meta"><span class="kb-chip"><strong>文档</strong> ' . count($docs) . ' 篇</span></div>';
        $content .= render_share_stats($share, $reportTrigger);
        $content .= '</div>';
        $content .= $rows;
        $content .= $reportModalHtml;
        $content .= '</div></div>';
        render_page($shareTitleRaw, $content, null, '', ['layout' => 'share']);
    }

    render_share_not_found_page();
}
// CLI 模式：仅暴露函数（供后台 worker require 复用），不执行 web 路由
if (PHP_SAPI === 'cli') {
    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
    if ($path === '') {
        $path = '/';
    }
}

if ($path === '/api/instances/heartbeat') {
    handle_instance_heartbeat();
}

if ($path === '/api/instances/stats') {
    handle_instance_stats();
}

if (strpos($path, '/api/v1/') === 0) {
    handle_api($path);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/res/(.+)$#', $path, $matches)) {
    handle_share_asset_request($matches[1], $matches[2]);
}

if (str_starts_with($path, '/uploads/')) {
    handle_legacy_upload_asset_request($path);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_submit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/upload$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_upload($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/edit$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_edit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/comment/delete$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_comment_delete($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/report$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_share_report_submit($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/search$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_share_search($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/list$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_list($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/search$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_search($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/open$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_open($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/preview$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_preview_page($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/thumb$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_thumb($matches[1]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/content$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_content($matches[1]);
}

// 网盘下载（需命中分享下载规则）
if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/download$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_download($matches[1]);
}

// WOPI 只读宿主：CheckFileInfo
if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/wopi/([^/]+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_wopi($matches[1], $matches[2]);
}

// WOPI 只读宿主：GetFile
if (preg_match('#^/s/([a-zA-Z0-9_-]+)/netdisk/wopi/([^/]+)/contents$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_netdisk_wopi_getfile($matches[1], $matches[2]);
}

if (preg_match('#^/s/([a-zA-Z0-9_-]+)(?:/([^/]+))?$#', $path, $matches)) {
    route_share($matches[1], $matches[2] ?? null);
}

if ($path === '/captcha') {
    render_captcha_image();
}

if ($path === '/logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    session_destroy();
    redirect('/');
}

if ($path === '/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!allow_registration()) {
        flash('error', '当前未开放注册');
        redirect('/register');
    }
    if (!email_verification_available()) {
        redirect('/register');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['register_email'] ?? '')));
    $_SESSION['register_email'] = $email;
    if (($_SESSION['register_step'] ?? '') !== 'verify') {
        flash('error', '请先完成注册信息');
        redirect('/register');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确');
        redirect('/register?step=verify');
    }
    $lastSent = (int)($_SESSION['register_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', '请稍后再发送验证码');
        redirect('/register?step=verify');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', '验证码发送失败，请检查邮件配置');
        redirect('/register?step=verify');
    }
    $_SESSION['register_email_code_at'] = time();
    flash('info', '验证码已发送，请查收邮件');
    redirect('/register?step=verify');
}

if ($path === '/login/email/prepare' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $captchaInput = (string)($_POST['captcha'] ?? '');
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if (captcha_enabled() && !check_captcha($captchaInput)) {
        flash('error', '验证码错误');
        redirect('/login?tab=email');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确');
        redirect('/login?tab=email');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT disabled FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        flash('error', '该邮箱未注册');
        redirect('/login?tab=email');
    }
    if ((int)$userRow['disabled'] === 1) {
        flash('error', '账号已被停用');
        redirect('/login?tab=email');
    }
    $_SESSION['login_email_step'] = 'verify';
    redirect('/login?tab=email&step=verify');
}

if ($path === '/login/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    if (($_SESSION['login_email_step'] ?? '') !== 'verify') {
        flash('error', '请先输入邮箱');
        redirect('/login?tab=email');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['login_email'] ?? '')));
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确');
        redirect('/login?tab=email&step=verify');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if (!$stmt->fetchColumn()) {
        flash('error', '该邮箱未注册');
        redirect('/login?tab=email&step=verify');
    }
    $lastSent = (int)($_SESSION['login_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', '请稍后再发送验证码');
        redirect('/login?tab=email&step=verify');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', '验证码发送失败，请检查邮件配置');
        redirect('/login?tab=email&step=verify');
    }
    $_SESSION['login_email_code_at'] = time();
    flash('info', '验证码已发送，请查收邮件');
    redirect('/login?tab=email&step=verify');
}

if ($path === '/login/email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!email_verification_available()) {
        redirect('/login');
    }
    check_csrf();
    if (($_SESSION['login_email_step'] ?? '') !== 'verify') {
        flash('error', '请先输入邮箱');
        redirect('/login?tab=email');
    }
    $email = trim((string)($_POST['email'] ?? ($_SESSION['login_email'] ?? '')));
    $code = trim((string)($_POST['email_code'] ?? ''));
    $_SESSION['login_email'] = $email;
    $_SESSION['login_tab'] = 'email';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确');
        redirect('/login?tab=email&step=verify');
    }
    if ($code === '' || !verify_email_code($email, $code)) {
        flash('error', '邮箱验证码错误');
        redirect('/login?tab=email&step=verify');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$user) {
        flash('error', '该邮箱未注册');
        redirect('/login?tab=email&step=verify');
    }
    if ((int)$user['disabled'] === 1) {
        flash('error', '账号已被停用');
        redirect('/login?tab=email&step=verify');
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['password_hash'] = $user['password_hash'];
    $_SESSION['login_email_step'] = 'start';
    unset($_SESSION['login_email']);
    if ((int)$user['email_verified'] !== 1) {
        $update = $pdo->prepare('UPDATE users SET email_verified = 1, updated_at = :updated_at WHERE id = :id');
        $update->execute([':updated_at' => now(), ':id' => $user['id']]);
    }
    if ((int)$user['must_change_password'] === 1) {
        flash('info', '检测到默认密码，请先修改密码');
        redirect('/account');
    }
    redirect('/dashboard');
}

if ($path === '/login') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $captchaInput = (string)($_POST['captcha'] ?? '');
        $_SESSION['login_username'] = $username;
        $_SESSION['login_tab'] = 'password';
        if (captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', '验证码错误');
            redirect('/login');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            flash('error', '用户名或密码错误');
            redirect('/login');
        }
        if ((int)$user['disabled'] === 1) {
            flash('error', '账号已被停用');
            redirect('/login');
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['password_hash'] = $user['password_hash'];
        $_SESSION['login_email_step'] = 'start';
        unset($_SESSION['login_username']);
        if ((int)$user['must_change_password'] === 1) {
            flash('info', '检测到默认密码，请先修改密码');
            redirect('/account');
        }
        redirect('/dashboard');
    }
    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>';
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $tabQuery = (string)($_GET['tab'] ?? '');
    if ($tabQuery !== '') {
        $_SESSION['login_tab'] = $tabQuery;
    }
    $stepQuery = (string)($_GET['step'] ?? '');
    if ($stepQuery === 'verify') {
        $_SESSION['login_email_step'] = 'verify';
    } elseif ($stepQuery === 'prepare') {
        $_SESSION['login_email_step'] = 'start';
    }
    $prefillLoginEmail = $_SESSION['login_email'] ?? '';
    $prefillLoginUser = $_SESSION['login_username'] ?? '';
    $loginEmailStep = $_SESSION['login_email_step'] ?? 'start';
    if (!email_verification_available()) {
        $loginEmailStep = 'start';
    }
    if ($loginEmailStep === 'verify' && $prefillLoginEmail === '') {
        $loginEmailStep = 'start';
    }
    // Keep session step aligned with the derived view step to avoid stale bypass paths.
    $_SESSION['login_email_step'] = $loginEmailStep;
    $loginTab = email_verification_available()
        ? (($_SESSION['login_tab'] ?? '') ?: ($loginEmailStep === 'verify' ? 'email' : 'password'))
        : 'password';
    if (!in_array($loginTab, ['password', 'email'], true)) {
        $loginTab = 'password';
    }
    $lastLoginSent = (int)($_SESSION['login_email_code_at'] ?? 0);
    $nextLoginCodeAt = ($lastLoginSent && (time() - $lastLoginSent) < 60) ? ($lastLoginSent + 60) : 0;
    $nextLoginAttr = $nextLoginCodeAt ? ' data-countdown-until="' . ($nextLoginCodeAt * 1000) . '"' : '';

    $content = '<div class="auth-card auth-card--mascot">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">账号登录</div>';
    $content .= '<div class="auth-subtitle">欢迎回来，请登录继续</div>';
    $content .= render_auth_mascot();
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    if (email_verification_available()) {
        $content .= '<div class="auth-tabs" data-login-tabs data-login-default="' . $loginTab . '">';
        $content .= '<button class="auth-tab" type="button" data-login-tab="password">密码登录</button>';
        $content .= '<button class="auth-tab" type="button" data-login-tab="email">邮箱登录</button>';
        $content .= '</div>';
    }
    $content .= '<form method="post" class="auth-form" data-login-panel="password"' . ($loginTab === 'password' ? '' : ' hidden') . '>';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="用户名" value="' . htmlspecialchars((string)$prefillLoginUser) . '" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="密码" required></div>';
    if (captcha_enabled()) {
        $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="验证码" required>';
        $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha></div>';
    }
    $content .= '<div class="auth-actions"><a class="link" href="' . base_path() . '/forgot">找回密码</a></div>';
    $content .= '<button class="button primary w-full" type="submit">登录</button>';
    $content .= '</form>';
    if (email_verification_available()) {
        if ($loginEmailStep === 'verify') {
            $content .= '<form method="post" class="auth-form" action="' . base_path() . '/login/email" data-login-panel="email"' . ($loginTab === 'email' ? '' : ' hidden') . '>';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" value="' . htmlspecialchars((string)$prefillLoginEmail) . '" readonly></div>';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email_code" placeholder="邮箱验证码" required></div>';
            $content .= '<div class="auth-actions">';
            $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/login/email-code" formnovalidate' . $nextLoginAttr . '>发送邮箱验证码</button>';
            $content .= '<a class="link" href="' . base_path() . '/login?tab=email&step=prepare">修改邮箱</a>';
            $content .= '</div>';
            $content .= '<button class="button primary w-full" type="submit">登录</button>';
            $content .= '</form>';
        } else {
            $content .= '<form method="post" class="auth-form" action="' . base_path() . '/login/email/prepare" data-login-panel="email"' . ($loginTab === 'email' ? '' : ' hidden') . '>';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" value="' . htmlspecialchars((string)$prefillLoginEmail) . '" required></div>';
            if (captcha_enabled()) {
                $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="验证码" required>';
                $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha></div>';
            }
            $content .= '<button class="button primary w-full" type="submit">下一步</button>';
            $content .= '</form>';
        }
    }
    $content .= '<div class="auth-footer">没有账号？ <a class="link" href="' . base_path() . '/register">立即注册</a></div>';
    $content .= '</div>';
    render_page('登录', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/register') {
    global $config;
    if (!allow_registration()) {
        render_page('注册', '<div class="auth-card"><div class="auth-title">暂未开放注册</div></div>', null, '', ['layout' => 'auth']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $step = trim((string)($_POST['step'] ?? 'info'));
        $username = trim((string)($_POST['username'] ?? ($_SESSION['register_username'] ?? '')));
        $email = trim((string)($_POST['email'] ?? ($_SESSION['register_email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $captchaInput = (string)($_POST['captcha'] ?? '');
        $_SESSION['register_username'] = $username;
        $_SESSION['register_email'] = $email;
        if ($step === 'info' && captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', '验证码错误');
            redirect('/register');
        }
        if ($step === 'info') {
            if ($username === '' || $password === '') {
                flash('error', '用户名和密码不能为空');
                redirect('/register');
            }
            if (strlen($password) < 6) {
                flash('error', '密码至少 6 位');
                redirect('/register');
            }
            if (email_verification_available()) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    flash('error', '邮箱格式不正确');
                    redirect('/register');
                }
                $_SESSION['register_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $_SESSION['register_step'] = 'verify';
                redirect('/register?step=verify');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', '邮箱格式不正确');
                redirect('/register');
            }
            $emailVerified = $email !== '' ? 1 : 0;
            $pdo = db();
            if ($email !== '') {
                $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $checkEmail->execute([':email' => $email]);
                if (pdo_fetch_exists($checkEmail)) {
                    flash('error', '该邮箱已注册');
                    redirect('/register');
                }
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
                VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
            try {
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':password_hash' => $passwordHash,
                    ':role' => 'user',
                    ':api_key_hash' => null,
                    ':api_key_prefix' => null,
                    ':api_key_last4' => null,
                    ':disabled' => 0,
                    ':storage_limit_bytes' => 0,
                    ':storage_used_bytes' => 0,
                    ':must_change_password' => 0,
                    ':email_verified' => $emailVerified,
                    ':created_at' => now(),
                    ':updated_at' => now(),
                ]);
            } catch (PDOException $e) {
                flash('error', '用户名已存在');
                redirect('/register');
            }
            unset($_SESSION['register_step'], $_SESSION['register_password_hash'], $_SESSION['register_email_code_at'], $_SESSION['register_username'], $_SESSION['register_email']);
            $_SESSION['user_id'] = (int)$pdo->lastInsertId();
            $_SESSION['password_hash'] = $passwordHash;
            redirect('/dashboard');
        }

        if (!email_verification_available()) {
            redirect('/register');
        }
        $passwordHash = (string)($_SESSION['register_password_hash'] ?? '');
        if ($passwordHash === '') {
            $_SESSION['register_step'] = 'info';
            flash('error', '请先填写注册信息');
            redirect('/register');
        }
        $emailCode = trim((string)($_POST['email_code'] ?? ''));
        if ($username === '') {
            flash('error', '用户名不能为空');
            redirect('/register?step=verify');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', '邮箱格式不正确');
            redirect('/register?step=verify');
        }
        if ($emailCode === '' || !verify_email_code($email, $emailCode)) {
            flash('error', '邮箱验证码错误');
            redirect('/register?step=verify');
        }
        $pdo = db();
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if (pdo_fetch_exists($checkEmail)) {
            flash('error', '该邮箱已注册');
            redirect('/register?step=verify');
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
            VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
        try {
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => $passwordHash,
                ':role' => 'user',
                ':api_key_hash' => null,
                ':api_key_prefix' => null,
                ':api_key_last4' => null,
                ':disabled' => 0,
                ':storage_limit_bytes' => 0,
                ':storage_used_bytes' => 0,
                ':must_change_password' => 0,
                ':email_verified' => 1,
                ':created_at' => now(),
                ':updated_at' => now(),
            ]);
        } catch (PDOException $e) {
            flash('error', '用户名已存在');
            redirect('/register?step=verify');
        }
        unset(
            $_SESSION['register_step'],
            $_SESSION['register_password_hash'],
            $_SESSION['register_email_code_at'],
            $_SESSION['register_username'],
            $_SESSION['register_email']
        );
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['password_hash'] = $passwordHash;
        redirect('/dashboard');
    }
    $error = flash('error');
    $info = flash('info');
    $stepQuery = (string)($_GET['step'] ?? '');
    if ($stepQuery === 'verify') {
        $_SESSION['register_step'] = 'verify';
    } elseif ($stepQuery === 'info') {
        $_SESSION['register_step'] = 'info';
    }
    $registerStep = $_SESSION['register_step'] ?? 'info';
    if (!email_verification_available()) {
        $registerStep = 'info';
    }
    if ($registerStep === 'verify' && empty($_SESSION['register_password_hash'])) {
        $registerStep = 'info';
    }
    $_SESSION['register_step'] = $registerStep;
    $prefillName = $_SESSION['register_username'] ?? '';
    $prefillEmail = $_SESSION['register_email'] ?? '';
    $brand = htmlspecialchars($config['app_name']);
    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>';
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $lastSent = (int)($_SESSION['register_email_code_at'] ?? 0);
    $nextCodeAt = ($lastSent && (time() - $lastSent) < 60) ? ($lastSent + 60) : 0;
    $nextCodeAttr = $nextCodeAt ? ' data-countdown-until="' . ($nextCodeAt * 1000) . '"' : '';
    $content = '<div class="auth-card auth-card--mascot">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">注册账号</div>';
    $content .= '<div class="auth-subtitle">填写信息创建账号</div>';
    $content .= render_auth_mascot();
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    if (email_verification_available()) {
        $content .= '<div class="auth-steps">';
        $content .= '<div class="auth-step' . ($registerStep === 'info' ? ' is-active' : '') . '"><span>1</span>填写信息</div>';
        $content .= '<div class="auth-step' . ($registerStep === 'verify' ? ' is-active' : '') . '"><span>2</span>邮箱验证</div>';
        $content .= '</div>';
    }
    if ($registerStep === 'verify' && email_verification_available()) {
        $content .= '<form method="post" class="auth-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<input type="hidden" name="step" value="verify">';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="用户名" value="' . htmlspecialchars((string)$prefillName) . '" readonly></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" value="' . htmlspecialchars((string)$prefillEmail) . '" readonly></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email_code" placeholder="邮箱验证码" required></div>';
        $content .= '<div class="auth-actions">';
        $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/email-code" formnovalidate' . $nextCodeAttr . '>发送邮箱验证码</button>';
        $content .= '<a class="link" href="' . base_path() . '/register?step=info">修改信息</a>';
        $content .= '</div>';
        $content .= '<button class="button primary w-full" type="submit">注册</button>';
        $content .= '<div class="auth-footer">已有账号？ <a class="link" href="' . base_path() . '/login">立即登录</a></div>';
        $content .= '</form>';
    } else {
        $content .= '<form method="post" class="auth-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<input type="hidden" name="step" value="info">';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconUser . '</span><input class="auth-input" name="username" placeholder="用户名" value="' . htmlspecialchars((string)$prefillName) . '" required></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" value="' . htmlspecialchars((string)$prefillEmail) . '"' . (email_verification_available() ? ' required' : '') . '></div>';
        $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="密码" required></div>';
        if (captcha_enabled()) {
            $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="验证码" required>';
            $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha></div>';
        }
        $content .= '<button class="button primary w-full" type="submit">' . (email_verification_available() ? '下一步' : '注册') . '</button>';
        $content .= '<div class="auth-footer">已有账号？ <a class="link" href="' . base_path() . '/login">立即登录</a></div>';
        $content .= '</form>';
    }
    $content .= '</div>';
    render_page('注册', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/forgot') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $captchaInput = (string)($_POST['captcha'] ?? '');
        if (captcha_enabled() && !check_captcha($captchaInput)) {
            flash('error', '验证码错误');
            redirect('/forgot');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', '邮箱格式不正确');
            redirect('/forgot');
        }
        $lastSent = (int)($_SESSION['reset_code_at'] ?? 0);
        if ($lastSent && (time() - $lastSent) < 60) {
            flash('error', '发送过于频繁，请稍后再试');
            redirect('/forgot');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && (int)$user['disabled'] !== 1) {
            $code = create_reset_code((int)$user['id'], $email, $_SERVER['REMOTE_ADDR'] ?? '');
            $sent = send_reset_code($email, $code);
            if (!$sent) {
                flash('error', '验证码发送失败，请检查邮件配置');
                redirect('/forgot');
            }
            $_SESSION['reset_code_at'] = time();
        }
        flash('info', '如果邮箱存在，重置码已发送');
        redirect('/reset');
    }

    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconShield = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.6 9.2 8 11 4.4-1.8 8-6 8-11V5z"/></svg>';
    $content = '<div class="auth-card auth-card--mascot">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">找回密码</div>';
    $content .= '<div class="auth-subtitle">输入邮箱获取重置码</div>';
    $content .= render_auth_mascot();
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    $content .= '<form method="post" class="auth-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" required></div>';
    if (captcha_enabled()) {
        $content .= '<div class="auth-field auth-field-captcha"><span class="auth-icon">' . $iconShield . '</span><input class="auth-input" name="captcha" placeholder="验证码" required>';
        $content .= '<img class="captcha-img" src="' . htmlspecialchars(captcha_url()) . '" alt="验证码" data-captcha></div>';
    }
    $content .= '<button class="button primary w-full" type="submit">发送重置码</button>';
    $content .= '<div class="auth-footer"><a class="link" href="' . base_path() . '/login">返回登录</a></div>';
    $content .= '</form></div>';
    render_page('找回密码', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/reset') {
    global $config;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $email = trim((string)($_POST['email'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($email === '' || $code === '' || $password === '' || $confirm === '') {
            flash('error', '请填写完整信息');
            redirect('/reset');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', '邮箱格式不正确');
            redirect('/reset');
        }
        if (strlen($password) < 6) {
            flash('error', '密码至少 6 位');
            redirect('/reset');
        }
        if ($password !== $confirm) {
            flash('error', '两次密码不一致');
            redirect('/reset');
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$user) {
            flash('error', '账号不存在');
            redirect('/reset');
        }
        if (!verify_reset_code((int)$user['id'], $email, $code)) {
            flash('error', '验证码无效或已过期');
            redirect('/reset');
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $user['id'],
        ]);
        flash('info', '密码已重置，请登录');
        redirect('/login');
    }
    $error = flash('error');
    $info = flash('info');
    $brand = htmlspecialchars($config['app_name']);
    $iconMail = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
    $iconLock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-6 8v-2a1 1 0 0 1 2 0v2zm3-8H10V7a2 2 0 0 1 4 0z"/></svg>';
    $content = '<div class="auth-card auth-card--mascot">';
    $content .= '<div class="auth-logo">' . $brand . '</div>';
    $content .= '<div class="auth-title">重置密码</div>';
    $content .= '<div class="auth-subtitle">输入邮箱与验证码设置新密码</div>';
    $content .= render_auth_mascot();
    if ($error) {
        $content .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="alert info">' . htmlspecialchars($info) . '</div>';
    }
    $content .= '<form method="post" class="auth-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="email" placeholder="邮箱" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconMail . '</span><input class="auth-input" name="code" placeholder="邮箱验证码" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="password" placeholder="新密码" required></div>';
    $content .= '<div class="auth-field"><span class="auth-icon">' . $iconLock . '</span><input class="auth-input" type="password" name="confirm_password" placeholder="确认密码" required></div>';
    $content .= '<button class="button primary w-full" type="submit">重置密码</button>';
    $content .= '<div class="auth-footer"><a class="link" href="' . base_path() . '/login">返回登录</a></div>';
    $content .= '</form></div>';
    render_page('重置密码', $content, null, '', ['layout' => 'auth']);
}

if ($path === '/account/email-code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $email = trim((string)($_POST['new_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '请输入有效的新邮箱');
        redirect('/account');
    }
    if (strcasecmp($email, (string)($user['email'] ?? '')) === 0) {
        flash('error', '新邮箱不能与当前邮箱相同');
        redirect('/account');
    }
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
    $check->execute([
        ':email' => $email,
        ':id' => (int)$user['id'],
    ]);
    if ($check->fetchColumn()) {
        flash('error', '该邮箱已被其他账号使用');
        redirect('/account');
    }
    $lastSent = (int)($_SESSION['account_email_code_at'] ?? 0);
    if ($lastSent && (time() - $lastSent) < 60) {
        flash('error', '请稍后再发送验证码');
        redirect('/account');
    }
    $code = create_email_code($email, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!send_email_code($email, $code)) {
        flash('error', '验证码发送失败，请检查邮件配置');
        redirect('/account');
    }
    $_SESSION['account_email_code_at'] = time();
    $_SESSION['account_email_target'] = $email;
    flash('info', '验证码已发送，请查收邮件');
    redirect('/account');
}

if ($path === '/account/email-change' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $email = trim((string)($_POST['new_email'] ?? ''));
    $code = trim((string)($_POST['email_code'] ?? ''));
    if ($email === '' || $code === '') {
        flash('error', '请填写完整信息');
        redirect('/account');
    }
    $target = (string)($_SESSION['account_email_target'] ?? '');
    if ($target === '' || strcasecmp($target, $email) !== 0) {
        flash('error', '请先获取该邮箱验证码');
        redirect('/account');
    }
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
    $check->execute([
        ':email' => $email,
        ':id' => (int)$user['id'],
    ]);
    if ($check->fetchColumn()) {
        flash('error', '该邮箱已被其他账号使用');
        redirect('/account');
    }
    if (!verify_email_code($email, $code)) {
        flash('error', '邮箱验证码错误');
        redirect('/account');
    }
    $stmt = $pdo->prepare('UPDATE users SET email = :email, email_verified = 1, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':email' => $email,
        ':updated_at' => now(),
        ':id' => $user['id'],
    ]);
    unset($_SESSION['account_email_target'], $_SESSION['account_email_code_at']);
    flash('info', '邮箱已更新');
    redirect('/account');
}

if ($path === '/account') {
    $user = require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($new === '' || $confirm === '') {
            flash('error', '请填写完整信息');
            redirect('/account');
        }
        if (strlen($new) < 6) {
            flash('error', '新密码至少 6 位');
            redirect('/account');
        }
        if ($new !== $confirm) {
            flash('error', '两次输入的新密码不一致');
            redirect('/account');
        }
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', '当前密码错误');
            redirect('/account');
        }
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':hash' => password_hash($new, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $user['id'],
        ]);
        unset($_SESSION['user_id'], $_SESSION['password_hash']);
        session_regenerate_id(true);
        flash('info', '密码修改成功，请重新登录');
        redirect('/login');
    }

    $error = flash('error');
    $info = flash('info');
    $content = '<div class="card"><h2>账号设置</h2>';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }
    if ((int)$user['must_change_password'] === 1) {
        $content .= '<div class="notice">当前为默认密码，请尽快修改。</div>';
    }
    $content .= '<form method="post" style="margin-top:12px">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>当前密码</label><input class="input" type="password" name="current_password" required></div>';
    $content .= '<div><label>新密码</label><input class="input" type="password" name="new_password" required></div>';
    $content .= '<div><label>确认新密码</label><input class="input" type="password" name="confirm_password" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">更新密码</button></div>';
    $content .= '</form></div>';

    $currentEmail = trim((string)($user['email'] ?? ''));
    $currentEmailLabel = $currentEmail !== '' ? htmlspecialchars($currentEmail) : '未绑定';
    $pendingEmail = (string)($_SESSION['account_email_target'] ?? '');
    $content .= '<div class="card"><h2>换绑邮箱</h2>';
    $content .= '<p class="muted">当前邮箱：' . $currentEmailLabel . '</p>';
    $content .= '<form method="post" action="' . base_path() . '/account/email-change" style="margin-top:12px">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>新邮箱</label><input class="input" type="email" name="new_email" value="' . htmlspecialchars($pendingEmail) . '" required></div>';
    $content .= '<div><label>邮箱验证码</label><input class="input" name="email_code" placeholder="请输入验证码" required></div>';
    $content .= '</div>';
    $content .= '<div class="form-actions" style="margin-top:12px">';
    $content .= '<button class="button ghost" type="submit" formaction="' . base_path() . '/account/email-code" formnovalidate>发送邮箱验证码</button>';
    $content .= '<button class="button primary" type="submit">更换邮箱</button>';
    $content .= '</div>';
    $content .= '</form></div>';
    $titleHtml = build_topbar_title('账号设置', $user);
    render_page('账号设置', $content, $user, '', ['title_html' => $titleHtml]);
}

if ($path === '/dashboard') {
    $user = require_login();
    $pdo = db();
    $usedBytes = recalculate_user_storage((int)$user['id']);
    $user['storage_used_bytes'] = $usedBytes;
    $limitBytes = get_user_limit_bytes($user);
    $limitLabel = $limitBytes > 0 ? format_bytes($limitBytes) : '不限';
    $limitSource = ((int)$user['storage_limit_bytes'] > 0) ? '自定义' : '默认';
    $storageFull = $limitBytes > 0 && $usedBytes >= $limitBytes;
    $shareSearch = trim((string)($_GET['share_search'] ?? ''));
    $sharePage = max(1, (int)($_GET['share_page'] ?? 1));
    $shareSize = normalize_page_size($_GET['share_size'] ?? 10);
    $filterStatus = (string)($_GET['status'] ?? 'active');
    if (!in_array($filterStatus, ['active', 'expired', 'deleted', 'all'], true)) {
        $filterStatus = 'active';
    }
    $where = ['user_id = :uid'];
    $params = [':uid' => $user['id']];
    if ($shareSearch !== '') {
        $where[] = '(title LIKE :share_search OR slug LIKE :share_search)';
        $params[':share_search'] = '%' . $shareSearch . '%';
    }
    if ($filterStatus === 'active') {
        $where[] = 'deleted_at IS NULL';
        $where[] = '(expires_at IS NULL OR expires_at <= 0 OR expires_at >= :now_ts)';
        $params[':now_ts'] = time();
    } elseif ($filterStatus === 'expired') {
        $where[] = 'deleted_at IS NULL';
        $where[] = 'expires_at > 0';
        $where[] = 'expires_at < :now_ts';
        $params[':now_ts'] = time();
    } elseif ($filterStatus === 'deleted') {
        $where[] = 'deleted_at IS NOT NULL';
    }
    $shareSql = 'SELECT * FROM shares';
    $shareCountSql = 'SELECT COUNT(*) FROM shares';
    if (!empty($where)) {
        $shareSql .= ' WHERE ' . implode(' AND ', $where);
        $shareCountSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $shareCountStmt = $pdo->prepare($shareCountSql);
    $shareCountStmt->execute($params);
    $totalShares = (int)$shareCountStmt->fetchColumn();
    [$sharePage, $shareSize, $sharePages, $shareOffset] = paginate($totalShares, $sharePage, $shareSize);
    $shareSql .= ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($shareSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $shareSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $shareOffset, PDO::PARAM_INT);
    $stmt->execute();
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $shareQuery = $_GET;
    unset($shareQuery['share_page'], $shareQuery['share_size'], $shareQuery['share_search'], $shareQuery['status']);
    $accessShare = trim((string)($_GET['access_share'] ?? 'all'));
    $accessPage = max(1, (int)($_GET['access_page'] ?? 1));
    $accessSize = normalize_page_size($_GET['access_size'] ?? 10);
    $accessSourcePage = max(1, (int)($_GET['access_source_page'] ?? 1));
    $accessSourceSize = normalize_page_size($_GET['access_source_size'] ?? 10);
    $accessShareId = 0;
    if ($accessShare !== '' && $accessShare !== 'all') {
        $accessShareId = (int)$accessShare;
    }
    if ($accessShareId > 0) {
        $checkShare = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid LIMIT 1');
        $checkShare->execute([':id' => $accessShareId, ':uid' => $user['id']]);
        if (!$checkShare->fetchColumn()) {
            $accessShareId = 0;
        }
    }
    $accessEnabled = access_stats_enabled((int)$user['id']);
    $accessRetention = access_stats_retention_days((int)$user['id']);
    $accessShareOptionsStmt = $pdo->prepare('SELECT id, title, slug FROM shares WHERE user_id = :uid AND deleted_at IS NULL ORDER BY updated_at DESC');
    $accessShareOptionsStmt->execute([':uid' => $user['id']]);
    $accessShareOptions = $accessShareOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessWhere = ['share_access_logs.user_id = :uid'];
    $accessParams = [':uid' => $user['id']];
    if ($accessShareId > 0) {
        $accessWhere[] = 'share_access_logs.share_id = :sid';
        $accessParams[':sid'] = $accessShareId;
    }
    $accessWhereSql = implode(' AND ', $accessWhere);
    $todayStart = date('Y-m-d H:i:s', strtotime('today'));
    $tomorrowStart = date('Y-m-d H:i:s', strtotime('tomorrow'));
    $yesterdayStart = date('Y-m-d H:i:s', strtotime('yesterday'));
    $summarySql = 'SELECT
        SUM(CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN 1 ELSE 0 END) AS pv_today,
        SUM(CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN 1 ELSE 0 END) AS pv_yesterday,
        COUNT(*) AS pv_total,
        COUNT(DISTINCT CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN visitor_id END) AS uv_today,
        COUNT(DISTINCT CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN visitor_id END) AS uv_yesterday,
        COUNT(DISTINCT visitor_id || ":" || substr(created_at, 1, 10)) AS uv_total,
        COUNT(DISTINCT CASE WHEN created_at >= :today_start AND created_at < :tomorrow_start THEN ip END) AS ip_today,
        COUNT(DISTINCT CASE WHEN created_at >= :yesterday_start AND created_at < :today_start THEN ip END) AS ip_yesterday,
        COUNT(DISTINCT ip) AS ip_total
        FROM share_access_logs WHERE ' . $accessWhereSql;
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryParams = array_merge($accessParams, [
        ':today_start' => $todayStart,
        ':tomorrow_start' => $tomorrowStart,
        ':yesterday_start' => $yesterdayStart,
    ]);
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $accessSummary = [
        'pv_today' => (int)($summaryRow['pv_today'] ?? 0),
        'pv_yesterday' => (int)($summaryRow['pv_yesterday'] ?? 0),
        'pv_total' => (int)($summaryRow['pv_total'] ?? 0),
        'uv_today' => (int)($summaryRow['uv_today'] ?? 0),
        'uv_yesterday' => (int)($summaryRow['uv_yesterday'] ?? 0),
        'uv_total' => (int)($summaryRow['uv_total'] ?? 0),
        'ip_today' => (int)($summaryRow['ip_today'] ?? 0),
        'ip_yesterday' => (int)($summaryRow['ip_yesterday'] ?? 0),
        'ip_total' => (int)($summaryRow['ip_total'] ?? 0),
    ];
    $sourceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT referer FROM share_access_logs WHERE ' . $accessWhereSql . ' AND referer != "" GROUP BY referer) AS t');
    $sourceCountStmt->execute($accessParams);
    $accessSourceTotal = (int)$sourceCountStmt->fetchColumn();
    [$accessSourcePage, $accessSourceSize, $accessSourcePages, $accessSourceOffset] = paginate(
        $accessSourceTotal,
        $accessSourcePage,
        $accessSourceSize
    );
    $sourceSql = 'SELECT referer, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND referer != "" GROUP BY referer ORDER BY total DESC LIMIT :limit OFFSET :offset';
    $sourceStmt = $pdo->prepare($sourceSql);
    foreach ($accessParams as $key => $value) {
        $sourceStmt->bindValue($key, $value);
    }
    $sourceStmt->bindValue(':limit', $accessSourceSize, PDO::PARAM_INT);
    $sourceStmt->bindValue(':offset', $accessSourceOffset, PDO::PARAM_INT);
    $sourceStmt->execute();
    $accessSources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
    $regionCnStmt = $pdo->prepare('SELECT ip_region AS label, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND ip_country_code = "CN" AND ip_region != "" GROUP BY ip_region ORDER BY total DESC LIMIT 12');
    $regionCnStmt->execute($accessParams);
    $accessRegionsCn = $regionCnStmt->fetchAll(PDO::FETCH_ASSOC);
    $regionIntlStmt = $pdo->prepare('SELECT ip_country AS label, COUNT(*) AS total FROM share_access_logs WHERE ' . $accessWhereSql . ' AND (ip_country_code IS NULL OR ip_country_code != "CN") AND ip_country != "" GROUP BY ip_country ORDER BY total DESC LIMIT 12');
    $regionIntlStmt->execute($accessParams);
    $accessRegionsIntl = $regionIntlStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessCnMax = 0;
    foreach ($accessRegionsCn as $row) {
        $accessCnMax = max($accessCnMax, (int)($row['total'] ?? 0));
    }
    $accessIntlMax = 0;
    foreach ($accessRegionsIntl as $row) {
        $accessIntlMax = max($accessIntlMax, (int)($row['total'] ?? 0));
    }
    $accessCountStmt = $pdo->prepare('SELECT COUNT(*) FROM share_access_logs WHERE ' . $accessWhereSql);
    $accessCountStmt->execute($accessParams);
    $accessTotal = (int)$accessCountStmt->fetchColumn();
    $accessSizeStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = :uid');
    $accessSizeStmt->execute([':uid' => $user['id']]);
    $accessLogTotalBytes = (int)$accessSizeStmt->fetchColumn();
    $accessLogTotalLabel = format_bytes($accessLogTotalBytes);
    [$accessPage, $accessSize, $accessPages, $accessOffset] = paginate($accessTotal, $accessPage, $accessSize);
    $accessSql = 'SELECT share_access_logs.*, shares.slug, shares.title AS share_title
        FROM share_access_logs
        JOIN shares ON share_access_logs.share_id = shares.id
        WHERE ' . $accessWhereSql . '
        ORDER BY share_access_logs.created_at DESC
        LIMIT :limit OFFSET :offset';
    $accessStmt = $pdo->prepare($accessSql);
    foreach ($accessParams as $key => $value) {
        $accessStmt->bindValue($key, $value);
    }
    $accessStmt->bindValue(':limit', $accessSize, PDO::PARAM_INT);
    $accessStmt->bindValue(':offset', $accessOffset, PDO::PARAM_INT);
    $accessStmt->execute();
    $accessLogs = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessQuery = $_GET;
    unset($accessQuery['access_page'], $accessQuery['access_size']);
    $accessFilterQuery = $accessQuery;
    unset($accessFilterQuery['access_share']);
    $accessSourceQuery = $_GET;
    unset($accessSourceQuery['access_source_page'], $accessSourceQuery['access_source_size']);
    $apiKey = flash('api_key');
    $info = flash('info');
    $error = flash('error');
    $content = '';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }
    if ((int)$user['must_change_password'] === 1) {
        $content .= '<div class="notice">检测到默认密码，请尽快在账号设置中修改。</div>';
    }
    $content .= '<div class="card"><h2>存储空间</h2>';
    $content .= '<p>已使用：' . format_bytes($usedBytes) . ' / ' . $limitLabel . '（' . $limitSource . '）</p>';
    $content .= '</div>';
    $content .= '<div class="card"><h2>API Key</h2>';
    if ($apiKey) {
        $content .= '<div class="notice">新的 API Key：<code>' . htmlspecialchars($apiKey) . '</code>（仅显示一次，请妥善保存）</div>';
    }
    if (!empty($user['api_key_last4'])) {
        $content .= '<p class="muted">当前 Key 末尾：' . htmlspecialchars($user['api_key_last4'] ?? '') . '</p>';
    } else {
        $content .= '<p class="muted">尚未生成 API Key。</p>';
    }
    $buttonLabel = !empty($user['api_key_last4']) ? '重新生成' : '生成 API Key';
    $content .= '<form method="post" action="' . base_path() . '/api-key/rotate">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button" type="submit">' . $buttonLabel . '</button>';
    $content .= '</form></div>';

    $content .= '<div class="card"><h2>账号设置</h2>';
    $content .= '<p class="muted">修改登录密码、查看账号状态。</p>';
    $content .= '<a class="button" href="' . base_path() . '/account">前往账号设置</a>';
    $content .= '</div>';

    $content .= '<div class="card" id="shares"><h2>分享列表</h2>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#shares" class="filter-form">';
    $content .= render_hidden_inputs($shareQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>关键词</label><input class="input" name="share_search" placeholder="标题 / Slug" value="' . htmlspecialchars($shareSearch) . '"></div>';
    $content .= '<div><label>状态筛选</label><select class="input" name="status">';
    $content .= '<option value="active"' . ($filterStatus === 'active' ? ' selected' : '') . '>正常</option>';
    $content .= '<option value="expired"' . ($filterStatus === 'expired' ? ' selected' : '') . '>已过期</option>';
    $content .= '<option value="deleted"' . ($filterStatus === 'deleted' ? ' selected' : '') . '>已删除</option>';
    $content .= '<option value="all"' . ($filterStatus === 'all' ? ' selected' : '') . '>全部</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">筛选</button></div>';
    $content .= '</form>';
    if (empty($shares)) {
        $content .= '<p class="muted" style="margin-top:12px">暂无分享记录。</p>';
    } else {
        $content .= '<div class="table-scroll" style="margin-top:12px"><table class="table"><thead><tr><th>标题</th><th>类型</th><th>链接</th><th>密码</th><th>到期</th><th>访客上限</th><th>状态</th><th>评论邮件通知</th><th>大小</th><th>更新时间</th></tr></thead><tbody>';
        foreach ($shares as $share) {
            $title = htmlspecialchars($share['title']);
            $type = $share['type'] === 'notebook' ? '笔记本' : '文档';
            $url = share_url($share['slug']);
            $updated = htmlspecialchars($share['updated_at']);
            $size = format_bytes((int)($share['size_bytes'] ?? 0));
            $hasPassword = !empty($share['password_hash']) ? '已设置' : '无';
            $expiresAt = !empty($share['expires_at']) ? date('Y-m-d H:i', (int)$share['expires_at']) : '永久';
            $visitorLimit = (int)($share['visitor_limit'] ?? 0);
            if ($visitorLimit > 0) {
                $visitorCount = share_visitor_count((int)$share['id']);
                $visitorLabel = $visitorCount . '/' . $visitorLimit;
            } else {
                $visitorLabel = '不限';
            }
            $status = !empty($share['deleted_at'])
                ? '已删除'
                : (share_is_expired($share) ? '已过期' : '正常');
            $notifyEnabled = (int)($share['comment_notify'] ?? 0) === 1;
            if (!empty($share['deleted_at'])) {
                $notifyHtml = '<span class="muted">已删除</span>';
            } else {
                $notifyHtml = '<div class="comment-notify">';
                $notifyHtml .= '<span class="comment-notify-status">' . ($notifyEnabled ? '已开启' : '已关闭') . '</span>';
                if ($notifyEnabled || smtp_enabled()) {
                    $notifyHtml .= '<form method="post" action="' . base_path() . '/dashboard/comment-notify" class="inline-form">';
                    $notifyHtml .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                    $notifyHtml .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                    $notifyHtml .= '<input type="hidden" name="action" value="' . ($notifyEnabled ? 'disable' : 'enable') . '" data-toggle-action>';
                    $notifyHtml .= '<label class="switch">';
                    $notifyHtml .= '<input type="checkbox" ' . ($notifyEnabled ? 'checked ' : '') . 'data-toggle-input>';
                    $notifyHtml .= '<span class="switch-slider"></span>';
                    $notifyHtml .= '</label>';
                    $notifyHtml .= '</form>';
                } else {
                    $notifyHtml .= '<span class="muted">需开启SMTP</span>';
                    $notifyHtml .= '<label class="switch is-disabled">';
                    $notifyHtml .= '<input type="checkbox" disabled>';
                    $notifyHtml .= '<span class="switch-slider"></span>';
                    $notifyHtml .= '</label>';
                }
                $notifyHtml .= '</div>';
            }
            $content .= "<tr><td>{$title}</td><td>{$type}</td><td><a href=\"{$url}\" target=\"_blank\">{$url}</a></td><td>{$hasPassword}</td><td>{$expiresAt}</td><td>{$visitorLabel}</td><td>{$status}</td><td>{$notifyHtml}</td><td>{$size}</td><td>{$updated}</td></tr>";
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_dashboard_query_url(['share_page' => max(1, $sharePage - 1)]) . '">上一页</a>';
    $content .= '<div class="pagination-info">第 ' . $sharePage . ' / ' . $sharePages . ' 页，共 ' . $totalShares . ' 条分享</div>';
    $content .= '<a class="button ghost" href="' . build_dashboard_query_url(['share_page' => min($sharePages, $sharePage + 1)]) . '">下一页</a>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#shares" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($shareQuery, [
        'share_search' => $shareSearch,
        'status' => $filterStatus,
    ]));
    $content .= '<label>每页</label><select class="input" name="share_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $shareSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>页码</label><input class="input small" type="number" name="share_page" min="1" max="' . $sharePages . '" value="' . $sharePage . '">';
    $content .= '<button class="button" type="submit">跳转</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="access-stats"><h2>访问概况</h2>';
    $content .= '<form method="post" action="' . base_path() . '/dashboard/access-stats/update" class="stats-settings">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>访问统计</label><label class="checkbox stats-toggle"><input type="checkbox" name="access_enabled" value="1"' . ($accessEnabled ? ' checked' : '') . '> 开启</label></div>';
    $content .= '<div><label>保留天数</label><input class="input" type="number" name="access_retention_days" min="1" max="365" value="' . (int)$accessRetention . '"></div>';
    $content .= '</div>';
    $content .= '<div class="muted stats-note">访客数(UV) 按浏览器 Cookie 去重（按天），访问记录计入账号存储空间，默认保留最近 7 天（当前 ' . (int)$accessRetention . ' 天），可在此调整；存储不足会自动关闭并清空统计。</div>';
    if ($storageFull) {
        $content .= '<div class="notice" style="margin-top:8px">存储空间已满，当前无法开启访问统计，请先清理空间。</div>';
    }
    if (!$accessEnabled) {
        $content .= '<div class="notice" style="margin-top:8px">访问统计已关闭，当前不会记录新的访问。</div>';
    }
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">保存设置</button></div>';
    $content .= '</form>';

    $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="filter-form">';
    $content .= render_hidden_inputs($accessFilterQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>笔记筛选</label><select class="input" name="access_share">';
    $content .= '<option value="all"' . ($accessShareId <= 0 ? ' selected' : '') . '>全部</option>';
    foreach ($accessShareOptions as $option) {
        $optionId = (int)($option['id'] ?? 0);
        $optionTitle = (string)($option['title'] ?? '');
        $optionSlug = (string)($option['slug'] ?? '');
        $label = $optionTitle !== '' ? $optionTitle : $optionSlug;
        if ($optionSlug !== '') {
            $label .= ' /s/' . $optionSlug;
        }
        $selected = $accessShareId === $optionId ? ' selected' : '';
        $content .= '<option value="' . $optionId . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">筛选</button></div>';
    $content .= '</form>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">访问概况</div>';
    $content .= '<div class="table-scroll"><table class="table stats-table"><thead><tr><th></th><th>浏览量(PV)</th><th>访客数(UV)</th><th>IP 数量</th></tr></thead><tbody>';
    $content .= '<tr><td>今日</td><td>' . $accessSummary['pv_today'] . '</td><td>' . $accessSummary['uv_today'] . '</td><td>' . $accessSummary['ip_today'] . '</td></tr>';
    $content .= '<tr><td>昨日</td><td>' . $accessSummary['pv_yesterday'] . '</td><td>' . $accessSummary['uv_yesterday'] . '</td><td>' . $accessSummary['ip_yesterday'] . '</td></tr>';
    $content .= '<tr><td>总计</td><td>' . $accessSummary['pv_total'] . '</td><td>' . $accessSummary['uv_total'] . '</td><td>' . $accessSummary['ip_total'] . '</td></tr>';
    $content .= '</tbody></table></div>';
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">来源页</div>';
    if (empty($accessSources)) {
        $content .= '<p class="muted">暂无来源数据。</p>';
    } else {
        $content .= '<div class="table-scroll"><table class="table stats-table"><thead><tr><th>排名</th><th>次数</th><th>来源地址</th></tr></thead><tbody>';
        $rank = 1;
        foreach ($accessSources as $row) {
            $referer = (string)($row['referer'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $content .= '<tr><td>' . $rank . '</td><td>' . $total . '</td><td class="stats-source">' . htmlspecialchars($referer) . '</td></tr>';
            $rank++;
        }
        $content .= '</tbody></table></div>';
        $content .= '<div class="pagination">';
        $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_source_page' => max(1, $accessSourcePage - 1)]) . '">上一页</a>';
        $content .= '<div class="pagination-info">第 ' . $accessSourcePage . ' / ' . $accessSourcePages . ' 页，共 ' . $accessSourceTotal . ' 条来源</div>';
        $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_source_page' => min($accessSourcePages, $accessSourcePage + 1)]) . '">下一页</a>';
        $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="pagination-form">';
        $content .= render_hidden_inputs(array_merge($accessSourceQuery, [
            'access_share' => $accessShareId > 0 ? $accessShareId : 'all',
        ]));
        $content .= '<label>每页</label><select class="input" name="access_source_size">';
        foreach ([10, 50, 200, 1000] as $size) {
            $selected = $accessSourceSize === $size ? ' selected' : '';
            $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }
        $content .= '</select>';
        $content .= '<label>页码</label><input class="input small" type="number" name="access_source_page" min="1" max="' . $accessSourcePages . '" value="' . $accessSourcePage . '">';
        $content .= '<button class="button" type="submit">跳转</button>';
        $content .= '</form>';
        $content .= '</div>';
    }
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">访客地域分析</div>';
    $content .= '<div class="stats-charts">';
    $content .= '<div class="stats-chart">';
    $content .= '<div class="stats-subtitle">国内</div>';
    if (empty($accessRegionsCn)) {
        $content .= '<p class="muted">暂无数据。</p>';
    } else {
        $content .= '<div class="stats-chart-list">';
        foreach ($accessRegionsCn as $row) {
            $label = (string)($row['label'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $percent = $accessCnMax > 0 ? round(($total / $accessCnMax) * 100, 1) : 0;
            $content .= '<div class="stats-chart-row"><div class="stats-label">' . htmlspecialchars($label) . '</div><div class="stats-bar-track"><div class="stats-bar" style="width:' . $percent . '%"></div></div><div class="stats-value">' . $total . '</div></div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>';
    $content .= '<div class="stats-chart">';
    $content .= '<div class="stats-subtitle">国际</div>';
    if (empty($accessRegionsIntl)) {
        $content .= '<p class="muted">暂无数据。</p>';
    } else {
        $content .= '<div class="stats-chart-list">';
        foreach ($accessRegionsIntl as $row) {
            $label = (string)($row['label'] ?? '');
            $total = (int)($row['total'] ?? 0);
            $percent = $accessIntlMax > 0 ? round(($total / $accessIntlMax) * 100, 1) : 0;
            $content .= '<div class="stats-chart-row"><div class="stats-label">' . htmlspecialchars($label) . '</div><div class="stats-bar-track"><div class="stats-bar" style="width:' . $percent . '%"></div></div><div class="stats-value">' . $total . '</div></div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="stats-block">';
    $content .= '<div class="stats-title">访问记录</div>';
    $content .= '<div class="table-actions stats-actions">';
    $content .= '<form id="access-batch-form" method="post" action="' . base_path() . '/dashboard/access-stats/delete" class="inline-form" data-batch-form="access">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="access"> 全选</label>';
    $content .= '<button class="button danger" type="submit">批量删除</button>';
    $content .= '</form>';
    $content .= '<form method="post" action="' . base_path() . '/dashboard/access-stats/delete-all" class="inline-form" data-confirm-message="确定删除全部访问记录吗？该操作不可恢复。">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button danger" type="submit">删除全部（占用 ' . $accessLogTotalLabel . '）</button>';
    $content .= '</form>';
    $content .= '</div>';
    if (empty($accessLogs)) {
        $content .= '<p class="muted">暂无访问记录。</p>';
    } else {
        $content .= '<div class="table-scroll"><table class="table stats-table"><thead><tr><th><input type="checkbox" data-check-all="access" form="access-batch-form"></th><th>标题</th><th>IP</th><th>IP归属地</th><th>访问日期</th></tr></thead><tbody>';
        foreach ($accessLogs as $log) {
            $logTitle = trim((string)($log['doc_title'] ?? ''));
            if ($logTitle === '') {
                $logTitle = (string)($log['share_title'] ?? '');
            }
            if ($logTitle === '') {
                $logTitle = '未命名';
            }
            $slug = (string)($log['slug'] ?? '');
            $docId = trim((string)($log['doc_id'] ?? ''));
            if ($slug !== '') {
                $shareLink = $docId !== '' ? base_url() . build_share_redirect_path($slug, $docId, '') : share_url($slug);
                $suffix = $docId !== '' ? '/s/' . $slug . '/' . $docId : '/s/' . $slug;
            } else {
                $shareLink = '#';
                $suffix = '';
            }
            $ip = trim((string)($log['ip'] ?? ''));
            if ($ip === '') {
                $ip = '-';
            }
            $location = format_ip_location([
                'country' => $log['ip_country'] ?? '',
                'country_code' => $log['ip_country_code'] ?? '',
                'region' => $log['ip_region'] ?? '',
                'city' => $log['ip_city'] ?? '',
            ]);
            if ($location === '') {
                $location = '-';
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="access_ids[]" value="' . (int)$log['id'] . '" data-check-item="access" form="access-batch-form"></td>';
            $content .= '<td><a href="' . htmlspecialchars($shareLink) . '" target="_blank">' . htmlspecialchars($logTitle) . '</a>';
            if ($suffix !== '') {
                $content .= '<div class="muted">' . htmlspecialchars($suffix) . '</div>';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars($ip) . '</td>';
            $content .= '<td>' . htmlspecialchars($location) . '</td>';
            $content .= '<td>' . htmlspecialchars((string)($log['created_at'] ?? '')) . '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_page' => max(1, $accessPage - 1)]) . '">上一页</a>';
    $content .= '<div class="pagination-info">第 ' . $accessPage . ' / ' . $accessPages . ' 页，共 ' . $accessTotal . ' 条访问</div>';
    $content .= '<a class="button ghost" href="' . build_access_stats_query_url(['access_page' => min($accessPages, $accessPage + 1)]) . '">下一页</a>';
    $content .= '<form method="get" action="' . base_path() . '/dashboard#access-stats" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($accessQuery, [
        'access_share' => $accessShareId > 0 ? $accessShareId : 'all',
    ]));
    $content .= '<label>每页</label><select class="input" name="access_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $accessSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>页码</label><input class="input small" type="number" name="access_page" min="1" max="' . $accessPages . '" value="' . $accessPage . '">';
    $content .= '<button class="button" type="submit">跳转</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '</div>';

    if ($user['role'] === 'admin') {
        $content .= '<div class="card"><h2>管理员入口</h2>';
        $content .= '<a class="button" href="' . base_path() . '/admin-home">进入后台</a>';
        $content .= '</div>';
    }

    $titleHtml = build_topbar_title('控制台', $user);
    render_page('控制台', $content, $user, '', ['layout' => 'app', 'nav' => 'dashboard', 'title_html' => $titleHtml]);
}

if ($path === '/admin-home') {
    $admin = require_admin();
    $pdo = db();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $disabledUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE disabled = 1')->fetchColumn();
    $totalSharesAll = (int)$pdo->query('SELECT COUNT(*) FROM shares')->fetchColumn();
    $totalSharesActive = (int)$pdo->query('SELECT COUNT(*) FROM shares WHERE deleted_at IS NULL')->fetchColumn();
    $deletedShares = max(0, $totalSharesAll - $totalSharesActive);
    $totalAccess = (int)$pdo->query('SELECT COUNT(*) FROM share_access_logs')->fetchColumn();
    $totalUv = (int)$pdo->query('SELECT COUNT(DISTINCT visitor_id) FROM share_access_logs WHERE visitor_id IS NOT NULL AND visitor_id != ""')->fetchColumn();
    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $todayStmt = $pdo->prepare('SELECT COUNT(*) AS pv, COUNT(DISTINCT CASE WHEN visitor_id IS NULL OR visitor_id = "" THEN NULL ELSE visitor_id END) AS uv
        FROM share_access_logs WHERE created_at >= :start AND created_at < :end');
    $todayStmt->execute([':start' => $todayStart, ':end' => $tomorrowStart]);
    $todayRow = $todayStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $todayPv = (int)($todayRow['pv'] ?? 0);
    $todayUv = (int)($todayRow['uv'] ?? 0);
    $activeStart30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $activeStart7 = date('Y-m-d H:i:s', strtotime('-7 days'));
    $activeStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE last_active_at >= :start');
    $activeStmt->execute([':start' => $activeStart30]);
    $activeUsers30 = (int)$activeStmt->fetchColumn();
    $activeStmt->execute([':start' => $activeStart7]);
    $activeUsers7 = (int)$activeStmt->fetchColumn();
    $shareBytes = (int)$pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM shares WHERE deleted_at IS NULL')->fetchColumn();
    $logBytes = (int)$pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM share_access_logs')->fetchColumn();
    $usedBytes = $shareBytes + $logBytes;
    $defaultLimitBytes = default_storage_limit_bytes();
    $limitStmt = $pdo->prepare('SELECT SUM(CASE WHEN storage_limit_bytes > 0 THEN storage_limit_bytes ELSE :default_limit END) AS total FROM users');
    $limitStmt->execute([':default_limit' => $defaultLimitBytes]);
    $totalLimitBytes = (int)($limitStmt->fetchColumn() ?: 0);
    if ($totalUsers === 0) {
        $totalLimitBytes = 0;
    }
    $remainingBytes = $totalLimitBytes > 0 ? max(0, $totalLimitBytes - $usedBytes) : 0;
    $storagePercent = $totalLimitBytes > 0 ? min(100, round($usedBytes / max(1, $totalLimitBytes) * 100, 1)) : 0;
    $commentTotal = (int)$pdo->query('SELECT COUNT(*) FROM share_comments')->fetchColumn();
    $commentStmt = $pdo->prepare('SELECT COUNT(*) FROM share_comments WHERE created_at >= :start');
    $commentStmt->execute([':start' => $activeStart7]);
    $commentNew7 = (int)$commentStmt->fetchColumn();
    $reportTotal = (int)$pdo->query('SELECT COUNT(*) FROM share_reports')->fetchColumn();
    $reportPending = (int)$pdo->query('SELECT COUNT(*) FROM share_reports WHERE handled_at IS NULL OR handled_at = ""')->fetchColumn();

    $range7 = build_date_range(7);
    $range30 = build_date_range(30);
    $range7Start = $range7[0] . ' 00:00:00';
    $range30Start = $range30[0] . ' 00:00:00';

    $accessDailySql = 'SELECT substr(created_at, 1, 10) AS day,
        COUNT(*) AS pv,
        COUNT(DISTINCT CASE WHEN visitor_id IS NULL OR visitor_id = "" THEN NULL ELSE visitor_id END) AS uv
        FROM share_access_logs WHERE created_at >= :start GROUP BY day ORDER BY day';
    $accessDailyStmt = $pdo->prepare($accessDailySql);
    $accessDailyStmt->execute([':start' => $range7Start]);
    $accessRows7 = $accessDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $accessDailyStmt->execute([':start' => $range30Start]);
    $accessRows30 = $accessDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $pvSeries7 = fill_series($range7, $accessRows7, 'pv');
    $uvSeries7 = fill_series($range7, $accessRows7, 'uv');
    $pvSeries30 = fill_series($range30, $accessRows30, 'pv');
    $uvSeries30 = fill_series($range30, $accessRows30, 'uv');

    $shareDailySql = 'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS total FROM shares WHERE created_at >= :start GROUP BY day ORDER BY day';
    $shareDailyStmt = $pdo->prepare($shareDailySql);
    $shareDailyStmt->execute([':start' => $range7Start]);
    $shareRows7 = $shareDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareDailyStmt->execute([':start' => $range30Start]);
    $shareRows30 = $shareDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareSeries7 = fill_series($range7, $shareRows7, 'total');
    $shareSeries30 = fill_series($range30, $shareRows30, 'total');

    $userDailySql = 'SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS total FROM users WHERE created_at >= :start GROUP BY day ORDER BY day';
    $userDailyStmt = $pdo->prepare($userDailySql);
    $userDailyStmt->execute([':start' => $range7Start]);
    $userRows7 = $userDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $userDailyStmt->execute([':start' => $range30Start]);
    $userRows30 = $userDailyStmt->fetchAll(PDO::FETCH_ASSOC);
    $userSeries7 = fill_series($range7, $userRows7, 'total');
    $userSeries30 = fill_series($range30, $userRows30, 'total');

    $shareBytesSql = 'SELECT substr(created_at, 1, 10) AS day, COALESCE(SUM(size_bytes), 0) AS total
        FROM shares WHERE created_at >= :start AND deleted_at IS NULL GROUP BY day ORDER BY day';
    $logBytesSql = 'SELECT substr(created_at, 1, 10) AS day, COALESCE(SUM(size_bytes), 0) AS total
        FROM share_access_logs WHERE created_at >= :start GROUP BY day ORDER BY day';
    $shareBytesStmt = $pdo->prepare($shareBytesSql);
    $logBytesStmt = $pdo->prepare($logBytesSql);
    $shareBytesStmt->execute([':start' => $range7Start]);
    $shareBytesRows7 = $shareBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $logBytesStmt->execute([':start' => $range7Start]);
    $logBytesRows7 = $logBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareBytesStmt->execute([':start' => $range30Start]);
    $shareBytesRows30 = $shareBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $logBytesStmt->execute([':start' => $range30Start]);
    $logBytesRows30 = $logBytesStmt->fetchAll(PDO::FETCH_ASSOC);
    $shareBytesSeries7 = fill_series($range7, $shareBytesRows7, 'total');
    $logBytesSeries7 = fill_series($range7, $logBytesRows7, 'total');
    $shareBytesSeries30 = fill_series($range30, $shareBytesRows30, 'total');
    $logBytesSeries30 = fill_series($range30, $logBytesRows30, 'total');
    $storageBytesSeries7 = [];
    $storageBytesSeries30 = [];
    foreach ($shareBytesSeries7 as $index => $value) {
        $storageBytesSeries7[] = $value + ($logBytesSeries7[$index] ?? 0);
    }
    foreach ($shareBytesSeries30 as $index => $value) {
        $storageBytesSeries30[] = $value + ($logBytesSeries30[$index] ?? 0);
    }
    $storageSeries7 = array_map(fn($value) => round($value / 1024 / 1024, 2), $storageBytesSeries7);
    $storageSeries30 = array_map(fn($value) => round($value / 1024 / 1024, 2), $storageBytesSeries30);
    $pvTotal7 = array_sum($pvSeries7);
    $uvTotal7 = array_sum($uvSeries7);
    $pvTotal30 = array_sum($pvSeries30);
    $uvTotal30 = array_sum($uvSeries30);
    $shareTotal7 = array_sum($shareSeries7);
    $shareTotal30 = array_sum($shareSeries30);
    $userTotal7 = array_sum($userSeries7);
    $userTotal30 = array_sum($userSeries30);
    $storageTotal7 = array_sum($storageBytesSeries7);
    $storageTotal30 = array_sum($storageBytesSeries30);

    $pvUvSeries7Chart = [
        ['key' => 'pv', 'values' => $pvSeries7, 'lineClass' => 'chart-line chart-line--primary', 'areaClass' => 'chart-area chart-area--primary'],
        ['key' => 'uv', 'values' => $uvSeries7, 'lineClass' => 'chart-line chart-line--accent'],
    ];
    $pvUvSeries30Chart = [
        ['key' => 'pv', 'values' => $pvSeries30, 'lineClass' => 'chart-line chart-line--primary', 'areaClass' => 'chart-area chart-area--primary'],
        ['key' => 'uv', 'values' => $uvSeries30, 'lineClass' => 'chart-line chart-line--accent'],
    ];
    $shareSeries7Chart = [
        ['key' => 'share', 'values' => $shareSeries7, 'lineClass' => 'chart-line chart-line--secondary', 'areaClass' => 'chart-area chart-area--secondary'],
    ];
    $shareSeries30Chart = [
        ['key' => 'share', 'values' => $shareSeries30, 'lineClass' => 'chart-line chart-line--secondary', 'areaClass' => 'chart-area chart-area--secondary'],
    ];
    $userSeries7Chart = [
        ['key' => 'user', 'values' => $userSeries7, 'lineClass' => 'chart-line chart-line--info', 'areaClass' => 'chart-area chart-area--info'],
    ];
    $userSeries30Chart = [
        ['key' => 'user', 'values' => $userSeries30, 'lineClass' => 'chart-line chart-line--info', 'areaClass' => 'chart-area chart-area--info'],
    ];
    $storageSeries7Chart = [
        [
            'key' => 'storage',
            'values' => $storageSeries7,
            'lineClass' => 'chart-line chart-line--storage',
            'areaClass' => 'chart-area chart-area--storage',
            'sumValues' => $storageBytesSeries7,
            'sumFormat' => 'bytes',
        ],
    ];
    $storageSeries30Chart = [
        [
            'key' => 'storage',
            'values' => $storageSeries30,
            'lineClass' => 'chart-line chart-line--storage',
            'areaClass' => 'chart-area chart-area--storage',
            'sumValues' => $storageBytesSeries30,
            'sumFormat' => 'bytes',
        ],
    ];

    $pvUvChart7 = render_chart_svg([
        ['values' => $pvSeries7, 'line_class' => 'chart-line chart-line--primary', 'area_class' => 'chart-area chart-area--primary'],
        ['values' => $uvSeries7, 'line_class' => 'chart-line chart-line--accent'],
    ]);
    $pvUvChart30 = render_chart_svg([
        ['values' => $pvSeries30, 'line_class' => 'chart-line chart-line--primary', 'area_class' => 'chart-area chart-area--primary'],
        ['values' => $uvSeries30, 'line_class' => 'chart-line chart-line--accent'],
    ]);
    $shareChart7 = render_chart_svg([
        ['values' => $shareSeries7, 'line_class' => 'chart-line chart-line--secondary', 'area_class' => 'chart-area chart-area--secondary'],
    ]);
    $shareChart30 = render_chart_svg([
        ['values' => $shareSeries30, 'line_class' => 'chart-line chart-line--secondary', 'area_class' => 'chart-area chart-area--secondary'],
    ]);
    $userChart7 = render_chart_svg([
        ['values' => $userSeries7, 'line_class' => 'chart-line chart-line--info', 'area_class' => 'chart-area chart-area--info'],
    ]);
    $userChart30 = render_chart_svg([
        ['values' => $userSeries30, 'line_class' => 'chart-line chart-line--info', 'area_class' => 'chart-area chart-area--info'],
    ]);
    $storageChart7 = render_chart_svg([
        ['values' => $storageSeries7, 'line_class' => 'chart-line chart-line--storage', 'area_class' => 'chart-area chart-area--storage'],
    ]);
    $storageChart30 = render_chart_svg([
        ['values' => $storageSeries30, 'line_class' => 'chart-line chart-line--storage', 'area_class' => 'chart-area chart-area--storage'],
    ]);

    $pvUvHolder7 = render_admin_chart_holder($range7, $pvUvSeries7Chart, 'count', $pvUvChart7);
    $pvUvHolder30 = render_admin_chart_holder($range30, $pvUvSeries30Chart, 'count', $pvUvChart30);
    $shareHolder7 = render_admin_chart_holder($range7, $shareSeries7Chart, 'count', $shareChart7);
    $shareHolder30 = render_admin_chart_holder($range30, $shareSeries30Chart, 'count', $shareChart30);
    $userHolder7 = render_admin_chart_holder($range7, $userSeries7Chart, 'count', $userChart7);
    $userHolder30 = render_admin_chart_holder($range30, $userSeries30Chart, 'count', $userChart30);
    $storageHolder7 = render_admin_chart_holder($range7, $storageSeries7Chart, 'MB', $storageChart7);
    $storageHolder30 = render_admin_chart_holder($range30, $storageSeries30Chart, 'MB', $storageChart30);

    $instanceStats = fetch_central_instance_stats();
    $instanceTotal = $instanceStats ? (int)($instanceStats['total'] ?? 0) : null;
    $instanceActive30 = $instanceStats ? (int)($instanceStats['active_30'] ?? 0) : null;
    $instanceUpdatedAt = $instanceStats ? (string)($instanceStats['updated_at'] ?? '') : '';

    $greetingLabel = '你好';
    $hour = (int)date('G');
    if ($hour < 6) {
        $greetingLabel = '凌晨好';
    } elseif ($hour < 12) {
        $greetingLabel = '上午好';
    } elseif ($hour < 18) {
        $greetingLabel = '下午好';
    } else {
        $greetingLabel = '晚上好';
    }
    $adminName = htmlspecialchars((string)($admin['username'] ?? ''));
    $greeting = $greetingLabel . '，' . $adminName;

    $content = '<section class="admin-home">';
    $content .= '<div class="admin-hero card">';
    $content .= '<div class="admin-hero__main">';
    $content .= '<div class="admin-hero__eyebrow">数据统计</div>';
    $content .= '<div class="admin-hero__title">' . $greeting . '</div>';
    $content .= '<div class="admin-hero__meta">今日访问 浏览量(PV) ' . number_format($todayPv) . ' / 访客数(UV) ' . number_format($todayUv) . '</div>';
    $content .= '</div>';
    $content .= '<div class="admin-hero__aside">';
    $content .= '<div class="admin-hero__panel">';
    $content .= '<div class="admin-hero__panel-label">系统在用实例</div>';
    if ($instanceTotal === null) {
        $content .= '<div class="admin-hero__panel-value">—</div>';
        $content .= '<div class="admin-hero__panel-meta muted">统计暂不可用</div>';
    } else {
        $content .= '<div class="admin-hero__panel-value">' . number_format($instanceTotal) . '</div>';
        $content .= '<div class="admin-hero__panel-meta">30天活跃 ' . number_format($instanceActive30 ?? 0) . '</div>';
        if ($instanceUpdatedAt !== '') {
            $content .= '<div class="admin-hero__panel-sub muted">更新时间 ' . htmlspecialchars($instanceUpdatedAt) . '</div>';
        }
    }
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $iconUser = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14z"/></svg>';
    $iconActive = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 12h4l2-5 4 10 2-5h4v2h-3l-3 7-4-10-2 5H3z"/></svg>';
    $iconShare = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 8a3 3 0 1 0-2.83-4H15a3 3 0 0 0 0 4 2.96 2.96 0 0 0 1 .19zM6 14a3 3 0 1 0 2.83 4H9a3 3 0 0 0 0-4 2.96 2.96 0 0 0-1-.19zM18 20a3 3 0 1 0-2.83-4H15a3 3 0 0 0 0 4 2.96 2.96 0 0 0 1 .19zM8.41 12.59l7.18 3.59.9-1.79-7.18-3.59-.9 1.79zM15.59 9.59l-7.18 3.59.9 1.79 7.18-3.59-.9-1.79z"/></svg>';
    $iconAccess = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 14.5 2.5 12 6.5 5 12 5zm0 3a4 4 0 1 0 4 4 4 4 0 0 0-4-4z"/></svg>';
    $iconStorage = '<svg class="kpi-icon kpi-icon--storage" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 6c0-1.1 3.6-2 8-2s8 .9 8 2-3.6 2-8 2-8-.9-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2V8c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2v-2c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2zm0 4c0 1.1 3.6 2 8 2s8-.9 8-2v-2c-1.7 1.2-5.1 2-8 2s-6.3-.8-8-2z"/></svg>';

    $storageValue = format_bytes($usedBytes) . ($totalLimitBytes > 0 ? ' / ' . format_bytes($totalLimitBytes) : '');
    $storageMeta = $totalLimitBytes > 0 ? '剩余 ' . format_bytes($remainingBytes) : '总量不限';
    $storageProgress = '<div class="admin-kpi__progress"><span style="width:' . $storagePercent . '%"></span></div>';

    $content .= '<div class="admin-kpi-grid">';
    $content .= render_kpi_card('总用户', number_format($totalUsers), '含禁用 ' . number_format($disabledUsers), $iconUser);
    $content .= render_kpi_card('活跃用户', number_format($activeUsers30), '近7天 ' . number_format($activeUsers7), $iconActive);
    $content .= render_kpi_card('总分享', number_format($totalSharesActive), '已软删除 ' . number_format($deletedShares), $iconShare);
    $content .= render_kpi_card('总访问', number_format($totalAccess), '访客数(UV) 总计 ' . number_format($totalUv), $iconAccess);
    $content .= render_kpi_card('存储占用/剩余', htmlspecialchars($storageValue), htmlspecialchars($storageMeta), $iconStorage, $storageProgress);
    $content .= '</div>';

    $pvUvRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $pvUvSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $shareRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $shareSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $userRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $userSeries30Chart,
        'unit' => 'count',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $storageRangeSource = htmlspecialchars(json_encode([
        'labels' => $range30,
        'series' => $storageSeries30Chart,
        'unit' => 'MB',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);

    $content .= '<div class="admin-chart-grid">';
    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $pvUvRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">PV/UV 走势</div><div class="admin-chart-card__meta" data-range-label>近7天</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">自定义</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>浏览量(PV) ' . number_format($pvTotal7) . '</div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>访客数(UV) ' . number_format($uvTotal7) . '</div></div>';
    $content .= $pvUvHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>浏览量(PV) ' . number_format($pvTotal30) . '</div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>访客数(UV) ' . number_format($uvTotal30) . '</div></div>';
    $content .= $pvUvHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary"><div class="admin-legend"><span class="legend-dot is-primary"></span>浏览量(PV) <span data-range-metric="pv">0</span></div>';
    $content .= '<div class="admin-legend"><span class="legend-dot is-accent"></span>访客数(UV) <span data-range-metric="uv">0</span></div></div>';
    $content .= $pvUvHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">最近 <span data-range-days>7</span> 天</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $shareRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">分享新增量</div><div class="admin-chart-card__meta" data-range-label>近7天</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">自定义</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">期间新增 ' . number_format($shareTotal7) . ' 条</div>';
    $content .= $shareHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间新增 ' . number_format($shareTotal30) . ' 条</div>';
    $content .= $shareHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间新增 <span data-range-total>0</span> 条</div>';
    $content .= $shareHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">最近 <span data-range-days>7</span> 天</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $userRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">用户新增量</div><div class="admin-chart-card__meta" data-range-label>近7天</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">自定义</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">期间新增 ' . number_format($userTotal7) . ' 人</div>';
    $content .= $userHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间新增 ' . number_format($userTotal30) . ' 人</div>';
    $content .= $userHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间新增 <span data-range-total>0</span> 人</div>';
    $content .= $userHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">最近 <span data-range-days>7</span> 天</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-chart-card" data-range-switch data-range-default="7" data-range-source="' . $storageRangeSource . '">';
    $content .= '<div class="admin-chart-card__head">';
    $content .= '<div><div class="admin-chart-card__title">存储增长</div><div class="admin-chart-card__meta" data-range-label>近7天</div></div>';
    $content .= '<div class="range-toggle">';
    $content .= '<button class="range-btn is-active" type="button" data-range-value="7">7天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="30">30天</button>';
    $content .= '<button class="range-btn" type="button" data-range-value="custom">自定义</button>';
    $content .= '</div></div>';
    $content .= '<div class="admin-chart-card__body">';
    $content .= '<div class="admin-chart-panel" data-range-panel="7">';
    $content .= '<div class="admin-chart-summary muted">期间增长 ' . htmlspecialchars(format_bytes($storageTotal7)) . '</div>';
    $content .= $storageHolder7 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="30" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间增长 ' . htmlspecialchars(format_bytes($storageTotal30)) . '</div>';
    $content .= $storageHolder30 . '</div>';
    $content .= '<div class="admin-chart-panel" data-range-panel="custom" hidden>';
    $content .= '<div class="admin-chart-summary muted">期间增长 <span data-range-total>0</span></div>';
    $content .= $storageHolder30;
    $content .= '<div class="range-slider"><input type="range" min="1" max="30" value="7" data-range-slider><div class="range-slider__value">最近 <span data-range-days>7</span> 天</div></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="admin-governance-grid">';
    $content .= '<div class="admin-governance card">';
    $content .= '<div class="admin-governance__label">评论总量</div>';
    $content .= '<div class="admin-governance__value">' . number_format($commentTotal) . '</div>';
    $content .= '<div class="admin-governance__meta">近7天新增 ' . number_format($commentNew7) . '</div>';
    $content .= '</div>';
    $content .= '<div class="admin-governance card">';
    $content .= '<div class="admin-governance__label">举报总量</div>';
    $content .= '<div class="admin-governance__value">' . number_format($reportTotal) . '</div>';
    $content .= '<div class="admin-governance__meta">待处理 ' . number_format($reportPending) . '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $titleHtml = build_topbar_title('数据统计', $admin);
    render_page('数据统计', $content, $admin, '', ['layout' => 'app', 'nav' => 'admin-home', 'title_html' => $titleHtml]);
}

if ($path === '/api-key/rotate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    [$rawKey, $hash, $prefix, $last4] = generate_api_key();
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET api_key_hash = :hash, api_key_prefix = :prefix, api_key_last4 = :last4, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':hash' => $hash,
        ':prefix' => $prefix,
        ':last4' => $last4,
        ':updated_at' => now(),
        ':id' => $user['id'],
    ]);
    flash('api_key', $rawKey);
    redirect('/dashboard');
}

if ($path === '/dashboard/comment-notify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($shareId <= 0 || !in_array($action, ['enable', 'disable'], true)) {
        flash('error', '请求参数错误');
        redirect('/dashboard#shares');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM shares WHERE id = :id AND user_id = :uid AND deleted_at IS NULL');
    $stmt->execute([':id' => $shareId, ':uid' => $user['id']]);
    if (!$stmt->fetchColumn()) {
        flash('error', '分享不存在');
        redirect('/dashboard#shares');
    }
    $enable = $action === 'enable';
    if ($enable && !smtp_enabled()) {
        flash('error', '请先在后台开启 SMTP，再启用评论邮件通知');
        redirect('/dashboard#shares');
    }
    $update = $pdo->prepare('UPDATE shares SET comment_notify = :notify WHERE id = :id AND user_id = :uid');
    $update->execute([
        ':notify' => $enable ? 1 : 0,
        ':id' => $shareId,
        ':uid' => $user['id'],
    ]);
    flash('info', $enable ? '已开启评论邮件通知' : '已关闭评论邮件通知');
    redirect('/dashboard#shares');
}

if ($path === '/dashboard/access-stats/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    $enabled = !empty($_POST['access_enabled']);
    $daysRaw = (int)($_POST['access_retention_days'] ?? access_stats_retention_days($userId));
    $days = max(1, min(365, $daysRaw));
    set_user_setting($userId, 'access_stats_retention_days', (string)$days);
    if ($enabled) {
        $used = recalculate_user_storage($userId);
        $limit = get_user_limit_bytes($user);
        if ($limit > 0 && $used >= $limit) {
            set_user_setting($userId, 'access_stats_enabled', '0');
            flash('error', '存储空间已满，无法开启访问统计');
            redirect('/dashboard#access-stats');
        }
        set_user_setting($userId, 'access_stats_enabled', '1');
    } else {
        set_user_setting($userId, 'access_stats_enabled', '0');
    }
    flash('info', '访问统计设置已更新');
    redirect('/dashboard#access-stats');
}

if ($path === '/dashboard/access-stats/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    $ids = $_POST['access_ids'] ?? [];
    $ids = is_array($ids) ? array_values(array_filter($ids)) : [];
    if (empty($ids)) {
        redirect('/dashboard#access-stats');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM share_access_logs WHERE user_id = ? AND id IN (' . $placeholders . ')');
    $sumStmt->execute(array_merge([$userId], $ids));
    $total = (int)($sumStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $delStmt = $pdo->prepare('DELETE FROM share_access_logs WHERE user_id = ? AND id IN (' . $placeholders . ')');
    $delStmt->execute(array_merge([$userId], $ids));
    adjust_user_storage($userId, -$total);
    flash('info', '已删除选中的访问记录');
    redirect('/dashboard#access-stats');
}

if ($path === '/dashboard/access-stats/delete-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    check_csrf();
    $userId = (int)$user['id'];
    purge_user_access_logs($userId);
    flash('info', '已清空全部访问记录');
    redirect('/dashboard#access-stats');
}

if ($path === '/admin') {
    $admin = require_admin();
    $pdo = db();
    $info = flash('info');
    $error = flash('error');
    $createForm = $_SESSION['user_create_form'] ?? [];
    if (!is_array($createForm)) {
        $createForm = [];
    }
    $createOpen = !empty($createForm['open']);
    $createUsername = (string)($createForm['username'] ?? '');
    $createEmail = (string)($createForm['email'] ?? '');
    $createRole = (string)($createForm['role'] ?? 'user');
    if (!in_array($createRole, ['admin', 'user'], true)) {
        $createRole = 'user';
    }
    $createDisabled = (string)($createForm['disabled'] ?? '0');
    $createLimitMb = (string)($createForm['limit_mb'] ?? '0');
    $createPassword = (string)($createForm['password'] ?? '');
    unset($_SESSION['user_create_form']);
    $allowRegistration = allow_registration();
    $captchaEnabled = captcha_enabled();
    $emailVerifyEnabled = email_verification_enabled();
    $defaultLimitBytes = default_storage_limit_bytes();
    $defaultLimitMb = mb_from_bytes($defaultLimitBytes);
    $emailFrom = get_setting('email_from', 'no-reply@example.com');
    $emailFromName = get_setting('email_from_name', '思源笔记分享');
    $emailSubject = get_setting('email_subject', '邮箱验证码');
    $emailResetSubject = get_setting('email_reset_subject', '重置密码验证码');
    $smtpEnabled = smtp_enabled();
    $smtpHost = get_setting('smtp_host', '');
    $smtpPort = get_setting('smtp_port', '587');
    $smtpSecure = get_setting('smtp_secure', 'tls');
    $smtpUser = get_setting('smtp_user', '');
    $smtpPass = get_setting('smtp_pass', '');
    $siteIcp = get_setting('site_icp', '');
    $siteContactEmail = get_setting('site_contact_email', '');
    $siteBaseUrl = get_setting('site_base_url', '');
    $siteHeadHtml = get_setting('site_head_html', '');
    $siteTips = get_setting('site_tips', '');
    $siteCustomCssEnabled = get_bool_setting('site_custom_css_enabled', true);
    $siteCustomCss = get_setting('site_custom_css', '');
    $siteCustomJsEnabled = get_bool_setting('site_custom_js_enabled', false);
    $siteCustomJs = get_setting('site_custom_js', '');
    $bannedWordsRaw = get_banned_words_raw();
    $scanKeep = ((string)($_GET['scan_keep'] ?? '')) === '1';
    if (!$scanKeep) {
        unset($_SESSION['scan_results'], $_SESSION['scan_logs'], $_SESSION['scan_done'], $_SESSION['scan_at'], $_SESSION['scan_total']);
    }
    $scanResults = $_SESSION['scan_results'] ?? [];
    $scanAt = (int)($_SESSION['scan_at'] ?? 0);
    $scanResults = is_array($scanResults) ? $scanResults : [];
    $scanLogs = $_SESSION['scan_logs'] ?? [];
    $scanLogs = is_array($scanLogs) ? $scanLogs : [];
    $scanDone = (int)($_SESSION['scan_done'] ?? 0) === 1;
    $scanPage = max(1, (int)($_GET['scan_page'] ?? 1));
    $scanSize = normalize_page_size($_GET['scan_size'] ?? 10);
    $scanTotal = count($scanResults);
    $scanKeepParam = (!empty($scanLogs) || $scanTotal > 0) ? '1' : null;
    $scanLogHtml = '';
    foreach ($scanLogs as $log) {
        $scanLogHtml .= '<div>' . $log . '</div>';
    }
    $scanProgressHidden = $scanLogHtml === '' ? ' hidden' : '';
    $scanReady = $scanDone || $scanLogHtml !== '';
    $scanStatusLabel = $scanReady ? ('扫描完成，共命中 ' . number_format($scanTotal) . ' 条记录') : '等待扫描...';
    $scanBarStyle = $scanReady ? ' style="width:100%"' : '';
    [$scanPage, $scanSize, $scanPages, $scanOffset] = paginate($scanTotal, $scanPage, $scanSize);
    $scanPageResults = array_slice($scanResults, $scanOffset, $scanSize);
    [$chunkTtlSeconds] = chunk_cleanup_settings();
    $staleChunks = list_stale_chunks($chunkTtlSeconds);
    $allUsers = $pdo->query('SELECT id, username FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
    $userSearch = trim((string)($_GET['user_search'] ?? ''));
    $userStatus = (string)($_GET['user_status'] ?? 'all');
    $userRole = (string)($_GET['user_role'] ?? 'all');
    $userPage = max(1, (int)($_GET['user_page'] ?? 1));
    $userSize = normalize_page_size($_GET['user_size'] ?? 10);
    $userWhere = [];
    $userParams = [];
    if ($userSearch !== '') {
        $userWhere[] = '(username LIKE :user_search OR email LIKE :user_search)';
        $userParams[':user_search'] = '%' . $userSearch . '%';
    }
    if ($userStatus === 'active') {
        $userWhere[] = 'disabled = 0';
    } elseif ($userStatus === 'disabled') {
        $userWhere[] = 'disabled = 1';
    }
    if ($userRole === 'admin') {
        $userWhere[] = 'role = "admin"';
    } elseif ($userRole === 'user') {
        $userWhere[] = 'role = "user"';
    }
    $userSql = 'SELECT * FROM users';
    $userCountSql = 'SELECT COUNT(*) FROM users';
    if (!empty($userWhere)) {
        $userSql .= ' WHERE ' . implode(' AND ', $userWhere);
        $userCountSql .= ' WHERE ' . implode(' AND ', $userWhere);
    }
    $userCountStmt = $pdo->prepare($userCountSql);
    $userCountStmt->execute($userParams);
    $totalUsers = (int)$userCountStmt->fetchColumn();
    $userCountStmt->closeCursor();
    [$userPage, $userSize, $userPages, $userOffset] = paginate($totalUsers, $userPage, $userSize);
    $userSql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
    $userStmt = $pdo->prepare($userSql);
    foreach ($userParams as $key => $value) {
        $userStmt->bindValue($key, $value);
    }
    $userStmt->bindValue(':limit', $userSize, PDO::PARAM_INT);
    $userStmt->bindValue(':offset', $userOffset, PDO::PARAM_INT);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    $userStmt->closeCursor();
    // 批量聚合重算（整页 2 条 SQL），替代逐用户 3 条 SQL 的"读后写"循环，
    // 避免与 nd-worker 心跳/队列写入撞 SQLite 升级死锁（database is locked）。
    $storageMap = recalculate_users_storage_batch(array_column($users, 'id'));
    foreach ($users as &$u) {
        $uid = (int)$u['id'];
        $u['storage_used_bytes'] = $storageMap[$uid] ?? (int)($u['storage_used_bytes'] ?? 0);
    }
    unset($u);

    $filterUser = (int)($_GET['user'] ?? 0);
    $filterStatus = (string)($_GET['status'] ?? 'active');
    $shareSearch = trim((string)($_GET['share_search'] ?? ''));
    $sharePage = max(1, (int)($_GET['share_page'] ?? 1));
    $shareSize = normalize_page_size($_GET['share_size'] ?? 10);
    if (!in_array($filterStatus, ['active', 'expired', 'deleted', 'all'], true)) {
        $filterStatus = 'active';
    }
    $where = [];
    $params = [];
    if ($filterUser > 0) {
        $where[] = 'shares.user_id = :uid';
        $params[':uid'] = $filterUser;
    }
    if ($shareSearch !== '') {
        $where[] = '(shares.title LIKE :share_search OR shares.slug LIKE :share_search)';
        $params[':share_search'] = '%' . $shareSearch . '%';
    }
    if ($filterStatus === 'active') {
        $where[] = 'shares.deleted_at IS NULL';
        $where[] = '(shares.expires_at IS NULL OR shares.expires_at <= 0 OR shares.expires_at >= :now_ts)';
        $params[':now_ts'] = time();
    } elseif ($filterStatus === 'expired') {
        $where[] = 'shares.deleted_at IS NULL';
        $where[] = 'shares.expires_at > 0';
        $where[] = 'shares.expires_at < :now_ts';
        $params[':now_ts'] = time();
    } elseif ($filterStatus === 'deleted') {
        $where[] = 'shares.deleted_at IS NOT NULL';
    }
    $shareSql = 'SELECT shares.*, users.username FROM shares JOIN users ON shares.user_id = users.id';
    $shareCountSql = 'SELECT COUNT(*) FROM shares JOIN users ON shares.user_id = users.id';
    if (!empty($where)) {
        $shareSql .= ' WHERE ' . implode(' AND ', $where);
        $shareCountSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $shareCountStmt = $pdo->prepare($shareCountSql);
    $shareCountStmt->execute($params);
    $totalShares = (int)$shareCountStmt->fetchColumn();
    [$sharePage, $shareSize, $sharePages, $shareOffset] = paginate($totalShares, $sharePage, $shareSize);
    $shareSql .= ' ORDER BY shares.updated_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($shareSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $shareSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $shareOffset, PDO::PARAM_INT);
    $stmt->execute();
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reportStatus = (string)($_GET['report_status'] ?? 'pending');
    if (!in_array($reportStatus, ['pending', 'handled', 'all'], true)) {
        $reportStatus = 'pending';
    }
    $reportPage = max(1, (int)($_GET['report_page'] ?? 1));
    $reportSize = normalize_page_size($_GET['report_size'] ?? 10);
    $reportWhere = [];
    if ($reportStatus === 'pending') {
        $reportWhere[] = 'share_reports.handled_at IS NULL';
    } elseif ($reportStatus === 'handled') {
        $reportWhere[] = 'share_reports.handled_at IS NOT NULL';
    }
    $reportSql = 'SELECT share_reports.*, users.username AS share_username, reporters.username AS reporter_username
        FROM share_reports
        LEFT JOIN users ON share_reports.share_user_id = users.id
        LEFT JOIN users reporters ON share_reports.reporter_user_id = reporters.id';
    $reportCountSql = 'SELECT COUNT(*) FROM share_reports';
    if (!empty($reportWhere)) {
        $reportSql .= ' WHERE ' . implode(' AND ', $reportWhere);
        $reportCountSql .= ' WHERE ' . implode(' AND ', $reportWhere);
    }
    $reportCountStmt = $pdo->prepare($reportCountSql);
    $reportCountStmt->execute();
    $reportTotal = (int)$reportCountStmt->fetchColumn();
    [$reportPage, $reportSize, $reportPages, $reportOffset] = paginate($reportTotal, $reportPage, $reportSize);
    $reportSql .= ' ORDER BY share_reports.created_at DESC LIMIT :limit OFFSET :offset';
    $reportStmt = $pdo->prepare($reportSql);
    $reportStmt->bindValue(':limit', $reportSize, PDO::PARAM_INT);
    $reportStmt->bindValue(':offset', $reportOffset, PDO::PARAM_INT);
    $reportStmt->execute();
    $reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

    $announcements = $pdo->query('SELECT a.*, u.username AS author FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);
    $userQuery = $_GET;
    unset($userQuery['user_page'], $userQuery['user_size'], $userQuery['user_search'], $userQuery['user_status'], $userQuery['user_role']);
    $shareQuery = $_GET;
    unset($shareQuery['share_page'], $shareQuery['share_size'], $shareQuery['share_search'], $shareQuery['user'], $shareQuery['status']);
    $reportQuery = $_GET;
    unset($reportQuery['report_page'], $reportQuery['report_size'], $reportQuery['report_status']);
    $scanQuery = $_GET;
    unset($scanQuery['scan_page'], $scanQuery['scan_size']);
    if ($scanKeepParam !== null) {
        $scanQuery['scan_keep'] = $scanKeepParam;
    } else {
        unset($scanQuery['scan_keep']);
    }

    $content = '';
    if ($error) {
        $content .= '<div class="flash">' . htmlspecialchars($error) . '</div>';
    }
    if ($info) {
        $content .= '<div class="flash">' . htmlspecialchars($info) . '</div>';
    }

    $content .= '<div class="card" id="settings"><h2>站点设置</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/settings">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>默认存储上限 (MB)</label><input class="input" name="default_storage_limit_mb" type="number" min="0" value="' . (int)$defaultLimitMb . '"></div>';
    $content .= '<div><label>邮箱发件人</label><input class="input" name="email_from" value="' . htmlspecialchars((string)$emailFrom) . '"></div>';
    $content .= '<div><label>发件人名称</label><input class="input" name="email_from_name" value="' . htmlspecialchars((string)$emailFromName) . '"></div>';
    $content .= '<div><label>验证码主题</label><input class="input" name="email_subject" value="' . htmlspecialchars((string)$emailSubject) . '"></div>';
    $content .= '<div><label>重置密码主题</label><input class="input" name="email_reset_subject" value="' . htmlspecialchars((string)$emailResetSubject) . '"></div>';
    $content .= '<div><label>ICP备案号</label><input class="input" name="site_icp" value="' . htmlspecialchars((string)$siteIcp) . '"></div>';
    $content .= '<div><label>联系邮箱</label><input class="input" name="site_contact_email" value="' . htmlspecialchars((string)$siteContactEmail) . '"></div>';
    $content .= '<div><label>网站地址（分享链接前缀）<button class="link-button" type="button" data-report-open data-report-target="site-base-url-help">说明</button></label><input class="input" name="site_base_url" placeholder="https://share.example.com" value="' . htmlspecialchars((string)$siteBaseUrl) . '"></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>违禁词（用 | 分隔）</label>';
    $content .= '<textarea class="input" name="banned_words" rows="2" placeholder="示例：词1|词2|词3">' . htmlspecialchars($bannedWordsRaw) . '</textarea>';
    $content .= '<div class="muted">用户分享和分享页评论命中任意违禁词将拒绝分享和评论，并在扫描结果中标记。</div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>HTML Head 插入内容</label>';
    $content .= '<textarea class="input" name="site_head_html" rows="4" placeholder="例如：&lt;script src=&quot;https://example.com/xxx.js&quot;&gt;&lt;/script&gt;">' . htmlspecialchars((string)$siteHeadHtml) . '</textarea>';
    $content .= '<div class="muted">会插入到页面 &lt;head&gt; 中，可用于统计脚本。</div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>使用技巧（Markdown，支持思源 Callout）</label>';
    $content .= '<textarea class="input" name="site_tips" rows="6" placeholder="示例：&#10;&gt; [!tip] 快速开始&#10;&gt; 分享页右上角可切换文档、搜索和目录。">' . htmlspecialchars((string)$siteTips) . '</textarea>';
    $content .= '<div class="muted">显示在分享页右下角紫色「使用技巧」悬浮按钮中，留空则不显示。</div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>自定义 CSS<button class="link-button" type="button" data-report-open data-report-target="site-custom-css-help">示例</button></label>';
    $content .= '<textarea class="input code-input" name="site_custom_css" rows="8" placeholder="/* 示例 1：正文更宽，阅读更舒服 */&#10;body.layout-share .markdown-body {&#10;  max-width: 980px;&#10;  margin: 0 auto;&#10;}&#10;&#10;/* ---------- 示例 2：隐藏评论区 ---------- */&#10;body.layout-share .share-comments {&#10;  display: none !important;&#10;}">' . htmlspecialchars((string)$siteCustomCss) . '</textarea>';
    $content .= '<div class="muted">建议加上 <code>body.layout-share</code> 或 <code>body.layout-app</code> 前缀，减少误伤。</div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label>自定义 JS<button class="link-button" type="button" data-report-open data-report-target="site-custom-js-help">示例</button></label>';
    $content .= '<textarea class="input code-input" name="site_custom_js" rows="8" placeholder="// 示例 1：进入页面自动展开目录树&#10;const expandBtn = document.querySelector(&quot;[data-tree-expand]&quot;);&#10;if (expandBtn) expandBtn.click();&#10;&#10;// ---------- 示例 2：页面加载后聚焦搜索框 ----------&#10;const search = document.querySelector(&quot;.kb-search-input&quot;);&#10;if (search) {&#10;  search.focus();&#10;  search.select();&#10;}">' . htmlspecialchars((string)$siteCustomJs) . '</textarea>';
    $content .= '<div class="muted">默认关闭。</div>';
    $content .= '</div>';
    $content .= '<div class="grid" style="margin-top:12px">';
    $content .= '<label><input type="checkbox" name="allow_registration" value="1"' . ($allowRegistration ? ' checked' : '') . '> 允许注册</label>';
    $content .= '<label><input type="checkbox" name="captcha_enabled" value="1"' . ($captchaEnabled ? ' checked' : '') . '> 启用验证码</label>';
    $content .= '<label><input type="checkbox" name="email_verification_enabled" value="1"' . ($emailVerifyEnabled ? ' checked' : '') . '> 启用邮箱验证码</label>';
    $content .= '<label><input type="checkbox" name="smtp_enabled" value="1"' . ($smtpEnabled ? ' checked' : '') . '> 启用 SMTP</label>';
    $content .= '<label><input type="checkbox" name="site_custom_css_enabled" value="1"' . ($siteCustomCssEnabled ? ' checked' : '') . '> 启用自定义 CSS</label>';
    $content .= '<label><input type="checkbox" name="site_custom_js_enabled" value="1"' . ($siteCustomJsEnabled ? ' checked' : '') . '> 启用自定义 JS</label>';
    $content .= '</div>';
    $content .= '<div class="grid" style="margin-top:12px">';
    $content .= '<div><label>SMTP 主机</label><input class="input" name="smtp_host" value="' . htmlspecialchars((string)$smtpHost) . '"></div>';
    $content .= '<div><label>SMTP 端口</label><input class="input" name="smtp_port" type="number" min="0" value="' . htmlspecialchars((string)$smtpPort) . '"></div>';
    $content .= '<div><label>加密方式</label><select class="input" name="smtp_secure">';
    $content .= '<option value="none"' . ($smtpSecure === 'none' ? ' selected' : '') . '>无</option>';
    $content .= '<option value="tls"' . ($smtpSecure === 'tls' ? ' selected' : '') . '>TLS</option>';
    $content .= '<option value="ssl"' . ($smtpSecure === 'ssl' ? ' selected' : '') . '>SSL</option>';
    $content .= '</select></div>';
    $content .= '<div><label>SMTP 用户名</label><input class="input" name="smtp_user" value="' . htmlspecialchars((string)$smtpUser) . '"></div>';
    $content .= '<div><label>SMTP 密码</label><input class="input" type="password" name="smtp_pass" value="' . htmlspecialchars((string)$smtpPass) . '"></div>';
    $content .= '</div>';
    $content .= '<div class="muted" style="margin-top:8px">网盘相关设置（Cloudreve 连接、分享绑定、文件预览引擎）请到“分享管理 → 网盘配置”中配置。</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">保存设置</button></div>';
    $content .= '</form></div>';
    $content .= '<div class="modal" id="site-base-url-help" data-report-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">网站地址说明</div>';
    $content .= '<div class="modal-body">';
    $content .= '<p><strong>留空：</strong>自动识别当前访问地址（协议/域名/端口）。</p>';
    $content .= '<p><strong>填写：</strong>分享链接统一使用该前缀，适合反代/HTTPS 终止/端口丢失等场景。</p>';
    $content .= '<p><strong>示例：</strong><code>https://share.example.com</code> 或 <code>https://IP:端口</code></p>';
    $content .= '<p><strong>说明：</strong>仅影响分享链接前缀，不会限制其他访问方式。</p>';
    $content .= '</div>';
    $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>关闭</button></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div class="modal" id="site-custom-css-help" data-report-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">自定义 CSS 示例</div>';
    $content .= '<div class="modal-body custom-help-body">';
    $content .= '<p><strong>快速教程</strong></p>';
    $content .= '<p>1. 打开目标页面，按 <code>F12</code>。</p>';
    $content .= '<p>2. 点左上角元素选择器，选中目标元素，记录 class / id。</p>';
    $content .= '<p>3. 把“页面 + 选择器 + 目标效果”发给 AI 生成 CSS，再粘贴回这里。</p>';
    $content .= '<p><strong>提问示例：</strong><code>在分享页，把 .share-comments 隐藏，并保持其他区域不变</code></p>';
    $content .= '<p><strong>实用示例 1：隐藏评论区</strong></p>';
    $content .= '<pre class="help-code">body.layout-share .share-comments {&#10;  display: none !important;&#10;}</pre>';
    $content .= '<p><strong>实用示例 2：正文更宽（阅读更舒服）</strong></p>';
    $content .= '<pre class="help-code">body.layout-share .markdown-body {&#10;  max-width: 980px;&#10;  margin: 0 auto;&#10;}</pre>';
    $content .= '<p><strong>实用示例 3：正文段落更疏朗</strong></p>';
    $content .= '<pre class="help-code">body.layout-share .markdown-body p {&#10;  line-height: 1.9;&#10;}</pre>';
    $content .= '<p><strong>实用示例 4：一级标题更醒目</strong></p>';
    $content .= '<pre class="help-code">body.layout-share .markdown-body h1 {&#10;  font-size: 2rem;&#10;  margin-bottom: 0.75em;&#10;}</pre>';
    $content .= '</div>';
    $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>关闭</button></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div class="modal" id="site-custom-js-help" data-report-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">自定义 JS 示例</div>';
    $content .= '<div class="modal-body custom-help-body">';
    $content .= '<p><strong>使用说明：</strong>只写 JS 语句，不要包 <code>&lt;script&gt;</code> 标签。脚本会在页面加载后执行。</p>';
    $content .= '<p><strong>实用示例 1：进入页面自动展开目录树</strong></p>';
    $content .= '<pre class="help-code">const expandBtn = document.querySelector(&quot;[data-tree-expand]&quot;);&#10;if (expandBtn) expandBtn.click();</pre>';
    $content .= '<p><strong>实用示例 2：移动端自动打开侧边栏</strong></p>';
    $content .= '<pre class="help-code">if (window.innerWidth &lt; 900) {&#10;  const openBtn = document.querySelector(&quot;[data-share-drawer-open]&quot;);&#10;  if (openBtn) openBtn.click();&#10;}</pre>';
    $content .= '<p><strong>实用示例 3：页面加载后聚焦搜索框</strong></p>';
    $content .= '<pre class="help-code">const search = document.querySelector(&quot;.kb-search-input&quot;);&#10;if (search) {&#10;  search.focus();&#10;  search.select();&#10;}</pre>';
    $content .= '<p><strong>实用示例 4：用 JS 隐藏评论和举报入口</strong></p>';
    $content .= '<pre class="help-code">document.querySelectorAll(&quot;.share-comments, #report, .report-trigger&quot;).forEach((el) =&gt; {&#10;  el.remove();&#10;});</pre>';
    $content .= '</div>';
    $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>关闭</button></div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card"><h2>SMTP 测试</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/smtp-test">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>测试邮箱</label><input class="input" name="test_email" placeholder="例如 test@example.com" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">发送测试邮件</button></div>';
    $content .= '</form></div>';

    $content .= '<div class="card" id="announcements"><h2>发布公告</h2>';
    $content .= '<form method="post" action="' . base_path() . '/admin/announcement/create">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>标题</label><input class="input" name="title" required></div>';
    $content .= '<div><label>内容</label><textarea class="input" name="content" rows="4" required></textarea></div>';
    $content .= '</div>';
    $content .= '<div class="muted" style="margin-top:6px">支持 HTML，保存后将直接渲染。</div>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<label><input type="checkbox" name="active" value="1" checked> 立即发布</label>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">发布公告</button></div>';
    $content .= '</form></div>';

    $content .= '<div class="card"><h2>公告列表</h2>';
    if (empty($announcements)) {
        $content .= '<p class="muted">暂无公告。</p>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions"><table class="table table-has-actions"><thead><tr><th>标题</th><th>状态</th><th>发布人</th><th>发布时间</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($announcements as $item) {
            $status = ((int)$item['active'] === 1) ? '已启用' : '已停用';
            $author = $item['author'] ?? '系统';
            $content .= '<tr>';
            $content .= '<td>' . htmlspecialchars($item['title']) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . htmlspecialchars($author) . '</td>';
            $content .= '<td>' . htmlspecialchars($item['created_at']) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/toggle" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<button class="button" type="submit">' . (((int)$item['active'] === 1) ? '停用' : '启用') . '</button>';
            $content .= '</form>';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<button class="button danger" type="submit">删除</button>';
            $content .= '</form>';
            $content .= '<details class="announcement-edit">';
            $content .= '<summary class="button">编辑</summary>';
            $content .= '<form method="post" action="' . base_path() . '/admin/announcement/update" class="announcement-edit-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="announcement_id" value="' . (int)$item['id'] . '">';
            $content .= '<div class="grid">';
            $content .= '<div><label>标题</label><input class="input" name="title" value="' . htmlspecialchars($item['title']) . '" required></div>';
            $content .= '<div><label>内容</label><textarea class="input" name="content" rows="4" required>' . htmlspecialchars((string)$item['content']) . '</textarea></div>';
            $content .= '</div>';
            $content .= '<div class="muted" style="margin-top:6px">支持 HTML，保存后将直接渲染。</div>';
            $content .= '<label style="margin-top:8px"><input type="checkbox" name="active" value="1"' . (((int)$item['active'] === 1) ? ' checked' : '') . '> 启用</label>';
            $content .= '<div style="margin-top:8px"><button class="button primary" type="submit">保存修改</button></div>';
            $content .= '</form>';
            $content .= '</details>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '</div>';

    $content .= '<div class="card" id="reports"><h2>举报管理</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#reports" class="filter-form">';
    $content .= render_hidden_inputs($reportQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>状态筛选</label><select class="input" name="report_status">';
    $content .= '<option value="pending"' . ($reportStatus === 'pending' ? ' selected' : '') . '>未处理</option>';
    $content .= '<option value="handled"' . ($reportStatus === 'handled' ? ' selected' : '') . '>已处理</option>';
    $content .= '<option value="all"' . ($reportStatus === 'all' ? ' selected' : '') . '>全部</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">筛选</button></div>';
    $content .= '</form>';
    $content .= '<form id="report-batch-form" method="post" action="' . base_path() . '/admin/report-batch" data-confirm-message="确定删除选中的举报记录吗？">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="reports" form="report-batch-form"> 全选</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="delete">批量删除</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit">执行</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($reports)) {
        $content .= '<p class="muted" style="margin-top:12px">暂无举报记录。</p>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th><input type="checkbox" data-check-all="reports" form="report-batch-form"></th><th>时间</th><th>分享</th><th>用户</th><th>状态</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($reports as $report) {
            $reportId = (int)($report['id'] ?? 0);
            $shareTitle = htmlspecialchars((string)($report['share_title'] ?? ''));
            $shareSlug = (string)($report['share_slug'] ?? '');
            $shareUrl = $shareSlug !== '' ? share_url($shareSlug) : '';
            $shareUserId = (int)($report['share_user_id'] ?? 0);
            $shareUser = htmlspecialchars((string)($report['share_username'] ?? ''));
            $reporter = (string)($report['reporter_username'] ?? '');
            $reporterLabel = $reporter !== '' ? htmlspecialchars($reporter) : '游客';
            $reportEmailRaw = (string)($report['report_email'] ?? '');
            $reportEmailLabel = $reportEmailRaw !== '' ? $reportEmailRaw : '-';
            $reason = report_reason_label((string)($report['reason_type'] ?? ''));
            $detailRaw = (string)($report['reason_detail'] ?? '');
            $created = htmlspecialchars((string)($report['created_at'] ?? ''));
            $handledAt = (string)($report['handled_at'] ?? '');
            $statusLabel = $handledAt !== '' ? '已处理' : '未处理';
            $modalId = 'report-view-' . $reportId;
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="report_ids[]" value="' . $reportId . '" data-check-item="reports" form="report-batch-form"></td>';
            $content .= '<td>' . $created . '</td>';
            $content .= '<td>';
            if ($shareUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($shareUrl) . '" target="_blank">' . $shareTitle . '</a>';
                $content .= '<div class="muted">/s/' . htmlspecialchars($shareSlug) . '</div>';
            } else {
                $content .= $shareTitle !== '' ? $shareTitle : '已删除';
            }
            $content .= '</td>';
            $content .= '<td>';
            if ($shareUserId > 0) {
                $content .= '<a href="' . base_path() . '/admin?user=' . $shareUserId . '&status=all#shares">' . ($shareUser !== '' ? $shareUser : ('ID:' . $shareUserId)) . '</a>';
            } else {
                $content .= $shareUser !== '' ? $shareUser : '-';
            }
            $content .= '</td>';
            $content .= '<td>' . htmlspecialchars($statusLabel) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button ghost" type="button" data-report-open data-report-target="' . htmlspecialchars($modalId) . '">查看举报内容</button>';
            if ($handledAt === '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-handle" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button" type="submit">标记已处理</button>';
                $content .= '</form>';
            }
            if ($handledAt !== '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-delete" class="inline-form" data-confirm-message="确定删除该举报记录吗？">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button ghost" type="submit">删除记录</button>';
                $content .= '</form>';
            }
            if ($shareSlug !== '') {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-share-delete" class="inline-form" data-confirm-message="确定彻底删除该分享吗？该操作不可恢复。">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button danger" type="submit">彻底删除分享</button>';
                $content .= '</form>';
            }
            if ($shareUserId > 0) {
                $content .= '<form method="post" action="' . base_path() . '/admin/report-user-disable" class="inline-form" data-confirm-message="确定禁用该账号吗？">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="report_id" value="' . $reportId . '">';
                $content .= '<button class="button" type="submit">禁用账号</button>';
                $content .= '</form>';
            }
            $content .= '<div class="modal report-modal" id="' . htmlspecialchars($modalId) . '" data-report-modal hidden>';
            $content .= '<div class="modal-backdrop" data-modal-close></div>';
            $content .= '<div class="modal-card">';
            $content .= '<div class="modal-header"><h3>举报内容</h3></div>';
            $content .= '<div class="modal-body">';
            $content .= '<div class="report-grid">';
            $content .= '<div><label>举报类型</label><input class="input" value="' . htmlspecialchars($reason) . '" readonly></div>';
            $content .= '<div><label>举报邮箱</label><input class="input" value="' . htmlspecialchars($reportEmailLabel) . '" readonly></div>';
            $content .= '<div><label>举报者</label><input class="input" value="' . htmlspecialchars($reporterLabel) . '" readonly></div>';
            $content .= '<div class="report-wide"><label>补充说明</label><textarea class="input" rows="4" readonly>' . htmlspecialchars($detailRaw) . '</textarea></div>';
            $content .= '</div>';
            $content .= '<div class="modal-actions"><button class="button" type="button" data-modal-close>关闭</button></div>';
            $content .= '</div>';
            $content .= '</div>';
            $content .= '</div>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('reports', ['report_page' => max(1, $reportPage - 1)]) . '">上一页</a>';
    $content .= '<div class="pagination-info">第' . $reportPage . ' / ' . $reportPages . ' 页，共 ' . $reportTotal . ' 条举报</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('reports', ['report_page' => min($reportPages, $reportPage + 1)]) . '">下一页</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#reports" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($reportQuery, [
        'report_status' => $reportStatus,
    ]));
    $content .= '<label>每页</label><select class="input" name="report_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $reportSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>页码</label><input class="input small" type="number" name="report_page" min="1" max="' . $reportPages . '" value="' . $reportPage . '">';
    $content .= '<button class="button" type="submit">跳转</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="users"><h2>用户管理</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#users" class="filter-form">';
    $content .= render_hidden_inputs($userQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>关键词</label><input class="input" name="user_search" placeholder="用户名 / 邮箱" value="' . htmlspecialchars($userSearch) . '"></div>';
    $content .= '<div><label>状态筛选</label><select class="input" name="user_status">';
    $content .= '<option value="all"' . ($userStatus === 'all' ? ' selected' : '') . '>全部</option>';
    $content .= '<option value="active"' . ($userStatus === 'active' ? ' selected' : '') . '>正常</option>';
    $content .= '<option value="disabled"' . ($userStatus === 'disabled' ? ' selected' : '') . '>已禁用</option>';
    $content .= '</select></div>';
    $content .= '<div><label>角色筛选</label><select class="input" name="user_role">';
    $content .= '<option value="all"' . ($userRole === 'all' ? ' selected' : '') . '>全部</option>';
    $content .= '<option value="admin"' . ($userRole === 'admin' ? ' selected' : '') . '>管理员</option>';
    $content .= '<option value="user"' . ($userRole === 'user' ? ' selected' : '') . '>普通用户</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div class="table-actions">';
    $content .= '<button class="button" type="submit">筛选</button>';
    $content .= '<button class="button" type="button" data-user-create-open>添加账号</button>';
    $content .= '</div>';
    $content .= '</form>';

    $content .= '<form id="user-batch-form" method="post" action="' . base_path() . '/admin/user-batch" data-batch-form="user">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="users" form="user-batch-form"> 全选</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="disable">批量禁用</option>';
    $content .= '<option value="enable">批量启用</option>';
    $content .= '<option value="delete">批量删除</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit">执行</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($users)) {
        $content .= '<p class="muted" style="margin-top:12px">暂无用户记录。</p>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions"><table class="table table-has-actions"><thead><tr><th><input type="checkbox" data-check-all="users" form="user-batch-form"></th><th>用户名</th><th>角色</th><th>状态</th><th>邮箱</th><th>存储</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $status = (int)$u['disabled'] === 1 ? '禁用' : '正常';
            $roleLabel = $u['role'] === 'admin' ? '管理员' : '用户';
            $limitMb = mb_from_bytes((int)$u['storage_limit_bytes']);
            $limitLabel = (int)$u['storage_limit_bytes'] > 0
                ? format_bytes((int)$u['storage_limit_bytes'])
                : ($defaultLimitBytes > 0 ? '默认(' . format_bytes($defaultLimitBytes) . ')' : '不限');
            $usedLabel = format_bytes((int)$u['storage_used_bytes']);
            $disabledAttr = $u['role'] === 'admin' ? ' disabled' : '';
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="user_ids[]" value="' . (int)$u['id'] . '" data-check-item="users" form="user-batch-form"' . $disabledAttr . '></td>';
            $content .= '<td>' . htmlspecialchars($u['username']) . '</td>';
            $content .= '<td>' . htmlspecialchars($roleLabel) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . htmlspecialchars((string)$u['email']) . '</td>';
            $content .= '<td>' . htmlspecialchars($usedLabel) . ' / ' . htmlspecialchars($limitLabel) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button" type="button" data-user-edit data-user-id="' . (int)$u['id'] . '" data-user-name="' . htmlspecialchars($u['username']) . '" data-user-email="' . htmlspecialchars((string)$u['email']) . '" data-user-role="' . htmlspecialchars((string)$u['role']) . '" data-user-disabled="' . (int)$u['disabled'] . '" data-user-limit="' . (int)$limitMb . '">编辑</button>';
            $shareUrl = build_admin_query_url('shares', ['user' => (int)$u['id'], 'status' => 'all']);
            $content .= '<a class="button" href="' . htmlspecialchars($shareUrl) . '">查看分享</a>';
            if ($u['role'] !== 'admin' && (int)$u['id'] !== (int)$admin['id']) {
                $content .= '<form method="post" action="' . base_path() . '/admin/user-delete" class="inline-form" data-confirm-message="确定删除该用户及其所有分享吗？该操作不可恢复。">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="user_id" value="' . (int)$u['id'] . '">';
                $content .= '<button class="button danger" type="submit">删除</button>';
                $content .= '</form>';
            }
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '';

    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('users', ['user_page' => max(1, $userPage - 1)]) . '">上一页</a>';
    $content .= '<div class="pagination-info">第 ' . $userPage . ' / ' . $userPages . ' 页，共 ' . $totalUsers . ' 个用户</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('users', ['user_page' => min($userPages, $userPage + 1)]) . '">下一页</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#users" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($userQuery, [
        'user_search' => $userSearch,
        'user_status' => $userStatus,
        'user_role' => $userRole,
    ]));
    $content .= '<label>每页</label><select class="input" name="user_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $userSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>页码</label><input class="input small" type="number" name="user_page" min="1" max="' . $userPages . '" value="' . $userPage . '">';
    $content .= '<button class="button" type="submit">跳转</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="shares"><h2>分享管理</h2>';
    $content .= '<form method="get" action="' . base_path() . '/admin#shares" class="filter-form">';
    $content .= render_hidden_inputs($shareQuery);
    $content .= '<div class="grid">';
    $content .= '<div><label>关键词</label><input class="input" name="share_search" placeholder="标题 / Slug" value="' . htmlspecialchars($shareSearch) . '"></div>';
    $content .= '<div><label>用户筛选</label><select class="input" name="user">';
    $content .= '<option value="0">全部用户</option>';
    foreach ($allUsers as $u) {
        $selected = ($filterUser === (int)$u['id']) ? ' selected' : '';
        $content .= '<option value="' . (int)$u['id'] . '"' . $selected . '>' . htmlspecialchars($u['username']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div><label>状态筛选</label><select class="input" name="status">';
    $content .= '<option value="all"' . ($filterStatus === 'all' ? ' selected' : '') . '>全部</option>';
    $content .= '<option value="active"' . ($filterStatus === 'active' ? ' selected' : '') . '>正常</option>';
    $content .= '<option value="expired"' . ($filterStatus === 'expired' ? ' selected' : '') . '>已过期</option>';
    $content .= '<option value="deleted"' . ($filterStatus === 'deleted' ? ' selected' : '') . '>已删除</option>';
    $content .= '</select></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button" type="submit">筛选</button></div>';
    $content .= '</form>';

    $content .= '<form id="share-batch-form" method="post" action="' . base_path() . '/admin/share-batch" data-batch-form="share">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="table-actions">';
    $content .= '<label class="checkbox"><input type="checkbox" data-check-all="shares" form="share-batch-form"> 全选</label>';
    $content .= '<select class="input" name="action">';
    $content .= '<option value="soft_delete">批量软删除</option>';
    $content .= '<option value="restore">批量恢复</option>';
    $content .= '<option value="hard_delete">批量彻底删除</option>';
    $content .= '</select>';
    $content .= '<button class="button" type="submit" data-confirm="hard_delete">执行</button>';
    $content .= '</div>';
    $content .= '</form>';
    if (empty($shares)) {
        $content .= '<p class="muted" style="margin-top:12px">暂无分享记录。</p>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th><input type="checkbox" data-check-all="shares" form="share-batch-form"></th><th>标题</th><th>链接</th><th>类型</th><th>用户</th><th>密码</th><th>到期</th><th>访客上限</th><th>状态</th><th>评论邮件通知</th><th>大小</th><th>更新时间</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($shares as $share) {
            $type = $share['type'] === 'notebook' ? '笔记本' : '文档';
            $status = !empty($share['deleted_at'])
                ? '已删除'
                : (share_is_expired($share) ? '已过期' : '正常');
            $size = format_bytes((int)($share['size_bytes'] ?? 0));
            $url = share_url((string)$share['slug']);
            $hasPassword = !empty($share['password_hash']) ? '有密码' : '无密码';
            $expiresAt = !empty($share['expires_at']) ? date('Y-m-d H:i', (int)$share['expires_at']) : '永久';
            $visitorLimit = (int)($share['visitor_limit'] ?? 0);
            if ($visitorLimit > 0) {
                $visitorCount = share_visitor_count((int)$share['id']);
                $visitorLabel = $visitorCount . '/' . $visitorLimit;
            } else {
                $visitorLabel = '不限';
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="share_ids[]" value="' . (int)$share['id'] . '" data-check-item="shares" form="share-batch-form"></td>';
            $content .= '<td>' . htmlspecialchars($share['title']) . '</td>';
            $content .= '<td><a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a></td>';
            $content .= '<td>' . htmlspecialchars($type) . '</td>';
            $content .= '<td>' . htmlspecialchars($share['username'] ?? '') . '</td>';
            $content .= '<td>' . htmlspecialchars($hasPassword) . '</td>';
            $content .= '<td>' . htmlspecialchars($expiresAt) . '</td>';
            $content .= '<td>' . htmlspecialchars($visitorLabel) . '</td>';
            $content .= '<td>' . htmlspecialchars($status) . '</td>';
            $content .= '<td>' . (((int)($share['comment_notify'] ?? 0) === 1) ? '开启' : '关闭') . '</td>';
            $content .= '<td>' . htmlspecialchars($size) . '</td>';
            $content .= '<td>' . htmlspecialchars($share['updated_at']) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button" type="button" data-share-announce-edit="1"'
                . ' data-share-id="' . (int)$share['id'] . '"'
                . ' data-share-announce-mode="' . htmlspecialchars((string)($share['announcement_mode'] ?? 'none'), ENT_QUOTES) . '"'
                . ' data-share-announce-title="' . htmlspecialchars((string)($share['announcement_title'] ?? ''), ENT_QUOTES) . '"'
                . ' data-share-announce-content="' . htmlspecialchars((string)($share['announcement_content'] ?? ''), ENT_QUOTES) . '"'
                . ' data-share-announce-doc="' . htmlspecialchars((string)($share['announcement_doc_id'] ?? ''), ENT_QUOTES) . '">公告</button>';
            if ($share['deleted_at']) {
                $content .= '<form method="post" action="' . base_path() . '/admin/share-restore" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                $content .= '<button class="button" type="submit">恢复</button>';
                $content .= '</form>';
            } else {
                $content .= '<form method="post" action="' . base_path() . '/admin/share-delete" class="inline-form">';
                $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
                $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
                $content .= '<button class="button" type="submit">软删除</button>';
                $content .= '</form>';
            }
            $content .= '<form method="post" action="' . base_path() . '/admin/share-hard-delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="share_id" value="' . (int)$share['id'] . '">';
            $content .= '<button class="button danger" type="submit">彻底删除</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '';

    $content .= '<div class="pagination">';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('shares', ['share_page' => max(1, $sharePage - 1)]) . '">上一页</a>';
    $content .= '<div class="pagination-info">第 ' . $sharePage . ' / ' . $sharePages . ' 页，共 ' . $totalShares . ' 条分享</div>';
    $content .= '<a class="button ghost" href="' . build_admin_query_url('shares', ['share_page' => min($sharePages, $sharePage + 1)]) . '">下一页</a>';
    $content .= '<form method="get" action="' . base_path() . '/admin#shares" class="pagination-form">';
    $content .= render_hidden_inputs(array_merge($shareQuery, [
        'share_search' => $shareSearch,
        'user' => $filterUser,
        'status' => $filterStatus,
    ]));
    $content .= '<label>每页</label><select class="input" name="share_size">';
    foreach ([10, 50, 200, 1000] as $size) {
        $selected = $shareSize === $size ? ' selected' : '';
        $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
    }
    $content .= '</select>';
    $content .= '<label>页码</label><input class="input small" type="number" name="share_page" min="1" max="' . $sharePages . '" value="' . $sharePage . '">';
    $content .= '<button class="button" type="submit">跳转</button>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $cloudreveConfigs = cloudreve_configs_all();
    $content .= '<div class="card" id="netdisk"><h2>网盘配置</h2>';

    // ===================== Cloudreve 连接配置管理 =====================
    $content .= '<h3 style="margin-top:4px;margin-bottom:8px">Cloudreve 连接配置</h3>';
    if (empty($cloudreveConfigs)) {
        $content .= '<div class="notice">尚未添加任何 Cloudreve 连接。请先在下方添加一个连接，再到“分享绑定”里为指定分享开启网盘。</div>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th>名称</th><th>地址</th><th>服务账号</th><th>默认根目录</th><th>状态</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($cloudreveConfigs as $cfg) {
            $cfgName = htmlspecialchars((string)($cfg['name'] ?? ''));
            $cfgBase = htmlspecialchars((string)($cfg['base_url'] ?? ''));
            $cfgEmail = htmlspecialchars((string)($cfg['email'] ?? ''));
            $cfgRoot = htmlspecialchars((string)($cfg['default_root'] ?? 'cloudreve://my'));
            $cfgEnabled = (int)($cfg['enabled'] ?? 0) === 1;
            $cfgState = $cfgEnabled ? '启用' : '停用';
            $content .= '<tr>';
            $content .= '<td>' . $cfgName . '</td>';
            $content .= '<td>' . $cfgBase . '</td>';
            $content .= '<td>' . $cfgEmail . '</td>';
            $content .= '<td><code>' . $cfgRoot . '</code></td>';
            $content .= '<td>' . htmlspecialchars($cfgState) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/config/test" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="config_id" value="' . (int)$cfg['id'] . '">';
            $content .= '<button class="button" type="submit">测试连接</button>';
            $content .= '</form>';
            $content .= '<button class="button" type="button" data-cfg-edit data-cfg-id="' . (int)$cfg['id'] . '" data-cfg-name="' . $cfgName . '" data-cfg-base="' . $cfgBase . '" data-cfg-email="' . $cfgEmail . '" data-cfg-root="' . htmlspecialchars($cfgRoot) . '" data-cfg-enabled="' . ($cfgEnabled ? '1' : '0') . '">编辑</button>';
            $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/config/delete" class="inline-form" data-confirm-message="确定删除该 Cloudreve 连接配置？其绑定的分享网盘将失效。">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="config_id" value="' . (int)$cfg['id'] . '">';
            $content .= '<button class="button danger" type="submit">删除</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }

    // 新增 / 编辑 连接配置表单
    $editCfg = null;
    $editCfgId = (int)($_GET['edit_netdisk_cfg'] ?? 0);
    if ($editCfgId > 0) {
        $editCfg = cloudreve_config_get($editCfgId);
    }
    $cfgFormName = $editCfg ? (string)$editCfg['name'] : '';
    $cfgFormBase = $editCfg ? (string)$editCfg['base_url'] : '';
    $cfgFormEmail = $editCfg ? (string)$editCfg['email'] : '';
    $cfgFormRoot = $editCfg ? (string)$editCfg['default_root'] : 'cloudreve://my';
    $cfgFormEnabled = !$editCfg || (int)$editCfg['enabled'] === 1;
    $content .= '<button class="button ghost" type="button" data-netdisk-cfg-toggle style="margin-top:12px">' . ($editCfg ? '正在编辑连接' : '新增连接') . '</button>';
    $content .= '<div data-netdisk-cfg-form' . ($editCfg ? '' : ' hidden') . '>';
    $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/config/save" style="margin-top:12px" class="card-sub-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($editCfg) {
        $content .= '<input type="hidden" name="config_id" value="' . (int)$editCfg['id'] . '">';
        $content .= '<div class="muted" style="margin-bottom:8px">正在编辑配置「' . htmlspecialchars($cfgFormName) . '」（ID ' . (int)$editCfg['id'] . '）</div>';
    }
    $content .= '<div class="grid">';
    $content .= '<div><label>配置名称</label><input class="input" name="name" placeholder="例如：主 Cloudreve" required value="' . htmlspecialchars($cfgFormName) . '"></div>';
    $content .= '<div><label>Cloudreve 网盘地址</label><input class="input" name="base_url" placeholder="https://cloud.example.com" required value="' . htmlspecialchars($cfgFormBase) . '"></div>';
    $content .= '<div><label>服务账号邮箱</label><input class="input" name="email" placeholder="用于 API 登录" required value="' . htmlspecialchars($cfgFormEmail) . '"></div>';
    $content .= '<div><label>服务账号密码</label><input class="input" type="password" name="password" autocomplete="new-password" placeholder="' . ($editCfg ? '留空表示不修改密码' : '') . '"' . ($editCfg ? '' : ' required') . ' value=""></div>';
    $content .= '<div><label>默认分享根目录 URI</label><div class="netdisk-root-pick"><input class="input" name="default_root" id="netdisk-default-root" placeholder="cloudreve://my 或 cloudreve://my/目录" value="' . htmlspecialchars($cfgFormRoot) . '"><button class="button ghost" type="button" data-netdisk-pick-root>选择目录</button></div></div>';
    $content .= '</div>';
    // ===== 连接级全文索引配置（留空 = 使用全局默认） =====
    $cfgIndexVals = $editCfg ? [
        'index_ocr_enabled' => (string)($editCfg['index_ocr_enabled'] ?? ''),
        'index_ocr_concurrency' => (string)($editCfg['index_ocr_concurrency'] ?? ''),
        'index_ocr_dpi_start' => (string)($editCfg['index_ocr_dpi_start'] ?? ''),
        'index_ocr_dpi_max' => (string)($editCfg['index_ocr_dpi_max'] ?? ''),
        'index_ocr_quality_threshold' => (string)($editCfg['index_ocr_quality_threshold'] ?? ''),
        'index_ocr_max_mb' => (string)($editCfg['index_ocr_max_mb'] ?? ''),
        'index_ocr_low_max_mb' => (string)($editCfg['index_ocr_low_max_mb'] ?? ''),
        'index_refresh_ttl' => (string)($editCfg['index_refresh_ttl'] ?? ''),
        'index_skip_exts' => (string)($editCfg['index_skip_exts'] ?? ''),
    ] : [];
    // 连接级 OCR/索引配置已迁移至【全文索引】区的"连接 OCR 配置"独立表单，此处不再展示
    $content .= '<label style="margin-top:8px;display:inline-block"><input type="checkbox" name="enabled" value="1"' . ($cfgFormEnabled ? ' checked' : '') . '> 启用该连接</label>';
    $content .= '<div style="margin-top:12px">';
    $content .= '<button class="button primary" type="submit">' . ($editCfg ? '保存修改' : '新增连接') . '</button>';
    $content .= ' <button class="button" type="button" data-netdisk-cfg-test>测试连接</button>';
    if ($editCfg) {
        $content .= ' <a class="button ghost" href="' . build_admin_query_url('netdisk', ['edit_netdisk_cfg' => null]) . '">取消编辑</a>';
    }
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';

    // ===================== 分享绑定 =====================
    $content .= '<h3 style="margin-top:20px;margin-bottom:8px">分享绑定（为已分享的笔记开启网盘）</h3>';
    $netdiskSharesStmt = $pdo->prepare('SELECT id, type, title, slug, netdisk_enabled, netdisk_root, netdisk_permission, netdisk_config_id FROM shares WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 500');
    $netdiskSharesStmt->execute();
    $netdiskShares = $netdiskSharesStmt->fetchAll(PDO::FETCH_ASSOC);
    // 批量预取每个分享的文档数（fetchAll 自动关游标，避免渲染循环内 SELECT→UPDATE 撞 SQLite 升级锁）
    $netdiskDocCounts = [];
    $netdiskDocStmt = $pdo->query('SELECT share_id, COUNT(*) AS cnt FROM share_docs GROUP BY share_id');
    if ($netdiskDocStmt) {
        foreach ($netdiskDocStmt->fetchAll(PDO::FETCH_ASSOC) as $docCountRow) {
            $netdiskDocCounts[(int)$docCountRow['share_id']] = (int)$docCountRow['cnt'];
        }
    }
    if (empty($cloudreveConfigs)) {
        $content .= '<div class="notice">请先在上方添加至少一个 Cloudreve 连接配置。</div>';
    } elseif (empty($netdiskShares)) {
        $content .= '<p class="muted">暂无分享，先创建分享后再配置网盘。</p>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th>标题</th><th>链接</th><th>启用网盘</th><th>绑定连接</th><th>网盘标签</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($netdiskShares as $ns) {
            $nsTitle = htmlspecialchars((string)($ns['title'] ?? ''));
            $nsUrl = share_url((string)$ns['slug']);
            $nsRoot = htmlspecialchars((string)($ns['netdisk_root'] ?? ''));
            $nsEnabled = (int)($ns['netdisk_enabled'] ?? 0) === 1;
            $nsCfgId = (int)($ns['netdisk_config_id'] ?? 0);
            $content .= '<tr>';
            $content .= '<td>' . $nsTitle . '</td>';
            $content .= '<td><a href="' . htmlspecialchars($nsUrl) . '" target="_blank">' . htmlspecialchars($nsUrl) . '</a></td>';
            $content .= '<td><label class="checkbox"><input type="checkbox" form="netdisk-form" name="netdisk_enabled[' . (int)$ns['id'] . ']" value="1"' . ($nsEnabled ? ' checked' : '') . '> 开启</label></td>';
            $content .= '<td><select class="input" form="netdisk-form" name="netdisk_config_id[' . (int)$ns['id'] . ']">';
            foreach ($cloudreveConfigs as $cfgOpt) {
                $optSelected = ($nsCfgId === (int)$cfgOpt['id']) ? ' selected' : '';
                $optDisabled = (int)($cfgOpt['enabled'] ?? 0) !== 1 ? ' (停用)' : '';
                $content .= '<option value="' . (int)$cfgOpt['id'] . '"' . $optSelected . '>' . htmlspecialchars((string)$cfgOpt['name']) . $optDisabled . '</option>';
            }
            $content .= '</select></td>';
            $nsState = netdisk_tab_state($ns, $netdiskDocCounts);
            $nsStateHtml = $nsState['show']
                ? '<span style="color:#16a34a">可显示</span>'
                : '<span style="color:#c00">' . htmlspecialchars($nsState['reason']) . '</span>';
            $content .= '<td>' . $nsStateHtml . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button" type="button" data-netdisk-dl-manage data-share-id="' . (int)$ns['id'] . '" data-share-title="' . htmlspecialchars($nsTitle) . '" data-netdisk-config-id="' . (int)$nsCfgId . '">下载规则</button>';
            $content .= '<a class="button" href="' . htmlspecialchars($nsUrl) . '" target="_blank">打开分享</a>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
        $content .= '<form id="netdisk-form" method="post" action="' . base_path() . '/admin/netdisk" style="margin-top:12px">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button primary" type="submit">保存分享绑定</button>';
        $content .= '</form>';
    }
    // ===================== 文件预览 =====================
    $content .= '<h3 id="netdisk-preview" style="margin-top:24px;margin-bottom:8px">文件预览</h3>';
    $officeApps = netdisk_office_apps();
    $officeDefaultEngine = strtolower((string)get_setting('netdisk_office_engine', 'auto'));
    if (!in_array($officeDefaultEngine, ['auto', 'collabora', 'onlyoffice'], true)) { $officeDefaultEngine = 'auto'; }
    $officeExtMap = json_decode((string)get_setting('netdisk_office_ext_map', '{}'), true);
    if (!is_array($officeExtMap)) { $officeExtMap = []; }
    // 生成多扩展名每行一个的显示文本：doc,docx,xlsx:collabora
    $officeExtTextByEngine = [];
    foreach ($officeExtMap as $ek => $ev) {
        $officeExtTextByEngine[strtolower((string)$ev)][] = strtolower((string)$ek);
    }
    $officeExtMapText = '';
    foreach ($officeExtTextByEngine as $eng => $exts) {
        $officeExtMapText .= implode(',', array_unique($exts)) . ':' . $eng . "\n";
    }
    $officeExtMapText = trim($officeExtMapText);
    if (empty($officeApps)) {
        $content .= '<div class="notice">尚未添加 WOPI 应用（如 OnlyOffice / Collabora）。添加后，访客在网盘里即可只读预览 docx/xlsx/pptx/pdf 等文档（仅浏览权限，无法从中编辑保存）。</div>';
    }
    if (!empty($officeApps)) {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th>名称</th><th>引擎</th><th>地址</th><th>状态</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($officeApps as $oa) {
            $oaName = htmlspecialchars((string)($oa['name'] ?? ''));
            $oaEngine = netdisk_office_engine_label((string)($oa['engine'] ?? ''));
            $oaUrl = htmlspecialchars((string)($oa['base_url'] ?? ''));
            $oaActive = (int)($oa['enabled'] ?? 1) === 1;
            $oaState = $oaActive ? '启用' : '停用';
            $content .= '<tr>';
            $content .= '<td>' . $oaName . '</td>';
            $content .= '<td>' . htmlspecialchars($oaEngine) . '</td>';
            $content .= '<td>' . $oaUrl . '</td>';
            $content .= '<td>' . htmlspecialchars($oaState) . '</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/office/test" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="app_id" value="' . (int)$oa['id'] . '">';
            $content .= '<button class="button" type="submit">测试</button>';
            $content .= '</form>';
            $content .= '<button class="button" type="button" data-office-edit data-app-id="' . (int)$oa['id'] . '" data-app-name="' . $oaName . '" data-app-url="' . htmlspecialchars((string)($oa['base_url'] ?? '')) . '" data-app-enabled="' . ($oaActive ? '1' : '0') . '">编辑</button>';
            $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/office/delete" class="inline-form" data-confirm-message="确定删除该 WOPI 应用？">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="app_id" value="' . (int)$oa['id'] . '">';
            $content .= '<button class="button danger" type="submit">删除</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    // 编辑应用预填
    $editOfficeApp = null;
    $editOfficeAppId = (int)($_GET['edit_office_app'] ?? 0);
    if ($editOfficeAppId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM office_apps WHERE id = :id');
        $stmt->execute([':id' => $editOfficeAppId]);
        $editOfficeApp = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $oaFormName = $editOfficeApp ? (string)$editOfficeApp['name'] : '';
    $oaFormUrl = $editOfficeApp ? (string)$editOfficeApp['base_url'] : '';
    $oaFormEnabled = !$editOfficeApp || (int)$editOfficeApp['enabled'] === 1;
    $content .= '<button class="button ghost" type="button" data-netdisk-office-toggle style="margin-top:12px">' . ($editOfficeApp ? '正在编辑应用' : '添加应用') . '</button>';
    $content .= '<div data-netdisk-office-form' . ($editOfficeApp ? '' : ' hidden') . '>';
    $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/office/save" style="margin-top:8px" class="card-sub-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    if ($editOfficeApp) {
        $content .= '<input type="hidden" name="app_id" value="' . (int)$editOfficeApp['id'] . '">';
        $content .= '<div class="muted" style="margin-bottom:8px">正在编辑应用「' . htmlspecialchars($oaFormName) . '」（ID ' . (int)$editOfficeApp['id'] . '）。引擎类型由 /hosting/discovery 自动识别，保存时重新校验。</div>';
    } else {
        $content .= '<div class="muted" style="margin-bottom:8px">添加时会自动抓取 /hosting/discovery 校验并识别引擎类型（参考 <a href="https://docs.cloudreve.org/zh/usage/wopi" target="_blank" rel="noopener">Cloudreve WOPI 文档</a>）。</div>';
    }
    $content .= '<div class="grid">';
    $content .= '<div><label>应用名称</label><input class="input" name="app_name" placeholder="例如：OnlyOffice 主服务" required value="' . htmlspecialchars($oaFormName) . '"></div>';
    $content .= '<div style="grid-column:1/-1"><label>WOPI 地址（根地址，需支持 /hosting/discovery，如 https://office.example.com:443）</label><input class="input" name="app_base_url" placeholder="https://office.example.com:443" required value="' . htmlspecialchars($oaFormUrl) . '"></div>';
    $content .= '</div>';
    $content .= '<label style="margin-top:8px;display:inline-block"><input type="checkbox" name="app_enabled" value="1"' . ($oaFormEnabled ? ' checked' : '') . '> 启用该应用</label>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">' . ($editOfficeApp ? '保存修改' : '添加应用') . '</button>';
    if ($editOfficeApp) {
        $content .= ' <a class="button ghost" href="' . build_admin_query_url('netdisk', ['edit_office_app' => null]) . '">取消编辑</a>';
    }
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    // 默认引擎下拉：动态来自已添加应用（不再硬编码“自动”）
    $officeEngineOptions = [];
    foreach ($officeApps as $oa) {
        $eng = strtolower((string)($oa['engine'] ?? ''));
        if ($eng === '') {
            continue;
        }
        $officeEngineOptions[$eng] = netdisk_office_engine_label($eng);
    }
    if ($officeDefaultEngine === 'auto' || !isset($officeEngineOptions[$officeDefaultEngine])) {
        // 旧值 auto 或已失效的引擎：默认选中第一个已配置引擎
        $officeDefaultEngine = count($officeEngineOptions) > 0 ? (string)array_key_first($officeEngineOptions) : '';
    }
    $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/office/settings" style="margin-top:16px" class="card-sub-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>默认引擎</label><select class="input" name="app_default_engine">';
    foreach ($officeEngineOptions as $engVal => $engLabel) {
        $content .= '<option value="' . htmlspecialchars($engVal) . '"' . ($officeDefaultEngine === $engVal ? ' selected' : '') . '>' . htmlspecialchars($engLabel) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div style="grid-column:1/-1"><label>按扩展名指定引擎（每行一个，扩展名用逗号分隔，如 doc,docx,xlsx:collabora，留空表示用默认引擎）</label><textarea class="input code-input" name="app_ext_map" rows="4" placeholder="doc,docx,xlsx:collabora&#10;pdf:collabora">' . htmlspecialchars($officeExtMapText) . '</textarea></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button primary" type="submit">保存预览设置</button></div>';
    $content .= '</form>';
    $content .= '<h3 style="margin-top:20px;margin-bottom:8px">全文索引</h3>';
    $content .= '<div class="muted" style="margin-bottom:8px">无需 Cloudreve 管理员权限即可检索文件名与文档正文。为每个 Cloudreve 连接配置 OCR/索引参数（新增连接默认关闭 OCR）；绑定该连接的分享在重建时按其配置运行，并可独立重建/终止。重建在后台异步执行，可实时查看进度。</div>';
    if (empty($cloudreveConfigs)) {
        $content .= '<div class="notice">请先在上方添加至少一个 Cloudreve 连接配置。</div>';
    } else {
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:8px"><table class="table table-has-actions"><thead><tr><th>连接</th><th>OCR</th><th>DPI</th><th>并发</th><th>上限MB</th><th>低优MB</th><th>刷新秒</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($cloudreveConfigs as $icfg) {
            $icfgId = (int)$icfg['id'];
            $icfgName = htmlspecialchars((string)($icfg['name'] ?? ''));
            $icfgOcrOn = trim((string)($icfg['index_ocr_enabled'] ?? '')) === '' ? false : trim((string)$icfg['index_ocr_enabled']) !== '0';
            $icCfgId = $icfgId;
            $icDpiS = netdisk_index_ocr_dpi_start($icCfgId);
            $icDpiM = netdisk_index_ocr_dpi_max($icCfgId);
            $icConc = netdisk_index_ocr_concurrency($icCfgId);
            $icMax = netdisk_index_ocr_max_mb($icCfgId);
            $icLow = netdisk_index_ocr_low_max_mb($icCfgId);
            $icTtl = netdisk_index_refresh_ttl($icCfgId);
            $icQty = netdisk_index_ocr_quality_threshold($icCfgId);
            $icSkip = implode(',', array_keys(netdisk_index_skip_exts($icCfgId)));
            $icVal = function (string $k, string $default) use ($icfg): string {
                $v = trim((string)($icfg[$k] ?? ''));
                return $v === '' ? $default : $v;
            };
            $content .= '<tr>';
            $content .= '<td>' . $icfgName . '</td>';
            $content .= '<td>' . ($icfgOcrOn ? '<span style="color:#16a34a">开</span>' : '<span class="muted">关</span>') . '</td>';
            $content .= '<td>' . (int)$icDpiS . '→' . (int)$icDpiM . '</td>';
            $content .= '<td>' . (int)$icConc . '</td>';
            $content .= '<td>' . (int)$icMax . '</td>';
            $content .= '<td>' . (int)$icLow . '</td>';
            $content .= '<td>' . (int)$icTtl . '</td>';
            $content .= '<td class="actions">';
            $content .= '<button class="button" type="button" data-netdisk-icfg-toggle data-cfg-id="' . $icfgId . '">配置</button>';
            // 重建/终止二合一：初始显示"重建索引"，运行时报状态由前端根据 configs[].job_status 切换
            $content .= '<button class="button" type="button" data-netdisk-cfg-action data-config-id="' . $icfgId . '" data-config-name="' . $icfgName . '" data-running="0">重建索引</button>';
            // 0018: 强制重跑 OCR 勾选框（默认不勾；勾选后重建请求附带 force_ocr=1，忽略已有高质量结果）
            $content .= '<label class="checkbox" style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;font-size:0.78rem;white-space:nowrap" title="忽略已有高质量 OCR 结果，全部重新识别"><input type="checkbox" data-nd-force-ocr data-config-id="' . $icfgId . '"> 强制重跑 OCR（忽略已有高质量结果）</label>';
            $content .= '</td>';
            $content .= '</tr>';
            // 展开的配置编辑表单（默认隐藏）
            $content .= '<tr class="nd-icfg-edit-row" data-nd-icfg-edit="' . $icfgId . '" hidden><td colspan="8">';
            $content .= '<form method="post" action="' . base_path() . '/admin/netdisk/index-config/save" style="margin:8px 0" class="card-sub-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="config_id" value="' . $icfgId . '">';
            $content .= '<label class="checkbox" style="margin-bottom:6px;display:inline-block"><input type="checkbox" name="index_ocr_enabled" value="1" data-netdisk-index-ocr-toggle' . ($icfgOcrOn ? ' checked' : '') . '> 启用 OCR</label>';
            $content .= '<div class="muted" style="margin-bottom:6px;font-size:0.75rem">OCR 引擎：PaddleOCR（PP-OCRv6，通过 serving 接入）。DPI 为 PDF 页转图分辨率，PaddleOCR 一次识别即高质量，无需再按阈值升档重扫。</div>';
            $content .= '<div class="grid" data-netdisk-index-ocr-fields' . ($icfgOcrOn ? '' : ' hidden') . ' style="grid-template-columns:repeat(4,minmax(0,1fr));max-width:920px">';
            $icInputs = [
                ['index_ocr_dpi_start', 'PDF转图DPI', '200'],
                ['index_ocr_dpi_max', 'DPI上限', '600'],
                ['index_ocr_quality_threshold', '质量阈值', '0.3'],
                ['index_ocr_concurrency', 'OCR 并发数', '4'],
                ['index_ocr_max_mb', '正常上限MB', '10'],
                ['index_ocr_low_max_mb', '低优上限MB', '50'],
                ['index_refresh_ttl', '刷新间隔秒', '3600'],
                ['index_skip_exts', '跳过扩展名', 'zip,dwg,rar'],
            ];
            foreach ($icInputs as $icIn) {
                $fName = $icIn[0];
                $fVal = trim((string)($icfg[$fName] ?? ''));
                $fDefault = $icIn[2];
                $fDisp = $fVal === '' ? $fDefault : $fVal;
                $content .= '<div style="display:flex;flex-direction:column;gap:4px"><label>' . htmlspecialchars($icIn[1]) . '</label><input class="input" name="' . $fName . '" value="' . htmlspecialchars($fDisp) . '"></div>';
            }
            $content .= '</div>';
            $content .= '<div style="margin-top:8px"><button class="button primary" type="submit">保存该连接配置</button></div>';
            $content .= '</form>';
            $content .= '</td></tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '<div data-nd-reindex-status style="margin-top:14px"></div>';
    $content .= '</div>';

    $content .= '<div class="modal" data-share-announce-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">设置分享公告</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/share-announcement/update" data-share-announce-form>';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<input type="hidden" name="share_id" value="" data-announce-share-id>';
    $content .= '<div class="modal-body">';
    $content .= '<div class="muted">公告会显示在分享页顶部（仅分享内文档可见）。引用笔记模式的标题取笔记标题，渲染效果与正文一致。</div>';
    $content .= '<label>模式</label>';
    $content .= '<select class="input" name="announcement_mode" data-announce-mode>'
        . '<option value="none">不显示</option>'
        . '<option value="manual">手动填写</option>'
        . '<option value="doc">引用笔记</option>'
        . '</select>';
    $content .= '<div data-announce-fields="manual" hidden>';
    $content .= '<label>标题（可选，留空显示“公告”）</label><input class="input" name="announcement_title" placeholder="例如：更新公告" data-announce-title>';
    $content .= '<label>内容（支持 Markdown）</label><textarea class="input" name="announcement_content" rows="6" placeholder="支持与正文相同的 Markdown 语法" data-announce-content></textarea>';
    $content .= '</div>';
    $content .= '<div data-announce-fields="doc" hidden>';
    $content .= '<label>引用笔记</label>';
    $content .= '<select class="input" name="announcement_doc_id" data-announce-doc-select><option value="">请选择分享内的笔记</option></select>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button ghost" type="button" data-modal-close>取消</button>';
    $content .= '<button class="button primary" type="submit">保存</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $chunkTtlHours = $chunkTtlSeconds > 0 ? ($chunkTtlSeconds / 3600) : 2;
    $content .= '<div class="card" id="chunks"><h2>分片清理</h2>';
    $content .= '<div class="muted">仅显示超过 ' . htmlspecialchars(number_format($chunkTtlHours, 1)) . ' 小时未更新的分片目录。</div>';
    if (empty($staleChunks)) {
        $content .= '<p class="muted" style="margin-top:12px">暂无过期分片。</p>';
    } else {
        $content .= '<form method="post" action="' . base_path() . '/admin/chunk-clean" class="inline-form" style="margin-top:12px">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button danger" type="submit">清理全部过期分片</button>';
        $content .= '</form>';
        $content .= '<div class="table-scroll table-scroll-has-actions" style="margin-top:12px"><table class="table table-has-actions"><thead><tr><th>目录</th><th>最后更新</th><th>已过期</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($staleChunks as $chunk) {
            $chunkId = (string)$chunk['id'];
            $mtime = (int)$chunk['mtime'];
            $ageHours = max(0, $chunk['age'] / 3600);
            $content .= '<tr>';
            $content .= '<td><span class="muted">' . htmlspecialchars($chunkId) . '</span></td>';
            $content .= '<td>' . htmlspecialchars(date('Y-m-d H:i', $mtime)) . '</td>';
            $content .= '<td>' . htmlspecialchars(number_format($ageHours, 1)) . ' 小时</td>';
            $content .= '<td class="actions">';
            $content .= '<form method="post" action="' . base_path() . '/admin/chunk-delete" class="inline-form">';
            $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
            $content .= '<input type="hidden" name="chunk_id" value="' . htmlspecialchars($chunkId) . '">';
            $content .= '<button class="button danger" type="submit">删除</button>';
            $content .= '</form>';
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '</div>';

    $content .= '<div class="card" id="scan"><h2>违禁词扫描</h2>';
    if ($bannedWordsRaw === '') {
        $content .= '<div class="notice">请先在“站点设置”里配置违禁词。</div>';
    }
    $content .= '<form method="post" action="' . base_path() . '/admin/scan" data-scan-form="1">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<button class="button" type="submit">开始扫描</button>';
    $content .= '</form>';
    $content .= '<div class="scan-progress" data-scan-progress' . $scanProgressHidden . '>';
    $content .= '<div class="scan-progress__bar"><span data-scan-bar' . $scanBarStyle . '></span></div>';
    $content .= '<div class="scan-progress__status" data-scan-status>' . htmlspecialchars($scanStatusLabel) . '</div>';
    $content .= '<div class="scan-log" data-scan-log>' . $scanLogHtml . '</div>';
    $content .= '</div>';
    if (!empty($scanResults)) {
        $shareIds = [];
        $userIds = [];
        foreach ($scanResults as $hit) {
            if (!isset($hit['share_id'], $hit['user_id'])) {
                continue;
            }
            $shareIds[(int)$hit['share_id']] = true;
            $userIds[(int)$hit['user_id']] = true;
        }
        $scanTimeLabel = $scanAt ? date('Y-m-d H:i', $scanAt) : '未知';
        $content .= '<div class="muted" style="margin-top:10px">最近扫描：' . htmlspecialchars($scanTimeLabel) . '，命中 ' . count($scanResults) . ' 条记录，涉及 ' . count($shareIds) . ' 个分享 / ' . count($userIds) . ' 个账号。</div>';
        $content .= '<div class="scan-actions" style="margin-top:10px">';
        $content .= '<form method="post" action="' . base_path() . '/admin/scan/delete" class="inline-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button danger" type="submit">一键删除违规分享</button>';
        $content .= '</form>';
        $content .= '<form method="post" action="' . base_path() . '/admin/scan/disable" class="inline-form">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<button class="button" type="submit">一键停用违规账号</button>';
        $content .= '</form>';
        $content .= '</div>';

        $content .= '<form id="scan-batch-form" method="post" action="' . base_path() . '/admin/scan/batch">';
        $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
        $content .= '<div class="table-actions">';
        $content .= '<label class="checkbox"><input type="checkbox" data-check-all="scan" form="scan-batch-form"> 全选</label>';
        $content .= '<select class="input" name="action">';
        $content .= '<option value="delete">批量删除分享</option>';
        $content .= '<option value="disable">批量停用账号</option>';
        $content .= '</select>';
        $content .= '<button class="button" type="submit">执行</button>';
        $content .= '</div>';
        $content .= '</form>';
        $content .= '<div class="table-scroll" style="margin-top:12px"><table class="table scan-table"><thead><tr><th><input type="checkbox" data-check-all="scan" form="scan-batch-form"></th><th>分享</th><th>文档/评论</th><th>用户</th><th>违禁词</th><th>预览</th><th>链接</th></tr></thead><tbody>';
        foreach ($scanPageResults as $hit) {
            $itemType = (string)($hit['item_type'] ?? 'doc');
            $shareTitleRaw = (string)($hit['share_title'] ?? '');
            $shareTitle = htmlspecialchars($shareTitleRaw);
            $docTitleRaw = (string)($hit['doc_title'] ?? $hit['doc_id'] ?? '');
            $docTitle = htmlspecialchars($docTitleRaw);
            $hPath = trim((string)($hit['hpath'] ?? ''), '/');
            $userName = htmlspecialchars((string)($hit['username'] ?? ''));
            $word = htmlspecialchars((string)($hit['word'] ?? ''));
            $snippet = htmlspecialchars((string)($hit['snippet'] ?? ''));
            $shareUrl = $hit['slug'] ? share_url((string)$hit['slug']) : '';
            $commentId = (int)($hit['comment_id'] ?? 0);
            $commentEmail = trim((string)($hit['comment_email'] ?? ''));
            $commentCreatedAt = (string)($hit['comment_created_at'] ?? '');
            $commentContent = (string)($hit['comment_content'] ?? '');
            $value = (int)($hit['share_id'] ?? 0) . '|' . (int)($hit['user_id'] ?? 0);
            $slug = (string)($hit['slug'] ?? '');
            $detailTitleHtml = htmlspecialchars($docTitleRaw);
            $detailMeta = $hPath;
            $docId = (string)($hit['doc_id'] ?? '');
            if ($itemType === 'doc') {
                $docLabel = trim($docTitleRaw) !== '' ? $docTitleRaw : $docId;
                $docLabel = $docLabel !== '' ? '文档：' . $docLabel : '文档';
                $detailTitleHtml = htmlspecialchars($docLabel);
                $detailMeta = $hPath !== '' ? '路径：' . $hPath : '';
                $docUrl = $shareUrl;
                if ($slug !== '' && $docId !== '') {
                    $docUrl = base_url() . build_share_redirect_path($slug, $docId, '');
                }
                if ($docUrl !== '') {
                    $detailTitleHtml = '<a class="scan-comment-link" href="' . htmlspecialchars($docUrl) . '" target="_blank">' . htmlspecialchars($docLabel) . '</a>';
                    $shareUrl = $docUrl;
                }
            }
            if ($itemType === 'comment') {
                $metaParts = [];
                if ($commentEmail !== '') {
                    $metaParts[] = '邮箱：' . $commentEmail;
                }
                if ($commentCreatedAt !== '') {
                    $metaParts[] = '时间：' . format_share_datetime($commentCreatedAt);
                }
                $detailMeta = implode(' / ', $metaParts);
                if ($shareUrl !== '' && $commentId > 0) {
                    $shareUrl .= '#comment-' . $commentId;
                }
                if ($commentId > 0) {
                    $detailTitleHtml = '<button type="button" class="scan-comment-link" data-admin-comment-edit="1"'
                        . ' data-admin-comment-id="' . $commentId . '"'
                        . ' data-admin-comment-email="' . htmlspecialchars($commentEmail, ENT_QUOTES) . '"'
                        . ' data-admin-comment-created="' . htmlspecialchars(format_share_datetime($commentCreatedAt), ENT_QUOTES) . '"'
                        . ' data-admin-comment-share="' . htmlspecialchars($shareTitleRaw, ENT_QUOTES) . '"'
                        . ' data-admin-comment-content="' . htmlspecialchars($commentContent, ENT_QUOTES) . '"'
                        . '>评论#' . $commentId . '</button>';
                } else {
                    $detailTitleHtml = '评论';
                }
            }
            $content .= '<tr>';
            $content .= '<td><input type="checkbox" name="scan_ids[]" value="' . htmlspecialchars($value) . '" data-check-item="scan" form="scan-batch-form"></td>';
            $content .= '<td>' . $shareTitle . '</td>';
            $content .= '<td>' . $detailTitleHtml;
            if ($detailMeta !== '') {
                $content .= '<div class="muted">' . htmlspecialchars($detailMeta) . '</div>';
            }
            $content .= '</td>';
            $content .= '<td>' . $userName . '</td>';
            $content .= '<td>' . $word . '</td>';
            $content .= '<td><div class="scan-snippet">' . $snippet . '</div></td>';
            $content .= '<td>';
            if ($shareUrl !== '') {
                $content .= '<a href="' . htmlspecialchars($shareUrl) . '" target="_blank">打开</a>';
            } else {
                $content .= '-';
            }
            $content .= '</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table></div>';

        $content .= '<div class="pagination">';
        $content .= '<a class="button ghost" href="' . build_admin_query_url('scan', ['scan_page' => max(1, $scanPage - 1), 'scan_keep' => $scanKeepParam]) . '">上一页</a>';
        $content .= '<div class="pagination-info">第 ' . $scanPage . ' / ' . $scanPages . ' 页，共 ' . $scanTotal . ' 条记录</div>';
        $content .= '<a class="button ghost" href="' . build_admin_query_url('scan', ['scan_page' => min($scanPages, $scanPage + 1), 'scan_keep' => $scanKeepParam]) . '">下一页</a>';
        $content .= '<form method="get" action="' . base_path() . '/admin#scan" class="pagination-form">';
        $content .= render_hidden_inputs($scanQuery);
        $content .= '<label>每页</label><select class="input" name="scan_size">';
        foreach ([10, 50, 200, 1000] as $size) {
            $selected = $scanSize === $size ? ' selected' : '';
            $content .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }
        $content .= '</select>';
        $content .= '<label>页码</label><input class="input small" type="number" name="scan_page" min="1" max="' . $scanPages . '" value="' . $scanPage . '">';
        $content .= '<button class="button" type="submit">跳转</button>';
        $content .= '</form>';
        $content .= '</div>';
    } else {
        $content .= '<p class="muted" style="margin-top:12px">暂无扫描结果。</p>';
    }
    $content .= '</div>';

    $content .= '<div class="card danger-zone"><h2>危险操作</h2>';
    $content .= '<p class="muted">删除所有数据将清空用户、分享与公告，仅保留初始管理员。</p>';
    $content .= '<form method="post" action="' . base_path() . '/admin/reset-data">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>输入“确认删除”继续</label><input class="input" name="confirm_phrase" placeholder="确认删除" required></div>';
    $content .= '</div>';
    $content .= '<div style="margin-top:12px"><button class="button danger" type="submit">删除所有数据</button></div>';
    $content .= '</form></div>';
$content .= '<div class="modal admin-comment-modal" data-admin-comment-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">编辑评论</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/comment/edit" data-admin-comment-form>';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<input type="hidden" name="comment_id" value="" data-admin-comment-id>';
    $content .= '<div class="modal-body">';
    $content .= '<div class="muted" data-admin-comment-note hidden></div>';
    $content .= '<textarea class="input" name="content" rows="6" placeholder="请输入评论内容" data-admin-comment-content required></textarea>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button" type="button" data-modal-close>取消</button>';
    $content .= '<button class="button primary" type="submit">保存修改</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="modal" data-user-modal hidden>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">编辑用户</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/user-update" class="modal-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<input type="hidden" name="user_id" value="" data-user-field="id">';
    $content .= '<div class="grid">';
    $content .= '<div><label>用户名</label><input class="input" name="username" data-user-field="username" required></div>';
    $content .= '<div><label>邮箱</label><input class="input" name="email" data-user-field="email"></div>';
    $content .= '<div><label>角色</label><select class="input" name="role" data-user-field="role"><option value="user">普通用户</option><option value="admin">管理员</option></select></div>';
    $content .= '<div><label>状态</label><select class="input" name="disabled" data-user-field="disabled"><option value="0">正常</option><option value="1">禁用</option></select></div>';
    $content .= '<div><label>存储上限 (MB)</label><input class="input" name="limit_mb" type="number" min="0" data-user-field="limit"></div>';
    $content .= '<div><label>新密码（留空不修改）</label><input class="input" name="password" type="password" placeholder="********"></div>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button ghost" type="button" data-modal-close>取消</button>';
    $content .= '<button class="button primary" type="submit">保存</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';
    $createModalHidden = $createOpen ? '' : ' hidden';
    $roleAdminSelected = $createRole === 'admin' ? ' selected' : '';
    $roleUserSelected = $createRole !== 'admin' ? ' selected' : '';
    $disabledSelected = $createDisabled === '1' ? ' selected' : '';
    $activeSelected = $createDisabled === '1' ? '' : ' selected';
    $content .= '<div class="modal" data-user-create-modal' . $createModalHidden . '>';
    $content .= '<div class="modal-backdrop" data-modal-close></div>';
    $content .= '<div class="modal-card">';
    $content .= '<div class="modal-header">添加账号</div>';
    $content .= '<form method="post" action="' . base_path() . '/admin/user-create" class="modal-form">';
    $content .= '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
    $content .= '<div class="grid">';
    $content .= '<div><label>用户名</label><input class="input" name="username" value="' . htmlspecialchars($createUsername) . '" required></div>';
    $content .= '<div><label>邮箱</label><input class="input" name="email" value="' . htmlspecialchars($createEmail) . '"></div>';
    $content .= '<div><label>角色</label><select class="input" name="role"><option value="user"' . $roleUserSelected . '>普通用户</option><option value="admin"' . $roleAdminSelected . '>管理员</option></select></div>';
    $content .= '<div><label>状态</label><select class="input" name="disabled"><option value="0"' . $activeSelected . '>正常</option><option value="1"' . $disabledSelected . '>禁用</option></select></div>';
    $content .= '<div><label>存储上限 (MB)</label><input class="input" name="limit_mb" type="number" min="0" value="' . htmlspecialchars($createLimitMb) . '"></div>';
    $content .= '<div><label>密码</label><input class="input" name="password" type="password" value="' . htmlspecialchars($createPassword) . '" required></div>';
    $content .= '</div>';
    $content .= '<div class="modal-actions">';
    $content .= '<button class="button ghost" type="button" data-modal-close>取消</button>';
    $content .= '<button class="button primary" type="submit">添加</button>';
    $content .= '</div>';
    $content .= '</form>';
    $content .= '</div>';
    $content .= '</div>';
    $titleHtml = build_topbar_title('后台', $admin);
    render_page('后台', $content, $admin, '', ['layout' => 'app', 'nav' => 'admin-settings', 'title_html' => $titleHtml]);
}

if ($path === '/admin/settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $allowRegistration = isset($_POST['allow_registration']) ? '1' : '0';
    $captchaEnabled = isset($_POST['captcha_enabled']) ? '1' : '0';
    $emailVerifyEnabled = isset($_POST['email_verification_enabled']) ? '1' : '0';
    $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
    $defaultLimitMb = max(0, (int)($_POST['default_storage_limit_mb'] ?? 0));
    $emailFrom = trim((string)($_POST['email_from'] ?? ''));
    $emailFromName = trim((string)($_POST['email_from_name'] ?? ''));
    $emailSubject = trim((string)($_POST['email_subject'] ?? ''));
    $emailResetSubject = trim((string)($_POST['email_reset_subject'] ?? ''));
    $siteIcp = trim((string)($_POST['site_icp'] ?? ''));
    $siteContactEmail = trim((string)($_POST['site_contact_email'] ?? ''));
    $siteBaseUrl = trim((string)($_POST['site_base_url'] ?? ''));
    $siteHeadHtml = trim((string)($_POST['site_head_html'] ?? ''));
    $siteTips = str_replace(["\r\n", "\r"], "\n", (string)($_POST['site_tips'] ?? ''));
    $siteCustomCssEnabled = isset($_POST['site_custom_css_enabled']) ? '1' : '0';
    $siteCustomJsEnabled = isset($_POST['site_custom_js_enabled']) ? '1' : '0';
    $siteCustomCss = str_replace(["\r\n", "\r"], "\n", (string)($_POST['site_custom_css'] ?? ''));
    $siteCustomJs = str_replace(["\r\n", "\r"], "\n", (string)($_POST['site_custom_js'] ?? ''));
    $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
    $smtpPort = trim((string)($_POST['smtp_port'] ?? ''));
    $smtpSecure = trim((string)($_POST['smtp_secure'] ?? ''));
    $smtpUser = trim((string)($_POST['smtp_user'] ?? ''));
    $smtpPass = trim((string)($_POST['smtp_pass'] ?? ''));
    $bannedWords = trim((string)($_POST['banned_words'] ?? ''));
    if ($bannedWords !== '') {
        $bannedWords = str_replace(["\r\n", "\n", "\r"], '|', $bannedWords);
    }
    $customCodeMaxBytes = 200000;
    if (strlen($siteCustomCss) > $customCodeMaxBytes || strlen($siteCustomJs) > $customCodeMaxBytes) {
        flash('error', '自定义 CSS / JS 过长，单项最多 200KB。');
        redirect('/admin#settings');
    }
    if (strlen($siteTips) > 200000) {
        flash('error', '使用技巧内容过长，最多 200KB。');
        redirect('/admin#settings');
    }
    set_setting('allow_registration', $allowRegistration);
    set_setting('captcha_enabled', $captchaEnabled);
    set_setting('email_verification_enabled', $emailVerifyEnabled);
    set_setting('smtp_enabled', $smtpEnabled);
    set_setting('default_storage_limit_bytes', (string)bytes_from_mb($defaultLimitMb));
    set_setting('email_from', $emailFrom);
    set_setting('email_from_name', $emailFromName);
    set_setting('email_subject', $emailSubject);
    set_setting('email_reset_subject', $emailResetSubject);
    set_setting('site_icp', $siteIcp);
    set_setting('site_contact_email', $siteContactEmail);
    set_setting('site_base_url', $siteBaseUrl);
    set_setting('site_head_html', $siteHeadHtml);
    set_setting('site_tips', $siteTips);
    set_setting('site_custom_css_enabled', $siteCustomCssEnabled);
    set_setting('site_custom_css', $siteCustomCss);
    set_setting('site_custom_js_enabled', $siteCustomJsEnabled);
    set_setting('site_custom_js', $siteCustomJs);
    set_setting('smtp_host', $smtpHost);
    set_setting('smtp_port', $smtpPort !== '' ? $smtpPort : '587');
    set_setting('smtp_secure', $smtpSecure !== '' ? $smtpSecure : 'tls');
    set_setting('smtp_user', $smtpUser);
    set_setting('smtp_pass', $smtpPass);
    set_setting('banned_words', $bannedWords);
    flash('info', '站点设置已更新');
    redirect('/admin#settings');
}

if ($path === '/admin/announcement/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;
    if ($title === '' || $content === '') {
        flash('error', '公告标题和内容不能为空');
        redirect('/admin#announcements');
    }
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO announcements (title, content, active, created_by, created_at)
        VALUES (:title, :content, :active, :created_by, :created_at)');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':active' => $active,
        ':created_by' => $admin['id'],
        ':created_at' => now(),
    ]);
    flash('info', '公告已发布');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;
    if ($id <= 0 || $title === '' || $content === '') {
        flash('error', '公告标题和内容不能为空');
        redirect('/admin#announcements');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE announcements SET title = :title, content = :content, active = :active WHERE id = :id');
    $stmt->execute([
        ':title' => $title,
        ':content' => $content,
        ':active' => $active,
        ':id' => $id,
    ]);
    flash('info', '公告已更新');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE announcements SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash('info', '公告状态已更新');
    redirect('/admin#announcements');
}

if ($path === '/admin/announcement/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $id = (int)($_POST['announcement_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash('info', '公告已删除');
    redirect('/admin#announcements');
}

if ($path === '/admin/smtp-test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $email = trim((string)($_POST['test_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '请输入有效的测试邮箱');
        redirect('/admin#settings');
    }
    if (!smtp_enabled()) {
        flash('error', '请先启用 SMTP');
        redirect('/admin#settings');
    }
    $sent = send_mail($email, 'SMTP 测试邮件', '这是一封 SMTP 配置测试邮件。');
    if ($sent) {
        flash('info', '测试邮件发送成功');
    } else {
        $detail = trim((string)($GLOBALS['smtp_last_error'] ?? ''));
        $message = $detail !== '' ? '测试邮件发送失败：' . $detail : '测试邮件发送失败';
        flash('error', $message);
    }
    redirect('/admin#settings');
}

if ($path === '/admin/user-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $ids = $_POST['user_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids)) {
        flash('error', '请先选择用户');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = :disabled WHERE id = :id AND role != "admin"');
    $updated = 0;
    $deleted = 0;
    foreach ($ids as $userId) {
        if ($userId === (int)$admin['id']) {
            continue;
        }
        if ($action === 'delete') {
            if (delete_user_account($userId)) {
                $deleted++;
            }
            continue;
        }
        if ($action === 'disable') {
            $stmt->execute([':disabled' => 1, ':id' => $userId]);
            $updated++;
        } elseif ($action === 'enable') {
            $stmt->execute([':disabled' => 0, ':id' => $userId]);
            $updated++;
        }
    }
    if ($action === 'delete') {
        flash('info', '已删除 ' . $deleted . ' 个用户');
    } else {
        flash('info', '已处理 ' . $updated . ' 个用户');
    }
    redirect('/admin#users');
}

if ($path === '/admin/user-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0 || $userId === (int)$admin['id']) {
        flash('error', '无法删除该用户');
        redirect('/admin#users');
    }
    if (!delete_user_account($userId)) {
        flash('error', '删除用户失败');
        redirect('/admin#users');
    }
    flash('info', '用户已删除');
    redirect('/admin#users');
}

if ($path === '/admin/user-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $disabled = (int)($_POST['disabled'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $password = (string)($_POST['password'] ?? '');
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }
    $createForm = [
        'open' => 1,
        'username' => $username,
        'email' => $email,
        'role' => $role,
        'disabled' => (string)($disabled ? 1 : 0),
        'limit_mb' => (string)$limitMb,
        'password' => $password,
    ];
    if ($username === '' || $password === '') {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', '请输入用户名和密码');
        redirect('/admin#users');
    }
    if (strlen($password) < 6) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', '密码至少 6 位');
        redirect('/admin#users');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', '邮箱格式不正确');
        redirect('/admin#users');
    }
    $pdo = db();
    if ($email !== '') {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if (pdo_fetch_exists($checkEmail)) {
            $_SESSION['user_create_form'] = $createForm;
            flash('error', '邮箱已被其他账号使用');
            redirect('/admin#users');
        }
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $check->execute([':username' => $username]);
    if (pdo_fetch_exists($check)) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', '用户名已存在');
        redirect('/admin#users');
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $emailVerified = $email !== '' ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, api_key_hash, api_key_prefix, api_key_last4, disabled, storage_limit_bytes, storage_used_bytes, must_change_password, email_verified, created_at, updated_at)
        VALUES (:username, :email, :password_hash, :role, :api_key_hash, :api_key_prefix, :api_key_last4, :disabled, :storage_limit_bytes, :storage_used_bytes, :must_change_password, :email_verified, :created_at, :updated_at)');
    try {
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':api_key_hash' => null,
            ':api_key_prefix' => null,
            ':api_key_last4' => null,
            ':disabled' => $disabled ? 1 : 0,
            ':storage_limit_bytes' => bytes_from_mb($limitMb),
            ':storage_used_bytes' => 0,
            ':must_change_password' => 0,
            ':email_verified' => $emailVerified,
            ':created_at' => now(),
            ':updated_at' => now(),
        ]);
    } catch (PDOException $e) {
        $_SESSION['user_create_form'] = $createForm;
        flash('error', '账号创建失败');
        redirect('/admin#users');
    }
    unset($_SESSION['user_create_form']);
    flash('info', '账号已添加');
    redirect('/admin#users');
}

if ($path === '/admin/user-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = (string)($_POST['role'] ?? 'user');
    $disabled = (int)($_POST['disabled'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $password = (string)($_POST['password'] ?? '');
    if ($userId <= 0 || $username === '') {
        flash('error', '请输入有效的用户名');
        redirect('/admin#users');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确');
        redirect('/admin#users');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        $role = 'user';
    }
    if ($userId === (int)$admin['id']) {
        $role = 'admin';
        $disabled = 0;
    }
    $pdo = db();
    if ($email !== '') {
        $checkEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
        $checkEmail->execute([':email' => $email, ':id' => $userId]);
        if (pdo_fetch_exists($checkEmail)) {
            flash('error', '邮箱已被其他账号使用');
            redirect('/admin#users');
        }
    }
    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id');
    $check->execute([':username' => $username, ':id' => $userId]);
    if (pdo_fetch_exists($check)) {
        flash('error', '用户名已存在');
        redirect('/admin#users');
    }
    $stmt = $pdo->prepare('UPDATE users SET username = :username, email = :email, role = :role, disabled = :disabled, storage_limit_bytes = :limit, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':role' => $role,
        ':disabled' => $disabled ? 1 : 0,
        ':limit' => bytes_from_mb($limitMb),
        ':updated_at' => now(),
        ':id' => $userId,
    ]);
    if ($password !== '') {
        $pwd = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
        $pwd->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':updated_at' => now(),
            ':id' => $userId,
        ]);
    }
    flash('info', '用户信息已更新');
    redirect('/admin#users');
}

if ($path === '/admin/netdisk/config/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $configId = (int)($_POST['config_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $viewerId = '';
    $defaultRoot = netdisk_normalize_root((string)($_POST['default_root'] ?? 'cloudreve://my'));
    $officeEngine = trim((string)($_POST['office_engine'] ?? 'auto'));
    if (!in_array($officeEngine, ['auto', 'collabora', 'onlyoffice'], true)) {
        $officeEngine = 'auto';
    }
    $officeExtMapRaw = trim((string)($_POST['office_ext_map'] ?? ''));
    $officeExtMapRaw = trim((string)($_POST["office_ext_map"] ?? ""));
    $officeExtMap = "{}";
    if ($officeExtMapRaw !== "") {
        $decoded = json_decode($officeExtMapRaw, true);
        if (!is_array($decoded)) {
            $decoded = [];
            foreach (preg_split("/\r\n|\n|\r/", $officeExtMapRaw) as $line) {
                $line = trim((string)$line);
                if ($line === "") continue;
                $parts = explode(":", $line, 2);
                if (count($parts) === 2) {
                    $ek = strtolower(trim($parts[0]));
                    $ev = strtolower(trim($parts[1]));
                    if ($ek !== "" && in_array($ev, ["collabora", "onlyoffice"], true)) $decoded[$ek] = $ev;
                }
            }
        }
        if (is_array($decoded)) {
            $officeExtMap = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }
    $officeCollaboraUrl = rtrim(trim((string)($_POST['office_collabora_url'] ?? '')), '/');
    $officeOnlyofficeUrl = rtrim(trim((string)($_POST['office_onlyoffice_url'] ?? '')), '/');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    // 连接级全文索引配置：编辑连接时不覆盖（OCR 配置已迁移至【全文索引】区的独立表单，
    // 由 index-config/save 管理）；新增连接时初始化为"OCR 关闭 + 其余留空"（getter 用硬编码默认）。
    $indexCfgFields = [];
    if ($configId <= 0) {
        $indexCfgFields = [
            'index_ocr_enabled' => '0',
            'index_ocr_concurrency' => '',
            'index_ocr_dpi_start' => '',
            'index_ocr_dpi_max' => '',
            'index_ocr_quality_threshold' => '',
            'index_ocr_max_mb' => '',
            'index_ocr_low_max_mb' => '',
            'index_refresh_ttl' => '',
            'index_skip_exts' => '',
        ];
    }
    if ($name === '' || $baseUrl === '' || $email === '') {
        flash('error', '配置名称、网盘地址与服务账号邮箱不能为空');
        redirect('/admin#netdisk');
    }
    $pdo = db();
    if ($configId > 0) {
        $existing = cloudreve_config_get($configId);
        if (!$existing) {
            flash('error', '配置不存在');
            redirect('/admin#netdisk');
        }
        if ($password === '') {
            $password = (string)$existing['password'];
        } else {
            $password = netdisk_encrypt_secret($password);
            if ($password === '') {
                flash('error', '密码加密失败：请检查 storage 目录是否可写');
                redirect('/admin#netdisk');
            }
        }
        $stmt = $pdo->prepare('UPDATE cloudreve_configs SET name=:name, base_url=:base_url, email=:email, password=:password, viewer_id=:viewer_id, default_root=:default_root, office_engine=:office_engine, office_ext_map=:office_ext_map, office_collabora_url=:office_collabora_url, office_onlyoffice_url=:office_onlyoffice_url, enabled=:enabled, ' . netdisk_index_cfg_sql_assign($indexCfgFields) . ' updated_at=:updated_at WHERE id=:id');
        $stmt->execute(array_merge([
            ':name' => $name,
            ':base_url' => $baseUrl,
            ':email' => $email,
            ':password' => $password,
            ':viewer_id' => $viewerId,
            ':default_root' => $defaultRoot,
            ':office_engine' => $officeEngine,
            ':office_ext_map' => $officeExtMap,
            ':office_collabora_url' => $officeCollaboraUrl,
            ':office_onlyoffice_url' => $officeOnlyofficeUrl,
            ':enabled' => $enabled,
            ':updated_at' => now(),
            ':id' => $configId,
        ], netdisk_index_cfg_params($indexCfgFields)));
        // 清空该连接的 token 缓存，下次自动重新登录
        set_setting(cloudreve_token_key($configId, 'access'), '');
        set_setting(cloudreve_token_key($configId, 'refresh'), '');
        set_setting(cloudreve_token_key($configId, 'expires'), '0');
        flash('info', '连接配置已更新');
    } else {
        if ($password === '') {
            flash('error', '新增连接时必须填写服务账号密码');
            redirect('/admin#netdisk');
        }
        $password = netdisk_encrypt_secret($password);
        if ($password === '') {
            flash('error', '密码加密失败：请检查 storage 目录是否可写');
            redirect('/admin#netdisk');
        }
        $stmt = $pdo->prepare('INSERT INTO cloudreve_configs (name, base_url, email, password, viewer_id, default_root, office_engine, office_ext_map, office_collabora_url, office_onlyoffice_url, enabled, ' . implode(', ', array_keys($indexCfgFields)) . ', created_at, updated_at) VALUES (:name, :base_url, :email, :password, :viewer_id, :default_root, :office_engine, :office_ext_map, :office_collabora_url, :office_onlyoffice_url, :enabled, ' . implode(', ', array_map(fn($c) => ':' . $c, array_keys($indexCfgFields))) . ', :created_at, :updated_at)');
        $stmt->execute(array_merge([
            ':name' => $name,
            ':base_url' => $baseUrl,
            ':email' => $email,
            ':password' => $password,
            ':viewer_id' => $viewerId,
            ':default_root' => $defaultRoot,
            ':office_engine' => $officeEngine,
            ':office_ext_map' => $officeExtMap,
            ':office_collabora_url' => $officeCollaboraUrl,
            ':office_onlyoffice_url' => $officeOnlyofficeUrl,
            ':enabled' => $enabled,
            ':created_at' => now(),
            ':updated_at' => now(),
        ], netdisk_index_cfg_params($indexCfgFields)));
        flash('info', '连接配置已新增');
    }
    redirect('/admin#netdisk');
}

if ($path === '/admin/netdisk/config/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $configId = (int)($_POST['config_id'] ?? 0);
    if ($configId > 0) {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM cloudreve_configs WHERE id = :id');
        $stmt->execute([':id' => $configId]);
        $upd = $pdo->prepare('UPDATE shares SET netdisk_config_id = 0 WHERE netdisk_config_id = :id');
        $upd->execute([':id' => $configId]);
        flash('info', '连接配置已删除');
    }
    redirect('/admin#netdisk');
}

if ($path === '/admin/netdisk/config/test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $configId = (int)($_POST['config_id'] ?? 0);
    $cfg = null;
    if ($configId > 0) {
        $cfg = cloudreve_config_get($configId);
    }
    if (!$cfg) {
        // 新增连接测试：用表单实时填写的连接信息构造临时配置
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $viewerId = trim((string)($_POST['viewer_id'] ?? ''));
        if ($baseUrl === '' || $email === '') {
            api_response(400, null, '请先填写网盘地址与服务账号');
        }
        $cfg = [
            'id' => 0,
            'base_url' => $baseUrl,
            'email' => $email,
            'password' => $password,
            'viewer_id' => $viewerId,
        ];
    }
    $result = netdisk_test_connection($cfg);
    if ($result['ok']) {
        api_response(200, ['message' => $result['message']]);
    } else {
        api_response(400, null, '测试失败：' . $result['message']);
    }
}

// 连接级全文索引/OCR 配置保存（全文索引区的"连接 OCR 配置"独立表单）
if ($path === '/admin/netdisk/index-config/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $configId = (int)($_POST['config_id'] ?? 0);
    if ($configId <= 0) {
        api_response(400, null, '缺少配置 ID');
    }
    $cfg = cloudreve_config_get($configId);
    if (!$cfg) {
        api_response(400, null, '配置不存在');
    }
    $ocrEnabled = !empty($_POST['index_ocr_enabled']) ? '1' : '0';
    $sub = [
        'index_ocr_concurrency' => trim((string)($_POST['index_ocr_concurrency'] ?? '')),
        'index_ocr_dpi_start' => trim((string)($_POST['index_ocr_dpi_start'] ?? '')),
        'index_ocr_dpi_max' => trim((string)($_POST['index_ocr_dpi_max'] ?? '')),
        'index_ocr_quality_threshold' => trim((string)($_POST['index_ocr_quality_threshold'] ?? '')),
        'index_ocr_max_mb' => trim((string)($_POST['index_ocr_max_mb'] ?? '')),
        'index_ocr_low_max_mb' => trim((string)($_POST['index_ocr_low_max_mb'] ?? '')),
        'index_refresh_ttl' => trim((string)($_POST['index_refresh_ttl'] ?? '')),
        'index_skip_exts' => trim((string)($_POST['index_skip_exts'] ?? '')),
    ];
    if ($ocrEnabled === '0') {
        foreach ($sub as $k => $v) {
            $sub[$k] = '';
        }
    }
    $fields = array_merge(['index_ocr_enabled' => $ocrEnabled], $sub);
    $pdo = db();
    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $sets[] = $col . ' = :' . $col;
        $params[':' . $col] = $val;
    }
    $params[':id'] = $configId;
    $pdo->prepare('UPDATE cloudreve_configs SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    api_response(0, null, '该连接 OCR/索引配置已保存');
}

// 文件夹选择器：列出某连接配置下的目录（含正在编辑的表单里的连接信息）
if ($path === '/admin/netdisk/folders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $configId = (int)($_POST['config_id'] ?? 0);
    $uri = trim((string)($_POST['uri'] ?? ''));
    $cfg = null;
    if ($configId > 0) {
        $cfg = cloudreve_config_get($configId);
    }
    if (!$cfg) {
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $viewerId = trim((string)($_POST['viewer_id'] ?? ''));
        if ($baseUrl === '' || $email === '') {
            api_response(400, null, '请先在表单填写网盘地址与服务账号');
        }
        $cfg = [
            'id' => 0,
            'base_url' => $baseUrl,
            'email' => $email,
            'password' => $password,
            'viewer_id' => $viewerId,
        ];
    }
    if ($uri === '') {
        $uri = netdisk_normalize_root((string)($cfg['default_root'] ?? 'cloudreve://my'));
    }
    $data = cloudreve_list_folder($cfg, $uri);
    if ($data === null) {
        // 用“根目录”再试一次，避免因子目录不可列而报错
        $root = netdisk_normalize_root((string)($cfg['default_root'] ?? 'cloudreve://my'));
        if ($uri !== $root) {
            $data = cloudreve_list_folder($cfg, $root);
            $uri = $root;
        }
    }
    if ($data === null) {
        api_response(502, null, 'Cloudreve 连接失败或目录不可访问');
    }
    $folders = [];
    $files = [];
    foreach (($data['files'] ?? []) as $f) {
        if ((int)($f['type'] ?? 0) === 1) {
            $redirect = (string)($f['metadata']['sys:shared_redirect'] ?? '');
            $folders[] = [
                'name' => (string)($f['name'] ?? ''),
                'uri' => $redirect !== '' ? $redirect : (string)($f['path'] ?? ''),
            ];
        } else {
            $files[] = [
                'name' => (string)($f['name'] ?? ''),
                'uri' => (string)($f['path'] ?? ''),
            ];
        }
    }
    api_response(200, [
        'current' => $uri,
        'root' => netdisk_normalize_root((string)($cfg['default_root'] ?? 'cloudreve://my')),
        'folders' => $folders,
        'files' => $files,
    ]);
}

if ($path === '/admin/netdisk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $pdo = db();
    $enabledMap = isset($_POST['netdisk_enabled']) && is_array($_POST['netdisk_enabled']) ? $_POST['netdisk_enabled'] : [];
    $permMap = isset($_POST['netdisk_permission']) && is_array($_POST['netdisk_permission']) ? $_POST['netdisk_permission'] : [];
    $cfgMap = isset($_POST['netdisk_config_id']) && is_array($_POST['netdisk_config_id']) ? $_POST['netdisk_config_id'] : [];
    $ids = array_unique(array_merge(
        array_map('intval', array_keys($enabledMap)),
        array_map('intval', array_keys($permMap)),
        array_map('intval', array_keys($cfgMap))
    ));
    // 分享绑定的访问目录 = 绑定连接配置的 default_root（不再单独指定根目录）
    $stmt = $pdo->prepare('UPDATE shares SET netdisk_enabled = :enabled, netdisk_root = "", netdisk_permission = :perm, netdisk_config_id = :cfg_id, updated_at = :updated_at WHERE id = :id');
    foreach ($ids as $shareId) {
        if ($shareId <= 0) {
            continue;
        }
        $enabled = !empty($enabledMap[$shareId]) ? 1 : 0;
        $perm = (string)($permMap[$shareId] ?? 'view');
        $perm = ($perm === 'edit') ? 'edit' : 'view';
        $cfgId = (int)($cfgMap[$shareId] ?? 0);
        $stmt->execute([
            ':enabled' => $enabled,
            ':perm' => $perm,
            ':cfg_id' => $cfgId,
            ':updated_at' => now(),
            ':id' => $shareId,
        ]);
    }
    flash('info', '分享绑定已保存');
    redirect('/admin#netdisk');
}

// 下载规则：新增（支持文件与文件夹）
if ($path === '/admin/netdisk/download/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $uri = trim((string)($_POST['uri'] ?? ''));
    $isFolder = !empty($_POST['is_folder']) ? 1 : 0;
    if ($shareId <= 0 || $uri === '') {
        api_response(400, null, '参数不完整');
    }
    $pdo = db();
    // 去重：同一分享下相同 uri + 类型 不重复插入
    $dup = $pdo->prepare('SELECT id FROM share_download_rules WHERE share_id=:sid AND uri=:uri AND is_folder=:isf');
    $dup->execute([':sid' => $shareId, ':uri' => $uri, ':isf' => $isFolder]);
    if (!$dup->fetch()) {
        $ins = $pdo->prepare('INSERT INTO share_download_rules (share_id, uri, is_folder, created_at) VALUES (:sid, :uri, :isf, :t)');
        $ins->execute([':sid' => $shareId, ':uri' => $uri, ':isf' => $isFolder, ':t' => now()]);
    }
    // 管理员配置的下载规则视为该分享根目录下的授权路径，登记为重定向白名单
    $ruleShare = find_share_by_id($shareId);
    if ($ruleShare) {
        $ruleCfg = netdisk_share_config($ruleShare);
        if ($ruleCfg['enabled'] && $ruleCfg['cfg']) {
            netdisk_register_redirect_alias($ruleCfg['cfg'], netdisk_normalize_root($ruleCfg['root']), $uri, $isFolder === 1);
        }
    }
    api_response(200, ['id' => (int)$pdo->lastInsertId(), 'share_id' => $shareId, 'uri' => $uri, 'is_folder' => $isFolder]);
}

// 下载规则：删除
if ($path === '/admin/netdisk/download/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    if ($ruleId > 0) {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM share_download_rules WHERE id = :id');
        $stmt->execute([':id' => $ruleId]);
    }
    api_response(200, ['id' => $ruleId]);
}

// 下载规则：列出某分享已配置的规则
if ($path === '/admin/netdisk/download/rules' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    api_response(200, netdisk_download_rules($shareId));
}

// 全局 WOPI 应用：新增（含校验）
if ($path === '/admin/netdisk/office/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $appId = (int)($_POST['app_id'] ?? 0);
    $appName = trim((string)($_POST['app_name'] ?? ''));
    $appBaseUrl = rtrim(trim((string)($_POST['app_base_url'] ?? '')), '/');
    $appEnabled = !empty($_POST['app_enabled']) ? 1 : 0;
    if ($appName === '' || $appBaseUrl === '') {
        flash('error', '应用名称与 WOPI 地址不能为空');
        redirect('/admin#netdisk');
    }
    $validated = netdisk_office_validate_base($appBaseUrl);
    if (!$validated['ok']) {
        flash('error', '校验失败：' . $validated['error'] . '（服务：' . $validated['app'] . '）');
        redirect('/admin#netdisk');
    }
    // 按 discovery 自动识别引擎：urlsrc 含 cool.html 视为 Collabora，含 /hosting/wopi/ 视为 OnlyOffice，
    // 其余按 urlsrc 模板存为自定义引擎
    $appEngine = 'collabora';
    $urlTemplate = '';
    foreach ($validated['exts'] as $tmpExt) {
        $map = netdisk_office_discovery_engine_map($appBaseUrl, (string)$tmpExt);
        if ($map !== null) {
            $appEngine = $map['engine'];
            $urlTemplate = (string)($map['template'] ?? '');
            break;
        }
    }
    if ($appEngine !== 'collabora' && $appEngine !== 'onlyoffice' && $urlTemplate === '') {
        // 无法识别时退回 collabora（可预览多数扩展），用户后续可自行调整
        $appEngine = 'collabora';
    }
    $pdo = db();
    $now = now();
    if ($appId > 0) {
        $existing = $pdo->prepare('SELECT id FROM office_apps WHERE id = :id');
        $existing->execute([':id' => $appId]);
        if (!$existing->fetch()) {
            flash('error', '应用不存在');
            redirect('/admin#netdisk');
        }
        $stmt = $pdo->prepare('UPDATE office_apps SET name=:name, engine=:engine, base_url=:base_url, url_template=:url_template, enabled=:enabled, updated_at=:updated_at WHERE id=:id');
        $stmt->execute([
            ':name' => $appName,
            ':engine' => $appEngine,
            ':base_url' => $appBaseUrl,
            ':url_template' => $urlTemplate,
            ':enabled' => $appEnabled,
            ':updated_at' => $now,
            ':id' => $appId,
        ]);
        $action = '更新';
    } else {
        $stmt = $pdo->prepare('INSERT INTO office_apps (name, engine, base_url, url_template, enabled, created_at, updated_at) VALUES (:name, :engine, :base_url, :url_template, :enabled, :created_at, :updated_at)');
        $stmt->execute([
            ':name' => $appName,
            ':engine' => $appEngine,
            ':base_url' => $appBaseUrl,
            ':url_template' => $urlTemplate,
            ':enabled' => $appEnabled,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $action = '添加';
    }
    // 清理旧缓存，让新地址立即生效
    set_setting('netdisk_office_disc_' . $appEngine . '_' . md5($appBaseUrl), '');
    $extPreview = count($validated['exts']) > 0 ? implode('、', array_map('strval', array_slice($validated['exts'], 0, 12))) : '（无）';
    flash('info', '已' . $action . ' WOPI 应用「' . $appName . '」，识别引擎：' . netdisk_office_engine_label($appEngine) . '，检测到服务：' . $validated['app'] . '，支持预览扩展：' . $extPreview);
    redirect('/admin#netdisk');
}

// 探测某 base 地址下某扩展的 view urlsrc，并识别引擎类型
// 返回 ['engine'=>string, 'template'=>string] 或 null
function netdisk_office_discovery_engine_map(string $base, string $ext): ?array {
    $base = rtrim($base, '/');
    $url = $base . '/hosting/discovery';
    $resp = netdisk_office_fetch($url);
    $raw = $resp['ok'] ? $resp['raw'] : '';
    if ($raw === '' || strpos($raw, '<') !== 0) {
        return null;
    }
    $ext = strtolower(ltrim($ext, '.'));
    $src = '';
    preg_match_all('#<action[^>]*name="([^"]+)"[^>]*ext="([^"]+)"[^>]*urlsrc="([^"]+)"#i', $raw, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        if (strtolower($row[1]) === 'view' && strtolower($row[2]) === $ext) {
            $src = html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5);
            break;
        }
    }
    if ($src === '') {
        preg_match_all('#<action[^>]*ext="([^"]+)"[^>]*name="([^"]+)"[^>]*urlsrc="([^"]+)"#i', $raw, $m2, PREG_SET_ORDER);
        foreach ($m2 as $row) {
            if (strtolower($row[2]) === 'view' && strtolower($row[1]) === $ext) {
                $src = html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5);
                break;
            }
        }
    }
    if ($src === '') {
        return null;
    }
    $src = preg_replace('#<[^>]*>#', '', $src);
    if (strpos($src, 'cool.html') !== false || stripos($src, '/browser/') !== false) {
        return ['engine' => 'collabora', 'template' => ''];
    }
    if (stripos($src, '/hosting/wopi/') !== false) {
        return ['engine' => 'onlyoffice', 'template' => ''];
    }
    return ['engine' => 'custom', 'template' => $src];
}

// 全局 WOPI 应用：列表（JSON，供管理界面/自动化使用）
if ($path === '/admin/netdisk/office/list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    api_response(200, netdisk_office_apps(false));
}

// 全局 WOPI 应用：删除
if ($path === '/admin/netdisk/office/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $appId = (int)($_POST['app_id'] ?? 0);
    if ($appId > 0) {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT engine, base_url FROM office_apps WHERE id = :id');
        $stmt->execute([':id' => $appId]);
        $deletedApp = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $stmt = $pdo->prepare('DELETE FROM office_apps WHERE id = :id');
        $stmt->execute([':id' => $appId]);
        if ($deletedApp) {
            // 清理该应用的 discovery 缓存，避免残留地址被误选
            $cacheKey = 'netdisk_office_disc_' . strtolower((string)$deletedApp['engine']) . '_' . md5(rtrim((string)$deletedApp['base_url'], '/'));
            set_setting($cacheKey, '');
        }
        flash('info', 'WOPI 应用已删除');
    }
    redirect('/admin#netdisk');
}

// 全局 WOPI 应用：测试连接
if ($path === '/admin/netdisk/office/test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $appId = (int)($_POST['app_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM office_apps WHERE id = :id');
    $stmt->execute([':id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        api_response(400, null, 'WOPI 应用不存在');
    }
    $validated = netdisk_office_validate_base((string)$app['base_url']);
    if ($validated['ok']) {
        $extPreview = count($validated['exts']) > 0 ? implode('、', array_map('strval', array_slice($validated['exts'], 0, 12))) : '（无）';
        api_response(200, ['message' => '测试成功：「' . (string)$app['name'] . '」服务：' . $validated['app'] . '，支持预览扩展：' . $extPreview]);
    } else {
        api_response(400, null, '测试失败：' . $validated['error']);
    }
}

// 全局 WOPI 应用：预览设置（默认引擎 + 扩展名映射）
if ($path === '/admin/netdisk/office/settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $appDefaultEngine = strtolower(trim((string)($_POST['app_default_engine'] ?? '')));
    // 校验：必须是当前已启用的引擎；否则退回第一个已启用引擎（不再使用 auto）
    if ($appDefaultEngine === 'auto' || netdisk_office_base_url($appDefaultEngine) === '') {
        $appDefaultEngine = '';
        foreach (netdisk_office_apps(true) as $firstApp) {
            $firstEng = strtolower((string)($firstApp['engine'] ?? ''));
            if ($firstEng !== '') {
                $appDefaultEngine = $firstEng;
                break;
            }
        }
    }
    $appExtMapRaw = trim((string)($_POST['app_ext_map'] ?? ''));
    $appExtMap = '{}';
    if ($appExtMapRaw !== '') {
        $decoded = json_decode($appExtMapRaw, true);
        if (!is_array($decoded)) {
            $decoded = [];
            foreach (preg_split('/\r\n|\n|\r/', $appExtMapRaw) as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $ev = strtolower(trim($parts[1]));
                    if ($ev !== '' && netdisk_office_base_url($ev) !== '') {
                        // 扩展名支持逗号分隔多个：doc,docx,xlsx:collabora
                        foreach (preg_split('/[\s,，]+/', $parts[0]) as $oneExt) {
                            $oneExt = strtolower(ltrim(trim($oneExt), '.'));
                            if ($oneExt !== '') {
                                $decoded[$oneExt] = $ev;
                            }
                        }
                    }
                }
            }
        }
        if (is_array($decoded)) {
            $appExtMap = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }
    set_setting('netdisk_office_engine', $appDefaultEngine);
    set_setting('netdisk_office_ext_map', $appExtMap);
    $extCount = count((array)json_decode($appExtMap, true));
    flash('info', '预览设置已保存（默认引擎：' . $appDefaultEngine . '，指定扩展 ' . $extCount . ' 项）');
    redirect('/admin#netdisk');
}

// 网盘本地全文索引：手动全量重建（默认所有启用网盘的分享；传 config_id 时只重建绑定该连接的分享）
if ($path === '/admin/netdisk/reindex' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $scopeConfigId = (int)($_POST['config_id'] ?? 0);
    // 0018: 强制重跑 OCR——忽略 netdisk_ocr_files 状态表的全部跳过判定（前端勾选框传入）
    $forceOcr = !empty($_POST['force_ocr']) || !empty($_GET['force_ocr']);
    set_setting('netdisk_index_ocr', !empty($_POST['index_ocr']) ? '1' : '0');
    set_setting('netdisk_index_ocr_concurrency', (string)max(1, (int)($_POST['ocr_concurrency'] ?? 4)));
    set_setting('netdisk_index_ocr_max_mb', (string)max(1, (int)($_POST['ocr_max_mb'] ?? 10)));
    set_setting('netdisk_index_ocr_low_max_mb', (string)max(1, (int)($_POST['ocr_low_max_mb'] ?? 50)));
    set_setting('netdisk_index_refresh_ttl', (string)max(60, (int)($_POST['refresh_ttl'] ?? 3600)));
    set_setting('netdisk_index_ocr_dpi_start', (string)max(50, min(600, (int)($_POST['ocr_dpi_start'] ?? 100))));
    set_setting('netdisk_index_ocr_dpi_max', (string)max(100, min(600, (int)($_POST['ocr_dpi_max'] ?? 600))));
    $thr = (float)($_POST['ocr_quality_threshold'] ?? 0.3);
    $thr = max(0.0, min(1.0, $thr));
    set_setting('netdisk_index_ocr_quality_threshold', (string)$thr);
    $skipRaw = trim((string)($_POST['skip_exts'] ?? 'zip,dwg,rar'));
    $skipNorm = [];
    foreach (preg_split('/[,，\s]+/', $skipRaw) as $e) {
        $e = strtolower(ltrim(trim($e), '.'));
        if ($e !== '') {
            $skipNorm[$e] = true;
        }
    }
    set_setting('netdisk_index_skip_exts', implode(',', array_keys($skipNorm)));
    $shares = db()->query('SELECT id, slug, title, netdisk_enabled, netdisk_config_id, netdisk_root, netdisk_permission FROM shares WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    $queued = 0;
    $skipped = 0;
    foreach ($shares as $s) {
        // 按连接过滤：指定 config_id 时，仅重建绑定该连接的分享
        if ($scopeConfigId > 0 && (int)($s['netdisk_config_id'] ?? 0) !== $scopeConfigId) {
            continue;
        }
        $base = netdisk_share_config($s);
        if (!$base['enabled'] || !$base['cfg']) {
            $skipped++;
            continue;
        }
        $jobId = netdisk_index_job_create((int)$s['id'], $forceOcr);
        if ($jobId > 0) {
            $queued++;
        }
    }
    api_response(0, ['queued' => $queued, 'skipped' => $skipped], $scopeConfigId > 0 ? '已为该连接绑定分享加入后台重建队列' : '已加入后台重建队列');
}
if ($path === '/admin/netdisk/reindex/status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    $pdo = db();
    // 按连接聚合进度：每个连接一行，汇总其下所有分享的索引/OCR 进度
    $shares = $pdo->query('SELECT id, slug, title, netdisk_config_id FROM shares WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    $configs = $pdo->query('SELECT * FROM cloudreve_configs ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $byCfg = [];
    foreach ($shares as $s) {
        $cfgId = (int)($s['netdisk_config_id'] ?? 0);
        if (!isset($byCfg[$cfgId])) {
            $byCfg[$cfgId] = [];
        }
        $byCfg[$cfgId][] = $s;
    }
    $configRows = [];
    foreach ($configs as $c) {
        $cfgId = (int)$c['id'];
        $cfgShares = $byCfg[$cfgId] ?? [];
        $agg = [
            'share_total' => count($cfgShares),
            'files_done' => 0,
            'files_total' => 0,
            'done_shares' => 0,
            'running_shares' => 0,
            'pending_shares' => 0,
            'failed_shares' => 0,
            'active_job' => null,
            'notes' => [],
        ];
        foreach ($cfgShares as $s) {
            $job = netdisk_index_job_latest((int)$s['id']);
            if (!$job) {
                continue;
            }
            if (!empty($job['notes'])) {
                $agg['notes'][] = (string)$job['notes'];
            }
            $agg['files_done'] += (int)($job['files'] ?? 0);
            $agg['files_total'] += (int)($job['total_files'] ?? 0);
            if ($job['status'] === 'done') {
                $agg['done_shares']++;
            } elseif ($job['status'] === 'running') {
                $agg['running_shares']++;
                // 取最新 running 的 job 作为活动进度展示
                if (!$agg['active_job'] || (int)$job['id'] > (int)$agg['active_job']['id']) {
                    $agg['active_job'] = $job;
                }
            } elseif ($job['status'] === 'pending') {
                $agg['pending_shares']++;
                if (!$agg['active_job'] || (int)$job['id'] > (int)$agg['active_job']['id']) {
                    $agg['active_job'] = $job;
                }
            } elseif ($job['status'] === 'failed') {
                $agg['failed_shares']++;
                if (!$agg['active_job'] || (int)$job['id'] > (int)$agg['active_job']['id']) {
                    $agg['active_job'] = $job;
                }
            } elseif ($job['status'] === 'cancelled') {
                // 视为完成（终止）
                $agg['done_shares']++;
            }
        }
        $agg['notes'] = implode('；', array_unique(array_filter($agg['notes'])));
        $configRows[] = [
            'config_id' => $cfgId,
            'name' => (string)($c['name'] ?? ('连接 #' . $cfgId)),
            'index_cfg' => netdisk_admin_index_cfg_snapshot($pdo, $cfgId),
            'agg' => $agg,
            'job_status' => $agg['active_job'] ? $agg['active_job']['status'] : 'idle',
        ];
    }
    $queueStats = [
        'pending' => (int)$pdo->query('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE status="pending"')->fetchColumn(),
        'running' => (int)$pdo->query('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE status="running"')->fetchColumn(),
        'done' => (int)$pdo->query('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE status="done"')->fetchColumn(),
        'failed' => (int)$pdo->query('SELECT COUNT(*) FROM netdisk_ocr_queue WHERE status="failed"')->fetchColumn(),
    ];
    $summary = [
        'ocr_enabled' => netdisk_index_ocr_enabled(0),
        'ocr_concurrency' => netdisk_index_ocr_concurrency(0),
        'ocr_max_mb' => netdisk_index_ocr_max_mb(0),
        'ocr_low_max_mb' => netdisk_index_ocr_low_max_mb(0),
        'refresh_ttl' => netdisk_index_refresh_ttl(0),
        'ocr_queue' => $queueStats,
        'ocr_dpi_start' => netdisk_index_ocr_dpi_start(0),
        'ocr_dpi_max' => netdisk_index_ocr_dpi_max(0),
        'ocr_quality_threshold' => netdisk_index_ocr_quality_threshold(0),
        'per_config' => netdisk_admin_index_cfg_map($pdo),
    ];
    api_response(0, ['configs' => $configRows, 'summary' => $summary]);
}

// 续跑重试：把某连接的 failed job 置回 pending（worker 拾取后增量续跑，不重头）
if ($path === '/admin/netdisk/reindex/retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $pdo = db();
    $configId = (int)($_POST['config_id'] ?? 0);
    $shareId = (int)($_POST['share_id'] ?? 0);
    if ($configId > 0) {
        $rows = $pdo->query('SELECT id FROM netdisk_index_jobs WHERE share_id IN (SELECT id FROM shares WHERE netdisk_config_id=' . (int)$configId . ') AND status="failed"')->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($shareId > 0) {
        $rows = $pdo->query('SELECT id FROM netdisk_index_jobs WHERE share_id=' . (int)$shareId . ' AND status="failed"')->fetchAll(PDO::FETCH_COLUMN);
    } else {
        api_response(400, null, '缺少参数');
    }
    // 0023: 续跑同样刷新 created_at（口径=最后一次被触发的时间，防 6 小时回收误杀）；
    //       清 current_detail 残留展示，进度字段 ocr_files_done/ocr_files_total 保留以续跑。
    $upd = $pdo->prepare('UPDATE netdisk_index_jobs SET status="pending", started_at=0, finished_at=0, error="", cancel_requested=0, crash_count=0, heartbeat_at=:now, created_at=:now, current_detail="" WHERE id=:id');
    $n = 0;
    foreach ($rows as $jid) {
        $upd->execute([':now' => time(), ':id' => (int)$jid]);
        $n++;
    }
    api_response(0, ['retried' => $n], $n > 0 ? '已续跑 ' . $n . ' 个失败任务（从断点继续）' : '没有可续跑的失败任务');
}
if ($path === '/admin/netdisk/reindex/cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $pdo = db();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $configId = (int)($_POST['config_id'] ?? 0);
    if ($configId > 0) {
        // 按连接取消：取消所有绑定该连接分享的 pending/running 任务
        $pdo->prepare('UPDATE netdisk_index_jobs SET cancel_requested=1
            WHERE share_id IN (SELECT id FROM shares WHERE netdisk_config_id=:c) AND status IN ("pending","running")')
            ->execute([':c' => $configId]);
        api_response(0, null, '已请求终止该连接所有分享的索引重建');
    } elseif ($shareId > 0) {
        // 将该分享最新 pending/running 的 job 标记取消请求；worker 在下个检查点响应。
        $pdo->prepare('UPDATE netdisk_index_jobs SET cancel_requested=1
            WHERE share_id=:s AND status IN ("pending","running")')
            ->execute([':s' => $shareId]);
        api_response(0, null, '已请求终止该分享的索引重建');
    } else {
        api_response(400, null, '缺少参数');
    }
}
if ($path === '/admin/share-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $ids = $_POST['share_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids)) {
        flash('error', '请先选择分享');
        redirect('/admin#shares');
    }
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $softStmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id');
    $restoreStmt = $pdo->prepare('UPDATE shares SET deleted_at = NULL WHERE id = :id');
    $affectedOwners = [];
    foreach ($ids as $shareId) {
        $ownerStmt->execute([':id' => $shareId]);
        $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
        if ($action === 'soft_delete') {
            $softStmt->execute([':deleted_at' => now(), ':id' => $shareId]);
            purge_share_access_logs($shareId);
        } elseif ($action === 'restore') {
            $restoreStmt->execute([':id' => $shareId]);
        } elseif ($action === 'hard_delete') {
            hard_delete_share($shareId);
        }
        if ($ownerId) {
            $affectedOwners[$ownerId] = true;
        }
    }
    foreach (array_keys($affectedOwners) as $ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', '批量操作已完成');
    redirect('/admin#shares');
}

if ($path === '/admin/scan/batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pairs = $_POST['scan_ids'] ?? [];
    $pairs = is_array($pairs) ? $pairs : [$pairs];
    if (empty($pairs)) {
        flash('error', '请先选择记录');
        redirect('/admin#scan');
    }
    $shareIds = [];
    $userIds = [];
    foreach ($pairs as $value) {
        $parts = explode('|', (string)$value, 2);
        $shareId = (int)($parts[0] ?? 0);
        $userId = (int)($parts[1] ?? 0);
        if ($shareId) {
            $shareIds[$shareId] = true;
        }
        if ($userId) {
            $userIds[$userId] = true;
        }
    }
    $pdo = db();
    if ($action === 'delete' && !empty($shareIds)) {
        $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL');
        foreach (array_keys($shareIds) as $shareId) {
            $stmt->execute([':deleted_at' => now(), ':id' => $shareId]);
            purge_share_access_logs($shareId);
        }
        flash('info', '已删除 ' . count($shareIds) . ' 个违规分享');
        redirect('/admin#scan');
    }
    if ($action === 'disable' && !empty($userIds)) {
        $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id AND role != "admin"');
        foreach (array_keys($userIds) as $userId) {
            $stmt->execute([':id' => $userId]);
        }
        flash('info', '已停用 ' . count($userIds) . ' 个违规账号');
        redirect('/admin#scan');
    }
    flash('error', '未找到可处理的记录');
    redirect('/admin#scan');
}

if ($path === '/admin/user-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === (int)$admin['id']) {
        flash('error', '不能禁用自己');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = CASE WHEN disabled = 1 THEN 0 ELSE 1 END WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    flash('info', '用户状态已更新');
    redirect('/admin#users');
}

if ($path === '/admin/user-role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === (int)$admin['id']) {
        flash('error', '不能修改自己的角色');
        redirect('/admin#users');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET role = CASE WHEN role = "admin" THEN "user" ELSE "admin" END WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    flash('info', '用户角色已更新');
    redirect('/admin#users');
}

if ($path === '/admin/user-limit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $limitMb = max(0, (int)($_POST['limit_mb'] ?? 0));
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET storage_limit_bytes = :limit WHERE id = :id');
    $stmt->execute([
        ':limit' => bytes_from_mb($limitMb),
        ':id' => $userId,
    ]);
    flash('info', '用户存储上限已更新');
    redirect('/admin#users');
}

if ($path === '/admin/share-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $ownerStmt->execute([':id' => $shareId]);
    $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id');
    $stmt->execute([
        ':deleted_at' => now(),
        ':id' => $shareId,
    ]);
    purge_share_access_logs($shareId);
    if ($ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', '分享已软删除');
    redirect('/admin#shares');
}

if ($path === '/admin/share-restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $pdo = db();
    $ownerStmt = $pdo->prepare('SELECT user_id FROM shares WHERE id = :id');
    $ownerStmt->execute([':id' => $shareId]);
    $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = NULL WHERE id = :id');
    $stmt->execute([':id' => $shareId]);
    if ($ownerId) {
        recalculate_user_storage($ownerId);
    }
    flash('info', '分享已恢复');
    redirect('/admin#shares');
}

if ($path === '/admin/share-hard-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $deleted = hard_delete_share($shareId);
    if ($deleted === null) {
        flash('error', '分享不存在');
    } else {
        flash('info', '分享已彻底删除');
    }
    redirect('/admin#shares');
}

if ($path === '/admin/report-handle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', '举报不存在');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE share_reports SET handled_at = :handled_at, handled_by = :handled_by WHERE id = :id');
    $stmt->execute([
        ':handled_at' => now(),
        ':handled_by' => (int)$admin['id'],
        ':id' => $reportId,
    ]);
    flash('info', '举报已处理');
    redirect('/admin#reports');
}

if ($path === '/admin/report-share-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', '举报不存在');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT share_id FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $shareId = (int)($stmt->fetchColumn() ?: 0);
    if ($shareId <= 0) {
        flash('error', '分享不存在');
        redirect('/admin#reports');
    }
    $deleted = hard_delete_share($shareId);
    if ($deleted === null) {
        flash('error', '分享不存在');
        redirect('/admin#reports');
    }
    flash('info', '分享已彻底删除');
    redirect('/admin#reports');
}

if ($path === '/admin/report-user-disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    $pdo = db();
    if ($reportId <= 0) {
        flash('error', '举报不存在');
        redirect('/admin#reports');
    }
    $stmt = $pdo->prepare('SELECT share_user_id FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if ($userId <= 0) {
        flash('error', '用户不存在');
        redirect('/admin#reports');
    }
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $roleStmt->execute([':id' => $userId]);
    $role = (string)($roleStmt->fetchColumn() ?? '');
    if ($role === 'admin') {
        flash('error', '无法禁用管理员账号');
        redirect('/admin#reports');
    }
    $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $pdo->prepare('UPDATE share_reports SET handled_at = :handled_at, handled_by = :handled_by WHERE id = :id')
        ->execute([
            ':handled_at' => now(),
            ':handled_by' => (int)$admin['id'],
            ':id' => $reportId,
        ]);
    flash('info', '账号已禁用');
    redirect('/admin#reports');
}

if ($path === '/admin/report-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId <= 0) {
        flash('error', '举报不存在');
        redirect('/admin#reports');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT handled_at FROM share_reports WHERE id = :id');
    $stmt->execute([':id' => $reportId]);
    $handledAt = (string)($stmt->fetchColumn() ?? '');
    if ($handledAt === '') {
        flash('error', '请先处理举报再删除');
        redirect('/admin#reports');
    }
    $pdo->prepare('DELETE FROM share_reports WHERE id = :id')->execute([':id' => $reportId]);
    flash('info', '举报记录已删除');
    redirect('/admin#reports');
}

if ($path === '/admin/report-batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $ids = $_POST['report_ids'] ?? [];
    $action = (string)($_POST['action'] ?? '');
    $ids = array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    if (empty($ids) || $action !== 'delete') {
        flash('error', '请先选择要删除的举报');
        redirect('/admin#reports');
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = $pdo->prepare('DELETE FROM share_reports WHERE id IN (' . $placeholders . ')');
    $del->execute($ids);
    $deleted = $del->rowCount();
    flash('info', '已删除 ' . $deleted . ' 条举报');
    redirect('/admin#reports');
}

if ($path === '/admin/chunk-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    global $config;
    $chunkId = trim((string)($_POST['chunk_id'] ?? ''));
    if ($chunkId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $chunkId)) {
        redirect('/admin#chunks');
    }
    $path = $config['uploads_dir'] . '/chunks/' . $chunkId;
    remove_dir($path);
    $stage = $config['uploads_dir'] . '/staging/' . $chunkId;
    remove_dir($stage);
    redirect('/admin#chunks');
}

if ($path === '/admin/chunk-clean' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    global $config;
    [$ttl] = chunk_cleanup_settings();
    $stale = list_stale_chunks($ttl);
    foreach ($stale as $chunk) {
        $chunkId = (string)($chunk['id'] ?? '');
        if ($chunkId === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $chunkId)) {
            continue;
        }
        $path = $config['uploads_dir'] . '/chunks/' . $chunkId;
        remove_dir($path);
        $stage = $config['uploads_dir'] . '/staging/' . $chunkId;
        remove_dir($stage);
    }
    redirect('/admin#chunks');
}

if ($path === '/admin/scan/start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        api_response(400, null, '请先设置违禁词');
    }
    $total = count_scannable_docs();
    $_SESSION['scan_results'] = [];
    $_SESSION['scan_logs'] = [];
    $_SESSION['scan_done'] = 0;
    $_SESSION['scan_at'] = time();
    $_SESSION['scan_total'] = $total;
    api_response(200, ['total' => $total]);
}

if ($path === '/admin/scan/step' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        api_response(400, null, '请先设置违禁词');
    }
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $limit = max(1, min(200, (int)($_POST['limit'] ?? 50)));
    $total = (int)($_SESSION['scan_total'] ?? count_scannable_docs());
    $batch = scan_banned_shares_batch($words, $offset, $limit);
    $existing = $_SESSION['scan_results'] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing = array_merge($existing, $batch['hits']);
    $_SESSION['scan_results'] = $existing;
    $existingLogs = $_SESSION['scan_logs'] ?? [];
    if (!is_array($existingLogs)) {
        $existingLogs = [];
    }
    $existingLogs = array_merge($existingLogs, $batch['logs']);
    $_SESSION['scan_logs'] = $existingLogs;
    $_SESSION['scan_at'] = time();
    $nextOffset = $offset + (int)$batch['count'];
    $done = $nextOffset >= $total || $batch['count'] === 0;
    $_SESSION['scan_done'] = $done ? 1 : 0;
    api_response(200, [
        'nextOffset' => $nextOffset,
        'done' => $done,
        'hitCount' => count($existing),
        'logs' => $batch['logs'],
        'total' => $total,
    ]);
}

if ($path === '/admin/scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $words = get_banned_words();
    if (empty($words)) {
        flash('error', '请先设置违禁词');
        redirect('/admin#scan');
    }
    $results = scan_banned_shares($words);
    $_SESSION['scan_results'] = $results;
    $_SESSION['scan_logs'] = [];
    $_SESSION['scan_done'] = 1;
    $_SESSION['scan_at'] = time();
    flash('info', '扫描完成，共命中 ' . count($results) . ' 条记录');
    redirect('/admin#scan');
}

if ($path === '/admin/scan/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $results = $_SESSION['scan_results'] ?? [];
    if (empty($results) || !is_array($results)) {
        flash('error', '暂无扫描结果');
        redirect('/admin#scan');
    }
    $shareIds = [];
    foreach ($results as $hit) {
        if (isset($hit['share_id'])) {
            $shareIds[(int)$hit['share_id']] = true;
        }
    }
    if (empty($shareIds)) {
        flash('error', '暂无可删除分享');
        redirect('/admin#scan');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE shares SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL');
    foreach (array_keys($shareIds) as $shareId) {
        $stmt->execute([':deleted_at' => now(), ':id' => $shareId]);
    }
    flash('info', '已删除 ' . count($shareIds) . ' 个违规分享');
    redirect('/admin#scan');
}

if ($path === '/admin/scan/disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $results = $_SESSION['scan_results'] ?? [];
    if (empty($results) || !is_array($results)) {
        flash('error', '暂无扫描结果');
        redirect('/admin#scan');
    }
    $userIds = [];
    foreach ($results as $hit) {
        if (isset($hit['user_id'])) {
            $userIds[(int)$hit['user_id']] = true;
        }
    }
    if (empty($userIds)) {
        flash('error', '暂无可停用账号');
        redirect('/admin#scan');
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET disabled = 1 WHERE id = :id AND role != "admin"');
    foreach (array_keys($userIds) as $userId) {
        $stmt->execute([':id' => $userId]);
    }
    flash('info', '已停用 ' . count($userIds) . ' 个违规账号');
    redirect('/admin#scan');
}

if ($path === '/admin/comment/edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $commentId = max(0, (int)($_POST['comment_id'] ?? 0));
    $content = trim((string)($_POST['content'] ?? ''));
    if ($commentId <= 0) {
        flash('error', '缺少评论ID');
        redirect('/admin#scan');
    }
    if ($content === '') {
        flash('error', '评论内容不能为空');
        redirect('/admin#scan');
    }
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($contentLength > 2000) {
        flash('error', '评论内容过长');
        redirect('/admin#scan');
    }
    $bannedWords = get_banned_words();
    if (!empty($bannedWords)) {
        $hit = find_banned_word($content, $bannedWords);
        if ($hit) {
            flash('error', '触发违禁词：' . $hit['word']);
            redirect('/admin#scan');
        }
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT share_comments.*, shares.user_id AS share_user_id FROM share_comments
        JOIN shares ON share_comments.share_id = shares.id
        WHERE share_comments.id = :id');
    $stmt->execute([':id' => $commentId]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$comment) {
        flash('error', '评论不存在');
        redirect('/admin#scan');
    }
    $shareId = (int)($comment['share_id'] ?? 0);
    $shareUserId = (int)($comment['share_user_id'] ?? 0);
    if ($shareId <= 0 || $shareUserId <= 0) {
        flash('error', '评论关联的分享不存在');
        redirect('/admin#scan');
    }
    $commentEmail = (string)($comment['email'] ?? '');
    $newSize = calculate_comment_size($commentEmail, $content);
    $oldSize = (int)($comment['size_bytes'] ?? 0);
    $oldAssets = extract_comment_asset_paths((string)($comment['content'] ?? ''), $shareId);
    $newAssets = extract_comment_asset_paths($content, $shareId);
    $removeAssets = array_values(array_diff($oldAssets, $newAssets));
    if (!empty($removeAssets)) {
        $removeAssets = filter_unused_comment_assets($shareId, $removeAssets, [$commentId]);
    }
    $removeAssetSize = sum_share_asset_sizes($shareId, $removeAssets);
    $delta = $newSize - $oldSize;
    $netDelta = $delta - $removeAssetSize;
    if ($netDelta > 0) {
        $owner = get_user_by_id($shareUserId);
        if (!$owner) {
            flash('error', '分享所属用户不存在');
            redirect('/admin#scan');
        }
        $used = recalculate_user_storage((int)$owner['id']);
        $limit = get_user_limit_bytes($owner);
        if ($limit > 0 && ($used + $netDelta) > $limit) {
            flash('error', '存储空间不足，无法保存修改');
            redirect('/admin#scan');
        }
    }
    $update = $pdo->prepare('UPDATE share_comments SET content = :content, size_bytes = :size_bytes WHERE id = :id');
    $update->execute([
        ':content' => $content,
        ':size_bytes' => $newSize,
        ':id' => $commentId,
    ]);
    $deletedAssetSize = delete_comment_assets($shareId, $removeAssets);
    $totalDelta = $delta - $deletedAssetSize;
    if ($totalDelta !== 0) {
        adjust_share_size($shareId, $totalDelta);
        adjust_user_storage($shareUserId, $totalDelta);
    }
    flash('info', '评论已更新');
    redirect('/admin#scan');
}

if ($path === '/admin/share-docs-json' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_admin();
    $shareId = (int)($_GET['share_id'] ?? 0);
    if ($shareId <= 0) {
        api_response(400, null, '缺少分享ID');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, doc_id, title, hpath FROM share_docs WHERE share_id = :sid ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':sid' => $shareId]);
    $docs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $docs[] = [
            'id' => (int)$row['id'],
            'docId' => (string)$row['doc_id'],
            'title' => (string)$row['title'],
            'hpath' => (string)$row['hpath'],
        ];
    }
    api_response(200, ['docs' => $docs]);
}

if ($path === '/admin/share-announcement/update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $shareId = (int)($_POST['share_id'] ?? 0);
    $mode = trim((string)($_POST['announcement_mode'] ?? 'none'));
    if ($shareId <= 0) {
        flash('error', '缺少分享ID');
        redirect('/admin#shares');
    }
    if (!in_array($mode, ['none', 'manual', 'doc'], true)) {
        $mode = 'none';
    }
    $title = trim((string)($_POST['announcement_title'] ?? ''));
    $content = trim((string)($_POST['announcement_content'] ?? ''));
    $docId = trim((string)($_POST['announcement_doc_id'] ?? ''));
    $titleLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    $contentLen = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($titleLen > 120) {
        flash('error', '公告标题过长（最多 120 字）');
        redirect('/admin#shares');
    }
    if ($contentLen > 20000) {
        flash('error', '公告内容过长（最多 20000 字）');
        redirect('/admin#shares');
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM shares WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    if (!$stmt->fetchColumn()) {
        flash('error', '分享不存在');
        redirect('/admin#shares');
    }
    $update = $pdo->prepare('UPDATE shares SET announcement_mode = :mode, announcement_title = :title, announcement_content = :content, announcement_doc_id = :doc_id, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':mode' => $mode,
        ':title' => $title,
        ':content' => $content,
        ':doc_id' => $docId,
        ':updated_at' => now(),
        ':id' => $shareId,
    ]);
    flash('info', '公告已保存');
    redirect('/admin#shares');
}

if ($path === '/admin/reset-data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    check_csrf();
    $phrase = trim((string)($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== '确认删除') {
        flash('error', '确认口令错误');
        redirect('/admin');
    }
    reset_database();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    flash('info', '数据已重置，请使用默认管理员登录');
    redirect('/login');
}

if ($path === '/') {
    $user = current_user();
    if ($user) {
        redirect('/dashboard');
    }
    $allowRegistration = allow_registration();
    $siteIcp = get_setting('site_icp', '');
    $siteContactEmail = get_setting('site_contact_email', '');
    $appName = htmlspecialchars($config['app_name']);
    $versionText = site_version();
    $versionHtml = '';
    if ($versionText !== '') {
        $versionLabel = $versionText;
        if (stripos($versionLabel, 'v') !== 0) {
            $versionLabel = 'v' . $versionLabel;
        }
        $versionHtml = '<span class="home-version">' . htmlspecialchars($versionLabel) . '</span>';
    }
    $loginUrl = base_path() . '/login';
    $registerUrl = base_path() . '/register';

    $content = '<section class="home-hero">';
    $content .= '<div class="home-hero__main">';
    $content .= '<div class="home-badge">官方 Markdown 导出</div>';
    $content .= '<h1 class="home-title">' . $appName . $versionHtml . '</h1>';
    $content .= '<p class="home-lead">让文档与笔记本以可控的外链分享：自动同步、密码与到期控制、统一链接管理。</p>';
    $content .= '<div class="home-actions">';
    $content .= '<a class="button primary" href="' . $loginUrl . '">立即登录</a>';
    if ($allowRegistration) {
        $content .= '<a class="button" href="' . $registerUrl . '">注册账号</a>';
    }
    $content .= '</div>';
    $content .= '<div class="home-hero__meta">支持文档 / 笔记本 · 权限可控 · 多用户协作</div>';
    $content .= '</div>';
    $content .= '<div class="home-hero__visual">';
    $content .= '<div class="hero-card hero-card--preview">';
    $content .= '<div class="hero-card__title">分享预览</div>';
    $content .= '<div class="hero-card__lines"><span></span><span></span><span></span><span></span></div>';
    $content .= '<div class="hero-card__chips"><span>访问 128</span><span>到期 2026-01-10</span><span>密码保护</span></div>';
    $content .= '</div>';
    $content .= '<div class="hero-card hero-card--flow">';
    $content .= '<div class="hero-card__title">同步流程</div>';
    $content .= '<ol class="hero-flow"><li>生成 API Key</li><li>一键验证同步</li><li>复制链接分享</li></ol>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-metrics">';
    $content .= '<div class="home-metric"><strong>文档与笔记本</strong><span>保持层级与目录结构</span></div>';
    $content .= '<div class="home-metric"><strong>权限与到期</strong><span>密码、有效期、禁用一应俱全</span></div>';
    $content .= '<div class="home-metric"><strong>集中管理</strong><span>分享列表统一检索与追踪</span></div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>核心能力</h2>';
    $content .= '<div class="home-grid">';
    $content .= '<div class="home-card"><h3>完整 Markdown 支持</h3><p>公式、表格、任务列表、Mermaid 与代码高亮全部可用。</p></div>';
    $content .= '<div class="home-card"><h3>统一链接管理</h3><p>软删/恢复/彻底删除，分享状态一目了然。</p></div>';
    $content .= '<div class="home-card"><h3>多用户与审核</h3><p>账号管理、公告发布、违禁词扫描集中处理。</p></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>使用流程</h2>';
    $content .= '<div class="home-steps">';
    $content .= '<div class="home-step"><span>1</span> 登录后台生成 API Key</div>';
    $content .= '<div class="home-step"><span>2</span> 插件填写地址与 Key</div>';
    $content .= '<div class="home-step"><span>3</span> 一键验证并同步</div>';
    $content .= '<div class="home-step"><span>4</span> 复制链接对外分享</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="home-section">';
    $content .= '<h2>适用场景</h2>';
    $content .= '<div class="home-grid">';
    $content .= '<div class="home-card"><h3>产品文档</h3><p>对外发布手册，自动同步更新。</p></div>';
    $content .= '<div class="home-card"><h3>团队知识库</h3><p>内部分享、权限控制、到期管理。</p></div>';
    $content .= '<div class="home-card"><h3>课程与教程</h3><p>长文输出，目录清晰可导航。</p></div>';
    $content .= '</div>';
    $content .= '</section>';

    $footerItems = [];
    if (trim((string)$siteIcp) !== '') {
        $footerItems[] = 'ICP备案：' . htmlspecialchars((string)$siteIcp);
    }
    if (trim((string)$siteContactEmail) !== '') {
        $footerItems[] = '联系邮箱：' . htmlspecialchars((string)$siteContactEmail);
    }
    if (!empty($footerItems)) {
        $content .= '<footer class="home-footer">' . implode(' · ', $footerItems) . '</footer>';
    }
    render_page('首页', $content, null);
}

render_404_page();
