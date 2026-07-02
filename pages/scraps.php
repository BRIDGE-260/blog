<?php
/**
 * scraps.php — 내가 스크랩한 글 모아보기. (로그인 필요)
 *   liked.php 와 같은 구조 (likes → scraps).
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.content, p.view_count, p.created_at,
            COALESCE((SELECT pi.stored FROM post_images pi WHERE pi.post_id = p.id AND pi.media_type = 'image' ORDER BY pi.sort_order, pi.id LIMIT 1), p.thumbnail_stored) AS thumbnail_stored,
            u.nickname, c.name AS category_name,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id = p.id) AS comment_count
     FROM scraps s
     JOIN posts p ON p.id = s.post_id AND p.status = 'published' AND p.visibility = 'all'
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE s.user_id = ?
     ORDER BY s.created_at DESC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '스크랩한 글 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="feed-head">
  <h1>스크랩한 글</h1>
</section>

<?php if (!$posts): ?>
  <p class="empty">아직 스크랩한 글이 없어요.</p>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

