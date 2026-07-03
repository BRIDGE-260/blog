<?php
/**
 * comments_manage.php - 내 글 댓글 관리함.
 *   블로거는 자기 글에 달린 댓글을 모아 보고 삭제할 수 있고,
 *   관리자는 전체 댓글을 확인하고 삭제할 수 있다.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];
$message = '';

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
$isAdmin = !empty($me['is_admin']);
$scope = ($_GET['scope'] ?? '') === 'all' && $isAdmin ? 'all' : 'mine';

function commentsManageLog(mysqli $conn, int $adminId, bool $isAdmin, int $commentId, string $reason = ''): void {
    if (!$isAdmin) return;
    $exists = $conn->query("SHOW TABLES LIKE 'moderation_logs'");
    if (!$exists || $exists->num_rows === 0) return;
    $targetType = 'comment';
    $action = 'delete_comment';
    $reason = mb_substr(trim($reason), 0, 255);
    $stmt = $conn->prepare(
        "INSERT INTO moderation_logs (admin_id, target_type, target_id, action, reason)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isiss", $adminId, $targetType, $commentId, $action, $reason);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($commentId > 0) {
            if ($isAdmin && $scope === 'all') {
                $stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->bind_param("i", $commentId);
            } else {
                $stmt = $conn->prepare(
                    "DELETE c FROM comments c
                     JOIN posts p ON p.id = c.post_id
                     WHERE c.id = ? AND p.user_id = ?"
                );
                $stmt->bind_param("ii", $commentId, $userId);
            }
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            if ($deleted) {
                commentsManageLog($conn, $userId, $isAdmin && $scope === 'all', $commentId, $reason);
                $message = '댓글을 삭제했어요.';
            } else {
                $message = '삭제할 수 있는 댓글을 찾지 못했어요.';
            }
        }
    }
}

$where = $scope === 'all' ? '1=1' : 'p.user_id = ?';
$types = $scope === 'all' ? '' : 'i';
$params = $scope === 'all' ? [] : [$userId];

$commentCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM comments c
     JOIN posts p ON p.id = c.post_id
     WHERE $where"
);
if ($types !== '') $commentCountStmt->bind_param($types, ...$params);
$commentCountStmt->execute();
$commentCount = (int)($commentCountStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$commentCountStmt->close();

$stmt = $conn->prepare(
    "SELECT c.id, c.parent_id, c.content, c.created_at,
            p.id AS post_id, p.title AS post_title,
            writer.id AS writer_id, writer.nickname AS writer_nickname,
            owner.id AS owner_id, owner.nickname AS owner_nickname
     FROM comments c
     JOIN posts p ON p.id = c.post_id
     JOIN users writer ON writer.id = c.user_id
     JOIN users owner ON owner.id = p.user_id
     WHERE $where
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 80"
);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '댓글 관리함 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="activity comments-manage">
  <div class="activity-hero">
    <div>
      <span>Comment Box</span>
      <h1>댓글 관리함</h1>
      <p>내 글에 달린 댓글을 한 화면에서 확인하고 필요한 댓글을 정리합니다.</p>
    </div>
    <nav>
      <a href="stats.php">블로그 현황</a>
      <a href="activity.php">내 활동</a>
      <?php if ($isAdmin): ?>
        <a href="comments_manage.php?scope=<?= $scope === 'all' ? 'mine' : 'all' ?>"><?= $scope === 'all' ? '내 글 댓글' : '전체 댓글' ?></a>
      <?php endif; ?>
    </nav>
  </div>

  <?php if ($message !== ''): ?>
    <div class="form-ok"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <section class="activity-panel">
    <div class="activity-panel__head">
      <h2><?= $scope === 'all' ? '전체 댓글' : '내 글 댓글' ?></h2>
      <span><?= number_format($commentCount) ?>개</span>
    </div>
    <?php if (!$comments): ?>
      <p class="activity-empty">관리할 댓글이 없습니다.</p>
    <?php else: ?>
      <div class="comment-manage-list">
        <?php foreach ($comments as $comment): ?>
          <article class="comment-manage-item">
            <div>
              <a href="view.php?id=<?= (int)$comment['post_id'] ?>">
                <strong><?= htmlspecialchars($comment['post_title']) ?></strong>
              </a>
              <span>
                <?= htmlspecialchars($comment['writer_nickname']) ?>님
                <?php if ($scope === 'all'): ?>
                  · <?= htmlspecialchars($comment['owner_nickname']) ?>님의 글
                <?php endif; ?>
                · <?= date('Y.m.d H:i', strtotime($comment['created_at'])) ?>
                <?= $comment['parent_id'] ? '· 답글' : '' ?>
              </span>
              <p><?= nl2br(htmlspecialchars($comment['content'])) ?></p>
            </div>
            <form method="post" action="comments_manage.php<?= $scope === 'all' ? '?scope=all' : '' ?>" data-confirm="이 댓글을 삭제할까요? 답글이 있으면 함께 삭제됩니다.">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="comment_id" value="<?= (int)$comment['id'] ?>">
              <?php if ($scope === 'all'): ?>
                <input type="text" name="reason" maxlength="255" placeholder="삭제 사유">
              <?php endif; ?>
              <button type="submit" class="is-danger">삭제</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
