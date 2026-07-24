<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/points.php';

$userId = (int)$_SESSION['user_id'];
bridge_daily_visit_points($conn, $userId);
$spinResult = null;
$pointNotice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'spin') {
        $spinResult = bridge_spin_roulette($conn, $userId);
    } elseif ($action === 'buy_badge') {
        $pointNotice = bridge_buy_badge($conn, $userId, (string)($_POST['badge_code'] ?? ''));
    } elseif ($action === 'equip_badge') {
        $pointNotice = bridge_equip_badge($conn, $userId, (string)($_POST['badge_code'] ?? ''));
    }
}

$balance = bridge_point_balance($conn, $userId);
$today = date('Y-m-d');
$stmt = $conn->prepare("SELECT reward FROM roulette_spins WHERE user_id = ? AND spin_date = ?");
$stmt->bind_param("is", $userId, $today);
$stmt->execute();
$todaySpin = $stmt->get_result()->fetch_assoc();
$stmt->close();
$rouletteAngles = [0 => 150, 5 => 330, 10 => 270, 20 => 210, 50 => 90];
$rouletteAngle = $spinResult && $spinResult['ok']
    ? 1440 + ($rouletteAngles[(int)$spinResult['reward']] ?? 0)
    : 1440;

$stmt = $conn->prepare(
    "SELECT amount, action_type, description, created_at
     FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 30"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$badges = bridge_point_badges();
$stmt = $conn->prepare("SELECT badge_code, is_equipped FROM user_point_badges WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$ownedBadges = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $owned) {
    $ownedBadges[$owned['badge_code']] = (int)$owned['is_equipped'] === 1;
}
$stmt->close();

$pageTitle = '포인트 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="point-page">
  <?php if ($pointNotice): ?>
    <div class="point-notice <?= $pointNotice['ok'] ? 'is-ok' : 'is-error' ?>"><?= htmlspecialchars($pointNotice['message']) ?></div>
  <?php endif; ?>
  <header class="point-hero">
    <div>
      <span class="point-kicker">BRIDGE POINT</span>
      <h1>활동으로 모으고<br>매일 한 번 즐겨보세요.</h1>
      <p>글과 댓글로 서로의 이야기를 연결하면 포인트가 쌓입니다.</p>
    </div>
    <div class="point-balance">
      <span>보유 포인트</span>
      <strong><?= number_format($balance) ?><small>P</small></strong>
      <em>오늘 첫 방문 +2P 자동 적립</em>
    </div>
  </header>

  <div class="point-layout">
    <section class="roulette-card">
      <div class="point-section-head">
        <div><span>DAILY EVENT</span><h2>오늘의 포인트 룰렛</h2></div>
        <b>하루 1회</b>
      </div>
      <div class="roulette-stage <?= $spinResult && $spinResult['ok'] ? 'is-spinning' : '' ?>" style="--roulette-angle: <?= (int)$rouletteAngle ?>deg">
        <div class="roulette-pointer">▼</div>
        <div class="roulette-wheel" aria-label="5, 10, 20, 0, 50, 10 포인트 룰렛">
          <span class="r1">5P</span><span class="r2">10P</span><span class="r3">20P</span>
          <span class="r4">0P</span><span class="r5">50P</span><span class="r6">10P</span>
        </div>
      </div>
      <?php if ($spinResult): ?>
        <p class="roulette-result <?= $spinResult['ok'] ? 'is-win' : '' ?>"><?= htmlspecialchars($spinResult['message']) ?></p>
      <?php elseif ($todaySpin): ?>
        <p class="roulette-result">오늘 받은 포인트는 <?= number_format((int)$todaySpin['reward']) ?>P입니다.</p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="spin">
        <button class="btn-primary roulette-button" type="submit" <?= $todaySpin ? 'disabled' : '' ?>>
          <?= $todaySpin ? '오늘 참여 완료' : '룰렛 돌리기' ?>
        </button>
      </form>
    </section>

    <section class="point-rules">
      <div class="point-section-head"><div><span>HOW TO EARN</span><h2>포인트 모으는 방법</h2></div></div>
      <div class="point-rule-list">
        <article><i>01</i><div><strong>매일 첫 방문</strong><span>하루 한 번 자동 적립</span></div><b>+2P</b></article>
        <article><i>02</i><div><strong>글 발행</strong><span>새 이야기를 공개하면 적립</span></div><b>+10P</b></article>
        <article><i>03</i><div><strong>댓글 작성</strong><span>대화에 참여하면 적립</span></div><b>+3P</b></article>
        <article><i>04</i><div><strong>내 글 공감받기</strong><span>사용자 한 명당 한 번 적립</span></div><b>+1P</b></article>
      </div>
    </section>
  </div>

  <section class="point-shop">
    <div class="point-section-head">
      <div><span>POINT SHOP</span><h2>포인트로 프로필 배지 꾸미기</h2></div>
      <b>보유 <?= number_format($balance) ?>P</b>
    </div>
    <div class="point-shop-grid">
      <?php foreach ($badges as $code => $badge): ?>
        <?php $isOwned = array_key_exists($code, $ownedBadges); $isEquipped = $isOwned && $ownedBadges[$code]; ?>
        <article class="point-shop-item <?= $isEquipped ? 'is-equipped' : '' ?>">
          <span class="point-badge point-badge--<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($badge['label']) ?></span>
          <h3><?= htmlspecialchars($badge['name']) ?></h3>
          <p><?= htmlspecialchars($badge['description']) ?></p>
          <div>
            <strong><?= number_format($badge['cost']) ?>P</strong>
            <?php if ($isEquipped): ?>
              <button type="button" disabled>장착 중</button>
            <?php elseif ($isOwned): ?>
              <form method="post"><input type="hidden" name="action" value="equip_badge"><input type="hidden" name="badge_code" value="<?= htmlspecialchars($code) ?>"><button type="submit">장착하기</button></form>
            <?php else: ?>
              <form method="post"><input type="hidden" name="action" value="buy_badge"><input type="hidden" name="badge_code" value="<?= htmlspecialchars($code) ?>"><button type="submit">구매하기</button></form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="point-history">
    <div class="point-section-head"><div><span>HISTORY</span><h2>최근 포인트 내역</h2></div></div>
    <?php if (!$transactions): ?>
      <p class="dashboard-empty">아직 포인트 내역이 없어요.</p>
    <?php else: ?>
      <div class="point-history-list">
        <?php foreach ($transactions as $tx): ?>
          <div>
            <span class="point-history-icon"><?= (int)$tx['amount'] >= 0 ? '+' : '−' ?></span>
            <p><strong><?= htmlspecialchars($tx['description']) ?></strong><small><?= date('Y.m.d H:i', strtotime($tx['created_at'])) ?></small></p>
            <b class="<?= (int)$tx['amount'] >= 0 ? 'is-plus' : 'is-minus' ?>"><?= (int)$tx['amount'] > 0 ? '+' : '' ?><?= number_format((int)$tx['amount']) ?>P</b>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
