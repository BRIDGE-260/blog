<?php
/**
 * notifications.php — 내 소식.
 *   내 글에 달린 최근 댓글 + 공감 + 이웃 새 글을 하나의 타임라인으로 모아 보여준다.
 *   (내가 내 글에 남긴 것은 제외)
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];

// ① 내 글에 달린 댓글
$stmt = $conn->prepare(
    "SELECT cm.content, cm.created_at, u.nickname, p.id AS post_id, p.title
     FROM comments cm
     JOIN posts p ON p.id = cm.post_id AND p.user_id = ?
     JOIN users u ON u.id = cm.user_id
     WHERE cm.user_id <> ?
     ORDER BY cm.created_at DESC LIMIT 30"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$feed = [];
foreach ($rows as $r) {
    $feed[] = [
        'type'    => 'comment',
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
        'content' => $r['content'],
    ];
}

// ② 내 글에 눌린 공감
$stmt = $conn->prepare(
    "SELECT l.created_at, u.nickname, p.id AS post_id, p.title
     FROM likes l
     JOIN posts p ON p.id = l.post_id AND p.user_id = ?
     JOIN users u ON u.id = l.user_id
     WHERE l.user_id <> ?
     ORDER BY l.created_at DESC LIMIT 30"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
    $feed[] = [
        'type'    => 'like',
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
    ];
}

// ③ 내가 추가한 이웃의 새 글
$stmt = $conn->prepare(
    "SELECT p.id AS post_id, p.title, p.created_at, u.nickname
     FROM posts p
     JOIN neighbors n ON n.neighbor_id = p.user_id AND n.user_id = ?
     JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published'
       AND p.visibility IN ('all', 'neighbor')
       AND p.user_id <> ?
     ORDER BY p.created_at DESC LIMIT 30"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
    $feed[] = [
        'type'    => 'neighbor_post',
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
    ];
}

// 두 종류를 시간순(최신)으로 합치기
usort($feed, fn($a, $b) => strtotime($b['when']) <=> strtotime($a['when']));
$feed = array_slice($feed, 0, 30);

$stmt = $conn->prepare("UPDATE users SET notifications_read_at = NOW() WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

$pageTitle = '소식 · MyBlog';
require_once __DIR__ . '/../app/header.php';
?>

<section class="noti">
  <h1>내 소식</h1>

  <?php if (!$feed): ?>
    <p class="empty">아직 내 글의 반응이나 이웃 새 글이 없어요.</p>
  <?php else: ?>
    <ul class="noti-list">
      <?php foreach ($feed as $f): ?>
        <li class="noti-item">
          <span class="noti-item__icon">
            <?php if ($f['type'] === 'comment'): ?>💬<?php elseif ($f['type'] === 'like'): ?>♥<?php else: ?>새글<?php endif; ?>
          </span>
          <a class="noti-item__body" href="view.php?id=<?= (int)$f['post_id'] ?>">
            <span class="noti-item__line">
              <?php if ($f['type'] === 'neighbor_post'): ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이 새 글
                “<?= htmlspecialchars($f['title']) ?>”을 올렸어요
              <?php else: ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이
                “<?= htmlspecialchars($f['title']) ?>”에
                <?= $f['type'] === 'comment' ? '댓글을 남겼어요' : '공감했어요' ?>
              <?php endif; ?>
            </span>
            <?php if ($f['type'] === 'comment'): ?>
              <span class="noti-item__sub"><?= htmlspecialchars(mb_strimwidth($f['content'], 0, 60, '…')) ?></span>
            <?php endif; ?>
            <span class="noti-item__date"><?= date('Y.m.d H:i', strtotime($f['when'])) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

