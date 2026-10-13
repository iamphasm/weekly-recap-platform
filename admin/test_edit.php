<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$teacher = require_teacher();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$test = $id ? q('SELECT * FROM tests WHERE id = ?', [$id])->fetch() : null;
if ($id && !$test) {
    redirect('admin/');
}
$locked = $test && (int)q('SELECT COUNT(*) FROM attempts WHERE test_id = ?', [$id])->fetchColumn() > 0;

$dt = fn(string $v) => ($ts = strtotime($v)) ? date('Y-m-d H:i:s', $ts) : null;
$qErr = null;
$editQ = null;
$editId = (int)($_GET['q'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_test') {
        $title = trim((string)($_POST['title'] ?? ''));
        $opens = $dt((string)($_POST['opens_at'] ?? ''));
        $closes = $dt((string)($_POST['closes_at'] ?? ''));
        if ($title === '' || !$opens || !$closes || $closes <= $opens) {
            flash('Enter a title and an opening time before the closing time.');
            redirect('admin/test_edit.php' . ($id ? "?id=$id" : ''));
        }
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $pub = isset($_POST['published']) ? 1 : 0;
        if ($id) {
            q('UPDATE tests SET title=?, description=?, opens_at=?, closes_at=?, published=? WHERE id=?',
                [$title, $desc, $opens, $closes, $pub, $id]);
        } else {
            q('INSERT INTO tests (title, description, opens_at, closes_at, published, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
                [$title, $desc, $opens, $closes, $pub, $teacher['id'], date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        flash('Saved.', 'ok');
        redirect("admin/test_edit.php?id=$id");
    }

    if (!$test) {
        redirect('admin/');
    }
    if ($locked && in_array($action, ['save_question', 'delete_question'], true)) {
        flash('This test already has submissions, so its questions can no longer be changed.');
        redirect("admin/test_edit.php?id=$id");
    }
    if ($action === 'delete_question') {
        q('DELETE FROM questions WHERE id = ? AND test_id = ?', [(int)$_POST['qid'], $id]);
        redirect("admin/test_edit.php?id=$id");
    }
    if ($action === 'save_question') {
        $qid = (int)($_POST['qid'] ?? 0) ?: null;
        $qErr = save_question($id, $qid, $_POST);
        if ($qErr === null) {
            flash('Question saved.', 'ok');
            redirect("admin/test_edit.php?id=$id");
        }
        $editQ = ['id' => $qid, 'type' => $_POST['type'] ?? 'single', 'prompt' => $_POST['prompt'] ?? '',
                  'explanation' => $_POST['explanation'] ?? '', 'raw' => $_POST['options'] ?? '', 'tf' => $_POST['tf_answer'] ?? 'true'];
    }
}

$questions = $test ? load_questions($id) : [];
if (!$editQ && $editId && !$locked) {
    foreach ($questions as $qq) {
        if ((int)$qq['id'] === $editId) {
            $raw = '';
            foreach ($qq['options'] as $o) {
                $raw .= ($o['is_correct'] && $qq['type'] !== 'text' ? '*' : '') . $o['label'] . "\n";
            }
            $tf = 'true';
            if ($qq['type'] === 'tf') {
                foreach ($qq['options'] as $o) {
                    if ($o['is_correct'] && $o['label'] === 'False') {
                        $tf = 'false';
                    }
                }
            }
            $editQ = ['id' => $qq['id'], 'type' => $qq['type'], 'prompt' => $qq['prompt'],
                      'explanation' => $qq['explanation'], 'raw' => trim($raw), 'tf' => $tf];
        }
    }
}
$editQ ??= ['id' => null, 'type' => 'single', 'prompt' => '', 'explanation' => '', 'raw' => '', 'tf' => 'true'];

// Default window: next Monday 08:00 -> following Sunday 23:59
$defOpen = $test['opens_at'] ?? date('Y-m-d 08:00:00', strtotime('monday this week'));
$defClose = $test['closes_at'] ?? date('Y-m-d 23:59:00', strtotime($defOpen . ' +6 days'));
$local = fn(string $v) => date('Y-m-d\TH:i', strtotime($v));

page_header($test ? 'Edit test' : 'New test', 'admin');
?>
<h1><?= $test ? 'Edit test' : 'New test' ?></h1>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_test"><input type="hidden" name="id" value="<?= $id ?>">
  <label>Title <input type="text" name="title" required value="<?= e($test['title'] ?? '') ?>" placeholder="Week 42 recap"></label>
  <label>Instructions (optional) <textarea name="description"><?= e($test['description'] ?? '') ?></textarea></label>
  <div class="grid2">
    <label>Opens <input type="datetime-local" name="opens_at" required value="<?= e($local($defOpen)) ?>"></label>
    <label>Closes <input type="datetime-local" name="closes_at" required value="<?= e($local($defClose)) ?>"></label>
  </div>
  <label><input type="checkbox" name="published" value="1" <?= !empty($test['published']) ? 'checked' : '' ?>> Published (visible to students during the window)</label>
  <button type="submit">Save test</button>
</form>

<?php if ($test): ?>
  <h2>Questions (<?= count($questions) ?>)</h2>
  <?php if ($locked): ?><div class="flash error">Students have already submitted, so questions are locked to keep results consistent.</div><?php endif; ?>
  <?php foreach ($questions as $i => $qq): ?>
    <div class="card row">
      <div><b><?= $i + 1 ?>.</b> <?= e($qq['prompt']) ?><br>
        <span class="muted small"><?= e(QUESTION_TYPES[$qq['type']]) ?> - correct: <?= e(describe_correct($qq)) ?></span></div>
      <?php if (!$locked): ?>
        <div>
          <a class="btn secondary" href="<?= e(url("admin/test_edit.php?id=$id&q={$qq['id']}")) ?>#qform">Edit</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="delete_question"><input type="hidden" name="qid" value="<?= (int)$qq['id'] ?>">
            <button class="danger" onclick="return confirm('Delete this question?')">Delete</button></form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$locked): ?>
  <form method="post" class="card" id="qform">
    <h3><?= $editQ['id'] ? 'Edit question' : 'Add question' ?></h3>
    <?php if ($qErr): ?><div class="flash error"><?= e($qErr) ?></div><?php endif; ?>
    <?= csrf_field() ?><input type="hidden" name="action" value="save_question"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="qid" value="<?= (int)$editQ['id'] ?>">
    <label>Type
      <select name="type" id="qtype">
        <?php foreach (QUESTION_TYPES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $editQ['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Question <textarea name="prompt" required><?= e($editQ['prompt']) ?></textarea></label>
    <div id="opts-box">
      <label><span id="opts-label">Options</span> <textarea name="options" id="opts"><?= e($editQ['raw']) ?></textarea></label>
      <p class="muted small" id="opts-help"></p>
    </div>
    <div id="tf-box">
      <label>Correct answer
        <select name="tf_answer"><option value="true" <?= $editQ['tf'] === 'true' ? 'selected' : '' ?>>True</option>
          <option value="false" <?= $editQ['tf'] === 'false' ? 'selected' : '' ?>>False</option></select></label>
    </div>
    <label>Explanation shown after submitting (optional) <textarea name="explanation"><?= e($editQ['explanation']) ?></textarea></label>
    <button type="submit"><?= $editQ['id'] ? 'Update question' : 'Add question' ?></button>
    <?php if ($editQ['id']): ?><a class="btn secondary" href="<?= e(url("admin/test_edit.php?id=$id")) ?>">Cancel</a><?php endif; ?>
  </form>
  <script>
  (function () {
    var sel = document.getElementById('qtype');
    var help = {
      single: 'One option per line. Start the correct one with * (e.g. *Paris).',
      multi: 'One option per line. Start every correct option with *.',
      text: 'One accepted answer per line (case and extra spaces are ignored).'
    };
    function upd() {
      var t = sel.value;
      document.getElementById('tf-box').style.display = t === 'tf' ? '' : 'none';
      document.getElementById('opts-box').style.display = t === 'tf' ? 'none' : '';
      document.getElementById('opts-label').textContent = t === 'text' ? 'Accepted answers' : 'Options';
      document.getElementById('opts-help').textContent = help[t] || '';
    }
    sel.addEventListener('change', upd); upd();
  })();
  </script>
  <?php endif; ?>
<?php else: ?>
  <p class="muted">Save the test first, then you can add questions.</p>
<?php endif; ?>
<?php page_footer();
