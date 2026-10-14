<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);

// Student sign-in: emailed one-time codes + trusted-device cookie.

const CODE_TTL_MIN = 10;
const CODE_RESEND_COOLDOWN = 60;     // seconds; a quick second click re-uses the code already sent
const CODES_PER_HOUR = 5;            // per email
const MAX_FAILS_PER_HOUR = 10;       // wrong codes per email, counted across all codes sent in the hour
const REQUESTS_PER_IP_HOUR = 100;    // generous: a whole classroom may share one school IP
const DEVICE_TTL_DAYS = 365;
const DEVICE_ROTATE_DAYS = 7;
const TEACHER_FAILS_PER_IP_HOUR = 10;
const TEACHER_SLOWDOWN_AFTER_EMAIL_FAILS = 20; // slows guessing against one account without ever locking the real teacher out
const DEVICE_COOKIE = 'recap_device';
const TEACHER_INVITE_TTL_MIN = 60;   // same 10 wrong guesses per hour as any code, so a longer life adds no guesses

/** Create the sign-in tables if an older install is missing them (needs the CREATE privilege). */
function ensure_auth_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!q("SHOW COLUMNS FROM teachers LIKE 'session_version'")->fetch()) {
        db()->exec('ALTER TABLE teachers ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!q("SHOW COLUMNS FROM students LIKE 'name'")->fetch()) {
        db()->exec('ALTER TABLE students ADD COLUMN name VARCHAR(120) NULL AFTER email');
    }
    $qtype = q("SHOW COLUMNS FROM questions LIKE 'type'")->fetch();
    if ($qtype && !str_contains((string)$qtype['Type'], "'match'")) {
        db()->exec("ALTER TABLE questions MODIFY type ENUM('single','multi','tf','text','match') NOT NULL");
    }
    if (!q("SHOW COLUMNS FROM questions LIKE 'left_title'")->fetch()) {
        db()->exec('ALTER TABLE questions ADD COLUMN left_title VARCHAR(80) NULL, ADD COLUMN right_title VARCHAR(80) NULL');
    }
    if (!q("SHOW COLUMNS FROM options LIKE 'pair_label'")->fetch()) {
        db()->exec('ALTER TABLE options ADD COLUMN pair_label VARCHAR(500) NULL AFTER label');
    }
    if (!q("SHOW COLUMNS FROM tests LIKE 'time_limit_min'")->fetch()) {
        db()->exec('ALTER TABLE tests ADD COLUMN time_limit_min SMALLINT UNSIGNED NULL AFTER published');
    }
    db()->exec('CREATE TABLE IF NOT EXISTS test_starts (
        test_id INT UNSIGNED NOT NULL, student_id INT UNSIGNED NOT NULL, started_at DATETIME NOT NULL,
        PRIMARY KEY (test_id, student_id),
        FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!q("SHOW TABLES LIKE 'login_challenges'")->fetch() || !q("SHOW TABLES LIKE 'device_tokens'")->fetch()) {
        $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(APP_ROOT . '/sql/upgrade_otp.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            db()->exec($stmt);
        }
    }
}

function code_hash(string $email, string $code): string
{
    return hash('sha256', $email . '|' . $code);
}

function now_s(): string
{
    return date('Y-m-d H:i:s');
}

/** Delete stale sign-in bookkeeping so the tables cannot grow without limit. Cheap enough to run now and then. */
function prune_auth_tables(): void
{
    $day = date('Y-m-d H:i:s', time() - 86400);
    q('DELETE FROM login_challenges WHERE window_start < ? AND (expires_at IS NULL OR expires_at < ?)', [$day, now_s()]);
    q('DELETE FROM login_ips WHERE window_start < ?', [$day]);
    q('DELETE FROM device_tokens WHERE last_used < ?', [date('Y-m-d H:i:s', time() - DEVICE_TTL_DAYS * 86400)]);
}

function maybe_prune_auth_tables(): void
{
    if (random_int(1, 50) === 1) {
        prune_auth_tables();
    }
}

function throttle_key(string $kind, string $value): string
{
    return substr($kind . md5($value), 0, 40);
}

/** Hits recorded for a key in the current hour. */
function throttle_peek(string $key): int
{
    $r = q('SELECT hits, window_start FROM login_ips WHERE ip = ?', [$key])->fetch();
    return ($r && strtotime($r['window_start']) >= time() - 3600) ? (int)$r['hits'] : 0;
}

