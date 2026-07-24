<?php
/**
 * admin.php - BRIDGE 206 administrator dashboard.
 *   Only users.is_admin = 1 can enter. This page is read-only for now;
 *   destructive management stays in each owner's existing screens.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/points.php';

$adminColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
if (!$adminColumnResult || $adminColumnResult->num_rows === 0) {
    http_response_code(503);
    $pageTitle = '관리자 설정 필요 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">관리자 권한 컬럼이 아직 없습니다. database/add_admin_role.sql 을 먼저 실행해주세요.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$adminUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$adminUser || (int)$adminUser['is_admin'] !== 1) {
    http_response_code(403);
    $pageTitle = '관리자 권한 필요 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">관리자 권한이 있는 계정만 들어갈 수 있습니다.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

function adminFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function adminFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = adminFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function adminCount(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $row = adminFetchOne($conn, $sql, $types, $params);
    return (int)($row['cnt'] ?? 0);
}

function adminSearchExcerpt(?string $text, int $width = 110): string {
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    return $plain === '' ? '' : mb_strimwidth($plain, 0, $width, '...');
}

function adminTableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function adminColumnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function adminLogAction(mysqli $conn, bool $hasLogs, int $adminId, string $targetType, int $targetId, string $action, string $reason = ''): void {
    if (!$hasLogs) return;
    $reason = mb_substr(trim($reason), 0, 255);
    $stmt = $conn->prepare(
        "INSERT INTO moderation_logs (admin_id, target_type, target_id, action, reason)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isiss", $adminId, $targetType, $targetId, $action, $reason);
    $stmt->execute();
    $stmt->close();
}

function adminDeletePost(mysqli $conn, int $postId): bool {
    $stmt = $conn->prepare("SELECT id, thumbnail_stored FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $post = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$post) return false;

    $stmt = $conn->prepare("SELECT stored FROM post_images WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $mediaRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($mediaRows as $media) {
        if (!empty($media['stored'])) {
            @unlink(__DIR__ . '/../uploads/' . $media['stored']);
        }
    }
    if (!empty($post['thumbnail_stored'])) {
        @unlink(__DIR__ . '/../uploads/' . $post['thumbnail_stored']);
    }

    $stmt = $conn->prepare("DELETE FROM posts WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    return $deleted;
}

$adminMessage = '';
$adminId = (int)$_SESSION['user_id'];
$hasSiteSettings = adminTableExists($conn, 'site_settings');
$hasModerationLogs = adminTableExists($conn, 'moderation_logs');
$hasBanColumn = adminColumnExists($conn, 'users', 'is_banned');
$hasReports = adminTableExists($conn, 'reports');
$hasCommentLikes = adminTableExists($conn, 'comment_likes');
$hasPoints = adminTableExists($conn, 'point_wallets') && adminTableExists($conn, 'point_transactions');
$hasRoulette = adminTableExists($conn, 'roulette_spins');
$hasAiLogs = adminTableExists($conn, 'ai_assist_logs');
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$summaryPeriod = $_GET['period'] ?? '30';
if (!in_array($summaryPeriod, ['7', '30', 'all'], true)) {
    $summaryPeriod = '30';
}
$periodDays = $summaryPeriod === 'all' ? 0 : (int)$summaryPeriod;
$periodLabel = $summaryPeriod === 'all' ? '전체 기간' : '최근 ' . $periodDays . '일';

function adminJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function adminPeriodWhere(string $column, int $periodDays): string {
    return $periodDays > 0 ? " WHERE $column >= CURDATE() - INTERVAL " . ($periodDays - 1) . " DAY" : "";
}

function adminPeriodAnd(string $column, int $periodDays): string {
    return $periodDays > 0 ? " AND $column >= CURDATE() - INTERVAL " . ($periodDays - 1) . " DAY" : "";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminAction = $_POST['admin_action'] ?? '';

    if ($adminAction === 'point_adjust' && $hasPoints) {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 120);
        $userExists = $targetId > 0 ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM users WHERE id = ?", 'i', [$targetId]) > 0 : false;
        if (!$userExists) {
            $adminMessage = '회원을 찾을 수 없어요.';
        } elseif ($amount === 0 || abs($amount) > 10000) {
            $adminMessage = '한 번에 1~10,000포인트만 지급하거나 차감할 수 있어요.';
        } elseif ($reason === '') {
            $adminMessage = '포인트 조정 사유를 입력해주세요.';
        } else {
            $refKey = 'admin-' . $adminId . '-' . bin2hex(random_bytes(8));
            $description = '관리자 조정: ' . $reason;
            $result = bridge_admin_adjust_points($conn, $targetId, $amount, $refKey, $description);
            $adminMessage = $result['message'];
            if ($result['ok']) {
                adminLogAction($conn, $hasModerationLogs, $adminId, 'user', $targetId, 'adjust_points', $amount . 'P · ' . $reason);
            }
        }
    } elseif ($adminAction === 'user_role') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        if ($targetId > 0) {
            if ($targetId === (int)$_SESSION['user_id'] && $isAdmin === 0) {
                $adminCountNow = adminCount($conn, "SELECT COUNT(*) AS cnt FROM users WHERE is_admin = 1");
                if ($adminCountNow <= 1) {
                    $adminMessage = '마지막 관리자 권한은 해제할 수 없어요.';
                }
            }
            if ($adminMessage === '') {
                $stmt = $conn->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
                $stmt->bind_param("ii", $isAdmin, $targetId);
                $stmt->execute();
                $stmt->close();
                adminLogAction($conn, $hasModerationLogs, $adminId, 'user', $targetId, $isAdmin ? 'grant_admin' : 'revoke_admin');
                $adminMessage = '회원 권한을 저장했어요.';
            }
        }
    } elseif ($adminAction === 'user_ban' && $hasBanColumn) {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $banMode = $_POST['ban_mode'] ?? '';
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        $isBannedNow = null;
        if ($targetId <= 0) {
            $adminMessage = '회원을 찾을 수 없어요.';
        } elseif ($targetId === $adminId) {
            $adminMessage = '자기 자신은 밴할 수 없어요.';
        } elseif ($banMode === 'ban') {
            $stmt = $conn->prepare("UPDATE users SET is_banned = 1, banned_reason = ?, banned_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $reason, $targetId);
            $stmt->execute();
            $stmt->close();
            adminLogAction($conn, $hasModerationLogs, $adminId, 'user', $targetId, 'ban_user', $reason);
            $adminMessage = '회원을 밴 처리했어요.';
            $isBannedNow = 1;
        } elseif ($banMode === 'unban') {
            $stmt = $conn->prepare("UPDATE users SET is_banned = 0, banned_reason = NULL, banned_at = NULL WHERE id = ?");
            $stmt->bind_param("i", $targetId);
            $stmt->execute();
            $stmt->close();
            adminLogAction($conn, $hasModerationLogs, $adminId, 'user', $targetId, 'unban_user', $reason);
            $adminMessage = '회원 밴을 해제했어요.';
            $isBannedNow = 0;
        }

        if ($isAjaxRequest) {
            if ($isBannedNow === null) {
                adminJson(['ok' => false, 'message' => $adminMessage ?: '처리할 수 없어요.'], 422);
            }
            $bannedCount = adminCount($conn, "SELECT COUNT(*) AS cnt FROM users WHERE is_banned = 1");
            adminJson([
                'ok' => true,
                'message' => $adminMessage,
                'user_id' => $targetId,
                'is_banned' => $isBannedNow,
                'reason' => $isBannedNow ? $reason : '',
                'banned_count' => $bannedCount,
            ]);
        }
    } elseif ($adminAction === 'post_policy') {
        $targetPostId = (int)($_POST['post_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
        $visibility = $_POST['visibility'] ?? 'all';
        if (!in_array($visibility, ['all', 'neighbor', 'private'], true)) {
            $visibility = 'all';
        }
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        if ($targetPostId > 0) {
            $stmt = $conn->prepare("UPDATE posts SET status = ?, visibility = ?, is_pinned = ? WHERE id = ?");
            $stmt->bind_param("ssii", $status, $visibility, $isPinned, $targetPostId);
            $stmt->execute();
            $stmt->close();
            adminLogAction($conn, $hasModerationLogs, $adminId, 'post', $targetPostId, 'update_post_policy');
            $adminMessage = '글 권한을 저장했어요.';
        }
    } elseif ($adminAction === 'post_delete') {
        $targetPostId = (int)($_POST['post_id'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        $deletedPost = false;
        if ($targetPostId <= 0) {
            $adminMessage = '글을 찾을 수 없어요.';
        } elseif (($_POST['confirm_delete'] ?? '') !== '1') {
            $adminMessage = '강제 삭제 확인에 체크해야 삭제할 수 있어요.';
        } elseif (adminDeletePost($conn, $targetPostId)) {
            adminLogAction($conn, $hasModerationLogs, $adminId, 'post', $targetPostId, 'delete_post', $reason);
            $adminMessage = '글을 강제 삭제했어요.';
            $deletedPost = true;
        } else {
            $adminMessage = '이미 삭제됐거나 찾을 수 없는 글입니다.';
        }

        if ($isAjaxRequest) {
            if (!$deletedPost) {
                adminJson(['ok' => false, 'message' => $adminMessage ?: '삭제할 수 없어요.'], 422);
            }
            adminJson([
                'ok' => true,
                'message' => $adminMessage,
                'post_id' => $targetPostId,
                'post_count' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts"),
                'published_count' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'published'"),
                'draft_count' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'draft'"),
            ]);
        }
    } elseif ($adminAction === 'comment_delete') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        if ($commentId > 0) {
            $stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
            $stmt->bind_param("i", $commentId);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            if ($deleted) {
                adminLogAction($conn, $hasModerationLogs, $adminId, 'comment', $commentId, 'delete_comment', $reason);
                $adminMessage = '댓글을 삭제했어요.';
            } else {
                $adminMessage = '이미 삭제됐거나 찾을 수 없는 댓글입니다.';
            }
        }
    } elseif ($adminAction === 'report_status' && $hasReports) {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $adminNote = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 255);
        if (!in_array($status, ['pending', 'reviewed', 'resolved'], true)) {
            $status = 'pending';
        }
        if ($reportId > 0) {
            $stmt = $conn->prepare("UPDATE reports SET status = ?, admin_note = ? WHERE id = ?");
            $stmt->bind_param("ssi", $status, $adminNote, $reportId);
            $stmt->execute();
            $stmt->close();
            adminLogAction($conn, $hasModerationLogs, $adminId, 'report', $reportId, 'update_report_status', $adminNote);
            $adminMessage = '신고 처리 상태를 저장했어요.';
        }
    } elseif ($adminAction === 'site_settings' && $hasSiteSettings) {
        $siteNotice = mb_substr(trim($_POST['site_notice'] ?? ''), 0, 500);
        $mainFeatureTitle = mb_substr(trim($_POST['main_feature_title'] ?? ''), 0, 100);
        $mainFeatureText = mb_substr(trim($_POST['main_feature_text'] ?? ''), 0, 255);
        $allowPublicJoin = isset($_POST['allow_public_join']) ? '1' : '0';
        $settingsToSave = [
            'site_notice' => $siteNotice,
            'main_feature_title' => $mainFeatureTitle,
            'main_feature_text' => $mainFeatureText,
            'allow_public_join' => $allowPublicJoin,
        ];
        foreach ($settingsToSave as $key => $value) {
            $stmt = $conn->prepare(
                "INSERT INTO site_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
            $stmt->close();
        }
        $adminMessage = '사이트 설정을 저장했어요.';
    }
}

$summary = [
    'users' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM users" . adminPeriodWhere('created_at', $periodDays)),
    'banned_users' => $hasBanColumn ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM users WHERE is_banned = 1") : 0,
    'today_users' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM users WHERE DATE(created_at) = CURDATE()"),
    'posts' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts" . adminPeriodWhere('created_at', $periodDays)),
    'published' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'published'" . adminPeriodAnd('created_at', $periodDays)),
    'drafts' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'draft'" . adminPeriodAnd('created_at', $periodDays)),
    'comments' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM comments" . adminPeriodWhere('created_at', $periodDays)),
    'guestbook' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM guestbook" . adminPeriodWhere('created_at', $periodDays)),
    'pending_reports' => $hasReports ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM reports WHERE status = 'pending'" . adminPeriodAnd('created_at', $periodDays)) : 0,
    'likes' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM likes" . adminPeriodWhere('created_at', $periodDays)),
    'comment_likes' => $hasCommentLikes ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM comment_likes" . adminPeriodWhere('created_at', $periodDays)) : 0,
    'scraps' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM scraps" . adminPeriodWhere('created_at', $periodDays)),
    'neighbors' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM neighbors" . adminPeriodWhere('created_at', $periodDays)),
    'tags' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM tags"),
    'today_visit' => adminCount($conn, "SELECT COALESCE(SUM(count), 0) AS cnt FROM visit_logs WHERE visit_date = CURDATE()"),
    'total_visit' => adminCount($conn, "SELECT COALESCE(SUM(count), 0) AS cnt FROM visit_logs" . adminPeriodWhere('visit_date', $periodDays)),
    'point_balance' => $hasPoints ? adminCount($conn, "SELECT COALESCE(SUM(balance), 0) AS cnt FROM point_wallets") : 0,
    'point_activity' => $hasPoints ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM point_transactions" . adminPeriodWhere('created_at', $periodDays)) : 0,
    'roulette_today' => $hasRoulette ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM roulette_spins WHERE spin_date = CURDATE()") : 0,
    'ai_activity' => $hasAiLogs ? adminCount($conn, "SELECT COUNT(*) AS cnt FROM ai_assist_logs" . adminPeriodWhere('created_at', $periodDays)) : 0,
];

$userSearch = mb_substr(trim($_GET['user_q'] ?? ''), 0, 60);
$userState = $_GET['user_state'] ?? 'all';
if (!in_array($userState, ['all', 'admin', 'banned', 'normal'], true)) {
    $userState = 'all';
}

$postSearch = mb_substr(trim($_GET['post_q'] ?? ''), 0, 80);
$postStatusFilter = $_GET['post_status'] ?? 'all';
if (!in_array($postStatusFilter, ['all', 'published', 'draft'], true)) {
    $postStatusFilter = 'all';
}
$postVisibilityFilter = $_GET['post_visibility'] ?? 'any';
if (!in_array($postVisibilityFilter, ['any', 'all', 'neighbor', 'private'], true)) {
    $postVisibilityFilter = 'any';
}

$globalSearch = mb_substr(trim($_GET['admin_q'] ?? ''), 0, 100);
$globalScope = $_GET['admin_scope'] ?? 'all';
$globalScopeLabels = [
    'users' => ['회원·블로그'],
    'posts' => ['게시글'],
    'comments' => ['댓글'],
    'guestbook' => ['방명록'],
    'messages' => ['쪽지'],
    'taxonomy' => ['카테고리', '태그'],
    'reports' => ['신고'],
    'logs' => ['운영 로그'],
    'points' => ['포인트 내역'],
    'ai' => ['AI 이용 기록'],
    'settings' => ['사이트 설정'],
];
if ($globalScope !== 'all' && !isset($globalScopeLabels[$globalScope])) {
    $globalScope = 'all';
}
$globalSearchGroups = [];
$globalSearchTotal = 0;
if ($globalSearch !== '') {
    $globalLike = '%' . $globalSearch . '%';

    $searchUsers = adminFetchAll(
        $conn,
        "SELECT id, email, name, nickname, blog_title, intro, created_at
         FROM users
         WHERE CAST(id AS CHAR) LIKE ? OR email LIKE ? OR name LIKE ? OR nickname LIKE ? OR blog_title LIKE ? OR intro LIKE ?
         ORDER BY created_at DESC, id DESC LIMIT 8",
        'ssssss',
        [$globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchUsers as $row) {
        $items[] = [
            'title' => $row['nickname'] . ' · ' . $row['name'],
            'description' => $row['blog_title'] ?: ($row['nickname'] . '의 블로그'),
            'meta' => $row['email'] . ' · 회원 #' . $row['id'],
            'url' => 'blog.php?id=' . (int)$row['id'],
        ];
    }
    if ($items) $globalSearchGroups['회원·블로그'] = $items;

    $searchPosts = adminFetchAll(
        $conn,
        "SELECT p.id, p.title, p.content, p.location_name, p.status, p.visibility, p.created_at,
                u.nickname, c.name AS category_name
         FROM posts p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE CAST(p.id AS CHAR) LIKE ? OR p.title LIKE ? OR p.content LIKE ? OR p.location_name LIKE ?
            OR p.status LIKE ? OR p.visibility LIKE ? OR u.nickname LIKE ? OR c.name LIKE ?
         ORDER BY p.created_at DESC, p.id DESC LIMIT 8",
        'ssssssss',
        [$globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchPosts as $row) {
        $items[] = [
            'title' => $row['title'],
            'description' => adminSearchExcerpt($row['content']),
            'meta' => $row['nickname'] . ' · ' . ($row['category_name'] ?: '카테고리 없음') . ' · ' . $row['status'] . '/' . $row['visibility'],
            'url' => 'view.php?id=' . (int)$row['id'],
        ];
    }
    if ($items) $globalSearchGroups['게시글'] = $items;

    $searchComments = adminFetchAll(
        $conn,
        "SELECT c.id, c.post_id, c.content, c.created_at, u.nickname, p.title AS post_title
         FROM comments c
         JOIN users u ON u.id = c.user_id
         JOIN posts p ON p.id = c.post_id
         WHERE CAST(c.id AS CHAR) LIKE ? OR c.content LIKE ? OR u.nickname LIKE ? OR p.title LIKE ?
         ORDER BY c.created_at DESC, c.id DESC LIMIT 8",
        'ssss',
        [$globalLike, $globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchComments as $row) {
        $items[] = [
            'title' => $row['nickname'] . '의 댓글',
            'description' => adminSearchExcerpt($row['content']),
            'meta' => $row['post_title'] . ' · 댓글 #' . $row['id'],
            'url' => 'view.php?id=' . (int)$row['post_id'] . '#comment-' . (int)$row['id'],
        ];
    }
    if ($items) $globalSearchGroups['댓글'] = $items;

    $searchGuestbook = adminFetchAll(
        $conn,
        "SELECT g.id, g.owner_id, g.content, writer.nickname AS writer_nickname, owner.nickname AS owner_nickname
         FROM guestbook g
         JOIN users writer ON writer.id = g.user_id
         JOIN users owner ON owner.id = g.owner_id
         WHERE CAST(g.id AS CHAR) LIKE ? OR g.content LIKE ? OR writer.nickname LIKE ? OR owner.nickname LIKE ?
         ORDER BY g.created_at DESC, g.id DESC LIMIT 8",
        'ssss',
        [$globalLike, $globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchGuestbook as $row) {
        $items[] = [
            'title' => $row['writer_nickname'] . ' → ' . $row['owner_nickname'],
            'description' => adminSearchExcerpt($row['content']),
            'meta' => '방명록 #' . $row['id'],
            'url' => 'guestbook.php?id=' . (int)$row['owner_id'],
        ];
    }
    if ($items) $globalSearchGroups['방명록'] = $items;

    $searchMessages = adminFetchAll(
        $conn,
        "SELECT m.id, m.content, m.is_read, sender.id AS sender_id, sender.nickname AS sender_nickname,
                receiver.nickname AS receiver_nickname
         FROM messages m
         JOIN users sender ON sender.id = m.sender_id
         JOIN users receiver ON receiver.id = m.receiver_id
         WHERE CAST(m.id AS CHAR) LIKE ? OR m.content LIKE ? OR sender.nickname LIKE ? OR receiver.nickname LIKE ?
         ORDER BY m.created_at DESC, m.id DESC LIMIT 8",
        'ssss',
        [$globalLike, $globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchMessages as $row) {
        $items[] = [
            'title' => $row['sender_nickname'] . ' → ' . $row['receiver_nickname'],
            'description' => adminSearchExcerpt($row['content']),
            'meta' => '쪽지 #' . $row['id'] . ' · ' . ((int)$row['is_read'] === 1 ? '읽음' : '안 읽음'),
            'url' => 'blog.php?id=' . (int)$row['sender_id'],
        ];
    }
    if ($items) $globalSearchGroups['쪽지'] = $items;

    $searchCategories = adminFetchAll(
        $conn,
        "SELECT c.id, c.user_id, c.name, u.nickname
         FROM categories c JOIN users u ON u.id = c.user_id
         WHERE CAST(c.id AS CHAR) LIKE ? OR c.name LIKE ? OR u.nickname LIKE ?
         ORDER BY c.created_at DESC, c.id DESC LIMIT 8",
        'sss',
        [$globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchCategories as $row) {
        $items[] = [
            'title' => $row['name'],
            'description' => $row['nickname'] . '의 카테고리',
            'meta' => '카테고리 #' . $row['id'],
            'url' => 'blog.php?id=' . (int)$row['user_id'] . '&cat=' . (int)$row['id'],
        ];
    }
    if ($items) $globalSearchGroups['카테고리'] = $items;

    $searchTags = adminFetchAll(
        $conn,
        "SELECT id, name, normalized_name FROM tags
         WHERE CAST(id AS CHAR) LIKE ? OR name LIKE ? OR normalized_name LIKE ?
         ORDER BY name LIMIT 8",
        'sss',
        [$globalLike, $globalLike, $globalLike]
    );
    $items = [];
    foreach ($searchTags as $row) {
        $items[] = [
            'title' => '#' . $row['name'],
            'description' => '연결된 게시글 보기',
            'meta' => '태그 #' . $row['id'],
            'url' => 'index.php?tag=' . (int)$row['id'],
        ];
    }
    if ($items) $globalSearchGroups['태그'] = $items;

    if ($hasReports) {
        $searchReports = adminFetchAll(
            $conn,
            "SELECT r.id, r.target_type, r.target_id, r.reason, r.admin_note, r.status, u.nickname
             FROM reports r JOIN users u ON u.id = r.reporter_id
             WHERE CAST(r.id AS CHAR) LIKE ? OR r.target_type LIKE ? OR CAST(r.target_id AS CHAR) LIKE ?
                OR r.reason LIKE ? OR r.admin_note LIKE ? OR r.status LIKE ? OR u.nickname LIKE ?
             ORDER BY r.created_at DESC, r.id DESC LIMIT 8",
            'sssssss',
            [$globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike]
        );
        $items = [];
        foreach ($searchReports as $row) {
            $items[] = [
                'title' => '신고 #' . $row['id'] . ' · ' . $row['target_type'] . ' #' . $row['target_id'],
                'description' => adminSearchExcerpt($row['reason']),
                'meta' => $row['nickname'] . ' · ' . $row['status'],
                'url' => '#reports',
            ];
        }
        if ($items) $globalSearchGroups['신고'] = $items;
    }

    if ($hasModerationLogs) {
        $searchLogs = adminFetchAll(
            $conn,
            "SELECT ml.id, ml.target_type, ml.target_id, ml.action, ml.reason, u.nickname
             FROM moderation_logs ml JOIN users u ON u.id = ml.admin_id
             WHERE CAST(ml.id AS CHAR) LIKE ? OR ml.target_type LIKE ? OR CAST(ml.target_id AS CHAR) LIKE ?
                OR ml.action LIKE ? OR ml.reason LIKE ? OR u.nickname LIKE ?
             ORDER BY ml.created_at DESC, ml.id DESC LIMIT 8",
            'ssssss',
            [$globalLike, $globalLike, $globalLike, $globalLike, $globalLike, $globalLike]
        );
        $items = [];
        foreach ($searchLogs as $row) {
            $items[] = [
                'title' => $row['action'],
                'description' => adminSearchExcerpt($row['reason']),
                'meta' => $row['nickname'] . ' · ' . $row['target_type'] . ' #' . $row['target_id'],
                'url' => '#logs',
            ];
        }
        if ($items) $globalSearchGroups['운영 로그'] = $items;
    }

    if ($hasPoints) {
        $searchPoints = adminFetchAll(
            $conn,
            "SELECT pt.id, pt.amount, pt.action_type, pt.description, u.id AS user_id, u.nickname
             FROM point_transactions pt JOIN users u ON u.id = pt.user_id
             WHERE CAST(pt.id AS CHAR) LIKE ? OR pt.action_type LIKE ? OR pt.description LIKE ? OR u.nickname LIKE ?
             ORDER BY pt.created_at DESC, pt.id DESC LIMIT 8",
            'ssss',
            [$globalLike, $globalLike, $globalLike, $globalLike]
        );
        $items = [];
        foreach ($searchPoints as $row) {
            $items[] = [
                'title' => $row['nickname'] . ' · ' . ((int)$row['amount'] > 0 ? '+' : '') . number_format((int)$row['amount']) . 'P',
                'description' => $row['description'],
                'meta' => $row['action_type'] . ' · 포인트 내역 #' . $row['id'],
                'url' => 'blog.php?id=' . (int)$row['user_id'],
            ];
        }
        if ($items) $globalSearchGroups['포인트 내역'] = $items;
    }

    if ($hasAiLogs) {
        $searchAi = adminFetchAll(
            $conn,
            "SELECT al.id, al.assist_mode, al.input_excerpt, al.used_api, u.id AS user_id, u.nickname
             FROM ai_assist_logs al JOIN users u ON u.id = al.user_id
             WHERE CAST(al.id AS CHAR) LIKE ? OR al.assist_mode LIKE ? OR al.input_excerpt LIKE ? OR u.nickname LIKE ?
             ORDER BY al.created_at DESC, al.id DESC LIMIT 8",
            'ssss',
            [$globalLike, $globalLike, $globalLike, $globalLike]
        );
        $items = [];
        foreach ($searchAi as $row) {
            $items[] = [
                'title' => $row['nickname'] . ' · ' . $row['assist_mode'],
                'description' => adminSearchExcerpt($row['input_excerpt']),
                'meta' => ((int)$row['used_api'] === 1 ? 'API 사용' : '로컬 도우미') . ' · AI 기록 #' . $row['id'],
                'url' => 'blog.php?id=' . (int)$row['user_id'],
            ];
        }
        if ($items) $globalSearchGroups['AI 이용 기록'] = $items;
    }

    if ($hasSiteSettings) {
        $searchSettings = adminFetchAll(
            $conn,
            "SELECT setting_key, setting_value FROM site_settings
             WHERE setting_key LIKE ? OR setting_value LIKE ? ORDER BY setting_key LIMIT 8",
            'ss',
            [$globalLike, $globalLike]
        );
        $items = [];
        foreach ($searchSettings as $row) {
            $items[] = [
                'title' => $row['setting_key'],
                'description' => adminSearchExcerpt($row['setting_value']),
                'meta' => '사이트 설정',
                'url' => '#site-settings',
            ];
        }
        if ($items) $globalSearchGroups['사이트 설정'] = $items;
    }

    if ($globalScope !== 'all') {
        $allowedLabels = $globalScopeLabels[$globalScope];
        $globalSearchGroups = array_filter(
            $globalSearchGroups,
            static fn($label) => in_array($label, $allowedLabels, true),
            ARRAY_FILTER_USE_KEY
        );
    }
    foreach ($globalSearchGroups as $groupItems) {
        $globalSearchTotal += count($groupItems);
    }
}

$reportStatusFilter = $_GET['report_status'] ?? 'all';
if (!in_array($reportStatusFilter, ['all', 'pending', 'reviewed', 'resolved'], true)) {
    $reportStatusFilter = 'all';
}
$reportTypeFilter = $_GET['report_type'] ?? 'all';
if (!in_array($reportTypeFilter, ['all', 'post', 'comment', 'guestbook', 'message'], true)) {
    $reportTypeFilter = 'all';
}

$banSelect = $hasBanColumn
    ? "is_banned, banned_reason, banned_at"
    : "0 AS is_banned, NULL AS banned_reason, NULL AS banned_at";

$userWhere = ["1 = 1"];
$userTypes = "";
$userParams = [];
if ($userSearch !== '') {
    $like = '%' . $userSearch . '%';
    $userWhere[] = "(email LIKE ? OR name LIKE ? OR nickname LIKE ? OR blog_title LIKE ?)";
    $userTypes .= "ssss";
    array_push($userParams, $like, $like, $like, $like);
}
if ($userState === 'admin') {
    $userWhere[] = "is_admin = 1";
} elseif ($userState === 'banned' && $hasBanColumn) {
    $userWhere[] = "is_banned = 1";
} elseif ($userState === 'normal' && $hasBanColumn) {
    $userWhere[] = "is_banned = 0";
}
$pointBalanceSelect = $hasPoints
    ? "COALESCE((SELECT pw.balance FROM point_wallets pw WHERE pw.user_id = users.id), 0) AS point_balance"
    : "0 AS point_balance";
$recentUsers = adminFetchAll(
    $conn,
    "SELECT id, email, name, nickname, blog_title, is_admin, $banSelect, $pointBalanceSelect, created_at
     FROM users
     WHERE " . implode(" AND ", $userWhere) . "
     ORDER BY created_at DESC, id DESC
     LIMIT 20",
    $userTypes,
    $userParams
);

$postWhere = ["1 = 1"];
$postTypes = "";
$postParams = [];
if ($postSearch !== '') {
    $like = '%' . $postSearch . '%';
    $postWhere[] = "(p.title LIKE ? OR u.nickname LIKE ? OR p.content LIKE ?)";
    $postTypes .= "sss";
    array_push($postParams, $like, $like, $like);
}
if ($postStatusFilter !== 'all') {
    $postWhere[] = "p.status = ?";
    $postTypes .= "s";
    $postParams[] = $postStatusFilter;
}
if ($postVisibilityFilter !== 'any') {
    $postWhere[] = "p.visibility = ?";
    $postTypes .= "s";
    $postParams[] = $postVisibilityFilter;
}
$commentLikeSelect = $hasCommentLikes
    ? "(SELECT COUNT(*) FROM comment_likes cl JOIN comments cc ON cc.id = cl.comment_id WHERE cc.post_id = p.id) AS comment_like_count"
    : "0 AS comment_like_count";
$recentPosts = adminFetchAll(
    $conn,
    "SELECT p.id, p.title, p.status, p.visibility, p.is_pinned, p.view_count, p.created_at,
            u.id AS user_id, u.nickname,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
            $commentLikeSelect
     FROM posts p
     JOIN users u ON u.id = p.user_id
     WHERE " . implode(" AND ", $postWhere) . "
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 12",
    $postTypes,
    $postParams
);

$siteSettings = [
    'site_notice' => '',
    'main_feature_title' => 'BRIDGE 206',
    'main_feature_text' => '세대와 관심사를 잇는 블로그',
    'allow_public_join' => '1',
];
if ($hasSiteSettings) {
    foreach (adminFetchAll($conn, "SELECT setting_key, setting_value FROM site_settings") as $settingRow) {
        if (array_key_exists($settingRow['setting_key'], $siteSettings)) {
            $siteSettings[$settingRow['setting_key']] = (string)$settingRow['setting_value'];
        }
    }
}

$recentCommentLikeSelect = $hasCommentLikes
    ? "(SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS comment_like_count"
    : "0 AS comment_like_count";
$commentSearch = mb_substr(trim($_GET['comment_q'] ?? ''), 0, 80);
$commentWhere = '';
$commentTypes = '';
$commentParams = [];
if ($commentSearch !== '') {
    $commentLike = '%' . $commentSearch . '%';
    $commentWhere = "WHERE c.content LIKE ? OR u.nickname LIKE ? OR p.title LIKE ?";
    $commentTypes = 'sss';
    $commentParams = [$commentLike, $commentLike, $commentLike];
}
$recentComments = adminFetchAll(
    $conn,
    "SELECT c.id, c.content, c.created_at, c.post_id,
            $recentCommentLikeSelect,
            u.nickname AS writer_nickname, p.title AS post_title
     FROM comments c
     JOIN users u ON u.id = c.user_id
     JOIN posts p ON p.id = c.post_id
     $commentWhere
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 8",
    $commentTypes,
    $commentParams
);

$hotCommentPosts = adminFetchAll(
    $conn,
    "SELECT p.id, p.title, u.nickname,
            COUNT(c.id) AS comment_count,
            MAX(c.created_at) AS last_comment_at
     FROM posts p
     JOIN users u ON u.id = p.user_id
     JOIN comments c ON c.post_id = p.id
     GROUP BY p.id, p.title, u.nickname
     ORDER BY comment_count DESC, last_comment_at DESC
     LIMIT 5"
);

$recentPointTransactions = $hasPoints ? adminFetchAll(
    $conn,
    "SELECT pt.amount, pt.action_type, pt.description, pt.created_at, u.id AS user_id, u.nickname
     FROM point_transactions pt
     JOIN users u ON u.id = pt.user_id
     ORDER BY pt.created_at DESC, pt.id DESC
     LIMIT 8"
) : [];
$recentAiLogs = $hasAiLogs ? adminFetchAll(
    $conn,
    "SELECT al.assist_mode, al.used_api, al.created_at, u.id AS user_id, u.nickname
     FROM ai_assist_logs al
     JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 8"
) : [];

$logActionLabels = [
    'grant_admin' => '관리자 권한 부여',
    'revoke_admin' => '관리자 권한 해제',
    'ban_user' => '회원 밴',
    'unban_user' => '회원 밴 해제',
    'update_post_policy' => '글 공개/공지 변경',
    'delete_post' => '글 강제 삭제',
    'delete_comment' => '댓글 삭제',
    'update_report_status' => '신고 상태 변경',
    'adjust_points' => '포인트 조정',
];
$logTargetLabels = ['user' => '회원', 'post' => '글', 'comment' => '댓글', 'report' => '신고'];
$logFilter = $_GET['log_filter'] ?? 'all';
$logSearch = mb_substr(trim($_GET['log_q'] ?? ''), 0, 80);
if (!in_array($logFilter, ['all', 'user', 'post', 'comment', 'report'], true)) {
    $logFilter = 'all';
}
$logWhereParts = ['1 = 1'];
$logTypes = '';
$logParams = [];
if ($logFilter !== 'all') {
    $logWhereParts[] = "ml.target_type = ?";
    $logTypes .= "s";
    $logParams[] = $logFilter;
}
if ($logSearch !== '') {
    $logLike = '%' . $logSearch . '%';
    $logWhereParts[] = "(ml.action LIKE ? OR ml.reason LIKE ? OR u.nickname LIKE ? OR CAST(ml.target_id AS CHAR) LIKE ?)";
    $logTypes .= 'ssss';
    array_push($logParams, $logLike, $logLike, $logLike, $logLike);
}
$logWhere = 'WHERE ' . implode(' AND ', $logWhereParts);
$recentLogs = $hasModerationLogs ? adminFetchAll(
    $conn,
    "SELECT ml.target_type, ml.target_id, ml.action, ml.reason, ml.created_at, u.nickname AS admin_nickname
     FROM moderation_logs ml
     JOIN users u ON u.id = ml.admin_id
     $logWhere
     ORDER BY ml.created_at DESC, ml.id DESC
     LIMIT 12",
    $logTypes,
    $logParams
) : [];

$reportWhere = ["1 = 1"];
$reportSearch = mb_substr(trim($_GET['report_q'] ?? ''), 0, 80);
$reportTypes = "";
$reportParams = [];
if ($reportStatusFilter !== 'all') {
    $reportWhere[] = "r.status = ?";
    $reportTypes .= "s";
    $reportParams[] = $reportStatusFilter;
}
if ($reportTypeFilter !== 'all') {
    $reportWhere[] = "r.target_type = ?";
    $reportTypes .= "s";
    $reportParams[] = $reportTypeFilter;
}
if ($reportSearch !== '') {
    $reportLike = '%' . $reportSearch . '%';
    $reportWhere[] = "(r.reason LIKE ? OR r.admin_note LIKE ? OR u.nickname LIKE ? OR CAST(r.target_id AS CHAR) LIKE ?)";
    $reportTypes .= 'ssss';
    array_push($reportParams, $reportLike, $reportLike, $reportLike, $reportLike);
}
$recentReports = $hasReports ? adminFetchAll(
    $conn,
    "SELECT r.id, r.target_type, r.target_id, r.reason, r.status, r.admin_note, r.created_at,
            u.nickname AS reporter_nickname,
            rp.title AS report_post_title,
            rc.post_id AS report_comment_post_id,
            rc.content AS report_comment_content,
            cp.title AS report_comment_post_title,
            rg.owner_id AS report_guestbook_owner_id,
            rg.content AS report_guestbook_content,
            msg.sender_id AS report_message_sender_id,
            msg.receiver_id AS report_message_receiver_id,
            msg.content AS report_message_content
     FROM reports r
     JOIN users u ON u.id = r.reporter_id
     LEFT JOIN posts rp ON r.target_type = 'post' AND rp.id = r.target_id
     LEFT JOIN comments rc ON r.target_type = 'comment' AND rc.id = r.target_id
     LEFT JOIN posts cp ON cp.id = rc.post_id
     LEFT JOIN guestbook rg ON r.target_type = 'guestbook' AND rg.id = r.target_id
     LEFT JOIN messages msg ON r.target_type = 'message' AND msg.id = r.target_id
     WHERE " . implode(" AND ", $reportWhere) . "
     ORDER BY FIELD(r.status, 'pending', 'reviewed', 'resolved'), r.created_at DESC, r.id DESC
     LIMIT 12",
    $reportTypes,
    $reportParams
) : [];

$guestbookSearch = mb_substr(trim($_GET['guestbook_q'] ?? ''), 0, 80);
$guestbookWhere = '';
$guestbookTypes = '';
$guestbookParams = [];
if ($guestbookSearch !== '') {
    $guestbookLike = '%' . $guestbookSearch . '%';
    $guestbookWhere = "WHERE g.content LIKE ? OR writer.nickname LIKE ? OR owner.nickname LIKE ?";
    $guestbookTypes = 'sss';
    $guestbookParams = [$guestbookLike, $guestbookLike, $guestbookLike];
}
$recentGuestbook = adminFetchAll(
    $conn,
    "SELECT g.id, g.content, g.created_at, g.owner_id,
            writer.nickname AS writer_nickname,
            owner.nickname AS owner_nickname
     FROM guestbook g
     JOIN users writer ON writer.id = g.user_id
     JOIN users owner ON owner.id = g.owner_id
     $guestbookWhere
     ORDER BY g.created_at DESC, g.id DESC
     LIMIT 8",
    $guestbookTypes,
    $guestbookParams
);

$tagSearch = mb_substr(trim($_GET['tag_q'] ?? ''), 0, 60);
$tagWhere = '';
$tagTypes = '';
$tagParams = [];
if ($tagSearch !== '') {
    $tagLike = '%' . $tagSearch . '%';
    $tagWhere = 'WHERE t.name LIKE ? OR t.normalized_name LIKE ?';
    $tagTypes = 'ss';
    $tagParams = [$tagLike, $tagLike];
}
$topTags = adminFetchAll(
    $conn,
    "SELECT t.id, t.name, COUNT(*) AS post_count
     FROM tags t
     JOIN post_tags pt ON pt.tag_id = t.id
     JOIN posts p ON p.id = pt.post_id
     $tagWhere
     GROUP BY t.id, t.name
     ORDER BY post_count DESC, t.name ASC
     LIMIT 12",
    $tagTypes,
    $tagParams
);

$topBlogs = adminFetchAll(
    $conn,
    "SELECT u.id, u.nickname, u.blog_title,
            COALESCE(SUM(v.count), 0) AS visit_count,
            (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count
     FROM users u
     LEFT JOIN visit_logs v ON v.user_id = u.id
     GROUP BY u.id, u.nickname, u.blog_title
     ORDER BY visit_count DESC, post_count DESC, u.nickname ASC
     LIMIT 5"
);

$visibilityLabels = ['all' => '전체공개', 'neighbor' => '이웃공개', 'private' => '비공개'];
$statusLabels = ['published' => '발행', 'draft' => '임시저장'];
$reportStatusLabels = ['pending' => '대기', 'reviewed' => '확인', 'resolved' => '조치 완료'];
$reportTargetLabels = ['post' => '글', 'comment' => '댓글', 'guestbook' => '방명록', 'message' => '쪽지'];
function adminReportTargetUrl(array $report): string {
    if ($report['target_type'] === 'post') {
        return 'view.php?id=' . (int)$report['target_id'];
    }
    if ($report['target_type'] === 'comment' && !empty($report['report_comment_post_id'])) {
        return 'view.php?id=' . (int)$report['report_comment_post_id'] . '#comment-' . (int)$report['target_id'];
    }
    if ($report['target_type'] === 'guestbook' && !empty($report['report_guestbook_owner_id'])) {
        return 'guestbook.php?id=' . (int)$report['report_guestbook_owner_id'];
    }
    if ($report['target_type'] === 'message') {
        return 'messages.php';
    }
    return '';
}

function adminReportTargetPreview(array $report): string {
    if ($report['target_type'] === 'post') {
        return (string)($report['report_post_title'] ?? '');
    }
    if ($report['target_type'] === 'comment') {
        return (string)($report['report_comment_content'] ?? '');
    }
    if ($report['target_type'] === 'guestbook') {
        return (string)($report['report_guestbook_content'] ?? '');
    }
    if ($report['target_type'] === 'message') {
        return (string)($report['report_message_content'] ?? '');
    }
    return '';
}

function adminLogTargetUrl(array $log): string {
    if ($log['target_type'] === 'user') {
        return 'blog.php?id=' . (int)$log['target_id'];
    }
    if ($log['target_type'] === 'post') {
        return 'view.php?id=' . (int)$log['target_id'];
    }
    if ($log['target_type'] === 'comment') {
        return 'comments_manage.php?scope=all';
    }
    if ($log['target_type'] === 'report') {
        return 'admin.php?report_status=all#reports';
    }
    return '';
}

function adminQueryWith(array $override): string {
    $query = $_GET;
    foreach ($override as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return http_build_query($query);
}
$opsChecklist = [];
if ($summary['pending_reports'] > 0) {
    $opsChecklist[] = [
        'level' => 'urgent',
        'title' => '대기 신고 처리',
        'body' => number_format($summary['pending_reports']) . '건의 신고가 아직 대기 상태입니다.',
        'href' => 'admin.php?report_status=pending#reports',
    ];
}
if ($summary['banned_users'] > 0) {
    $opsChecklist[] = [
        'level' => 'watch',
        'title' => '밴 회원 상태 점검',
        'body' => number_format($summary['banned_users']) . '명의 밴 회원이 있습니다.',
        'href' => 'admin.php?user_state=banned#users',
    ];
}
if ($summary['drafts'] > 0) {
    $opsChecklist[] = [
        'level' => 'watch',
        'title' => '임시저장 글 확인',
        'body' => number_format($summary['drafts']) . '개의 임시저장 글이 있습니다.',
        'href' => 'admin.php?post_status=draft#posts',
    ];
}
if ($summary['today_users'] > 0) {
    $opsChecklist[] = [
        'level' => 'info',
        'title' => '신규 가입자 확인',
        'body' => '오늘 ' . number_format($summary['today_users']) . '명이 가입했습니다.',
        'href' => '#users',
    ];
}
if ($hasModerationLogs && !$recentLogs) {
    $opsChecklist[] = [
        'level' => 'info',
        'title' => '운영 로그 없음',
        'body' => '선택한 조건의 운영 조치 기록이 없습니다.',
        'href' => '#logs',
    ];
}
if (!$opsChecklist) {
    $opsChecklist[] = [
        'level' => 'done',
        'title' => '긴급 조치 없음',
        'body' => '현재 우선 처리할 신고나 위험 항목이 없습니다.',
        'href' => '#reports',
    ];
}

if (($_GET['export'] ?? '') === 'admin') {
    require_once __DIR__ . '/../app/excel_export.php';
    bridge206ExcelDownloadHeaders(bridge206ExcelFilename('bridge206_admin'));
    bridge206ExcelStart('BRIDGE 206 관리자 대시보드', '기준: ' . $periodLabel . ' / 다운로드 일시: ' . date('Y-m-d H:i:s'));

    $summaryRows = [
        ['기준', $periodLabel],
        ['회원', ['value' => $summary['users'], 'class' => 'num']],
        ['밴 회원', ['value' => $summary['banned_users'], 'class' => 'num']],
        ['오늘 가입', ['value' => $summary['today_users'], 'class' => 'num']],
        ['전체 글', ['value' => $summary['posts'], 'class' => 'num']],
        ['발행 글', ['value' => $summary['published'], 'class' => 'num']],
        ['임시저장', ['value' => $summary['drafts'], 'class' => 'num']],
        ['댓글', ['value' => $summary['comments'], 'class' => 'num']],
        ['방명록', ['value' => $summary['guestbook'], 'class' => 'num']],
        ['대기 신고', ['value' => $summary['pending_reports'], 'class' => 'num']],
        ['공감', ['value' => $summary['likes'], 'class' => 'num']],
        ['댓글 좋아요', ['value' => $summary['comment_likes'], 'class' => 'num']],
        ['스크랩', ['value' => $summary['scraps'], 'class' => 'num']],
        ['이웃 연결', ['value' => $summary['neighbors'], 'class' => 'num']],
        ['태그', ['value' => $summary['tags'], 'class' => 'num']],
        ['오늘 방문', ['value' => $summary['today_visit'], 'class' => 'num']],
        ['누적 방문', ['value' => $summary['total_visit'], 'class' => 'num']],
    ];

    $checkRows = [];
    foreach ($opsChecklist as $item) {
        $checkRows[] = [$item['title'], $item['body']];
    }
    bridge206ExcelTableGroup([
        ['title' => '요약', 'headers' => ['항목', '값'], 'rows' => $summaryRows, 'widths' => [200, 120]],
        ['title' => '운영 체크리스트', 'headers' => ['항목', '내용'], 'rows' => $checkRows, 'widths' => [180, 420]],
    ]);

    $userRows = [];
    foreach ($recentUsers as $u) {
        $userRows[] = [
            $u['id'],
            $u['nickname'],
            $u['name'],
            $u['email'],
            $u['blog_title'] ?: $u['nickname'] . '의 블로그',
            (int)$u['is_banned'] === 1 ? '밴' : '정상',
            (int)$u['is_admin'] === 1 ? '관리자' : '일반',
            ['value' => bridge206ExcelDate($u['created_at']), 'class' => 'date'],
        ];
    }
    bridge206ExcelTable('회원 관리', ['번호', '닉네임', '이름', '이메일', '블로그', '상태', '권한', '가입일'], $userRows ?: [['-', '-', '-', '-', '-', '-', '-', '기록 없음']], [70, 130, 100, 220, 220, 80, 80, 150]);

    $postRows = [];
    foreach ($recentPosts as $p) {
        $postRows[] = [
            $p['id'],
            $p['title'],
            $p['nickname'],
            $statusLabels[$p['status']] ?? $p['status'],
            $visibilityLabels[$p['visibility']] ?? $p['visibility'],
            ['value' => $p['view_count'], 'class' => 'num'],
            ['value' => $p['like_count'], 'class' => 'num'],
            ['value' => $p['comment_like_count'], 'class' => 'num'],
            ['value' => $p['comment_count'], 'class' => 'num'],
            ['value' => bridge206ExcelDate($p['created_at']), 'class' => 'date'],
        ];
    }
    bridge206ExcelTable('게시글 관리', ['번호', '제목', '작성자', '상태', '공개', '조회', '공감', '댓글 좋아요', '댓글', '작성일'], $postRows ?: [['-', '-', '-', '-', '-', 0, 0, 0, 0, '기록 없음']], [70, 300, 120, 90, 100, 70, 70, 100, 70, 150]);

    $reportRows = [];
    foreach ($recentReports as $report) {
        $reportRows[] = [
            $report['id'],
            $reportTargetLabels[$report['target_type']] ?? $report['target_type'],
            $report['target_id'],
            mb_strimwidth(adminReportTargetPreview($report), 0, 120, '...'),
            $report['reporter_nickname'],
            $reportStatusLabels[$report['status']] ?? $report['status'],
            $report['reason'],
            $report['admin_note'] ?? '',
            ['value' => bridge206ExcelDate($report['created_at']), 'class' => 'date'],
        ];
    }
    bridge206ExcelTable('신고 관리', ['번호', '대상', '대상 번호', '대상 내용', '신고자', '상태', '사유', '처리 메모', '접수일'], $reportRows ?: [['-', '-', '-', '신고 기록 없음', '-', '-', '-', '-', '기록 없음']], [70, 90, 90, 320, 120, 100, 220, 220, 150]);

    bridge206ExcelEnd();
    exit;
}

$pageTitle = '관리자 대시보드 · BRIDGE 206';
$pageClass = 'page--wide page--admin';
require_once __DIR__ . '/../app/header.php';
?>

<section class="admin">
  <div class="admin-hero">
    <div>
      <span class="admin-hero__eyebrow">ADMINISTRATOR</span>
      <h1>관리자 대시보드</h1>
      <p>회원, 글, 댓글, 방문 기록을 한 화면에서 확인하는 BRIDGE 206 운영 현황판입니다.</p>
    </div>
    <div class="admin-hero__note">
      <strong>운영 권한 관리</strong>
      <span>회원 관리자 권한, 글 공개 상태, 사이트 공지와 메인 문구를 조정할 수 있습니다.</span>
    </div>
  </div>

  <?php if ($adminMessage !== ''): ?>
    <div class="form-ok"><?= htmlspecialchars($adminMessage) ?></div>
  <?php endif; ?>

  <div class="admin-workspace">
    <aside class="admin-sidebar" aria-label="관리자 대시보드 메뉴">
      <strong>관리 메뉴</strong>
      <nav>
        <span>대시보드</span>
        <a href="#global-search">통합 검색</a>
        <a href="#feature-activity">이용 현황</a>
        <span>사이트 운영</span>
        <a href="#site-settings">사이트 설정</a>
        <a href="#users">회원 관리</a>
        <a href="#posts">게시글 관리</a>
        <span>소통 · 안전</span>
        <a href="#comments">최근 댓글</a>
        <a href="#reports">신고 관리</a>
        <a href="#hot-comments">댓글 많은 글</a>
        <span>운영 기록</span>
        <a href="#logs">운영 로그</a>
        <a href="#blogs">방명록</a>
        <a href="#tags">태그</a>
        <a href="#top-blogs">인기 블로그</a>
      </nav>
    </aside>
    <div class="admin-workspace__content">

  <section class="admin-global-search" id="global-search">
    <div class="admin-global-search__head">
      <div>
        <span>ADMIN SEARCH</span>
        <h2>관리자 통합 검색</h2>
      </div>
      <p>회원부터 게시글, 댓글, 쪽지, 운영 기록까지 한 번에 찾습니다.</p>
    </div>
    <form class="admin-global-search__form" method="get" action="admin.php#global-search">
      <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
      <label>
        <select name="admin_scope" aria-label="검색할 항목">
          <option value="all" <?= $globalScope === 'all' ? 'selected' : '' ?>>모든 항목</option>
          <option value="users" <?= $globalScope === 'users' ? 'selected' : '' ?>>회원·블로그</option>
          <option value="posts" <?= $globalScope === 'posts' ? 'selected' : '' ?>>게시글</option>
          <option value="comments" <?= $globalScope === 'comments' ? 'selected' : '' ?>>댓글</option>
          <option value="guestbook" <?= $globalScope === 'guestbook' ? 'selected' : '' ?>>방명록</option>
          <option value="messages" <?= $globalScope === 'messages' ? 'selected' : '' ?>>쪽지</option>
          <option value="taxonomy" <?= $globalScope === 'taxonomy' ? 'selected' : '' ?>>카테고리·태그</option>
          <option value="reports" <?= $globalScope === 'reports' ? 'selected' : '' ?>>신고</option>
          <option value="logs" <?= $globalScope === 'logs' ? 'selected' : '' ?>>운영 로그</option>
          <option value="points" <?= $globalScope === 'points' ? 'selected' : '' ?>>포인트 내역</option>
          <option value="ai" <?= $globalScope === 'ai' ? 'selected' : '' ?>>AI 이용 기록</option>
          <option value="settings" <?= $globalScope === 'settings' ? 'selected' : '' ?>>사이트 설정</option>
        </select>
      </label>
      <label>
        <input type="search" name="admin_q" value="<?= htmlspecialchars($globalSearch) ?>" maxlength="100" aria-label="관리자 통합 검색어" placeholder="이름, 닉네임, 이메일, 제목, 본문, 댓글, 태그, 신고 사유 등을 검색">
      </label>
      <button type="submit">전체 검색</button>
      <?php if ($globalSearch !== ''): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#global-search">검색 초기화</a><?php endif; ?>
    </form>

    <?php if ($globalSearch !== ''): ?>
      <div class="admin-global-search__summary">
        <strong>‘<?= htmlspecialchars($globalSearch) ?>’ 검색 결과</strong>
        <span><?= number_format($globalSearchTotal) ?>개 표시 · 종류별 최대 8개</span>
      </div>
      <?php if (!$globalSearchGroups): ?>
        <p class="admin-empty">일치하는 회원이나 콘텐츠, 운영 기록이 없습니다.</p>
      <?php else: ?>
        <div class="admin-global-results">
          <?php foreach ($globalSearchGroups as $groupLabel => $groupItems): ?>
            <section class="admin-global-group">
              <div class="admin-global-group__head">
                <h3><?= htmlspecialchars($groupLabel) ?></h3>
                <span><?= count($groupItems) ?>개</span>
              </div>
              <div class="admin-global-group__list">
                <?php foreach ($groupItems as $item): ?>
                  <a href="<?= htmlspecialchars($item['url']) ?>">
                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                    <?php if ($item['description'] !== ''): ?><span><?= htmlspecialchars($item['description']) ?></span><?php endif; ?>
                    <em><?= htmlspecialchars($item['meta']) ?></em>
                  </a>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <div class="admin-period">
    <strong>요약 기준: <?= htmlspecialchars($periodLabel) ?></strong>
    <nav>
      <a class="<?= $summaryPeriod === '7' ? 'is-active' : '' ?>" href="admin.php?period=7">최근 7일</a>
      <a class="<?= $summaryPeriod === '30' ? 'is-active' : '' ?>" href="admin.php?period=30">최근 30일</a>
      <a class="<?= $summaryPeriod === 'all' ? 'is-active' : '' ?>" href="admin.php?period=all">전체 기간</a>
      <?php $exportQuery = $_GET; $exportQuery['export'] = 'admin'; ?>
      <a class="admin-period__download" href="admin.php?<?= htmlspecialchars(http_build_query($exportQuery)) ?>">엑셀 다운로드</a>
    </nav>
  </div>

  <div class="admin-ops" aria-label="관리자 운영 현황">
    <section class="admin-ops__group admin-ops__group--attention">
      <div class="admin-ops__head">
        <h2>주의 필요</h2>
        <span>먼저 확인할 항목</span>
      </div>
      <div class="admin-ops__list">
        <a class="admin-ops__metric is-critical" href="#reports">
          <strong><?= number_format($summary['pending_reports']) ?></strong>
          <span>대기 신고</span>
          <em>신고 관리로 이동</em>
        </a>
        <a class="admin-ops__metric" href="#users">
          <strong data-admin-banned-count><?= number_format($summary['banned_users']) ?></strong>
          <span>밴 회원</span>
          <em>회원 상태 점검</em>
        </a>
        <a class="admin-ops__metric" href="#posts">
          <strong data-admin-draft-count><?= number_format($summary['drafts']) ?></strong>
          <span>임시저장 글</span>
          <em>발행 상태 확인</em>
        </a>
      </div>
    </section>

    <section class="admin-ops__group">
      <div class="admin-ops__head">
        <h2>콘텐츠</h2>
        <span><?= htmlspecialchars($periodLabel) ?></span>
      </div>
      <div class="admin-ops__list">
        <a class="admin-ops__metric" href="#posts">
          <strong data-admin-post-count><?= number_format($summary['posts']) ?></strong>
          <span>전체 글</span>
          <em>발행 <span data-admin-published-count><?= number_format($summary['published']) ?></span> · 임시 <span data-admin-draft-count><?= number_format($summary['drafts']) ?></span></em>
        </a>
        <a class="admin-ops__metric" href="#comments">
          <strong><?= number_format($summary['comments']) ?></strong>
          <span>댓글</span>
          <em>방명록 <?= number_format($summary['guestbook']) ?></em>
        </a>
        <a class="admin-ops__metric" href="#tags">
          <strong><?= number_format($summary['tags']) ?></strong>
          <span>태그</span>
          <em>콘텐츠 분류</em>
        </a>
      </div>
    </section>

    <section class="admin-ops__group">
      <div class="admin-ops__head">
        <h2>회원/참여</h2>
        <span>커뮤니티 활동</span>
      </div>
      <div class="admin-ops__list">
        <a class="admin-ops__metric" href="#users">
          <strong><?= number_format($summary['users']) ?></strong>
          <span>회원</span>
          <em>오늘 가입 <?= number_format($summary['today_users']) ?></em>
        </a>
        <div class="admin-ops__metric">
          <strong><?= number_format($summary['likes'] + $summary['comment_likes'] + $summary['scraps']) ?></strong>
          <span>반응</span>
          <em>공감 <?= number_format($summary['likes']) ?> · 댓글 좋아요 <?= number_format($summary['comment_likes']) ?> · 스크랩 <?= number_format($summary['scraps']) ?></em>
        </div>
        <div class="admin-ops__metric">
          <strong><?= number_format($summary['neighbors']) ?></strong>
          <span>이웃 연결</span>
          <em>관계 활성도</em>
        </div>
      </div>
    </section>

    <section class="admin-ops__group">
      <div class="admin-ops__head">
        <h2>방문</h2>
        <span>트래픽</span>
      </div>
      <div class="admin-ops__list">
        <a class="admin-ops__metric" href="#blogs">
          <strong><?= number_format($summary['today_visit']) ?></strong>
          <span>오늘 방문</span>
          <em>실시간 운영 참고</em>
        </a>
        <a class="admin-ops__metric" href="#blogs">
          <strong><?= number_format($summary['total_visit']) ?></strong>
          <span>누적 방문</span>
          <em><?= htmlspecialchars($periodLabel) ?> 기준</em>
        </a>
      </div>
    </section>

    <section class="admin-ops__group">
      <div class="admin-ops__head">
        <h2>확장 기능</h2>
        <span>포인트 · AI</span>
      </div>
      <div class="admin-ops__list">
        <a class="admin-ops__metric" href="#feature-activity">
          <strong><?= number_format($summary['point_balance']) ?>P</strong>
          <span>전체 보유 포인트</span>
          <em><?= number_format($summary['point_activity']) ?>건의 변동</em>
        </a>
        <a class="admin-ops__metric" href="#feature-activity">
          <strong><?= number_format($summary['roulette_today']) ?></strong>
          <span>오늘 룰렛 참여</span>
          <em>하루 한 번 참여</em>
        </a>
        <a class="admin-ops__metric" href="#feature-activity">
          <strong><?= number_format($summary['ai_activity']) ?></strong>
          <span>AI 글쓰기 이용</span>
          <em><?= htmlspecialchars($periodLabel) ?> 기준</em>
        </a>
      </div>
    </section>
  </div>

  <section class="admin-checklist" aria-label="오늘 운영 체크리스트">
    <div class="admin-checklist__head">
      <h2>오늘 운영 체크리스트</h2>
      <span><?= count($opsChecklist) ?>개 항목</span>
    </div>
    <div class="admin-checklist__items">
      <?php foreach ($opsChecklist as $item): ?>
        <a class="admin-checklist__item admin-checklist__item--<?= htmlspecialchars($item['level']) ?>" href="<?= htmlspecialchars($item['href']) ?>">
          <b><?= htmlspecialchars($item['title']) ?></b>
          <span><?= htmlspecialchars($item['body']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="admin-grid">
    <section class="admin-panel admin-panel--wide" id="feature-activity">
      <div class="admin-panel__head">
        <h2>포인트 · AI 운영 현황</h2>
        <span>최근 이용 기록</span>
      </div>
      <?php if (!$hasPoints && !$hasAiLogs): ?>
        <p class="admin-empty">포인트와 AI 마이그레이션을 실행하면 이용 현황이 표시됩니다.</p>
      <?php else: ?>
        <div class="admin-feature-feed">
          <div>
            <h3>포인트 변동</h3>
            <div class="admin-list">
              <?php foreach ($recentPointTransactions as $transaction): ?>
                <a class="admin-list__item" href="blog.php?id=<?= (int)$transaction['user_id'] ?>">
                  <span><b><?= htmlspecialchars($transaction['nickname']) ?></b> · <?= htmlspecialchars($transaction['description']) ?></span>
                  <strong class="<?= (int)$transaction['amount'] < 0 ? 'is-minus' : 'is-plus' ?>"><?= (int)$transaction['amount'] > 0 ? '+' : '' ?><?= number_format((int)$transaction['amount']) ?>P</strong>
                  <small><?= date('m.d H:i', strtotime($transaction['created_at'])) ?></small>
                </a>
              <?php endforeach; ?>
              <?php if (!$recentPointTransactions): ?><p class="admin-empty">포인트 변동 기록이 없습니다.</p><?php endif; ?>
            </div>
          </div>
          <div>
            <h3>AI 글쓰기 이용</h3>
            <div class="admin-list">
              <?php foreach ($recentAiLogs as $aiLog): ?>
                <a class="admin-list__item" href="blog.php?id=<?= (int)$aiLog['user_id'] ?>">
                  <span><b><?= htmlspecialchars($aiLog['nickname']) ?></b> · <?= htmlspecialchars($aiLog['assist_mode']) ?></span>
                  <strong><?= (int)$aiLog['used_api'] === 1 ? 'OpenAI' : '로컬 도우미' ?></strong>
                  <small><?= date('m.d H:i', strtotime($aiLog['created_at'])) ?></small>
                </a>
              <?php endforeach; ?>
              <?php if (!$recentAiLogs): ?><p class="admin-empty">AI 이용 기록이 없습니다.</p><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel admin-panel--wide" id="site-settings">
      <div class="admin-panel__head">
        <h2>사이트 구조 설정</h2>
        <span>공지 · 메인 문구 · 가입 정책</span>
      </div>
      <?php if (!$hasSiteSettings): ?>
        <p class="admin-empty">사이트 설정을 변경하려면 database/add_professor_features.sql 을 먼저 실행해주세요.</p>
      <?php else: ?>
        <form class="admin-site-form" method="post" action="admin.php">
          <input type="hidden" name="admin_action" value="site_settings">
          <label>
            <span>사이트 공지</span>
            <textarea name="site_notice" rows="3" maxlength="500"><?= htmlspecialchars($siteSettings['site_notice']) ?></textarea>
          </label>
          <div>
            <label>
              <span>메인 소개 제목</span>
              <input type="text" name="main_feature_title" maxlength="100" value="<?= htmlspecialchars($siteSettings['main_feature_title']) ?>">
            </label>
            <label>
              <span>메인 소개 문구</span>
              <input type="text" name="main_feature_text" maxlength="255" value="<?= htmlspecialchars($siteSettings['main_feature_text']) ?>">
            </label>
          </div>
          <label class="admin-check admin-check--setting">
            <input type="checkbox" name="allow_public_join" value="1" <?= $siteSettings['allow_public_join'] === '1' ? 'checked' : '' ?>>
            <span>
              <strong>신규 회원가입 허용</strong>
              <small>체크하면 로그인하지 않은 방문자가 회원가입 화면을 이용할 수 있습니다.</small>
            </span>
          </label>
          <button type="submit" class="btn-primary">사이트 설정 저장</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-panel admin-panel--wide" id="users">
      <div class="admin-panel__head">
        <h2>회원 관리</h2>
        <span>표시 <?= count($recentUsers) ?>명</span>
      </div>
      <form class="admin-search" method="get" action="admin.php#users">
        <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
        <label>
          <span>회원 검색</span>
          <input type="text" name="user_q" value="<?= htmlspecialchars($userSearch) ?>" placeholder="닉네임, 이름, 이메일, 블로그">
        </label>
        <label>
          <span>상태</span>
          <select name="user_state">
            <option value="all" <?= $userState === 'all' ? 'selected' : '' ?>>전체</option>
            <option value="admin" <?= $userState === 'admin' ? 'selected' : '' ?>>관리자</option>
            <option value="banned" <?= $userState === 'banned' ? 'selected' : '' ?>>밴 회원</option>
            <option value="normal" <?= $userState === 'normal' ? 'selected' : '' ?>>정상 회원</option>
          </select>
        </label>
        <button type="submit">검색</button>
        <?php if ($userSearch !== '' || $userState !== 'all'): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#users">초기화</a><?php endif; ?>
      </form>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>회원번호</th>
              <th>닉네임</th>
              <th>이름</th>
              <th>이메일</th>
              <th>블로그</th>
              <th>가입일</th>
              <th>포인트</th>
              <th>상태</th>
              <th>권한</th>
              <th>밴</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
              <tr data-user-row="<?= (int)$u['id'] ?>">
                <td><?= (int)$u['id'] ?></td>
                <td><a href="blog.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nickname']) ?></a></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['blog_title'] ?: $u['nickname'] . '의 블로그') ?></td>
                <td><?= date('Y.m.d H:i', strtotime($u['created_at'])) ?></td>
                <td>
                  <strong class="admin-point-balance"><?= number_format((int)$u['point_balance']) ?>P</strong>
                  <?php if ($hasPoints): ?>
                    <form class="admin-point-form" method="post" action="admin.php#users">
                      <input type="hidden" name="admin_action" value="point_adjust">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="number" name="amount" min="-10000" max="10000" step="1" placeholder="±P" required aria-label="지급 또는 차감 포인트">
                      <input type="text" name="reason" maxlength="120" placeholder="조정 사유" required aria-label="포인트 조정 사유">
                      <button type="submit">조정</button>
                    </form>
                  <?php endif; ?>
                </td>
                <td data-user-status>
                  <?php if ((int)$u['is_banned'] === 1): ?>
                    <span class="admin-badge admin-badge--danger">밴</span>
                    <?php if (!empty($u['banned_reason'])): ?><small class="admin-help"><?= htmlspecialchars($u['banned_reason']) ?></small><?php endif; ?>
                  <?php else: ?>
                    <span class="admin-badge">정상</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form class="admin-inline-form" method="post" action="admin.php">
                    <input type="hidden" name="admin_action" value="user_role">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <label><input type="checkbox" name="is_admin" value="1" <?= (int)$u['is_admin'] === 1 ? 'checked' : '' ?>> 관리자</label>
                    <button type="submit">저장</button>
                  </form>
                </td>
                <td>
                  <?php if ($hasBanColumn): ?>
                    <form class="admin-inline-form admin-inline-form--ban" method="post" action="admin.php" data-ajax-action="user_ban" data-confirm="<?= (int)$u['is_banned'] === 1 ? '이 회원의 밴을 해제할까요?' : '이 회원을 강제 밴할까요?' ?>">
                      <input type="hidden" name="admin_action" value="user_ban">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="ban_mode" value="<?= (int)$u['is_banned'] === 1 ? 'unban' : 'ban' ?>">
                      <?php if ((int)$u['is_banned'] !== 1): ?>
                        <input type="text" name="reason" maxlength="255" placeholder="사유">
                      <?php endif; ?>
                      <button type="submit" class="<?= (int)$u['is_banned'] === 1 ? '' : 'is-danger' ?>"><?= (int)$u['is_banned'] === 1 ? '해제' : '밴' ?></button>
                    </form>
                  <?php else: ?>
                    <span class="admin-help">migration 필요</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentUsers): ?>
              <tr><td colspan="10" class="admin-empty">회원이 없습니다.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel admin-panel--wide" id="posts">
      <div class="admin-panel__head">
        <h2>게시글 관리</h2>
        <span>표시 <?= count($recentPosts) ?>개</span>
      </div>
      <form class="admin-search" method="get" action="admin.php#posts">
        <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
        <label>
          <span>글 검색</span>
          <input type="text" name="post_q" value="<?= htmlspecialchars($postSearch) ?>" placeholder="제목, 작성자, 본문">
        </label>
        <label>
          <span>발행 상태</span>
          <select name="post_status">
            <option value="all" <?= $postStatusFilter === 'all' ? 'selected' : '' ?>>전체</option>
            <option value="published" <?= $postStatusFilter === 'published' ? 'selected' : '' ?>>발행</option>
            <option value="draft" <?= $postStatusFilter === 'draft' ? 'selected' : '' ?>>임시</option>
          </select>
        </label>
        <label>
          <span>공개 범위</span>
          <select name="post_visibility">
            <option value="any" <?= $postVisibilityFilter === 'any' ? 'selected' : '' ?>>전체</option>
            <option value="all" <?= $postVisibilityFilter === 'all' ? 'selected' : '' ?>>전체공개</option>
            <option value="neighbor" <?= $postVisibilityFilter === 'neighbor' ? 'selected' : '' ?>>이웃공개</option>
            <option value="private" <?= $postVisibilityFilter === 'private' ? 'selected' : '' ?>>비공개</option>
          </select>
        </label>
        <button type="submit">검색</button>
        <?php if ($postSearch !== '' || $postStatusFilter !== 'all' || $postVisibilityFilter !== 'any'): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#posts">초기화</a><?php endif; ?>
      </form>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>글번호</th>
              <th>제목</th>
              <th>작성자</th>
              <th>상태</th>
              <th>공개</th>
              <th>반응</th>
              <th>작성일</th>
              <th>권한</th>
              <th>강제 삭제</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentPosts as $p): ?>
              <tr data-post-row="<?= (int)$p['id'] ?>">
                <td><?= (int)$p['id'] ?></td>
                <td><a href="view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a></td>
                <td><a href="blog.php?id=<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['nickname']) ?></a></td>
                <td><span class="admin-badge"><?= htmlspecialchars($statusLabels[$p['status']] ?? $p['status']) ?></span></td>
                <td><?= htmlspecialchars($visibilityLabels[$p['visibility']] ?? $p['visibility']) ?></td>
                <td>
                  <div class="admin-reactions">
                    <span>조회 <strong><?= (int)$p['view_count'] ?></strong></span>
                    <span>공감 <strong><?= (int)$p['like_count'] ?></strong></span>
                    <span>댓글 좋아요 <strong><?= (int)$p['comment_like_count'] ?></strong></span>
                    <span>댓글 <strong><?= (int)$p['comment_count'] ?></strong></span>
                  </div>
                </td>
                <td><?= date('Y.m.d H:i', strtotime($p['created_at'])) ?></td>
                <td>
                  <form class="admin-inline-form admin-inline-form--post" method="post" action="admin.php">
                    <input type="hidden" name="admin_action" value="post_policy">
                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                    <select name="status">
                      <option value="published" <?= $p['status'] === 'published' ? 'selected' : '' ?>>발행</option>
                      <option value="draft" <?= $p['status'] === 'draft' ? 'selected' : '' ?>>임시</option>
                    </select>
                    <select name="visibility">
                      <option value="all" <?= $p['visibility'] === 'all' ? 'selected' : '' ?>>전체</option>
                      <option value="neighbor" <?= $p['visibility'] === 'neighbor' ? 'selected' : '' ?>>이웃</option>
                      <option value="private" <?= $p['visibility'] === 'private' ? 'selected' : '' ?>>비공개</option>
                    </select>
                    <label><input type="checkbox" name="is_pinned" value="1" <?= (int)$p['is_pinned'] === 1 ? 'checked' : '' ?>> 공지</label>
                    <button type="submit">저장</button>
                  </form>
                </td>
                <td>
                  <form class="admin-inline-form admin-inline-form--delete" method="post" action="admin.php" data-ajax-action="post_delete" data-confirm="이 글을 강제 삭제할까요? 첨부파일, 댓글, 공감도 함께 삭제됩니다.">
                    <input type="hidden" name="admin_action" value="post_delete">
                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                    <input type="text" name="reason" maxlength="255" placeholder="사유">
                    <label><input type="checkbox" name="confirm_delete" value="1" required> 확인</label>
                    <button type="submit" class="is-danger">삭제</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentPosts): ?>
              <tr><td colspan="9" class="admin-empty">게시글이 없습니다.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel" id="comments">
      <div class="admin-panel__head">
        <h2>최근 댓글</h2>
        <a class="admin-section-link" href="comments_manage.php?scope=all"><?= count($recentComments) ?>개 · 전체 관리</a>
      </div>
      <form class="admin-mini-search" method="get" action="admin.php#comments">
        <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
        <input type="search" name="comment_q" value="<?= htmlspecialchars($commentSearch) ?>" placeholder="댓글 내용, 작성자, 게시글 제목">
        <button type="submit">댓글 검색</button>
        <?php if ($commentSearch !== ''): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#comments">초기화</a><?php endif; ?>
      </form>
      <div class="admin-list">
        <?php foreach ($recentComments as $c): ?>
          <div class="admin-list__item">
            <a href="view.php?id=<?= (int)$c['post_id'] ?>#comment-<?= (int)$c['id'] ?>">
              <strong><?= htmlspecialchars($c['writer_nickname']) ?></strong>
              <span><?= htmlspecialchars(mb_strimwidth($c['content'], 0, 70, '...')) ?></span>
              <em><?= htmlspecialchars($c['post_title']) ?> · 좋아요 <?= (int)$c['comment_like_count'] ?> · <?= date('m.d H:i', strtotime($c['created_at'])) ?></em>
            </a>
            <form method="post" action="admin.php" data-confirm="이 댓글을 삭제할까요? 답글이 있으면 함께 삭제됩니다.">
              <input type="hidden" name="admin_action" value="comment_delete">
              <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
              <input type="text" name="reason" maxlength="255" placeholder="삭제 사유">
              <button type="submit" class="is-danger">댓글 삭제</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!$recentComments): ?><p class="admin-empty">댓글이 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel admin-panel--wide" id="reports">
      <div class="admin-panel__head">
        <h2>신고 관리</h2>
        <span><?= $hasReports ? '표시 ' . count($recentReports) . '개' : 'migration 필요' ?></span>
      </div>
      <?php if (!$hasReports): ?>
        <p class="admin-empty">신고 기능을 사용하려면 database/add_professor_features.sql 을 먼저 실행해주세요.</p>
      <?php else: ?>
        <form class="admin-search" method="get" action="admin.php#reports">
          <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
          <label>
            <span>신고 검색</span>
            <input type="search" name="report_q" value="<?= htmlspecialchars($reportSearch) ?>" placeholder="신고자, 사유, 메모, 대상 번호">
          </label>
          <label>
            <span>신고 상태</span>
            <select name="report_status">
              <option value="all" <?= $reportStatusFilter === 'all' ? 'selected' : '' ?>>전체</option>
              <option value="pending" <?= $reportStatusFilter === 'pending' ? 'selected' : '' ?>>대기</option>
              <option value="reviewed" <?= $reportStatusFilter === 'reviewed' ? 'selected' : '' ?>>확인</option>
              <option value="resolved" <?= $reportStatusFilter === 'resolved' ? 'selected' : '' ?>>조치 완료</option>
            </select>
          </label>
          <label>
            <span>신고 대상</span>
            <select name="report_type">
              <option value="all" <?= $reportTypeFilter === 'all' ? 'selected' : '' ?>>전체</option>
              <option value="post" <?= $reportTypeFilter === 'post' ? 'selected' : '' ?>>글</option>
              <option value="comment" <?= $reportTypeFilter === 'comment' ? 'selected' : '' ?>>댓글</option>
              <option value="guestbook" <?= $reportTypeFilter === 'guestbook' ? 'selected' : '' ?>>방명록</option>
              <option value="message" <?= $reportTypeFilter === 'message' ? 'selected' : '' ?>>쪽지</option>
            </select>
          </label>
          <button type="submit">필터</button>
          <?php if ($reportSearch !== '' || $reportStatusFilter !== 'all' || $reportTypeFilter !== 'all'): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#reports">초기화</a><?php endif; ?>
        </form>
        <?php if (!$recentReports): ?>
          <p class="admin-empty">조건에 맞는 신고가 없습니다.</p>
        <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>신고번호</th>
                <th>대상</th>
                <th>신고자</th>
                <th>사유</th>
                <th>상태</th>
                <th>접수일</th>
                <th>처리</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentReports as $report): ?>
                <?php
                  $targetUrl = adminReportTargetUrl($report);
                  $targetLabel = ($reportTargetLabels[$report['target_type']] ?? $report['target_type']) . ' #' . (int)$report['target_id'];
                  $targetPreview = mb_strimwidth(adminReportTargetPreview($report), 0, 80, '...');
                ?>
                <tr>
                  <td><?= (int)$report['id'] ?></td>
                  <td>
                    <?php if ($targetUrl !== ''): ?>
                      <a class="admin-target" href="<?= htmlspecialchars($targetUrl) ?>">
                        <strong><?= htmlspecialchars($targetLabel) ?></strong>
                        <?php if ($targetPreview !== ''): ?><span><?= htmlspecialchars($targetPreview) ?></span><?php endif; ?>
                      </a>
                    <?php else: ?>
                      <span class="admin-target">
                        <strong><?= htmlspecialchars($targetLabel) ?></strong>
                        <?php if ($targetPreview !== ''): ?><span><?= htmlspecialchars($targetPreview) ?></span><?php endif; ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($report['reporter_nickname']) ?></td>
                  <td><?= htmlspecialchars($report['reason']) ?></td>
                  <td><span class="admin-badge"><?= htmlspecialchars($reportStatusLabels[$report['status']] ?? $report['status']) ?></span></td>
                  <td><?= date('Y.m.d H:i', strtotime($report['created_at'])) ?></td>
                  <td>
                    <form class="admin-inline-form admin-inline-form--post" method="post" action="admin.php">
                      <input type="hidden" name="admin_action" value="report_status">
                      <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                      <select name="status">
                        <option value="pending" <?= $report['status'] === 'pending' ? 'selected' : '' ?>>대기</option>
                        <option value="reviewed" <?= $report['status'] === 'reviewed' ? 'selected' : '' ?>>확인</option>
                        <option value="resolved" <?= $report['status'] === 'resolved' ? 'selected' : '' ?>>조치 완료</option>
                      </select>
                      <input type="text" name="admin_note" maxlength="255" placeholder="처리 메모" value="<?= htmlspecialchars($report['admin_note'] ?? '') ?>">
                      <button type="submit">저장</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="admin-panel" id="hot-comments">
      <div class="admin-panel__head">
        <h2>댓글 많은 글</h2>
        <span>관리 우선순위</span>
      </div>
      <div class="admin-rank">
        <?php foreach ($hotCommentPosts as $i => $p): ?>
          <a href="view.php?id=<?= (int)$p['id'] ?>">
            <span><?= $i + 1 ?></span>
            <strong><?= htmlspecialchars($p['title']) ?></strong>
            <em><?= htmlspecialchars($p['nickname']) ?> · 댓글 <?= number_format((int)$p['comment_count']) ?> · 최근 <?= date('m.d H:i', strtotime($p['last_comment_at'])) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$hotCommentPosts): ?><p class="admin-empty">댓글이 달린 글이 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel" id="logs">
      <div class="admin-panel__head">
        <h2>최근 운영 로그</h2>
        <span><?= $hasModerationLogs ? count($recentLogs) . '개' : 'migration 필요' ?></span>
      </div>
      <?php if ($hasModerationLogs): ?>
        <form class="admin-mini-search" method="get" action="admin.php#logs">
          <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
          <input type="hidden" name="log_filter" value="<?= htmlspecialchars($logFilter) ?>">
          <input type="search" name="log_q" value="<?= htmlspecialchars($logSearch) ?>" placeholder="조치, 관리자, 사유, 대상 번호">
          <button type="submit">로그 검색</button>
          <?php if ($logSearch !== ''): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>&log_filter=<?= urlencode($logFilter) ?>#logs">초기화</a><?php endif; ?>
        </form>
        <div class="admin-filter">
          <a class="<?= $logFilter === 'all' ? 'is-active' : '' ?>" href="admin.php?<?= htmlspecialchars(adminQueryWith(['log_filter' => 'all', 'export' => null])) ?>#logs">전체</a>
          <a class="<?= $logFilter === 'user' ? 'is-active' : '' ?>" href="admin.php?<?= htmlspecialchars(adminQueryWith(['log_filter' => 'user', 'export' => null])) ?>#logs">회원</a>
          <a class="<?= $logFilter === 'post' ? 'is-active' : '' ?>" href="admin.php?<?= htmlspecialchars(adminQueryWith(['log_filter' => 'post', 'export' => null])) ?>#logs">글</a>
          <a class="<?= $logFilter === 'comment' ? 'is-active' : '' ?>" href="admin.php?<?= htmlspecialchars(adminQueryWith(['log_filter' => 'comment', 'export' => null])) ?>#logs">댓글</a>
          <a class="<?= $logFilter === 'report' ? 'is-active' : '' ?>" href="admin.php?<?= htmlspecialchars(adminQueryWith(['log_filter' => 'report', 'export' => null])) ?>#logs">신고</a>
        </div>
      <?php endif; ?>
      <?php if (!$hasModerationLogs): ?>
        <p class="admin-empty">운영 로그를 보려면 database/add_professor_features.sql 을 실행해주세요.</p>
      <?php elseif (!$recentLogs): ?>
        <p class="admin-empty">선택한 조건의 운영 조치 기록이 없습니다.</p>
      <?php else: ?>
        <div class="admin-list admin-list--logs">
          <?php foreach ($recentLogs as $log): ?>
            <?php
              $logTargetUrl = adminLogTargetUrl($log);
              $logTargetText = ($logTargetLabels[$log['target_type']] ?? $log['target_type']) . ' #' . (int)$log['target_id'];
            ?>
            <div class="admin-log">
              <div class="admin-log__top">
                <strong><?= htmlspecialchars($logActionLabels[$log['action']] ?? $log['action']) ?></strong>
                <?php if ($logTargetUrl !== ''): ?>
                  <a href="<?= htmlspecialchars($logTargetUrl) ?>"><?= htmlspecialchars($logTargetText) ?></a>
                <?php else: ?>
                  <span><?= htmlspecialchars($logTargetText) ?></span>
                <?php endif; ?>
              </div>
              <span><?= htmlspecialchars($log['admin_nickname']) ?> · <?= date('Y.m.d H:i', strtotime($log['created_at'])) ?></span>
              <?php if (!empty($log['reason'])): ?><em>메모: <?= htmlspecialchars($log['reason']) ?></em><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel" id="blogs">
      <div class="admin-panel__head">
        <h2>최근 방명록</h2>
        <span><?= count($recentGuestbook) ?>개</span>
      </div>
      <form class="admin-mini-search" method="get" action="admin.php#blogs">
        <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
        <input type="search" name="guestbook_q" value="<?= htmlspecialchars($guestbookSearch) ?>" placeholder="방명록 내용, 작성자, 블로그 주인">
        <button type="submit">방명록 검색</button>
        <?php if ($guestbookSearch !== ''): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#blogs">초기화</a><?php endif; ?>
      </form>
      <div class="admin-list">
        <?php foreach ($recentGuestbook as $g): ?>
          <a href="guestbook.php?id=<?= (int)$g['owner_id'] ?>">
            <strong><?= htmlspecialchars($g['writer_nickname']) ?> → <?= htmlspecialchars($g['owner_nickname']) ?></strong>
            <span><?= htmlspecialchars(mb_strimwidth($g['content'], 0, 70, '...')) ?></span>
            <em><?= date('m.d H:i', strtotime($g['created_at'])) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentGuestbook): ?><p class="admin-empty">방명록 글이 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel" id="tags">
      <div class="admin-panel__head">
        <h2>인기 태그</h2>
        <span>TOP <?= count($topTags) ?></span>
      </div>
      <form class="admin-mini-search" method="get" action="admin.php#tags">
        <input type="hidden" name="period" value="<?= htmlspecialchars($summaryPeriod) ?>">
        <input type="search" name="tag_q" value="<?= htmlspecialchars($tagSearch) ?>" placeholder="태그 이름 검색">
        <button type="submit">태그 검색</button>
        <?php if ($tagSearch !== ''): ?><a href="admin.php?period=<?= urlencode($summaryPeriod) ?>#tags">초기화</a><?php endif; ?>
      </form>
      <div class="admin-chips">
        <?php foreach ($topTags as $t): ?>
          <a href="index.php?tag=<?= (int)$t['id'] ?>">#<?= htmlspecialchars($t['name']) ?> <span><?= (int)$t['post_count'] ?></span></a>
        <?php endforeach; ?>
        <?php if (!$topTags): ?><p class="admin-empty">태그가 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel" id="top-blogs">
      <div class="admin-panel__head">
        <h2>방문 많은 블로그</h2>
        <span>TOP <?= count($topBlogs) ?></span>
      </div>
      <div class="admin-rank">
        <?php foreach ($topBlogs as $i => $b): ?>
          <a href="blog.php?id=<?= (int)$b['id'] ?>">
            <span><?= $i + 1 ?></span>
            <strong><?= htmlspecialchars($b['blog_title'] ?: $b['nickname'] . '의 블로그') ?></strong>
            <em>방문 <?= number_format((int)$b['visit_count']) ?> · 글 <?= number_format((int)$b['post_count']) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$topBlogs): ?><p class="admin-empty">블로그가 없습니다.</p><?php endif; ?>
      </div>
    </section>
  </div>
    </div>
  </div>
</section>

<script>
(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-ajax-action="user_ban"], form[data-ajax-action="post_delete"]');
    if (!form) return;
    event.preventDefault();

    var message = form.getAttribute('data-confirm') || '처리할까요?';
    var ask = window.confirmAction ? window.confirmAction(message) : Promise.resolve(true);
    ask.then(function (ok) {
      if (!ok) return;

      var button = form.querySelector('button[type="submit"]');
      var originalText = button ? button.textContent : '';
      if (button) {
        button.disabled = true;
        button.textContent = '처리중';
      }

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
      })
        .then(function (res) {
          return res.json().then(function (json) {
            if (!res.ok || !json.ok) throw new Error(json.message || '처리하지 못했어요.');
            return json;
          });
        })
        .then(function (json) {
          if (form.dataset.ajaxAction === 'post_delete') {
            var postRow = document.querySelector('[data-post-row="' + json.post_id + '"]');
            if (postRow) {
              postRow.style.opacity = '0.35';
              setTimeout(function () {
                postRow.remove();
              }, 180);
            }
            document.querySelectorAll('[data-admin-post-count]').forEach(function (el) {
              el.textContent = Number(json.post_count).toLocaleString();
            });
            document.querySelectorAll('[data-admin-published-count]').forEach(function (el) {
              el.textContent = Number(json.published_count).toLocaleString();
            });
            document.querySelectorAll('[data-admin-draft-count]').forEach(function (el) {
              el.textContent = Number(json.draft_count).toLocaleString();
            });
            window.showToast && window.showToast(json.message, false);
            return;
          }

          var row = document.querySelector('[data-user-row="' + json.user_id + '"]');
          var status = row && row.querySelector('[data-user-status]');
          var mode = form.querySelector('input[name="ban_mode"]');
          var reason = form.querySelector('input[name="reason"]');
          var count = document.querySelector('[data-admin-banned-count]');

          if (status) {
            if (Number(json.is_banned) === 1) {
              status.innerHTML = '<span class="admin-badge admin-badge--danger">밴</span>' +
                (json.reason ? '<small class="admin-help"></small>' : '');
              var help = status.querySelector('.admin-help');
              if (help) help.textContent = json.reason;
            } else {
              status.innerHTML = '<span class="admin-badge">정상</span>';
            }
          }

          if (mode) mode.value = Number(json.is_banned) === 1 ? 'unban' : 'ban';
          form.setAttribute('data-confirm', Number(json.is_banned) === 1 ? '이 회원의 밴을 해제할까요?' : '이 회원을 강제 밴할까요?');
          if (button) {
            button.textContent = Number(json.is_banned) === 1 ? '해제' : '밴';
            button.classList.toggle('is-danger', Number(json.is_banned) !== 1);
          }
          if (Number(json.is_banned) === 1 && reason) {
            reason.remove();
          } else if (Number(json.is_banned) === 0 && !form.querySelector('input[name="reason"]')) {
            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'reason';
            input.maxLength = 255;
            input.placeholder = '사유';
            form.insertBefore(input, button);
          }
          if (count) count.textContent = Number(json.banned_count).toLocaleString();
          window.showToast && window.showToast(json.message, false);
        })
        .catch(function (err) {
          window.showToast ? window.showToast(err.message, true) : alert(err.message);
          if (button) button.textContent = originalText;
        })
        .finally(function () {
          if (button) button.disabled = false;
        });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
