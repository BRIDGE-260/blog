<?php
/**
 * stats.php — 내 블로그 방문자 통계.
 *   visit_logs 에서 최근 7일 방문 수를 막대그래프로 + 오늘/전체 합계.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];

// 최근 7일 방문 수 (기록 있는 날만 옴 → 아래에서 빈 날 0으로 채움)
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

// 오늘 포함 최근 7일 배열 만들기 (빈 날은 0)
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['date' => $d, 'count' => $map[$d] ?? 0];
}
$maxCount  = max(1, max(array_column($days, 'count')));   // 막대 높이 환산용(0 나눗셈 방지)
$todayCount = end($days)['count'];

// 전체 누적 방문
$stmt = $conn->prepare("SELECT COALESCE(SUM(count), 0) AS total FROM visit_logs WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$totalVisit = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$pageTitle = '방문자 통계 · MyBlog';
require_once __DIR__ . '/../app/header.php';
?>

<section class="stats">
  <h1>방문자 통계</h1>

  <div class="stats-sum">
    <div class="stats-sum__box"><span><?= $todayCount ?></span>오늘</div>
    <div class="stats-sum__box"><span><?= $totalVisit ?></span>전체 누적</div>
  </div>

  <h2 class="sec-title">최근 7일</h2>
  <div class="stats-chart">
    <?php foreach ($days as $d): ?>
      <div class="stats-bar">
        <div class="stats-bar__count"><?= $d['count'] ?></div>
        <div class="stats-bar__fill" style="height: <?= round($d['count'] / $maxCount * 100) ?>%"></div>
        <div class="stats-bar__label"><?= date('n/j', strtotime($d['date'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

