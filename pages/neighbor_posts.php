<?php
/**
 * neighbor_posts.php — 이웃 새 글 전체.
 *   내가 이웃 추가한 사람들의 글(공개/이웃공개)을 최신순 + 페이징으로.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$viewerId = $_SESSION['user_id'];
$perPage  = 9;
$page     = max(1, (int)($_GET['page'] ?? 1));

// 개수 → 페이지 수
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM posts p
     JOIN neighbors n ON n.neighbor_id = p.user_id AND n.user_id = ?
     WHERE p.status = 'published' AND p.visibility IN ('all','neighbor')"
);
$stmt->bind_param("i", $viewerId);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// 글 목록 (공감·댓글 수 포함)
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.content,
            COALESCE((SELECT pi.stored FROM post_images pi WHERE pi.post_id = p.id AND pi.media_type = 'image' ORDER BY pi.sort_order, pi.id LIMIT 1), p.thumbnail_stored) AS thumbnail_stored,
            p.view_count, p.created_at,
            u.nickname, c.name AS category_name,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id = p.id) AS comment_count
     FROM posts p
     JOIN neighbors n ON n.neighbor_id = p.user_id AND n.user_id = ?
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 'published' AND p.visibility IN ('all','neighbor')
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param("iii", $viewerId, $perPage, $offset);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '이웃 새 글 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="feed-head">
  <h1>이웃 새 글</h1>
</section>

<?php if (!$posts): ?>
  <p class="empty">이웃의 새 글이 없어요. <a href="neighbors.php">이웃</a>을 추가해보세요.</p>
<?php else: ?>
  <div class="feed">
    <?php foreach ($posts as $p): ?>
      <a class="card" href="view.php?id=<?= (int)$p['id'] ?>">
        <div class="card__thumb">
          <?php if (!empty($p['thumbnail_stored'])): ?>
            <img src="../uploads/<?= htmlspecialchars($p['thumbnail_stored']) ?>" alt="">
          <?php else: ?>
            <span class="card__noimg">No Image</span>
          <?php endif; ?>
        </div>
        <div class="card__body">
          <?php if ($p['category_name']): ?>
            <span class="card__cat"><?= htmlspecialchars($p['category_name']) ?></span>
          <?php endif; ?>
          <h2 class="card__title"><?= htmlspecialchars($p['title']) ?></h2>
          <p class="card__excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 70, '…')) ?></p>
          <div class="card__meta">
            <span><?= htmlspecialchars($p['nickname']) ?>님 · <?= date('Y.m.d', strtotime($p['created_at'])) ?></span>
            <span>조회 <?= (int)$p['view_count'] ?> · ♥ <?= (int)$p['like_count'] ?> · 💬 <?= (int)$p['comment_count'] ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <nav class="pager">
    <?php if ($page > 1): ?>
      <a href="neighbor_posts.php?page=<?= $page - 1 ?>">‹ 이전</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="neighbor_posts.php?page=<?= $page + 1 ?>">다음 ›</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

