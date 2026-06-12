<?php
/**
 * index.php — 블로그 메인 (둘러보기).
 *   ① 이웃 새 글  ② 인기 태그  ③ 정렬(최신/인기)+검색+태그필터 피드 + 페이징.
 *   공개 글 = status=published, visibility=all (이웃 새 글만 이웃공개 포함).
 */

session_start();
if (!isset($_SESSION['user_id'])) {        // 로그인 검사 먼저 (header 출력 전)
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$viewerId = $_SESSION['user_id'];
$q        = trim($_GET['q'] ?? '');
$tagId    = (int)($_GET['tag'] ?? 0);
$sort     = ($_GET['sort'] ?? 'latest') === 'popular' ? 'popular' : 'latest';
$perPage  = 6;
$page     = max(1, (int)($_GET['page'] ?? 1));

// ── ① 이웃 새 글 (내가 이웃 추가한 사람들의 최신 글) ──
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.thumbnail_stored, p.created_at, u.nickname
     FROM posts p
     JOIN neighbors n ON n.neighbor_id = p.user_id AND n.user_id = ?
     JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.visibility IN ('all','neighbor')
     ORDER BY p.created_at DESC LIMIT 4"
);
$stmt->bind_param("i", $viewerId);
$stmt->execute();
$neighborPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── ② 인기 태그 (공개글에 많이 쓰인 태그 Top 10) ──
$popularTags = $conn->query(
    "SELECT t.id, t.name, COUNT(*) AS cnt
     FROM post_tags pt
     JOIN tags t  ON t.id = pt.tag_id
     JOIN posts p ON p.id = pt.post_id AND p.status='published' AND p.visibility='all'
     GROUP BY t.id ORDER BY cnt DESC, t.name ASC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

// ── ③ 메인 피드 조건 (검색 + 태그 필터) ──
$join   = '';
$where  = "p.status = 'published' AND p.visibility = 'all'";
$params = [];
$types  = '';

if ($tagId > 0) {
    $join     = " JOIN post_tags pt ON pt.post_id = p.id AND pt.tag_id = ?";
    $params[] = $tagId;
    $types   .= 'i';
}
if ($q !== '') {
    $where   .= " AND (p.title LIKE ? OR p.content LIKE ?)";
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like;
    $types   .= 'ss';
}
$order = $sort === 'popular' ? "p.view_count DESC, p.created_at DESC" : "p.created_at DESC";

// 개수 → 페이지 수
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM posts p $join WHERE $where");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// 글 목록
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.content, p.thumbnail_stored, p.view_count, p.created_at,
            u.nickname, c.name AS category_name
     FROM posts p
     $join
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY $order
     LIMIT ? OFFSET ?"
);
$stmt->bind_param($types . 'ii', ...[...$params, $perPage, $offset]);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 태그 필터 중이면 태그명
$tagName = '';
if ($tagId > 0) {
    $stmt = $conn->prepare("SELECT name FROM tags WHERE id = ?");
    $stmt->bind_param("i", $tagId);
    $stmt->execute();
    $tagName = $stmt->get_result()->fetch_assoc()['name'] ?? '';
    $stmt->close();
}

// 현재 상태(q·sort·tag)를 유지하며 일부만 바꾼 URL 생성
function feedUrl(array $override, $q, $sort, $tagId, $page) {
    $qs = [];
    if ($q !== '')        $qs['q']    = $q;
    if ($sort !== 'latest') $qs['sort'] = $sort;
    if ($tagId > 0)       $qs['tag']  = $tagId;
    $qs['page'] = $page;
    $qs = array_merge($qs, $override);
    return 'index.php?' . http_build_query($qs);
}

$pageTitle = '블로그 메인 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<!-- ① 이웃 새 글 -->
<?php if ($neighborPosts): ?>
  <section class="nb">
    <h2 class="sec-title">이웃 새 글</h2>
    <div class="nb__row">
      <?php foreach ($neighborPosts as $n): ?>
        <a class="nb__item" href="view.php?id=<?= (int)$n['id'] ?>">
          <div class="nb__thumb">
            <?php if (!empty($n['thumbnail_stored'])): ?>
              <img src="uploads/<?= htmlspecialchars($n['thumbnail_stored']) ?>" alt="">
            <?php endif; ?>
          </div>
          <div class="nb__title"><?= htmlspecialchars($n['title']) ?></div>
          <div class="nb__nick"><?= htmlspecialchars($n['nickname']) ?>님</div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<!-- ② 인기 태그 -->
<?php if ($popularTags): ?>
  <section class="tagcloud">
    <h2 class="sec-title">인기 태그</h2>
    <div class="tagcloud__chips">
      <?php foreach ($popularTags as $t): ?>
        <a class="chip <?= $tagId === (int)$t['id'] ? 'on' : '' ?>" href="index.php?tag=<?= (int)$t['id'] ?>">
          #<?= htmlspecialchars($t['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<!-- ③ 메인 피드 -->
<section class="feed-head">
  <div class="feed-head__left">
    <h1>둘러보기</h1>
    <div class="sorttabs">
      <a class="<?= $sort === 'latest'  ? 'on' : '' ?>" href="<?= feedUrl(['sort' => 'latest',  'page' => 1], $q, $sort, $tagId, $page) ?>">최신순</a>
      <a class="<?= $sort === 'popular' ? 'on' : '' ?>" href="<?= feedUrl(['sort' => 'popular', 'page' => 1], $q, $sort, $tagId, $page) ?>">인기순</a>
    </div>
  </div>
  <form class="search" method="get" action="index.php">
    <?php if ($tagId > 0): ?><input type="hidden" name="tag" value="<?= $tagId ?>"><?php endif; ?>
    <?php if ($sort !== 'latest'): ?><input type="hidden" name="sort" value="<?= $sort ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="제목·내용 검색">
    <button type="submit">검색</button>
  </form>
</section>

<?php if ($tagId > 0 && $tagName !== ''): ?>
  <div class="filter-bar">
    <span>#<?= htmlspecialchars($tagName) ?> 글 모아보기</span>
    <a href="index.php">✕ 해제</a>
  </div>
<?php endif; ?>

<?php if (!$posts): ?>
  <p class="empty"><?= ($q !== '' || $tagId > 0) ? '결과가 없어요.' : '아직 공개된 글이 없어요.' ?></p>
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
          <?php if ($p['category_name']): ?>
            <span class="card__cat"><?= htmlspecialchars($p['category_name']) ?></span>
          <?php endif; ?>
          <h2 class="card__title"><?= htmlspecialchars($p['title']) ?></h2>
          <p class="card__excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 70, '…')) ?></p>
          <div class="card__meta">
            <span><?= htmlspecialchars($p['nickname']) ?>님</span>
            <span><?= date('Y.m.d', strtotime($p['created_at'])) ?> · 조회 <?= (int)$p['view_count'] ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <nav class="pager">
    <?php if ($page > 1): ?>
      <a href="<?= feedUrl(['page' => $page - 1], $q, $sort, $tagId, $page) ?>">‹ 이전</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= feedUrl(['page' => $page + 1], $q, $sort, $tagId, $page) ?>">다음 ›</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
