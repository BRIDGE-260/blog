<?php
/**
 * blog.php — 특정 유저의 블로그 메인.
 *   사이드바(프로필·카테고리·방문자수) + 그 유저 글 목록 + 카테고리 필터 + 페이징.
 *   남의 블로그면 이웃 추가/취소(neighbors). 방문 시 visit_logs 카운트.
 */

session_start();
require_once __DIR__ . '/../app/db.php';

$isLogin  = isset($_SESSION['user_id']);
$viewerId = $_SESSION['user_id'] ?? 0;          // 게스트는 0
$ownerId  = (int)($_GET['id'] ?? $viewerId);    // id 없으면 내 블로그

// 블로그 주인 정보
$stmt = $conn->prepare(
    "SELECT id, nickname, blog_title, intro, profile_image_stored FROM users WHERE id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$owner) {
    $pageTitle = '블로그 · MyBlog';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">블로그를 찾을 수 없어요.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

$isOwner = $ownerId === (int)$viewerId;

$blogSettings = [
    'accent_color' => '#d4af7a',
    'background_color' => '#f5f6f8',
    'background_image_stored' => null,
    'background_repeat' => 'no-repeat',
    'background_position' => 'center',
    'background_size' => 'cover',
    'header_image_stored' => null,
    'header_height' => 220,
    'layout_type' => 'standard',
    'title_align' => 'left',
    'sidebar_position' => 'left',
    'profile_shape' => 'circle',
    'profile_card_color' => '#ffffff',
    'post_list_style' => 'card',
    'thumbnail_style' => 'wide',
    'font_style' => 'sans',
    'show_intro' => 1,
    'show_post_summary' => 1,
    'show_visit_count' => 1,
];

$stmt = $conn->prepare(
    "SELECT accent_color, background_color, background_image_stored, background_repeat,
            background_position, background_size, header_image_stored, header_height,
            layout_type, title_align, sidebar_position, profile_shape, profile_card_color,
            post_list_style, thumbnail_style, font_style, show_intro, show_post_summary,
            show_visit_count
     FROM blog_settings
     WHERE user_id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$settingsRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($settingsRow) {
    $blogSettings = array_merge($blogSettings, $settingsRow);
}

function blogColor($value, $fallback) {
    return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value) ? $value : $fallback;
}
function blogChoice($value, $allowed, $fallback) {
    return in_array($value, $allowed, true) ? $value : $fallback;
}

$blogSettings['accent_color'] = blogColor($blogSettings['accent_color'], '#d4af7a');
$blogSettings['background_color'] = blogColor($blogSettings['background_color'], '#f5f6f8');
$blogSettings['profile_card_color'] = blogColor($blogSettings['profile_card_color'], '#ffffff');
$blogSettings['background_repeat'] = blogChoice($blogSettings['background_repeat'], ['no-repeat', 'repeat'], 'no-repeat');
$blogSettings['background_position'] = blogChoice($blogSettings['background_position'], ['left', 'center', 'right'], 'center');
$blogSettings['background_size'] = blogChoice($blogSettings['background_size'], ['cover', 'contain', 'auto'], 'cover');
$blogSettings['layout_type'] = blogChoice($blogSettings['layout_type'], ['standard', 'wide', 'compact'], 'standard');
$blogSettings['title_align'] = blogChoice($blogSettings['title_align'], ['left', 'center'], 'left');
$blogSettings['sidebar_position'] = blogChoice($blogSettings['sidebar_position'], ['left', 'right'], 'left');
$blogSettings['profile_shape'] = blogChoice($blogSettings['profile_shape'], ['circle', 'rounded', 'square'], 'circle');
$blogSettings['post_list_style'] = blogChoice($blogSettings['post_list_style'], ['card', 'list'], 'card');
$blogSettings['thumbnail_style'] = blogChoice($blogSettings['thumbnail_style'], ['wide', 'square', 'hidden'], 'wide');
$blogSettings['font_style'] = blogChoice($blogSettings['font_style'], ['sans', 'serif', 'rounded'], 'sans');
$blogSettings['header_height'] = min(360, max(120, (int)$blogSettings['header_height']));

$blogStyle = '--blog-accent:' . $blogSettings['accent_color'] . ';'
    . '--blog-bg:' . $blogSettings['background_color'] . ';'
    . '--blog-profile-bg:' . $blogSettings['profile_card_color'] . ';'
    . '--blog-header-height:' . (int)$blogSettings['header_height'] . 'px;';
$blogClasses = [
    'blog-shell',
    'blog-shell--layout-' . $blogSettings['layout_type'],
    'blog-shell--sidebar-' . $blogSettings['sidebar_position'],
    'blog-shell--profile-' . $blogSettings['profile_shape'],
    'blog-shell--posts-' . $blogSettings['post_list_style'],
    'blog-shell--thumb-' . $blogSettings['thumbnail_style'],
    'blog-shell--font-' . $blogSettings['font_style'],
    'blog-shell--title-' . $blogSettings['title_align'],
];
$blogBgStyle = 'background-color:' . $blogSettings['background_color'] . ';';
if (!empty($blogSettings['background_image_stored'])) {
    $blogBgStyle .= "background-image:url('../uploads/" . htmlspecialchars($blogSettings['background_image_stored'], ENT_QUOTES) . "');"
        . 'background-repeat:' . $blogSettings['background_repeat'] . ';'
        . 'background-position:' . $blogSettings['background_position'] . ' top;'
        . 'background-size:' . $blogSettings['background_size'] . ';';
}

// 내가 이 블로그 주인을 이웃 추가했는지 (버튼 상태용)
$iAddedOwner = false;
if (!$isOwner) {
    $stmt = $conn->prepare("SELECT id FROM neighbors WHERE user_id = ? AND neighbor_id = ?");
    $stmt->bind_param("ii", $viewerId, $ownerId);
    $stmt->execute();
    $iAddedOwner = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ── POST: 이웃 추가/취소 토글 ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'neighbor' && !$isOwner && $isLogin) {
    if ($iAddedOwner) {
        $stmt = $conn->prepare("DELETE FROM neighbors WHERE user_id = ? AND neighbor_id = ?");
    } else {
        $stmt = $conn->prepare("INSERT INTO neighbors (user_id, neighbor_id) VALUES (?, ?)");
    }
    $stmt->bind_param("ii", $viewerId, $ownerId);
    $stmt->execute();
    $stmt->close();
    header('Location: blog.php?id=' . $ownerId);
    exit;
}

// 방문 카운트 (매번 카운트 방식, 본인 방문은 제외)
if (!$isOwner) {
    $stmt = $conn->prepare(
        "INSERT INTO visit_logs (user_id, visit_date, count) VALUES (?, CURDATE(), 1)
         ON DUPLICATE KEY UPDATE count = count + 1"
    );
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $stmt->close();
}

// 이웃 관계(양방향) — 이웃공개 글 노출 판단용
$isNeighborRel = false;
if (!$isOwner) {
    $stmt = $conn->prepare(
        "SELECT 1 FROM neighbors
         WHERE (user_id = ? AND neighbor_id = ?) OR (user_id = ? AND neighbor_id = ?) LIMIT 1"
    );
    $stmt->bind_param("iiii", $ownerId, $viewerId, $viewerId, $ownerId);
    $stmt->execute();
    $isNeighborRel = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 사이드바: 카테고리 목록
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY sort_order");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 사이드바: 방문자 수 (오늘 / 전체)
$stmt = $conn->prepare("SELECT count FROM visit_logs WHERE user_id = ? AND visit_date = CURDATE()");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$todayVisit = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(count), 0) AS total FROM visit_logs WHERE user_id = ?");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$totalVisit = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// 본인 블로그면 상태별 개수 (전체/발행/임시저장 탭용)
$statusCnt = ['all' => 0, 'published' => 0, 'draft' => 0];
if ($isOwner) {
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM posts WHERE user_id = ? GROUP BY status");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $statusCnt[$r['status']] = (int)$r['c'];
    $stmt->close();
    $statusCnt['all'] = $statusCnt['published'] + $statusCnt['draft'];
}

// ── 글 목록 조건 ───────────────────────
$cat     = (int)($_GET['cat'] ?? 0);
$perPage = 6;
$page    = max(1, (int)($_GET['page'] ?? 1));

// 본인일 때만 상태 필터 (방문자는 항상 발행글만)
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'published', 'draft'], true)) $status = 'all';

