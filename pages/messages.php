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
                header('Location: messages.php?to=' . $receiverId);
                exit;
            }
        }
    }
}

$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
            u.last_seen_at,
            EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = u.id AND r.neighbor_id = ?) AS mutual
     FROM users u
     WHERE u.id <> ?
       AND EXISTS(
           SELECT 1 FROM neighbors n
           WHERE (n.user_id = ? AND n.neighbor_id = u.id)
              OR (n.user_id = u.id AND n.neighbor_id = ?)
       )
     ORDER BY u.nickname"
);
$stmt->bind_param("iiii", $userId, $userId, $userId, $userId);
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
     LIMIT 200"
);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedReceiver = (int)($_GET['to'] ?? 0);
$neighborIds = array_map('intval', array_column($neighbors, 'id'));
if (!in_array($selectedReceiver, $neighborIds, true)) {
    $selectedReceiver = 0;
}
if ($selectedReceiver === 0) {
    foreach ($messages as $message) {
        $partnerId = (int)$message['sender_id'] === $userId
            ? (int)$message['receiver_id']
            : (int)$message['sender_id'];
        if (in_array($partnerId, $neighborIds, true)) {
            $selectedReceiver = $partnerId;
            break;
        }
    }
}
if ($selectedReceiver === 0 && $neighbors) {
    $selectedReceiver = (int)$neighbors[0]['id'];
}

$conversationMeta = [];
foreach ($neighbors as $neighbor) {
    $conversationMeta[(int)$neighbor['id']] = [
        'latest_content' => '',
        'latest_at' => '',
        'unread_count' => 0,
    ];
}
foreach ($messages as $message) {
    $partnerId = (int)$message['sender_id'] === $userId
        ? (int)$message['receiver_id']
        : (int)$message['sender_id'];
    if (!isset($conversationMeta[$partnerId])) continue;
    if ($conversationMeta[$partnerId]['latest_at'] === '') {
        $conversationMeta[$partnerId]['latest_content'] = (string)$message['content'];
        $conversationMeta[$partnerId]['latest_at'] = (string)$message['created_at'];
    }
    if ((int)$message['receiver_id'] === $userId && (int)$message['is_read'] === 0) {
        $conversationMeta[$partnerId]['unread_count']++;
    }
}

if ($selectedReceiver > 0) {
    $stmt = $conn->prepare(
        "UPDATE messages SET is_read = 1
         WHERE receiver_id = ? AND sender_id = ? AND is_read = 0"
    );
    $stmt->bind_param("ii", $userId, $selectedReceiver);
    $stmt->execute();
    $stmt->close();
    $conversationMeta[$selectedReceiver]['unread_count'] = 0;
}

usort($neighbors, function (array $a, array $b) use ($conversationMeta): int {
    $aTime = $conversationMeta[(int)$a['id']]['latest_at'] ?? '';
    $bTime = $conversationMeta[(int)$b['id']]['latest_at'] ?? '';
    if ($aTime !== $bTime) return strcmp($bTime, $aTime);
    return strcmp($a['nickname'], $b['nickname']);
});

$selectedNeighbor = null;
foreach ($neighbors as $neighbor) {
    if ((int)$neighbor['id'] === $selectedReceiver) {
        $selectedNeighbor = $neighbor;
        break;
    }
}
$threadMessages = array_values(array_filter($messages, function (array $message) use ($userId, $selectedReceiver): bool {
    return ((int)$message['sender_id'] === $userId && (int)$message['receiver_id'] === $selectedReceiver)
        || ((int)$message['sender_id'] === $selectedReceiver && (int)$message['receiver_id'] === $userId);
}));
$threadMessages = array_reverse($threadMessages);

$pageTitle = '쪽지 · BRIDGE 206';
$pageClass = 'page--wide page--messages';
require_once __DIR__ . '/../app/header.php';
?>

