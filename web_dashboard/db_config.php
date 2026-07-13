<?php
// ============================================================
// db_config.php — Loads credentials from config.php (gitignored)
// ============================================================

$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    die("Missing config.php. Copy config.example.php to config.php and fill in your credentials.");
}
require_once $config_path;

date_default_timezone_set('Asia/Colombo'); // GMT+5:30

$is_production = (
    strpos($_SERVER['HTTP_HOST'], 'localhost') === false &&
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false &&
    $_SERVER['SERVER_ADDR'] !== '::1'
);

if ($is_production) {
    $host    = DB_HOST_PROD;
    $user    = DB_USER_PROD;
    $pass    = DB_PASS_PROD;
    $db_name = DB_NAME_PROD;
} else {
    $host    = DB_HOST_LOCAL;
    $user    = DB_USER_LOCAL;
    $pass    = DB_PASS_LOCAL;
    $db_name = DB_NAME_LOCAL;
}

$conn = null;
$DB_ERROR = null;

try {
    $conn = new mysqli($host, $user, $pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
    $conn->query("SET time_zone = '+05:30'");
} catch (Throwable $e) {
    $DB_ERROR = $e->getMessage();
    $conn = null;
}

if (!defined('DB_AVAILABLE')) {
    define('DB_AVAILABLE', $conn !== null);
}
?>
