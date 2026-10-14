<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables(); // adds students.name on older installs
require_teacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $name = $name === '' ? '' : mb_substr($name, 0, 120);
    if (!is_school_email($email)) {
        $domains = cfg('school_domains', []);
        flash('Enter a school email' . ($domains ? ' (@' . implode(', @', $domains) . ')' : '') . '.');
    } else {
        $now = date('Y-m-d H:i:s');
        q('INSERT INTO students (email, name, first_seen, last_seen) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE name = IF(VALUES(name) IS NULL, name, VALUES(name))', [$email, $name === '' ? null : $name, $now, $now]);
        if (send_student_invite($email, $name)) {
            flash("Student added. An invitation was emailed to $email; they get their login code when they enter their email on the login page.", 'ok');
        } else {
            flash("Student added, but the email to $email could not be sent (check the mail settings). They can still log in with their school email.");
        }
    }
    redirect('admin/students.php');
}

$rows = q('SELECT s.id, s.email, s.name, s.last_seen, COUNT(a.id) AS n, AVG(a.score/a.max_score*100) AS avgp,
                  (SELECT a2.score/a2.max_score*100 FROM attempts a2 WHERE a2.student_id = s.id ORDER BY a2.submitted_at DESC LIMIT 1) AS lastp
           FROM students s LEFT JOIN attempts a ON a.student_id = s.id GROUP BY s.id ORDER BY s.email')->fetchAll();

page_header('Students', 'admin');
?>
<h1>Students</h1>
<form method="post" class="card">
  <?= csrf_field() ?>
  <b>Add student</b> <span class="muted small">- they get an invitation email. Adding an existing email updates the name.</span>
  <div class="grid2">
    <label>School email <input type="email" name="email" required></label>
    <label>Name <span class="muted small">(optional)</span> <input type="text" name="name" maxlength="120"></label>
  </div>
  <button type="submit">Add and send invitation</button>
</form>
<table>
  <tr><th>Student</th><th>Recaps done</th><th>Average</th><th>Latest</th><th>Last seen</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('admin/student.php?id=' . $r['id'])) ?>"><?= e(student_label($r)) ?></a><?php if (trim((string)$r['name']) !== ''): ?> <span class="muted small"><?= e($r['email']) ?></span><?php endif; ?></td>
      <td><?= (int)$r['n'] ?></td>
      <td><?= $r['avgp'] === null ? '-' : round((float)$r['avgp']) . '%' ?></td>
      <td><?= $r['lastp'] === null ? '-' : round((float)$r['lastp']) . '%' ?></td>
      <td class="small"><?= e(fmt_dt($r['last_seen'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No students yet.</td></tr><?php endif; ?>
</table>
<?php page_footer();
