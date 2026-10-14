<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
ensure_auth_tables();

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $post ? (string)($_POST['action'] ?? '') : '';
if ($post) {
    csrf_check();
}

if ($action === 'switch') { // "Not you?"
    forget_this_device();
    redirect('index.php?switched=1');
}

// Returning student on a trusted browser: sign in silently.
if (!$post && !isset($_GET['switched']) && !current_student() && resume_from_cookie()) {
    redirect('recap.php');
}

$error = null;
$email = '';

if ($action === 'email') {
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (!is_school_email($email)) {
        $domains = cfg('school_domains', []);
        $error = 'Please enter your school email' . ($domains ? ' (@' . implode(', @', $domains) . ')' : '') . '.';
    } elseif (!ip_allow(client_ip())) {
        $error = 'Too many requests from your network. Please try again later.';
    } else {
        [$status, $code] = create_login_code($email);
        if ($status === 'locked') {
            $error = 'Too many wrong codes for this email. Please try again in an hour.';
        } elseif ($status === 'limited') {
            $error = 'Too many codes requested. Please try again in an hour.';
        } elseif ($status === 'cooldown') {
            $_SESSION['pending_email'] = $email; // a code was sent a moment ago: use that one
        } elseif (send_mail($email, "Your login code: $code",
            email_shell('Your login code', '<p style="font-size:32px;letter-spacing:6px;font-weight:bold">' . $code . '</p>'
                . '<p>Enter this code to continue. It is valid for ' . CODE_TTL_MIN . ' minutes. If you did not ask for it, ignore this email.</p>'),
            "Your login code: $code\nIt is valid for " . CODE_TTL_MIN . " minutes. If you did not ask for it, ignore this email.")) {
            $_SESSION['pending_email'] = $email;
        } else {
            revoke_login_code($email);
            $error = 'We could not send the email. Please try again later or contact your teacher.';
        }
    }
} elseif ($action === 'code') {
    $email = (string)($_SESSION['pending_email'] ?? '');
    $result = $email === '' ? 'expired' : check_login_code($email, preg_replace('/\D/', '', (string)($_POST['code'] ?? '')));
    if ($result === 'ok') {
        sign_in_verified($email);
        redirect('recap.php');
    }
    if ($result === 'wrong') {
        $error = 'Wrong code. Check the email and try again.';
    } else {
        unset($_SESSION['pending_email']);
        $error = $result === 'locked'
            ? 'Too many wrong codes for this email. Please try again in an hour.'
            : 'That code has expired. Request a new one.';
    }
}

$pending = $_SESSION['pending_email'] ?? null;
$switched = isset($_GET['switched']);

login_header('Start');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<?php if ($pending): ?>
  <form method="post" class="login-card">
    <?= csrf_field() ?>
    <p class="login-help">We emailed a 6-digit code to <b><?= e($pending) ?></b>. It is valid for <?= CODE_TTL_MIN ?> minutes.</p>
    <input type="hidden" name="action" value="code">
    <label for="code">Login code</label>
    <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus>
    <button type="submit">Continue</button>
  </form>
  <?php login_footer('<form method="post" class="inline">' . csrf_field()
      . '<input type="hidden" name="action" value="switch"><button class="linkbtn">&larr; Use a different email</button></form>'); ?>
<?php else: ?>
  <form method="post" id="login" class="login-card" autocomplete="email">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="email">
    <label for="email">School email</label>
    <input type="email" name="email" id="email" value="<?= e($email) ?>" required autofocus>
    <p class="login-help" style="clear:both">On a new device we email you a code once. After that this browser stays signed in.</p>
    <button type="submit">Send code</button>
  </form>
  <script>
  (function () {
    try {
      if (<?= $switched ? 'true' : 'false' ?>) { localStorage.removeItem('recap_email'); return; }
      var saved = localStorage.getItem('recap_email'), input = document.getElementById('email');
      if (saved && !input.value) { input.value = saved; } // convenience only; never overwrite what was typed
    } catch (e) {}
  })();
  </script>
  <?php login_footer('<a href="' . e(url('admin/login.php')) . '">Teacher login</a>'); ?>
<?php endif;
