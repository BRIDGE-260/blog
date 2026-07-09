<?php
/**
 * activity.php - 내 활동 모아보기.
 *   14개 기본 테이블 안에서 댓글, 공감, 스크랩 기록을 모아 보여준다.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];

function activityTableExists(mysqli $conn, string $table): bool {
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

$hasCommentLikes = activityTableExists($conn, 'comment_likes');

$visibilitySql =
    "(p.user_id = ?
      OR (
        p.status = 'published'
        AND (
          p.visibility = 'all'
          OR (
            p.visibility = 'neighbor'
            AND EXISTS (
              SELECT 1 FROM neighbors n
              WHERE (n.user_id = ? AND n.neighbor_id = p.user_id)
                 OR (n.user_id = p.user_id AND n.neighbor_id = ?)
            )
          )
        )
      )
    )";

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count, p.created_at, u.nickname, c.name AS category_name,
            MAX(cm.created_at) AS last_commented_at,
            COUNT(cm.id) AS my_comment_count,
            (SELECT COUNT(*) FROM comments allc WHERE allc.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count
     FROM comments cm
     JOIN posts p ON p.id = cm.post_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE cm.user_id = ? AND {$visibilitySql}
     GROUP BY p.id, p.title, p.view_count, p.created_at, u.nickname, c.name
     ORDER BY last_commented_at DESC
     LIMIT 10"
);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$commentedPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count, p.created_at, u.nickname, c.name AS category_name,
            MAX(l.created_at) AS acted_at,
            (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM likes all_likes WHERE all_likes.post_id = p.id) AS like_count
     FROM likes l
     JOIN posts p ON p.id = l.post_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE l.user_id = ? AND {$visibilitySql}
     GROUP BY p.id, p.title, p.view_count, p.created_at, u.nickname, c.name
     ORDER BY acted_at DESC
     LIMIT 10"
);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$likedPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count, p.created_at, u.nickname, c.name AS category_name,
            MAX(s.created_at) AS acted_at,
            (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM likes all_likes WHERE all_likes.post_id = p.id) AS like_count
     FROM scraps s
     JOIN posts p ON p.id = s.post_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE s.user_id = ? AND {$visibilitySql}
     GROUP BY p.id, p.title, p.view_count, p.created_at, u.nickname, c.name
     ORDER BY acted_at DESC
     LIMIT 10"
);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$stmt->execute();
$scrappedPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$likedComments = [];
if ($hasCommentLikes) {
    $stmt = $conn->prepare(
        "SELECT cm.id AS comment_id, cm.content, cm.created_at AS comment_created_at,
                cl.created_at AS acted_at, cu.nickname AS comment_nickname,
                p.id AS post_id, p.title, p.view_count, u.nickname, c.name AS category_name,
                (SELECT COUNT(*) FROM comments allc WHERE allc.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM likes all_likes WHERE all_likes.post_id = p.id) AS like_count
         FROM comment_likes cl
         JOIN comments cm ON cm.id = cl.comment_id
         JOIN users cu ON cu.id = cm.user_id
         JOIN posts p ON p.id = cm.post_id
         JOIN users u ON u.id = p.user_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE cl.user_id = ? AND {$visibilitySql}
         ORDER BY cl.created_at DESC
         LIMIT 10"
    );
    $stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
    $stmt->execute();
    $likedComments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$pageTitle = '내 활동 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="activity">
  <div class="activity-hero">
    <div>
      <span>내 활동 모아보기</span>
      <h1>댓글, 공감, 스크랩을 한눈에 보기</h1>
      <p>내가 참여하고 다시 보고 싶어 표시한 글을 한 화면에 모았습니다.</p>
    </div>
    <nav>
      <a href="stats.php">블로그 현황</a>
      <a href="liked.php">좋아한 글</a>
      <a href="scraps.php">스크랩</a>
    </nav>
  </div>

  <div class="activity-grid <?= $hasCommentLikes ? 'activity-grid--four' : 'activity-grid--three' ?>">
    <section class="activity-panel">
      <div class="activity-panel__head">
        <h2>내가 댓글 단 글</h2>
        <span><?= count($commentedPosts) ?>개</span>
      </div>
      <?php if ($commentedPosts): ?>
        <div class="activity-list">
          <?php foreach ($commentedPosts as $post): ?>
            <a class="activity-item" href="view.php?id=<?= (int)$post['id'] ?>">
              <span class="activity-item__type"><?= htmlspecialchars($post['category_name'] ?? '분류 없음') ?></span>
              <strong><?= htmlspecialchars($post['title']) ?></strong>
              <em><?= htmlspecialchars($post['nickname']) ?>님 · 내 댓글 <?= (int)$post['my_comment_count'] ?>개 · <?= date('Y.m.d H:i', strtotime($post['last_commented_at'])) ?></em>
              <small>조회 <?= number_format($post['view_count']) ?> · 공감 <?= number_format($post['like_count']) ?> · 댓글 <?= number_format($post['comment_count']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="activity-empty">아직 댓글을 남긴 글이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="activity-panel">
      <div class="activity-panel__head">
        <h2>공감한 글</h2>
        <span><?= count($likedPosts) ?>개</span>
      </div>
      <?php if ($likedPosts): ?>
        <div class="activity-list">
          <?php foreach ($likedPosts as $post): ?>
            <a class="activity-item" href="view.php?id=<?= (int)$post['id'] ?>">
              <span class="activity-item__type"><?= htmlspecialchars($post['category_name'] ?? '분류 없음') ?></span>
              <strong><?= htmlspecialchars($post['title']) ?></strong>
              <em><?= htmlspecialchars($post['nickname']) ?>님 · <?= date('Y.m.d H:i', strtotime($post['acted_at'])) ?>에 공감</em>
              <small>조회 <?= number_format($post['view_count']) ?> · 공감 <?= number_format($post['like_count']) ?> · 댓글 <?= number_format($post['comment_count']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="activity-empty">아직 공감한 글이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="activity-panel">
      <div class="activity-panel__head">
        <h2>스크랩한 글</h2>
        <span><?= count($scrappedPosts) ?>개</span>
      </div>
      <?php if ($scrappedPosts): ?>
        <div class="activity-list">
          <?php foreach ($scrappedPosts as $post): ?>
            <a class="activity-item" href="view.php?id=<?= (int)$post['id'] ?>">
              <span class="activity-item__type"><?= htmlspecialchars($post['category_name'] ?? '분류 없음') ?></span>
              <strong><?= htmlspecialchars($post['title']) ?></strong>
              <em><?= htmlspecialchars($post['nickname']) ?>님 · <?= date('Y.m.d H:i', strtotime($post['acted_at'])) ?>에 스크랩</em>
              <small>조회 <?= number_format($post['view_count']) ?> · 공감 <?= number_format($post['like_count']) ?> · 댓글 <?= number_format($post['comment_count']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="activity-empty">아직 스크랩한 글이 없어요.</p>
      <?php endif; ?>
    </section>

    <?php if ($hasCommentLikes): ?>
      <section class="activity-panel">
        <div class="activity-panel__head">
          <h2>좋아요한 댓글</h2>
          <span><?= count($likedComments) ?>개</span>
        </div>
        <?php if ($likedComments): ?>
          <div class="activity-list">
            <?php foreach ($likedComments as $item): ?>
              <a class="activity-item" href="view.php?id=<?= (int)$item['post_id'] ?>#comment-<?= (int)$item['comment_id'] ?>">
                <span class="activity-item__type"><?= htmlspecialchars($item['category_name'] ?? '분류 없음') ?></span>
                <strong><?= htmlspecialchars($item['title']) ?></strong>
                <em><?= htmlspecialchars($item['comment_nickname']) ?>님의 댓글 · <?= date('Y.m.d H:i', strtotime($item['acted_at'])) ?>에 좋아요</em>
                <small><?= htmlspecialchars(mb_strimwidth($item['content'], 0, 58, '...')) ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="activity-empty">아직 좋아요한 댓글이 없어요.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
