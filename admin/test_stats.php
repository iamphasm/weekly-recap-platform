<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables(); // adds students.name on older installs
require_teacher();

$id = (int)($_GET['id'] ?? 0);
$test = q('SELECT * FROM tests WHERE id = ?', [$id])->fetch();
if (!$test) {
    redirect('admin/');
}
$attempts = q('SELECT a.*, s.email, s.name FROM attempts a JOIN students s ON s.id = a.student_id WHERE a.test_id = ? ORDER BY s.email', [$id])->fetchAll();
$questions = load_questions($id);
$perQ = [];
foreach (q('SELECT an.question_id, COUNT(*) AS n, SUM(an.is_correct) AS ok FROM answers an
            JOIN attempts a ON a.id = an.attempt_id WHERE a.test_id = ? GROUP BY an.question_id', [$id])->fetchAll() as $r) {
    $perQ[$r['question_id']] = $r;
}
$notDone = q('SELECT email, name FROM students WHERE id NOT IN (SELECT student_id FROM attempts WHERE test_id = ?) ORDER BY email', [$id])->fetchAll();

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="test-' . $id . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'score', 'max', 'percent', 'submitted_at']);
    foreach ($attempts as $a) {
        fputcsv($out, [csv_safe($a['email']), csv_safe((string)$a['name']), $a['score'], $a['max_score'], pct((int)$a['score'], (int)$a['max_score']), $a['submitted_at']]);
    }
    exit;
}

page_header('Results', 'admin');
?>
<h1><?= e($test['title']) ?> - results</h1>
<p><?= count($attempts) ?> submission(s). <a href="<?= e(url('admin/test_stats.php?id=' . $id . '&csv=1')) ?>">Download CSV</a></p>
<h2>By question</h2>
<table>
  <tr><th>#</th><th>Question</th><th>Correct</th></tr>
  <?php foreach ($questions as $i => $qq): $r = $perQ[$qq['id']] ?? null; $p = $r ? (int)round($r['ok'] * 100 / $r['n']) : 0; ?>
    <tr><td><?= $i + 1 ?></td><td><?= e(mb_strimwidth($qq['prompt'], 0, 90, '...')) ?></td>
      <td><span class="bar"><i style="width:<?= $p ?>%"></i></span> <?= $r ? $p . '%' : '-' ?></td></tr>
  <?php endforeach; ?>
</table>
<h2>By student</h2>
<table>
  <tr><th>Student</th><th>Score</th><th>Submitted</th></tr>
  <?php foreach ($attempts as $a): ?>
    <tr><td><a href="<?= e(url('admin/student.php?id=' . $a['student_id'])) ?>"><?= e(student_label($a)) ?></a></td>
      <td><?= (int)$a['score'] ?>/<?= (int)$a['max_score'] ?> (<?= pct((int)$a['score'], (int)$a['max_score']) ?>%)</td>
      <td><?= e(fmt_dt($a['submitted_at'])) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if ($notDone): ?>
  <h2>Registered students who have not submitted (<?= count($notDone) ?>)</h2>
  <p class="small"><?= e(implode(', ', array_map('student_label', $notDone))) ?></p>
<?php endif; ?>
<?php page_footer();
