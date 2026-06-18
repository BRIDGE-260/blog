<?php
/**
 * write.php — 글쓰기.
 *   카테고리 선택 + 공개설정 + 태그(N:M) + 썸네일 업로드 + 임시저장/발행.
 */

session_start();
if (!isset($_SESSION['user_id'])) {        // 로그인 검사 먼저 (header 출력 전)
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/categories.php';

$userId = $_SESSION['user_id'];
$error  = '';

// 폼 값 (에러 시 다시 채우기 위해 변수로 보관)
$title      = '';
$content    = '';
$category   = '';
$visibility = 'all';
$tagInput   = '';

// ============================================================
// POST 처리 — 저장
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title']   ?? '');
    $content    = trim($_POST['content'] ?? '');
    $category   = $_POST['category']     ?? '';      // 고정 목록의 카테고리 이름('' = 선택 안 함)
    $visibility = $_POST['visibility']   ?? 'all';
    $tagInput   = trim($_POST['tags']    ?? '');
    $status     = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    // 값 검증
    if (!in_array($visibility, ['all', 'neighbor', 'private'], true)) {
        $visibility = 'all';
    }
    if ($title === '' || $content === '') {
        $error = '제목과 내용을 입력해주세요.';
    }

    // 썸네일 업로드 처리 (선택) — 원본명 + 고유 저장명 분리
    $thumbOriginal = null;
    $thumbStored   = null;
    if ($error === '' && isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext     = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $error = '이미지는 jpg, png, gif, webp 만 올릴 수 있어요.';
        } else {
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $thumbOriginal = basename($_FILES['thumbnail']['name']);          // 원래 파일명
            $thumbStored   = uniqid('thumb_', true) . '.' . $ext;             // 디스크 저장명(고유)
            if (!move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . '/' . $thumbStored)) {
                $error = '이미지 업로드에 실패했어요.';
            }
        }
    }

    // 검증 통과 → 글 INSERT + 태그 연결
    if ($error === '') {
        // 고른 주제를 이 유저의 카테고리로 보장(없으면 생성) → id. 선택 안 함이면 NULL
        $catParam = in_array($category, $FIXED_CATEGORIES, true)
            ? ensureCategory($conn, $userId, $category)
            : null;

        $stmt = $conn->prepare(
            "INSERT INTO posts
               (user_id, category_id, title, content, thumbnail_original, thumbnail_stored, visibility, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "iissssss",
            $userId, $catParam, $title, $content, $thumbOriginal, $thumbStored, $visibility, $status
        );
        $stmt->execute();
        $postId = $conn->insert_id;
        $stmt->close();

        // ── 태그 처리: "#JPOP #시티팝" → 공백 분리 → 있으면 재사용 / 없으면 INSERT ──
        $tagNames = preg_split('/\s+/', $tagInput, -1, PREG_SPLIT_NO_EMPTY);
        $done = [];   // 같은 글에 중복 태그 방지용
        foreach ($tagNames as $raw) {
            $name = trim(ltrim($raw, '#'));     // 앞 # 와 공백 제거
            if ($name === '' || isset($done[$name])) continue;
            $done[$name] = true;

            // 이미 있는 태그면 그 id, 없으면 새로 INSERT
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

            // 글-태그 연결 (PK 중복은 IGNORE 로 무시)
            $stmt = $conn->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $postId, $tagId);
            $stmt->execute();
            $stmt->close();
        }

        // 본문 이미지 여러 장 업로드 → post_images 에 저장
        if (!empty($_FILES['images']['name'][0])) {
            $imgAllowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $uploadDir  = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $order = 0;
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

        // 임시저장이면 내 블로그 임시저장 탭으로, 발행이면 그 글로 이동
        header('Location: ' . ($status === 'draft'
            ? 'blog.php?id=' . $userId . '&status=draft'
            : 'view.php?id=' . $postId));
        exit;
    }
}

$pageTitle = '글쓰기 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="write">
  <h1>글쓰기</h1>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form class="write-form" method="post" action="write.php" enctype="multipart/form-data">
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
      <span>썸네일 (선택)</span>
      <input type="file" name="thumbnail" accept="image/*">
    </label>

    <label class="wf-field">
      <span>본문 이미지 (여러 장 선택 가능)</span>
      <input type="file" name="images[]" accept="image/*" multiple>
    </label>

    <div class="wf-actions">
      <button type="submit" name="status" value="draft"     class="btn-ghost-dark">임시저장</button>
      <button type="submit" name="status" value="published" class="btn-primary">발행</button>
    </div>
  </form>
</section>

<script src="taginput.js"></script>

<?php require_once __DIR__ . '/footer.php'; ?>
