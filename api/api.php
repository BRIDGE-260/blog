<?php
session_start();
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getPostAccess(mysqli $conn, int $postId, int $viewerId): ?array {
    $stmt = $conn->prepare("SELECT id, user_id, status, visibility FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $post = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$post) return null;

    $isOwner = ((int)$post['user_id'] === $viewerId);
    $isNeighbor = false;

    if (!$isOwner && $post['visibility'] === 'neighbor' && $viewerId > 0) {
        $stmt = $conn->prepare(
            "SELECT 1 FROM neighbors
             WHERE (user_id = ? AND neighbor_id = ?) OR (user_id = ? AND neighbor_id = ?)
             LIMIT 1"
        );
        $ownerId = (int)$post['user_id'];
        $stmt->bind_param("iiii", $ownerId, $viewerId, $viewerId, $ownerId);
        $stmt->execute();
        $isNeighbor = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $canView = $isOwner
        || ($post['status'] === 'published' && $post['visibility'] === 'all')
        || ($post['status'] === 'published' && $post['visibility'] === 'neighbor' && $isNeighbor);

    $post['can_view'] = $canView;
    return $post;
}

function getLikeState(mysqli $conn, int $postId, int $viewerId): array {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM likes WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $liked = false;
    if ($viewerId > 0) {
        $stmt = $conn->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $liked = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    return ['liked' => $liked, 'count' => $count];
}

function getCommentCount(mysqli $conn, int $postId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM comments WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $count;
}

function getComment(mysqli $conn, int $commentId): ?array {
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.parent_id, cm.content, cm.created_at, cm.user_id, u.nickname
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.id = ?"
    );
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $comment ?: null;
}

function getCommentInPost(mysqli $conn, int $commentId, int $postId): ?array {
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.parent_id, cm.content, cm.created_at, cm.user_id, u.nickname
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.id = ? AND cm.post_id = ?"
    );
    $stmt->bind_param("ii", $commentId, $postId);
    $stmt->execute();
    $comment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $comment ?: null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJson(['ok' => false, 'message' => 'POST only'], 405);
}

$isLogin = isset($_SESSION['user_id']);
$viewerId = (int)($_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$postId = (int)($_POST['post_id'] ?? $_GET['id'] ?? 0);

if (!$isLogin) {
    sendJson(['ok' => false, 'message' => 'login_required'], 401);
}

if ($postId <= 0) {
    sendJson(['ok' => false, 'message' => 'invalid_post'], 422);
}

$post = getPostAccess($conn, $postId, $viewerId);
if (!$post || !$post['can_view']) {
    sendJson(['ok' => false, 'message' => 'forbidden'], 403);
}

if ($action === 'like') {
    $stmt = $conn->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $postId, $viewerId);
    $stmt->execute();
    $liked = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($liked) {
        $stmt = $conn->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO likes (post_id, user_id) VALUES (?, ?)");
    }
    $stmt->bind_param("ii", $postId, $viewerId);
    $stmt->execute();
    $stmt->close();

    sendJson(['ok' => true, 'like' => getLikeState($conn, $postId, $viewerId)]);
}

if ($action === 'scrap') {
    $stmt = $conn->prepare("SELECT id FROM scraps WHERE post_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $postId, $viewerId);
    $stmt->execute();
    $has = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($has) {
        $stmt = $conn->prepare("DELETE FROM scraps WHERE post_id = ? AND user_id = ?");
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO scraps (post_id, user_id) VALUES (?, ?)");
    }
    $stmt->bind_param("ii", $postId, $viewerId);
    $stmt->execute();
    $stmt->close();

    sendJson(['ok' => true, 'scrapped' => !$has]);
}

if ($action === 'comment') {
    $content = trim($_POST['content'] ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0);

    if ($content === '') {
        sendJson(['ok' => false, 'message' => 'empty_content'], 422);
    }
    if (mb_strlen($content) > 500) {
        sendJson(['ok' => false, 'message' => 'content_too_long'], 422);
    }

    $parentParam = null;
    if ($parentId > 0) {
        $stmt = $conn->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ? AND parent_id IS NULL");
        $stmt->bind_param("ii", $parentId, $postId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            sendJson(['ok' => false, 'message' => 'invalid_parent'], 422);
        }
        $stmt->close();
        $parentParam = $parentId;
    }

    $stmt = $conn->prepare("INSERT INTO comments (post_id, parent_id, user_id, content) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $postId, $parentParam, $viewerId, $content);
    $stmt->execute();
    $commentId = $stmt->insert_id;
    $stmt->close();

    sendJson([
        'ok' => true,
        'comment' => getComment($conn, $commentId),
        'comment_count' => getCommentCount($conn, $postId),
    ]);
}

if ($action === 'comment_edit') {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if ($content === '') {
        sendJson(['ok' => false, 'message' => 'empty_content'], 422);
    }
    if (mb_strlen($content) > 500) {
        sendJson(['ok' => false, 'message' => 'content_too_long'], 422);
    }

    $comment = getCommentInPost($conn, $commentId, $postId);
    if (!$comment || (int)$comment['user_id'] !== $viewerId) {
        sendJson(['ok' => false, 'message' => 'not_found'], 404);
    }

    $stmt = $conn->prepare("UPDATE comments SET content = ? WHERE id = ? AND post_id = ? AND user_id = ?");
    $stmt->bind_param("siii", $content, $commentId, $postId, $viewerId);
    $stmt->execute();
    $stmt->close();

    sendJson(['ok' => true, 'comment' => getCommentInPost($conn, $commentId, $postId)]);
}

if ($action === 'comment_delete') {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $comment = getCommentInPost($conn, $commentId, $postId);

    if (!$comment || (int)$comment['user_id'] !== $viewerId) {
        sendJson(['ok' => false, 'message' => 'not_found'], 404);
    }

    $stmt = $conn->prepare("DELETE FROM comments WHERE id = ? AND post_id = ? AND user_id = ?");
    $stmt->bind_param("iii", $commentId, $postId, $viewerId);
    $stmt->execute();
    $stmt->close();

    sendJson([
        'ok' => true,
        'deleted_id' => $commentId,
        'parent_id' => $comment['parent_id'],
        'comment_count' => getCommentCount($conn, $postId),
    ]);
}

sendJson(['ok' => false, 'message' => 'unknown_action'], 400);

