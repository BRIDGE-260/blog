<?php
session_start();
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json; charset=utf-8');

function aiJson(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function localSuggestions(string $mode, string $topic, string $category): array {
    $subject = trim($topic) !== '' ? trim($topic) : '오늘 기억에 남은 일';
    $subject = mb_strimwidth(preg_replace('/\s+/', ' ', $subject), 0, 55, '…', 'UTF-8');
    $categoryText = $category !== '' ? $category : '일상';
    if ($mode === 'title') {
        return [
            $subject . ', 천천히 기록해두고 싶은 이유',
            $categoryText . '에서 발견한 작은 변화',
            '오늘의 나에게 묻는 한 가지: ' . $subject,
        ];
    }
    if ($mode === 'tags') {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $subject, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_slice(array_merge([$categoryText, 'BRIDGE206', '기록'], $words), 0, 7)));
    }
    return [
        "시작 · 이 이야기를 쓰게 된 순간\n\n경험 · {$subject}와 관련해 직접 보고 느낀 장면\n\n연결 · 다른 세대나 독자에게 묻고 싶은 질문\n\n마무리 · 오늘의 생각과 다음에 이어갈 이야기",
    ];
}

function openAiSuggestions(string $apiKey, string $mode, string $topic, string $category): ?array {
    if (!function_exists('curl_init')) return null;
    $model = getenv('OPENAI_MODEL') ?: 'gpt-5.4-nano';
    $instruction = '당신은 한국어 블로그 BRIDGE 206의 글쓰기 도우미입니다. 과장 없이 따뜻하고 구체적으로 제안하세요. 반드시 {"suggestions":["..."]} JSON 하나만 출력하세요.';
    $requestText = "작업: {$mode}\n카테고리: {$category}\n사용자 메모: {$topic}\n제목은 3개, 태그는 # 없는 단어 5~7개, 개요는 1개의 4단계 한국어 개요로 제안하세요.";
    $payload = json_encode([
        'model' => $model,
        'instructions' => $instruction,
        'input' => $requestText,
        'max_output_tokens' => 500,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300 || !is_string($raw)) return null;
    $response = json_decode($raw, true);
    $text = '';
    foreach (($response['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text') $text .= (string)($content['text'] ?? '');
        }
    }
    $parsed = json_decode(trim($text), true);
    return isset($parsed['suggestions']) && is_array($parsed['suggestions']) ? array_values($parsed['suggestions']) : null;
}

if (!isset($_SESSION['user_id'])) aiJson(['ok' => false, 'message' => '로그인이 필요해요.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') aiJson(['ok' => false, 'message' => 'POST only'], 405);

$mode = (string)($_POST['mode'] ?? '');
$topic = mb_substr(trim((string)($_POST['topic'] ?? '')), 0, 2000, 'UTF-8');
$category = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 50, 'UTF-8');
if (!in_array($mode, ['title', 'outline', 'tags'], true)) aiJson(['ok' => false, 'message' => '지원하지 않는 요청이에요.'], 422);

$apiKey = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
$usedApi = false;
$suggestions = null;
$apiRequestAllowed = true;
$logTable = $conn->query("SHOW TABLES LIKE 'ai_assist_logs'");
if ($logTable && $logTable->num_rows > 0) {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ai_assist_logs WHERE user_id = ? AND used_api = 1 AND created_at >= CURDATE()");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $apiRequestAllowed = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) < 20;
    $stmt->close();
}
if ($apiKey !== '' && $apiRequestAllowed) {
    $suggestions = openAiSuggestions($apiKey, $mode, $topic, $category);
    $usedApi = is_array($suggestions);
}
if (!$suggestions) $suggestions = localSuggestions($mode, $topic, $category);

if ($logTable && $logTable->num_rows > 0) {
    $excerpt = mb_substr($topic, 0, 500, 'UTF-8');
    $apiFlag = $usedApi ? 1 : 0;
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO ai_assist_logs (user_id, assist_mode, input_excerpt, used_api) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("issi", $userId, $mode, $excerpt, $apiFlag);
    $stmt->execute();
    $stmt->close();
}

aiJson(['ok' => true, 'mode' => $mode, 'suggestions' => $suggestions, 'source' => $usedApi ? 'openai' : 'local']);
