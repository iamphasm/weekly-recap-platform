<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_teacher();

$uploadDir = __DIR__ . '/../uploads';
$types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $errors = [];

    $name = trim((string)($_POST['site_name'] ?? ''));
    set_setting('site_name', $name === '' ? null : mb_substr($name, 0, 80));

    foreach (array_keys(THEME_DEFAULTS) as $k) {
        $v = (string)($_POST[$k] ?? '');
        if (!empty($_POST['reset_colors'])) {
            set_setting($k, null);
        } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            set_setting($k, strtolower($v));
        }
    }

    if (!empty($_POST['remove_logo'])) {
        if ($old = setting('logo')) {
            @unlink($uploadDir . '/' . basename($old));
        }
        set_setting('logo', null);
    }

    $f = $_FILES['logo'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        $info = $f['error'] === UPLOAD_ERR_OK && $f['size'] <= 2 * 1024 * 1024 ? @getimagesize($f['tmp_name']) : false;
        if (!$info || !isset($types[$info['mime']])) {
            $errors[] = 'Logo must be a PNG, JPG, GIF or WebP image up to 2 MB.';
        } else {
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            if ($old = setting('logo')) {
                @unlink($uploadDir . '/' . basename($old));
            }
            $file = 'logo-' . bin2hex(random_bytes(4)) . '.' . $types[$info['mime']];
            if (move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $file)) {
                set_setting('logo', $file);
            } else {
                $errors[] = 'Could not save the logo (is uploads writable?).';
            }
        }
    }
    $errors ? flash(implode(' ', $errors)) : flash('Settings saved.', 'ok');
    redirect('admin/settings.php');
}

page_header('Settings', 'admin');
?>
<h1>Appearance</h1>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <label>Site name <input type="text" name="site_name" maxlength="80" value="<?= e(setting('site_name', '')) ?>" placeholder="<?= e((string)cfg('site_name', 'Weekly Recap')) ?>"></label>
  <label>Logo <span class="muted small">(PNG, JPG, GIF or WebP, max 2 MB. Shown on the login pages and in the header.)</span>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
  <?php if ($logo = logo_url()): ?>
    <p><img src="<?= e($logo) ?>" alt="" style="max-height:80px;background:#eee;padding:6px;border-radius:6px">
      <label class="opt"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label></p>
  <?php endif; ?>
  <div class="grid2">
    <label>Buttons &amp; links <span class="swatch"><input type="color" name="color_primary" value="<?= e(theme_color('color_primary')) ?>"></span></label>
    <label>Header &amp; top bar <span class="swatch"><input type="color" name="color_header" value="<?= e(theme_color('color_header')) ?>"></span></label>
    <label>Page background <span class="swatch"><input type="color" name="color_bg" value="<?= e(theme_color('color_bg')) ?>"></span></label>
  </div>
  <label class="opt"><input type="checkbox" name="reset_colors" value="1"> Reset colors to default</label>
  <button type="submit">Save</button>
</form>
<?php page_footer();
