<?php
/**
 * index.php — 블로그 메인 (둘러보기).
 *   ① 이웃 새 글  ② 인기 태그  ③ 정렬(최신/인기)+검색+태그필터 피드 + 페이징.
 *   공개 글 = status=published, visibility=all (이웃 새 글만 이웃공개 포함).
 */

session_start();
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/categories.php';

$viewerId = $_SESSION['user_id'] ?? 0;   // 비로그인(게스트)도 메인 열람 가능
$q        = trim($_GET['q'] ?? '');
$cat      = $_GET['cat'] ?? '';                 // 카테고리(주제) 이름 필터
$tagId    = (int)($_GET['tag'] ?? 0);
$sort     = ($_GET['sort'] ?? 'latest') === 'popular' ? 'popular' : 'latest';
$perPage  = 6;
$page     = max(1, (int)($_GET['page'] ?? 1));
$ajax     = isset($_GET['ajax']);   // 카테고리/정렬/검색 클릭 시 피드만 교체(AJAX)

// ── ① 이웃 새 글 (내가 이웃 추가한 사람들의 최신 글) ──
$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.created_at, u.nickname,
            COALESCE((SELECT pi.stored FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), p.thumbnail_stored) AS thumbnail_stored
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

// ── 인기글 (공개글 중 공감 많은 순 Top 6) ──
$popularPosts = $conn->query(
    "SELECT p.id, p.title, p.view_count, u.nickname,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count
     FROM posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.visibility = 'all'
     ORDER BY like_count DESC, p.view_count DESC, p.created_at DESC
     LIMIT 6"
)->fetch_all(MYSQLI_ASSOC);

// ── ② 인기 태그 (공개글에 많이 쓰인 태그 Top 10) ──
$popularTags = $conn->query(
    "SELECT t.id, t.name, COUNT(*) AS cnt
     FROM post_tags pt
     JOIN tags t  ON t.id = pt.tag_id
     JOIN posts p ON p.id = pt.post_id AND p.status='published' AND p.visibility='all'
     GROUP BY t.id HAVING cnt >= 2 ORDER BY cnt DESC, t.name ASC LIMIT 10"
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
    // 제목·내용 + 작성자 닉네임/블로그 제목 + 카테고리 + 태그명까지 검색
    $where   .= " AND (p.title LIKE ? OR p.content LIKE ?
                  OR u.nickname LIKE ? OR u.blog_title LIKE ?
                  OR c.name LIKE ?
                  OR EXISTS(SELECT 1 FROM post_tags pt2 JOIN tags t2 ON t2.id = pt2.tag_id
                            WHERE pt2.post_id = p.id AND (t2.name LIKE ? OR t2.normalized_name LIKE ?)))";
    $like     = '%' . $q . '%';
    $normalizedLike = '%' . mb_strtolower($q, 'UTF-8') . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $normalizedLike;
    $types   .= 'sssssss';
}
if ($cat !== '' && in_array($cat, $FIXED_CATEGORIES, true)) {
    // 주제는 유저별 카테고리라, 예전/샘플 세부 카테고리 이름까지 같은 탭에 묶어서 매칭한다.
    $catNames = $CATEGORY_ALIASES[$cat] ?? [$cat];
    $placeholders = implode(',', array_fill(0, count($catNames), '?'));
    $where .= " AND EXISTS(SELECT 1 FROM categories cc WHERE cc.id = p.category_id AND cc.name IN ($placeholders))";
    foreach ($catNames as $name) {
        $params[] = $name;
        $types .= 's';
    }
}
$order = $sort === 'popular' ? "p.view_count DESC, p.created_at DESC" : "p.created_at DESC";

// 개수 → 페이지 수
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM posts p
     $join
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where"
);
if ($types !== '') $stmt->bind_param($types, ...$params);
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
            u.nickname, c.name AS category_name,
            (SELECT COUNT(*) FROM likes l    WHERE l.post_id  = p.id) AS like_count,
            (SELECT COUNT(*) FROM comments m WHERE m.post_id  = p.id) AS comment_count
     FROM posts p
     $join
     JOIN users u ON u.id = p.user_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY $order
     LIMIT ? OFFSET ?"
);
$listParams = [...$params, $perPage, $offset];
$stmt->bind_param($types . 'ii', ...$listParams);
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
function feedUrl(array $override, $q, $sort, $tagId, $cat, $page) {
    $qs = [];
    if ($q !== '')        $qs['q']    = $q;
    if ($sort !== 'latest') $qs['sort'] = $sort;
    if ($tagId > 0)       $qs['tag']  = $tagId;
    if ($cat !== '')      $qs['cat']  = $cat;
    $qs['page'] = $page;
    $qs = array_merge($qs, $override);
    return 'index.php?' . http_build_query($qs);
}

