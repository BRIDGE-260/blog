<?php
/**
 * blog.php — 특정 유저의 블로그 메인.
 *   사이드바(프로필·카테고리·방문자수) + 그 유저 글 목록 + 카테고리 필터 + 페이징.
 *   남의 블로그면 이웃 추가/취소(neighbors). 방문 시 visit_logs 카운트.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$viewerId = $_SESSION['user_id'];
$ownerId  = (int)($_GET['id'] ?? $viewerId);   // id 없으면 내 블로그

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
    require_once __DIR__ . '/header.php';
    echo '<p class="empty">블로그를 찾을 수 없어요.</p>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$isOwner = $ownerId === (int)$viewerId;

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'neighbor' && !$isOwner) {
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

// ── 글 목록 조건 ───────────────────────
$cat     = (int)($_GET['cat'] ?? 0);
$perPage = 6;
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = "p.user_id = ?";
$params = [$ownerId];
$types  = "i";

if ($isOwner) {
    // 본인: 임시저장 포함 전부
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
    "SELECT p.id, p.title, p.content, p.thumbnail_stored, p.view_count, p.created_at,
            p.status, p.visibility, c.name AS category_name,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id = p.id) AS comment_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY p.created_at DESC
     LIMIT ? OFFSET ?"
);
$listParams = [...$params, $perPage, $offset];
$stmt->bind_param($types . 'ii', ...$listParams);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 페이징 URL (id·cat 유지)
function blogUrl($n, $ownerId, $cat) {
    $qs = ['id' => $ownerId, 'page' => $n];
    if ($cat > 0) $qs['cat'] = $cat;
    return 'blog.php?' . http_build_query($qs);
}

$pageTitle = ($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') . ' · MyBlog';
require_once __DIR__ . '/header.php';
?>

<div class="blog-layout">

  <!-- 사이드바 -->
  <aside class="blog-side">
    <div class="profile">
      <div class="profile__img">
        <?php if (!empty($owner['profile_image_stored'])): ?>
          <img src="uploads/<?= htmlspecialchars($owner['profile_image_stored']) ?>" alt="">
        <?php else: ?>
          <span><?= htmlspecialchars(mb_substr($owner['nickname'], 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="profile__title"><?= htmlspecialchars($owner['blog_title'] ?: $owner['nickname'] . '님의 블로그') ?></div>
      <div class="profile__nick"><?= htmlspecialchars($owner['nickname']) ?></div>
      <?php if (!empty($owner['intro'])): ?>
        <p class="profile__intro"><?= nl2br(htmlspecialchars($owner['intro'])) ?></p>
      <?php endif; ?>

      <?php if (!$isOwner): ?>
        <form method="post" action="blog.php?id=<?= $ownerId ?>">
          <input type="hidden" name="action" value="neighbor">
          <button type="submit" class="<?= $iAddedOwner ? 'btn-ghost-dark' : 'btn-primary' ?>">
            <?= $iAddedOwner ? '이웃 취소' : '이웃 추가' ?>
          </button>
        </form>
      <?php endif; ?>

      <div class="profile__visit">오늘 <?= $todayVisit ?> · 전체 <?= $totalVisit ?></div>
    </div>

    <nav class="cat-list">
      <div class="cat-list__head">카테고리</div>
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
    <?php if (!$posts): ?>
      <p class="empty">아직 글이 없어요.</p>
    <?php else: ?>
      <div class="feed">
        <?php foreach ($posts as $p): ?>
          <a class="card" href="view.php?id=<?= (int)$p['id'] ?>">
            <div class="card__thumb">
              <?php if (!empty($p['thumbnail_stored'])): ?>
                <img src="uploads/<?= htmlspecialchars($p['thumbnail_stored']) ?>" alt="">
              <?php else: ?>
                <span class="card__noimg">No Image</span>
              <?php endif; ?>
            </div>
            <div class="card__body">
              <span class="card__cat">
                <?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '미분류' ?>
                <?php if ($isOwner && $p['status'] === 'draft'): ?> · 임시저장<?php endif; ?>
                <?php if ($isOwner && $p['visibility'] !== 'all'): ?> · <?= $p['visibility'] === 'private' ? '비공개' : '이웃공개' ?><?php endif; ?>
              </span>
              <h2 class="card__title"><?= htmlspecialchars($p['title']) ?></h2>
              <p class="card__excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 70, '…')) ?></p>
              <div class="card__meta">
                <span><?= date('Y.m.d', strtotime($p['created_at'])) ?></span>
                <span>조회 <?= (int)$p['view_count'] ?> · ♥ <?= (int)$p['like_count'] ?> · 💬 <?= (int)$p['comment_count'] ?></span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <nav class="pager">
        <?php if ($page > 1): ?>
          <a href="<?= blogUrl($page - 1, $ownerId, $cat) ?>">‹ 이전</a>
        <?php endif; ?>
        <span><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="<?= blogUrl($page + 1, $ownerId, $cat) ?>">다음 ›</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
