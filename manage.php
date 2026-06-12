<?php
/**
 * manage.php — 내 글 관리.
 *   내가 쓴 글 전체를 상태(전체/발행/임시저장)별로 보고 수정·삭제. 임시저장함 역할.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'published', 'draft'], true)) $status = 'all';

// 상태별 개수
$stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM posts WHERE user_id = ? GROUP BY status");
$stmt->bind_param("i", $userId);
$stmt->execute();
$cnt = ['published' => 0, 'draft' => 0];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $cnt[$r['status']] = (int)$r['c'];
$stmt->close();
$totalCnt = $cnt['published'] + $cnt['draft'];

// 목록 (상태 필터)
$where  = "p.user_id = ?";
$params = [$userId];
$types  = "i";
if ($status !== 'all') {
    $where   .= " AND p.status = ?";
    $params[] = $status;
    $types   .= "s";
}
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.status, p.visibility, p.view_count, p.created_at, c.name AS cat,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id = p.id) AS comment_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY p.created_at DESC"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$visLabel = ['all' => '전체공개', 'neighbor' => '이웃공개', 'private' => '비공개'];

$pageTitle = '내 글 관리 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="setting">
  <h1>내 글 관리</h1>

  <nav class="manage-tabs">
    <a class="<?= $status === 'all'       ? 'on' : '' ?>" href="manage.php">전체 <?= $totalCnt ?></a>
    <a class="<?= $status === 'published' ? 'on' : '' ?>" href="manage.php?status=published">발행 <?= $cnt['published'] ?></a>
    <a class="<?= $status === 'draft'     ? 'on' : '' ?>" href="manage.php?status=draft">임시저장 <?= $cnt['draft'] ?></a>
  </nav>

  <?php if (!$posts): ?>
    <p class="empty"><?= $status === 'draft' ? '임시저장한 글이 없어요.' : '아직 쓴 글이 없어요.' ?></p>
  <?php else: ?>
    <ul class="manage-list">
      <?php foreach ($posts as $p): ?>
        <li class="manage-item">
          <div class="manage-item__main">
            <div class="manage-item__title">
              <?php if ($p['status'] === 'draft'): ?>
                <span class="badge badge--draft">임시저장</span>
              <?php else: ?>
                <span class="badge badge--pub"><?= $visLabel[$p['visibility']] ?></span>
              <?php endif; ?>
              <a href="view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a>
            </div>
            <div class="manage-item__meta">
              <?= $p['cat'] ? htmlspecialchars($p['cat']) . ' · ' : '' ?><?= date('Y.m.d', strtotime($p['created_at'])) ?>
              · 조회 <?= (int)$p['view_count'] ?> · ♥ <?= (int)$p['like_count'] ?> · 💬 <?= (int)$p['comment_count'] ?>
            </div>
          </div>
          <div class="manage-item__act">
            <a href="modify.php?id=<?= (int)$p['id'] ?>">수정</a>
            <a href="delete.php?id=<?= (int)$p['id'] ?>">삭제</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
