<?php
/**
 * view.php — 글 상세.
 *   조회수+1 · 태그 · 공감(likes 토글) · 댓글(목록/작성/본인삭제) ·
 *   이전/다음 글 · 공개설정 권한 체크 · 본인글이면 수정/삭제 진입.
 */

session_start();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/activity.php';
require_once __DIR__ . '/../app/comment_likes.php';

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
            if (mb_strlen($content) > 500) {
                header('Location: view.php?id=' . $postId);
                exit;
            }
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
            if (mb_strlen($content) > 500) {
                header('Location: view.php?id=' . $postId);
                exit;
            }
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

    elseif ($action === 'comment_like') {
        ensureCommentLikesTable($conn);
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ?");
        $stmt->bind_param("ii", $commentId, $postId);
        $stmt->execute();
        $targetComment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($targetComment) {
            $stmt = $conn->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $commentId, $viewerId);
            $stmt->execute();
            $liked = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($liked) {
                $stmt = $conn->prepare("DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            } else {
                $stmt = $conn->prepare("INSERT IGNORE INTO comment_likes (comment_id, user_id) VALUES (?, ?)");
            }
            $stmt->bind_param("ii", $commentId, $viewerId);
            $stmt->execute();
            $stmt->close();
        }
    }

    header('Location: view.php?id=' . $postId);
    exit;
}

// 볼 수 있는 글이면 조회수 +1 (단, 본인 글 제외 + 같은 세션에선 1회만 — 새로고침 중복 방지)
if ($canView) {
    ensureCommentLikesTable($conn);

    if (!isset($_SESSION['viewed'])) $_SESSION['viewed'] = [];
    if (!$isOwner && empty($_SESSION['viewed'][$postId])) {
        $stmt = $conn->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['viewed'][$postId] = true;
        $post['view_count'] += 1;   // 화면에 즉시 반영
    }

    if ($isLogin) {
        ensurePostViewsTable($conn);
        $stmt = $conn->prepare(
            "INSERT INTO post_views (user_id, post_id, viewed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE viewed_at = NOW()"
        );
        $stmt->bind_param("ii", $viewerId, $postId);
        $stmt->execute();
        $stmt->close();
    }
}

// 상세 데이터 (태그 / 공감 / 댓글 / 이전·다음) — 볼 수 있을 때만 조회
$tags = []; $images = []; $likeCount = 0; $likedByMe = false; $scrapped = false;
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

    // 본문 이미지 (id 포함 — 본문 [[img:id]] 토큰 치환용)
    $stmt = $conn->prepare("SELECT id, stored FROM post_images WHERE post_id = ? ORDER BY sort_order, id");
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
        "SELECT cm.id, cm.parent_id, cm.content, cm.created_at, cm.user_id, u.nickname,
                (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = cm.id) AS like_count,
                (SELECT COUNT(*) FROM comment_likes cl2 WHERE cl2.comment_id = cm.id AND cl2.user_id = ?) AS liked_by_me
         FROM comments cm JOIN users u ON u.id = cm.user_id
         WHERE cm.post_id = ? ORDER BY cm.created_at ASC"
    );
    $stmt->bind_param("ii", $viewerId, $postId);
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

/**
 * 본문 렌더: [[img:id]] 토큰을 그 자리에서 <img> 로 치환, 나머지 텍스트는 escape + nl2br.
 *   $images: [['id'=>, 'stored'=>], ...]  →  [본문HTML, 사용된 이미지 id 맵]
 */