$where  = "p.user_id = ?";
$params = [$ownerId];
$types  = "i";

if ($isOwner) {
    // 본인: 임시저장 포함 전부 (상태 탭 선택 시 그 상태만)
    if ($status !== 'all') {
        $where   .= " AND p.status = ?";
        $params[] = $status;
        $types   .= "s";
    }
} else {
    // 방문자: 발행 + (전체공개 또는 이웃공개[이웃일 때])
    if ($isNeighborRel) {
        $where .= " AND p.status = 'published' AND p.visibility IN ('all','neighbor')";
    } else {
        $where .= " AND p.status = 'published' AND p.visibility = 'all'";
    }
}
if ($cat > 0) {
    $where   .= " AND p.category_id = ?";
    $params[] = $cat;
    $types   .= "i";
}

// 개수 → 페이지 수
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM posts p WHERE $where");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// 글 목록
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.content, p.view_count, p.created_at,
            COALESCE((SELECT pi.stored FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), p.thumbnail_stored) AS thumbnail_stored,
            p.status, p.visibility, p.is_pinned, c.name AS category_name,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id = p.id) AS comment_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY p.is_pinned DESC, p.created_at DESC
     LIMIT ? OFFSET ?"
);
$listParams = [...$params, $perPage, $offset];
$stmt->bind_param($types . 'ii', ...$listParams);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 페이징 URL (id·cat·status 유지)
function blogUrl($n, $ownerId, $cat, $status = 'all') {
    $qs = ['id' => $ownerId, 'page' => $n];
    if ($cat > 0)            $qs['cat']    = $cat;
    if ($status !== 'all')   $qs['status'] = $status;
    return 'blog.php?' . http_build_query($qs);
}

