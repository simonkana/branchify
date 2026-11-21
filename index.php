<?php
/**
 * Branchify: a link-in-bio SaaS MVP in one file.
 *
 * Run:  php -S localhost:8000 index.php      (router mode: pretty /username URLs)
 * Env:  BRANCHIFY_ENV=production             (hides the dev mail catcher + demo hints)
 *       BRANCHIFY_URL=https://example.com    (canonical origin used in emails / QR codes)
 *       BRANCHIFY_MAIL=mail                  (also deliver via PHP mail(); default: catcher only)
 *
 * Requires PHP 8.4+ (built for 8.5) with pdo_sqlite and gd.
 */
declare(strict_types=1);

// ============================================================================
// 1. Configuration & bootstrap
// ============================================================================
const APP_NAME     = 'Branchify';
const DATA_DIR     = __DIR__ . '/data';
const DB_FILE      = DATA_DIR . '/branchify.sqlite';
const AVATAR_DIR   = DATA_DIR . '/avatars';
const MAIL_LOG     = DATA_DIR . '/mail.log';
const DEMO_EMAIL   = 'juno@branchify.test';
const DEMO_PASS    = 'branchify-demo';
const MIN_PASSWORD = 8;
const RESERVED_USERNAMES = ['admin', 'api', 'app', 'login', 'logout', 'register', 'signup', 'settings', 'help', 'support',
    'index', 'index.php', 'data', 'static', 'assets', 'about', 'pricing', 'mail', 'branchify', 'root', 'www', 'favicon.ico'];

const BUTTON_STYLES = ['fill' => 'Filled', 'outline' => 'Outline', 'hard' => 'Offset shadow', 'soft' => 'Tinted'];
const BUTTON_SHAPES = ['square' => 'Square', 'round' => 'Rounded', 'pill' => 'Pill'];
const FONTS = ['grotesk' => 'Grotesk', 'wide' => 'Wide', 'serif' => 'Serif', 'mono' => 'Mono'];


define('IS_DEV', (getenv('BRANCHIFY_ENV') ?: 'development') !== 'production');
define('CSP_NONCE', base64_encode(random_bytes(16)));

date_default_timezone_set('UTC');
ini_set('display_errors', IS_DEV ? '1' : '0');
error_reporting(E_ALL);

foreach ([DATA_DIR, AVATAR_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}
// Defence in depth for Apache-style hosts: the data folder must never be web-served.
if (!is_file(DATA_DIR . '/.htaccess')) {
    file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
}

function app_secret(): string
{
    static $secret = null;
    if ($secret === null) {
        $file = DATA_DIR . '/secret.key';
        if (!is_file($file)) {
            file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            chmod($file, 0600);
        }
        $secret = trim((string) file_get_contents($file));
    }
    return $secret;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

// --- Session ---------------------------------------------------------------
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '7200');
session_name('bf_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// --- Security headers ------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-" . CSP_NONCE . "' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src https://fonts.gstatic.com; "
    . "img-src 'self' data: blob: https://cdn.jsdelivr.net; "
    . "frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'none'; object-src 'none'");

// ============================================================================
// 2. Database
// ============================================================================
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
        migrate($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) {
        return;
    }
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL,
            bio           TEXT NOT NULL DEFAULT '',
            avatar        TEXT NOT NULL DEFAULT '',
            theme         TEXT NOT NULL DEFAULT 'riso',
            button_style  TEXT NOT NULL DEFAULT 'hard',
            button_shape  TEXT NOT NULL DEFAULT 'round',
            font          TEXT NOT NULL DEFAULT 'grotesk',
            socials       TEXT NOT NULL DEFAULT '{}',
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS links (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind       TEXT NOT NULL DEFAULT 'link' CHECK (kind IN ('link','header')),
            title      TEXT NOT NULL,
            url        TEXT NOT NULL DEFAULT '',
            emoji      TEXT NOT NULL DEFAULT '',
            featured   INTEGER NOT NULL DEFAULT 0,
            is_active  INTEGER NOT NULL DEFAULT 1,
            position   INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_links_user ON links(user_id, position);
        CREATE TABLE IF NOT EXISTS clicks (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            link_id    INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            referrer   TEXT NOT NULL DEFAULT 'Direct',
            device     TEXT NOT NULL DEFAULT 'desktop',
            ip_hash    TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_clicks_user ON clicks(user_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_clicks_link ON clicks(link_id);
        CREATE TABLE IF NOT EXISTS views (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            referrer   TEXT NOT NULL DEFAULT 'Direct',
            device     TEXT NOT NULL DEFAULT 'desktop',
            ip_hash    TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_views_user ON views(user_id, created_at);
        CREATE TABLE IF NOT EXISTS password_resets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at    TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            selector       TEXT NOT NULL UNIQUE,
            validator_hash TEXT NOT NULL,
            expires_at     TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS outbox (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient  TEXT NOT NULL,
            subject    TEXT NOT NULL,
            body_text  TEXT NOT NULL,
            body_html  TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket     TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_rate_bucket ON rate_limits(bucket, created_at);
        PRAGMA user_version = 1;
    SQL);
    seed_demo($pdo);
}

function seed_demo(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        return;
    }
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users (email, username, password_hash, display_name, bio, theme, button_style, button_shape, font, socials)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            DEMO_EMAIL, 'juno', password_hash(DEMO_PASS, PASSWORD_DEFAULT), 'Juno Park',
            "Ceramicist in Busan. Small-batch tableware, Friday kiln openings, and a podcast about making things slowly.",
            'riso', 'hard', 'round', 'grotesk',
            json_encode([
                'instagram' => 'https://instagram.com/junopark.studio',
                'youtube'   => 'https://youtube.com/@junoparkstudio',
                'tiktok'    => 'https://tiktok.com/@junopark',
                'email'     => 'mailto:hello@junopark.test',
            ]),
        ]);
    $uid = (int) $pdo->lastInsertId();

    $links = [
        ['header', 'Studio', '', '', 0],
        ['link', 'Shop the spring glaze drop', 'https://example.com/shop', '🏺', 1],
        ['link', 'Book a wheel-throwing class', 'https://example.com/classes', '🎟️', 0],
        ['link', 'Wholesale and restaurant orders', 'https://example.com/wholesale', '', 0],
        ['header', 'Listen and watch', '', '', 0],
        ['link', 'Kiln Talk, episode 42: ash glazes', 'https://example.com/podcast/42', '🎙️', 0],
        ['link', 'Friday kiln opening on YouTube', 'https://youtube.com', '', 0],
        ['link', 'Get the monthly studio letter', 'https://example.com/letter', '✉️', 0],
    ];
    $ins = $pdo->prepare('INSERT INTO links (user_id, kind, title, url, emoji, featured, position) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $linkIds = [];
    foreach ($links as $i => [$kind, $title, $url, $emoji, $featured]) {
        $ins->execute([$uid, $kind, $title, $url, $emoji, $featured, $i + 1]);
        if ($kind === 'link') {
            $linkIds[] = (int) $pdo->lastInsertId();
        }
    }

    // 30 days of believable traffic, weighted towards recent days and the featured link.
    $refs    = ['Instagram', 'Instagram', 'Instagram', 'TikTok', 'TikTok', 'YouTube', 'Direct', 'Direct', 'Google'];
    $devices = ['mobile', 'mobile', 'mobile', 'mobile', 'desktop', 'desktop', 'tablet'];
    $weights = [6, 3, 1, 3, 2, 1];
    $view  = $pdo->prepare('INSERT INTO views (user_id, referrer, device, ip_hash, created_at) VALUES (?, ?, ?, ?, ?)');
    $click = $pdo->prepare('INSERT INTO clicks (link_id, user_id, referrer, device, ip_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $visitors = array_map(fn() => bin2hex(random_bytes(6)), range(1, 520));
    for ($d = 29; $d >= 0; $d--) {
        $dayViews = random_int(14, 26) + (int) ((29 - $d) * 0.9) + ($d % 7 === 2 ? 18 : 0);
        for ($v = 0; $v < $dayViews; $v++) {
            $ts  = gmdate('Y-m-d H:i:s', time() - $d * 86400 - random_int(0, 86000));
            $ref = $refs[array_rand($refs)];
            $dev = $devices[array_rand($devices)];
            $ip  = $visitors[array_rand($visitors)];
            $view->execute([$uid, $ref, $dev, $ip, $ts]);
            if (random_int(1, 100) <= 46) {
                $pick = random_int(1, array_sum($weights));
                foreach ($weights as $idx => $w) {
                    $pick -= $w;
                    if ($pick <= 0) {
                        break;
                    }
                }
                $click->execute([$linkIds[$idx], $uid, $ref, $dev, $ip, $ts]);
            }
        }
    }
    $pdo->commit();
}

// ============================================================================
// 3. Core helpers
// ============================================================================
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/');
}

/** Absolute origin. Prefers configured URL so a spoofed Host header can't poison emailed links. */
function origin(): string
{
    if ($configured = getenv('BRANCHIFY_URL')) {
        return rtrim($configured, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/i', $host) && !preg_match('/^\[[0-9a-f:]+\](:\d{1,5})?$/i', $host)) {
        $host = 'localhost';
    }
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

function url(string $page = '', array $params = []): string
{
    if ($page !== '') {
        $params = ['page' => $page] + $params;
    }
    return base_path() . '/' . ($params ? '?' . http_build_query($params) : '');
}

function pretty_urls(): bool
{
    return PHP_SAPI === 'cli-server' || getenv('BRANCHIFY_PRETTY') === '1';
}

function profile_path(string $username): string
{
    return pretty_urls() ? base_path() . '/' . rawurlencode($username) : url('', ['u' => $username]);
}

function profile_url(string $username): string
{
    return origin() . substr(profile_path($username), strlen(base_path()));
}

function profile_display(string $username): string
{
    return preg_replace('~^https?://~', '', profile_url($username));
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && hash_equals(csrf_token(), $sent);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function remember_input(array $fields): void
{
    $_SESSION['old'] = array_intersect_key($_POST, array_flip($fields));
}

function old(string $key, string $default = ''): string
{
    return e($GLOBALS['OLD'][$key] ?? $default);
}

function is_fetch(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/** One response helper for both classic form posts and fetch()-enhanced ones. */
function respond(bool $ok, string $message, string $redirectTo, array $extra = []): never
{
    if (is_fetch()) {
        http_response_code($ok ? 200 : 422);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
        exit;
    }
    if ($message !== '') {
        flash($ok ? 'success' : 'error', $message);
    }
    redirect($redirectTo);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Daily-rotating keyed hash: lets us de-duplicate visits without storing IPs. */
function visitor_hash(): string
{
    return substr(hash_hmac('sha256', client_ip() . '|' . gmdate('Y-m-d') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''), app_secret()), 0, 32);
}

function is_bot(): bool
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|headless/', $ua) === 1;
}

function device_type(): string
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return match (true) {
        str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
        str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android') => 'mobile',
        default => 'desktop',
    };
}

function referrer_label(?string $ref = null): string
{
    $host = strtolower((string) parse_url($ref ?? ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
    if ($host === '' || $host === strtolower((string) parse_url(origin(), PHP_URL_HOST))) {
        return 'Direct';
    }
    $host = preg_replace('/^(www|m|l|lm)\./', '', $host);
    return match (true) {
        str_contains($host, 'instagram')                          => 'Instagram',
        str_contains($host, 'tiktok')                             => 'TikTok',
        str_contains($host, 'youtube') || $host === 'youtu.be'    => 'YouTube',
        $host === 't.co' || $host === 'x.com' || $host === 'twitter.com' => 'X',
        str_contains($host, 'facebook')                           => 'Facebook',
        str_contains($host, 'linkedin')                           => 'LinkedIn',
        str_contains($host, 'google.')                            => 'Google',
        default                                                   => mb_substr($host, 0, 60),
    };
}

/** Reports whether a bucket already holds $max attempts inside the window; optionally records this one. */
function rate_limited(string $bucket, int $max, int $windowSeconds, bool $record = true): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM rate_limits WHERE created_at < ?')->execute([time() - 86400]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at >= ?');
    $stmt->execute([$bucket, time() - $windowSeconds]);
    $limited = (int) $stmt->fetchColumn() >= $max;
    if ($record && !$limited) {
        record_attempt($bucket);
    }
    return $limited;
}

function record_attempt(string $bucket): void
{
    db()->prepare('INSERT INTO rate_limits (bucket, created_at) VALUES (?, ?)')->execute([$bucket, time()]);
}

function clear_rate_limit(string $bucket): void
{
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]);
}

/** Every outgoing email lands in the outbox table + data/mail.log; the dev mail catcher reads the table. */
function send_mail(string $to, string $subject, string $text, string $html): void
{
    db()->prepare('INSERT INTO outbox (recipient, subject, body_text, body_html) VALUES (?, ?, ?, ?)')
        ->execute([$to, $subject, $text, $html]);
    $entry = sprintf("==== %s ====\nTo: %s\nSubject: %s\n\n%s\n\n", gmdate('c'), $to, $subject, $text);
    file_put_contents(MAIL_LOG, $entry, FILE_APPEND | LOCK_EX);
    if (getenv('BRANCHIFY_MAIL') === 'mail') {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . APP_NAME . " <no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">";
        @mail($to, $subject, $html, $headers);
    }
}

function email_template(string $heading, string $intro, string $buttonLabel = '', string $buttonUrl = '', string $footnote = ''): string
{
    $btn = $buttonUrl === '' ? '' : '<p style="margin:28px 0"><a href="' . e($buttonUrl) . '" style="background:#3B2EF0;color:#fff;padding:13px 22px;border-radius:10px;text-decoration:none;font-weight:700;display:inline-block;box-shadow:3px 3px 0 #F0478F">' . e($buttonLabel) . '</a></p>'
        . '<p style="font-size:13px;color:#57547F">Or paste this address into your browser:<br><span style="word-break:break-all;color:#3B2EF0">' . e($buttonUrl) . '</span></p>';
    return '<div style="background:#EDEFF6;padding:32px 16px;font-family:Helvetica,Arial,sans-serif">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border:2px solid #1B1650;border-radius:14px;padding:32px;color:#1B1650">'
        . '<p style="margin:0 0 24px;font-weight:800;font-size:18px">' . APP_NAME . '</p>'
        . '<h1 style="font-size:24px;margin:0 0 12px">' . e($heading) . '</h1>'
        . '<p style="font-size:15px;line-height:1.55;margin:0">' . $intro . '</p>' . $btn
        . ($footnote ? '<p style="font-size:13px;color:#57547F;border-top:1px solid #DADDEB;padding-top:16px;margin-top:28px">' . e($footnote) . '</p>' : '')
        . '</div></div>';
}

// ============================================================================
// 4. Authentication
// ============================================================================
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (!empty($_SESSION['uid'])) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            unset($_SESSION['uid']);
        }
    } elseif (!empty($_COOKIE['bf_remember'])) {
        $user = login_from_remember_cookie();
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        if (is_fetch()) {
            respond(false, 'Your session ended. Log in again.', url('login'));
        }
        flash('error', 'Log in to continue.');
        redirect(url('login'));
    }
    return $user;
}

function login_user(int $userId, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['csrf']);
    if ($remember) {
        issue_remember_token($userId);
    }
}

function issue_remember_token(int $userId): void
{
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + 60 * 60 * 24 * 30;
    db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $selector, hash('sha256', $validator), gmdate('Y-m-d H:i:s', $expires)]);
    setcookie('bf_remember', $selector . ':' . $validator, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function forget_remember_cookie(): void
{
    if (!empty($_COOKIE['bf_remember'])) {
        [$selector] = explode(':', (string) $_COOKIE['bf_remember'], 2) + [''];
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }
    setcookie('bf_remember', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function login_from_remember_cookie(): ?array
{
    $parts = explode(':', (string) $_COOKIE['bf_remember'], 2);
    if (count($parts) !== 2) {
        forget_remember_cookie();
        return null;
    }
    [$selector, $validator] = $parts;
    $stmt = db()->prepare("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > datetime('now')");
    $stmt->execute([$selector]);
    $token = $stmt->fetch();
    if (!$token || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        forget_remember_cookie();
        return null;
    }
    // Rotate on use so a stolen cookie only works once.
    db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$token['id']]);
    login_user((int) $token['user_id'], true);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$token['user_id']]);
    return $stmt->fetch() ?: null;
}

function logout_user(): void
{
    forget_remember_cookie();
    $_SESSION = [];
    session_regenerate_id(true); // drops the old session server-side and issues a fresh, empty one
}

