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
    "SELECT id, nickname, blog_title, intro, profile_image_stored, notifications_read_at FROM users WHERE id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$owner) {
    $pageTitle = '블로그 · BRIDGE 206';
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
function blogContrastColor($hex, $dark = '#2d3436', $light = '#ffffff') {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) return $dark;

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $brightness = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

    return $brightness >= 150 ? $dark : $light;
}
function blogMutedColor($textColor) {
    return $textColor === '#ffffff' ? 'rgba(255,255,255,0.72)' : '#7f878d';
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
    . '--blog-page-text:' . blogContrastColor($blogSettings['background_color']) . ';'
    . '--blog-profile-text:' . blogContrastColor($blogSettings['profile_card_color']) . ';'
    . '--blog-profile-muted:' . blogMutedColor(blogContrastColor($blogSettings['profile_card_color'])) . ';'
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
$stageClasses = [
    'blog-stage',
    'blog-stage--profile-' . $blogSettings['profile_shape'],
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
$blogSearch = trim($_GET['q'] ?? '');

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
if ($blogSearch !== '') {
    $where .= " AND (p.title LIKE ? OR p.content LIKE ? OR c.name LIKE ?)";
    $like = '%' . $blogSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

// 개수 → 페이지 수
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where"
);
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

$latestDraft = null;
$recentComments = [];
$recentGuestbook = [];
$topPosts = [];
$relatedBlogs = [];
$sideStats = [
    'published' => 0,
    'draft' => 0,
    'comments' => 0,
    'likes' => 0,
    'guestbook' => 0,
    'neighbors' => 0,
    'new_comments' => 0,
    'new_likes' => 0,
    'new_guestbook' => 0,
    'uncategorized' => 0,
];

if ($isOwner) {
    $stmt = $conn->prepare(
        "SELECT id, title, updated_at
         FROM posts
         WHERE user_id = ? AND status = 'draft'
         ORDER BY updated_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $latestDraft = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$stmt = $conn->prepare(
    "SELECT
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count
     FROM posts
     WHERE user_id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$postSummary = $stmt->get_result()->fetch_assoc();
$stmt->close();
$sideStats['published'] = (int)($postSummary['published_count'] ?? 0);
$sideStats['draft'] = (int)($postSummary['draft_count'] ?? 0);

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM comments cm
     JOIN posts p ON p.id = cm.post_id
     WHERE p.user_id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$sideStats['comments'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM likes l
     JOIN posts p ON p.id = l.post_id
     WHERE p.user_id = ?"
);
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$sideStats['likes'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM guestbook WHERE owner_id = ?");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$sideStats['guestbook'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM neighbors WHERE neighbor_id = ?");
$stmt->bind_param("i", $ownerId);
$stmt->execute();
$sideStats['neighbors'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($isOwner) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM posts WHERE user_id = ? AND category_id IS NULL");
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $sideStats['uncategorized'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $readAt = $owner['notifications_read_at'] ?: '1970-01-01 00:00:00';

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM comments cm
         JOIN posts p ON p.id = cm.post_id
         WHERE p.user_id = ? AND cm.user_id <> ? AND cm.created_at > ?"
    );
    $stmt->bind_param("iis", $ownerId, $ownerId, $readAt);
    $stmt->execute();
    $sideStats['new_comments'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM likes l
         JOIN posts p ON p.id = l.post_id
         WHERE p.user_id = ? AND l.user_id <> ? AND l.created_at > ?"
    );
    $stmt->bind_param("iis", $ownerId, $ownerId, $readAt);
    $stmt->execute();
    $sideStats['new_likes'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM guestbook
         WHERE owner_id = ? AND user_id <> ? AND created_at > ?"
    );
    $stmt->bind_param("iis", $ownerId, $ownerId, $readAt);
    $stmt->execute();
    $sideStats['new_guestbook'] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

$guestbookWhere = "g.owner_id = ?";
$guestbookTypes = "i";
$guestbookParams = [$ownerId];
if ($isOwner) {
    $guestbookWhere .= " AND g.user_id <> ?";
    $guestbookTypes .= "i";
    $guestbookParams[] = $ownerId;
}
$stmt = $conn->prepare(
    "SELECT g.content, g.created_at, u.nickname
     FROM guestbook g
     JOIN users u ON u.id = g.user_id
     WHERE $guestbookWhere
     ORDER BY g.created_at DESC
     LIMIT 2"
);
$stmt->bind_param($guestbookTypes, ...$guestbookParams);
$stmt->execute();
$recentGuestbook = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$commentWhere = "p.user_id = ?";
$commentTypes = "i";
$commentParams = [$ownerId];
if (!$isOwner) {
    if ($isNeighborRel) {
        $commentWhere .= " AND p.status = 'published' AND p.visibility IN ('all','neighbor')";
    } else {
        $commentWhere .= " AND p.status = 'published' AND p.visibility = 'all'";
    }
}
$stmt = $conn->prepare(
    "SELECT cm.post_id, cm.content, cm.created_at, u.nickname, p.title
     FROM comments cm
     JOIN posts p ON p.id = cm.post_id
     JOIN users u ON u.id = cm.user_id
     WHERE $commentWhere
     ORDER BY cm.created_at DESC
     LIMIT 2"
);
$stmt->bind_param($commentTypes, ...$commentParams);
$stmt->execute();
$recentComments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$topWhere = "p.user_id = ?";
$topTypes = "i";
$topParams = [$ownerId];
if ($isOwner) {
    $topWhere .= " AND p.status = 'published'";
} elseif ($isNeighborRel) {
    $topWhere .= " AND p.status = 'published' AND p.visibility IN ('all','neighbor')";
} else {
    $topWhere .= " AND p.status = 'published' AND p.visibility = 'all'";
}
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id) AS comment_count
     FROM posts p
     WHERE $topWhere
     ORDER BY like_count DESC, comment_count DESC, p.view_count DESC, p.created_at DESC
     LIMIT 2"
);
$stmt->bind_param($topTypes, ...$topParams);
$stmt->execute();
$topPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categoryNames = [];
foreach ($categories as $category) {
    $categoryNames[] = $category['name'];
}
$categoryNames = array_values(array_unique($categoryNames));
if ($categoryNames) {
    $placeholders = implode(',', array_fill(0, count($categoryNames), '?'));
    $relatedTypes = 'i' . str_repeat('s', count($categoryNames));
    $relatedParams = array_merge([$ownerId], $categoryNames);
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
                COUNT(DISTINCT p.id) AS matched_posts
         FROM users u
         JOIN posts p ON p.user_id = u.id AND p.status = 'published' AND p.visibility = 'all'
         JOIN categories c ON c.id = p.category_id
         WHERE u.id <> ? AND c.name IN ($placeholders)
         GROUP BY u.id
         ORDER BY matched_posts DESC, u.created_at DESC
         LIMIT 3"
    );
    $stmt->bind_param($relatedTypes, ...$relatedParams);
} else {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
                COUNT(p.id) AS matched_posts
         FROM users u
         JOIN posts p ON p.user_id = u.id AND p.status = 'published' AND p.visibility = 'all'
         WHERE u.id <> ?
         GROUP BY u.id
         ORDER BY matched_posts DESC, u.created_at DESC
         LIMIT 3"
    );
    $stmt->bind_param("i", $ownerId);
}
$stmt->execute();
$relatedBlogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$relatedBlogs && $categoryNames) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
                COUNT(p.id) AS matched_posts
         FROM users u
         JOIN posts p ON p.user_id = u.id AND p.status = 'published' AND p.visibility = 'all'
         WHERE u.id <> ?
         GROUP BY u.id
         ORDER BY matched_posts DESC, u.created_at DESC
         LIMIT 3"
    );
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $relatedBlogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 페이징 URL (id·cat·status 유지)
function blogUrl($n, $ownerId, $cat, $status = 'all', $search = '') {
    $qs = ['id' => $ownerId, 'page' => $n];
    if ($cat > 0)            $qs['cat']    = $cat;
    if ($status !== 'all')   $qs['status'] = $status;
    if ($search !== '')      $qs['q']      = $search;
    return 'blog.php?' . http_build_query($qs);
}

