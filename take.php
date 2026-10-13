<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$student = require_student();

$testId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$now = date('Y-m-d H:i:s');
$test = q('SELECT * FROM tests WHERE id = ? AND published = 1', [$testId])->fetch();
if (!$test) {
    flash('That recap does not exist.');
    redirect('recap.php');
}
if (q('SELECT 1 FROM attempts WHERE test_id = ? AND student_id = ?', [$testId, $student['id']])->fetch()) {
    redirect('result.php?id=' . $testId);
}
if ($test['opens_at'] > $now || $test['closes_at'] < $now) {
    flash('That recap is not open right now.');
    redirect('recap.php');
}
$questions = load_questions($testId);
if (!$questions) {
    flash('That recap has no questions yet.');
    redirect('recap.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $score = 0;
    $graded = [];
    foreach ($questions as $qq) {
        $resp = parse_response($qq, $_POST['q'][$qq['id']] ?? null);
        $ok = is_response_correct($qq, $resp);
        $score += $ok ? 1 : 0;
        $graded[] = [$qq['id'], $resp, $ok];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO attempts (test_id, student_id, submitted_at, score, max_score) VALUES (?,?,?,?,?)',
            [$testId, $student['id'], date('Y-m-d H:i:s'), $score, count($questions)]);
        $attemptId = (int)$pdo->lastInsertId();
        foreach ($graded as [$qid, $resp, $ok]) {
            q('INSERT INTO answers (attempt_id, question_id, response, is_correct) VALUES (?,?,?,?)',
                [$attemptId, $qid, $resp === null ? null : json_encode($resp, JSON_UNESCAPED_UNICODE), $ok ? 1 : 0]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') { // double submit
            redirect('result.php?id=' . $testId);
        }
        throw $e;
    }
    send_weekly_debrief($attemptId);
    redirect('result.php?id=' . $testId);
}

page_header($test['title']);
?>
<h1><?= e($test['title']) ?></h1>
<?php if ($test['description']): ?><p><?= nl2br(e($test['description'])) ?></p><?php endif; ?>
<p class="muted small">You can only submit once. You'll see the correct answers after you submit all your answers.</p>
<form method="post" id="quiz">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $testId ?>">
  <?php foreach ($questions as $i => $qq): $name = 'q[' . $qq['id'] . ']'; ?>
    <fieldset class="card">
      <legend>Question <?= $i + 1 ?></legend>
      <p class="prompt"><?= nl2br(e($qq['prompt'])) ?></p>
      <?php if ($qq['type'] === 'text'): ?>
        <input type="text" name="<?= e($name) ?>" maxlength="500" autocomplete="off">
      <?php elseif ($qq['type'] === 'multi'): ?>
        <p class="muted small">Select all that apply.</p>
        <?php foreach ($qq['options'] as $o): ?>
          <label class="opt"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int)$o['id'] ?>"> <?= e($o['label']) ?></label>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($qq['options'] as $o): ?>
          <label class="opt"><input type="radio" name="<?= e($name) ?>" value="<?= (int)$o['id'] ?>"> <?= e($o['label']) ?></label>
        <?php endforeach; ?>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit">Submit answers</button>
</form>
<script>
document.getElementById('quiz').addEventListener('submit', function (ev) {
  var fs = this.querySelectorAll('fieldset'), missing = 0;
  fs.forEach(function (f) {
    var t = f.querySelector('input[type=text]');
    var answered = t ? t.value.trim() !== '' : f.querySelector('input:checked') !== null;
    if (!answered) { missing++; }
  });
  if (missing && !confirm(missing + ' question(s) unanswered. Submit anyway? You cannot change answers afterwards.')) {
    ev.preventDefault();
  }
});
</script>
<?php page_footer();
