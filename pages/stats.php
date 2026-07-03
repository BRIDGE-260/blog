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
            (SELECT COUNT(*) FROM comments cm JOIN posts cp ON cp.id = cm.post_id WHERE cp.user_id = ? AND COALESCE(cp.category_id, 0) = COALESCE(c.id, 0)) AS comment_count
     FROM posts p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.user_id = ?
     GROUP BY c.id, c.name
     ORDER BY view_count DESC, post_count DESC
     LIMIT 6"
);
$stmt->bind_param("iii", $userId, $userId, $userId);
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
    header('Content-Type: text/csv; charset=UTF-8');
    $csvFilename = 'bridge206_stats_' . date('Ymd') . '.csv';
    header('Content-Disposition: attachment; filename="' . $csvFilename . '"; filename*=UTF-8\'\'' . rawurlencode($csvFilename));
    header('Content-Transfer-Encoding: binary');
    echo "\xEF\xBB\xBF";
    echo "sep=,\r\n";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['구분', '항목', '값']);
    fputcsv($out, ['요약', '오늘 방문', $todayCount]);
    fputcsv($out, ['요약', '누적 방문', $totalVisit]);
    fputcsv($out, ['요약', '발행 글', $publishedCount]);
    fputcsv($out, ['요약', '임시저장', $draftCount]);
    fputcsv($out, ['요약', '글 조회', $totalViews]);
    fputcsv($out, ['요약', '공감', $totalLikes]);
    fputcsv($out, ['요약', '댓글', $totalComments]);
    fputcsv($out, ['요약', '스크랩', $totalScraps]);
    fputcsv($out, ['요약', '이웃에게 추가된 수', $neighborCount]);
    fputcsv($out, ['요약', '방명록', $guestbookCount]);
    foreach ($days as $d) {
        fputcsv($out, ['최근 7일 방문', $d['date'], $d['count']]);
    }
    foreach ($hourRows as $r) {
        fputcsv($out, ['시간대별 방문', sprintf('%02d시', (int)$r['visit_hour']), (int)$r['cnt']]);
    }
    foreach ($genderRows as $r) {
        fputcsv($out, ['성별 방문', $r['label'], (int)$r['cnt']]);
    }
    foreach ($topPosts as $p) {
        fputcsv($out, ['인기 글', $p['title'], '조회 ' . $p['view_count'] . ' / 공감 ' . $p['like_count'] . ' / 댓글 ' . $p['comment_count']]);
    }
    foreach ($categoryStats as $c) {
        fputcsv($out, ['카테고리 성과', $c['category_name'], '글 ' . $c['post_count'] . ' / 조회 ' . $c['view_count'] . ' / 공감 ' . $c['like_count'] . ' / 댓글 ' . $c['comment_count']]);
    }
    foreach ($topCommenters as $c) {
        fputcsv($out, ['댓글 방문자', $c['nickname'], $c['comment_count']]);
    }
    fclose($out);
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

  <div class="stats-sum dashboard-metrics">
    <div class="stats-sum__box"><span><?= number_format($todayCount) ?></span>오늘 방문</div>
    <div class="stats-sum__box"><span><?= number_format($totalVisit) ?></span>누적 방문</div>
    <div class="stats-sum__box"><span><?= number_format($publishedCount) ?></span>발행 글</div>
    <div class="stats-sum__box"><span><?= number_format($draftCount) ?></span>임시저장</div>
    <div class="stats-sum__box"><span><?= number_format($totalViews) ?></span>글 조회</div>
    <div class="stats-sum__box"><span><?= number_format($totalLikes) ?></span>공감</div>
    <div class="stats-sum__box"><span><?= number_format($totalComments) ?></span>댓글</div>
    <div class="stats-sum__box"><span><?= number_format($totalScraps) ?></span>스크랩</div>
    <div class="stats-sum__box"><span><?= number_format($neighborCount) ?></span>나를 추가한 이웃</div>
    <div class="stats-sum__box"><span><?= number_format($guestbookCount) ?></span>방명록</div>
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
        <div class="top-posts-visual">
          <?php foreach ($topPosts as $i => $p): ?>
            <a href="view.php?id=<?= (int)$p['id'] ?>">
              <b><?= $i + 1 ?></b>
              <span>
                <strong><?= htmlspecialchars($p['title']) ?></strong>
                <em>조회 <?= number_format($p['view_count']) ?> · 공감 <?= number_format($p['like_count']) ?> · 댓글 <?= number_format($p['comment_count']) ?> · 스크랩 <?= number_format($p['scrap_count']) ?></em>
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

    <section class="dashboard-panel">
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

    <section class="dashboard-panel">
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

    <section class="dashboard-panel">
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
                <em>조회 <?= number_format((int)$c['view_count']) ?> · 공감 <?= number_format((int)$c['like_count']) ?> · 댓글 <?= number_format((int)$c['comment_count']) ?></em>
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
