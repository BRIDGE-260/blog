<?php
/**
 * categories_manage.php — 내 카테고리 직접 관리 (추가/이름변경/순서변경/삭제).
 *   삭제 시 그 카테고리의 글은 posts.category_id 가 FK(ON DELETE SET NULL)로 '미분류'가 됨.
 *   ※ 글쓰기 화면은 여전히 고정 주제 목록(categories.php)을 쓰므로,
 *      여기서 이름을 바꿔도 글쓰기에서 같은 주제를 다시 고르면 그 이름으로 새로 생길 수 있음.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/categories.php';   // ensureCategory()

$userId = $_SESSION['user_id'];
$error  = '';

// ── POST: 추가 / 이름변경 / 삭제 / 순서이동 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') ensureCategory($conn, $userId, $name);

    } elseif ($action === 'rename') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            // 같은 이름이 이미 있으면(다른 카테고리) 막기
            $stmt = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND id <> ?");
            $stmt->bind_param("isi", $userId, $name, $id);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dup) {
                $error = '이미 같은 이름의 카테고리가 있어요.';
            } else {
                $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->bind_param("sii", $name, $id, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'reorder') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
        if ($ids) {
            $stmt = $conn->prepare("UPDATE categories SET sort_order = ? WHERE id = ? AND user_id = ?");
            foreach ($ids as $i => $cid) {
                $stmt->bind_param("iii", $i, $cid, $userId);
                $stmt->execute();
            }
            $stmt->close();
        }

    } elseif ($action === 'up' || $action === 'down') {
        $id = (int)($_POST['id'] ?? 0);
        // 현재 순서대로 id 목록을 받아 위/아래와 자리 교환 후 sort_order 재부여
        $stmt = $conn->prepare("SELECT id FROM categories WHERE user_id = ? ORDER BY sort_order, id");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();

        $pos  = array_search($id, $ids);
        $swap = $action === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            $stmt = $conn->prepare("UPDATE categories SET sort_order = ? WHERE id = ? AND user_id = ?");
            foreach ($ids as $i => $cid) {
                $stmt->bind_param("iii", $i, $cid, $userId);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    // 에러 없으면 리다이렉트(새로고침 중복 POST 방지). 에러는 화면에 보여줘야 해서 그대로 진행.
    if ($error === '') {
        header('Location: categories_manage.php');
        exit;
    }
}

// 목록 (글 개수 포함)
$stmt = $conn->prepare(
    "SELECT c.id, c.name, c.sort_order,
            (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id) AS post_count
     FROM categories c
     WHERE c.user_id = ?
     ORDER BY c.sort_order, c.id"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$cats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = '카테고리 관리 · BRIDGE 206';
$pageClass = 'page--settings page--categories';
require_once __DIR__ . '/../app/header.php';
?>

<section class="setting">
  <h1>카테고리 관리</h1>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$cats): ?>
    <p class="empty">아직 카테고리가 없어요. 아래에서 추가해보세요.</p>
  <?php else: ?>
    <form class="cat-manage__order" method="post" action="categories_manage.php" data-cat-order-form>
      <input type="hidden" name="action" value="reorder">
      <input type="hidden" name="order" data-cat-order>
      <button type="submit" class="btn-primary" hidden data-cat-order-save>순서 저장</button>
    </form>
    <p class="cat-manage__hint">왼쪽 손잡이를 드래그해서 순서를 바꿀 수 있어요.</p>
    <ul class="cat-manage" data-cat-list>
      <?php foreach ($cats as $i => $c): ?>
        <li class="cat-manage__item" draggable="true" data-cat-id="<?= (int)$c['id'] ?>">
          <button type="button" class="cat-manage__drag" aria-label="순서 이동">☰</button>
          <form class="cat-manage__rename" method="post" action="categories_manage.php">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>" required>
            <span class="cat-manage__count">글 <?= (int)$c['post_count'] ?></span>
            <button type="submit" class="cat-manage__save">이름저장</button>
          </form>
          <div class="cat-manage__act">
            <form method="post" action="categories_manage.php">
              <input type="hidden" name="action" value="up">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
            </form>
            <form method="post" action="categories_manage.php">
              <input type="hidden" name="action" value="down">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" <?= $i === count($cats) - 1 ? 'disabled' : '' ?>>▼</button>
            </form>
            <form method="post" action="categories_manage.php"
                  data-confirm="삭제하면 이 카테고리의 글은 미분류가 됩니다. 삭제할까요?">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="cat-manage__del">삭제</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form class="cat-manage__add" method="post" action="categories_manage.php">
    <input type="hidden" name="action" value="add">
    <input type="text" name="name" placeholder="새 카테고리 이름" required>
    <button type="submit" class="btn-primary">추가</button>
  </form>
</section>

<script>
(function () {
  var list = document.querySelector('[data-cat-list]');
  var orderInput = document.querySelector('[data-cat-order]');
  var saveBtn = document.querySelector('[data-cat-order-save]');
  if (!list || !orderInput || !saveBtn) return;

  var dragging = null;

  function markChanged() {
    orderInput.value = Array.prototype.map.call(list.querySelectorAll('[data-cat-id]'), function (item) {
      return item.dataset.catId;
    }).join(',');
    saveBtn.hidden = false;
  }

  list.addEventListener('dragstart', function (e) {
    dragging = e.target.closest('[data-cat-id]');
    if (!dragging) return;
    dragging.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
  });

  list.addEventListener('dragover', function (e) {
    if (!dragging) return;
    e.preventDefault();
    var target = e.target.closest('[data-cat-id]');
    if (!target || target === dragging) return;
    var rect = target.getBoundingClientRect();
    var before = e.clientY < rect.top + rect.height / 2;
    list.insertBefore(dragging, before ? target : target.nextSibling);
  });

  list.addEventListener('dragend', function () {
    if (dragging) dragging.classList.remove('is-dragging');
    dragging = null;
    markChanged();
  });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

