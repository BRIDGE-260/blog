<?php
/**
 * bloggers.php — 블로그 찾기.
 *   다른 사용자 목록(공개글 수 순) + 블로그 가기 + 이웃 추가/취소.
 *   다른 사람 블로그로 들어가는 입구이자 이웃 추가 진입점.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$viewerId = $_SESSION['user_id'];

// 이웃 추가/취소 토글
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)($_POST['target'] ?? 0);
    $action   = $_POST['action'] ?? '';
    if ($targetId > 0 && $targetId !== (int)$viewerId) {
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT IGNORE INTO neighbors (user_id, neighbor_id) VALUES (?, ?)");
        } elseif ($action === 'cancel') {
            $stmt = $conn->prepare("DELETE FROM neighbors WHERE user_id = ? AND neighbor_id = ?");
        }
        if (isset($stmt)) {
            $stmt->bind_param("ii", $viewerId, $targetId);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: bloggers.php');
    exit;
}

// 나 빼고 전체 사용자 (공개글 수 + 내가 이웃했는지)
$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
            (SELECT COUNT(*) FROM posts p
             WHERE p.user_id = u.id AND p.status='published' AND p.visibility='all') AS post_count,
            EXISTS(SELECT 1 FROM neighbors n WHERE n.user_id = ? AND n.neighbor_id = u.id) AS is_neighbor
     FROM users u
     WHERE u.id <> ?
     ORDER BY post_count DESC, u.nickname"
);
$stmt->bind_param("ii", $viewerId, $viewerId);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '블로그 찾기 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="nbr-sec">
  <h1>블로그 찾기</h1>
  <?php if (!$users): ?>
    <p class="empty">아직 다른 사용자가 없어요.</p>
  <?php else: ?>
    <div class="nbr-list">
      <?php foreach ($users as $u):
          $img = !empty($u['profile_image_stored'])
              ? '<img src="uploads/' . htmlspecialchars($u['profile_image_stored']) . '" alt="">'
              : '<span>' . htmlspecialchars(mb_substr($u['nickname'], 0, 1)) . '</span>';
          $title = $u['blog_title'] ?: $u['nickname'] . '님의 블로그';
      ?>
        <div class="nbr">
          <a class="nbr__main" href="blog.php?id=<?= (int)$u['id'] ?>">
            <div class="nbr__img"><?= $img ?></div>
            <div>
              <div class="nbr__title"><?= htmlspecialchars($title) ?></div>
              <div class="nbr__nick"><?= htmlspecialchars($u['nickname']) ?> · 글 <?= (int)$u['post_count'] ?></div>
            </div>
          </a>
          <form method="post" action="bloggers.php">
            <input type="hidden" name="target" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="action" value="<?= $u['is_neighbor'] ? 'cancel' : 'add' ?>">
            <button type="submit" class="<?= $u['is_neighbor'] ? 'btn-ghost-dark' : 'btn-primary' ?>">
              <?= $u['is_neighbor'] ? '이웃 취소' : '이웃 추가' ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