// ============================================================================
// 5. Validation & domain data
// ============================================================================
function username_error(string $username): ?string
{
    if (!preg_match('/^[a-z0-9_.]{3,24}$/', $username) || str_starts_with($username, '.') || str_ends_with($username, '.')) {
        return 'Usernames use 3–24 lowercase letters, numbers, underscores or dots, and can’t start or end with a dot.';
    }
    if (in_array($username, RESERVED_USERNAMES, true)) {
        return 'That username is reserved. Try another.';
    }
    return null;
}

function password_error(string $password): ?string
{
    if (strlen($password) < MIN_PASSWORD) {
        return 'Passwords need at least ' . MIN_PASSWORD . ' characters.';
    }
    if (strlen($password) > 200) {
        return 'Passwords can be at most 200 characters.';
    }
    return null;
}

/** Accepts http(s), mailto: and tel:, adds https:// to bare domains, rejects everything else (javascript:, data:, …). */
function normalize_url(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 2000) {
        return null;
    }
    if (preg_match('/^mailto:/i', $raw)) {
        return filter_var(substr($raw, 7), FILTER_VALIDATE_EMAIL) ? 'mailto:' . substr($raw, 7) : null;
    }
    if (preg_match('/^tel:\+?[0-9 ()\-]{4,20}$/i', $raw)) {
        return 'tel:' . preg_replace('/[^0-9+]/', '', substr($raw, 4));
    }
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $raw)) {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $raw) && !preg_match('/^[^:\/]+\.[^:\/]+:\d/', $raw)) {
            return null; // some other scheme, e.g. javascript:
        }
        $raw = 'https://' . $raw;
    }
    if (!preg_match('~^https?://~i', $raw) || !filter_var($raw, FILTER_VALIDATE_URL)) {
        return null;
    }
    $host = (string) parse_url($raw, PHP_URL_HOST);
    return str_contains($host, '.') || $host === 'localhost' ? $raw : null;
}

function themes(): array
{
    return [
        'riso'      => ['Riso',       ['bg' => '#EDEFF6', 'fg' => '#1B1650', 'muted' => '#57547F', 'btn' => '#FFFFFF', 'btnfg' => '#1B1650', 'edge' => '#1B1650', 'accent' => '#F0478F']],
        'moss'      => ['Moss',       ['bg' => '#17352A', 'fg' => '#F4E9DA', 'muted' => '#B5C4B1', 'btn' => '#F4E9DA', 'btnfg' => '#17352A', 'edge' => '#F4E9DA', 'accent' => '#E8A0B4']],
        'tangerine' => ['Tangerine',  ['bg' => '#FF7A1A', 'fg' => '#2A0E00', 'muted' => '#5C2B08', 'btn' => '#2A0E00', 'btnfg' => '#FFE9D6', 'edge' => '#2A0E00', 'accent' => '#FFF4E8']],
        'blueprint' => ['Blueprint',  ['bg' => '#1D3FBF', 'fg' => '#FFFFFF', 'muted' => '#C9D3FF', 'btn' => '#FFFFFF', 'btnfg' => '#1D3FBF', 'edge' => '#FFFFFF', 'accent' => '#8FB2FF']],
        'chalk'     => ['Chalk',      ['bg' => '#FAFAF7', 'fg' => '#000000', 'muted' => '#5E5E5A', 'btn' => '#FFFFFF', 'btnfg' => '#000000', 'edge' => '#000000', 'accent' => '#000000']],
        'bubblegum' => ['Bubblegum',  ['bg' => '#FFC6E0', 'fg' => '#6B1E4A', 'muted' => '#8E4470', 'btn' => '#FFFFFF', 'btnfg' => '#6B1E4A', 'edge' => '#6B1E4A', 'accent' => '#6B1E4A']],
        'dusk'      => ['Dusk',       ['bg' => '#3A1466', 'fg' => '#FFFFFF', 'muted' => '#F2CBE7', 'btn' => '#FFFFFF', 'btnfg' => '#3A1466', 'edge' => '#FFFFFF', 'accent' => '#FFD447']],
        'concrete'  => ['Concrete',   ['bg' => '#CFCFCA', 'fg' => '#1A1A1A', 'muted' => '#4B4B47', 'btn' => '#1A1A1A', 'btnfg' => '#F2F2EE', 'edge' => '#1A1A1A', 'accent' => '#FF5A1F']],
    ];
}

function social_platforms(): array
{
    // key => [label, simple-icons slug, placeholder, URL prefix for bare handles]
    return [
        'instagram' => ['Instagram', 'instagram', '@yourname',             'https://instagram.com/'],
        'tiktok'    => ['TikTok',    'tiktok',    '@yourname',             'https://tiktok.com/@'],
        'youtube'   => ['YouTube',   'youtube',   '@yourchannel',          'https://youtube.com/@'],
        'x'         => ['X',         'x',         '@yourname',             'https://x.com/'],
        'threads'   => ['Threads',   'threads',   '@yourname',             'https://threads.net/@'],
        'bluesky'   => ['Bluesky',   'bluesky',   'you.bsky.social',       'https://bsky.app/profile/'],
        'github'    => ['GitHub',    'github',    'yourname',              'https://github.com/'],
        'linkedin'  => ['LinkedIn',  'linkedin',  'linkedin.com/in/you',   'https://linkedin.com/in/'],
        'spotify'   => ['Spotify',   'spotify',   'open.spotify.com/…',    ''],
        'twitch'    => ['Twitch',    'twitch',    'yourchannel',           'https://twitch.tv/'],
        'email'     => ['Email',     '',          'you@example.com',       'mailto:'],
    ];
}

