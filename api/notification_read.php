<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'login_required']);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];
$key = trim($_POST['key'] ?? '');

if ($key === '' || !preg_match('/^(comment|like|comment_like|neighbor_post|guestbook):\d+$/', $key)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'invalid_key']);
    exit;
}

$stmt = $conn->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_key) VALUES (?, ?)");
$stmt->bind_param("is", $userId, $key);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
