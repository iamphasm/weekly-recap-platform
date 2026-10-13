<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Not installed yet: open the site in a browser to run the installer.\n");
        exit(1);
    }
    if (!defined('INSTALLER')) {
        $self = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = dirname($self);
        if (basename($dir) === 'admin') {
            $dir = dirname($dir);
        }
        header('Location: ' . rtrim($dir, '/') . '/install.php');
        exit;
    }
    $GLOBALS['CONFIG'] = ['timezone' => 'UTC'];
} else {
    $GLOBALS['CONFIG'] = require $configFile;
}
date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'UTC');

// PHPMailer is bundled in lib/. A Composer vendor/ folder also works if present.
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
} elseif (is_file(APP_ROOT . '/lib/PHPMailer/PHPMailer.php')) {
    require APP_ROOT . '/lib/PHPMailer/Exception.php';
    require APP_ROOT . '/lib/PHPMailer/PHPMailer.php';
    require APP_ROOT . '/lib/PHPMailer/SMTP.php';
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/grading.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/auth.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
