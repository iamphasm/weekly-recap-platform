<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    unset($_SESSION['teacher_id'], $_SESSION['teacher_seen']);
    session_regenerate_id(true);
}
redirect('admin/login.php');
