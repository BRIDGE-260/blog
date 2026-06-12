<?php
/**
 * view.php — 글 상세.
 *   조회수+1 · 태그 · 공감(likes 토글) · 댓글(목록/작성/본인삭제) ·
 *   이전/다음 글 · 공개설정 권한 체크 · 본인글이면 수정/삭제 진입.
 */

session_start();
require_once __DIR__ . '/db.php';

$isLogin  = isset($_SESSION['user_id']);
$viewerId = $_SESSION['user_id'] ?? 0;   // 게스트는 0 (작성/공감/댓글은 로그인 필요)
$postId   = (int)($_GET['id'] ?? 0);

// 글 + 작성자 닉네임 + 카테고리명 조회
$stmt = $conn->prepare(
    "SELECT p.*, u.nickname, c.name AS category_name
     FROM posts p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.id = ?"
);
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── 권한 판단 ──────────────────────────
$isOwner = $post && $post['user_id'] == $viewerId;

$isNeighbor = false;
if ($post && !$isOwner && $post['visibility'] === 'neighbor') {
    $stmt = $conn->prepare(
        "SELECT 1 FROM neighbors
         WHERE (user_id = ? AND neighbor_id = ?) OR (user_id = ? AND neighbor_id = ?) LIMIT 1"
    );
    $stmt->bind_param("iiii", $post['user_id'], $viewerId, $viewerId, $post['user_id']);
    $stmt->execute();
    $isNeighbor = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$canView = $post && (
    $isOwner
    || ($post['status'] === 'published' && $post['visibility'] === 'all')
    || ($post['status'] === 'published' && $post['visibility'] === 'neighbor' && $isNeighbor)
);

// ============================================================
// POST 처리 (공감/댓글) — 볼 수 있는 글에만, 처리 후 리다이렉트
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canView && $isLogin) {
    $action = $_POST['action'] ?? '';

    // 공감 토글: 이미 눌렀으면 취소, 아니면 추가
    if ($action === 'like') {
        $stmt = $conn->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $liked = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($liked) {
            $stmt = $conn->prepare("DELETE FROM likes WHERE post_id = ? AND user_id = ?");
        } else {
            $stmt = $conn->prepare("INSERT INTO likes (post_id, user_id) VALUES (?, ?)");
        }
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $stmt->close();
    }

    // 댓글 작성
    elseif ($action === 'comment') {
        $content = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $stmt = $conn->prepare("INSERT INTO comments (post_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $postId, $viewerId, $content);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 댓글 삭제 (본인 것만)
    elseif ($action === 'comment_delete') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $commentId, $viewerId);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: view.php?id=' . $postId);
    exit;
}

// 볼 수 있는 글이면 조회수 +1
if ($canView) {
    $stmt = $conn->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $stmt->close();
    $post['view_count'] += 1;   // 화면에 즉시 반영
}

// 상세 데이터 (태그 / 공감 / 댓글 / 이전·다음) — 볼 수 있을 때만 조회
$tags = []; $images = []; $likeCount = 0; $likedByMe = false; $comments = []; $prev = $next = null;
if ($canView) {
    // 태그 (id 포함 — 클릭 시 메인 태그 필터로 이동)
    $stmt = $conn->prepare(
        "SELECT t.id, t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ?"
    );
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $tags = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 본문 이미지
    $stmt = $conn->prepare("SELECT stored FROM post_images WHERE post_id = ? ORDER BY sort_order, id");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 공감 수 + 내가 눌렀는지
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM likes WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $likeCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $postId, $viewerId);
    $stmt->execute();
    $likedByMe = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 댓글 목록
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.content, cm.created_at, cm.user_id, u.nickname
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.post_id = ? ORDER BY cm.created_at ASC"
    );
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 같은 블로그(작성자)의 이전/다음 발행글
    $stmt = $conn->prepare(
        "SELECT id, title FROM posts
         WHERE user_id = ? AND status = 'published' AND created_at < ?
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param("is", $post['user_id'], $post['created_at']);
    $stmt->execute();
    $prev = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id, title FROM posts
         WHERE user_id = ? AND status = 'published' AND created_at > ?
         ORDER BY created_at ASC LIMIT 1"
    );
    $stmt->bind_param("is", $post['user_id'], $post['created_at']);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pageTitle = ($post && $canView ? $post['title'] : '글') . ' · MyBlog';
require_once __DIR__ . '/header.php';
?>

<?php if (!$post): ?>
  <p class="empty">글을 찾을 수 없어요.</p>
