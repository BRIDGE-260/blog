<?php
/**
 * guestbook.php — 방명록 (블로그 자체에 남기는 글).
 *   누구나 열람, 작성은 로그인 필요.
 *   삭제: 글쓴이 본인 또는 방명록 주인(블로그 주인)이 가능.
 */

session_start();
require_once __DIR__ . '/db.php';

$isLogin  = isset($_SESSION['user_id']);
$viewerId = $_SESSION['user_id'] ?? 0;
$ownerId  = (int)($_GET['id'] ?? $viewerId);

// 방명록 주인 정보
$stmt = $conn->prepare("SELECT id, nickname, blog_title FROM users WHERE id = ?");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$owner) {
    $pageTitle = '방명록 · MyBlog';
    require_once __DIR__ . '/header.php';
    echo '<p class="empty">블로그를 찾을 수 없어요.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$isOwner = $ownerId === (int)$viewerId;

// ── POST: 작성 / 삭제 ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLogin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'write') {
        $content = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $stmt = $conn->prepare("INSERT INTO guestbook (owner_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $ownerId, $viewerId, $content);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        // 글쓴이 본인 또는 방명록 주인만 삭제
        $gid = (int)($_POST['gid'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM guestbook WHERE id = ? AND (user_id = ? OR owner_id = ?)");
        $stmt->bind_param("iii", $gid, $viewerId, $viewerId);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: guestbook.php?id=' . $ownerId);
    exit;
}

// 목록
$stmt = $conn->prepare(
    "SELECT g.id, g.content, g.created_at, g.user_id, u.nickname
     FROM guestbook g JOIN users u ON u.id = g.user_id
     WHERE g.owner_id = ?
     ORDER BY g.created_at DESC"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$ownerName = $owner['blog_title'] ?: $owner['nickname'] . '님의 블로그';
$pageTitle = $owner['nickname'] . '님의 방명록 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="comments gb">
  <div class="gb__head">
    <h1><?= htmlspecialchars($owner['nickname']) ?>님의 방명록</h1>
    <a class="gb__back" href="blog.php?id=<?= (int)$ownerId ?>">← <?= htmlspecialchars($ownerName) ?></a>
  </div>

  <?php if ($isLogin): ?>
    <form class="comment-form" method="post" action="guestbook.php?id=<?= (int)$ownerId ?>">
      <input type="hidden" name="action" value="write">
      <textarea name="content" rows="3" placeholder="방명록을 남겨보세요" required></textarea>
      <button type="submit" class="btn-primary">등록</button>
    </form>
  <?php else: ?>
    <p class="comment-guest">방명록을 쓰려면 <a href="auth.php">로그인</a>하세요.</p>
  <?php endif; ?>

  <h2 class="gb__count">방명록 <?= count($entries) ?></h2>

  <?php if (!$entries): ?>
    <p class="empty">아직 방명록이 없어요.</p>
  <?php else: ?>
    <?php foreach ($entries as $g): ?>
      <div class="comment">
        <div class="comment__head">
          <a class="comment__name" href="blog.php?id=<?= (int)$g['user_id'] ?>"><?= htmlspecialchars($g['nickname']) ?>님</a>
          <span class="comment__date"><?= date('Y.m.d H:i', strtotime($g['created_at'])) ?></span>
        </div>
        <p class="comment__body"><?= nl2br(htmlspecialchars($g['content'])) ?></p>
        <?php if ($g['user_id'] == $viewerId || $isOwner): ?>
          <div class="comment__actions">
            <form method="post" action="guestbook.php?id=<?= (int)$ownerId ?>" class="comment__del">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
              <button type="submit">삭제</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
