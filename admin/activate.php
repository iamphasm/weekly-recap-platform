<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables();

$error = null;
$info = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
        $error = 'Enter the email address you were invited with.';
    } elseif (!ip_allow(client_ip())) {
        $error = 'Too many requests from your network. Please try again later.';
    } else {
        $t = q("SELECT * FROM teachers WHERE email = ? AND password_hash = ''", [$email])->fetch();
        if ($action === 'resend') {
            if ($t) {
                issue_teacher_invite($t);
            }
            // Same answer whether or not the address was invited, so this page cannot be used to look up accounts.
            $info = 'If that address has been invited and is waiting for activation, a new code is on its way.';
        } else {
            $result = $t ? check_login_code(teacher_code_key($email), preg_replace('/\D/', '', (string)($_POST['code'] ?? ''))) : 'expired';
            if ($result === 'ok') {
                session_regenerate_id(true);
                $_SESSION['activate_teacher'] = ['id' => (int)$t['id'], 'at' => time()];
                redirect('admin/set_password.php');
            }
            if (!$t) {
                usleep(400000);
            }
            $error = $result === 'locked'
                ? 'Too many wrong codes. Please try again in an hour.'
                : 'That code is wrong or has expired. You can ask for a new one below.';
        }
    }
}

login_header('Activate account');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="login-msg ok"><?= e($info) ?></div><?php endif; ?>
<form method="post" class="login-card">
  <?= csrf_field() ?><input type="hidden" name="action" value="code">
  <p class="login-help">Enter the email you were invited with and the 6-digit code we emailed you.</p>
  <label for="email">Email address</label>
  <input type="email" name="email" id="email" required autofocus value="<?= e($email) ?>">
  <label for="code">Activation code</label>
  <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required>
  <button type="submit">Continue</button>
</form>
<form method="post" class="login-card" style="margin-top:16px">
  <?= csrf_field() ?><input type="hidden" name="action" value="resend">
  <p class="login-help">No code, or it has expired? We can send a new one.</p>
  <label for="email2">Email address</label>
  <input type="email" name="email" id="email2" required value="<?= e($email) ?>">
  <button type="submit" class="secondary">Send a new code</button>
</form>
<?php login_footer('<a href="' . e(url('admin/login.php')) . '">&larr; Teacher login</a>');