function renderContent(string $content, array $images): array {
    $map = [];
    foreach ($images as $im) $map[(int)$im['id']] = $im['stored'];
    $used  = [];
    $parts = preg_split('/(\[\[img:\d+(?:\|\d+)?\]\])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html  = '';
    foreach ($parts as $part) {
        if (preg_match('/^\[\[img:(\d+)(?:\|(\d+))?\]\]$/', $part, $m)) {
            $id = (int)$m[1];
            if (isset($map[$id])) {
                $used[$id] = true;
                $style = isset($m[2]) ? ' style="width:' . (int)$m[2] . '%"' : '';   // 지정 너비(없으면 CSS 기본 50%)
                $html .= '<img class="post__inline-img"' . $style . ' src="../uploads/' . htmlspecialchars($map[$id]) . '" alt="">';
            }
            // 알 수 없는 토큰은 그냥 버림
        } else {
            $html .= nl2br(htmlspecialchars($part));
        }
    }
    return [$html, $used];
}

$pageTitle = ($post && $canView ? $post['title'] : '글') . ' · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<?php if (!$post): ?>
  <p class="empty">글을 찾을 수 없어요.</p>
<?php elseif (!$canView): ?>
  <p class="empty">비공개 글이거나 볼 수 있는 권한이 없어요.</p>
<?php else: ?>

  <?php if (($_GET['from'] ?? '') === 'notifications'): ?>
    <a class="back-link" href="notifications.php">← 소식으로 돌아가기</a>
  <?php endif; ?>

  <article class="post post--nothumb">
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

    <?php [$contentHtml, $usedImg] = renderContent($post['content'], $images); ?>
    <div class="post__content"><?= $contentHtml ?></div>

    <?php
      // 본문에 토큰으로 넣지 않은(남은) 이미지는 아래에 갤러리로 보여줌(예전 글·미삽입분 대비)
      $restImg = array_filter($images, fn($im) => empty($usedImg[(int)$im['id']]));
    ?>
    <?php if ($restImg): ?>
      <div class="post__gallery">
        <?php foreach ($restImg as $im): ?>
          <img src="../uploads/<?= htmlspecialchars($im['stored']) ?>" alt="">
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
        <form method="post" action="view.php?id=<?= (int)$post['id'] ?>" data-ajax-action="like">
          <input type="hidden" name="action" value="like">
          <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
          <button type="submit" class="like-btn <?= $likedByMe ? 'on' : '' ?>" data-like-btn>♥ 공감 <?= $likeCount ?></button>
        </form>
        <form method="post" action="view.php?id=<?= (int)$post['id'] ?>" data-ajax-action="scrap">
          <input type="hidden" name="action" value="scrap">
          <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
          <button type="submit" class="like-btn <?= $scrapped ? 'on' : '' ?>" data-scrap-btn><?= $scrapped ? '★ 스크랩됨' : '☆ 스크랩' ?></button>
        </form>
      <?php else: ?>
        <a class="like-btn" href="auth.php">♥ 공감 <?= $likeCount ?></a>
        <a class="like-btn" href="auth.php">☆ 스크랩</a>
      <?php endif; ?>
      <button type="button" class="like-btn" id="copyLink">🔗 링크 복사</button>
    </div>
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
      <div class="comment <?= $isReply ? 'comment--reply' : '' ?>" data-comment-id="<?= (int)$cm['id'] ?>" data-parent-id="<?= (int)($cm['parent_id'] ?? 0) ?>">
        <div class="comment__head">
          <span class="comment__name"><?= htmlspecialchars($cm['nickname']) ?>님</span>
          <span class="comment__date"><?= date('Y.m.d H:i', strtotime($cm['created_at'])) ?></span>
        </div>
        <p class="comment__body" data-comment-body><?= nl2br(htmlspecialchars($cm['content'])) ?></p>
        <?php if ($mine): ?>
          <form method="post" action="view.php?id=<?= (int)$postId ?>" class="comment__inline-edit" data-ajax-action="comment_edit" data-edit-form hidden>
            <input type="hidden" name="action" value="comment_edit">
            <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <textarea name="content" rows="2" maxlength="500" required data-comment-textarea><?= htmlspecialchars($cm['content']) ?></textarea>
            <small class="comment-counter" data-comment-counter>0 / 500</small>
            <div class="comment__edit-actions">
              <button type="button" class="btn-ghost-dark" data-edit-cancel>취소</button>
              <button type="submit" class="btn-primary">저장</button>
            </div>
          </form>
        <?php endif; ?>
        <?php if ($isLogin || $mine): ?>
          <div class="comment__actions">
            <?php if ($isLogin): ?>
              <form method="post" action="view.php?id=<?= (int)$postId ?>" class="comment__like" data-ajax-action="comment_like">
                <input type="hidden" name="action" value="comment_like">
                <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
                <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
                <button type="submit" class="comment__like-btn <?= !empty($cm['liked_by_me']) ? 'on' : '' ?>" data-comment-like-btn>
                  ♥ <?= (int)($cm['like_count'] ?? 0) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($canReply): ?>
              <button type="button" class="comment__reply-btn" data-reply-toggle>답글</button>
            <?php endif; ?>
            <?php if ($mine): ?>
              <button type="button" class="comment__edit-btn" data-edit-toggle>수정</button>
              <form method="post" action="view.php?id=<?= (int)$postId ?>" class="comment__del" data-ajax-action="comment_delete" data-confirm="댓글을 삭제할까요?">
                <input type="hidden" name="action" value="comment_delete">
                <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
                <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
                <button type="submit">삭제</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($canReply): ?>
          <form method="post" action="view.php?id=<?= (int)$postId ?>" class="comment__inline-reply" data-ajax-action="comment" data-reply-form hidden>
            <input type="hidden" name="action" value="comment">
            <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
            <input type="hidden" name="parent_id" value="<?= (int)$cm['id'] ?>">
            <textarea name="content" rows="2" maxlength="500" placeholder="답글을 남겨보세요" required data-comment-textarea></textarea>
            <small class="comment-counter" data-comment-counter>0 / 500</small>
            <div class="comment__edit-actions">
              <button type="button" class="btn-ghost-dark" data-reply-cancel>취소</button>
              <button type="submit" class="btn-primary">등록</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
      <?php
  }
  ?>
  <section class="comments">
    <h2 data-comment-title>댓글 <?= count($comments) ?></h2>
    <p class="ajax-status" data-ajax-status role="status" aria-live="polite"></p>

    <?php foreach ($parents as $cm): ?>
      <?php renderComment($cm, $post['id'], $viewerId, $isLogin, false); ?>
      <?php if (!empty($children[$cm['id']])): ?>
        <div class="comment-replies" data-replies-for="<?= (int)$cm['id'] ?>">
          <?php foreach ($children[$cm['id']] as $rep): ?>
            <?php renderComment($rep, $post['id'], $viewerId, $isLogin, true); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($isLogin): ?>
      <form class="comment-form" method="post" action="view.php?id=<?= (int)$post['id'] ?>" data-ajax-action="comment">
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
        <textarea name="content" rows="3" maxlength="500" placeholder="댓글을 남겨보세요" required data-comment-textarea></textarea>
        <small class="comment-counter" data-comment-counter>0 / 500</small>
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
    document.querySelectorAll('.post__content img, .post__gallery img').forEach(function (el) {
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

    var postId = <?= (int)$post['id'] ?>;
    var isLogin = <?= $isLogin ? 'true' : 'false' ?>;
    var apiUrl = '../api/api.php';
    var commentTitle = document.querySelector('[data-comment-title]');
    var ajaxStatus = document.querySelector('[data-ajax-status]');
    var messages = {
      empty_content: '내용을 입력해 주세요.',
      content_too_long: '댓글은 500자까지 입력할 수 있어요.',
      forbidden: '처리할 권한이 없어요.',
      invalid_parent: '답글을 달 수 없는 댓글입니다.',
      invalid_post: '글 정보를 확인할 수 없어요.',
      login_required: '로그인이 필요합니다.',
      not_found: '대상을 찾을 수 없어요.',
      unknown_action: '알 수 없는 요청입니다.'
    };

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function nl2br(value) {
      return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function formatDate(value) {
      var date = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return value;
      var pad = function (n) { return String(n).padStart(2, '0'); };
      return date.getFullYear() + '.' + pad(date.getMonth() + 1) + '.' + pad(date.getDate()) +
        ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function setBusy(form, busy) {
      form.querySelectorAll('button, textarea').forEach(function (el) { el.disabled = busy; });
      form.classList.toggle('is-loading', busy);
    }

    function messageText(key) {
      return messages[key] || key || '요청 처리에 실패했습니다.';
    }

    function showStatus(text, isError) {
      if (!ajaxStatus) return;
      ajaxStatus.textContent = text || '';
      ajaxStatus.classList.toggle('is-error', Boolean(isError));
      if (text && !isError) {
        setTimeout(function () {
          if (ajaxStatus.textContent === text) ajaxStatus.textContent = '';
        }, 1800);
      }
    }

    function updateCommentCounter(textarea) {
      if (!textarea) return;
      var form = textarea.closest('form');
      var counter = form && form.querySelector('[data-comment-counter]');
      if (!counter) return;
      var length = Array.from(textarea.value).length;
      counter.textContent = length + ' / 500';
      counter.classList.toggle('is-warning', length >= 450);
    }

    function initCommentCounters(root) {
      (root || document).querySelectorAll('[data-comment-textarea]').forEach(updateCommentCounter);
    }

    document.addEventListener('input', function (event) {
      if (event.target.matches('[data-comment-textarea]')) {
        updateCommentCounter(event.target);
      }
    });
    initCommentCounters(document);

    function readJson(res) {
      return res.text().then(function (text) {
        var json = null;
        try {
          json = text ? JSON.parse(text) : null;
        } catch (e) {
          throw new Error('서버 응답을 읽을 수 없어요.');
        }

        if (res.status === 401) {
          location.href = 'auth.php';
          throw new Error('로그인이 필요합니다.');
        }

        if (!res.ok || !json || !json.ok) {
          throw new Error(messageText(json && json.message));
        }

        return json;
      });
    }

    function updateCommentCount(count) {
      if (commentTitle) commentTitle.textContent = '댓글 ' + count;
    }

    function closeEditForm(comment) {
      var form = comment && comment.querySelector('[data-edit-form]');
      var body = comment && comment.querySelector('[data-comment-body]');
      var btn = comment && comment.querySelector('[data-edit-toggle]');
      if (!form || !body || !btn) return;
      form.hidden = true;
      body.hidden = false;
      btn.textContent = '수정';
      btn.classList.remove('on');
    }

    function openEditForm(comment) {
      var form = comment && comment.querySelector('[data-edit-form]');
      var body = comment && comment.querySelector('[data-comment-body]');
      var btn = comment && comment.querySelector('[data-edit-toggle]');
      if (!form || !body || !btn) return;

      document.querySelectorAll('[data-edit-form]:not([hidden])').forEach(function (otherForm) {
        closeEditForm(otherForm.closest('[data-comment-id]'));
      });

      closeReplyForm(comment);
      body.hidden = true;
      form.hidden = false;
      btn.textContent = '수정중';
      btn.classList.add('on');
      var textarea = form.querySelector('textarea');
      if (textarea) {
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
      }
    }

    function closeReplyForm(comment) {
      var form = comment && comment.querySelector('[data-reply-form]');
      var btn = comment && comment.querySelector('[data-reply-toggle]');
      if (!form || !btn) return;
      form.hidden = true;
      form.reset();
      btn.textContent = '답글';
      btn.classList.remove('on');
    }

    function openReplyForm(comment) {
      var form = comment && comment.querySelector('[data-reply-form]');
      var btn = comment && comment.querySelector('[data-reply-toggle]');
      if (!form || !btn) return;

      document.querySelectorAll('[data-reply-form]:not([hidden])').forEach(function (otherForm) {
        closeReplyForm(otherForm.closest('[data-comment-id]'));
      });

      closeEditForm(comment);
      form.hidden = false;
      btn.textContent = '답글중';
      btn.classList.add('on');
      var textarea = form.querySelector('textarea');
      if (textarea) setTimeout(function () { textarea.focus(); }, 0);
    }

    document.addEventListener('click', function (event) {
      var editBtn = event.target.closest('[data-edit-toggle]');
      if (editBtn) {
        var comment = editBtn.closest('[data-comment-id]');
        var form = comment && comment.querySelector('[data-edit-form]');
        if (form && !form.hidden) closeEditForm(comment);
        else openEditForm(comment);
        return;
      }

      var replyBtn = event.target.closest('[data-reply-toggle]');
      if (replyBtn) {
        var replyComment = replyBtn.closest('[data-comment-id]');
        var replyForm = replyComment && replyComment.querySelector('[data-reply-form]');
        if (replyForm && !replyForm.hidden) closeReplyForm(replyComment);
        else openReplyForm(replyComment);
        return;
      }

      var cancelBtn = event.target.closest('[data-edit-cancel]');
      if (cancelBtn) {
        closeEditForm(cancelBtn.closest('[data-comment-id]'));
        return;
      }

      var replyCancelBtn = event.target.closest('[data-reply-cancel]');
      if (replyCancelBtn) {
        closeReplyForm(replyCancelBtn.closest('[data-comment-id]'));
        return;
      }

      if (!event.target.closest('.comment')) {
        document.querySelectorAll('[data-reply-form]:not([hidden])').forEach(function (form) {
          closeReplyForm(form.closest('[data-comment-id]'));
        });
      }
    });

    function commentHtml(comment, isReply) {
      var id = Number(comment.id);
      var parentId = Number(comment.parent_id || 0);
      var replyClass = isReply ? ' comment--reply' : '';
      var html = ''
        + '<div class="comment' + replyClass + '" data-comment-id="' + id + '" data-parent-id="' + parentId + '">'
        + '<div class="comment__head">'
        + '<span class="comment__name">' + escapeHtml(comment.nickname) + '님</span>'
        + '<span class="comment__date">' + escapeHtml(formatDate(comment.created_at)) + '</span>'
        + '</div>'
        + '<p class="comment__body" data-comment-body>' + nl2br(comment.content) + '</p>'
        + '<form method="post" action="view.php?id=' + postId + '" class="comment__inline-edit" data-ajax-action="comment_edit" data-edit-form hidden>'
        + '<input type="hidden" name="action" value="comment_edit">'
        + '<input type="hidden" name="post_id" value="' + postId + '">'
        + '<input type="hidden" name="comment_id" value="' + id + '">'
        + '<textarea name="content" rows="2" maxlength="500" required data-comment-textarea>' + escapeHtml(comment.content) + '</textarea>'
        + '<small class="comment-counter" data-comment-counter>0 / 500</small>'
        + '<div class="comment__edit-actions">'
        + '<button type="button" class="btn-ghost-dark" data-edit-cancel>취소</button>'
        + '<button type="submit" class="btn-primary">저장</button>'
        + '</div>'
        + '</form>'
        + '<div class="comment__actions">';

      if (isLogin) {
        html += ''
          + '<form method="post" action="view.php?id=' + postId + '" class="comment__like" data-ajax-action="comment_like">'
          + '<input type="hidden" name="action" value="comment_like">'
          + '<input type="hidden" name="post_id" value="' + postId + '">'
          + '<input type="hidden" name="comment_id" value="' + id + '">'
          + '<button type="submit" class="comment__like-btn' + (comment.liked_by_me ? ' on' : '') + '" data-comment-like-btn>♥ ' + Number(comment.like_count || 0) + '</button>'
          + '</form>';
      }

      if (!isReply && isLogin) {
        html += ''
          + '<button type="button" class="comment__reply-btn" data-reply-toggle>답글</button>';
      }

      html += ''
        + '<button type="button" class="comment__edit-btn" data-edit-toggle>수정</button>'
        + '<form method="post" action="view.php?id=' + postId + '" class="comment__del" data-ajax-action="comment_delete" data-confirm="댓글을 삭제할까요?">'
        + '<input type="hidden" name="action" value="comment_delete">'
        + '<input type="hidden" name="post_id" value="' + postId + '">'
        + '<input type="hidden" name="comment_id" value="' + id + '">'
        + '<button type="submit">삭제</button>'
        + '</form>'
        + '</div>'
        + (!isReply && isLogin
          ? '<form method="post" action="view.php?id=' + postId + '" class="comment__inline-reply" data-ajax-action="comment" data-reply-form hidden>'
            + '<input type="hidden" name="action" value="comment">'
            + '<input type="hidden" name="post_id" value="' + postId + '">'
            + '<input type="hidden" name="parent_id" value="' + id + '">'
            + '<textarea name="content" rows="2" maxlength="500" placeholder="답글을 남겨보세요" required data-comment-textarea></textarea>'
            + '<small class="comment-counter" data-comment-counter>0 / 500</small>'
            + '<div class="comment__edit-actions">'
            + '<button type="button" class="btn-ghost-dark" data-reply-cancel>취소</button>'
            + '<button type="submit" class="btn-primary">등록</button>'
            + '</div>'
            + '</form>'
          : '')
        + '</div>';

      return html;
    }

    function addComment(comment) {
      var isReply = Boolean(Number(comment.parent_id || 0));
      var wrapper = document.createElement('div');
      wrapper.innerHTML = commentHtml(comment, isReply);
      var node = wrapper.firstElementChild;

      if (isReply) {
        var parent = document.querySelector('[data-comment-id="' + Number(comment.parent_id) + '"]');
        if (!parent) return;
        var replies = document.querySelector('[data-replies-for="' + Number(comment.parent_id) + '"]');
        if (!replies) {
          replies = document.createElement('div');
          replies.className = 'comment-replies';
          replies.dataset.repliesFor = String(comment.parent_id);
          parent.insertAdjacentElement('afterend', replies);
        }
        replies.appendChild(node);
        initCommentCounters(node);
        return;
      }

      var mainForm = document.querySelector('.comment-form');
      if (mainForm) mainForm.insertAdjacentElement('beforebegin', node);
      initCommentCounters(node);
    }

    function removeComment(deletedId, parentId) {
      var comment = document.querySelector('[data-comment-id="' + Number(deletedId) + '"]');
      if (!comment) return;

      if (!Number(parentId)) {
        var replies = document.querySelector('[data-replies-for="' + Number(deletedId) + '"]');
        if (replies) replies.remove();
      }

      var parentReplies = comment.closest('.comment-replies');
      comment.remove();
      if (parentReplies && !parentReplies.querySelector('.comment')) parentReplies.remove();
    }

    document.addEventListener('submit', function (event) {
      var form = event.target.closest('form[data-ajax-action]');
      if (!form) return;

      event.preventDefault();
      var confirmMessage = form.getAttribute('data-confirm');
      var proceed = confirmMessage && window.confirmAction
        ? window.confirmAction(confirmMessage)
        : Promise.resolve(!confirmMessage || confirm(confirmMessage));

      proceed.then(function (ok) {
        if (!ok) return;

        var data = new FormData(form);
        if (!data.has('post_id')) data.append('post_id', postId);
        setBusy(form, true);
        showStatus('', false);

        fetch(apiUrl, {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch' }
        })
          .then(readJson)
        .then(function (json) {
          if (form.dataset.ajaxAction === 'like') {
            var likeBtn = document.querySelector('[data-like-btn]');
            likeBtn.textContent = '♥ 공감 ' + json.like.count;
            likeBtn.classList.toggle('on', json.like.liked);
          } else if (form.dataset.ajaxAction === 'scrap') {
            var scrapBtn = document.querySelector('[data-scrap-btn]');
            scrapBtn.textContent = json.scrapped ? '★ 스크랩됨' : '☆ 스크랩';
            scrapBtn.classList.toggle('on', json.scrapped);
          } else if (form.dataset.ajaxAction === 'comment') {
            addComment(json.comment);
            updateCommentCount(json.comment_count);
            form.reset();
            var replyComment = form.closest('[data-comment-id]');
            if (replyComment) closeReplyForm(replyComment);
            showStatus('댓글이 등록됐어요.', false);
          } else if (form.dataset.ajaxAction === 'comment_edit') {
            var comment = form.closest('[data-comment-id]');
            if (comment) {
              comment.querySelector('[data-comment-body]').innerHTML = nl2br(json.comment.content);
              form.querySelector('textarea[name="content"]').value = json.comment.content;
              closeEditForm(comment);
            }
            showStatus('댓글을 수정했어요.', false);
          } else if (form.dataset.ajaxAction === 'comment_delete') {
            removeComment(json.deleted_id, json.parent_id);
            updateCommentCount(json.comment_count);
            showStatus('댓글을 삭제했어요.', false);
          } else if (form.dataset.ajaxAction === 'comment_like') {
            var likeButton = form.querySelector('[data-comment-like-btn]');
            if (likeButton) {
              likeButton.textContent = '♥ ' + json.comment_like.count;
              likeButton.classList.toggle('on', json.comment_like.liked);
            }
          }
        })
        .catch(function (err) {
          showStatus(err.message, true);
        })
        .finally(function () {
          setBusy(form, false);
        });
      });
    });
  })();
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

