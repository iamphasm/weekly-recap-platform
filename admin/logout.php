<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
unset($_SESSION['teacher_id']);
session_regenerate_id(true);
redirect('admin/login.php');
