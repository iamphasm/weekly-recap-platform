<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables();
$me = require_teacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $invite = function (array $t): void {
        $r = issue_teacher_invite($t);
        if ($r === 'sent') {
            flash("An activation code was emailed to {$t['email']}.", 'ok');
        } elseif ($r === 'recent') {
            flash("A code was emailed to {$t['email']} a moment ago. They can also ask for a new one on the activation page.", 'ok');
        } elseif ($r === 'limited') {
            flash('Too many codes were requested for this address. Try again in an hour.');
        } else {
            flash("The email to {$t['email']} could not be sent (check the mail settings).");
        }
    };

    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = normalize_email((string)($_POST['email'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            flash('Enter a name and a valid email address.');
        } elseif (q('SELECT 1 FROM teachers WHERE email = ?', [$email])->fetch()) {
            flash('A teacher with that email already exists.');
        } else {
            // No password yet: an empty hash can never match a login until the invited person sets one.
            // Random starting session_version: if a removed teacher's id is ever reused (MySQL can do that after a restart),
            // the old teacher's leftover session must not match the new account.
            q('INSERT INTO teachers (name, email, password_hash, session_version, created_at) VALUES (?,?,?,?,?)',
                [mb_substr($name, 0, 120), $email, '', random_int(1000, 2000000000), date('Y-m-d H:i:s')]);
            $invite(q('SELECT * FROM teachers WHERE email = ?', [$email])->fetch());
        }
    } elseif ($action === 'resend') {
        $t = q("SELECT * FROM teachers WHERE id = ? AND password_hash = ''", [(int)($_POST['id'] ?? 0)])->fetch();
        $t ? $invite($t) : flash('That account is already active.');
    } elseif ($action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash('You cannot remove your own account.');
        } else {
            q('DELETE FROM teachers WHERE id = ?', [$id]); // their sessions end because the account no longer exists
            flash('Account removed.', 'ok');
        }
    }
    redirect('admin/accounts.php');
}

$teachers = q("SELECT id, name, email, created_at, password_hash = '' AS pending FROM teachers ORDER BY name")->fetchAll();

page_header('Accounts', 'admin');
?>
<h1>Accounts</h1>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <b>Add teacher / admin</b> <span class="muted small">- they get an email with an activation code and a link where they enter it and choose a password.</span>
  <div class="grid2">
    <label>Name <input type="text" name="name" required maxlength="120"></label>
    <label>Email <input type="email" name="email" required maxlength="180"></label>
  </div>
  <button type="submit">Add and send code</button>
</form>
<table>
  <tr><th>Name</th><th>Email</th><th>Status</th><th>Added</th><th></th></tr>
  <?php foreach ($teachers as $t): ?>
    <tr>
      <td><?= e($t['name']) ?><?= (int)$t['id'] === (int)$me['id'] ? ' <span class="muted small">(you)</span>' : '' ?></td>
      <td><?= e($t['email']) ?></td>
      <td><?= $t['pending'] ? '<span class="tag bad">Waiting for activation</span>' : '<span class="tag ok">Active</span>' ?></td>
      <td class="small"><?= e(fmt_dt($t['created_at'])) ?></td>
      <td>
        <?php if ($t['pending']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="secondary">Resend code</button></form>
        <?php endif; ?>
        <?php if ((int)$t['id'] !== (int)$me['id']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="danger" onclick="return confirm('Remove <?= e(addslashes($t['name'])) ?>? They will no longer be able to log in.')">Remove</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
<?php page_footer();
