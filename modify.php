<?php
/**
 * modify.php — 글 수정 (본인 글만).
 *   write.php 와 거의 같되, 기존 값 미리채움 + 썸네일 교체/제거 + 태그 재동기화.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categories.php';

$userId = $_SESSION['user_id'];
$postId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// 글 조회 + 소유권 확인 (남의 글이면 차단)
$stmt = $conn->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$post || $post['user_id'] != $userId) {
    header('Location: index.php');
    exit;
}

$error = '';

// 기본값 = 기존 글 값
$title      = $post['title'];
$content    = $post['content'];
$visibility = $post['visibility'];

// 현재 글의 카테고리 이름 (없으면 '')
$category = '';
if (!empty($post['category_id'])) {
    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $post['category_id']);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc()['name'] ?? '';
    $stmt->close();
}

// 기존 태그 → "#태그 #태그" 문자열로 복원
$stmt = $conn->prepare("SELECT t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ?");
$stmt->bind_param("i", $postId);
$stmt->execute();
$tagNames = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
$stmt->close();
$tagInput = $tagNames ? '#' . implode(' #', $tagNames) : '';

// 기존 본문 이미지 (수정 폼에서 개별 제거 / 추가)
$stmt = $conn->prepare("SELECT id, stored, original FROM post_images WHERE post_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $postId);
$stmt->execute();
$postImages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ============================================================
// POST 처리 — 수정 저장
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title']   ?? '');
    $content    = trim($_POST['content'] ?? '');
    $category   = $_POST['category']     ?? '';
    $visibility = $_POST['visibility']   ?? 'all';
    $tagInput   = trim($_POST['tags']    ?? '');
    $status     = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    if (!in_array($visibility, ['all', 'neighbor', 'private'], true)) $visibility = 'all';
    if ($title === '' || $content === '') $error = '제목과 내용을 입력해주세요.';

    // 썸네일 최종값 = 기존 값에서 시작
    $thumbOriginal = $post['thumbnail_original'];
    $thumbStored   = $post['thumbnail_stored'];
    $uploadDir     = __DIR__ . '/uploads';

    if ($error === '') {
        // 1) 제거 체크 시 기존 파일 삭제 후 NULL
        if (!empty($_POST['remove_thumb']) && $thumbStored) {
            @unlink($uploadDir . '/' . $thumbStored);
            $thumbOriginal = null;
            $thumbStored   = null;
        }
        // 2) 새 파일 업로드 시 교체 (기존 파일 삭제)
        elseif (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext     = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $error = '이미지는 jpg, png, gif, webp 만 올릴 수 있어요.';
            } else {
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $newStored = uniqid('thumb_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . '/' . $newStored)) {
                    if ($thumbStored) @unlink($uploadDir . '/' . $thumbStored);
                    $thumbOriginal = basename($_FILES['thumbnail']['name']);
                    $thumbStored   = $newStored;
                } else {
                    $error = '이미지 업로드에 실패했어요.';
                }
            }
        }
    }

    // 검증 통과 → UPDATE + 태그 재동기화
    if ($error === '') {
        $catParam = in_array($category, $FIXED_CATEGORIES, true)
            ? ensureCategory($conn, $userId, $category)
            : null;

        $stmt = $conn->prepare(
            "UPDATE posts
               SET category_id = ?, title = ?, content = ?,
                   thumbnail_original = ?, thumbnail_stored = ?,
                   visibility = ?, status = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param(
            "issssssii",
            $catParam, $title, $content, $thumbOriginal, $thumbStored,
            $visibility, $status, $postId, $userId
        );
        $stmt->execute();
        $stmt->close();

        // 태그 재동기화: 기존 연결 모두 지우고 다시 연결
        $stmt = $conn->prepare("DELETE FROM post_tags WHERE post_id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();

        $names = preg_split('/\s+/', $tagInput, -1, PREG_SPLIT_NO_EMPTY);
        $done = [];
        foreach ($names as $raw) {
            $name = trim(ltrim($raw, '#'));
            if ($name === '' || isset($done[$name])) continue;
            $done[$name] = true;

            $stmt = $conn->prepare("SELECT id FROM tags WHERE name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $tagId = $row['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO tags (name) VALUES (?)");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $tagId = $conn->insert_id;
                $stmt->close();
            }

            $stmt = $conn->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $postId, $tagId);
            $stmt->execute();
            $stmt->close();
        }

        // 본문 이미지 — 체크된 기존 이미지 제거 (파일 삭제 후 row 삭제)
        if (!empty($_POST['remove_images']) && is_array($_POST['remove_images'])) {
            foreach ($_POST['remove_images'] as $imgId) {
                $imgId = (int)$imgId;
                $stmt = $conn->prepare("SELECT stored FROM post_images WHERE id = ? AND post_id = ?");
                $stmt->bind_param("ii", $imgId, $postId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    @unlink($uploadDir . '/' . $row['stored']);
                    $stmt = $conn->prepare("DELETE FROM post_images WHERE id = ? AND post_id = ?");
                    $stmt->bind_param("ii", $imgId, $postId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // 본문 이미지 — 새로 올린 이미지 추가 (write.php 업로드 루프와 동일)
        if (!empty($_FILES['images']['name'][0])) {
            $imgAllowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            // 기존 마지막 정렬값 다음부터 이어붙임
            $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 AS nextord FROM post_images WHERE post_id = ?");
            $stmt->bind_param("i", $postId);
            $stmt->execute();
            $order = (int)$stmt->get_result()->fetch_assoc()['nextord'];
            $stmt->close();
            for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $imgAllowed, true)) continue;
                $stored = uniqid('img_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir . '/' . $stored)) {
                    $orig = basename($_FILES['images']['name'][$i]);
                    $stmt = $conn->prepare("INSERT INTO post_images (post_id, original, stored, sort_order) VALUES (?,?,?,?)");
                    $stmt->bind_param("issi", $postId, $orig, $stored, $order);
                    $stmt->execute(); $stmt->close();
                    $order++;
                }
            }
        }

        header('Location: view.php?id=' . $postId);
        exit;
    }
}

$pageTitle = '글 수정 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="write">
  <h1>글 수정</h1>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form class="write-form" method="post" action="modify.php" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= (int)$postId ?>">
    <input class="wf-title" type="text" name="title" placeholder="제목"
           value="<?= htmlspecialchars($title) ?>" required>

    <div class="wf-row">
      <label>
        <span>카테고리</span>
        <select name="category">
          <option value="">선택 안 함</option>
          <?php foreach ($FIXED_CATEGORIES as $cn): ?>
            <option value="<?= htmlspecialchars($cn) ?>" <?= $category === $cn ? 'selected' : '' ?>>
              <?= htmlspecialchars($cn) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <span>공개 설정</span>
        <select name="visibility">
          <option value="all"      <?= $visibility === 'all'      ? 'selected' : '' ?>>전체 공개</option>
          <option value="neighbor" <?= $visibility === 'neighbor' ? 'selected' : '' ?>>이웃 공개</option>
          <option value="private"  <?= $visibility === 'private'  ? 'selected' : '' ?>>비공개</option>
        </select>
      </label>
    </div>

    <textarea class="wf-content" name="content" rows="14" placeholder="내용을 입력하세요" required><?= htmlspecialchars($content) ?></textarea>

    <div class="wf-field">
      <span>태그 (입력 후 Enter)</span>
      <div class="taginput">
        <input type="text" class="taginput__field" placeholder="예: JPOP 시티팝">
        <input type="hidden" name="tags" value="<?= htmlspecialchars($tagInput) ?>">
      </div>
    </div>

    <label class="wf-field">
      <span>썸네일 (선택 — 새로 올리면 교체됨)</span>
      <input type="file" name="thumbnail" accept="image/*">
    </label>
    <?php if (!empty($post['thumbnail_stored'])): ?>
      <label class="wf-checkfield">
        <input type="checkbox" name="remove_thumb" value="1">
        <span>현재 썸네일(<?= htmlspecialchars($post['thumbnail_original']) ?>) 제거</span>
      </label>
    <?php endif; ?>

    <?php if ($postImages): ?>
      <div class="wf-field">
        <span>현재 본문 이미지 (체크하면 제거)</span>
        <div class="wf-imgedit">
          <?php foreach ($postImages as $im): ?>
            <label class="wf-imgedit__item">
              <img src="uploads/<?= htmlspecialchars($im['stored']) ?>" alt="">
              <span><input type="checkbox" name="remove_images[]" value="<?= (int)$im['id'] ?>"> 제거</span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <label class="wf-field">
      <span>본문 이미지 추가 (여러 장 선택 가능)</span>
      <input type="file" name="images[]" accept="image/*" multiple>
    </label>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="view.php?id=<?= (int)$postId ?>">취소</a>
      <button type="submit" name="status" value="draft"     class="btn-ghost-dark">임시저장</button>
      <button type="submit" name="status" value="published" class="btn-primary">수정 완료</button>
    </div>
  </form>
</section>

<script src="taginput.js"></script>

<?php require_once __DIR__ . '/footer.php'; ?>
