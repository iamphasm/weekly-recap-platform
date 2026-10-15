<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ensure_auth_tables(); // adds students.name on older installs
require_teacher();

$id = (int)($_GET['id'] ?? 0);
$student = q('SELECT * FROM students WHERE id = ?', [$id])->fetch();
if (!$student) {
    redirect('admin/students.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    q('UPDATE students SET name = ? WHERE id = ?', [$name === '' ? null : mb_substr($name, 0, 120), $id]);
    flash('Name saved.', 'ok');
    redirect('admin/student.php?id=' . $id);
}
$attempts = q('SELECT a.*, t.title FROM attempts a JOIN tests t ON t.id = a.test_id WHERE a.student_id = ? ORDER BY a.submitted_at', [$id])->fetchAll();
$points = array_map(fn($a) => pct((int)$a['score'], (int)$a['max_score']), $attempts);

// Inline SVG learning curve
function curve_svg(array $points): string
{
    $w = 760; $h = 220; $pl = 36; $pr = 12; $pt = 12; $pb = 24;
    $n = count($points);
    if ($n === 0) {
        return '';
    }
    $x = fn($i) => $n === 1 ? $pl + ($w - $pl - $pr) / 2 : $pl + $i * ($w - $pl - $pr) / ($n - 1);
    $y = fn($v) => $pt + (100 - $v) * ($h - $pt - $pb) / 100;
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="Score per recap over time">';
    foreach ([0, 25, 50, 75, 100] as $g) {
        $svg .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $y($g) . '" y2="' . $y($g) . '" stroke="#e5e7eb"/>'
            . '<text x="' . ($pl - 6) . '" y="' . ($y($g) + 4) . '" font-size="11" text-anchor="end" fill="#6b7280">' . $g . '%</text>';
    }
    $d = '';
    foreach ($points as $i => $v) {
        $d .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($v), 1) . ' ';
    }
    $svg .= '<path d="' . $d . '" fill="none" stroke="#2563eb" stroke-width="2.5"/>';
    foreach ($points as $i => $v) {
        $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="4" fill="#2563eb"/>'
            . '<text x="' . round($x($i), 1) . '" y="' . round($y($v) - 8, 1) . '" font-size="11" text-anchor="middle" fill="#1f2937">' . $v . '</text>';
    }
    return $svg . '</svg>';
}

page_header(student_label($student), 'admin');
?>
<h1><?= e(student_label($student)) ?></h1>
<form method="post" class="card">
  <?= csrf_field() ?>
  <?php if ($pic = avatar_url($student)): ?><img class="profile-pic" src="<?= e($pic) ?>" alt=""><?php endif; ?>
  <p class="muted"><?= e($student['email']) ?></p>
  <?php if (trim((string)($student['bio'] ?? '')) !== ''): ?><p class="bio"><?= e($student['bio']) ?></p><?php endif; ?>
  <label>Name <span class="muted small">(optional; the student can also set it on their own profile page)</span> <input type="text" name="name" maxlength="120" value="<?= e((string)$student['name']) ?>"></label>
  <button type="submit">Save name</button>
</form>
<p class="muted">First seen <?= e(fmt_dt($student['first_seen'])) ?> - <?= count($attempts) ?> recap(s) completed
  <?php if ($points): ?> - average <?= (int)round(array_sum($points) / count($points)) ?>%<?php endif; ?></p>
<?php if ($attempts): ?>
  <div class="card"><?= curve_svg($points) ?></div>
  <table>
    <tr><th>Recap</th><th>Submitted</th><th>Score</th></tr>
    <?php foreach (array_reverse($attempts) as $a): ?>
      <tr><td><a href="<?= e(url('admin/test_stats.php?id=' . $a['test_id'])) ?>"><?= e($a['title']) ?></a></td>
        <td><?= e(fmt_dt($a['submitted_at'])) ?></td>
        <td><?= (int)$a['score'] ?>/<?= (int)$a['max_score'] ?> (<?= pct((int)$a['score'], (int)$a['max_score']) ?>%)</td></tr>
    <?php endforeach; ?>
  </table>
<?php else: ?>
  <p>No recaps completed yet.</p>
<?php endif; ?>
<form method="post" action="<?= e(url('admin/students.php')) ?>" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
  <b>Delete this student</b>
  <p class="muted small">Removes the account and all <?= count($attempts) ?> recap result(s), which also changes the test statistics. This cannot be undone. The student can sign up again later with a fresh, empty account.</p>
  <button class="danger" onclick="return confirm('Delete <?= e(addslashes($student['email'])) ?> and all of their results? This cannot be undone.')">Delete student</button>
</form>
<?php page_footer();