function avatar_src(array $user): string
{
    return $user['avatar'] !== '' ? url('', ['avatar' => $user['avatar']]) : '';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

function user_links(int $userId, bool $activeOnly = false): array
{
    $sql = 'SELECT l.*, (SELECT COUNT(*) FROM clicks c WHERE c.link_id = l.id) AS clicks
            FROM links l WHERE l.user_id = ?' . ($activeOnly ? ' AND l.is_active = 1' : '') . ' ORDER BY l.position, l.id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ============================================================================
// 6. Routing
// ============================================================================
$GLOBALS['OLD'] = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rel  = rawurldecode(substr($path, strlen(base_path())) ?: '/');

// Avatars are stored outside any public folder and streamed through here.
if (isset($_GET['avatar'])) {
    serve_avatar((string) $_GET['avatar']);
}
if ($rel === '/favicon.ico') {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo logo_svg(true);
    exit;
}
// Pretty profile URLs: /juno or /@juno (router mode).
if (!in_array($rel, ['/', '', '/index.php'], true)) {
    if (preg_match('~^/@?([A-Za-z0-9_.]{3,24})/?$~', $rel, $m)) {
        show_profile(strtolower($m[1]));
    }
    render_not_found();
}
if (isset($_GET['r'])) {
    track_and_redirect((int) $_GET['r']);
}
if (isset($_GET['u'])) {
    show_profile(strtolower(trim((string) $_GET['u'])));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dispatch_action((string) ($_POST['_action'] ?? ''));
}

$page = (string) ($_GET['page'] ?? '');
$user = current_user();
$guestOnly = ['login', 'register', 'forgot', 'reset'];
if ($user && in_array($page, $guestOnly, true)) {
    redirect(url('links'));
}

match ($page) {
    ''           => $user ? redirect(url('links')) : page_home(),
    'login'      => page_login(),
    'register'   => page_register(),
    'forgot'     => page_forgot(),
    'reset'      => page_reset(),
    'mail'       => page_mail(),
    'links'      => page_links(require_user()),
    'appearance' => page_appearance(require_user()),
    'analytics'  => page_analytics(require_user()),
    'settings'   => page_settings(require_user()),
    default      => render_not_found(),
};
exit;

// ============================================================================
// 7. Actions (POST)
// ============================================================================
function dispatch_action(string $action): never
{
    if (!csrf_valid()) {
        respond(false, 'This form expired. Reload the page and try again.', url(current_user() ? 'links' : 'login'));
    }
    $handlers = [
        'register' => 'action_register', 'login' => 'action_login', 'logout' => 'action_logout',
        'demo_login' => 'action_demo_login', 'forgot' => 'action_forgot', 'reset' => 'action_reset',
        'link_add' => 'action_link_add', 'link_update' => 'action_link_update', 'link_toggle' => 'action_link_toggle',
        'link_delete' => 'action_link_delete', 'link_move' => 'action_link_move', 'links_reorder' => 'action_links_reorder',
        'profile_save' => 'action_profile_save', 'avatar_upload' => 'action_avatar_upload', 'avatar_remove' => 'action_avatar_remove',
        'design_save' => 'action_design_save', 'socials_save' => 'action_socials_save',
        'account_save' => 'action_account_save', 'password_save' => 'action_password_save', 'account_delete' => 'action_account_delete',
        'mail_clear' => 'action_mail_clear',
    ];
    if (!isset($handlers[$action])) {
        respond(false, 'Unknown action.', url());
    }
    $handlers[$action]();
    exit;
}

// --- Auth -------------------------------------------------------------------
function action_register(): never
{
    remember_input(['email', 'username', 'display_name']);
    $back     = url('register');
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $name     = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (($_POST['website'] ?? '') !== '') { // honeypot
        respond(true, '', url());
    }
    if (rate_limited('register:' . client_ip(), 5, 3600)) {
        respond(false, 'Too many sign-ups from this network. Try again in an hour.', $back);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Enter a valid email address.', $back);
    }
    if ($err = username_error($username)) {
        respond(false, $err, $back);
    }
    if ($name === '' || mb_strlen($name) > 60) {
        respond(false, 'Add a display name of up to 60 characters.', $back);
    }
    if ($err = password_error($password)) {
        respond(false, $err, $back);
    }
    $stmt = db()->prepare('SELECT email, username FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    if ($taken = $stmt->fetch()) {
        respond(false, strcasecmp($taken['username'], $username) === 0
            ? "@{$username} is already taken. Try another username."
            : 'An account with that email already exists. Log in or reset your password.', $back);
    }

    $pdo = db();
    $pdo->prepare('INSERT INTO users (email, username, password_hash, display_name) VALUES (?, ?, ?, ?)')
        ->execute([$email, $username, password_hash($password, PASSWORD_DEFAULT), $name]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO links (user_id, kind, title, url, position) VALUES (?, 'link', ?, ?, 1)")
        ->execute([$uid, 'My website', 'https://example.com']);

    send_mail($email, 'Welcome to ' . APP_NAME, "Hi {$name},\n\nYour page is live at " . profile_url($username) . "\n\nAdd links, pick a theme, then share it anywhere.",
        email_template('Your page is live', 'Hi ' . e($name) . ', your page is ready at <strong>' . e(profile_display($username)) . '</strong>. Add links, pick a theme, then share it anywhere.', 'Open my page', profile_url($username)));

    unset($_SESSION['old']);
    login_user($uid, false);
    respond(true, 'Your page is live. Add your first links below.', url('links'));
}

function action_login(): never
{
    remember_input(['login']);
    $back     = url('login');
    $login    = strtolower(trim((string) ($_POST['login'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $bucket   = 'login:' . hash('sha256', $login);

    if ($login === '' || $password === '') {
        respond(false, 'Enter your email or username and your password.', $back);
    }
    if (rate_limited('login-ip:' . client_ip(), 30, 900, false) || rate_limited($bucket, 5, 900, false)) {
        respond(false, 'Too many failed attempts. Wait 15 minutes or reset your password.', $back);
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();
    // Verify against a throwaway hash when the user doesn't exist so response time doesn't reveal accounts.
    $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$user) {
        record_attempt($bucket);
        record_attempt('login-ip:' . client_ip());
        respond(false, 'That email/username and password don’t match.', $back);
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    clear_rate_limit($bucket);
    unset($_SESSION['old']);
    login_user((int) $user['id'], !empty($_POST['remember']));
    respond(true, 'Welcome back, ' . $user['display_name'] . '.', url('links'));
}

function action_demo_login(): never
{
    if (!IS_DEV) {
        respond(false, 'The demo account is only available in development mode.', url('login'));
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([DEMO_EMAIL]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        respond(false, 'The demo account was deleted. Delete data/branchify.sqlite to re-seed it.', url('login'));
    }
    login_user((int) $id, false);
    respond(true, 'You’re in the demo account. Change anything you like.', url('links'));
}

function action_logout(): never
{
    logout_user();
    respond(true, 'You’re logged out.', url('login'));
}

function action_forgot(): never
{
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    remember_input(['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Enter the email address you signed up with.', url('forgot'));
    }
    if (rate_limited('forgot:' . client_ip(), 5, 900) || rate_limited('forgot:' . $email, 3, 900)) {
        respond(false, 'Too many reset requests. Try again in 15 minutes.', url('forgot'));
    }
    $stmt = db()->prepare('SELECT id, display_name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($user = $stmt->fetch()) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
        db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$user['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $link = origin() . '/?' . http_build_query(['page' => 'reset', 'token' => $token]);
        send_mail($email, 'Reset your ' . APP_NAME . ' password',
            "Hi {$user['display_name']},\n\nReset your password here (valid for 1 hour):\n{$link}\n\nIf you didn't ask for this, ignore this email.",
            email_template('Reset your password', 'Hi ' . e($user['display_name']) . ', someone asked to reset the password for this account. The link works once and expires in 1 hour.', 'Choose a new password', $link, 'If you didn’t ask for this, ignore this email. Your password stays the same.'));
    }
    // Same response whether or not the account exists: no account enumeration.
    unset($_SESSION['old']);
    $_SESSION['reset_sent_to'] = $email;
    redirect(url('forgot', ['sent' => 1]));
}

function find_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare("SELECT r.*, u.email, u.display_name FROM password_resets r JOIN users u ON u.id = r.user_id
                           WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > datetime('now')");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function action_reset(): never
{
    $token = (string) ($_POST['token'] ?? '');
    $back  = url('reset', ['token' => $token]);
    $reset = find_reset($token);
    if (!$reset) {
        respond(false, 'This reset link has expired or was already used. Request a new one.', url('forgot'));
    }
    $password = (string) ($_POST['password'] ?? '');
    if ($err = password_error($password)) {
        respond(false, $err, $back);
    }
    if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        respond(false, 'The two passwords don’t match.', $back);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    $pdo->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = ?")->execute([$reset['id']]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$reset['user_id']]);
    $pdo->commit();
    clear_rate_limit('login:' . hash('sha256', strtolower($reset['email'])));
    send_mail($reset['email'], 'Your ' . APP_NAME . ' password was changed', "Your password was just changed. If this wasn't you, reset it again right away.",
        email_template('Password changed', 'The password for ' . e($reset['email']) . ' was just changed. If this wasn’t you, reset it again right away.'));
    respond(true, 'Password updated. Log in with your new password.', url('login'));
}

// --- Links ------------------------------------------------------------------
function link_input(): array
{
    $kind  = ($_POST['kind'] ?? 'link') === 'header' ? 'header' : 'link';
    $title = trim((string) ($_POST['title'] ?? ''));
    $emoji = trim((string) ($_POST['emoji'] ?? ''));
    $url   = '';
    if ($title === '' || mb_strlen($title) > 100) {
        respond(false, $kind === 'header' ? 'Give the header a title of up to 100 characters.' : 'Give the link a title of up to 100 characters.', url('links'));
    }
    if (mb_strlen($emoji) > 8) {
        respond(false, 'Use a single emoji for the icon.', url('links'));
    }
    if ($kind === 'link') {
        $url = normalize_url((string) ($_POST['url'] ?? ''));
        if ($url === null) {
            respond(false, 'Enter a web address (https://…), an email (mailto:…) or a phone number (tel:…).', url('links'));
        }
    }
    return [$kind, $title, $url, $kind === 'link' ? $emoji : '', $kind === 'link' && !empty($_POST['featured']) ? 1 : 0];
}

function action_link_add(): never
{
    $user = require_user();
    [$kind, $title, $url, $emoji, $featured] = link_input();
    $stmt = db()->prepare('SELECT COUNT(*) FROM links WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    if ((int) $stmt->fetchColumn() >= 100) {
        respond(false, 'You’ve reached 100 links. Remove some to add more.', url('links'));
    }
    // New items go to the top, like most link-in-bio tools.
    db()->prepare('UPDATE links SET position = position + 1 WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('INSERT INTO links (user_id, kind, title, url, emoji, featured, position) VALUES (?, ?, ?, ?, ?, ?, 0)')
        ->execute([$user['id'], $kind, $title, $url, $emoji, $featured]);
    respond(true, $kind === 'header' ? 'Header added.' : 'Link added to the top of your page.', url('links'));
}

function action_link_update(): never
{
    $user = require_user();
    [$kind, $title, $url, $emoji, $featured] = link_input();
    $stmt = db()->prepare('UPDATE links SET kind = ?, title = ?, url = ?, emoji = ?, featured = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$kind, $title, $url, $emoji, $featured, (int) ($_POST['id'] ?? 0), $user['id']]);
    respond($stmt->rowCount() > 0, $stmt->rowCount() ? 'Changes saved.' : 'That link no longer exists.', url('links'));
}

function action_link_toggle(): never
{
    $user = require_user();
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $stmt = db()->prepare('UPDATE links SET is_active = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$active, (int) ($_POST['id'] ?? 0), $user['id']]);
    respond($stmt->rowCount() > 0, $active ? 'Shown on your page.' : 'Hidden from your page.', url('links'));
}

function action_link_delete(): never
{
    $user = require_user();
    $stmt = db()->prepare('DELETE FROM links WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) ($_POST['id'] ?? 0), $user['id']]);
    respond($stmt->rowCount() > 0, 'Deleted.', url('links'), ['removed' => true]);
}

function save_order(int $userId, array $ids): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE links SET position = ? WHERE id = ? AND user_id = ?');
    foreach (array_values($ids) as $pos => $id) {
        $upd->execute([$pos + 1, (int) $id, $userId]);
    }
    $pdo->commit();
}

function action_link_move(): never
{
    $user = require_user();
    $ids  = array_map(fn($l) => (int) $l['id'], user_links((int) $user['id']));
    $idx  = array_search((int) ($_POST['id'] ?? 0), $ids, true);
    $swap = ($_POST['direction'] ?? '') === 'up' ? $idx - 1 : $idx + 1;
    if ($idx !== false && isset($ids[$swap])) {
        [$ids[$idx], $ids[$swap]] = [$ids[$swap], $ids[$idx]];
        save_order((int) $user['id'], $ids);
    }
    respond(true, '', url('links'));
}

function action_links_reorder(): never
{
    $user = require_user();
    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    save_order((int) $user['id'], $ids);
    respond(true, 'Order saved.', url('links'));
}

// --- Appearance -------------------------------------------------------------
function action_profile_save(): never
{
    $user = require_user();
    $name = trim((string) ($_POST['display_name'] ?? ''));
    $bio  = trim(str_replace("\r\n", "\n", (string) ($_POST['bio'] ?? '')));
    if ($name === '' || mb_strlen($name) > 60) {
        respond(false, 'Your display name needs 1–60 characters.', url('appearance'));
    }
    if (mb_strlen($bio) > 160) {
        respond(false, 'Keep your bio to 160 characters.', url('appearance'));
    }
    db()->prepare("UPDATE users SET display_name = ?, bio = ?, updated_at = datetime('now') WHERE id = ?")->execute([$name, $bio, $user['id']]);
    respond(true, 'Profile saved.', url('appearance'));
}

function action_design_save(): never
{
    $user  = require_user();
    $theme = (string) ($_POST['theme'] ?? '');
    $style = (string) ($_POST['button_style'] ?? '');
    $shape = (string) ($_POST['button_shape'] ?? '');
    $font  = (string) ($_POST['font'] ?? '');
    if (!isset(themes()[$theme], BUTTON_STYLES[$style], BUTTON_SHAPES[$shape], FONTS[$font])) {
        respond(false, 'Pick a theme, button style, shape and font from the lists.', url('appearance'));
    }
    db()->prepare("UPDATE users SET theme = ?, button_style = ?, button_shape = ?, font = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$theme, $style, $shape, $font, $user['id']]);
    respond(true, 'Design saved.', url('appearance'));
}

function action_socials_save(): never
{
    $user = require_user();
    $out = [];
    foreach (social_platforms() as $key => [$label, , , $prefix]) {
        $val = trim((string) ($_POST['social'][$key] ?? ''));
        if ($val === '') {
            continue;
        }
        if ($key === 'email') {
            $val = preg_replace('/^mailto:/i', '', $val);
            if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                respond(false, 'Enter a valid email address for the email icon.', url('appearance'));
            }
            $out[$key] = 'mailto:' . $val;
            continue;
        }
        $looksLikeUrl = str_contains($val, '/') || (str_contains($val, '.') && !str_starts_with($val, '@') && $key !== 'bluesky');
        if (!$looksLikeUrl && $prefix !== '') {
            $val = $prefix . ltrim($val, '@');
        }
        $normalized = normalize_url($val);
        if ($normalized === null) {
            respond(false, "Check the {$label} address. Use your handle or the full profile URL.", url('appearance'));
        }
        $out[$key] = $normalized;
    }
    db()->prepare("UPDATE users SET socials = ?, updated_at = datetime('now') WHERE id = ?")->execute([json_encode($out), $user['id']]);
    respond(true, 'Social icons saved.', url('appearance'));
}

function action_avatar_upload(): never
{
    $user = require_user();
    $back = url('appearance');
    $file = $_FILES['avatar'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(false, ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That image is too large. Use one under 4 MB.' : 'Choose an image to upload.', $back);
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        respond(false, 'That image is too large. Use one under 4 MB.', $back);
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || $info[0] * $info[1] > 40_000_000) {
        respond(false, 'Upload a JPG, PNG, WebP or GIF image.', $back);
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        respond(false, 'That image couldn’t be read. Try a different file.', $back);
    }
    // Re-encode to a 400×400 centre crop: strips metadata and any smuggled payloads.
    $side = min($info[0], $info[1]);
    $dst = imagecreatetruecolor(400, 400);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int) (($info[0] - $side) / 2), (int) (($info[1] - $side) / 2), 400, 400, $side, $side);
    $name = $user['id'] . '-' . bin2hex(random_bytes(6)) . '.webp';
    imagewebp($dst, AVATAR_DIR . '/' . $name, 86);
    delete_avatar_file($user['avatar']);
    db()->prepare("UPDATE users SET avatar = ?, updated_at = datetime('now') WHERE id = ?")->execute([$name, $user['id']]);
    respond(true, 'Profile photo updated.', $back);
}

function action_avatar_remove(): never
{
    $user = require_user();
    delete_avatar_file($user['avatar']);
    db()->prepare("UPDATE users SET avatar = '' WHERE id = ?")->execute([$user['id']]);
    respond(true, 'Profile photo removed.', url('appearance'));
}

function delete_avatar_file(string $name): void
{
    if ($name !== '' && preg_match('/^[0-9]+-[a-f0-9]{12}\.webp$/', $name) && is_file(AVATAR_DIR . '/' . $name)) {
        unlink(AVATAR_DIR . '/' . $name);
    }
}

function serve_avatar(string $name): never
{
    if (!preg_match('/^[0-9]+-[a-f0-9]{12}\.webp$/', $name) || !is_file(AVATAR_DIR . '/' . $name)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile(AVATAR_DIR . '/' . $name);
    exit;
}

// --- Account ----------------------------------------------------------------
function action_account_save(): never
{
    $user     = require_user();
    $back     = url('settings');
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Enter a valid email address.', $back);
    }
    if ($err = username_error($username)) {
        respond(false, $err, $back);
    }
    if (strcasecmp($email, $user['email']) !== 0 && !password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        respond(false, 'Enter your current password to change your email.', $back);
    }
    $stmt = db()->prepare('SELECT username FROM users WHERE (email = ? OR username = ?) AND id != ?');
    $stmt->execute([$email, $username, $user['id']]);
    if ($taken = $stmt->fetch()) {
        respond(false, strcasecmp($taken['username'], $username) === 0 ? "@{$username} is taken. Try another." : 'Another account already uses that email.', $back);
    }
    if (strcasecmp($email, $user['email']) !== 0) {
        send_mail($user['email'], 'Your ' . APP_NAME . ' email was changed', "Your account email was changed to {$email}. If this wasn't you, contact support.",
            email_template('Email address changed', 'Your account email was changed to <strong>' . e($email) . '</strong>. If this wasn’t you, reset your password right away.'));
    }
    db()->prepare("UPDATE users SET email = ?, username = ?, updated_at = datetime('now') WHERE id = ?")->execute([$email, $username, $user['id']]);
    respond(true, 'Account saved.', $back);
}

function action_password_save(): never
{
    $user = require_user();
    $back = url('settings');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        respond(false, 'Your current password isn’t right.', $back);
    }
    $new = (string) ($_POST['new_password'] ?? '');
    if ($err = password_error($new)) {
        respond(false, $err, $back);
    }
    if ($new !== (string) ($_POST['new_password_confirm'] ?? '')) {
        respond(false, 'The two new passwords don’t match.', $back);
    }
    db()->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now') WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$user['id']]);
    login_user((int) $user['id'], false); // fresh session id after a credential change
    respond(true, 'Password changed. Other devices have been signed out.', $back);
}

function action_account_delete(): never
{
    $user = require_user();
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        respond(false, 'Your password isn’t right, so nothing was deleted.', url('settings'));
    }
    if (($_POST['confirm_username'] ?? '') !== $user['username']) {
        respond(false, 'Type your username exactly to confirm.', url('settings'));
    }
    delete_avatar_file($user['avatar']);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
    logout_user();
    respond(true, 'Your account and page were deleted.', url());
}

function action_mail_clear(): never
{
    if (!IS_DEV) {
        respond(false, 'Not available.', url());
    }
    db()->exec('DELETE FROM outbox');
    respond(true, 'Mail catcher emptied.', url('mail'));
}

// ============================================================================
// 8. Public profile & click tracking
// ============================================================================
function find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function show_profile(string $username): never
{
    $profile = find_user_by_username($username);
    if (!$profile) {
        render_not_found($username);
    }
    $preview = ($_GET['preview'] ?? '') === '1';
    $viewer  = current_user();
    $isOwner = $viewer && (int) $viewer['id'] === (int) $profile['id'];

    if (!$preview && !$isOwner && !is_bot()) {
        $hash = visitor_hash();
        $recent = db()->prepare("SELECT 1 FROM views WHERE user_id = ? AND ip_hash = ? AND created_at > datetime('now', '-30 minutes')");
        $recent->execute([$profile['id'], $hash]);
        if (!$recent->fetchColumn()) {
            db()->prepare('INSERT INTO views (user_id, referrer, device, ip_hash) VALUES (?, ?, ?, ?)')
                ->execute([$profile['id'], referrer_label(), device_type(), $hash]);
        }
    }
    render_profile($profile, user_links((int) $profile['id'], true), $preview, $isOwner);
    exit;
}

function track_and_redirect(int $linkId): never
{
    $stmt = db()->prepare("SELECT * FROM links WHERE id = ? AND is_active = 1 AND kind = 'link'");
    $stmt->execute([$linkId]);
    $link = $stmt->fetch();
    if (!$link) {
        render_not_found();
    }
    $viewer = current_user();
    $fromPreview = str_contains((string) ($_SERVER['HTTP_REFERER'] ?? ''), 'preview=1');
    if (!is_bot() && !$fromPreview && (!$viewer || (int) $viewer['id'] !== (int) $link['user_id'])) {
        // Attribute the click to where the *profile* visit came from, not to our own page.
        $refStmt = db()->prepare("SELECT referrer FROM views WHERE user_id = ? AND ip_hash = ? ORDER BY id DESC LIMIT 1");
        $hash = visitor_hash();
        $refStmt->execute([$link['user_id'], $hash]);
        db()->prepare('INSERT INTO clicks (link_id, user_id, referrer, device, ip_hash) VALUES (?, ?, ?, ?, ?)')
            ->execute([$link['id'], $link['user_id'], $refStmt->fetchColumn() ?: 'Direct', device_type(), $hash]);
    }
    header('Cache-Control: no-store');
    header('Location: ' . $link['url'], true, 302);
    exit;
}

// ============================================================================
// 9. Shared view pieces
// ============================================================================
function logo_svg(bool $standalone = false, int $size = 28): string
{
    $ns = $standalone ? ' xmlns="http://www.w3.org/2000/svg"' : '';
    return '<svg' . $ns . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true">'
        . '<path d="M9 29V5M9 20c0-6 4-9 13-9M9 26c0-3.5 3-5.5 9-5.5" fill="none" stroke="#1B1650" stroke-width="3.2" stroke-linecap="round"/>'
        . '<circle cx="9" cy="5" r="3.6" fill="#F0478F"/><circle cx="23" cy="11" r="3.6" fill="#3B2EF0"/><circle cx="19" cy="20.5" r="3.6" fill="#FFD447" stroke="#1B1650" stroke-width="1.4"/></svg>';
}

function icon(string $name, int $size = 18): string
{
    $p = [
        'links'    => '<path d="M5 4v16M5 9c0-3 3-4 9-4M5 15c0-2.5 3-3.5 8-3.5"/><circle cx="17" cy="5" r="2"/><circle cx="16" cy="11.5" r="2"/>',
        'palette'  => '<path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-2 0-.6-.2-1-.5-1.4-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.3c2 0 3.7-1.7 3.7-3.7C21 6.4 17 3 12 3Z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10.5" cy="7" r="1.2"/><circle cx="15" cy="7.5" r="1.2"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'grip'     => '<circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/>',
        'up'       => '<path d="m6 15 6-6 6 6"/>',
        'down'     => '<path d="m6 9 6 6 6-6"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'pencil'   => '<path d="M4 20h4L19 9l-4-4L4 16v4ZM14 6l4 4"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'share'    => '<path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/>',
        'qr'       => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/>',
        'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'logout'   => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l4-4-4-4M14 12H4"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    ][$name] ?? '';
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function social_icon(string $key): string
{
    $slug = social_platforms()[$key][1] ?? '';
    if ($slug === '') {
        return icon('mail', 20);
    }
    return '<span class="si" style="--si:url(https://cdn.jsdelivr.net/npm/simple-icons@13.21.0/icons/' . e($slug) . '.svg)" aria-hidden="true"></span>';
}

function fonts_link(bool $profileFonts = false): string
{
    $families = 'family=Anybody:wdth,wght@50..150,400..900&family=Schibsted+Grotesk:wght@400..800';
    if ($profileFonts) {
        $families .= '&family=Young+Serif&family=DM+Mono:wght@400;500';
    }
    return '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' . $families . '&display=swap">';
}

function html_head(string $title, string $css, string $extra = '', string $htmlAttrs = ''): void
{
    ?><!doctype html>
<html lang="en"<?= $htmlAttrs ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode(logo_svg(true)) ?>">
<?= $extra ?>
<style><?= $css ?></style>
</head>
<?php
}

function notice_html(?array $flash): string
{
    if (!$flash) {
        return '';
    }
    $type = $flash['type'] === 'error' ? 'error' : 'success';
    return '<div class="notice notice--' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($flash['message']) . '</div>';
}

function avatar_html(array $user, string $class = 'avatar'): string
{
    $src = avatar_src($user);
    return $src
        ? '<img class="' . $class . '" src="' . e($src) . '" alt="" width="96" height="96">'
        : '<span class="' . $class . ' ' . strtok($class, ' ') . '--initials" aria-hidden="true">' . e(initials($user['display_name'])) . '</span>';
}

function render_not_found(string $username = ''): never
{
    http_response_code(404);
    html_head('Page not found · ' . APP_NAME, app_css(), fonts_link());
    ?>
<body class="solo">
<main class="solo__main">
    <a class="brand" href="<?= e(url()) ?>"><?= logo_svg() ?><span><?= APP_NAME ?></span></a>
    <h1 class="display display--xl">Nothing grows here yet.</h1>
    <?php if ($username !== ''): ?>
        <p class="lede">No one has claimed <strong>@<?= e($username) ?></strong>. It could be yours.</p>
        <p class="row"><a class="btn btn--primary" href="<?= e(url('register', ['username' => $username])) ?>">Claim @<?= e($username) ?></a>
        <a class="btn btn--ghost" href="<?= e(url()) ?>">Go to the homepage</a></p>
    <?php else: ?>
        <p class="lede">That page doesn’t exist or was removed.</p>
        <p class="row"><a class="btn btn--primary" href="<?= e(url()) ?>">Go to the homepage</a></p>
    <?php endif; ?>
</main>
</body></html>
    <?php
    exit;
}

// ============================================================================
// 10. Marketing & auth pages
// ============================================================================
function page_home(): void
{
    html_head(APP_NAME . ' · One link for everything you make', app_css(), fonts_link() . '<meta name="description" content="Make a single page for all your links. Free, fast, and yours to style.">');
    $prefix = profile_display('x');
    $prefix = substr($prefix, 0, -1);
    ?>
<body class="home">
<header class="topbar">
    <a class="brand" href="<?= e(url()) ?>"><?= logo_svg() ?><span><?= APP_NAME ?></span></a>
    <nav class="topbar__nav">
        <a href="<?= e(profile_path('juno')) ?>">See an example</a>
        <a href="<?= e(url('login')) ?>">Log in</a>
        <a class="btn btn--primary btn--sm" href="<?= e(url('register')) ?>">Sign up free</a>
    </nav>
</header>

<main>
<section class="hero">
    <div class="hero__copy">
        <h1 class="display display--hero">Everything you make, one link away.</h1>
        <p class="lede">Put your shop, your podcast, your bookings and your socials on one page. Drop the link in your bio and watch which branches people follow.</p>
        <form class="claim" method="get" action="<?= e(base_path() . '/') ?>">
            <input type="hidden" name="page" value="register">
            <label class="claim__field">
                <span class="claim__prefix"><?= e($prefix) ?></span>
                <input name="username" placeholder="yourname" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9_.]{3,24}" aria-label="Choose your username" required>
            </label>
            <button class="btn btn--primary" type="submit">Claim my link</button>
        </form>
        <p class="fineprint">Free while in beta. No card needed.</p>
    </div>
    <div class="hero__art" aria-hidden="true">
        <svg class="tree" viewBox="0 0 560 440">
            <g class="tree__paths" fill="none" stroke="#1B1650" stroke-width="3" stroke-linecap="round">
                <path pathLength="1" d="M150 220 C 225 220, 215 52, 300 52"/>
                <path pathLength="1" d="M150 220 C 230 220, 220 136, 300 136"/>
                <path pathLength="1" d="M150 220 L 300 220"/>
                <path pathLength="1" d="M150 220 C 230 220, 220 304, 300 304"/>
                <path pathLength="1" d="M150 220 C 225 220, 215 388, 300 388"/>
            </g>
            <g class="tree__root">
                <rect x="18" y="194" width="134" height="52" rx="26" fill="#3B2EF0"/>
                <text x="85" y="227" text-anchor="middle" fill="#fff">@juno</text>
            </g>
            <g class="tree__leaf" style="--i:0"><rect x="306" y="32" width="230" height="44" rx="12" fill="#fff" stroke="#1B1650" stroke-width="2.5"/><rect x="306" y="32" width="230" height="44" rx="12" fill="none" stroke="#F0478F" stroke-width="2.5" transform="translate(5 5)" opacity=".9"/><text x="326" y="60">Shop the glaze drop</text></g>
            <g class="tree__leaf" style="--i:1"><rect x="306" y="114" width="230" height="44" rx="12" fill="#F0478F"/><text x="326" y="142" fill="#fff">Kiln Talk, ep. 42</text></g>
            <g class="tree__leaf" style="--i:2"><rect x="306" y="198" width="230" height="44" rx="12" fill="#fff" stroke="#1B1650" stroke-width="2.5"/><text x="326" y="226">Book a wheel class</text></g>
            <g class="tree__leaf" style="--i:3"><rect x="306" y="282" width="230" height="44" rx="12" fill="#FFD447" stroke="#1B1650" stroke-width="2.5"/><text x="326" y="310">Friday kiln opening</text></g>
            <g class="tree__leaf" style="--i:4"><rect x="306" y="366" width="230" height="44" rx="12" fill="#fff" stroke="#1B1650" stroke-width="2.5"/><text x="326" y="394">Studio letter</text></g>
            <g class="tree__counts" text-anchor="end">
                <text x="520" y="60">412</text><text x="520" y="142" fill="#fff">188</text><text x="520" y="226">96</text><text x="520" y="310">151</text><text x="520" y="394">67</text>
            </g>
        </svg>
    </div>
</section>

<section class="steps">
    <h2 class="display display--md">From sign-up to shared in three steps</h2>
    <ol class="steps__list">
        <li><strong>Claim your name.</strong> Pick a username and your page goes live straight away.</li>
        <li><strong>Add your links.</strong> Drag them into order, group them under headers, hide the ones that are out of season.</li>
        <li><strong>Share and learn.</strong> Put the link in every bio, then see views, clicks and where visitors came from.</li>
    </ol>
</section>

<section class="themes-strip">
    <h2 class="display display--md">Eight looks to start from</h2>
    <p class="lede">Pick a theme, then mix button style, shape and font until the page feels like yours.</p>
    <div class="swatches">
        <?php foreach (themes() as $key => [$name, $v]): ?>
            <div class="swatch" style="--bg:<?= $v['bg'] ?>;--fg:<?= $v['fg'] ?>;--btn:<?= $v['btn'] ?>;--edge:<?= $v['edge'] ?>;--accent:<?= $v['accent'] ?>">
                <span class="swatch__bar"></span><span class="swatch__bar"></span><span class="swatch__name"><?= e($name) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
</main>
<footer class="sitefoot">
    <span><?= logo_svg(false, 20) ?> <?= APP_NAME ?></span>
    <span>Built with PHP and SQLite.</span>
</footer>
</body></html>
<?php
}

function auth_shell(string $title, string $heading, callable $body, string $aside = ''): void
{
    html_head($title . ' · ' . APP_NAME, app_css(), fonts_link());
    $flash = take_flash();
    ?>
<body class="auth">
<main class="auth__main">
    <a class="brand" href="<?= e(url()) ?>"><?= logo_svg() ?><span><?= APP_NAME ?></span></a>
    <div class="auth__card">
        <h1 class="display display--lg"><?= e($heading) ?></h1>
        <?= notice_html($flash) ?>
        <?php $body(); ?>
    </div>
    <?php if (IS_DEV): ?>
        <p class="devnote">Development mode: outgoing email goes to the <a href="<?= e(url('mail')) ?>">mail catcher</a> and <code>data/mail.log</code>.</p>
    <?php endif; ?>
</main>
<aside class="auth__aside" aria-hidden="true">
    <div class="auth__quote"><?= $aside ?: 'One page.<br>Every branch.' ?></div>
    <svg viewBox="0 0 200 300" class="auth__tree"><path d="M40 300V30M40 190c0-50 40-70 120-70M40 250c0-30 30-48 90-48M40 110c0-30 25-45 70-45" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round"/><circle cx="40" cy="30" r="14" fill="#F0478F"/><circle cx="160" cy="120" r="14" fill="#FFD447"/><circle cx="130" cy="202" r="14" fill="#fff"/><circle cx="110" cy="65" r="14" fill="#F0478F"/></svg>
</aside>
<?= app_script() ?>
</body></html>
<?php
}

function page_login(): void
{
    auth_shell('Log in', 'Log in', function () {
        ?>
        <form method="post" class="stack" novalidate>
            <?= csrf_field() ?><input type="hidden" name="_action" value="login">
            <label class="field"><span>Email or username</span>
                <input name="login" value="<?= old('login') ?>" autocomplete="username" required autofocus></label>
            <label class="field"><span class="field__row">Password <a href="<?= e(url('forgot')) ?>">Forgot it?</a></span>
                <input type="password" name="password" autocomplete="current-password" required></label>
            <label class="check"><input type="checkbox" name="remember" value="1"> Keep me logged in for 30 days</label>
            <button class="btn btn--primary btn--block" type="submit">Log in</button>
        </form>
        <p class="auth__alt">New here? <a href="<?= e(url('register')) ?>">Create a free page</a></p>
        <?php if (IS_DEV): ?>
            <form method="post" class="demo">
                <?= csrf_field() ?><input type="hidden" name="_action" value="demo_login">
                <p>Want to look around first? The demo account is <code><?= DEMO_EMAIL ?></code> with password <code><?= DEMO_PASS ?></code>.</p>
                <button class="btn btn--ghost btn--block" type="submit">Open the demo account</button>
            </form>
        <?php endif;
    });
}

function page_register(): void
{
    $prefill = $GLOBALS['OLD']['username'] ?? strtolower((string) ($_GET['username'] ?? ''));
    auth_shell('Sign up', 'Claim your page', function () use ($prefill) {
        ?>
        <form method="post" class="stack" novalidate>
            <?= csrf_field() ?><input type="hidden" name="_action" value="register">
            <label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
            <label class="field"><span>Username</span>
                <span class="prefixed"><span><?= e(substr(profile_display('x'), 0, -1)) ?></span>
                <input name="username" value="<?= e($prefill) ?>" autocomplete="username" pattern="[a-z0-9_.]{3,24}" maxlength="24" spellcheck="false" required></span>
                <small>3–24 lowercase letters, numbers, dots or underscores.</small></label>
            <label class="field"><span>Display name</span>
                <input name="display_name" value="<?= old('display_name') ?>" maxlength="60" autocomplete="name" placeholder="How your name appears on your page" required></label>
            <label class="field"><span>Email</span>
                <input type="email" name="email" value="<?= old('email') ?>" autocomplete="email" required></label>
            <label class="field"><span>Password</span>
                <input type="password" name="password" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required>
                <small>At least <?= MIN_PASSWORD ?> characters.</small></label>
            <button class="btn btn--primary btn--block" type="submit">Create my page</button>
        </form>
        <p class="auth__alt">Already have a page? <a href="<?= e(url('login')) ?>">Log in</a></p>
        <?php
    }, 'Claim your<br>name before<br>someone else.');
}

function page_forgot(): void
{
    $sent = isset($_GET['sent']) && isset($_SESSION['reset_sent_to']);
    auth_shell('Reset password', $sent ? 'Check your inbox' : 'Reset your password', function () use ($sent) {
        if ($sent): ?>
            <p class="lede lede--sm">If an account exists for <strong><?= e($_SESSION['reset_sent_to']) ?></strong>, we’ve sent a link to choose a new password. It expires in 1 hour.</p>
            <?php if (IS_DEV): ?><p><a class="btn btn--primary btn--block" href="<?= e(url('mail')) ?>"><?= icon('mail') ?> Open the mail catcher</a></p><?php endif; ?>
            <p class="auth__alt"><a href="<?= e(url('forgot')) ?>">Use a different email</a> or <a href="<?= e(url('login')) ?>">go back to log in</a></p>
        <?php else: ?>
            <p class="lede lede--sm">Enter the email you signed up with and we’ll send you a reset link.</p>
            <form method="post" class="stack" novalidate>
                <?= csrf_field() ?><input type="hidden" name="_action" value="forgot">
                <label class="field"><span>Email</span><input type="email" name="email" value="<?= old('email') ?>" autocomplete="email" required autofocus></label>
                <button class="btn btn--primary btn--block" type="submit">Send reset link</button>
            </form>
            <p class="auth__alt">Remembered it? <a href="<?= e(url('login')) ?>">Log in</a></p>
        <?php endif;
    });
}

function page_reset(): void
{
    $token = (string) ($_GET['token'] ?? '');
    $reset = find_reset($token);
    auth_shell('Choose a new password', $reset ? 'Choose a new password' : 'This link has expired', function () use ($token, $reset) {
        if (!$reset): ?>
            <p class="lede lede--sm">Reset links work once and expire after an hour. Request a fresh one and use it straight away.</p>
            <p><a class="btn btn--primary btn--block" href="<?= e(url('forgot')) ?>">Request a new link</a></p>
        <?php else: ?>
            <p class="lede lede--sm">For <strong><?= e($reset['email']) ?></strong>. You’ll be signed out everywhere else.</p>
            <form method="post" class="stack" novalidate>
                <?= csrf_field() ?><input type="hidden" name="_action" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>">
                <label class="field"><span>New password</span><input type="password" name="password" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required autofocus><small>At least <?= MIN_PASSWORD ?> characters.</small></label>
                <label class="field"><span>Repeat new password</span><input type="password" name="password_confirm" autocomplete="new-password" required></label>
                <button class="btn btn--primary btn--block" type="submit">Save new password</button>
            </form>
        <?php endif;
    });
}

function page_mail(): void
{
    if (!IS_DEV) {
        render_not_found();
    }
    $mails = db()->query('SELECT id, recipient, subject, created_at FROM outbox ORDER BY id DESC LIMIT 100')->fetchAll();
    $selectedId = (int) ($_GET['id'] ?? ($mails[0]['id'] ?? 0));
    $stmt = db()->prepare('SELECT * FROM outbox WHERE id = ?');
    $stmt->execute([$selectedId]);
    $selected = $stmt->fetch();
    $flash = take_flash();
    html_head('Mail catcher · ' . APP_NAME, app_css(), fonts_link());
    ?>
<body class="mailpage">
<header class="topbar">
    <a class="brand" href="<?= e(url()) ?>"><?= logo_svg() ?><span><?= APP_NAME ?></span></a>
    <nav class="topbar__nav">
        <a href="<?= e(url(current_user() ? 'links' : 'login')) ?>"><?= current_user() ? 'Back to dashboard' : 'Back to log in' ?></a>
        <?php if ($mails): ?>
        <form method="post" data-confirm="Delete every caught email?"><?= csrf_field() ?><input type="hidden" name="_action" value="mail_clear"><button class="btn btn--ghost btn--sm" type="submit">Empty</button></form>
        <?php endif; ?>
    </nav>
</header>
<main class="mail">
    <div class="mail__head">
        <h1 class="display display--lg">Mail catcher</h1>
        <p class="lede lede--sm">In development nothing is really sent. Every email the app writes shows up here and in <code>data/mail.log</code>.</p>
        <?= notice_html($flash) ?>
    </div>
    <?php if (!$mails): ?>
        <div class="empty"><p><strong>No email yet.</strong> Request a <a href="<?= e(url('forgot')) ?>">password reset</a> or sign up, and the message lands here.</p></div>
    <?php else: ?>
    <div class="mail__grid">
        <ul class="mail__list">
            <?php foreach ($mails as $m): ?>
                <li><a href="<?= e(url('mail', ['id' => $m['id']])) ?>" class="<?= (int) $m['id'] === $selectedId ? 'is-current' : '' ?>"<?= (int) $m['id'] === $selectedId ? ' aria-current="true"' : '' ?>>
                    <strong><?= e($m['subject']) ?></strong>
                    <span><?= e($m['recipient']) ?></span>
                    <time datetime="<?= e($m['created_at']) ?>Z"><?= e(date('j M, H:i', strtotime($m['created_at'] . ' UTC'))) ?> UTC</time>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($selected): ?>
        <article class="mail__view">
            <dl class="mail__meta"><dt>To</dt><dd><?= e($selected['recipient']) ?></dd><dt>Subject</dt><dd><?= e($selected['subject']) ?></dd></dl>
            <iframe title="Email body" sandbox="allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation" srcdoc="<?= e('<base target="_top">' . $selected['body_html']) ?>"></iframe>
        </article>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>
<?= app_script() ?>
</body></html>
<?php
}

// ============================================================================
// 11. Dashboard shell
// ============================================================================
function app_shell(array $user, string $active, string $title, callable $body, bool $preview = true): void
{
    html_head($title . ' · ' . APP_NAME, app_css(), fonts_link() . '<meta name="csrf" content="' . e(csrf_token()) . '">');
    $flash = take_flash();
    $tabs = ['links' => ['Links', 'links'], 'appearance' => ['Appearance', 'palette'], 'analytics' => ['Analytics', 'chart'], 'settings' => ['Account', 'gear']];
    ?>
<body class="app<?= $preview ? ' app--preview' : '' ?>">
<a class="skip" href="#main">Skip to content</a>
<nav class="rail" aria-label="Dashboard">
    <a class="brand" href="<?= e(url('links')) ?>"><?= logo_svg() ?><span><?= APP_NAME ?></span></a>
    <ul class="rail__tabs">
        <?php foreach ($tabs as $key => [$label, $ic]): ?>
            <li><a href="<?= e(url($key)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= $label ?></span></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="rail__foot">
        <a class="rail__me" href="<?= e(profile_path($user['username'])) ?>" target="_blank" rel="noopener"><?= avatar_html($user, 'avatar avatar--xs') ?><span>@<?= e($user['username']) ?></span></a>
        <?php if (IS_DEV): ?><a class="rail__small" href="<?= e(url('mail')) ?>"><?= icon('mail', 16) ?><span>Mail catcher</span></a><?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="logout"><button class="rail__small" type="submit"><?= icon('logout', 16) ?><span>Log out</span></button></form>
    </div>
</nav>

<main id="main" class="main">
    <header class="main__head">
        <h1 class="display display--lg"><?= e($title) ?></h1>
        <div class="sharebar">
            <a class="sharebar__url" href="<?= e(profile_path($user['username'])) ?>" target="_blank" rel="noopener"><?= e(profile_display($user['username'])) ?></a>
            <button class="btn btn--ghost btn--sm" type="button" data-copy="<?= e(profile_url($user['username'])) ?>"><?= icon('copy', 16) ?> Copy link</button>
        </div>
    </header>
    <?= notice_html($flash) ?>
    <?php $body(); ?>
</main>

<?php if ($preview): ?>
<aside class="preview" aria-label="Live preview of your page">
    <div class="phone">
        <iframe id="preview" title="Your page" src="<?= e(profile_path($user['username']) . (pretty_urls() ? '?' : '&') . 'preview=1') ?>"></iframe>
    </div>
    <p class="preview__cap">Live preview. Visits from here aren’t counted.</p>
</aside>
<?php endif; ?>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<?= app_script() ?>
</body></html>
<?php
}

// ============================================================================
// 12. Dashboard pages
// ============================================================================
function page_links(array $user): void
{
    $links = user_links((int) $user['id']);
    app_shell($user, 'links', 'Links', function () use ($links) {
        ?>
        <section class="composer" aria-label="Add to your page">
            <div class="composer__tabs" role="tablist">
                <button type="button" role="tab" aria-selected="true" data-kind="link"><?= icon('plus', 16) ?> Link</button>
                <button type="button" role="tab" aria-selected="false" data-kind="header"><?= icon('plus', 16) ?> Header</button>
            </div>
            <form method="post" class="composer__form" id="composer">
                <?= csrf_field() ?><input type="hidden" name="_action" value="link_add"><input type="hidden" name="kind" value="link">
                <label class="field field--grow"><span>Title</span><input name="title" maxlength="100" placeholder="Shop my new collection" required></label>
                <label class="field field--grow" data-only="link"><span>Address</span><input name="url" inputmode="url" placeholder="example.com/shop" required></label>
                <label class="field field--emoji" data-only="link"><span>Emoji</span><input name="emoji" maxlength="8" placeholder="✨"></label>
                <button class="btn btn--primary" type="submit">Add</button>
            </form>
        </section>

        <?php if (!$links): ?>
            <div class="empty"><p><strong>Your page is empty.</strong> Add a link above. It appears at the top of your page, and you can drag it into place later.</p></div>
        <?php else: ?>
        <p class="hint">Drag the handle to reorder, or use the arrows. The first item sits at the top of your page.</p>
        <ol class="branch" id="branch">
            <?php foreach ($links as $i => $l): $isHeader = $l['kind'] === 'header'; ?>
            <li class="node<?= $isHeader ? ' node--header' : '' ?><?= $l['is_active'] ? '' : ' is-off' ?>" data-id="<?= (int) $l['id'] ?>">
                <div class="node__card">
                    <button class="node__grip" type="button" draggable="true" aria-label="Drag to reorder <?= e($l['title']) ?>"><?= icon('grip', 18) ?></button>
                    <div class="node__body">
                        <p class="node__title"><?= $l['emoji'] !== '' ? '<span class="node__emoji">' . e($l['emoji']) . '</span>' : '' ?><?= e($l['title']) ?><?= $l['featured'] ? ' <span class="tag tag--sap">' . icon('star', 12) . ' Highlighted</span>' : '' ?></p>
                        <?php if ($isHeader): ?>
                            <p class="node__meta">Section header</p>
                        <?php else: ?>
                            <p class="node__meta"><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('~^(https?://|mailto:|tel:)~', '', $l['url'])) ?></a><span class="node__clicks"><?= number_format((int) $l['clicks']) ?> <?= (int) $l['clicks'] === 1 ? 'click' : 'clicks' ?></span></p>
                        <?php endif; ?>
                    </div>
                    <div class="node__tools">
                        <form method="post" data-async>
                            <?= csrf_field() ?><input type="hidden" name="_action" value="link_toggle"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <label class="switch" title="Show on page"><input type="checkbox" name="is_active" value="1" <?= $l['is_active'] ? 'checked' : '' ?> data-autosubmit aria-label="Show “<?= e($l['title']) ?>” on your page"><span></span></label>
                        </form>
                        <form method="post" class="node__move">
                            <?= csrf_field() ?><input type="hidden" name="_action" value="link_move"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <button class="iconbtn" name="direction" value="up" aria-label="Move up" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('up', 16) ?></button>
                            <button class="iconbtn" name="direction" value="down" aria-label="Move down" <?= $i === count($links) - 1 ? 'disabled' : '' ?>><?= icon('down', 16) ?></button>
                        </form>
                    </div>
                </div>
                <details class="node__edit">
                    <summary><?= icon('pencil', 14) ?> Edit</summary>
                    <form method="post" class="node__form">
                        <?= csrf_field() ?><input type="hidden" name="_action" value="link_update"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="kind" value="<?= e($l['kind']) ?>">
                        <label class="field"><span>Title</span><input name="title" value="<?= e($l['title']) ?>" maxlength="100" required></label>
                        <?php if (!$isHeader): ?>
                        <label class="field"><span>Address</span><input name="url" value="<?= e($l['url']) ?>" required></label>
                        <div class="node__form-row">
                            <label class="field field--emoji"><span>Emoji</span><input name="emoji" value="<?= e($l['emoji']) ?>" maxlength="8"></label>
                            <label class="check"><input type="checkbox" name="featured" value="1" <?= $l['featured'] ? 'checked' : '' ?>> Highlight this link</label>
                        </div>
                        <?php endif; ?>
                        <div class="node__form-actions">
                            <button class="btn btn--primary btn--sm" type="submit">Save changes</button>
                            <button class="btn btn--danger btn--sm" type="submit" form="del-<?= (int) $l['id'] ?>"><?= icon('trash', 14) ?> Delete</button>
                        </div>
                    </form>
                    <form method="post" id="del-<?= (int) $l['id'] ?>" data-confirm="Delete “<?= e($l['title']) ?>”? Its click history goes too."><?= csrf_field() ?><input type="hidden" name="_action" value="link_delete"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"></form>
                </details>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif;
    });
}

function page_appearance(array $user): void
{
    $socials = json_decode($user['socials'], true) ?: [];
    app_shell($user, 'appearance', 'Appearance', function () use ($user, $socials) {
        ?>
        <section class="panel">
            <h2 class="panel__title">Profile</h2>
            <div class="avatar-row">
                <?= avatar_html($user, 'avatar avatar--lg') ?>
                <form method="post" enctype="multipart/form-data" class="avatar-row__form">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="avatar_upload">
                    <label class="btn btn--ghost btn--sm filebtn">Upload photo<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-autosubmit></label>
                    <small>Square crop, JPG, PNG, WebP or GIF up to 4 MB.</small>
                </form>
                <?php if ($user['avatar'] !== ''): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="avatar_remove"><button class="btn btn--text btn--sm" type="submit">Remove</button></form>
                <?php endif; ?>
            </div>
            <form method="post" class="stack" data-async data-live-profile>
                <?= csrf_field() ?><input type="hidden" name="_action" value="profile_save">
                <label class="field"><span>Display name</span><input name="display_name" value="<?= e($user['display_name']) ?>" maxlength="60" required></label>
                <label class="field"><span class="field__row">Bio <output data-count-for="bio"><?= mb_strlen($user['bio']) ?>/160</output></span>
                    <textarea name="bio" id="bio" rows="3" maxlength="160" placeholder="One or two lines about you"><?= e($user['bio']) ?></textarea></label>
                <div><button class="btn btn--primary btn--sm" type="submit">Save profile</button></div>
            </form>
        </section>

        <form method="post" class="panel" data-async data-live-design>
            <?= csrf_field() ?><input type="hidden" name="_action" value="design_save">
            <h2 class="panel__title">Theme</h2>
            <div class="themes">
                <?php foreach (themes() as $key => [$name, $v]): ?>
                <label class="theme" style="--bg:<?= $v['bg'] ?>;--fg:<?= $v['fg'] ?>;--btn:<?= $v['btn'] ?>;--edge:<?= $v['edge'] ?>;--accent:<?= $v['accent'] ?>">
                    <input type="radio" name="theme" value="<?= $key ?>" <?= $user['theme'] === $key ? 'checked' : '' ?>>
                    <span class="theme__art"><span class="theme__dot"></span><span class="theme__bar"></span><span class="theme__bar"></span><span class="theme__bar"></span></span>
                    <span class="theme__name"><?= e($name) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="choices">
                <fieldset><legend>Buttons</legend>
                    <?php foreach (BUTTON_STYLES as $k => $label): ?><label class="chip"><input type="radio" name="button_style" value="<?= $k ?>" <?= $user['button_style'] === $k ? 'checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?>
                </fieldset>
                <fieldset><legend>Corners</legend>
                    <?php foreach (BUTTON_SHAPES as $k => $label): ?><label class="chip"><input type="radio" name="button_shape" value="<?= $k ?>" <?= $user['button_shape'] === $k ? 'checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?>
                </fieldset>
                <fieldset><legend>Font</legend>
                    <?php foreach (FONTS as $k => $label): ?><label class="chip chip--font-<?= $k ?>"><input type="radio" name="font" value="<?= $k ?>" <?= $user['font'] === $k ? 'checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?>
                </fieldset>
            </div>
            <div class="panel__foot"><span class="hint">Changes show in the preview straight away. Save to publish them.</span><button class="btn btn--primary btn--sm" type="submit">Save design</button></div>
        </form>

        <form method="post" class="panel" data-async>
            <?= csrf_field() ?><input type="hidden" name="_action" value="socials_save">
            <h2 class="panel__title">Social icons</h2>
            <p class="hint">Shown as a row of icons under your bio. Use a handle or a full profile address. Leave a field empty to hide it.</p>
            <div class="socials">
                <?php foreach (social_platforms() as $key => [$label, , $ph]): ?>
                <label class="social"><?= social_icon($key) ?><span class="sr"><?= e($label) ?></span>
                    <input name="social[<?= $key ?>]" value="<?= e(preg_replace('/^mailto:/', '', $socials[$key] ?? '')) ?>" placeholder="<?= e($label . ': ' . $ph) ?>" aria-label="<?= e($label) ?>"></label>
                <?php endforeach; ?>
            </div>
            <div class="panel__foot"><span></span><button class="btn btn--primary btn--sm" type="submit">Save social icons</button></div>
        </form>
        <?php
    });
}

function page_analytics(array $user): void
{
    $days = in_array((int) ($_GET['days'] ?? 30), [7, 30, 90], true) ? (int) ($_GET['days'] ?? 30) : 30;
    $since = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
    $uid = (int) $user['id'];
    $pdo = db();

    $q = function (string $sql, array $params) use ($pdo): PDOStatement {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    };
    $viewsByDay  = $q("SELECT date(created_at) d, COUNT(*) n FROM views WHERE user_id = ? AND created_at >= ? GROUP BY d", [$uid, $since])->fetchAll(PDO::FETCH_KEY_PAIR);
    $clicksByDay = $q("SELECT date(created_at) d, COUNT(*) n FROM clicks WHERE user_id = ? AND created_at >= ? GROUP BY d", [$uid, $since])->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalViews  = array_sum($viewsByDay);
    $totalClicks = array_sum($clicksByDay);
    $uniques     = (int) $q("SELECT COUNT(DISTINCT ip_hash) FROM views WHERE user_id = ? AND created_at >= ?", [$uid, $since])->fetchColumn();
    $perLink     = $q("SELECT l.title, l.emoji, l.is_active, COUNT(c.id) n FROM links l LEFT JOIN clicks c ON c.link_id = l.id AND c.created_at >= ?
                      WHERE l.user_id = ? AND l.kind = 'link' GROUP BY l.id ORDER BY n DESC, l.position", [$since, $uid])->fetchAll();
    $referrers   = $q("SELECT referrer label, COUNT(*) n FROM views WHERE user_id = ? AND created_at >= ? GROUP BY referrer ORDER BY n DESC LIMIT 6", [$uid, $since])->fetchAll();
    $devices     = $q("SELECT device label, COUNT(*) n FROM views WHERE user_id = ? AND created_at >= ? GROUP BY device ORDER BY n DESC", [$uid, $since])->fetchAll();

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', time() - $i * 86400);
        $series[] = ['d' => $d, 'v' => (int) ($viewsByDay[$d] ?? 0), 'c' => (int) ($clicksByDay[$d] ?? 0)];
    }

    app_shell($user, 'analytics', 'Analytics', function () use ($days, $totalViews, $totalClicks, $uniques, $series, $perLink, $referrers, $devices) {
        $ctr = $totalViews ? round($totalClicks / $totalViews * 100, 1) : 0;
        $max = max(1, ...array_map(fn($r) => max($r['v'], $r['c']), $series));
        $niceMax = (int) (ceil($max / 5) * 5) ?: 5;
        ?>
        <div class="range" role="group" aria-label="Date range">
            <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $n => $label): ?>
                <a href="<?= e(url('analytics', ['days' => $n])) ?>"<?= $n === $days ? ' aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <dl class="figures">
            <div><dt>Page views</dt><dd><?= number_format($totalViews) ?></dd></div>
            <div><dt>Unique visitors</dt><dd><?= number_format($uniques) ?></dd></div>
            <div><dt>Link clicks</dt><dd><?= number_format($totalClicks) ?></dd></div>
            <div><dt>Click rate</dt><dd><?= $ctr ?><small>%</small></dd></div>
        </dl>

        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Views and clicks per day</h2>
                <ul class="legend"><li><span class="key key--views"></span>Views</li><li><span class="key key--clicks"></span>Clicks</li></ul>
            </div>
            <?php if ($totalViews + $totalClicks === 0): ?>
                <div class="empty empty--flat"><p><strong>No visits in this period.</strong> Share your link and the first views show up here within seconds.</p></div>
            <?php else: ?>
            <div class="chart" style="--max:<?= $niceMax ?>">
                <div class="chart__axis" aria-hidden="true"><span><?= $niceMax ?></span><span><?= (int) ($niceMax / 2) ?></span><span>0</span></div>
                <div class="chart__plot<?= $days > 30 ? ' chart__plot--dense' : '' ?>">
                    <?php foreach ($series as $i => $r): $label = date('D j M', strtotime($r['d'])); ?>
                    <div class="chart__day" tabindex="0" aria-label="<?= e("$label: {$r['v']} views, {$r['c']} clicks") ?>">
                        <span class="bar bar--views" style="--h:<?= $r['v'] / $niceMax ?>"></span>
                        <span class="bar bar--clicks" style="--h:<?= $r['c'] / $niceMax ?>"></span>
                        <span class="chart__tip"><strong><?= e($label) ?></strong><?= $r['v'] ?> views<br><?= $r['c'] ?> clicks</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart__dates" aria-hidden="true"><span><?= e(date('j M', strtotime($series[0]['d']))) ?></span><span>Today</span></div>
            </div>
            <details class="tableview"><summary>Show as a table</summary>
                <table><thead><tr><th>Day</th><th>Views</th><th>Clicks</th></tr></thead><tbody>
                <?php foreach (array_reverse($series) as $r): ?><tr><td><?= e(date('D j M', strtotime($r['d']))) ?></td><td><?= $r['v'] ?></td><td><?= $r['c'] ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </details>
            <?php endif; ?>
        </section>

        <div class="split">
            <section class="panel">
                <h2 class="panel__title">Clicks by link</h2>
                <?php $top = max(1, ...array_map(fn($l) => (int) $l['n'], $perLink ?: [['n' => 0]])); ?>
                <?php if (!$perLink): ?><p class="hint">Add links to see how each one performs.</p><?php endif; ?>
                <ol class="ranks">
                    <?php foreach ($perLink as $l): ?>
                    <li><span class="ranks__label"><?= e(trim($l['emoji'] . ' ' . $l['title'])) ?><?= $l['is_active'] ? '' : ' <span class="tag">Hidden</span>' ?></span><span class="ranks__n"><?= number_format((int) $l['n']) ?></span><span class="ranks__bar" style="--w:<?= (int) $l['n'] / $top ?>"></span></li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <section class="panel">
                <h2 class="panel__title">Where visitors came from</h2>
                <?= ranks_html($referrers, $totalViews) ?>
                <h2 class="panel__title panel__title--spaced">Devices</h2>
                <?= ranks_html(array_map(fn($d) => ['label' => ucfirst($d['label']), 'n' => $d['n']], $devices), $totalViews) ?>
            </section>
        </div>
        <?php
    }, false);
}

function ranks_html(array $rows, int $total): string
{
    if (!$rows) {
        return '<p class="hint">Nothing yet.</p>';
    }
    $out = '<ol class="ranks">';
    foreach ($rows as $r) {
        $pct = $total ? round($r['n'] / $total * 100) : 0;
        $out .= '<li><span class="ranks__label">' . e($r['label']) . '</span><span class="ranks__n">' . $pct . '%</span><span class="ranks__bar" style="--w:' . ($total ? $r['n'] / $total : 0) . '"></span></li>';
    }
    return $out . '</ol>';
}

function page_settings(array $user): void
{
    app_shell($user, 'settings', 'Account', function () use ($user) {
        ?>
        <form method="post" class="panel stack">
            <?= csrf_field() ?><input type="hidden" name="_action" value="account_save">
            <h2 class="panel__title">Login details</h2>
            <label class="field"><span>Username</span>
                <span class="prefixed"><span><?= e(substr(profile_display('x'), 0, -1)) ?></span><input name="username" value="<?= e($user['username']) ?>" pattern="[a-z0-9_.]{3,24}" maxlength="24" required></span>
                <small>Changing it changes your page address. Old links stop working.</small></label>
            <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($user['email']) ?>" autocomplete="email" required></label>
            <label class="field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password"><small>Only needed when you change your email.</small></label>
            <div><button class="btn btn--primary btn--sm" type="submit">Save login details</button></div>
        </form>

        <form method="post" class="panel stack">
            <?= csrf_field() ?><input type="hidden" name="_action" value="password_save">
            <h2 class="panel__title">Password</h2>
            <label class="field"><span>Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
            <div class="grid2">
                <label class="field"><span>New password</span><input type="password" name="new_password" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required></label>
                <label class="field"><span>Repeat new password</span><input type="password" name="new_password_confirm" autocomplete="new-password" required></label>
            </div>
            <div><button class="btn btn--primary btn--sm" type="submit">Change password</button></div>
        </form>

        <form method="post" class="panel panel--danger stack" data-confirm="Delete your account and page for good? This can’t be undone.">
            <?= csrf_field() ?><input type="hidden" name="_action" value="account_delete">
            <h2 class="panel__title">Delete account</h2>
            <p class="hint">Removes your page, links, photo and all analytics. <strong><?= e(profile_display($user['username'])) ?></strong> becomes free for anyone to claim.</p>
            <div class="grid2">
                <label class="field"><span>Type <strong><?= e($user['username']) ?></strong> to confirm</span><input name="confirm_username" autocomplete="off" required></label>
                <label class="field"><span>Password</span><input type="password" name="current_password" autocomplete="current-password" required></label>
            </div>
            <div><button class="btn btn--danger btn--sm" type="submit">Delete my account</button></div>
        </form>
        <?php
    }, false);
}

// ============================================================================
// 13. Public profile view
// ============================================================================
function render_profile(array $user, array $links, bool $preview, bool $isOwner): void
{
    $theme   = isset(themes()[$user['theme']]) ? $user['theme'] : 'riso';
    $socials = json_decode($user['socials'], true) ?: [];
    $platforms = social_platforms();
    $url     = profile_url($user['username']);
    $desc    = $user['bio'] !== '' ? $user['bio'] : $user['display_name'] . ' on ' . APP_NAME;
    $avatar  = avatar_src($user);
    $meta = '<meta name="description" content="' . e($desc) . '">'
        . '<meta property="og:title" content="' . e($user['display_name']) . '"><meta property="og:description" content="' . e($desc) . '">'
        . '<meta property="og:url" content="' . e($url) . '"><meta property="og:type" content="profile">'
        . ($avatar ? '<meta property="og:image" content="' . e(origin() . substr($avatar, strlen(base_path()))) . '">' : '')
        . '<link rel="canonical" href="' . e($url) . '">'
        . ($preview ? '<meta name="robots" content="noindex">' : '');
    html_head($user['display_name'] . ' (@' . $user['username'] . ')', profile_css(), fonts_link(true) . $meta,
        ' data-theme="' . e($theme) . '" data-btn="' . e($user['button_style']) . '" data-shape="' . e($user['button_shape']) . '" data-font="' . e($user['font']) . '"');
    $base = base_path() . '/';
    ?>
<body class="pf<?= $preview ? ' pf--preview' : '' ?>">
<?php if (!$preview): ?>
<div class="pf__bar">
    <?php if ($isOwner): ?><a class="pf__chip" href="<?= e(url('links')) ?>">Edit my page</a><?php else: ?><span></span><?php endif; ?>
    <button class="pf__round" type="button" id="share-open" aria-label="Share this page"><?= icon('share', 18) ?></button>
</div>
<?php endif; ?>
<main class="pf__main">
    <header class="pf__head">
        <?= avatar_html($user, 'pf__avatar') ?>
        <h1 class="pf__name"><?= e($user['display_name']) ?></h1>
        <p class="pf__bio"<?= $user['bio'] === '' ? ' hidden' : '' ?>><?= nl2br(e($user['bio'])) ?></p>
        <?php if ($socials): ?>
        <ul class="pf__socials">
            <?php foreach ($socials as $key => $href): if (!isset($platforms[$key])) continue; ?>
                <li><a href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer me" aria-label="<?= e($platforms[$key][0]) ?>"><?= social_icon($key) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </header>
    <?php if (!$links): ?>
        <p class="pf__empty">Links are on their way.</p>
    <?php else: ?>
    <ul class="pf__links">
        <?php foreach ($links as $l): ?>
            <?php if ($l['kind'] === 'header'): ?>
                <li><h2 class="pf__section"><?= e($l['title']) ?></h2></li>
            <?php else: ?>
                <li><a class="lk<?= $l['featured'] ? ' lk--featured' : '' ?>" href="<?= e($base . '?r=' . (int) $l['id']) ?>" rel="noopener"<?= $preview ? ' target="_blank"' : '' ?>>
                    <span class="lk__emoji" aria-hidden="true"><?= e($l['emoji']) ?></span>
                    <span class="lk__title"><?= e($l['title']) ?></span>
                    <span class="lk__emoji" aria-hidden="true"></span>
                </a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>
<footer class="pf__foot"><a href="<?= e($base) ?>"<?= $preview ? ' target="_top"' : '' ?>><?= logo_svg(false, 18) ?> Made with <?= APP_NAME ?></a></footer>

<?php if (!$preview): ?>
<dialog class="share" id="share">
    <form method="dialog" class="share__close"><button aria-label="Close"><?= icon('close', 18) ?></button></form>
    <h2>Share this page</h2>
    <div class="share__qr" id="qr" role="img" aria-label="QR code for <?= e(profile_display($user['username'])) ?>"></div>
    <p class="share__url"><?= e(profile_display($user['username'])) ?></p>
    <div class="share__actions">
        <button type="button" class="share__btn" id="share-copy"><?= icon('copy', 16) ?> Copy link</button>
        <button type="button" class="share__btn" id="share-native" hidden><?= icon('share', 16) ?> More options</button>
    </div>
</dialog>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" nonce="<?= CSP_NONCE ?>" defer></script>
<script nonce="<?= CSP_NONCE ?>">
(() => {
    const url = <?= json_encode($url) ?>, dlg = document.getElementById('share');
    let drawn = false;
    document.getElementById('share-open').addEventListener('click', () => {
        dlg.showModal();
        if (!drawn && window.QRCode) { new QRCode(document.getElementById('qr'), { text: url, width: 184, height: 184, colorDark: '#000000', colorLight: '#ffffff' }); drawn = true; }
    });
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
    const copy = document.getElementById('share-copy');
    copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(url); copy.lastChild.textContent = ' Copied'; }
        catch { prompt('Copy this link', url); }
        setTimeout(() => copy.lastChild.textContent = ' Copy link', 1800);
    });
    const nat = document.getElementById('share-native');
    if (navigator.share) { nat.hidden = false; nat.addEventListener('click', () => navigator.share({ title: document.title, url }).catch(() => {})); }
})();
</script>
<?php endif; ?>
</body></html>
<?php
}

// ============================================================================
// 14. Styles
// ============================================================================
function app_css(): string
{
    return <<<'CSS'
:root{
  --paper:#EDEFF6; --paper-2:#E2E5F0; --card:#FFFFFF; --ink:#1B1650; --ink-2:#47437A; --ink-3:#6E6B96;
  --line:#C9CDE2; --ultra:#3B2EF0; --ultra-2:#2A1FC4; --pink:#F0478F; --sap:#FFD447; --ok:#187A50; --bad:#C42740;
  --display:'Anybody', 'Arial Narrow', sans-serif; --body:'Schibsted Grotesk', system-ui, sans-serif;
  --r1:8px; --r2:14px; --shadow:3px 3px 0 var(--pink);
  color-scheme: light;
}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--paper);color:var(--ink);font:400 16px/1.5 var(--body);-webkit-font-smoothing:antialiased}
a{color:var(--ultra);text-underline-offset:3px}
a:hover{color:var(--ultra-2)}
img{max-width:100%;display:block}
code{font:500 .88em/1 ui-monospace,Menlo,monospace;background:var(--paper-2);padding:.15em .4em;border-radius:5px;color:var(--ink)}
:focus-visible{outline:3px solid var(--ultra);outline-offset:2px;border-radius:4px}
.sr,.hp{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.skip{position:absolute;left:-999px;top:8px;background:var(--ink);color:#fff;padding:8px 14px;border-radius:var(--r1);z-index:99}
.skip:focus{left:8px}
.ic{flex:none}

/* Type */
.display{font-family:var(--display);font-weight:800;letter-spacing:-.01em;line-height:1;margin:0;color:var(--ink);font-stretch:125%}
.display--hero{font-size:clamp(2.5rem,5.6vw,4.9rem);font-stretch:128%;line-height:.95;letter-spacing:-.025em;max-width:12.5ch;text-wrap:balance}
.display--xl{font-size:clamp(2.2rem,5vw,3.8rem);max-width:14ch}
.display--lg{font-size:clamp(1.7rem,3vw,2.3rem)}
.display--md{font-size:clamp(1.5rem,2.6vw,2rem);font-stretch:115%}
.lede{font-size:1.16rem;line-height:1.55;color:var(--ink-2);max-width:52ch;margin:1.1rem 0 0}
.lede--sm{font-size:1rem;margin:.5rem 0 1.25rem}
.hint{color:var(--ink-3);font-size:.9rem;margin:.25rem 0 0}
.fineprint{font-size:.88rem;color:var(--ink-3);margin:.75rem 0 0}

/* Brand */
.brand{display:inline-flex;align-items:center;gap:.55rem;font:800 1.2rem/1 var(--display);font-stretch:120%;color:var(--ink);text-decoration:none}
.brand:hover{color:var(--ink)}

/* Buttons */
.btn{--bg:var(--card);--fg:var(--ink);display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font:700 1rem/1 var(--body);padding:.85rem 1.25rem;border-radius:var(--r1);border:2px solid var(--ink);background:var(--bg);color:var(--fg);text-decoration:none;cursor:pointer;transition:transform .12s ease,box-shadow .12s ease;white-space:nowrap}
.btn:hover{color:var(--fg)}
.btn--primary{--bg:var(--ultra);--fg:#fff;border-color:var(--ultra);box-shadow:var(--shadow)}
.btn--primary:hover{transform:translate(-1px,-1px);box-shadow:4px 4px 0 var(--pink)}
.btn--primary:active{transform:translate(2px,2px);box-shadow:1px 1px 0 var(--pink)}
.btn--ghost:hover{background:var(--paper-2)}
.btn--danger{--bg:var(--card);--fg:var(--bad);border-color:var(--bad)}
.btn--danger:hover{--bg:var(--bad);--fg:#fff}
.btn--text{border-color:transparent;background:none;color:var(--ink-2);padding-inline:.5rem}
.btn--sm{font-size:.9rem;padding:.6rem .95rem}
.btn--block{width:100%}
.iconbtn{display:grid;place-items:center;width:32px;height:32px;border-radius:var(--r1);border:1.5px solid transparent;background:none;color:var(--ink-2);cursor:pointer}
.iconbtn:hover:not(:disabled){background:var(--paper-2);color:var(--ink)}
.iconbtn:disabled{opacity:.3;cursor:default}
.row{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:2rem}

/* Forms */
.stack{display:grid;gap:1rem}
.field{display:grid;gap:.4rem;font-weight:600;font-size:.92rem;min-width:0}
.field small{font-weight:400;color:var(--ink-3);font-size:.84rem}
.field__row{display:flex;justify-content:space-between;align-items:baseline;gap:1rem}
.field__row a,.field__row output{font-weight:500;font-size:.85rem;color:var(--ink-3)}
.field__row a{color:var(--ultra)}
input:not([type=checkbox]):not([type=radio]):not([type=file]),textarea{width:100%;font:400 1rem/1.4 var(--body);color:var(--ink);background:var(--card);border:2px solid var(--line);border-radius:var(--r1);padding:.72rem .85rem;transition:border-color .12s, box-shadow .12s}
textarea{resize:vertical;min-height:84px}
input::placeholder,textarea::placeholder{color:#9A98B8}
input:focus,textarea:focus{outline:none;border-color:var(--ultra);box-shadow:0 0 0 4px rgb(59 46 240 / .14)}
.prefixed{display:flex;align-items:stretch;border:2px solid var(--line);border-radius:var(--r1);background:var(--card);overflow:hidden}
.prefixed:focus-within{border-color:var(--ultra);box-shadow:0 0 0 4px rgb(59 46 240 / .14)}
.prefixed input{flex:1;min-width:0}
.prefixed>span{flex:none;display:flex;align-items:center;padding:0 0 0 .85rem;color:var(--ink-3);font-weight:500;white-space:nowrap;max-width:55%;overflow:hidden;text-overflow:ellipsis}
.prefixed input{border:0!important;box-shadow:none!important;padding-left:.1rem!important}
.check{display:flex;gap:.6rem;align-items:center;font-size:.95rem;cursor:pointer}
.check input{width:18px;height:18px;accent-color:var(--ultra)}

/* Notices */
.notice{border-radius:var(--r1);padding:.8rem 1rem;font-weight:600;font-size:.95rem;margin:0 0 1.25rem;border:2px solid}
.notice--error{background:#FFE9EE;color:#8E1530;border-color:#F2A7B8}
.notice--success{background:#E4F6EC;color:#0F5A39;border-color:#9AD8B6}
.empty{border:2px dashed var(--line);border-radius:var(--r2);padding:2rem;text-align:center;color:var(--ink-2);background:rgb(255 255 255 / .5)}
.empty p{margin:0 auto;max-width:44ch}
.empty--flat{border:0;background:var(--paper)}
.tag{display:inline-flex;align-items:center;gap:.25rem;font-size:.75rem;font-weight:700;padding:.15rem .5rem;border-radius:99px;background:var(--paper-2);color:var(--ink-2);vertical-align:middle}
.tag--sap{background:var(--sap);color:var(--ink)}
.devnote{font-size:.85rem;color:var(--ink-3);max-width:420px;margin:1.25rem 0 0}

/* Top bar (marketing / mail) */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.25rem clamp(16px,4vw,48px)}
.topbar__nav{display:flex;align-items:center;gap:clamp(.75rem,2vw,1.75rem);font-weight:600}
.topbar__nav a:not(.btn){color:var(--ink);text-decoration:none;white-space:nowrap}
.topbar__nav a:not(.btn):hover{text-decoration:underline}
.topbar__nav form{margin:0}

/* Home */
.hero{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:clamp(2rem,4vw,4rem);align-items:center;padding:clamp(2rem,6vw,5rem) clamp(16px,4vw,48px) clamp(3rem,7vw,6rem);max-width:1320px;margin:0 auto}
.claim{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:2rem;max-width:560px}
.claim__field{flex:1 1 280px;display:flex;align-items:center;background:var(--card);border:2px solid var(--ink);border-radius:var(--r1);padding-left:1rem;min-width:0}
.claim__field:focus-within{box-shadow:0 0 0 4px rgb(59 46 240 / .18);border-color:var(--ultra)}
.claim__prefix{flex:none;color:var(--ink-3);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:60%}
.claim__field input{flex:1;min-width:0}
.claim__field input{border:0!important;box-shadow:none!important;padding-left:.1rem!important;font-weight:700!important;font-size:1.05rem!important}
.tree{width:100%;height:auto;overflow:visible}
.tree text{font:700 16px var(--body);fill:var(--ink)}
.tree__root text{font:800 20px var(--display);font-stretch:120%;fill:#fff}
.tree__counts text{font:600 14px var(--body);fill:var(--ink-2);font-variant-numeric:tabular-nums}
.tree__paths path{stroke-dasharray:1;stroke-dashoffset:1;animation:draw .9s cubic-bezier(.6,0,.2,1) .2s forwards}
.tree__paths path:nth-child(2){animation-delay:.3s}.tree__paths path:nth-child(3){animation-delay:.4s}.tree__paths path:nth-child(4){animation-delay:.5s}.tree__paths path:nth-child(5){animation-delay:.6s}
.tree__leaf,.tree__counts{opacity:0;animation:pop .45s ease-out forwards;animation-delay:calc(.85s + var(--i,5) * .08s)}
@keyframes draw{to{stroke-dashoffset:0}}
@keyframes pop{from{opacity:0;transform:translateX(-8px)}to{opacity:1;transform:none}}
.steps,.themes-strip{max-width:1320px;margin:0 auto;padding:clamp(2.5rem,5vw,4rem) clamp(16px,4vw,48px);border-top:2px solid var(--ink)}
.steps__list{list-style:none;counter-reset:s;padding:0;margin:2rem 0 0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:clamp(1.25rem,3vw,3rem)}
.steps__list li{counter-increment:s;color:var(--ink-2);font-size:1.05rem;max-width:34ch}
.steps__list li::before{content:counter(s);display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:var(--ultra);color:#fff;font:800 1.25rem/1 var(--display);margin-bottom:1rem;box-shadow:var(--shadow)}
.steps__list strong{display:block;color:var(--ink);font-size:1.15rem;margin-bottom:.3rem}
.swatches{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:1rem;margin-top:2rem}
.swatch{aspect-ratio:3/4;background:var(--bg);border-radius:var(--r2);padding:1rem .9rem;display:flex;flex-direction:column;justify-content:flex-end;gap:.5rem;border:2px solid var(--ink)}
.swatch__bar{height:18px;border-radius:6px;background:var(--btn);border:2px solid var(--edge);box-shadow:3px 3px 0 var(--accent)}
.swatch__name{font-weight:700;color:var(--fg);margin-top:.4rem;font-size:.95rem}
.sitefoot{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:2rem clamp(16px,4vw,48px);border-top:2px solid var(--ink);color:var(--ink-3);font-size:.9rem}
.sitefoot span{display:inline-flex;align-items:center;gap:.5rem}

/* Auth */
.auth{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.8fr);min-height:100vh;min-height:100dvh}
.auth__main{padding:clamp(1.25rem,4vw,3rem) clamp(16px,6vw,5rem);display:flex;flex-direction:column;align-items:flex-start}
.auth__card{width:100%;max-width:420px;margin:auto 0;padding:2.5rem 0}
.auth__card h1{margin-bottom:1.25rem}
.auth__alt{margin:1.5rem 0 0;color:var(--ink-2);font-size:.95rem}
.auth__aside{background:var(--ultra);color:#fff;position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;padding:clamp(2rem,4vw,3.5rem)}
.auth__quote{font:800 clamp(2rem,3.6vw,3.4rem)/.98 var(--display);font-stretch:130%;letter-spacing:-.02em;max-width:9ch}
.auth__tree{width:min(60%,260px);align-self:flex-end;margin-bottom:-3.5rem}
.demo{margin-top:2rem;padding-top:1.5rem;border-top:2px dashed var(--line)}
.demo p{font-size:.9rem;color:var(--ink-2);margin:0 0 .85rem}
.solo{min-height:100vh;display:grid;place-items:center;padding:16px}
.solo__main{max-width:640px}
.solo .display{margin-top:2.5rem}

/* App shell */
.app{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}
.app--preview{grid-template-columns:220px minmax(0,1fr) 380px}
.rail{position:sticky;top:0;height:100vh;display:flex;flex-direction:column;gap:2rem;padding:1.5rem 1rem;border-right:2px solid var(--ink);background:var(--paper)}
.rail .brand{padding:0 .6rem}
.rail__tabs{list-style:none;margin:0;padding:0;display:grid;gap:.25rem}
.rail__tabs a{display:flex;align-items:center;gap:.7rem;padding:.7rem .75rem;border-radius:var(--r1);color:var(--ink-2);text-decoration:none;font-weight:600}
.rail__tabs a:hover{background:var(--paper-2);color:var(--ink)}
.rail__tabs a[aria-current]{background:var(--ink);color:#fff}
.rail__tabs a[aria-current] .ic{color:var(--sap)}
.rail__foot{margin-top:auto;display:grid;gap:.25rem}
.rail__foot form{margin:0}
.rail__me{display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;border-radius:var(--r1);color:var(--ink);font-weight:700;text-decoration:none;margin-bottom:.4rem;overflow:hidden}
.rail__me span{overflow:hidden;text-overflow:ellipsis}
.rail__me:hover{background:var(--paper-2);color:var(--ink)}
.rail__small{display:flex;align-items:center;gap:.6rem;width:100%;padding:.5rem .7rem;border:0;background:none;border-radius:var(--r1);font:500 .9rem var(--body);color:var(--ink-3);text-decoration:none;cursor:pointer}
.rail__small:hover{background:var(--paper-2);color:var(--ink)}
.main{padding:clamp(1.25rem,3vw,2.5rem) clamp(16px,3.5vw,3rem) 4rem;min-width:0;max-width:880px;width:100%}
.main__head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem}
.sharebar{display:flex;align-items:center;gap:.5rem;min-width:0}
.sharebar__url{font-weight:600;color:var(--ink-2);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:30ch}
.sharebar__url:hover{color:var(--ultra);text-decoration:underline}

.avatar{border-radius:50%;object-fit:cover;flex:none;background:var(--paper-2)}
.avatar--initials{display:grid;place-items:center;background:var(--pink);color:#fff;font:800 1rem/1 var(--display)}
.avatar--xs{width:30px;height:30px;font-size:.75rem}
.avatar--lg{width:84px;height:84px;font-size:1.8rem;border:2px solid var(--ink)}

/* Panels */
.panel{background:var(--card);border:2px solid var(--ink);border-radius:var(--r2);padding:clamp(1.1rem,2.5vw,1.6rem);margin-bottom:1.5rem}
.panel--danger{border-color:var(--bad)}
.panel__title{font:800 1.2rem/1.2 var(--display);font-stretch:112%;margin:0 0 1rem}
.panel__title--spaced{margin-top:2rem}
.panel__head{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap}
.panel__foot{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1.25rem;padding-top:1.1rem;border-top:1.5px solid var(--paper-2)}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem}
.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem}
.split{align-items:start}
.split .panel{margin:0}

/* Composer */
.composer{background:var(--card);border:2px solid var(--ink);border-radius:var(--r2);margin-bottom:2rem;box-shadow:5px 5px 0 var(--ultra)}
.composer__tabs{display:flex;border-bottom:2px solid var(--ink)}
.composer__tabs button{display:flex;align-items:center;gap:.35rem;font:700 .95rem var(--body);color:var(--ink-3);background:none;border:0;padding:.8rem 1.2rem;cursor:pointer;border-right:2px solid var(--ink)}
.composer__tabs button[aria-selected=true]{color:var(--ink);background:var(--sap)}
.composer__tabs button:first-child{border-top-left-radius:12px}
.composer__form{display:flex;flex-wrap:wrap;gap:.85rem;align-items:flex-end;padding:1.1rem 1.25rem 1.3rem}
.field--grow{flex:1 1 200px}
.field--emoji{flex:0 0 84px}
.field--emoji input{text-align:center}
.composer__form[data-kind=header] [data-only=link]{display:none}

/* Branch list: the trunk line + one node per item */
.branch{list-style:none;margin:.9rem 0 0;padding:0 0 0 34px;position:relative}
.branch::before{content:"";position:absolute;left:10px;top:-14px;bottom:28px;width:3px;background:var(--ink);border-radius:3px}
.node{position:relative;margin-bottom:.8rem}
.node::before{content:"";position:absolute;left:-24px;top:29px;width:24px;height:3px;background:var(--ink)}
.node::after{content:"";position:absolute;left:-30px;top:24px;width:13px;height:13px;border-radius:50%;background:var(--ultra);border:2.5px solid var(--ink)}
.node--header::after{background:var(--pink);border-radius:3px;transform:rotate(45deg)}
.node.is-off::after{background:var(--paper)}
.node__card{display:flex;align-items:center;gap:.6rem;background:var(--card);border:2px solid var(--ink);border-radius:var(--r2);padding:.65rem .8rem .65rem .4rem;min-height:62px}
.node--header .node__card{background:var(--paper-2)}
.node.is-off .node__card{border-style:dashed;background:transparent}
.node.is-off .node__title{color:var(--ink-3)}
.node.is-dragging{opacity:.45}
.node.is-drop-target .node__card{box-shadow:0 -4px 0 var(--ultra)}
.node__grip{display:grid;place-items:center;width:30px;height:40px;border:0;background:none;color:var(--ink-3);cursor:grab;border-radius:6px;flex:none}
.node__grip:hover{color:var(--ink);background:var(--paper-2)}
.node__body{flex:1;min-width:0}
.node__title{margin:0;font-weight:700;overflow-wrap:anywhere}
.node--header .node__title{font:800 1.05rem/1.2 var(--display);font-stretch:118%}
.node__emoji{margin-right:.4rem}
.node__meta{margin:.15rem 0 0;font-size:.86rem;color:var(--ink-3);display:flex;gap:.9rem;min-width:0}
.node__meta a{color:var(--ink-3);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.node__meta a:hover{color:var(--ultra);text-decoration:underline}
.node__clicks{white-space:nowrap;flex:none;font-weight:600;color:var(--ink-2)}
.node__tools{display:flex;align-items:center;gap:.6rem;flex:none}
.node__tools form{margin:0;display:flex}
.node__edit{margin:.35rem 0 0 .25rem}
.node__edit summary{display:inline-flex;align-items:center;gap:.35rem;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--ink-3);list-style:none;padding:.2rem .4rem;border-radius:6px}
.node__edit summary::-webkit-details-marker{display:none}
.node__edit summary:hover{color:var(--ultra)}
.node__edit[open] summary{color:var(--ink)}
.node__form{display:grid;gap:.85rem;background:var(--card);border:2px solid var(--line);border-radius:var(--r2);padding:1rem;margin-top:.4rem}
.node__form-row{display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap}
.node__form-row .check{padding-bottom:.7rem}
.node__form-actions{display:flex;justify-content:space-between;gap:.75rem}
.switch{position:relative;display:inline-block;width:44px;height:26px;cursor:pointer}
.switch input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
.switch span{position:absolute;inset:0;border-radius:99px;background:var(--line);transition:background .15s}
.switch span::after{content:"";position:absolute;left:3px;top:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgb(27 22 80 / .3);transition:transform .15s}
.switch input:checked+span{background:var(--ok)}
.switch input:checked+span::after{transform:translateX(18px)}
.switch input:focus-visible+span{outline:3px solid var(--ultra);outline-offset:2px}

/* Appearance */
.avatar-row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.avatar-row__form{display:grid;gap:.35rem;margin:0}
.avatar-row__form small{color:var(--ink-3);font-size:.82rem}
.avatar-row form{margin:0}
.filebtn{position:relative;overflow:hidden;justify-self:start}
.filebtn input{position:absolute;inset:0;opacity:0;cursor:pointer}
.filebtn:focus-within{outline:3px solid var(--ultra);outline-offset:2px}
.themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:.85rem}
.theme{cursor:pointer;display:grid;gap:.4rem;position:relative}
.theme input{position:absolute;opacity:0}
.theme__art{aspect-ratio:4/5;border-radius:12px;background:var(--bg);border:2px solid var(--line);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;padding:12px;transition:border-color .12s}
.theme__dot{width:22px;height:22px;border-radius:50%;background:var(--fg);opacity:.85;margin-bottom:4px}
.theme__bar{width:100%;height:13px;border-radius:5px;background:var(--btn);border:1.5px solid var(--edge);box-shadow:2px 2px 0 var(--accent)}
.theme__name{font-size:.88rem;font-weight:600;text-align:center;color:var(--ink-2)}
.theme input:checked+.theme__art{border-color:var(--ink);box-shadow:0 0 0 3px var(--paper),0 0 0 5px var(--ultra)}
.theme input:checked~.theme__name{color:var(--ink);font-weight:800}
.theme input:focus-visible+.theme__art{outline:3px solid var(--ultra);outline-offset:4px}
.choices{display:grid;gap:1.1rem;margin-top:1.5rem}
.choices fieldset{border:0;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.choices legend{font-weight:700;font-size:.92rem;margin-bottom:.5rem;padding:0;float:left;width:100%}
.chip{position:relative;cursor:pointer}
.chip input{position:absolute;opacity:0}
.chip span{display:inline-block;padding:.5rem .9rem;border:2px solid var(--line);border-radius:99px;font-weight:600;font-size:.92rem;background:var(--card)}
.chip input:checked+span{border-color:var(--ink);background:var(--ink);color:#fff}
.chip input:focus-visible+span{outline:3px solid var(--ultra);outline-offset:2px}
.chip--font-wide span{font-family:'Anybody';font-stretch:130%}
.chip--font-serif span{font-family:'Young Serif',Georgia,serif}
.chip--font-mono span{font-family:'DM Mono',monospace}
.socials{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.7rem;margin-top:1rem}
.social{display:flex;align-items:center;gap:.6rem;color:var(--ink)}
.social input{padding:.6rem .75rem!important;font-size:.95rem!important}
.si{display:inline-block;width:20px;height:20px;flex:none;background:currentColor;-webkit-mask:var(--si) center/contain no-repeat;mask:var(--si) center/contain no-repeat}

/* Preview */
.preview{position:sticky;top:0;height:100vh;padding:1.5rem 1.5rem 1rem;border-left:2px solid var(--ink);display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--paper-2)}
.phone{width:100%;max-width:310px;aspect-ratio:9/18.5;max-height:calc(100vh - 5rem);border:3px solid var(--ink);border-radius:38px;padding:9px;background:var(--ink);box-shadow:8px 8px 0 var(--pink)}
.phone iframe{width:100%;height:100%;border:0;border-radius:29px;background:#fff}
.preview__cap{font-size:.82rem;color:var(--ink-3);margin:1rem 0 0;text-align:center}

/* Analytics */
.range{display:inline-flex;border:2px solid var(--ink);border-radius:var(--r1);overflow:hidden;margin-bottom:1.5rem;background:var(--card);max-width:100%}
.range a{padding:.55rem .95rem;font-weight:600;font-size:.92rem;color:var(--ink-2);text-decoration:none;white-space:nowrap}
.range a+a{border-left:2px solid var(--ink)}
.range a[aria-current]{background:var(--ink);color:#fff}
.figures{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin:0 0 1.5rem;border:2px solid var(--ink);border-radius:var(--r2);background:var(--card);overflow:hidden}
.figures div{padding:1.1rem 1.25rem;min-width:0}
.figures div+div{border-left:2px solid var(--paper-2)}
.figures dt{font-size:.88rem;font-weight:600;color:var(--ink-3)}
.figures dd{margin:.35rem 0 0;font:800 clamp(1.6rem,3vw,2.3rem)/1 var(--display);font-stretch:110%;font-variant-numeric:tabular-nums}
.figures small{font-size:.55em;margin-left:.1em}
.legend{list-style:none;display:flex;gap:1rem;margin:0;padding:0;font-size:.88rem;color:var(--ink-2)}
.legend li{display:flex;align-items:center;gap:.4rem}
.key{width:12px;height:12px;border-radius:3px}
.key--views,.bar--views{background:#3B2EF0}
.key--clicks,.bar--clicks{background:#F0478F}
.chart{display:grid;grid-template-columns:auto 1fr;grid-template-rows:200px auto;column-gap:.6rem;margin-top:1rem}
.chart__axis{display:flex;flex-direction:column;justify-content:space-between;font-size:.76rem;color:var(--ink-3);text-align:right;font-variant-numeric:tabular-nums;margin:-.45em 0}
.chart__plot{display:flex;align-items:flex-end;gap:2px;border-bottom:2px solid var(--ink);background:linear-gradient(var(--paper-2) 1px,transparent 1px) 0 0/100% 50%;position:relative}
.chart__day{flex:1;display:flex;align-items:flex-end;justify-content:center;gap:2px;height:100%;position:relative;min-width:0;outline-offset:0}
.chart__day:hover,.chart__day:focus-visible{background:rgb(59 46 240 / .07)}
.bar{width:min(42%,12px);height:calc(var(--h) * 100%);border-radius:4px 4px 0 0;min-height:0}
.chart__plot--dense .chart__day{gap:1px}
.chart__plot--dense .bar{border-radius:2px 2px 0 0}
.chart__tip{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;font-size:.8rem;line-height:1.35;padding:.45rem .65rem;border-radius:var(--r1);white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .1s;z-index:3}
.chart__tip strong{display:block}
.chart__day:hover .chart__tip,.chart__day:focus-visible .chart__tip{opacity:1}
.chart__day:first-child .chart__tip{left:0;transform:none}
.chart__day:last-child .chart__tip{left:auto;right:0;transform:none}
.chart__dates{grid-column:2;display:flex;justify-content:space-between;font-size:.78rem;color:var(--ink-3);padding-top:.4rem}
.tableview{margin-top:1rem;font-size:.9rem}
.tableview summary{cursor:pointer;color:var(--ink-2);font-weight:600}
.tableview table{border-collapse:collapse;margin-top:.75rem;width:100%;max-width:420px}
.tableview th,.tableview td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid var(--paper-2);font-variant-numeric:tabular-nums}
.ranks{list-style:none;margin:0;padding:0;display:grid;gap:.85rem}
.ranks li{display:grid;grid-template-columns:1fr auto;gap:.3rem .75rem;font-size:.94rem}
.ranks__label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.ranks__n{font-weight:700;font-variant-numeric:tabular-nums}
.ranks__bar{grid-column:1/-1;height:8px;border-radius:4px;background:var(--paper-2);position:relative;overflow:hidden}
.ranks__bar::after{content:"";position:absolute;inset:0 auto 0 0;width:calc(var(--w) * 100%);background:var(--ultra);border-radius:4px}

/* Mail catcher */
.mail{max-width:1240px;margin:0 auto;padding:0 clamp(16px,4vw,48px) 3rem}
.mail__head{margin-bottom:1.5rem}
.mail__grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:1.25rem;align-items:start}
.mail__list{list-style:none;margin:0;padding:0;border:2px solid var(--ink);border-radius:var(--r2);background:var(--card);overflow:hidden}
.mail__list li+li{border-top:1.5px solid var(--paper-2)}
.mail__list a{display:grid;gap:.15rem;padding:.85rem 1rem;text-decoration:none;color:var(--ink)}
.mail__list a:hover{background:var(--paper)}
.mail__list a.is-current{background:var(--sap)}
.mail__list span,.mail__list time{font-size:.84rem;color:var(--ink-2)}
.mail__view{border:2px solid var(--ink);border-radius:var(--r2);background:var(--card);overflow:hidden}
.mail__meta{display:grid;grid-template-columns:auto 1fr;gap:.3rem 1rem;margin:0;padding:1rem 1.25rem;border-bottom:2px solid var(--ink);font-size:.92rem}
.mail__meta dt{color:var(--ink-3);font-weight:600}
.mail__meta dd{margin:0;font-weight:600}
.mail__view iframe{display:block;width:100%;height:620px;border:0}

/* Toast */
.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);background:var(--ink);color:#fff;padding:.75rem 1.1rem;border-radius:var(--r1);font-weight:600;font-size:.94rem;opacity:0;pointer-events:none;transition:opacity .18s,transform .18s;z-index:50;box-shadow:var(--shadow);max-width:calc(100vw - 32px)}
.toast.is-on{opacity:1;transform:translate(-50%,0)}
.toast.is-error{background:var(--bad);box-shadow:none}

/* Responsive */
@media (max-width:1180px){
  .app--preview{grid-template-columns:220px minmax(0,1fr)}
  .preview{display:none}
}
@media (max-width:900px){
  .hero{grid-template-columns:1fr}
  .hero__art{max-width:520px}
  .steps__list{grid-template-columns:1fr}
  .auth{grid-template-columns:1fr}
  .auth__aside{display:none}
  .mail__grid{grid-template-columns:1fr}
  .figures{grid-template-columns:repeat(2,minmax(0,1fr))}
  .figures div:nth-child(3){border-left:0}
  .figures div:nth-child(n+3){border-top:2px solid var(--paper-2)}
}
@media (max-width:760px){
  .app,.app--preview{grid-template-columns:1fr}
  .rail{position:sticky;top:0;z-index:20;height:auto;flex-direction:row;align-items:center;gap:.5rem;padding:.6rem 16px;border-right:0;border-bottom:2px solid var(--ink);overflow-x:auto}
  .rail .brand span,.rail__tabs a span,.rail__me>span:last-child,.rail__small span{display:none}
  .rail .brand{padding:0 .25rem 0 0}
  .rail__tabs{display:flex;gap:.15rem}
  .rail__tabs a{padding:.55rem}
  .rail__foot{margin:0 0 0 auto;display:flex;align-items:center}
  .rail__me{margin:0;padding:.3rem}
  .rail__small{padding:.5rem}
  .main{padding-top:1.25rem}
  .sharebar__url{display:none}
  .topbar__nav a:not(.btn):first-child{display:none}
  .node__meta{flex-direction:column;gap:0}
  .chart{grid-template-rows:160px auto}
  .swatches{grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem}
  .swatch{padding:.55rem .45rem;gap:.3rem;border-radius:10px}
  .swatch__bar{height:10px;border-radius:4px;box-shadow:2px 2px 0 var(--accent)}
  .swatch__name{font-size:.72rem}
}
@media (max-width:420px){
  .node__move{display:none!important}
  .composer__form .btn{width:100%}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-delay:0s!important;transition-duration:.01ms!important}
  .tree__paths path{stroke-dashoffset:0}
  .tree__leaf,.tree__counts{opacity:1}
}
CSS;
}

function profile_css(): string
{
    $css = '';
    foreach (themes() as $key => [, $v]) {
        $css .= '[data-theme="' . $key . '"]{--bg:' . $v['bg'] . ';--fg:' . $v['fg'] . ';--muted:' . $v['muted'] . ';--btn:' . $v['btn'] . ';--btnfg:' . $v['btnfg'] . ';--edge:' . $v['edge'] . ';--accent:' . $v['accent'] . '}';
    }
    return $css . <<<'CSS'
[data-theme="blueprint"] body{background-image:linear-gradient(rgb(255 255 255 / .09) 1px,transparent 1px),linear-gradient(90deg,rgb(255 255 255 / .09) 1px,transparent 1px);background-size:24px 24px}
[data-theme="dusk"] body{background-image:linear-gradient(172deg,#2B1055 0%,#6E2590 52%,#D53A9D 100%);background-attachment:fixed}
[data-theme="tangerine"] body{background-image:radial-gradient(circle at 85% 8%,#FFB15C 0,transparent 42%)}
[data-shape="square"]{--rad:0}
[data-shape="round"]{--rad:14px}
[data-shape="pill"]{--rad:999px}
[data-font="grotesk"]{--font:'Schibsted Grotesk',system-ui,sans-serif;--stretch:100%}
[data-font="wide"]{--font:'Anybody',sans-serif;--stretch:122%}
[data-font="serif"]{--font:'Young Serif',Georgia,serif;--stretch:100%}
[data-font="mono"]{--font:'DM Mono',ui-monospace,monospace;--stretch:100%}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;background-color:var(--bg);color:var(--fg);font-family:var(--font);font-stretch:var(--stretch);-webkit-font-smoothing:antialiased;transition:background-color .25s,color .25s}
:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.ic{flex:none}
.pf__bar{display:flex;justify-content:space-between;align-items:center;padding:16px;max-width:620px;width:100%;margin:0 auto}
.pf__chip{font:600 14px/1 'Schibsted Grotesk',sans-serif;font-stretch:100%;color:var(--fg);text-decoration:none;padding:9px 14px;border-radius:99px;border:2px solid var(--edge)}
.pf__round{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;border:2px solid var(--edge);background:transparent;color:var(--fg);cursor:pointer}
.pf__round:hover,.pf__chip:hover{background:color-mix(in srgb,var(--fg) 10%,transparent)}
.pf__main{flex:1;width:100%;max-width:580px;margin:0 auto;padding:12px 20px 40px}
.pf--preview .pf__main{padding-top:40px}
.pf__head{display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:28px}
.pf__avatar{width:104px;height:104px;border-radius:50%;object-fit:cover;border:3px solid var(--edge);box-shadow:5px 5px 0 var(--accent)}
.pf__avatar--initials{display:grid;place-items:center;background:var(--btn);color:var(--btnfg);font:800 36px/1 'Anybody',sans-serif;font-stretch:120%}
.pf__name{font-size:clamp(24px,6vw,30px);line-height:1.1;margin:22px 0 0;font-weight:800;letter-spacing:-.01em;overflow-wrap:anywhere}
[data-font="serif"] .pf__name{font-weight:400}
.pf__bio{margin:10px 0 0;color:var(--muted);font-size:16px;line-height:1.5;max-width:40ch}
.pf__socials{list-style:none;display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin:18px 0 0;padding:0}
.pf__socials a{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;color:var(--fg);transition:transform .15s}
.pf__socials a:hover{transform:translateY(-2px);background:color-mix(in srgb,var(--fg) 10%,transparent)}
.si{display:inline-block;width:22px;height:22px;background:currentColor;-webkit-mask:var(--si) center/contain no-repeat;mask:var(--si) center/contain no-repeat}
.pf__links{list-style:none;margin:0;padding:0;display:grid;gap:14px}
.pf__section{font-size:14px;font-weight:700;margin:14px 0 0;text-align:center;color:var(--muted);letter-spacing:.01em}
.lk{display:grid;grid-template-columns:32px 1fr 32px;align-items:center;gap:8px;min-height:62px;padding:12px 16px;border-radius:var(--rad);text-decoration:none;font-weight:700;font-size:16px;line-height:1.3;text-align:center;transition:transform .14s ease,box-shadow .14s ease,background-color .2s}
.lk__emoji{font-size:20px;line-height:1}
.lk__title{overflow-wrap:anywhere}
[data-btn="fill"] .lk{background:var(--btn);color:var(--btnfg);border:2px solid var(--btn)}
[data-btn="outline"] .lk{background:transparent;color:var(--fg);border:2px solid var(--edge)}
[data-btn="hard"] .lk{background:var(--btn);color:var(--btnfg);border:2px solid var(--edge);box-shadow:5px 5px 0 var(--accent)}
[data-btn="soft"] .lk{background:color-mix(in srgb,var(--btn) 18%,transparent);color:var(--fg);border:2px solid transparent}
.lk:hover{transform:translate(-2px,-2px)}
[data-btn="hard"] .lk:hover{box-shadow:7px 7px 0 var(--accent)}
[data-btn="hard"] .lk:active{transform:translate(3px,3px);box-shadow:2px 2px 0 var(--accent)}
[data-btn="outline"] .lk:hover{background:color-mix(in srgb,var(--fg) 8%,transparent)}
.lk--featured{animation:nudge 3.2s ease-in-out 1.2s infinite}
.lk--featured .lk__title::after{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent);margin-left:8px;vertical-align:middle;box-shadow:0 0 0 2px var(--edge)}
@keyframes nudge{0%,88%,100%{transform:none}91%{transform:rotate(-1.2deg)}94%{transform:rotate(1.2deg)}97%{transform:rotate(-.5deg)}}
.pf__empty{text-align:center;color:var(--muted)}
.pf__foot{text-align:center;padding:12px 16px 28px}
.pf__foot a{display:inline-flex;align-items:center;gap:7px;font:600 13px/1 'Schibsted Grotesk',sans-serif;font-stretch:100%;color:var(--fg);opacity:.75;text-decoration:none;padding:9px 14px;border-radius:99px;background:color-mix(in srgb,var(--bg) 70%,#fff)}
[data-theme="moss"] .pf__foot a,[data-theme="blueprint"] .pf__foot a,[data-theme="dusk"] .pf__foot a{background:rgb(255 255 255 / .12)}
.pf__foot a:hover{opacity:1}
.share{border:3px solid #1B1650;border-radius:20px;padding:28px 24px 22px;width:min(340px,calc(100vw - 32px));text-align:center;font-family:'Schibsted Grotesk',sans-serif;font-stretch:100%;color:#1B1650;box-shadow:8px 8px 0 #F0478F}
.share::backdrop{background:rgb(27 22 80 / .55)}
.share h2{margin:0 0 18px;font:800 22px/1.1 'Anybody',sans-serif;font-stretch:120%}
.share__close{position:absolute;top:10px;right:10px;margin:0}
.share__close button{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:50%;background:none;cursor:pointer;color:#1B1650}
.share__close button:hover{background:#EDEFF6}
.share__qr{display:grid;place-items:center;width:208px;height:208px;margin:0 auto;padding:12px;border:2px solid #1B1650;border-radius:12px;background:#fff}
.share__qr img,.share__qr canvas{display:block}
.share__url{font-weight:700;margin:14px 0 18px;overflow-wrap:anywhere}
.share__actions{display:grid;gap:8px}
.share__btn{display:flex;align-items:center;justify-content:center;gap:8px;font:700 15px 'Schibsted Grotesk',sans-serif;padding:12px;border-radius:10px;border:2px solid #1B1650;background:#fff;color:#1B1650;cursor:pointer}
.share__btn:first-child{background:#3B2EF0;border-color:#3B2EF0;color:#fff}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
CSS;
}

// ============================================================================
// 15. Dashboard script (progressive enhancement; every form also works without JS)
// ============================================================================
function app_script(): string
{
    ob_start(); ?>
<script nonce="<?= CSP_NONCE ?>">
(() => {
    const $ = (s, el = document) => el.querySelector(s), $$ = (s, el = document) => [...el.querySelectorAll(s)];
    const csrf = $('meta[name=csrf]')?.content || $('input[name=_csrf]')?.value || '';
    const preview = $('#preview');
    let toastTimer;
    const toast = (msg, isError = false) => {
        const t = $('#toast'); if (!t || !msg) return;
        t.textContent = msg; t.classList.toggle('is-error', isError); t.classList.add('is-on');
        clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('is-on'), 2600);
    };
    const reloadPreview = () => preview?.contentWindow?.location.reload();
    const post = async (data) => {
        const res = await fetch(location.pathname, { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf } });
        let json = {}; try { json = await res.json(); } catch {}
        if (!res.ok || !json.ok) throw new Error(json.message || 'Something went wrong. Reload and try again.');
        return json;
    };

    // Confirm destructive forms
    document.addEventListener('submit', e => {
        const form = e.target.closest('form'), msg = form?.dataset.confirm;
        if (msg && !confirm(msg)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    // fetch()-enhanced forms: stay on the page, show a toast, refresh the preview
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.matches('[data-async]') || e.defaultPrevented) return;
        e.preventDefault();
        const btn = $('button[type=submit]', form); if (btn) btn.disabled = true;
        try { toast((await post(new FormData(form))).message); reloadPreview(); }
        catch (err) { toast(err.message, true); if (form.querySelector('[data-autosubmit]')?.type === 'checkbox') { const c = form.querySelector('[data-autosubmit]'); c.checked = !c.checked; } }
        finally { if (btn) btn.disabled = false; }
    });
    document.addEventListener('change', e => {
        const el = e.target;
        if (!el.matches('[data-autosubmit]')) return;
        if (el.type === 'checkbox') el.closest('.node')?.classList.toggle('is-off', !el.checked);
        el.form.requestSubmit();
    });

    // Copy profile link
    $$('[data-copy]').forEach(b => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); toast('Link copied.'); }
        catch { prompt('Copy your link', b.dataset.copy); }
    }));

    // Composer: switch between link and header
    const composer = $('#composer');
    $$('.composer__tabs button').forEach(tab => tab.addEventListener('click', () => {
        $$('.composer__tabs button').forEach(t => t.setAttribute('aria-selected', String(t === tab)));
        const kind = tab.dataset.kind;
        const f = composer.elements;
        composer.dataset.kind = kind; f.kind.value = kind;
        f.url.required = kind === 'link';
        f.title.placeholder = kind === 'link' ? 'Shop my new collection' : 'Section name, e.g. Listen';
        f.title.focus();
    }));

    // Drag to reorder (mouse) – arrow buttons cover keyboard and touch
    const branch = $('#branch');
    if (branch) {
        let dragged = null;
        branch.addEventListener('dragstart', e => {
            dragged = e.target.closest('.node'); if (!dragged) return;
            dragged.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setDragImage(dragged, 20, 30);
        });
        branch.addEventListener('dragover', e => {
            if (!dragged) return; e.preventDefault();
            const over = e.target.closest('.node');
            $$('.node', branch).forEach(n => n.classList.toggle('is-drop-target', n === over && n !== dragged));
        });
        branch.addEventListener('drop', async e => {
            e.preventDefault();
            const over = e.target.closest('.node');
            $$('.is-drop-target', branch).forEach(n => n.classList.remove('is-drop-target'));
            if (!dragged || !over || over === dragged) return;
            const nodes = $$('.node', branch);
            branch.insertBefore(dragged, nodes.indexOf(dragged) < nodes.indexOf(over) ? over.nextSibling : over);
            const fd = new FormData(); fd.append('_action', 'links_reorder'); fd.append('_csrf', csrf);
            $$('.node', branch).forEach(n => fd.append('ids[]', n.dataset.id));
            try { toast((await post(fd)).message); reloadPreview(); }
            catch (err) { toast(err.message, true); }
            const all = $$('.node', branch);
            all.forEach((n, i) => { const [up, down] = $$('.node__move button', n); if (up) up.disabled = i === 0; if (down) down.disabled = i === all.length - 1; });
        });
        branch.addEventListener('dragend', () => { dragged?.classList.remove('is-dragging'); dragged = null; });
    }

    // Appearance: live preview before saving
    const doc = () => preview?.contentDocument?.documentElement;
    const design = $('[data-live-design]');
    design?.addEventListener('change', e => {
        const map = { theme: 'theme', button_style: 'btn', button_shape: 'shape', font: 'font' };
        if (map[e.target.name] && doc()) doc().dataset[map[e.target.name]] = e.target.value;
    });
    const prof = $('[data-live-profile]');
    prof?.addEventListener('input', e => {
        const d = preview?.contentDocument; if (!d) return;
        if (e.target.name === 'display_name') { const n = d.querySelector('.pf__name'); if (n) n.textContent = e.target.value; }
        if (e.target.name === 'bio') {
            const b = d.querySelector('.pf__bio'); if (b) { b.textContent = e.target.value; b.hidden = !e.target.value; }
            const out = $('[data-count-for=bio]'); if (out) out.textContent = e.target.value.length + '/160';
        }
    });
})();
</script>
<?php
    return (string) ob_get_clean();
}
