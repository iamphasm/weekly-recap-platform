<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Only reachable right after entering a valid activation code (15 minutes to finish).
$act = $_SESSION['activate_teacher'] ?? null;
$teacher = ($act && time() - (int)$act['at'] <= 900)
    ? q("SELECT * FROM teachers WHERE id = ? AND password_hash = ''", [(int)$act['id']])->fetch()
    : null;
if (!$teacher) {
    unset($_SESSION['activate_teacher']);
    flash('Please enter your activation code first.');
    redirect('admin/activate.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = (string)($_POST['new'] ?? '');
    if (strlen($new) < 10) {
        $error = 'The password must be at least 10 characters.';
    } elseif ($new !== (string)($_POST['confirm'] ?? '')) {
        $error = 'The passwords do not match.';
    } else {
        // The "password_hash = ''" condition makes this work once only.
        $st = q("UPDATE teachers SET password_hash = ?, session_version = session_version + 1 WHERE id = ? AND password_hash = ''",
            [password_hash($new, PASSWORD_DEFAULT), $teacher['id']]);
        unset($_SESSION['activate_teacher']);
        session_regenerate_id(true);
        if ($st->rowCount() === 1) {
            flash('Password set. You can now log in with your email and password.', 'ok');
            redirect('admin/login.php');
        }
        redirect('admin/activate.php');
    }
}

login_header('Choose a password');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="login-card" autocomplete="off">
  <?= csrf_field() ?>
  <p class="login-help">Welcome, <?= e($teacher['name']) ?>. Choose a password for <b><?= e($teacher['email']) ?></b>.</p>
  <label for="new">Password (at least 10 characters)</label>
  <input type="password" name="new" id="new" required minlength="10" autocomplete="new-password" autofocus>
  <label for="confirm">Repeat password</label>
  <input type="password" name="confirm" id="confirm" required minlength="10" autocomplete="new-password">
  <button type="submit">Save password</button>
</form>
<?php login_footer();
