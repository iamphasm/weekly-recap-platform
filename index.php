<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$switch = isset($_GET['switch']);
if ($switch) {
    unset($_SESSION['student_id'], $_SESSION['student_email']);
}

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_email((string)($_POST['email'] ?? ''));
    if (!is_school_email($email)) {
        $domains = cfg('school_domains', []);
        $error = 'Please enter your school email' . ($domains ? ' (@' . implode(', @', $domains) . ')' : '') . '.';
    } else {
        $now = date('Y-m-d H:i:s');
        q('INSERT INTO students (email, first_seen, last_seen) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)', [$email, $now, $now]);
        $id = (int)q('SELECT id FROM students WHERE email = ?', [$email])->fetchColumn();
        session_regenerate_id(true);
        $_SESSION['student_id'] = $id;
        $_SESSION['student_email'] = $email;
        $_SESSION['remember_email'] = $email; // tells the page to store it in this browser
        redirect('recap.php');
    }
}

login_header('Start');
?>
<?php if ($error): ?><div class="login-msg"><?= e($error) ?></div><?php endif; ?>
<form method="post" id="login" class="login-card" autocomplete="email">
  <label for="email">School email</label>
  <input type="email" name="email" id="email" value="<?= e($email) ?>" required autofocus>
  <button type="submit">Continue</button>
</form>
<script>
(function () {
  var KEY = 'recap_email', form = document.getElementById('login'), input = document.getElementById('email');
  var switching = <?= $switch ? 'true' : 'false' ?>;
  try {
    if (switching) { localStorage.removeItem(KEY); }
    var saved = localStorage.getItem(KEY);
    if (saved && !switching && !input.value && <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? 'false' : 'true' ?>) {
      input.value = saved;
      form.submit();
    }
    form.addEventListener('submit', function () { localStorage.setItem(KEY, input.value.trim().toLowerCase()); });
  } catch (e) { /* localStorage unavailable: typing the email each time still works */ }
})();
</script>
<?php login_footer('<a href="' . e(url('admin/login.php')) . '">Teacher login</a>');
