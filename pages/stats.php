<?php
/**
 * stats.php - 작성자 전용 블로그 현황.
 *   방문, 글, 반응, 인기 글을 한 화면에서 확인한다.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];
$visitEventsResult = $conn->query("SHOW TABLES LIKE 'visit_events'");
$hasVisitEvents = $visitEventsResult && $visitEventsResult->num_rows > 0;
$commentLikesResult = $conn->query("SHOW TABLES LIKE 'comment_likes'");
$hasCommentLikes = $commentLikesResult && $commentLikesResult->num_rows > 0;

function statOne(mysqli $conn, string $sql, int $userId): int {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $value = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $value;
}

function statTwo(mysqli $conn, string $sql, int $a, int $b): int {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $a, $b);
    $stmt->execute();
    $value = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $value;
}

$stmt = $conn->prepare(
    "SELECT visit_date, count FROM visit_logs
     WHERE user_id = ? AND visit_date >= CURDATE() - INTERVAL 6 DAY"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$map = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $map[$r['visit_date']] = (int)$r['count'];
}
$stmt->close();

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['date' => $d, 'count' => $map[$d] ?? 0];
}
$maxCount = max(1, max(array_column($days, 'count')));
$todayCount = end($days)['count'];

$totalVisit = statOne($conn, "SELECT COALESCE(SUM(count), 0) AS cnt FROM visit_logs WHERE user_id = ?", $userId);
$publishedCount = statOne($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE user_id = ? AND status = 'published'", $userId);
$draftCount = statOne($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE user_id = ? AND status = 'draft'", $userId);
$totalViews = statOne($conn, "SELECT COALESCE(SUM(view_count), 0) AS cnt FROM posts WHERE user_id = ?", $userId);
$totalLikes = statOne($conn, "SELECT COUNT(*) AS cnt FROM likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = ?", $userId);
$totalCommentLikes = $hasCommentLikes ? statOne($conn, "SELECT COUNT(*) AS cnt FROM comment_likes cl JOIN comments c ON c.id = cl.comment_id JOIN posts p ON p.id = c.post_id WHERE p.user_id = ?", $userId) : 0;
$totalComments = statOne($conn, "SELECT COUNT(*) AS cnt FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id = ?", $userId);
$totalScraps = statOne($conn, "SELECT COUNT(*) AS cnt FROM scraps s JOIN posts p ON p.id = s.post_id WHERE p.user_id = ?", $userId);
$newComments = statTwo($conn, "SELECT COUNT(*) AS cnt FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id = ? AND c.user_id <> ?", $userId, $userId);
$newLikes = statTwo($conn, "SELECT COUNT(*) AS cnt FROM likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = ? AND l.user_id <> ?", $userId, $userId);
$newCommentLikes = $hasCommentLikes ? statTwo($conn, "SELECT COUNT(*) AS cnt FROM comment_likes cl JOIN comments c ON c.id = cl.comment_id JOIN posts p ON p.id = c.post_id WHERE p.user_id = ? AND cl.user_id <> ?", $userId, $userId) : 0;
$neighborCount = statOne($conn, "SELECT COUNT(*) AS cnt FROM neighbors WHERE neighbor_id = ?", $userId);
$guestbookCount = statOne($conn, "SELECT COUNT(*) AS cnt FROM guestbook WHERE owner_id = ?", $userId);

$hourRows = [];
$genderRows = [];
$recentVisitEvents = [];
$recentVisitors = [];
$frequentNeighbors = [];
if ($hasVisitEvents) {
    $stmt = $conn->prepare(
        "SELECT visit_hour, COUNT(*) AS cnt
         FROM visit_events
         WHERE owner_id = ? AND visit_date >= CURDATE() - INTERVAL 6 DAY
         GROUP BY visit_hour
         ORDER BY visit_hour"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $hourRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(viewer_gender, ''), '알 수 없음') AS label, COUNT(*) AS cnt
         FROM visit_events
         WHERE owner_id = ? AND visit_date >= CURDATE() - INTERVAL 29 DAY
         GROUP BY label
         ORDER BY cnt DESC, label"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $genderRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT visit_date, visit_hour, COALESCE(NULLIF(viewer_gender, ''), '알 수 없음') AS viewer_gender, COUNT(*) AS cnt
         FROM visit_events
         WHERE owner_id = ?
         GROUP BY visit_date, visit_hour, viewer_gender
         ORDER BY visit_date DESC, visit_hour DESC
         LIMIT 40"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $recentVisitEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, MAX(ve.created_at) AS last_visit_at, COUNT(*) AS visit_count
         FROM visit_events ve
         JOIN users u ON u.id = ve.viewer_id
         WHERE ve.owner_id = ? AND ve.viewer_id IS NOT NULL AND ve.viewer_id <> ?
         GROUP BY u.id, u.nickname
         ORDER BY last_visit_at DESC
         LIMIT 5"
    );
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $recentVisitors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, COUNT(*) AS visit_count, MAX(ve.created_at) AS last_visit_at
         FROM visit_events ve
         JOIN users u ON u.id = ve.viewer_id
         JOIN neighbors n ON n.user_id = ? AND n.neighbor_id = u.id
         WHERE ve.owner_id = ? AND ve.viewer_id IS NOT NULL
         GROUP BY u.id, u.nickname
         ORDER BY visit_count DESC, last_visit_at DESC
         LIMIT 5"
    );
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $frequentNeighbors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count, p.status, p.created_at,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
            " . ($hasCommentLikes ? "(SELECT COUNT(*) FROM comment_likes cl JOIN comments cc ON cc.id = cl.comment_id WHERE cc.post_id = p.id)" : "0") . " AS comment_like_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM scraps s WHERE s.post_id = p.id) AS scrap_count
     FROM posts p
     WHERE p.user_id = ?
     ORDER BY p.view_count DESC, like_count DESC, comment_count DESC, p.created_at DESC
     LIMIT 5"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$topPosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COALESCE(c.name, '미분류') AS category_name,
            COUNT(p.id) AS post_count,
            COALESCE(SUM(p.view_count), 0) AS view_count,
            (SELECT COUNT(*) FROM likes l JOIN posts lp ON lp.id = l.post_id WHERE lp.user_id = ? AND COALESCE(lp.category_id, 0) = COALESCE(c.id, 0)) AS like_count,
            " . ($hasCommentLikes ? "(SELECT COUNT(*) FROM comment_likes cl JOIN comments cm ON cm.id = cl.comment_id JOIN posts cp ON cp.id = cm.post_id WHERE cp.user_id = ? AND COALESCE(cp.category_id, 0) = COALESCE(c.id, 0))" : "0") . " AS comment_like_count,
            (SELECT COUNT(*) FROM comments cm JOIN posts cp ON cp.id = cm.post_id WHERE cp.user_id = ? AND COALESCE(cp.category_id, 0) = COALESCE(c.id, 0)) AS comment_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.user_id = ?
     GROUP BY c.id, c.name
     ORDER BY view_count DESC, post_count DESC
     LIMIT 6"
);
if ($hasCommentLikes) {
    $stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
} else {
    $stmt->bind_param("iii", $userId, $userId, $userId);
}
$stmt->execute();
$categoryStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, COUNT(c.id) AS comment_count, MAX(c.created_at) AS last_comment_at
     FROM comments c
     JOIN posts p ON p.id = c.post_id
     JOIN users u ON u.id = c.user_id
     WHERE p.user_id = ? AND c.user_id <> ?
     GROUP BY u.id, u.nickname
     ORDER BY comment_count DESC, last_comment_at DESC
     LIMIT 5"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$topCommenters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (($_GET['export'] ?? '') === 'stats') {
    require_once __DIR__ . '/../app/excel_export.php';
    bridge206ExcelDownloadHeaders(bridge206ExcelFilename('bridge206_stats'));
    bridge206ExcelStart('BRIDGE 206 블로그 현황', '다운로드 일시: ' . date('Y-m-d H:i:s'));

    $summaryRows = [
        ['오늘 방문', ['value' => $todayCount, 'class' => 'num']],
        ['누적 방문', ['value' => $totalVisit, 'class' => 'num']],
        ['발행 글', ['value' => $publishedCount, 'class' => 'num']],
        ['임시저장', ['value' => $draftCount, 'class' => 'num']],
        ['글 조회', ['value' => $totalViews, 'class' => 'num']],
        ['공감', ['value' => $totalLikes, 'class' => 'num']],
        ['댓글 좋아요', ['value' => $totalCommentLikes, 'class' => 'num']],
        ['댓글', ['value' => $totalComments, 'class' => 'num']],
        ['스크랩', ['value' => $totalScraps, 'class' => 'num']],
        ['이웃에게 추가된 수', ['value' => $neighborCount, 'class' => 'num']],
        ['방명록', ['value' => $guestbookCount, 'class' => 'num']],
    ];

    $visitRows = [];
    foreach ($days as $d) {
        $visitRows[] = [['value' => $d['date'], 'class' => 'date'], ['value' => $d['count'], 'class' => 'num']];
    }
    bridge206ExcelTableGroup([
        ['title' => '요약', 'headers' => ['항목', '값'], 'rows' => $summaryRows, 'widths' => [210, 110]],
        ['title' => '최근 7일 방문', 'headers' => ['날짜', '방문 수'], 'rows' => $visitRows, 'widths' => [170, 110]],
    ]);

    $hourExportRows = [];
    foreach ($hourRows as $r) {
        $hourExportRows[] = [sprintf('%02d시', (int)$r['visit_hour']), ['value' => (int)$r['cnt'], 'class' => 'num']];
    }
    $genderExportRows = [];
    foreach ($genderRows as $r) {
        $genderExportRows[] = [$r['label'], ['value' => (int)$r['cnt'], 'class' => 'num']];
    }
    bridge206ExcelTableGroup([
        ['title' => '시간대별 방문', 'headers' => ['시간대', '방문 수'], 'rows' => $hourExportRows ?: [['기록 없음', ['value' => 0, 'class' => 'num']]], 'widths' => [150, 110]],
        ['title' => '성별 방문', 'headers' => ['성별', '방문 수'], 'rows' => $genderExportRows ?: [['기록 없음', ['value' => 0, 'class' => 'num']]], 'widths' => [150, 110]],
    ]);

    $postRows = [];
    foreach ($topPosts as $p) {
        $postRows[] = [
            $p['title'],
            ['value' => $p['view_count'], 'class' => 'num'],
            ['value' => $p['like_count'], 'class' => 'num'],
            ['value' => $p['comment_like_count'], 'class' => 'num'],
            ['value' => $p['comment_count'], 'class' => 'num'],
            ['value' => bridge206ExcelDate($p['created_at']), 'class' => 'date'],
        ];
    }
    bridge206ExcelTable('인기 글', ['제목', '조회', '공감', '댓글 좋아요', '댓글', '작성일'], $postRows ?: [['기록 없음', 0, 0, 0, 0, '기록 없음']], [340, 80, 80, 100, 80, 150]);

    $categoryRows = [];
    foreach ($categoryStats as $c) {
        $categoryRows[] = [
            $c['category_name'],
            ['value' => $c['post_count'], 'class' => 'num'],
            ['value' => $c['view_count'], 'class' => 'num'],
            ['value' => $c['like_count'], 'class' => 'num'],
            ['value' => $c['comment_like_count'], 'class' => 'num'],
            ['value' => $c['comment_count'], 'class' => 'num'],
        ];
    }
    bridge206ExcelTable('카테고리 성과', ['카테고리', '글', '조회', '공감', '댓글 좋아요', '댓글'], $categoryRows ?: [['기록 없음', 0, 0, 0, 0, 0]], [180, 70, 80, 80, 100, 80]);

    $commenterRows = [];
    foreach ($topCommenters as $c) {
        $commenterRows[] = [$c['nickname'], ['value' => $c['comment_count'], 'class' => 'num']];
    }
    bridge206ExcelTable('댓글 방문자', ['닉네임', '댓글 수'], $commenterRows ?: [['기록 없음', 0]], [180, 100]);

    bridge206ExcelEnd();
    exit;
}

$stmt = $conn->prepare(
    "SELECT 'comment' AS type, c.created_at, c.content AS body, p.id AS post_id, p.title, u.nickname
     FROM comments c
     JOIN posts p ON p.id = c.post_id
     JOIN users u ON u.id = c.user_id
     WHERE p.user_id = ? AND c.user_id <> ?
     ORDER BY c.created_at DESC
     LIMIT 5"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$recentReactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT 'like' AS type, l.created_at, '' AS body, p.id AS post_id, p.title, u.nickname
     FROM likes l
     JOIN posts p ON p.id = l.post_id
     JOIN users u ON u.id = l.user_id
     WHERE p.user_id = ? AND l.user_id <> ?
     ORDER BY l.created_at DESC
     LIMIT 5"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$recentReactions = array_merge($recentReactions, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();

if ($hasCommentLikes) {
    $stmt = $conn->prepare(
        "SELECT 'comment_like' AS type, cl.created_at, cm.content AS body, p.id AS post_id, p.title, u.nickname
         FROM comment_likes cl
         JOIN comments cm ON cm.id = cl.comment_id
         JOIN posts p ON p.id = cm.post_id
         JOIN users u ON u.id = cl.user_id
         WHERE p.user_id = ? AND cl.user_id <> ?
         ORDER BY cl.created_at DESC
         LIMIT 5"
    );
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $recentReactions = array_merge($recentReactions, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
}

usort($recentReactions, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$recentReactions = array_slice($recentReactions, 0, 8);

$pageTitle = '블로그 현황 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="stats dashboard">
  <div class="dashboard-hero">
    <div>
      <span>작성자 전용</span>
      <h1>블로그 현황</h1>
      <p>내 글이 얼마나 읽히고, 어떤 반응이 쌓였는지 한 번에 확인합니다.</p>
    </div>
    <nav>
      <a href="blog.php?id=<?= $userId ?>">내 블로그</a>
      <a href="activity.php">내 활동</a>
      <a href="comments_manage.php">댓글 관리</a>
      <a href="write.php">글쓰기</a>
      <a href="stats.php?export=stats">엑셀 다운로드</a>
    </nav>
  </div>

  <div class="blog-ops" aria-label="블로그 운영 현황">
    <section class="blog-ops__group blog-ops__group--focus">
      <div class="blog-ops__head">
        <h2>오늘 확인</h2>
        <span>새 반응과 방문</span>
      </div>
      <div class="blog-ops__list">
        <a class="blog-ops__metric is-primary" href="#visits">
          <strong><?= number_format($todayCount) ?></strong>
          <span>오늘 방문</span>
          <em>최근 7일 흐름 보기</em>
        </a>
        <a class="blog-ops__metric" href="#reactions">
          <strong><?= number_format($newComments + $newLikes + $newCommentLikes) ?></strong>
          <span>새 반응</span>
          <em>댓글 <?= number_format($newComments) ?> · 공감 <?= number_format($newLikes) ?> · 댓글 좋아요 <?= number_format($newCommentLikes) ?></em>
        </a>
        <a class="blog-ops__metric" href="blog.php?id=<?= $userId ?>&status=draft">
          <strong><?= number_format($draftCount) ?></strong>
          <span>임시저장</span>
          <em>이어 쓸 글 확인</em>
        </a>
      </div>
    </section>

    <section class="blog-ops__group">
      <div class="blog-ops__head">
        <h2>콘텐츠</h2>
        <span>글 운영 상태</span>
      </div>
      <div class="blog-ops__list">
        <a class="blog-ops__metric" href="#top-posts">
          <strong><?= number_format($publishedCount) ?></strong>
          <span>발행 글</span>
          <em>조회 <?= number_format($totalViews) ?></em>
        </a>
        <a class="blog-ops__metric" href="#categories">
          <strong><?= number_format(count($categoryStats)) ?></strong>
          <span>성과 카테고리</span>
          <em>주제별 반응 비교</em>
        </a>
      </div>
    </section>

    <section class="blog-ops__group">
      <div class="blog-ops__head">
        <h2>반응</h2>
        <span>독자 참여</span>
      </div>
      <div class="blog-ops__list">
        <a class="blog-ops__metric" href="#reactions">
          <strong><?= number_format($totalLikes + $totalCommentLikes + $totalComments + $totalScraps) ?></strong>
          <span>전체 반응</span>
          <em>공감 <?= number_format($totalLikes) ?> · 댓글 좋아요 <?= number_format($totalCommentLikes) ?> · 댓글 <?= number_format($totalComments) ?> · 스크랩 <?= number_format($totalScraps) ?></em>
        </a>
        <a class="blog-ops__metric" href="#regulars">
          <strong><?= number_format($neighborCount) ?></strong>
          <span>나를 추가한 이웃</span>
          <em>방명록 <?= number_format($guestbookCount) ?></em>
        </a>
      </div>
    </section>

    <section class="blog-ops__group">
      <div class="blog-ops__head">
        <h2>방문자</h2>
        <span>관계 흐름</span>
      </div>
      <div class="blog-ops__list">
        <a class="blog-ops__metric" href="#visitors">
          <strong><?= number_format($totalVisit) ?></strong>
          <span>누적 방문</span>
          <em>최근 방문자 <?= number_format(count($recentVisitors)) ?>명</em>
        </a>
        <a class="blog-ops__metric" href="#regulars">
          <strong><?= number_format(count($frequentNeighbors)) ?></strong>
          <span>자주 오는 이웃</span>
          <em>관계 관리 참고</em>
        </a>
      </div>
    </section>
  </div>

  <div class="dashboard-grid">
    <section class="dashboard-panel dashboard-panel--wide" id="visits">
      <div class="dashboard-panel__head">
        <h2>최근 7일 방문</h2>
        <span>오늘 기준</span>
      </div>
      <div class="stats-chart">
        <?php foreach ($days as $d): ?>
          <div class="stats-bar">
            <div class="stats-bar__count"><?= number_format($d['count']) ?></div>
            <div class="stats-bar__fill" style="height: <?= round($d['count'] / $maxCount * 100) ?>%"></div>
            <div class="stats-bar__label"><?= date('n/j', strtotime($d['date'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>시간대별 방문</h2>
        <span>최근 7일</span>
      </div>
      <?php if ($hourRows): ?>
        <div class="mini-bars">
          <?php
            $hourMap = [];
            foreach ($hourRows as $r) $hourMap[(int)$r['visit_hour']] = (int)$r['cnt'];
            $hourMax = max(1, max($hourMap));
            for ($h = 0; $h < 24; $h += 3):
              $sum = 0;
              for ($j = $h; $j < $h + 3; $j++) $sum += $hourMap[$j] ?? 0;
          ?>
            <div>
              <span><?= sprintf('%02d', $h) ?>시</span>
              <b style="width: <?= round($sum / $hourMax * 100) ?>%"></b>
              <em><?= number_format($sum) ?></em>
            </div>
          <?php endfor; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">방문 이벤트 기록이 아직 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>성별 방문</h2>
        <span>최근 30일</span>
      </div>
      <?php if ($genderRows): ?>
        <div class="dashboard-reaction-counts">
          <?php foreach ($genderRows as $g): ?>
            <a href="#"><strong><?= number_format((int)$g['cnt']) ?></strong><span><?= htmlspecialchars($g['label']) ?></span></a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">성별 통계가 아직 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="reactions">
      <div class="dashboard-panel__head">
        <h2>받은 반응</h2>
        <span>내 글 기준</span>
      </div>
      <div class="dashboard-reaction-counts">
        <a href="notifications.php"><strong><?= number_format($newComments) ?></strong><span>댓글</span></a>
        <a href="notifications.php"><strong><?= number_format($newLikes) ?></strong><span>공감</span></a>
        <?php if ($hasCommentLikes): ?>
          <a href="notifications.php?type=comment_like"><strong><?= number_format($newCommentLikes) ?></strong><span>댓글 좋아요</span></a>
        <?php endif; ?>
      </div>
      <p class="dashboard-note">새로 들어온 알림은 소식 화면에서 따로 확인할 수 있어요.</p>
    </section>

    <section class="dashboard-panel" id="top-posts">
      <div class="dashboard-panel__head">
        <h2>인기 글</h2>
        <span>조회순</span>
      </div>
      <?php if ($topPosts): ?>
        <div class="top-posts-visual">
          <?php foreach ($topPosts as $i => $p): ?>
            <a href="view.php?id=<?= (int)$p['id'] ?>">
              <b><?= $i + 1 ?></b>
              <span>
                <strong><?= htmlspecialchars($p['title']) ?></strong>
                <em>조회 <?= number_format($p['view_count']) ?> · 공감 <?= number_format($p['like_count']) ?> · 댓글 좋아요 <?= number_format($p['comment_like_count']) ?> · 댓글 <?= number_format($p['comment_count']) ?> · 스크랩 <?= number_format($p['scrap_count']) ?></em>
              </span>
              <i style="width: <?= round(((int)$p['view_count']) / max(1, (int)$topPosts[0]['view_count']) * 100) ?>%"></i>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">아직 작성한 글이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>최근 반응</h2>
        <span>댓글·공감</span>
      </div>
      <?php if ($recentReactions): ?>
        <div class="dashboard-feed">
          <?php foreach ($recentReactions as $r): ?>
            <a href="view.php?id=<?= (int)$r['post_id'] ?>">
              <strong><?= htmlspecialchars($r['nickname']) ?>님이 <?= $r['type'] === 'like' ? '공감했어요' : ($r['type'] === 'comment_like' ? '댓글을 좋아했어요' : '댓글을 남겼어요') ?></strong>
              <span><?= htmlspecialchars($r['title']) ?></span>
              <?php if ($r['body'] !== ''): ?>
                <em><?= htmlspecialchars(mb_strimwidth($r['body'], 0, 70, '...')) ?></em>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">아직 받은 반응이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="visitors">
      <div class="dashboard-panel__head">
        <h2>최근 방문자</h2>
        <span>회원 방문</span>
      </div>
      <?php if ($recentVisitors): ?>
        <div class="dashboard-feed">
          <?php foreach ($recentVisitors as $visitor): ?>
            <a href="blog.php?id=<?= (int)$visitor['id'] ?>">
              <strong><?= htmlspecialchars($visitor['nickname']) ?>님</strong>
              <span>최근 <?= date('Y.m.d H:i', strtotime($visitor['last_visit_at'])) ?></span>
              <em>방문 <?= number_format((int)$visitor['visit_count']) ?>회</em>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">최근 회원 방문 기록이 아직 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="regulars">
      <div class="dashboard-panel__head">
        <h2>자주 오는 이웃</h2>
        <span>내 이웃 기준</span>
      </div>
      <?php if ($frequentNeighbors): ?>
        <div class="dashboard-list">
          <?php foreach ($frequentNeighbors as $i => $neighbor): ?>
            <a href="blog.php?id=<?= (int)$neighbor['id'] ?>">
              <b><?= $i + 1 ?></b>
              <span>
                <strong><?= htmlspecialchars($neighbor['nickname']) ?>님</strong>
                <em>방문 <?= number_format((int)$neighbor['visit_count']) ?>회 · 최근 <?= date('m.d H:i', strtotime($neighbor['last_visit_at'])) ?></em>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">아직 자주 오는 이웃 기록이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="categories">
      <div class="dashboard-panel__head">
        <h2>카테고리 성과</h2>
        <span>조회·반응 기준</span>
      </div>
      <?php if ($categoryStats): ?>
        <div class="dashboard-list">
          <?php foreach ($categoryStats as $c): ?>
            <a href="#">
              <b><?= number_format((int)$c['post_count']) ?></b>
              <span>
                <strong><?= htmlspecialchars($c['category_name']) ?></strong>
                <em>조회 <?= number_format((int)$c['view_count']) ?> · 공감 <?= number_format((int)$c['like_count']) ?> · 댓글 좋아요 <?= number_format((int)$c['comment_like_count']) ?> · 댓글 <?= number_format((int)$c['comment_count']) ?></em>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">카테고리별로 볼 글이 아직 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>댓글 단골</h2>
        <span>내 글 기준</span>
      </div>
      <?php if ($topCommenters): ?>
        <div class="dashboard-feed">
          <?php foreach ($topCommenters as $c): ?>
            <a href="blog.php?id=<?= (int)$c['id'] ?>">
              <strong><?= htmlspecialchars($c['nickname']) ?>님</strong>
              <span>댓글 <?= number_format((int)$c['comment_count']) ?>개</span>
              <em>최근 <?= date('Y.m.d H:i', strtotime($c['last_comment_at'])) ?></em>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">아직 댓글 단골이 없어요.</p>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel dashboard-panel--wide">
      <div class="dashboard-panel__head">
        <h2>최근 방문 이벤트</h2>
        <span>시간·성별 요약</span>
      </div>
      <?php if ($recentVisitEvents): ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr><th>날짜</th><th>시간대</th><th>성별</th><th>방문</th></tr></thead>
            <tbody>
              <?php foreach ($recentVisitEvents as $v): ?>
                <tr>
                  <td><?= htmlspecialchars($v['visit_date']) ?></td>
                  <td><?= sprintf('%02d:00', (int)$v['visit_hour']) ?></td>
                  <td><?= htmlspecialchars($v['viewer_gender']) ?></td>
                  <td><?= number_format((int)$v['cnt']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="dashboard-empty">아직 기록된 방문 이벤트가 없어요.</p>
      <?php endif; ?>
    </section>
  </div>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