$pageTitle = ($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') . ' · MyBlog';
require_once __DIR__ . '/../app/header.php';
?>

<div class="<?= htmlspecialchars(implode(' ', $blogClasses)) ?>"
     style="<?= htmlspecialchars($blogStyle . $blogBgStyle, ENT_QUOTES) ?>">

  <section class="blog-cover">
    <?php if (!empty($blogSettings['header_image_stored'])): ?>
      <img src="../uploads/<?= htmlspecialchars($blogSettings['header_image_stored']) ?>" alt="">
    <?php endif; ?>
    <div class="blog-cover__text">
      <h1><?= htmlspecialchars($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') ?></h1>
      <?php if (!empty($owner['intro']) && (int)$blogSettings['show_intro'] === 1): ?>
        <p><?= nl2br(htmlspecialchars($owner['intro'])) ?></p>
      <?php endif; ?>
      <?php if ($isOwner): ?>
        <a href="blog_customize.php">블로그 꾸미기</a>
      <?php endif; ?>
    </div>
  </section>

<div class="blog-layout">

  <!-- 사이드바 -->
  <aside class="blog-side">
    <div class="profile">
      <div class="profile__img">
        <?php if (!empty($owner['profile_image_stored'])): ?>
          <img src="../uploads/<?= htmlspecialchars($owner['profile_image_stored']) ?>" alt="">
        <?php else: ?>
          <span><?= htmlspecialchars(mb_substr($owner['nickname'], 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="profile__title"><?= htmlspecialchars($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') ?></div>
      <div class="profile__nick"><?= htmlspecialchars($owner['nickname']) ?></div>
      <?php if (!empty($owner['intro']) && (int)$blogSettings['show_intro'] === 1): ?>
        <p class="profile__intro"><?= nl2br(htmlspecialchars($owner['intro'])) ?></p>
      <?php endif; ?>

      <?php if ($isLogin && !$isOwner): ?>
        <form method="post" action="blog.php?id=<?= $ownerId ?>">
          <input type="hidden" name="action" value="neighbor">
          <button type="submit" class="<?= $iAddedOwner ? 'btn-ghost-dark' : 'btn-primary' ?>">
            <?= $iAddedOwner ? '이웃 취소' : '이웃 추가' ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if ((int)$blogSettings['show_visit_count'] === 1): ?>
        <div class="profile__visit">
          오늘 <?= $todayVisit ?> · 전체 <?= $totalVisit ?>
          <?php if ($isOwner): ?><br><a href="stats.php">통계 보기</a> · <a href="liked.php">좋아요한 글</a> · <a href="scraps.php">스크랩</a><?php endif; ?>
        </div>
      <?php elseif ($isOwner): ?>
        <div class="profile__visit"><a href="stats.php">통계 보기</a> · <a href="liked.php">좋아요한 글</a> · <a href="scraps.php">스크랩</a></div>
      <?php endif; ?>

      <a class="profile__gb" href="guestbook.php?id=<?= $ownerId ?>">📖 방명록</a>
      <?php if ($isOwner): ?><a class="profile__gb" href="blog_customize.php">블로그 꾸미기</a><?php endif; ?>
    </div>

    <nav class="cat-list">
      <div class="cat-list__head">카테고리<?php if ($isOwner): ?><a class="cat-list__manage" href="categories_manage.php">관리</a><?php endif; ?></div>
      <a class="<?= $cat === 0 ? 'on' : '' ?>" href="blog.php?id=<?= $ownerId ?>">전체</a>
      <?php foreach ($categories as $c): ?>
        <a class="<?= $cat === (int)$c['id'] ? 'on' : '' ?>"
           href="blog.php?id=<?= $ownerId ?>&cat=<?= (int)$c['id'] ?>">
          <?= htmlspecialchars($c['name']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <!-- 글 목록 -->
  <main class="blog-main">
    <?php if ($isOwner): ?>
      <nav class="manage-tabs">
        <a class="<?= $status === 'all'       ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'all') ?>">전체 <?= $statusCnt['all'] ?></a>
        <a class="<?= $status === 'published' ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'published') ?>">발행 <?= $statusCnt['published'] ?></a>
        <a class="<?= $status === 'draft'     ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'draft') ?>">임시저장 <?= $statusCnt['draft'] ?></a>
      </nav>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <p class="empty"><?= ($isOwner && $status === 'draft') ? '임시저장한 글이 없어요.' : '아직 글이 없어요.' ?></p>
    <?php else: ?>
      <div class="feed">
        <?php foreach ($posts as $p): ?>
          <?php if ($isOwner): ?><div class="card-wrap"><?php endif; ?>
          <a class="card" href="view.php?id=<?= (int)$p['id'] ?>">
            <div class="card__thumb">
              <?php if (!empty($p['thumbnail_stored'])): ?>
                <img src="../uploads/<?= htmlspecialchars($p['thumbnail_stored']) ?>" alt="">
              <?php else: ?>
                <span class="card__noimg">No Image</span>
              <?php endif; ?>
            </div>
            <div class="card__body">
              <span class="card__cat">
                <?php if (!empty($p['is_pinned'])): ?><b class="card__pin">공지</b><?php endif; ?>
                <?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '미분류' ?>
                <?php if ($isOwner && $p['status'] === 'draft'): ?> · 임시저장<?php endif; ?>
                <?php if ($isOwner && $p['visibility'] !== 'all'): ?> · <?= $p['visibility'] === 'private' ? '비공개' : '이웃공개' ?><?php endif; ?>
              </span>
              <h2 class="card__title"><?= htmlspecialchars($p['title']) ?></h2>
              <?php if ((int)$blogSettings['show_post_summary'] === 1): ?>
                <p class="card__excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 70, '…')) ?></p>
              <?php endif; ?>
              <div class="card__meta">
                <span><?= date('Y.m.d', strtotime($p['created_at'])) ?></span>
                <span>조회 <?= (int)$p['view_count'] ?> · ♥ <?= (int)$p['like_count'] ?> · 💬 <?= (int)$p['comment_count'] ?></span>
              </div>
            </div>
          </a>
          <?php if ($isOwner): ?>
            <div class="card-actions">
              <a href="modify.php?id=<?= (int)$p['id'] ?>">수정</a>
              <a href="delete.php?id=<?= (int)$p['id'] ?>">삭제</a>
            </div>
          </div><!-- .card-wrap -->
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <nav class="pager">
        <?php if ($page > 1): ?>
          <a href="<?= blogUrl($page - 1, $ownerId, $cat, $status) ?>">‹ 이전</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="<?= blogUrl($page + 1, $ownerId, $cat, $status) ?>">다음 ›</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>

</div>
</div>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

