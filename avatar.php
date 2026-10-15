<?php
// Weekly Recap Platform - https://github.com/iamphasm/weekly-recap-platform
// Created by Kristian Daae-Johansen / IamPhasm
// Stack: PHP + MySQL
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

// A student may fetch their own picture; teachers may fetch anyone's. Nobody else.
$me = current_student();
$target = (int)($_GET['id'] ?? ($me['id'] ?? 0));
if (!$me || $target !== (int)$me['id']) {
    require_teacher();
}
$photo = q('SELECT photo FROM students WHERE id = ?', [$target])->fetchColumn();
if (!avatar_file_ok($photo ?: null)) {
    http_response_code(404);
    exit;
}
$path = APP_ROOT . '/uploads/avatars/' . $photo;
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][pathinfo($photo, PATHINFO_EXTENSION)];
session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
