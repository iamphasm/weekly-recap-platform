<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$student = require_student();

$testId = (int)($_GET['id'] ?? 0);
$attempt = q('SELECT a.*, t.title FROM attempts a JOIN tests t ON t.id = a.test_id WHERE a.test_id = ? AND a.student_id = ?',
    [$testId, $student['id']])->fetch();
if (!$attempt) {
    redirect('recap.php'); // answers are only shown after submitting
}
$questions = load_questions($testId);
$answers = load_answers((int)$attempt['id']);
$p = pct((int)$attempt['score'], (int)$attempt['max_score']);

page_header('Results');
?>
<h1><?= e($attempt['title']) ?></h1>
<div class="card score">
  <span class="big"><?= (int)$attempt['score'] ?> / <?= (int)$attempt['max_score'] ?></span> (<?= $p ?>%)
  <?php if ($attempt['email_sent_at']): ?>
    <p class="muted small">A debrief was emailed to <?= e($student['email']) ?>.</p>
  <?php endif; ?>
</div>
<?php foreach ($questions as $i => $qq):
    $a = $answers[$qq['id']] ?? ['response' => null, 'is_correct' => false]; ?>
  <div class="card <?= $a['is_correct'] ? 'right' : 'wrong' ?>">
    <b>Question <?= $i + 1 ?> - <?= $a['is_correct'] ? 'Correct' : 'Wrong' ?></b>
    <p class="prompt"><?= nl2br(e($qq['prompt'])) ?></p>
    <?php if ($qq['type'] === 'text'): ?>
      <p>Your answer: <b><?= e(describe_response($qq, $a['response'])) ?></b></p>
    <?php elseif ($qq['type'] === 'match'):
        $desc = array_column($qq['options'], 'pair_label', 'id'); ?>
      <?php foreach ($qq['options'] as $o):
          $picked = (int)($a['response'][(int)$o['id']] ?? 0);
          $good = isset($desc[$picked]) && norm_text((string)$desc[$picked]) === norm_text((string)$o['pair_label']); ?>
        <div class="opt <?= $good ? 'is-correct' : 'is-wrong' ?>"><b><?= e($o['label']) ?></b> &rarr; <?= e($desc[$picked] ?? '(no answer)') ?>
          <?= $good ? '' : '<br><span class="small">Correct: ' . e($o['pair_label']) . '</span>' ?></div>
      <?php endforeach; ?>
    <?php else: ?>
      <?php foreach ($qq['options'] as $o):
          $chosen = in_array((int)$o['id'], (array)$a['response'], true);
          $cls = $o['is_correct'] ? 'is-correct' : ($chosen ? 'is-wrong' : ''); ?>
        <div class="opt <?= $cls ?>"><?= $chosen ? '&#9679;' : '&#9675;' ?> <?= e($o['label']) ?>
          <?= $o['is_correct'] ? ' <span class="tag ok">correct</span>' : '' ?>
          <?= $chosen && !$o['is_correct'] ? ' <span class="tag bad">your answer</span>' : '' ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!$a['is_correct'] && $qq['type'] !== 'match'): ?><p>Correct answer: <b><?= e(describe_correct($qq)) ?></b></p><?php endif; ?>
    <?php if ($qq['explanation']): ?><p class="muted"><?= nl2br(e($qq['explanation'])) ?></p><?php endif; ?>
  </div>
<?php endforeach; ?>
<p><a class="btn secondary" href="<?= e(url('recap.php')) ?>">Back</a></p>
<?php page_footer();
