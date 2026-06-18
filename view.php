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

    // 스크랩 토글: 이미 했으면 취소, 아니면 추가
    elseif ($action === 'scrap') {
        $stmt = $conn->prepare("SELECT id FROM scraps WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $has = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($has) {
            $stmt = $conn->prepare("DELETE FROM scraps WHERE post_id = ? AND user_id = ?");
        } else {
            $stmt = $conn->prepare("INSERT INTO scraps (post_id, user_id) VALUES (?, ?)");
        }
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $stmt->close();
    }

    // 댓글 작성 (parent_id 있으면 답글)
    elseif ($action === 'comment') {
        $content  = trim($_POST['content'] ?? '');
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if ($content !== '') {
            // 답글은 1단계만 — 부모가 이 글의 "최상위" 댓글일 때만 허용
            $parentParam = null;
            if ($parentId > 0) {
                $stmt = $conn->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ? AND parent_id IS NULL");
                $stmt->bind_param("ii", $parentId, $postId);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) $parentParam = $parentId;
                $stmt->close();
            }
            $stmt = $conn->prepare("INSERT INTO comments (post_id, parent_id, user_id, content) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiis", $postId, $parentParam, $viewerId, $content);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 댓글 수정 (본인 것만)
    elseif ($action === 'comment_edit') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $content   = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $stmt = $conn->prepare("UPDATE comments SET content = ? WHERE id = ? AND user_id = ?");
            $stmt->bind_param("sii", $content, $commentId, $viewerId);
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

// 볼 수 있는 글이면 조회수 +1 (단, 본인 글 제외 + 같은 세션에선 1회만 — 새로고침 중복 방지)
if ($canView) {
    if (!isset($_SESSION['viewed'])) $_SESSION['viewed'] = [];
    if (!$isOwner && empty($_SESSION['viewed'][$postId])) {
        $stmt = $conn->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['viewed'][$postId] = true;
        $post['view_count'] += 1;   // 화면에 즉시 반영
    }
}

// 상세 데이터 (태그 / 공감 / 댓글 / 이전·다음) — 볼 수 있을 때만 조회
$tags = []; $images = []; $likeCount = 0; $likedByMe = false; $likers = []; $scrapped = false;
$comments = []; $parents = []; $children = []; $prev = $next = null;
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

    // 공감 누른 사람 목록 (최신순)
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname FROM likes l JOIN users u ON u.id = l.user_id
         WHERE l.post_id = ? ORDER BY l.created_at DESC"
    );
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $likers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 내가 이 글을 스크랩했는지
    if ($isLogin) {
        $stmt = $conn->prepare("SELECT id FROM scraps WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $postId, $viewerId);
        $stmt->execute();
        $scrapped = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // 댓글 목록 (부모 댓글 + 답글) → parent_id 로 묶기
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.parent_id, cm.content, cm.created_at, cm.user_id, u.nickname
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.post_id = ? ORDER BY cm.created_at ASC"
    );
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($comments as $c) {
        if ($c['parent_id'] === null) $parents[] = $c;
        else $children[$c['parent_id']][] = $c;
    }

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

    <!-- 공감 / 스크랩 -->
    <div class="post__react">
      <?php if ($isLogin): ?>
        <form method="post" action="view.php?id=<?= (int)$post['id'] ?>">
          <input type="hidden" name="action" value="like">
          <button type="submit" class="like-btn <?= $likedByMe ? 'on' : '' ?>">♥ 공감 <?= $likeCount ?></button>
        </form>
        <form method="post" action="view.php?id=<?= (int)$post['id'] ?>">
          <input type="hidden" name="action" value="scrap">
          <button type="submit" class="like-btn <?= $scrapped ? 'on' : '' ?>"><?= $scrapped ? '★ 스크랩됨' : '☆ 스크랩' ?></button>
        </form>
      <?php else: ?>
        <a class="like-btn" href="auth.php">♥ 공감 <?= $likeCount ?></a>
        <a class="like-btn" href="auth.php">☆ 스크랩</a>
      <?php endif; ?>
      <button type="button" class="like-btn" id="copyLink">🔗 링크 복사</button>
    </div>

    <?php if ($likers): ?>
      <details class="likers">
        <summary>공감한 사람 <?= count($likers) ?>명</summary>
        <div class="likers__list">
          <?php foreach ($likers as $lk): ?>
            <a href="blog.php?id=<?= (int)$lk['id'] ?>"><?= htmlspecialchars($lk['nickname']) ?>님</a>
          <?php endforeach; ?>
        </div>
      </details>
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
  <?php
  /** 댓글/답글 한 개 출력. $allowReply=true 면 답글 폼 노출(최상위 댓글에만). */
  function renderComment($cm, $postId, $viewerId, $isLogin, $isReply = false) {
      $mine = $cm['user_id'] == $viewerId;
      $canReply = !$isReply && $isLogin;
      ?>
      <div class="comment <?= $isReply ? 'comment--reply' : '' ?>">
        <div class="comment__head">
          <span class="comment__name"><?= htmlspecialchars($cm['nickname']) ?>님</span>
          <span class="comment__date"><?= date('Y.m.d H:i', strtotime($cm['created_at'])) ?></span>
        </div>
        <p class="comment__body"><?= nl2br(htmlspecialchars($cm['content'])) ?></p>
        <?php if ($canReply || $mine): ?>
          <div class="comment__actions">
            <?php if ($canReply): ?>
              <details class="comment__reply">
                <summary>답글</summary>
                <form method="post" action="view.php?id=<?= (int)$postId ?>">
                  <input type="hidden" name="action" value="comment">
                  <input type="hidden" name="parent_id" value="<?= (int)$cm['id'] ?>">
                  <textarea name="content" rows="2" placeholder="답글을 남겨보세요" required></textarea>
                  <button type="submit" class="btn-primary">등록</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($mine): ?>
              <details class="comment__edit">
                <summary>수정</summary>
                <form method="post" action="view.php?id=<?= (int)$postId ?>">
                  <input type="hidden" name="action" value="comment_edit">
                  <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
                  <textarea name="content" rows="2" required><?= htmlspecialchars($cm['content']) ?></textarea>
                  <button type="submit" class="btn-primary">저장</button>
                </form>
              </details>
              <form method="post" action="view.php?id=<?= (int)$postId ?>" class="comment__del">
                <input type="hidden" name="action" value="comment_delete">
                <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
                <button type="submit">삭제</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php
  }
  ?>
  <section class="comments">
    <h2>댓글 <?= count($comments) ?></h2>

    <?php foreach ($parents as $cm): ?>
      <?php renderComment($cm, $post['id'], $viewerId, $isLogin, false); ?>
      <?php if (!empty($children[$cm['id']])): ?>
        <div class="comment-replies">
          <?php foreach ($children[$cm['id']] as $rep): ?>
            <?php renderComment($rep, $post['id'], $viewerId, $isLogin, true); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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

  <!-- 이미지 라이트박스(클릭 확대) -->
  <div id="lightbox" class="lightbox"><img src="" alt=""></div>
  <script>
  (function () {
    // 본문/썸네일 이미지 클릭 시 확대
    var lb = document.getElementById('lightbox');
    var lbImg = lb.querySelector('img');
    document.querySelectorAll('.post__gallery img, .post__thumb img').forEach(function (el) {
      el.style.cursor = 'zoom-in';
      el.addEventListener('click', function () { lbImg.src = el.src; lb.classList.add('on'); });
    });
    lb.addEventListener('click', function () { lb.classList.remove('on'); });

    // 글 링크 복사
    var copy = document.getElementById('copyLink');
    if (copy) copy.addEventListener('click', function () {
      navigator.clipboard.writeText(location.href).then(function () {
        copy.textContent = '✓ 복사됨';
        setTimeout(function () { copy.textContent = '🔗 링크 복사'; }, 1500);
      });
    });
  })();
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