/**
 * Record a hit for a key (hourly window) and return this request's own total.
 * LAST_INSERT_ID(expr) hands back the value this statement wrote, so parallel requests each get a distinct count.
 */
function throttle_hit(string $key): int
{
    maybe_prune_auth_tables();
    $now = now_s();
    $cut = date('Y-m-d H:i:s', time() - 3600);
    q('INSERT INTO login_ips (ip, hits, window_start) VALUES (?,1,?)
       ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(window_start < ?, 1, hits + 1)), window_start = IF(window_start < ?, ?, window_start)',
        [$key, $now, $cut, $cut, $now]);
    $n = (int)db()->lastInsertId();
    return $n > 0 ? $n : 1; // 0 means a brand-new row
}

/** Give back one hit (a successful attempt must not use up the budget meant for failures). */
function throttle_undo(string $key): void
{
    q('UPDATE login_ips SET hits = GREATEST(hits, 1) - 1 WHERE ip = ?', [$key]);
}

/** Count a code request from this IP; false once the hourly limit is exceeded. */
function ip_allow(string $ip): bool
{
    return throttle_hit(throttle_key('i', $ip)) <= REQUESTS_PER_IP_HOUR;
}

/**
 * Prepare a new code for $email. Returns [status, code]; status is one of
 * 'sent' (caller must email $code), 'cooldown' (a code was just sent, nothing to do), 'limited', 'locked'.
 */
