<?php
require_once __DIR__ . '/error_handler.php';

// db.php
$host = 'localhost';
$db = 'epim_gestion_codex';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    app_error_response('system_error', 'Database connection failed: ' . $conn->connect_error, 500);
}
$conn->set_charset("utf8");
?>
