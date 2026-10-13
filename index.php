<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

const CODE_TTL_MIN = 10;
const CODE_MAX_ATTEMPTS = 5;
const CODES_PER_HOUR = 5;
const DEVICE_TTL_DAYS = 365;

function code_hash(string $email, string $code): string
{
    return hash('sha256', $email . '|' . $code);
}

function start_student_session(int $studentId, string $email, ?int $deviceId): void
{
    session_regenerate_id(true);
    $_SESSION['student_id'] = $studentId;
    $_SESSION['student_email'] = $email;
    $_SESSION['device_id'] = $deviceId;
    unset($_SESSION['pending_email']);
}

$switch = isset($_GET['switch']);
if ($switch) {
    if (!empty($_SESSION['device_id'])) { // forget this browser
        q('DELETE FROM device_tokens WHERE id = ?', [$_SESSION['device_id']]);
    }
    unset($_SESSION['student_id'], $_SESSION['student_email'], $_SESSION['pending_email'], $_SESSION['device_id']);
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $post ? (string)($_POST['action'] ?? '') : '';

// Silent sign-in from a trusted browser (called by JS with the token kept in localStorage)
if ($action === 'resume') {
    header('Content-Type: application/json');
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $token = (string)($_POST['token'] ?? '');
    $row = q('SELECT d.id, s.id AS sid FROM device_tokens d JOIN students s ON s.id = d.student_id
              WHERE s.email = ? AND d.token_hash = ? AND d.last_used > ?',
        [$email, hash('sha256', $token), date('Y-m-d H:i:s', strtotime('-' . DEVICE_TTL_DAYS . ' days'))])->fetch();
    if ($row) {
        $now = date('Y-m-d H:i:s');
        q('UPDATE device_tokens SET last_used = ? WHERE id = ?', [$now, $row['id']]);
        q('UPDATE students SET last_seen = ? WHERE id = ?', [$now, $row['sid']]);
        start_student_session((int)$row['sid'], $email, (int)$row['id']);
        echo '{"ok":true}';
    } else {
        echo '{"ok":false}';
    }
    exit;
}

$error = null;
$email = '';

if ($action === 'email') {
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (!is_school_email($email)) {
        $domains = cfg('school_domains', []);
        $error = 'Please enter your school email' . ($domains ? ' (@' . implode(', @', $domains) . ')' : '') . '.';
    } else {
        q('DELETE FROM login_codes WHERE expires_at < ?', [date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $recent = (int)q('SELECT COUNT(*) FROM login_codes WHERE email = ? AND created_at > ?',
            [$email, date('Y-m-d H:i:s', strtotime('-1 hour'))])->fetchColumn();
        if ($recent >= CODES_PER_HOUR) {
            $error = 'Too many codes requested. Please try again in an hour.';
        } else {
            $code = sprintf('%06d', random_int(0, 999999));
            q('INSERT INTO login_codes (email, code_hash, created_at, expires_at) VALUES (?,?,?,?)',
                [$email, code_hash($email, $code), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+' . CODE_TTL_MIN . ' minutes'))]);
            $id = (int)db()->lastInsertId();
            $ok = send_mail($email, "Your login code: $code",
                email_shell('Your login code', '<p style="font-size:32px;letter-spacing:6px;font-weight:bold">' . $code . '</p>'
                    . '<p>Enter this code to continue. It is valid for ' . CODE_TTL_MIN . ' minutes. If you did not ask for it, ignore this email.</p>'),
                "Your login code: $code\nIt is valid for " . CODE_TTL_MIN . " minutes. If you did not ask for it, ignore this email.");
            if ($ok) {
                $_SESSION['pending_email'] = $email;
            } else {
                q('DELETE FROM login_codes WHERE id = ?', [$id]);
                $error = 'We could not send the email. Please try again later or contact your teacher.';
            }
        }
    }
} elseif ($action === 'code') {
    $email = (string)($_SESSION['pending_email'] ?? '');
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $row = $email === '' ? false : q('SELECT * FROM login_codes WHERE email = ? AND expires_at > ? ORDER BY id DESC LIMIT 1',
        [$email, date('Y-m-d H:i:s')])->fetch();
    if (!$row || $row['attempts'] >= CODE_MAX_ATTEMPTS) {
        unset($_SESSION['pending_email']);
        $error = 'That code has expired or was entered wrong too many times. Request a new one.';
    } else {
        q('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        if (hash_equals($row['code_hash'], code_hash($email, $code))) {
            q('DELETE FROM login_codes WHERE email = ?', [$email]);
            $now = date('Y-m-d H:i:s');
            q('INSERT INTO students (email, first_seen, last_seen) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)', [$email, $now, $now]);
            $sid = (int)q('SELECT id FROM students WHERE email = ?', [$email])->fetchColumn();
            $token = bin2hex(random_bytes(32));
            q('INSERT INTO device_tokens (student_id, token_hash, created_at, last_used) VALUES (?,?,?,?)',
                [$sid, hash('sha256', $token), $now, $now]);
            $deviceId = (int)db()->lastInsertId();
            start_student_session($sid, $email, $deviceId);
            // Hand the secret to this browser once, then continue (the redirect in recap.php would skip a normal page).
            echo '<!doctype html><meta charset="utf-8"><title>Signing in</title><script>try{localStorage.setItem("recap_email",'
                . json_encode($email) . ');localStorage.setItem("recap_token",' . json_encode($token) . ');}catch(e){}'
                . 'location.replace(' . json_encode(url('recap.php')) . ');</script>'
                . '<noscript><a href="' . e(url('recap.php')) . '">Continue</a></noscript>';
            exit;
        }
        $error = 'Wrong code. Check the email and try again.';
    }
}

$pending = $_SESSION['pending_email'] ?? null;

login_header('Start');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<?php if ($pending): ?>
  <form method="post" class="login-card">
    <p class="login-help">We emailed a 6-digit code to <b><?= e($pending) ?></b>. It is valid for <?= CODE_TTL_MIN ?> minutes.</p>
    <input type="hidden" name="action" value="code">
    <label for="code">Login code</label>
    <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus>
    <button type="submit">Continue</button>
  </form>
  <?php login_footer('<a href="' . e(url('index.php?switch=1')) . '">&larr; Use a different email</a>'); ?>
<?php else: ?>
  <form method="post" id="login" class="login-card" autocomplete="email">
    <input type="hidden" name="action" value="email">
    <label for="email">School email</label>
    <input type="email" name="email" id="email" value="<?= e($email) ?>" required autofocus>
    <p class="login-help" style="clear:both">On a new device we email you a code once. After that this browser stays signed in.</p>
    <button type="submit">Send code</button>
  </form>
  <script>
  (function () {
    var switching = <?= $switch ? 'true' : 'false' ?>;
    try {
      if (switching) { localStorage.removeItem('recap_email'); localStorage.removeItem('recap_token'); return; }
      var email = localStorage.getItem('recap_email'), token = localStorage.getItem('recap_token');
      if (email) { document.getElementById('email').value = email; }
      if (email && token) {
        var body = new URLSearchParams({action: 'resume', email: email, token: token});
        fetch(location.pathname, {method: 'POST', body: body, credentials: 'same-origin'})
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.ok) { location.href = <?= json_encode(url('recap.php')) ?>; }
            else { localStorage.removeItem('recap_token'); }
          }).catch(function () {});
      }
    } catch (e) { /* localStorage unavailable: a code is needed each visit */ }
  })();
  </script>
  <?php login_footer('<a href="' . e(url('admin/login.php')) . '">Teacher login</a>'); ?>
<?php endif;
