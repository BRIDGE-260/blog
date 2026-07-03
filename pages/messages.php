<?php
/**
 * messages.php - Neighbor messages.
 *   Users can send short private messages to neighbors and read received messages.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];
$error = '';
$ok = '';
$hasReports = false;

$messagesTableResult = $conn->query("SHOW TABLES LIKE 'messages'");
if (!$messagesTableResult || $messagesTableResult->num_rows === 0) {
    $pageTitle = '쪽지 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">쪽지 기능을 사용하려면 database/add_professor_features.sql 을 먼저 실행해주세요.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

$reportTableResult = $conn->query("SHOW TABLES LIKE 'reports'");
if ($reportTableResult && $reportTableResult->num_rows > 0) {
    $hasReports = true;
}

function saveMessageReport(mysqli $conn, int $reporterId, int $targetId, string $reason): bool {
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reporterId <= 0 || $targetId <= 0 || $reason === '') return false;
    $targetType = 'message';
    $stmt = $conn->prepare(
        "INSERT INTO reports (reporter_id, target_type, target_id, reason, status)
         VALUES (?, ?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'pending', admin_note = NULL"
    );
    $stmt->bind_param("isis", $reporterId, $targetType, $targetId, $reason);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send';

    if ($action === 'report' && $hasReports) {
        $messageId = (int)($_POST['message_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT sender_id FROM messages
             WHERE id = ? AND receiver_id = ?"
        );
        $stmt->bind_param("ii", $messageId, $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$target || (int)$target['sender_id'] === $userId) {
            $error = '신고할 쪽지를 찾을 수 없어요.';
        } elseif (saveMessageReport($conn, $userId, $messageId, $_POST['reason'] ?? '')) {
            $ok = '신고가 접수됐어요. 관리자가 확인할게요.';
        } else {
            $error = '신고 사유를 입력해주세요.';
        }
    } else {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($receiverId <= 0 || $receiverId === $userId) {
            $error = '받는 사람을 다시 선택해주세요.';
        } elseif ($content === '') {
            $error = '쪽지 내용을 입력해주세요.';
        } elseif (mb_strlen($content) > 500) {
            $error = '쪽지는 500자까지 보낼 수 있어요.';
        } else {
            $stmt = $conn->prepare(
                "SELECT 1 FROM neighbors
                 WHERE (user_id = ? AND neighbor_id = ?) OR (user_id = ? AND neighbor_id = ?)
                 LIMIT 1"
            );
            $stmt->bind_param("iiii", $userId, $receiverId, $receiverId, $userId);
            $stmt->execute();
            $canSend = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$canSend) {
                $error = '이웃 관계인 사용자에게만 쪽지를 보낼 수 있어요.';
            } else {
                $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $userId, $receiverId, $content);
                $stmt->execute();
                $stmt->close();
                $ok = '쪽지를 보냈어요.';
            }
        }
    }
}

$stmt = $conn->prepare(
    "UPDATE messages SET is_read = 1
     WHERE receiver_id = ? AND is_read = 0"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
            u.last_seen_at,
            EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = u.id AND r.neighbor_id = ?) AS mutual
     FROM neighbors n
     JOIN users u ON u.id = n.neighbor_id
     WHERE n.user_id = ?
     ORDER BY u.nickname"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$neighbors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT m.id, m.content, m.is_read, m.created_at,
            s.id AS sender_id, s.nickname AS sender_nickname,
            r.id AS receiver_id, r.nickname AS receiver_nickname
     FROM messages m
     JOIN users s ON s.id = m.sender_id
     JOIN users r ON r.id = m.receiver_id
     WHERE m.sender_id = ? OR m.receiver_id = ?
     ORDER BY m.created_at DESC, m.id DESC
     LIMIT 60"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedReceiver = (int)($_GET['to'] ?? 0);

$pageTitle = '쪽지 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="messages dashboard">
  <div class="dashboard-hero">
    <div>
      <span>Neighbor Message</span>
      <h1>쪽지</h1>
      <p>이웃에게 짧은 안부와 이야기를 개인적으로 보낼 수 있어요.</p>
    </div>
    <nav>
      <a href="neighbors.php">이웃 보기</a>
      <a href="neighbor_posts.php">이웃 새 글</a>
    </nav>
  </div>

  <?php if ($ok): ?><div class="form-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="message-grid">
    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>쪽지 쓰기</h2>
        <span>이웃에게만 전송</span>
      </div>
      <?php if (!$neighbors): ?>
        <p class="dashboard-empty">쪽지를 보내려면 먼저 이웃을 추가해주세요.</p>
      <?php else: ?>
        <form class="message-form" method="post" action="messages.php">
          <input type="hidden" name="action" value="send">
          <label>
            <span>받는 이웃</span>
            <select name="receiver_id" required>
              <option value="">선택</option>
              <?php foreach ($neighbors as $n): ?>
                <option value="<?= (int)$n['id'] ?>" <?= $selectedReceiver === (int)$n['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($n['nickname']) ?><?= (int)$n['mutual'] === 1 ? ' · 서로이웃' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>내용</span>
            <textarea name="content" rows="5" maxlength="500" required placeholder="쪽지 내용을 입력하세요."></textarea>
          </label>
          <button type="submit" class="btn-primary">보내기</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="dashboard-panel">
      <div class="dashboard-panel__head">
        <h2>접속 중인 이웃</h2>
        <span>최근 5분 기준</span>
      </div>
      <?php if (!$neighbors): ?>
        <p class="dashboard-empty">표시할 이웃이 없어요.</p>
      <?php else: ?>
        <div class="online-list">
          <?php foreach ($neighbors as $n): ?>
            <?php $online = !empty($n['last_seen_at']) && strtotime($n['last_seen_at']) >= time() - 300; ?>
            <a href="messages.php?to=<?= (int)$n['id'] ?>">
              <span class="online-dot <?= $online ? 'is-on' : '' ?>"></span>
              <strong><?= htmlspecialchars($n['nickname']) ?></strong>
              <em><?= $online ? '접속 중' : (!empty($n['last_seen_at']) ? date('m.d H:i', strtotime($n['last_seen_at'])) : '접속 기록 없음') ?></em>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <section class="dashboard-panel">
    <div class="dashboard-panel__head">
      <h2>쪽지함</h2>
      <span>최근 <?= count($messages) ?>개</span>
    </div>
    <?php if (!$messages): ?>
      <p class="dashboard-empty">아직 주고받은 쪽지가 없어요.</p>
    <?php else: ?>
      <div class="message-list">
        <?php foreach ($messages as $m): ?>
          <?php $sent = (int)$m['sender_id'] === $userId; ?>
          <article class="message-item <?= $sent ? 'is-sent' : 'is-received' ?>">
            <div>
              <strong><?= $sent ? '나 → ' . htmlspecialchars($m['receiver_nickname']) : htmlspecialchars($m['sender_nickname']) . ' → 나' ?></strong>
              <time><?= date('Y.m.d H:i', strtotime($m['created_at'])) ?></time>
            </div>
            <p><?= nl2br(htmlspecialchars($m['content'])) ?></p>
            <?php if (!$sent): ?>
              <a href="messages.php?to=<?= (int)$m['sender_id'] ?>">답장</a>
              <?php if ($hasReports): ?>
                <form method="post" action="messages.php" class="report-form" data-confirm="이 쪽지를 신고할까요?">
                  <input type="hidden" name="action" value="report">
                  <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                  <input type="text" name="reason" maxlength="255" placeholder="신고 사유" required>
                  <button type="submit">신고</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
