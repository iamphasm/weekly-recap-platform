<?php
declare(strict_types=1);

const TEACHER_IDLE_SECONDS = 7200;

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            return $default;
        }
        $v = $v[$part];
    }
    return $v;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    // URL path of the app folder (e.g. "/recap"), detected from the request so the folder can be placed anywhere.
    static $p = null;
    if ($p === null) {
        if (PHP_SAPI === 'cli') {
            return $p = rtrim((string)parse_url((string)cfg('base_url', ''), PHP_URL_PATH), '/');
        }
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        if (realpath(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) === realpath(APP_ROOT . '/admin')) {
            $dir = dirname($dir);
        }
        $p = rtrim($dir === '.' ? '' : $dir, '/');
    }
    return $p;
}

/** Absolute URL of the app root without trailing slash (for emails and links shown to teachers). */
function app_url(): string
{
    // Prefer the URL saved at install time: the Host header is client-controlled and must not end up in emails.
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST']) || cfg('base_url')) {
        return rtrim((string)cfg('base_url', ''), '/');
    }
    return (is_https() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . base_path();
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** True when the visitor reached us over HTTPS (also behind a proxy, if trust_proxy is on). */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (bool)cfg('trust_proxy') && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Visitor IP. Only trusts X-Forwarded-For when config 'trust_proxy' is true (the last hop is the one our proxy added). */
function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($parts);
        if (filter_var($last, FILTER_VALIDATE_IP)) {
            $ip = $last;
        }
    }
    return $ip;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, reload the page and try again.');
    }
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function is_school_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domains = cfg('school_domains', []);
    if (!$domains) {
        return true;
    }
    $domain = substr(strrchr($email, '@'), 1);
    return in_array($domain, array_map('strtolower', $domains), true);
}

function flash(?string $msg = null, string $kind = 'error'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$kind, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function fmt_dt(string $dt): string
{
    return date('D j M Y, H:i', strtotime($dt));
}

function pct(int $score, int $max): int
{
    return $max > 0 ? (int)round($score * 100 / $max) : 0;
}

// ---- branding (editable by teachers under Admin > Settings) ----

const THEME_DEFAULTS = ['color_primary' => '#3858e9', 'color_bg' => '#f0f0f1', 'color_header' => '#1d2327'];

function setting(string $key, ?string $default = null): ?string
{
    static $all = null;
    if ($all === null) {
        if (!cfg('db')) {
            return $default; // not installed yet
        }
        try {
            $all = q('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $all = []; // settings table not created yet
        }
    }
    return $all[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
}

function site_name(): string
{
    return setting('site_name') ?: (string)cfg('site_name', 'Weekly Recap');
}

function logo_url(): ?string
{
    $f = setting('logo');
    return $f && is_file(__DIR__ . '/../uploads/' . $f) ? url('uploads/' . $f) . '?v=' . filemtime(__DIR__ . '/../uploads/' . $f) : null;
}

function theme_color(string $key): string
{
    $v = setting($key, THEME_DEFAULTS[$key]);
    return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? $v : THEME_DEFAULTS[$key];
}

/** Black or white, whichever reads better on the given #rrggbb background. */
function contrast_color(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1d2327' : '#ffffff';
}

function html_head(string $title, string $bodyClass = ''): void
{
    $p = theme_color('color_primary');
    $h = theme_color('color_header');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' - ' . e(site_name()) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('assets/style.css')) . '">'
        . '<style>:root{--primary:' . $p . ';--on-primary:' . contrast_color($p) . ';--bg:' . theme_color('color_bg')
        . ';--header:' . $h . ';--on-header:' . contrast_color($h) . '}</style>'
        . '</head><body class="' . e($bodyClass) . '">';
}

function brand_html(int $logoHeight = 0): string
{
    $logo = logo_url();
    if ($logo) {
        return '<img class="logo" src="' . e($logo) . '" alt="' . e(site_name()) . '">';
    }
    return e(site_name());
}

function page_header(string $title, string $area = 'student'): void
{
    html_head($title);
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . e(url($area === 'admin' ? 'admin/' : '')) . '">' . brand_html() . '</a>';
    if ($area === 'admin' && !empty($_SESSION['teacher_id'])) {
        echo '<nav><a href="' . e(url('admin/')) . '">Tests</a>'
            . '<a href="' . e(url('admin/students.php')) . '">Students</a>'
            . '<a href="' . e(url('admin/settings.php')) . '">Settings</a>'
            . '<form method="post" action="' . e(url('admin/logout.php')) . '" class="inline">' . csrf_field()
            . '<button class="linkbtn">Log out</button></form></nav>';
    } elseif ($area === 'student' && !empty($_SESSION['student_id'])) {
        echo '<nav><span class="muted">' . e($_SESSION['student_email'] ?? '') . '</span>'
            . '<form method="post" action="' . e(url('index.php')) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="action" value="switch"><button class="linkbtn">Not you?</button></form></nav>';
    }
    echo '</div></header><main class="wrap">';
    if ($f = flash()) {
        echo '<div class="flash ' . e($f[0]) . '">' . e($f[1]) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main></body></html>';
}

/** WordPress-style centered login layout: logo, white card, links underneath. */
function login_header(string $title): void
{
    html_head($title, 'login');
    echo '<div class="login"><h1 class="login-logo"><a href="' . e(url('')) . '">' . brand_html() . '</a></h1>';
    if ($f = flash()) {
        echo '<div class="login-msg ' . e($f[0]) . '">' . e($f[1]) . '</div>';
    }
}

function login_footer(string $linksHtml = ''): void
{
    echo ($linksHtml ? '<p class="login-links">' . $linksHtml . '</p>' : '') . '</div></body></html>';
}

// ---- auth ----

function current_student(): ?array
{
    if (empty($_SESSION['student_id'])) {
        return null;
    }
    // A session started from a trusted device ends as soon as that device has been forgotten ("Not you?").
    if (!empty($_SESSION['device_id']) && !q('SELECT 1 FROM device_tokens WHERE id = ?', [$_SESSION['device_id']])->fetch()) {
        unset($_SESSION['student_id'], $_SESSION['student_email'], $_SESSION['device_id']);
        return null;
    }
    $s = q('SELECT * FROM students WHERE id = ?', [$_SESSION['student_id']])->fetch();
    return $s ?: null;
}

function require_student(): array
{
    $s = current_student();
    if (!$s) {
        redirect('index.php');
    }
    return $s;
}

function require_teacher(): array
{
    if (!empty($_SESSION['teacher_id']) && time() - (int)($_SESSION['teacher_seen'] ?? 0) > TEACHER_IDLE_SECONDS) {
        unset($_SESSION['teacher_id'], $_SESSION['teacher_seen']);
        flash('You were signed out after being inactive. Please log in again.');
    }
    if (!empty($_SESSION['teacher_id'])) {
        $_SESSION['teacher_seen'] = time();
        $t = q('SELECT * FROM teachers WHERE id = ?', [$_SESSION['teacher_id']])->fetch();
        if ($t) {
            return $t;
        }
    }
    redirect('admin/login.php');
}

// Minimal fallbacks so the app still runs on hosts without the mbstring extension.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s): string { return strtolower($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $len = null): string
    {
        return preg_match_all('/./us', $s, $m) ? implode('', array_slice($m[0], $start, $len)) : '';
    }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $s, int $start, int $width, string $trim = ''): string
    {
        return strlen($s) <= $width ? $s : mb_substr($s, $start, $width - strlen($trim)) . $trim;
    }
}

/** Neutralize spreadsheet formulas in user-supplied CSV cells. */
function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}