function create_login_code(string $email, int $ttlMin = CODE_TTL_MIN): array
{
    maybe_prune_auth_tables();
    $pdo = db();
    $now = time();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO login_challenges (email, window_start) VALUES (?,?) ON DUPLICATE KEY UPDATE email = email', [$email, now_s()]);
        $r = q('SELECT * FROM login_challenges WHERE email = ? FOR UPDATE', [$email])->fetch();
        $windowStart = $r['window_start'];
        if (strtotime($windowStart) < $now - 3600) {
            $r['requests'] = 0;
            $r['attempts'] = 0;
            $windowStart = now_s();
        }
        $status = null;
        if ($r['attempts'] >= MAX_FAILS_PER_HOUR) {
            $status = 'locked';
        } elseif ($r['code_hash'] && strtotime((string)$r['expires_at']) > $now && $now - strtotime((string)$r['last_sent']) < CODE_RESEND_COOLDOWN) {
            $status = 'cooldown';
        } elseif ($r['requests'] >= CODES_PER_HOUR) {
            $status = 'limited';
        }
        if ($status) {
            q('UPDATE login_challenges SET requests = ?, attempts = ?, window_start = ? WHERE email = ?',
                [$r['requests'], $r['attempts'], $windowStart, $email]);
            $pdo->commit();
            return [$status, null];
        }
        $code = sprintf('%06d', random_int(0, 999999));
        q('UPDATE login_challenges SET code_hash = ?, expires_at = ?, last_sent = ?, requests = ?, attempts = ?, window_start = ? WHERE email = ?',
            [code_hash($email, $code), date('Y-m-d H:i:s', $now + $ttlMin * 60), now_s(), $r['requests'] + 1, $r['attempts'], $windowStart, $email]);
        $pdo->commit();
        return ['sent', $code];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** The email could not be sent: invalidate the code and give the request back. */
function revoke_login_code(string $email): void
{
    q('UPDATE login_challenges SET code_hash = NULL, expires_at = NULL, last_sent = NULL, requests = GREATEST(requests, 1) - 1 WHERE email = ?', [$email]);
}

/** Returns 'ok', 'wrong', 'expired' or 'locked'. The row is locked, so parallel guesses are counted one by one. */
function check_login_code(string $email, string $code): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = q('SELECT * FROM login_challenges WHERE email = ? FOR UPDATE', [$email])->fetch();
        if (!$r || !$r['code_hash'] || strtotime((string)$r['expires_at']) <= time()) {
            $pdo->commit();
            return 'expired';
        }
        $attempts = (int)$r['attempts'];
        $windowStart = $r['window_start'];
        if (strtotime($windowStart) < time() - 3600) {
            $attempts = 0;
            $windowStart = now_s();
            q('UPDATE login_challenges SET requests = 0 WHERE email = ?', [$email]);
        }
        if ($attempts >= MAX_FAILS_PER_HOUR) {
            $pdo->commit();
            return 'locked';
        }
        if (hash_equals($r['code_hash'], code_hash($email, $code))) {
            q('UPDATE login_challenges SET code_hash = NULL, expires_at = NULL, attempts = 0, window_start = ? WHERE email = ?', [$windowStart, $email]);
            $pdo->commit();
            return 'ok';
        }
        q('UPDATE login_challenges SET attempts = ?, window_start = ? WHERE email = ?', [$attempts + 1, $windowStart, $email]);
        $pdo->commit();
        return 'wrong';
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---- sessions and trusted devices ----

function start_student_session(int $studentId, string $email, ?int $deviceId): void
{
    session_regenerate_id(true);
    $_SESSION['student_id'] = $studentId;
    $_SESSION['student_email'] = $email;
    $_SESSION['device_id'] = $deviceId;
    unset($_SESSION['pending_email']);
}

function set_device_cookie(?string $token): void
{
    $path = base_path() . '/';
    setcookie(DEVICE_COOKIE, $token ?? '', [
        'expires' => $token === null ? time() - 3600 : time() + DEVICE_TTL_DAYS * 86400,
        'path' => $path,
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Verified student: find/create the account, remember this browser, start the session. */
function sign_in_verified(string $email): int
{
    $now = now_s();
    q('INSERT INTO students (email, first_seen, last_seen) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)', [$email, $now, $now]);
    $sid = (int)q('SELECT id FROM students WHERE email = ?', [$email])->fetchColumn();
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO device_tokens (student_id, token_hash, created_at, last_used) VALUES (?,?,?,?)',
        [$sid, hash('sha256', $token), $now, $now]);
    $deviceId = (int)db()->lastInsertId();
    set_device_cookie($token);
    start_student_session($sid, $email, $deviceId);
    return $sid;
}

/** Sign in from the trusted-device cookie, if there is a valid one. */
function resume_from_cookie(): bool
{
    $token = (string)($_COOKIE[DEVICE_COOKIE] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return false;
    }
    $row = q('SELECT d.id, d.created_at, s.id AS sid, s.email FROM device_tokens d JOIN students s ON s.id = d.student_id
              WHERE d.token_hash = ? AND d.last_used > ?',
        [hash('sha256', $token), date('Y-m-d H:i:s', time() - DEVICE_TTL_DAYS * 86400)])->fetch();
    if (!$row) {
        set_device_cookie(null);
        return false;
    }
    $now = now_s();
    if (strtotime($row['created_at']) < time() - DEVICE_ROTATE_DAYS * 86400) { // rotate the secret now and then
        $new = bin2hex(random_bytes(32));
        q('UPDATE device_tokens SET token_hash = ?, created_at = ?, last_used = ? WHERE id = ?', [hash('sha256', $new), $now, $now, $row['id']]);
        set_device_cookie($new);
    } else {
        q('UPDATE device_tokens SET last_used = ? WHERE id = ?', [$now, $row['id']]);
    }
    q('UPDATE students SET last_seen = ? WHERE id = ?', [$now, $row['sid']]);
    start_student_session((int)$row['sid'], $row['email'], (int)$row['id']);
    return true;
}

/** "Not you?": forget this browser and sign out. */
function forget_this_device(): void
{
    $token = (string)($_COOKIE[DEVICE_COOKIE] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/', $token)) {
        q('DELETE FROM device_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    }
    if (!empty($_SESSION['device_id'])) {
        q('DELETE FROM device_tokens WHERE id = ?', [$_SESSION['device_id']]);
    }
    set_device_cookie(null);
    unset($_SESSION['student_id'], $_SESSION['student_email'], $_SESSION['pending_email'], $_SESSION['device_id']);
}

// ---- inviting teachers ----

/** Key under which a teacher's activation code is kept (cannot collide with a student's email address). */
function teacher_code_key(string $email): string
{
    return 'teacher:' . $email;
}

/** Email a pending teacher a fresh activation code. Returns 'sent', 'recent' (one went out a moment ago), 'limited' or 'failed'. */
function issue_teacher_invite(array $teacher): string
{
    $key = teacher_code_key($teacher['email']);
    [$status, $code] = create_login_code($key, TEACHER_INVITE_TTL_MIN);
    if ($status !== 'sent') {
        return $status === 'cooldown' ? 'recent' : 'limited';
    }
    if (send_teacher_invite($teacher['name'], $teacher['email'], $code)) {
        return 'sent';
    }
    revoke_login_code($key);
    return 'failed';
}