<?php elseif (!$canView): ?>
  <p class="empty">비공개 글이거나 볼 수 있는 권한이 없어요.</p>
<?php else: ?>

  <article class="post <?= empty($post['thumbnail_stored']) ? 'post--nothumb' : '' ?>">
    <?php if (!empty($post['thumbnail_stored'])): ?>
      <div class="post__thumb">
        <img src="uploads/<?= htmlspecialchars($post['thumbnail_stored']) ?>" alt="">
      </div>
    <?php endif; ?>

    <?php if ($post['category_name']): ?>
      <span class="post__cat"><?= htmlspecialchars($post['category_name']) ?></span>
    <?php endif; ?>
    <h1 class="post__title"><?= htmlspecialchars($post['title']) ?></h1>

    <div class="post__meta">
      <a href="blog.php?id=<?= (int)$post['user_id'] ?>"><?= htmlspecialchars($post['nickname']) ?>님</a>
      <span><?= date('Y.m.d H:i', strtotime($post['created_at'])) ?> · 조회 <?= (int)$post['view_count'] ?></span>
    </div>

    <?php if ($isOwner): ?>
      <div class="post__owner">
        <a href="modify.php?id=<?= (int)$post['id'] ?>">수정</a>
        <a href="delete.php?id=<?= (int)$post['id'] ?>">삭제</a>
      </div>
    <?php endif; ?>

    <div class="post__content"><?= nl2br(htmlspecialchars($post['content'])) ?></div>

    <?php if ($images): ?>
      <div class="post__gallery">
        <?php foreach ($images as $im): ?>
          <img src="uploads/<?= htmlspecialchars($im['stored']) ?>" alt="">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($tags): ?>
      <div class="post__tags">
        <?php foreach ($tags as $t): ?>
          <a class="tag" href="index.php?tag=<?= (int)$t['id'] ?>">#<?= htmlspecialchars($t['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- 공감 -->
    <?php if ($isLogin): ?>
      <form class="like" method="post" action="view.php?id=<?= (int)$post['id'] ?>">
        <input type="hidden" name="action" value="like">
        <button type="submit" class="like-btn <?= $likedByMe ? 'on' : '' ?>">♥ 공감 <?= $likeCount ?></button>
      </form>
    <?php else: ?>
      <div class="like"><a class="like-btn" href="auth.php">♥ 공감 <?= $likeCount ?></a></div>
    <?php endif; ?>
  </article>

  <!-- 이전/다음 글 -->
  <nav class="post-nav">
    <?php if ($prev): ?>
      <a href="view.php?id=<?= (int)$prev['id'] ?>"><span>‹ 이전 글</span><b><?= htmlspecialchars($prev['title']) ?></b></a>
    <?php else: ?>
      <div class="post-nav__empty"><span>‹ 이전 글</span><b>없음</b></div>
    <?php endif; ?>
    <?php if ($next): ?>
      <a class="r" href="view.php?id=<?= (int)$next['id'] ?>"><span>다음 글 ›</span><b><?= htmlspecialchars($next['title']) ?></b></a>
    <?php else: ?>
      <div class="post-nav__empty r"><span>다음 글 ›</span><b>없음</b></div>
    <?php endif; ?>
  </nav>

  <!-- 댓글 -->
  <section class="comments">
    <h2>댓글 <?= count($comments) ?></h2>

    <?php foreach ($comments as $cm): ?>
      <div class="comment">
        <div class="comment__head">
          <span class="comment__name"><?= htmlspecialchars($cm['nickname']) ?>님</span>
          <span class="comment__date"><?= date('Y.m.d H:i', strtotime($cm['created_at'])) ?></span>
        </div>
        <p class="comment__body"><?= nl2br(htmlspecialchars($cm['content'])) ?></p>
        <?php if ($cm['user_id'] == $viewerId): ?>
          <form method="post" action="view.php?id=<?= (int)$post['id'] ?>" class="comment__del">
            <input type="hidden" name="action" value="comment_delete">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <button type="submit">삭제</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($isLogin): ?>
      <form class="comment-form" method="post" action="view.php?id=<?= (int)$post['id'] ?>">
        <input type="hidden" name="action" value="comment">
        <textarea name="content" rows="3" placeholder="댓글을 남겨보세요" required></textarea>
        <button type="submit" class="btn-primary">등록</button>
      </form>
    <?php else: ?>
      <p class="comment-guest">댓글을 쓰려면 <a href="auth.php">로그인</a>하세요.</p>
    <?php endif; ?>
  </section>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
