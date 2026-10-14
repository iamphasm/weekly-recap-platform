<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
ensure_auth_tables(); // timer column/table on older installs
$student = require_student();

const TIME_GRACE_SECONDS = 30; // slack for the automatic submit at zero to arrive

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
if ($test['opens_at'] > $now || $test['closes_at'] < date('Y-m-d H:i:s', time() - TIME_GRACE_SECONDS)) {
    flash('That recap is not open right now.');
    redirect('recap.php');
}
$questions = load_questions($testId);
if (!$questions) {
    flash('That recap has no questions yet.');
    redirect('recap.php');
}

$limitMin = (int)($test['time_limit_min'] ?? 0);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    csrf_check();
}

// Timed test: the clock starts when the student presses Start, and the deadline is kept on the server.
$deadline = null;
if ($limitMin > 0) {
    $startedAt = q('SELECT started_at FROM test_starts WHERE test_id = ? AND student_id = ?', [$testId, $student['id']])->fetchColumn();
    if (!$startedAt) {
        if ($isPost && ($_POST['action'] ?? '') === 'start') {
            q('INSERT IGNORE INTO test_starts (test_id, student_id, started_at) VALUES (?,?,?)', [$testId, $student['id'], $now]);
        } elseif (!$isPost) {
            page_header($test['title']);
            ?>
            <h1><?= e($test['title']) ?></h1>
            <?php if ($test['description']): ?><p><?= nl2br(e($test['description'])) ?></p><?php endif; ?>
            <form method="post" class="card">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $testId ?>"><input type="hidden" name="action" value="start">
              <p>This recap is <b>timed</b>: you have <b><?= $limitMin ?> minute<?= $limitMin === 1 ? '' : 's' ?></b> from the moment you press Start.
                When time runs out your answers are submitted automatically, and you can only take it once.</p>
              <button type="submit">Start</button>
            </form>
            <?php
            page_footer();
            exit;
        }
        redirect('take.php?id=' . $testId);
    }
    $deadline = min(strtotime((string)$startedAt) + $limitMin * 60, strtotime($test['closes_at'])); // never later than the closing time
    if ($isPost && ($_POST['action'] ?? '') === 'start') {
        redirect('take.php?id=' . $testId); // double click on Start
    }
}
$timedOut = $deadline !== null && time() > $deadline + TIME_GRACE_SECONDS;

if (($isPost && ($_POST['action'] ?? '') !== 'start') || $timedOut) {
    // Past the deadline the answers are not counted (a blank attempt is recorded so the test cannot be retaken).
    $answers = (!$timedOut && is_array($_POST['q'] ?? null)) ? $_POST['q'] : [];
    $score = 0;
    $graded = [];
    foreach ($questions as $qq) {
        $resp = parse_response($qq, $answers[$qq['id']] ?? null);
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
    if ($timedOut) {
        flash('Time ran out before the answers were submitted, so they were not counted.');
    }
    redirect('result.php?id=' . $testId);
}

page_header($test['title']);
?>
<h1><?= e($test['title']) ?></h1>
<?php if ($test['description']): ?><p><?= nl2br(e($test['description'])) ?></p><?php endif; ?>
<?php if ($deadline !== null): $left = max(0, $deadline - time()); ?>
  <div class="timer" id="timer" role="timer" aria-live="off"><span class="small">Time left</span><b id="tleft"><?= sprintf('%d:%02d', intdiv($left, 60), $left % 60) ?></b></div>
<?php endif; ?>
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
      <?php elseif ($qq['type'] === 'match'): $shuf = shuffled_pairs($qq); ?>
        <div class="match">
          <div>
            <h3><?= e($qq['left_title'] ?: 'Categories') ?>:</h3>
            <?php foreach ($qq['options'] as $o): ?>
              <div class="mbox"><span><?= e($o['label']) ?></span>
                <select name="<?= e($name) ?>[<?= (int)$o['id'] ?>]" aria-label="<?= e($o['label']) ?>">
                  <option value="">-</option>
                  <?php foreach ($shuf as $n => $s): ?><option value="<?= e(match_token((int)$qq['id'], (int)$s['id'])) ?>"><?= chr(65 + $n) ?></option><?php endforeach; ?>
                </select></div>
            <?php endforeach; ?>
          </div>
          <div>
            <h3><?= e($qq['right_title'] ?: 'Options') ?>:</h3>
            <?php foreach ($shuf as $n => $s): ?>
              <div class="mbox"><b class="letter"><?= chr(65 + $n) ?></b><span><?= e($s['pair_label']) ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
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
    var sels = f.querySelectorAll('select'), answered;
    if (sels.length) { answered = Array.prototype.every.call(sels, function (x) { return x.value !== ''; }); }
    else { answered = t ? t.value.trim() !== '' : f.querySelector('input:checked') !== null; }
    if (!answered) { missing++; }
  });
  if (missing && !confirm(missing + ' question(s) unanswered. Submit anyway? You cannot change answers afterwards.')) {
    ev.preventDefault();
  }
});
<?php if ($deadline !== null): ?>
var quiz = document.getElementById('quiz'), tleft = document.getElementById('tleft'), timer = document.getElementById('timer');
var endAt = performance.now() + <?= max(0, $deadline - time()) ?> * 1000, sent = false;
(function tick() {
  var s = Math.max(0, Math.ceil((endAt - performance.now()) / 1000));
  tleft.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  if (s <= 60) { timer.classList.add('low'); }
  if (s === 0) {
    if (!sent) { sent = true; quiz.submit(); }
    return;
  }
  setTimeout(tick, 250);
})();
<?php endif; ?>
</script>
<?php page_footer();
