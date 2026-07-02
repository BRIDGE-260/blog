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
    sendJson(['ok' => false, 'message' => '확인 항목이 올바르지 않습니다.'], 400);
}

if ($value === '') {
    sendJson(['ok' => true, 'available' => null, 'message' => '']);
}

if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
    sendJson(['ok' => true, 'available' => false, 'message' => '이메일 형식이 올바르지 않습니다.']);
}

if ($field === 'nickname') {
    if (mb_strlen($value) < 2 || mb_strlen($value) > 20) {
        sendJson(['ok' => true, 'available' => false, 'message' => '닉네임은 2자 이상 20자 이하로 입력해주세요.']);
    }
    if (!preg_match('/^[A-Za-z0-9가-힣_]+$/u', $value)) {
        sendJson(['ok' => true, 'available' => false, 'message' => '한글, 영문, 숫자, 밑줄(_)만 사용할 수 있어요.']);
    }
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
