<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
ensure_auth_tables(); // profile columns on older installs
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $errors = [];
    $name = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($_POST['name'] ?? ''))), 0, 120);
    $bio = mb_substr(trim(str_replace("\r", '', (string)($_POST['bio'] ?? ''))), 0, 300);
    $bio = (string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $bio);
    $photo = $student['photo'] ?? null;

    if (!empty($_POST['remove_photo'])) {
        delete_avatar_file($photo);
        $photo = null;
    }
    $f = $_FILES['photo'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        [$new, $err] = store_avatar($f);
        if ($err) {
            $errors[] = $err;
        } else {
            delete_avatar_file($photo);
            $photo = $new;
        }
    }
    q('UPDATE students SET name = ?, bio = ?, photo = ? WHERE id = ?', [$name === '' ? null : $name, $bio === '' ? null : $bio, $photo, $student['id']]);
    $errors ? flash(implode(' ', $errors)) : flash('Profile saved.', 'ok');
    redirect('profile.php');
}

page_header('My profile');
$pic = avatar_url($student);
?>
<h1>My profile</h1>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <?php if ($pic): ?><img class="profile-pic" src="<?= e($pic) ?>" alt="Your picture"><?php endif; ?>
  <p class="muted small"><?= e($student['email']) ?> - your teachers can see your name, picture and bio.</p>
  <label>Name <input type="text" name="name" maxlength="120" value="<?= e((string)$student['name']) ?>"></label>
  <label>Picture <span class="muted small">(PNG, JPG, GIF or WebP, max 2 MB. It is cropped to a square.)</span>
    <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
  <?php if ($pic): ?><label class="opt"><input type="checkbox" name="remove_photo" value="1"> Remove my picture</label><?php endif; ?>
  <label>Short bio <span class="muted small">(up to 300 characters)</span>
    <textarea name="bio" maxlength="300" rows="4"><?= e((string)($student['bio'] ?? '')) ?></textarea></label>
  <button type="submit">Save profile</button>
  <a class="btn secondary" href="<?= e(url('recap.php')) ?>">Back</a>
</form>
<?php page_footer();
