<?php
/**
 * index.php — 블로그 메인 (전체 공개 글 피드).
 *   공개글(status=published, visibility=all)을 최신순으로 + 검색 + 페이징.
 *   카테고리 필터는 유저별 페이지(blog.php)에서 처리 — 전체 피드엔 안 넣음.
 */

session_start();
if (!isset($_SESSION['user_id'])) {        // 로그인 검사 먼저 (header 출력 전)
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$q       = trim($_GET['q'] ?? '');         // 검색어
$perPage = 6;
$page    = max(1, (int)($_GET['page'] ?? 1));

// 공개글 조건 + (검색어 있으면) 제목/내용 LIKE
$where  = "p.status = 'published' AND p.visibility = 'all'";
$params = [];
$types  = '';
if ($q !== '') {
    $where  .= " AND (p.title LIKE ? OR p.content LIKE ?)";
    $like    = '%' . $q . '%';
    $params  = [$like, $like];
    $types   = 'ss';
}

// 전체 개수 → 총 페이지 수
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM posts p WHERE $where");
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// 현재 페이지 글 목록 (작성자 닉네임, 카테고리명 같이)
$sql = "SELECT p.id, p.title, p.content, p.thumbnail_stored, p.view_count, p.created_at,
               u.nickname, c.name AS category_name
        FROM posts p
        JOIN users u      ON u.id = p.user_id
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $where
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types . 'ii', ...[...$params, $perPage, $offset]);
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 페이징 링크용 쿼리스트링 헬퍼 (검색어 유지)
function pageUrl($n, $q) {
    $qs = ['page' => $n];
    if ($q !== '') $qs['q'] = $q;
    return 'index.php?' . http_build_query($qs);
}

$pageTitle = '블로그 메인 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="feed-head">
  <h1>둘러보기</h1>
  <form class="search" method="get" action="index.php">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="제목·내용 검색">
    <button type="submit">검색</button>
  </form>
</section>

<?php if (!$posts): ?>
  <p class="empty"><?= $q !== '' ? '검색 결과가 없어요.' : '아직 공개된 글이 없어요.' ?></p>
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
      <a href="<?= pageUrl($page - 1, $q) ?>">‹ 이전</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= pageUrl($page + 1, $q) ?>">다음 ›</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
