<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_teacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        q('DELETE FROM tests WHERE id = ?', [$id]);
        flash('Test deleted.', 'ok');
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        q('UPDATE tests SET published = 1 - published WHERE id = ?', [$id]);
    }
    redirect('admin/');
}

$tests = q('SELECT t.*, (SELECT COUNT(*) FROM questions WHERE test_id=t.id) AS nq,
                   (SELECT COUNT(*) FROM attempts WHERE test_id=t.id) AS na,
                   (SELECT AVG(score/max_score*100) FROM attempts WHERE test_id=t.id) AS avgp
            FROM tests t ORDER BY t.opens_at DESC')->fetchAll();
$totalStudents = (int)q('SELECT COUNT(*) FROM students')->fetchColumn();

page_header('Tests', 'admin');
?>
<h1>Weekly recaps</h1>
<p>Student link: <code><?= e(app_url()) ?>/</code> - <?= $totalStudents ?> student(s) registered.
  <a class="btn" style="float:right" href="<?= e(url('admin/test_edit.php')) ?>">New test</a></p>
<table>
  <tr><th>Title</th><th>Open</th><th>Qs</th><th>Done</th><th>Avg</th><th>Status</th><th></th></tr>
  <?php foreach ($tests as $t):
      $now = date('Y-m-d H:i:s');
      $status = !$t['published'] ? 'Draft' : ($t['opens_at'] > $now ? 'Scheduled' : ($t['closes_at'] < $now ? 'Closed' : 'Open')); ?>
    <tr>
      <td><a href="<?= e(url('admin/test_edit.php?id=' . $t['id'])) ?>"><?= e($t['title']) ?></a></td>
      <td class="small"><?= e(fmt_dt($t['opens_at'])) ?><br>to <?= e(fmt_dt($t['closes_at'])) ?></td>
      <td><?= (int)$t['nq'] ?></td>
      <td><?= (int)$t['na'] ?></td>
      <td><?= $t['avgp'] === null ? '-' : round((float)$t['avgp']) . '%' ?></td>
      <td><?= e($status) ?></td>
      <td>
        <a href="<?= e(url('admin/test_stats.php?id=' . $t['id'])) ?>">Results</a>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="secondary" name="action" value="toggle"><?= $t['published'] ? 'Unpublish' : 'Publish' ?></button>
          <button class="danger" name="action" value="delete" onclick="return confirm('Delete this test and all its results?')">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tests): ?><tr><td colspan="7" class="muted">No tests yet.</td></tr><?php endif; ?>
</table>
<?php page_footer();
