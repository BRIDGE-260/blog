<?php
session_start();
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$field = $_GET['field'] ?? '';
$value = trim($_GET['value'] ?? '');

if (!in_array($field, ['email', 'nickname'], true)) {
    sendJson(['ok' => false, 'message' => '확인할 항목이 올바르지 않아요.'], 400);
}

if ($value === '') {
    sendJson(['ok' => true, 'available' => null, 'message' => '']);
}

if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
    sendJson(['ok' => true, 'available' => false, 'message' => '이메일 형식이 올바르지 않아요.']);
}

$column = $field === 'email' ? 'email' : 'nickname';
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE {$column} = ?");
$stmt->bind_param("s", $value);
$stmt->execute();
$exists = (int)$stmt->get_result()->fetch_assoc()['cnt'] > 0;
$stmt->close();

if ($exists) {
    sendJson([
        'ok' => true,
        'available' => false,
        'message' => $field === 'email' ? '이미 가입된 이메일이에요.' : '이미 사용 중인 닉네임이에요.',
    ]);
}

sendJson([
    'ok' => true,
    'available' => true,
    'message' => $field === 'email' ? '사용 가능한 이메일이에요.' : '사용 가능한 닉네임이에요.',
]);
