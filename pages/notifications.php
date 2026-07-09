<?php
/**
 * notifications.php — 내 소식.
 *   내 글에 달린 최근 댓글 + 공감 + 이웃 새 글 + 방명록을 하나의 타임라인으로 모아 보여준다.
 *   읽음 여부는 notification_reads 에 알림별 key 로 저장한다.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, ['', 'neighbor_post', 'comment_like'], true)) {
    $typeFilter = '';
}

function notiTableExists(mysqli $conn, string $table): bool {
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

$hasCommentLikes = notiTableExists($conn, 'comment_likes');
if (!$hasCommentLikes && $typeFilter === 'comment_like') {
    $typeFilter = '';
}

// ① 내 글에 달린 댓글
$stmt = $conn->prepare(
    "SELECT cm.id AS source_id, cm.content, cm.created_at, u.nickname, p.id AS post_id, p.title
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
        'key'     => 'comment:' . (int)$r['source_id'],
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'comment_id' => $r['source_id'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
        'content' => $r['content'],
    ];
}

// ② 내 글에 눌린 공감
$stmt = $conn->prepare(
    "SELECT l.id AS source_id, l.created_at, u.nickname, p.id AS post_id, p.title
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
        'key'     => 'like:' . (int)$r['source_id'],
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
    ];
}

// ③ 내가 추가한 이웃의 새 글
if ($hasCommentLikes) {
    $stmt = $conn->prepare(
        "SELECT cl.id AS source_id, cl.created_at, u.nickname,
                cm.id AS comment_id, cm.content, p.id AS post_id, p.title
         FROM comment_likes cl
         JOIN comments cm ON cm.id = cl.comment_id AND cm.user_id = ?
         JOIN posts p ON p.id = cm.post_id
         JOIN users u ON u.id = cl.user_id
         WHERE cl.user_id <> ?
         ORDER BY cl.created_at DESC LIMIT 30"
    );
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $r) {
        $feed[] = [
            'type'       => 'comment_like',
            'key'        => 'comment_like:' . (int)$r['source_id'],
            'when'       => $r['created_at'],
            'nick'       => $r['nickname'],
            'comment_id' => $r['comment_id'],
            'post_id'    => $r['post_id'],
            'title'      => $r['title'],
            'content'    => $r['content'],
        ];
    }
}

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
        'key'     => 'neighbor_post:' . (int)$r['post_id'],
        'when'    => $r['created_at'],
        'nick'    => $r['nickname'],
        'post_id' => $r['post_id'],
        'title'   => $r['title'],
    ];
}

// ④ 내 방명록에 남긴 글
$stmt = $conn->prepare(
    "SELECT g.id AS source_id, g.content, g.created_at, u.nickname, u.id AS writer_id
     FROM guestbook g
     JOIN users u ON u.id = g.user_id
     WHERE g.owner_id = ? AND g.user_id <> ?
     ORDER BY g.created_at DESC LIMIT 30"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
    $feed[] = [
        'type'      => 'guestbook',
        'key'       => 'guestbook:' . (int)$r['source_id'],
        'when'      => $r['created_at'],
        'nick'      => $r['nickname'],
        'writer_id' => $r['writer_id'],
        'content'   => $r['content'],
    ];
}

// 여러 종류를 시간순(최신)으로 합치기
usort($feed, fn($a, $b) => strtotime($b['when']) <=> strtotime($a['when']));
if ($typeFilter !== '') {
    $feed = array_values(array_filter($feed, fn($item) => $item['type'] === $typeFilter));
}
$feed = array_slice($feed, 0, 30);

$readMap = [];
if ($feed) {
    $keys = array_column($feed, 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $types = 'i' . str_repeat('s', count($keys));
    $params = array_merge([$userId], $keys);
    $stmt = $conn->prepare(
        "SELECT notification_key
         FROM notification_reads
         WHERE user_id = ? AND notification_key IN ($placeholders)"
    );
    $bind = [$types];
    foreach ($params as $i => $value) {
        $bind[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $readMap[$row['notification_key']] = true;
    }
    $stmt->close();
}

$pageTitle = '소식 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="noti">
  <h1>내 소식</h1>
  <nav class="noti-tabs">
    <a class="<?= $typeFilter === '' ? 'on' : '' ?>" href="notifications.php">전체 소식</a>
    <?php if ($hasCommentLikes): ?>
      <a class="<?= $typeFilter === 'comment_like' ? 'on' : '' ?>" href="notifications.php?type=comment_like">댓글 좋아요</a>
    <?php endif; ?>
    <a class="<?= $typeFilter === 'neighbor_post' ? 'on' : '' ?>" href="notifications.php?type=neighbor_post">이웃 새 글만</a>
    <a href="neighbor_posts.php">이웃 새 글 전체</a>
  </nav>

  <?php if (!$feed): ?>
    <p class="empty">아직 내 글의 반응이나 이웃 새 글이 없어요.</p>
  <?php else: ?>
    <ul class="noti-list">
      <?php foreach ($feed as $f): ?>
        <?php
          $isRead = isset($readMap[$f['key']]);
          if ($f['type'] === 'guestbook') {
            $href = 'guestbook.php?id=' . (int)$userId . '&from=notifications';
          } else {
            $href = 'view.php?id=' . (int)$f['post_id'] . '&from=notifications';
            if (!empty($f['comment_id'])) {
              $href .= '#comment-' . (int)$f['comment_id'];
            }
          }
        ?>
        <li class="noti-item <?= $isRead ? 'is-read' : 'is-unread' ?>">
          <span class="noti-item__icon">
            <?php if ($f['type'] === 'comment'): ?>💬<?php elseif ($f['type'] === 'like' || $f['type'] === 'comment_like'): ?>♥<?php elseif ($f['type'] === 'guestbook'): ?>✎<?php else: ?>＋<?php endif; ?>
          </span>
          <a class="noti-item__body" href="<?= htmlspecialchars($href) ?>" data-noti-key="<?= htmlspecialchars($f['key']) ?>">
            <span class="noti-item__line">
              <?php if ($f['type'] === 'neighbor_post'): ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이 새 글
                “<?= htmlspecialchars($f['title']) ?>”을 올렸어요
              <?php elseif ($f['type'] === 'guestbook'): ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이 내 방명록에 글을 남겼어요
              <?php elseif ($f['type'] === 'comment_like'): ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이
                “<?= htmlspecialchars($f['title']) ?>”의 내 댓글을 좋아했어요
              <?php else: ?>
                <b><?= htmlspecialchars($f['nick']) ?>님</b>이
                “<?= htmlspecialchars($f['title']) ?>”에
                <?= $f['type'] === 'comment' ? '댓글을 남겼어요' : '공감했어요' ?>
              <?php endif; ?>
            </span>
            <?php if ($f['type'] === 'comment' || $f['type'] === 'guestbook' || $f['type'] === 'comment_like'): ?>
              <span class="noti-item__sub"><?= htmlspecialchars(mb_strimwidth($f['content'], 0, 60, '…')) ?></span>
            <?php endif; ?>
            <span class="noti-item__date"><?= date('Y.m.d H:i', strtotime($f['when'])) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script>
(function () {
  document.addEventListener('click', function (e) {
    var link = e.target.closest('[data-noti-key]');
    if (!link) return;
    e.preventDefault();
    var target = link.href;
    var key = link.getAttribute('data-noti-key');
    var data = new FormData();
    data.append('key', key);
    fetch('../api/notification_read.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).finally(function () {
      location.href = target;
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

