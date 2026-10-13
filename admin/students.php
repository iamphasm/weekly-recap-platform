<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_teacher();

$rows = q('SELECT s.id, s.email, s.last_seen, COUNT(a.id) AS n, AVG(a.score/a.max_score*100) AS avgp,
                  (SELECT a2.score/a2.max_score*100 FROM attempts a2 WHERE a2.student_id = s.id ORDER BY a2.submitted_at DESC LIMIT 1) AS lastp
           FROM students s LEFT JOIN attempts a ON a.student_id = s.id GROUP BY s.id ORDER BY s.email')->fetchAll();

page_header('Students', 'admin');
?>
<h1>Students</h1>
<table>
  <tr><th>Email</th><th>Recaps done</th><th>Average</th><th>Latest</th><th>Last seen</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= e(url('admin/student.php?id=' . $r['id'])) ?>"><?= e($r['email']) ?></a></td>
      <td><?= (int)$r['n'] ?></td>
      <td><?= $r['avgp'] === null ? '-' : round((float)$r['avgp']) . '%' ?></td>
      <td><?= $r['lastp'] === null ? '-' : round((float)$r['lastp']) . '%' ?></td>
      <td class="small"><?= e(fmt_dt($r['last_seen'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No students yet.</td></tr><?php endif; ?>
</table>
<?php page_footer();
