<?php
/**
 * write.php — 글쓰기.
 *   카테고리 선택 + 공개설정 + 태그(N:M) + 본문 이미지 삽입(토큰) + 임시저장/발행.
 *   본문 중간 이미지: 업로드한 이미지를 미리보기에서 클릭하면 커서 위치에 [[img:newK]] 토큰 삽입.
 *   저장 시 그 토큰을 실제 post_images.id 로 치환([[img:id]]) → view 에서 그 자리에 이미지로 렌더.
 */

session_start();
if (!isset($_SESSION['user_id'])) {        // 로그인 검사 먼저 (header 출력 전)
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/categories.php';

$userId = $_SESSION['user_id'];
$error  = '';

// 폼 값 (에러 시 다시 채우기 위해 변수로 보관)
$title      = '';
$content    = '';
$category   = '';
$visibility = 'all';
$tagInput   = '';
$isPinned   = 0;

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
    $isPinned   = isset($_POST['is_pinned']) ? 1 : 0;

    // 값 검증
    if (!in_array($visibility, ['all', 'neighbor', 'private'], true)) {
        $visibility = 'all';
    }
    if ($title === '' || $content === '') {
        $error = '제목과 내용을 입력해주세요.';
    }

    // 검증 통과 → 글 INSERT + 태그 연결
    if ($error === '') {
        // 고른 주제를 이 유저의 카테고리로 보장(없으면 생성) → id. 선택 안 함이면 NULL
        $catParam = in_array($category, $FIXED_CATEGORIES, true)
            ? ensureCategory($conn, $userId, $category)
            : null;

        $stmt = $conn->prepare(
            "INSERT INTO posts
               (user_id, category_id, title, content, visibility, status, is_pinned)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "iissssi",
            $userId, $catParam, $title, $content, $visibility, $status, $isPinned
        );
        $stmt->execute();
        $postId = $conn->insert_id;
        $stmt->close();

        // ── 태그 처리: "#JPOP #시티팝" → 공백 분리 → 있으면 재사용 / 없으면 INSERT ──
        $tagNames = preg_split('/\s+/', $tagInput, -1, PREG_SPLIT_NO_EMPTY);
        $done = [];   // 같은 글에 중복 태그 방지용(대소문자 무시)
        foreach ($tagNames as $raw) {
            $name = trim(ltrim($raw, '#'));     // 앞 # 와 공백 제거
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if ($name === '' || isset($done[$normalizedName])) continue;
            $done[$normalizedName] = true;

            // 이미 있는 태그면 그 id, 없으면 새로 INSERT
            $stmt = $conn->prepare("SELECT id FROM tags WHERE normalized_name = ?");
            $stmt->bind_param("s", $normalizedName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $tagId = $row['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO tags (name, normalized_name) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $normalizedName);
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

        // 본문에 드래그해 넣은 [[img:newK]] 만 업로드(안 넣은 사진은 저장 안 함).
        // 본문 등장 순서대로 sort_order 부여 → 첫 이미지가 목록 썸네일이 됨.
        if (!empty($_FILES['images']['name'][0]) && preg_match_all('/\[\[img:new(\d+)(?:\|\d+)?\]\]/', $content, $mm)) {
            $imgAllowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $uploadDir  = __DIR__ . '/../uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $order = 0;
            $seen  = [];
            $newContent = $content;
            foreach ($mm[1] as $idxStr) {
                $i = (int)$idxStr;
                if (isset($seen[$i])) continue;     // 같은 사진 중복 방지
                $seen[$i] = true;
                if (!isset($_FILES['images']['name'][$i]) || $_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $imgAllowed, true)) continue;
                $stored = uniqid('img_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $uploadDir . '/' . $stored)) {
                    $orig = basename($_FILES['images']['name'][$i]);
                    $stmt = $conn->prepare("INSERT INTO post_images (post_id, original, stored, sort_order) VALUES (?,?,?,?)");
                    $stmt->bind_param("issi", $postId, $orig, $stored, $order);
                    $stmt->execute();
                    $imgId = $conn->insert_id;
                    $stmt->close();
                    // new{i} 부분만 실제 id 로 (|너비 suffix 는 보존)
                    $newContent = preg_replace('/\[\[img:new' . $i . '\b/', '[[img:' . $imgId, $newContent);
                    $order++;
                }
            }
            if ($newContent !== $content) {
                $stmt = $conn->prepare("UPDATE posts SET content = ? WHERE id = ?");
                $stmt->bind_param("si", $newContent, $postId);
                $stmt->execute(); $stmt->close();
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
require_once __DIR__ . '/../app/header.php';
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

    <label class="wf-checkfield">
      <input type="checkbox" name="is_pinned" value="1" <?= $isPinned ? 'checked' : '' ?>>
      <span>내 블로그 글 목록 상단에 공지로 고정</span>
    </label>

    <div class="wf-content wf-editor" id="editor" contenteditable="true"
         data-placeholder="내용을 입력하세요. 아래에서 이미지를 고른 뒤 미리보기를 본문으로 드래그하면 그 자리에 사진이 들어갑니다."><?= htmlspecialchars(preg_replace('/\[\[img:[^\]]+\]\]/', '', $content)) ?></div>
    <input type="hidden" name="content" id="contentField">

    <div class="wf-field">
      <span>태그 (입력 후 Enter)</span>
      <div class="taginput">
        <input type="text" class="taginput__field" placeholder="예: JPOP 시티팝">
        <input type="hidden" name="tags" value="<?= htmlspecialchars($tagInput) ?>">
      </div>
    </div>

    <label class="wf-field">
      <span>본문 이미지 (파일 선택 → 아래 미리보기를 본문으로 드래그하면 그 자리에 사진이 들어갑니다 · 안 넣은 사진은 저장 안 됨)</span>
      <small class="wf-hint">여러 장을 한 번에 고르려면 파일 선택 창에서 Ctrl 또는 Shift를 누른 채 선택하세요.</small>
      <input type="file" name="images[]" accept="image/*" multiple>
    </label>
    <div id="imgTray" class="imgtray"></div>

    <div class="wf-actions">
      <button type="submit" name="status" value="draft"     class="btn-ghost-dark">임시저장</button>
      <button type="submit" name="status" value="published" class="btn-primary">발행</button>
    </div>
  </form>
</section>

<script src="../assets/js/taginput.js?v=20260619c"></script>
<script src="../assets/js/imageinsert.js?v=20260619d"></script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

