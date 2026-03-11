<?php
// Centralized Database Connection Configuration
$is_production = (strpos($_SERVER['HTTP_HOST'], 'localhost') === false && strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === false && $_SERVER['SERVER_ADDR'] !== '::1');

if ($is_production) {
    // --- PRODUCTION (InfinityFree) ---
    $host = "sql100.infinityfree.com";
    $user = "if0_41365392";
    $pass = "ZiS9Zrk6luHAB8";
    $db_name = "if0_41365392_NeuroGuard";
} else {
    // --- LOCALHOST (XAMPP) ---
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db_name = "neuroguard_db";
}

$conn = new mysqli($host, $user, $pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