// 히어로(대표글): 공개글 중 썸네일 있는 최신 글 우선, 없으면 그냥 최신
$hero = $conn->query(
    "SELECT p.id, p.title, u.nickname,
            COALESCE((SELECT pi.stored FROM post_images pi WHERE pi.post_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), p.thumbnail_stored) AS thumbnail_stored
     FROM posts p JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.visibility = 'all'
     ORDER BY (EXISTS(SELECT 1 FROM post_images pi WHERE pi.post_id = p.id) OR p.thumbnail_stored IS NOT NULL) DESC, p.created_at DESC
     LIMIT 1"
)->fetch_assoc();

$isHome = !$ajax;   // 전체 페이지(헤더·히어로·위젯)는 일반 요청에서만. AJAX면 피드만 출력.

if (!$ajax) {
    $pageTitle = '블로그 메인 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
}
?>

<!-- 대표글 히어로 (홈에서만) -->
<?php if ($isHome && $hero): ?>
  <a class="hero" href="view.php?id=<?= (int)$hero['id'] ?>"
     <?php if (!empty($hero['thumbnail_stored'])): ?>style="background-image: url('../uploads/<?= htmlspecialchars($hero['thumbnail_stored']) ?>')"<?php endif; ?>>
    <div class="hero__inner">
      <span class="hero__badge">오늘의 블로그</span>
      <h2 class="hero__title"><?= htmlspecialchars($hero['title']) ?></h2>
      <span class="hero__nick"><?= htmlspecialchars($hero['nickname']) ?>님</span>
    </div>
  </a>
<?php endif; ?>

<?php if ($isHome): ?>
  <section class="bridge-panel" aria-label="BRIDGE 206 소개">
    <div>
      <span class="bridge-panel__eyebrow">BRIDGE 206</span>
      <h1>20대와 60대에서 시작해, 모든 세대를 잇는 블로그</h1>
      <p>읽기 편한 글자 크기와 세대가 함께 나눌 수 있는 이야기로 서로의 일상을 연결합니다.</p>
    </div>
    <div class="bridge-panel__question">
      <span>오늘의 연결 질문</span>
      <strong>다른 세대에게 가장 물어보고 싶은 것은 무엇인가요?</strong>
    </div>
    <div class="bridge-panel__features" aria-label="BRIDGE 206 특징">
      <a href="#bridge-feature-reading">읽기 편한 글자 크기</a>
      <a href="#bridge-feature-topics">세대별 관심 주제</a>
      <a href="#bridge-feature-question">함께 묻는 연결 질문</a>
    </div>
  </section>

  <section class="bridge-feature-detail" aria-label="BRIDGE 206 기능 설명">
    <article id="bridge-feature-reading">
      <span>01</span>
      <h2>읽기 편한 글자 크기</h2>
      <p>보통, 크게, 가장 크게 중 선택하면 글자뿐 아니라 버튼, 입력창, 카드 간격까지 함께 커져서 어느 세대든 편하게 읽을 수 있어요.</p>
    </article>
    <article id="bridge-feature-topics">
      <span>02</span>
      <h2>세대별 관심 주제</h2>
      <p>자동차, 영화, 취미, 어학처럼 세대마다 관심사가 다른 주제를 한곳에 모아 서로의 일상을 발견하도록 돕습니다.</p>
    </article>
    <article id="bridge-feature-question">
      <span>03</span>
      <h2>함께 묻는 연결 질문</h2>
      <p>다른 세대에게 궁금한 질문을 먼저 보여주어 글쓰기와 댓글 대화가 자연스럽게 이어지도록 만든 장치입니다.</p>
    </article>
  </section>
<?php endif; ?>

<!-- ① 이웃 새 글 -->
<?php if ($isHome && $neighborPosts): ?>
  <section class="nb">
    <h2 class="sec-title">이웃 새 글 <a class="sec-more" href="neighbor_posts.php">더보기 ›</a></h2>
    <div class="nb__row">
      <?php foreach ($neighborPosts as $n): ?>
        <a class="nb__item" href="view.php?id=<?= (int)$n['id'] ?>">
          <div class="nb__thumb">
            <?php if (!empty($n['thumbnail_stored'])): ?>
              <img src="../uploads/<?= htmlspecialchars($n['thumbnail_stored']) ?>" alt="">
            <?php endif; ?>
          </div>
          <div class="nb__title"><?= htmlspecialchars($n['title']) ?></div>
          <div class="nb__nick"><?= htmlspecialchars($n['nickname']) ?>님</div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<!-- 인기글 -->
<?php if ($isHome && $popularPosts): ?>
  <section class="popular">
    <h2 class="sec-title">인기글</h2>
    <ol class="popular__list">
      <?php foreach ($popularPosts as $i => $pp): ?>
        <li>
          <a href="view.php?id=<?= (int)$pp['id'] ?>">
            <span class="popular__rank"><?= $i + 1 ?></span>
            <span class="popular__body">
              <span class="popular__title"><?= htmlspecialchars($pp['title']) ?></span>
              <span class="popular__meta"><?= htmlspecialchars($pp['nickname']) ?>님 · ♥ <?= (int)$pp['like_count'] ?> · 조회 <?= (int)$pp['view_count'] ?></span>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<!-- ② 인기 태그 -->
