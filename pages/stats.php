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
$totalComments = statOne($conn, "SELECT COUNT(*) AS cnt FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id = ?", $userId);
$totalScraps = statOne($conn, "SELECT COUNT(*) AS cnt FROM scraps s JOIN posts p ON p.id = s.post_id WHERE p.user_id = ?", $userId);
$newComments = statTwo($conn, "SELECT COUNT(*) AS cnt FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id = ? AND c.user_id <> ?", $userId, $userId);
$newLikes = statTwo($conn, "SELECT COUNT(*) AS cnt FROM likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = ? AND l.user_id <> ?", $userId, $userId);

$stmt = $conn->prepare(
    "SELECT p.id, p.title, p.view_count, p.status, p.created_at,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
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
      <a href="write.php">글쓰기</a>
    </nav>
  </div>

  <div class="stats-sum dashboard-metrics">
    <div class="stats-sum__box"><span><?= number_format($todayCount) ?></span>오늘 방문</div>
    <div class="stats-sum__box"><span><?= number_format($totalVisit) ?></span>누적 방문</div>
    <div class="stats-sum__box"><span><?= number_format($publishedCount) ?></span>발행 글</div>
    <div class="stats-sum__box"><span><?= number_format($draftCount) ?></span>임시저장</div>
    <div class="stats-sum__box"><span><?= number_format($totalViews) ?></span>글 조회</div>
    <div class="stats-sum__box"><span><?= number_format($totalLikes) ?></span>공감</div>
    <div class="stats-sum__box"><span><?= number_format($totalComments) ?></span>댓글</div>
    <div class="stats-sum__box"><span><?= number_format($totalScraps) ?></span>스크랩</div>
  </div>

  <div class="dashboard-grid">
    <section class="dashboard-panel dashboard-panel--wide">
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
        <h2>받은 반응</h2>
        <span>내 글 기준</span>
      </div>
      <div class="dashboard-reaction-counts">
        <a href="notifications.php"><strong><?= number_format($newComments) ?></strong><span>댓글</span></a>
        <a href="notifications.php"><strong><?= number_format($newLikes) ?></strong><span>공감</span></a>
      </div>
      <p class="dashboard-note">새로 들어온 알림은 소식 화면에서 따로 확인할 수 있어요.</p>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>인기 글</h2>
        <span>조회순</span>
      </div>
      <?php if ($topPosts): ?>
        <div class="dashboard-list">
          <?php foreach ($topPosts as $i => $p): ?>
            <a href="view.php?id=<?= (int)$p['id'] ?>">
              <b><?= $i + 1 ?></b>
              <span>
                <strong><?= htmlspecialchars($p['title']) ?></strong>
                <em>조회 <?= number_format($p['view_count']) ?> · 공감 <?= number_format($p['like_count']) ?> · 댓글 <?= number_format($p['comment_count']) ?> · 스크랩 <?= number_format($p['scrap_count']) ?></em>
              </span>
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
              <strong><?= htmlspecialchars($r['nickname']) ?>님이 <?= $r['type'] === 'like' ? '공감했어요' : '댓글을 남겼어요' ?></strong>
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
  </div>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
