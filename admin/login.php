<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$count = (int)q('SELECT COUNT(*) FROM teachers')->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if ($count === 0) { // first-run setup: create the first teacher account
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10) {
            $error = 'Enter a name, a valid email and a password of at least 10 characters.';
        } else {
            q('INSERT INTO teachers (name, email, password_hash, created_at) VALUES (?,?,?,?)',
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            $t = q('SELECT id FROM teachers WHERE email = ?', [$email])->fetch();
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = (int)$t['id'];
            redirect('admin/');
        }
    } else {
        $t = q('SELECT * FROM teachers WHERE email = ?', [$email])->fetch();
        // Always run a hash check to keep timing similar for unknown emails.
        $hash = $t['password_hash'] ?? password_hash('x', PASSWORD_DEFAULT);
        if (password_verify($pass, $hash) && $t) {
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = (int)$t['id'];
            redirect('admin/');
        }
        usleep(400000);
        $error = 'Wrong email or password.';
    }
}

login_header($count === 0 ? 'Create teacher account' : 'Teacher login');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="login-card">
  <?= csrf_field() ?>
  <?php if ($count === 0): ?>
    <p class="login-help">No teacher account exists yet. Create the first one.</p>
    <label for="name">Name</label><input type="text" name="name" id="name" required>
  <?php endif; ?>
  <label for="email">Email address</label>
  <input type="email" name="email" id="email" required autofocus>
  <label for="password">Password</label>
  <div class="pw-wrap">
    <input type="password" name="password" id="password" required <?= $count === 0 ? 'minlength="10"' : '' ?>>
    <button type="button" id="pw-toggle" class="secondary" aria-label="Show password">&#128065;</button>
  </div>
  <button type="submit"><?= $count === 0 ? 'Create account' : 'Log In' ?></button>
</form>
<script>
document.getElementById('pw-toggle').addEventListener('click', function () {
  var i = document.getElementById('password');
  i.type = i.type === 'password' ? 'text' : 'password';
});
</script>
<?php login_footer('<a href="' . e(url('')) . '">&larr; Student login</a>');