<section class="chat-page">
  <header class="chat-page__head">
    <div>
      <span>NEIGHBOR CHAT</span>
      <h1>이웃 대화</h1>
      <p>온라인 여부와 관계없이 이웃에게 메시지를 남길 수 있어요.</p>
    </div>
    <a href="neighbors.php">이웃 관리</a>
  </header>

  <?php if ($ok): ?><div class="form-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="form-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="chat-shell">
    <aside class="chat-contacts" aria-label="대화 상대 목록">
      <div class="chat-contacts__head">
        <strong>대화</strong>
        <span><?= count($neighbors) ?>명</span>
      </div>
      <?php if (!$neighbors): ?>
        <div class="chat-contacts__empty">
          <p>대화할 이웃이 없어요.</p>
          <a href="neighbors.php?tab=find">이웃 찾기</a>
        </div>
      <?php else: ?>
        <nav class="chat-contact-list">
          <?php foreach ($neighbors as $neighbor): ?>
            <?php
              $neighborId = (int)$neighbor['id'];
              $meta = $conversationMeta[$neighborId];
              $online = !empty($neighbor['last_seen_at']) && strtotime($neighbor['last_seen_at']) >= time() - 300;
            ?>
            <a class="chat-contact <?= $selectedReceiver === $neighborId ? 'is-active' : '' ?>" href="messages.php?to=<?= $neighborId ?>">
              <span class="chat-avatar">
                <?php if (!empty($neighbor['profile_image_stored'])): ?>
                  <img src="../uploads/<?= htmlspecialchars($neighbor['profile_image_stored']) ?>" alt="">
                <?php else: ?>
                  <?= htmlspecialchars(mb_substr($neighbor['nickname'], 0, 1)) ?>
                <?php endif; ?>
                <i class="<?= $online ? 'is-online' : '' ?>"></i>
              </span>
              <span class="chat-contact__body">
                <strong><?= htmlspecialchars($neighbor['nickname']) ?></strong>
                <em><?= $meta['latest_content'] !== '' ? htmlspecialchars(mb_strimwidth($meta['latest_content'], 0, 34, '…')) : '대화를 시작해보세요.' ?></em>
              </span>
              <span class="chat-contact__meta">
                <?php if ($meta['latest_at'] !== ''): ?><time><?= date('m.d', strtotime($meta['latest_at'])) ?></time><?php endif; ?>
                <?php if ($meta['unread_count'] > 0): ?><b><?= $meta['unread_count'] > 99 ? '99+' : (int)$meta['unread_count'] ?></b><?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
    </aside>

    <section class="chat-room">
      <?php if (!$selectedNeighbor): ?>
        <div class="chat-room__empty">
          <strong>대화를 선택해주세요.</strong>
          <p>이웃을 선택하면 지금까지 주고받은 메시지가 표시됩니다.</p>
        </div>
      <?php else: ?>
        <?php $selectedOnline = !empty($selectedNeighbor['last_seen_at']) && strtotime($selectedNeighbor['last_seen_at']) >= time() - 300; ?>
        <header class="chat-room__head">
          <div>
            <span class="chat-avatar chat-avatar--small">
              <?php if (!empty($selectedNeighbor['profile_image_stored'])): ?>
                <img src="../uploads/<?= htmlspecialchars($selectedNeighbor['profile_image_stored']) ?>" alt="">
              <?php else: ?>
                <?= htmlspecialchars(mb_substr($selectedNeighbor['nickname'], 0, 1)) ?>
              <?php endif; ?>
              <i class="<?= $selectedOnline ? 'is-online' : '' ?>"></i>
            </span>
            <p><strong><?= htmlspecialchars($selectedNeighbor['nickname']) ?></strong><span><?= $selectedOnline ? '접속 중' : '오프라인' ?></span></p>
          </div>
          <a href="blog.php?id=<?= (int)$selectedNeighbor['id'] ?>">블로그 보기</a>
        </header>

        <div class="chat-thread" data-chat-thread>
          <?php if (!$threadMessages): ?>
            <div class="chat-thread__empty">
              <strong>첫 메시지를 보내보세요.</strong>
              <span>이 대화는 나와 <?= htmlspecialchars($selectedNeighbor['nickname']) ?>님만 볼 수 있어요.</span>
            </div>
          <?php else: ?>
            <?php foreach ($threadMessages as $message): ?>
              <?php $mine = (int)$message['sender_id'] === $userId; ?>
              <div class="chat-row <?= $mine ? 'is-mine' : 'is-theirs' ?>">
                <article class="chat-bubble">
                  <p><?= nl2br(htmlspecialchars($message['content'])) ?></p>
                  <time><?= date('m.d H:i', strtotime($message['created_at'])) ?></time>
                  <?php if (!$mine && $hasReports): ?>
                    <details class="chat-report">
                      <summary>신고</summary>
                      <form method="post" action="messages.php?to=<?= (int)$selectedNeighbor['id'] ?>" data-confirm="이 메시지를 신고할까요?">
                        <input type="hidden" name="action" value="report">
                        <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>">
                        <input type="text" name="reason" maxlength="255" placeholder="신고 사유" required>
                        <button type="submit">접수</button>
                      </form>
                    </details>
                  <?php endif; ?>
                </article>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <form class="chat-composer" method="post" action="messages.php?to=<?= (int)$selectedNeighbor['id'] ?>" data-chat-form>
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="receiver_id" value="<?= (int)$selectedNeighbor['id'] ?>">
          <textarea name="content" rows="1" maxlength="500" required autofocus placeholder="메시지를 입력하세요" aria-label="메시지 입력" data-chat-input></textarea>
          <button type="submit" aria-label="메시지 보내기">보내기</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</section>

<script>
(function () {
  var thread = document.querySelector('[data-chat-thread]');
  var input = document.querySelector('[data-chat-input]');
  var form = document.querySelector('[data-chat-form]');
  var focusKey = 'bridge206ChatRefocus';
  if (thread) thread.scrollTop = thread.scrollHeight;
  if (!input || !form) return;

  if (sessionStorage.getItem(focusKey) === '1') {
    sessionStorage.removeItem(focusKey);
    requestAnimationFrame(function () {
      input.focus({ preventScroll: true });
    });
  }

  function resizeInput() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }
  input.addEventListener('input', resizeInput);
  input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (input.value.trim() !== '') form.requestSubmit();
    }
  });
  form.addEventListener('submit', function () {
    sessionStorage.setItem(focusKey, '1');
  });
  resizeInput();
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
