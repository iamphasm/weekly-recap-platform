<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables(); // also adds teachers.session_version on older installs
$teacher = require_teacher();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    // Counted before the check so parallel guesses cannot beat the limit; a success refunds its own attempt.
    $key = throttle_key('p', (string)$teacher['id']);
    if (throttle_hit($key) > TEACHER_FAILS_PER_IP_HOUR) {
        $error = 'Too many attempts. Please try again in an hour.';
    } elseif (!password_verify($current, $teacher['password_hash'])) {
        usleep(400000);
        $error = 'Your current password is wrong.';
    } else {
        throttle_undo($key);
        if (strlen($new) < 10) {
            $error = 'The new password must be at least 10 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The new passwords do not match.';
        } elseif ($new === $current) {
            $error = 'Choose a password different from the current one.';
        } else {
            q('UPDATE teachers SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $teacher['id']]);
            // Every other session of this teacher is now invalid; keep this one.
            $_SESSION['teacher_ver'] = (int)q('SELECT session_version FROM teachers WHERE id = ?', [$teacher['id']])->fetchColumn();
            session_regenerate_id(true);
            flash('Password changed.', 'ok');
            redirect('admin/profile.php');
        }
    }
}

page_header('Profile', 'admin');
?>
<h1>Profile</h1>
<p class="muted"><?= e($teacher['name']) ?> - <?= e($teacher['email']) ?></p>
<form method="post" class="card" autocomplete="off">
  <h2 style="margin-top:0">Change password</h2>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
  <label>New password (at least 10 characters) <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
  <label>Repeat new password <input type="password" name="confirm" required minlength="10" autocomplete="new-password"></label>
  <button type="submit">Change password</button>
</form>
<?php page_footer();