<?php if ($isHome && $popularTags): ?>
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

<!-- 카테고리(주제) 탭 — 둘러보기 바로 위 -->
<?php if ($isHome): ?>
<section class="category-panel" aria-label="관심 주제">
  <div class="category-panel__head">
    <span>관심 주제</span>
    <strong>세대가 함께 읽는 이야기</strong>
  </div>
  <nav class="cat-tabs">
    <a class="<?= $cat === '' ? 'on' : '' ?>" href="index.php">전체</a>
    <?php foreach ($FIXED_CATEGORIES as $cn): ?>
      <a class="<?= $cat === $cn ? 'on' : '' ?>" href="index.php?cat=<?= urlencode($cn) ?>"><?= htmlspecialchars($cn) ?></a>
    <?php endforeach; ?>
  </nav>
</section>
<?php endif; ?>

<!-- ③ 메인 피드 (AJAX로 교체되는 영역) -->
<?php if ($isHome): ?><div id="feedZone"><?php endif; ?>
<section class="feed-head">
  <div class="feed-head__left">
    <h1>둘러보기</h1>
    <div class="sorttabs">
      <a class="<?= $sort === 'latest'  ? 'on' : '' ?>" href="<?= feedUrl(['sort' => 'latest',  'page' => 1], $q, $sort, $tagId, $cat, $page) ?>">최신순</a>
      <a class="<?= $sort === 'popular' ? 'on' : '' ?>" href="<?= feedUrl(['sort' => 'popular', 'page' => 1], $q, $sort, $tagId, $cat, $page) ?>">인기순</a>
    </div>
  </div>
  <form class="search" method="get" action="index.php">
    <?php if ($tagId > 0): ?><input type="hidden" name="tag" value="<?= $tagId ?>"><?php endif; ?>
    <?php if ($sort !== 'latest'): ?><input type="hidden" name="sort" value="<?= $sort ?>"><?php endif; ?>
    <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="제목·내용·블로그 검색">
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
            <img src="../uploads/<?= htmlspecialchars($p['thumbnail_stored']) ?>" alt="">
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
            <span><?= htmlspecialchars($p['nickname']) ?>님 · <?= date('Y.m.d', strtotime($p['created_at'])) ?></span>
            <span>조회 <?= (int)$p['view_count'] ?> · ♥ <?= (int)$p['like_count'] ?> · 💬 <?= (int)$p['comment_count'] ?></span>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <nav class="pager">
    <?php if ($page > 1): ?>
      <a href="<?= feedUrl(['page' => $page - 1], $q, $sort, $tagId, $cat, $page) ?>">‹ 이전</a>
    <?php endif; ?>
    <span><?= $page ?> / <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="<?= feedUrl(['page' => $page + 1], $q, $sort, $tagId, $cat, $page) ?>">다음 ›</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<?php if ($isHome): ?>
</div><!-- #feedZone -->

<script>
(function () {
  const zone = document.getElementById('feedZone');
  if (!zone) return;

  // 주어진 URL을 AJAX(ajax=1)로 불러 피드 영역만 교체
  function load(url, push) {
    const u = new URL(url, location.href);
    u.searchParams.set('ajax', '1');
    fetch(u).then(r => r.text()).then(function (html) {
      zone.innerHTML = html;
      const cat = new URL(url, location.href).searchParams.get('cat') || '';
      document.querySelectorAll('.cat-tabs a').forEach(function (a) {
        const ac = new URL(a.href, location.href).searchParams.get('cat') || '';
        a.classList.toggle('on', ac === cat);
      });
      if (push) history.pushState(null, '', url);
    });
  }

  // 카테고리 탭(피드 영역 바깥)
  document.querySelectorAll('.cat-tabs a').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); load(a.getAttribute('href'), true); });
  });
  // 피드 영역 안의 index.php 링크(정렬·페이징·필터해제)만 위임 처리. 글 카드(view.php)는 그냥 이동.
  zone.addEventListener('click', function (e) {
    const a = e.target.closest('a');
    if (!a) return;
    if (!new URL(a.href, location.href).pathname.endsWith('index.php')) return;
    e.preventDefault();
    load(a.getAttribute('href'), true);
  });
  // 검색
  zone.addEventListener('submit', function (e) {
    const f = e.target.closest('form.search');
    if (f) { e.preventDefault(); load('index.php?' + new URLSearchParams(new FormData(f)).toString(), true); }
  });
  window.addEventListener('popstate', function () { load(location.href, false); });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; endif; ?>

