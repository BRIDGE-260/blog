<?php
/**
 * db.example.php - database connection sample (mysqli)
 *
 * Copy this file to app/db.php, then edit the values for your local XAMPP/MySQL.
 *
 *   Windows PowerShell:
 *   Copy-Item app\db.example.php app\db.php
 */

$DB_HOST = 'localhost';
$DB_NAME = 'blog';
$DB_USER = 'user1';
$DB_PASS = '1234';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    exit('DB connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
