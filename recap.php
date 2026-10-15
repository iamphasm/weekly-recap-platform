<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
ensure_auth_tables(); // timer column on older installs
$student = require_student();

$now = date('Y-m-d H:i:s');
$open = q('SELECT t.*, (SELECT COUNT(*) FROM questions WHERE test_id = t.id) AS nq,
                  EXISTS(SELECT 1 FROM attempts a WHERE a.test_id = t.id AND a.student_id = ?) AS done
           FROM tests t WHERE t.published = 1 AND t.opens_at <= ? AND t.closes_at >= ?
           HAVING nq > 0 ORDER BY t.opens_at DESC', [$student['id'], $now, $now])->fetchAll();

$todo = array_values(array_filter($open, fn($t) => !$t['done']));
if (count($todo) === 1 && count($open) === 1) {
    redirect('take.php?id=' . $todo[0]['id']);
}

$history = q('SELECT a.test_id, a.score, a.max_score, a.submitted_at, t.title FROM attempts a
              JOIN tests t ON t.id = a.test_id WHERE a.student_id = ? ORDER BY a.submitted_at DESC', [$student['id']])->fetchAll();

page_header('Recaps');
?>
<h1>Hi<?= trim((string)($student['name'] ?? '')) !== '' ? ', ' . e($student['name']) : '' ?>!</h1>
<?php if (!$open): ?>
  <div class="card"><p>There is no recap open right now. Check back when your teacher tells you a new one is ready.</p></div>
<?php else: ?>
  <h2>Open recaps</h2>
  <?php foreach ($open as $t): ?>
    <div class="card row">
      <div><b><?= e($t['title']) ?></b><br><span class="muted small"><?= (int)$t['nq'] ?> questions<?= $t['time_limit_min'] ? ' - ' . (int)$t['time_limit_min'] . ' min' : '' ?> - open until <?= e(fmt_dt($t['closes_at'])) ?></span></div>
      <?php if ($t['done']): ?>
        <a class="btn secondary" href="<?= e(url('result.php?id=' . $t['id'])) ?>">See results</a>
      <?php else: ?>
        <a class="btn" href="<?= e(url('take.php?id=' . $t['id'])) ?>">Start</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($history): ?>
  <h2>Your results</h2>
  <table>
    <tr><th>Recap</th><th>Date</th><th>Score</th><th></th></tr>
    <?php foreach ($history as $h): ?>
      <tr>
        <td><?= e($h['title']) ?></td>
        <td><?= e(date('j M Y', strtotime($h['submitted_at']))) ?></td>
        <td><?= (int)$h['score'] ?>/<?= (int)$h['max_score'] ?> (<?= pct((int)$h['score'], (int)$h['max_score']) ?>%)</td>
        <td><a href="<?= e(url('result.php?id=' . $h['test_id'])) ?>">Review</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<script>
try {
  localStorage.setItem('recap_email', <?= json_encode($student['email'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
} catch (e) {}
</script>
<?php page_footer();