$pageTitle = ($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') . ' · BRIDGE 206';
$pageClass = 'page--wide';
$emptyTitle = '아직 글이 없어요.';
$emptyText = '첫 글이 발행되면 이 공간이 블로그 피드로 채워져요.';
if ($isOwner && $status === 'draft') {
    $emptyTitle = '임시저장한 글이 없어요.';
    $emptyText = '새 글을 쓰다가 임시저장하면 여기에서 이어서 쓸 수 있어요.';
} elseif ($blogSearch !== '') {
    $emptyTitle = '검색 결과가 없어요.';
    $emptyText = '다른 검색어로 다시 찾아보거나 전체 글을 확인해보세요.';
} elseif ($isOwner) {
    $emptyText = '첫 글을 쓰면 이 공간이 블로그 피드로 채워져요.';
} elseif (!$isOwner) {
    $emptyTitle = '아직 공개된 글이 없어요.';
    $emptyText = '블로그 주인이 글을 발행하면 이곳에 표시돼요.';
}
$profileReady = trim((string)($owner['intro'] ?? '')) !== '';
$categoryReady = count($categories) > 0;
$draftReady = $sideStats['draft'] > 0;
require_once __DIR__ . '/../app/header.php';
?>

<div class="<?= htmlspecialchars(implode(' ', $stageClasses)) ?>"
     style="<?= htmlspecialchars($blogStyle, ENT_QUOTES) ?>">

  <aside class="blog-side">
    <div class="blog-rail">
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
          </div>
        <?php endif; ?>

        <a class="profile__gb" href="guestbook.php?id=<?= $ownerId ?>">방명록</a>
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
    </div>
  </aside>

  <!-- 글 목록 -->
  <div class="<?= htmlspecialchars(implode(' ', $blogClasses)) ?>"
       style="<?= htmlspecialchars($blogStyle . $blogBgStyle, ENT_QUOTES) ?>">
  <main class="blog-main">
    <section class="blog-cover">
      <?php if (!empty($blogSettings['header_image_stored'])): ?>
        <img src="../uploads/<?= htmlspecialchars($blogSettings['header_image_stored']) ?>" alt="">
      <?php endif; ?>
      <div class="blog-cover__text">
        <h1><?= htmlspecialchars($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') ?></h1>
        <?php if (!empty($owner['intro']) && (int)$blogSettings['show_intro'] === 1): ?>
          <p><?= nl2br(htmlspecialchars($owner['intro'])) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <section class="blog-searchbar" aria-label="블로그 글 검색">
      <form method="get" action="blog.php">
        <input type="hidden" name="id" value="<?= $ownerId ?>">
        <?php if ($cat > 0): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
        <?php if ($isOwner && $status !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= htmlspecialchars($blogSearch) ?>" placeholder="이 블로그 글 검색">
        <button type="submit">검색</button>
        <?php if ($blogSearch !== ''): ?>
          <a href="<?= blogUrl(1, $ownerId, $cat, $status) ?>">해제</a>
        <?php endif; ?>
      </form>
    </section>

    <?php if ($isOwner): ?>
      <nav class="manage-tabs">
        <a class="<?= $status === 'all'       ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'all', $blogSearch) ?>">전체 <?= $statusCnt['all'] ?></a>
        <a class="<?= $status === 'published' ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'published', $blogSearch) ?>">발행 <?= $statusCnt['published'] ?></a>
        <a class="<?= $status === 'draft'     ? 'on' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'draft', $blogSearch) ?>">임시저장 <?= $statusCnt['draft'] ?></a>
      </nav>
    <?php endif; ?>

    <?php if (!$posts): ?>
      <div class="blog-empty">
        <span>BRIDGE 206</span>
        <h2><?= htmlspecialchars($emptyTitle) ?></h2>
        <p><?= htmlspecialchars($emptyText) ?></p>
        <?php if ($isOwner): ?>
          <?php if ($status === 'draft'): ?>
            <div class="blog-empty__actions">
              <a class="btn-primary" href="write.php">새 글 쓰기</a>
              <a class="btn-ghost-dark" href="<?= blogUrl(1, $ownerId, $cat, 'all') ?>">전체 글 보기</a>
            </div>
          <?php elseif ($blogSearch !== ''): ?>
            <div class="blog-empty__actions">
              <a class="btn-primary" href="<?= blogUrl(1, $ownerId, $cat, $status) ?>">전체 글 보기</a>
              <a class="btn-ghost-dark" href="write.php">새 글 쓰기</a>
            </div>
          <?php else: ?>
            <div class="blog-empty__setup" aria-label="블로그 시작 체크리스트">
              <a class="<?= $profileReady ? 'is-done' : '' ?>" href="profile.php">
                <strong><?= $profileReady ? '완료' : '필요' ?></strong>
                <span>프로필 소개</span>
              </a>
              <a class="<?= $categoryReady ? 'is-done' : '' ?>" href="categories_manage.php">
                <strong><?= $categoryReady ? count($categories) . '개' : '필요' ?></strong>
                <span>카테고리</span>
              </a>
              <a class="<?= $draftReady ? 'is-done' : '' ?>" href="<?= blogUrl(1, $ownerId, $cat, 'draft') ?>">
                <strong><?= $draftReady ? $sideStats['draft'] . '개' : '없음' ?></strong>
                <span>임시저장</span>
              </a>
            </div>
            <div class="blog-empty__actions">
              <a class="btn-primary" href="write.php">첫 글 쓰기</a>
              <a class="btn-ghost-dark" href="blog_customize.php">블로그 꾸미기</a>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="blog-empty__actions">
            <a class="btn-primary" href="<?= $blogSearch !== '' ? blogUrl(1, $ownerId, $cat, $status) : 'guestbook.php?id=' . $ownerId ?>"><?= $blogSearch !== '' ? '전체 글 보기' : '방명록 남기기' ?></a>
            <a class="btn-ghost-dark" href="index.php">다른 글 둘러보기</a>
          </div>
        <?php endif; ?>
      </div>
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
          <a href="<?= blogUrl($page - 1, $ownerId, $cat, $status, $blogSearch) ?>">‹ 이전</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="<?= blogUrl($page + 1, $ownerId, $cat, $status, $blogSearch) ?>">다음 ›</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>

</div>

  <aside class="blog-assist" aria-label="블로그 빠른 기능">
    <div class="blog-rail blog-rail--assist">
      <?php if ($isOwner): ?>
        <section class="blog-assist__section">
          <span>새 반응</span>
          <h2>확인할 소식</h2>
          <div class="blog-assist__stats">
            <a href="notifications.php">
              <strong><?= $sideStats['new_comments'] ?></strong>
              <em>새 댓글</em>
            </a>
            <a href="notifications.php">
              <strong><?= $sideStats['new_likes'] ?></strong>
              <em>새 공감</em>
            </a>
            <a href="notifications.php">
              <strong><?= $sideStats['new_guestbook'] ?></strong>
              <em>새 방명록</em>
            </a>
          </div>
        </section>

        <section class="blog-assist__section">
          <span>바로 작업</span>
          <h2>오늘 이어서 할 일</h2>
          <nav class="blog-assist__actions">
            <a href="write.php">새 글 쓰기</a>
            <?php if ($latestDraft): ?>
              <a href="modify.php?id=<?= (int)$latestDraft['id'] ?>">임시저장 이어쓰기</a>
            <?php else: ?>
              <a href="<?= blogUrl(1, $ownerId, $cat, 'draft') ?>">임시저장함 보기</a>
            <?php endif; ?>
            <a href="notifications.php">소식 확인</a>
            <a href="categories_manage.php">카테고리 정리</a>
          </nav>
        </section>

        <section class="blog-assist__section">
          <span>관리 도구</span>
          <h2>블로그 정리</h2>
          <nav class="blog-assist__actions blog-assist__actions--plain">
            <a href="blog_customize.php">꾸미기 바꾸기</a>
            <a href="stats.php">방문 통계 보기</a>
            <a href="liked.php">좋아요한 글</a>
            <a href="scraps.php">스크랩한 글</a>
          </nav>
        </section>

        <?php if ($sideStats['uncategorized'] > 0 || $sideStats['draft'] > 0): ?>
          <section class="blog-assist__section">
            <span>정리 필요</span>
            <div class="blog-assist__todo">
              <?php if ($sideStats['uncategorized'] > 0): ?>
                <a href="blog.php?id=<?= $ownerId ?>">미분류 글 <?= $sideStats['uncategorized'] ?>개 정리하기</a>
              <?php endif; ?>
              <?php if ($sideStats['draft'] > 0): ?>
                <a href="<?= blogUrl(1, $ownerId, $cat, 'draft') ?>">임시저장 <?= $sideStats['draft'] ?>개 이어보기</a>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php else: ?>
        <section class="blog-assist__section">
          <span>인기글</span>
          <h2>먼저 읽기 좋은 글</h2>
          <?php if ($topPosts): ?>
            <div class="blog-assist__posts">
              <?php foreach ($topPosts as $i => $topPost): ?>
                <a href="view.php?id=<?= (int)$topPost['id'] ?>">
                  <b><?= $i + 1 ?></b>
                  <span>
                    <strong><?= htmlspecialchars($topPost['title']) ?></strong>
                    <em>공감 <?= (int)$topPost['like_count'] ?> · 댓글 <?= (int)$topPost['comment_count'] ?> · 조회 <?= (int)$topPost['view_count'] ?></em>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="blog-assist__empty">아직 추천할 공개 글이 없어요.</p>
          <?php endif; ?>
        </section>

        <section class="blog-assist__section">
          <span>연결하기</span>
          <h2>이 블로그와 이어지기</h2>
          <nav class="blog-assist__actions">
            <a href="guestbook.php?id=<?= $ownerId ?>">방명록 남기기</a>
            <?php if ($isLogin): ?>
              <a href="neighbors.php?tab=find">다른 블로그 찾기</a>
            <?php else: ?>
              <a href="auth.php">로그인하고 이웃 맺기</a>
            <?php endif; ?>
            <a href="index.php?q=<?= urlencode($owner['nickname']) ?>">작성자 글 검색</a>
          </nav>
        </section>

      <?php endif; ?>

      <section class="blog-assist__section">
        <span><?= $isOwner ? '최근 반응' : '최근 대화' ?></span>
        <?php if ($recentComments || $recentGuestbook): ?>
          <?php if ($recentComments): ?>
            <div class="blog-assist__comments">
              <?php foreach ($recentComments as $comment): ?>
                <a href="view.php?id=<?= (int)$comment['post_id'] ?>">
                  <strong><?= htmlspecialchars($comment['nickname']) ?> · 댓글</strong>
                  <em><?= htmlspecialchars(mb_strimwidth($comment['content'], 0, 42, '…')) ?></em>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($recentGuestbook): ?>
            <div class="blog-assist__comments">
              <?php foreach ($recentGuestbook as $guest): ?>
                <a href="guestbook.php?id=<?= $ownerId ?>">
                  <strong><?= htmlspecialchars($guest['nickname']) ?> · 방명록</strong>
                  <em><?= htmlspecialchars(mb_strimwidth($guest['content'], 0, 42, '…')) ?></em>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="blog-assist__empty">아직 대화가 없어요.</p>
        <?php endif; ?>
      </section>

      <?php if ($relatedBlogs): ?>
        <section class="blog-assist__section">
          <span>관심사 연결</span>
          <h2>비슷한 블로그</h2>
          <div class="blog-assist__people">
            <?php foreach ($relatedBlogs as $related): ?>
              <a href="blog.php?id=<?= (int)$related['id'] ?>">
                <span class="blog-assist__avatar">
                  <?php if (!empty($related['profile_image_stored'])): ?>
                    <img src="../uploads/<?= htmlspecialchars($related['profile_image_stored']) ?>" alt="">
                  <?php else: ?>
                    <?= htmlspecialchars(mb_substr($related['nickname'], 0, 1)) ?>
                  <?php endif; ?>
                </span>
                <span>
                  <strong><?= htmlspecialchars($related['blog_title'] ?: $related['nickname'] . '님의 블로그') ?></strong>
                  <em><?= htmlspecialchars($related['nickname']) ?> · 읽을 글 <?= (int)$related['matched_posts'] ?></em>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </aside>
</div>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

