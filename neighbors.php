<?php
/**
 * neighbors.php — 이웃 목록.
 *   ① 내 이웃(내가 추가한 사람, 서로이웃 표시 + 취소)
 *   ② 나를 추가한 이웃(아직 내가 안 추가 → 추가 가능)
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';

$viewerId = $_SESSION['user_id'];

// ── POST: 이웃 추가/취소 ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)($_POST['target'] ?? 0);
    $action   = $_POST['action'] ?? '';
    if ($targetId > 0 && $targetId !== (int)$viewerId) {
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT IGNORE INTO neighbors (user_id, neighbor_id) VALUES (?, ?)");
        } elseif ($action === 'cancel') {
            $stmt = $conn->prepare("DELETE FROM neighbors WHERE user_id = ? AND neighbor_id = ?");
        }
        if (isset($stmt)) {
            $stmt->bind_param("ii", $viewerId, $targetId);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: neighbors.php');
    exit;
}

// ① 내 이웃 (서로이웃 여부 mutual 포함)
$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
            EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = u.id AND r.neighbor_id = ?) AS mutual
     FROM neighbors n
     JOIN users u ON u.id = n.neighbor_id
     WHERE n.user_id = ?
     ORDER BY u.nickname"
);
$stmt->bind_param("ii", $viewerId, $viewerId);
$stmt->execute();
$myNeighbors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ② 나를 추가했지만 내가 아직 안 추가한 사람
$stmt = $conn->prepare(
    "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored
     FROM neighbors n
     JOIN users u ON u.id = n.user_id
     WHERE n.neighbor_id = ?
       AND NOT EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = ? AND r.neighbor_id = u.id)
     ORDER BY u.nickname"
);
$stmt->bind_param("ii", $viewerId, $viewerId);
$stmt->execute();
$addedMe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '이웃 · MyBlog';
require_once __DIR__ . '/header.php';

/** 이웃 한 명을 카드로 출력하는 헬퍼 */
function neighborCard($u, $buttonAction, $buttonLabel, $buttonClass, $mutual = false) {
    $img = !empty($u['profile_image_stored'])
        ? '<img src="uploads/' . htmlspecialchars($u['profile_image_stored']) . '" alt="">'
        : '<span>' . htmlspecialchars(mb_substr($u['nickname'], 0, 1)) . '</span>';
    $title = $u['blog_title'] ?: $u['nickname'] . '님의 블로그';
    ?>
    <div class="nbr">
      <a class="nbr__main" href="blog.php?id=<?= (int)$u['id'] ?>">
        <div class="nbr__img"><?= $img ?></div>
        <div>
          <div class="nbr__title"><?= htmlspecialchars($title) ?>
            <?php if ($mutual): ?><em class="nbr__mutual">서로이웃</em><?php endif; ?>
          </div>
          <div class="nbr__nick"><?= htmlspecialchars($u['nickname']) ?></div>
        </div>
      </a>
      <form method="post" action="neighbors.php">
        <input type="hidden" name="target" value="<?= (int)$u['id'] ?>">
        <input type="hidden" name="action" value="<?= $buttonAction ?>">
        <button type="submit" class="<?= $buttonClass ?>"><?= $buttonLabel ?></button>
      </form>
    </div>
    <?php
}
?>

<section class="nbr-sec">
  <h1>이웃 <?= count($myNeighbors) ?></h1>
  <?php if (!$myNeighbors): ?>
    <p class="empty">아직 추가한 이웃이 없어요. 다른 블로그에서 "이웃 추가"를 눌러보세요.</p>
  <?php else: ?>
    <div class="nbr-list">
      <?php foreach ($myNeighbors as $u):
          neighborCard($u, 'cancel', '이웃 취소', 'btn-ghost-dark', $u['mutual']);
      endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($addedMe): ?>
  <section class="nbr-sec">
    <h2 class="sec-title">나를 추가한 이웃</h2>
    <div class="nbr-list">
      <?php foreach ($addedMe as $u):
          neighborCard($u, 'add', '이웃 추가', 'btn-primary');
      endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
