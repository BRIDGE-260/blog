<?php
/**
 * modify.php — 글 수정 (본인 글만). write.php 와 동일한 위지윅 본문 편집기.
 *   기존 본문 이미지는 편집기 안에 실제 이미지로 렌더(이동·크기조절·삭제 가능).
 *   저장 시: 본문에 남은 이미지만 유지, 빠진 이미지는 파일·row 삭제, 새 이미지는 업로드 후 토큰 치환.
 *   sort_order 는 본문 등장 순서로 재부여 → 첫 이미지가 목록 썸네일.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/categories.php';
require_once __DIR__ . '/../app/media.php';
require_once __DIR__ . '/../app/points.php';

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
$isPinned   = (int)($post['is_pinned'] ?? 0);

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
$locationName = (string)($post['location_name'] ?? '');

// 기존 본문 이미지 (편집기 초기 렌더용)
$stmt = $conn->prepare("SELECT id, stored, media_type FROM post_images WHERE post_id = ? ORDER BY sort_order, id");
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
    $locationName = mb_substr(trim($_POST['location_name'] ?? ''), 0, 120, 'UTF-8');
    $status     = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $isPinned   = isset($_POST['is_pinned']) ? 1 : 0;

    if (!in_array($visibility, ['all', 'neighbor', 'private'], true)) $visibility = 'all';
    if ($title === '' || $content === '') $error = '제목과 내용을 입력해주세요.';

    if ($error === '') {
        $catParam = in_array($category, $FIXED_CATEGORIES, true)
            ? ensureCategory($conn, $userId, $category)
            : null;
        $uploadDir = __DIR__ . '/../uploads';

        // 1) 본문에 새로 끼워넣은 첨부만 업로드 + 실제 id 로 치환
        [$content, $savedMedia] = bridge_save_inline_media($conn, $postId, $content, $_FILES['images'] ?? null, 0);

        // 2) 글 UPDATE (썸네일 컬럼은 더 이상 안 씀)
        $stmt = $conn->prepare(
            "UPDATE posts
               SET category_id = ?, title = ?, content = ?, location_name = ?, visibility = ?, status = ?, is_pinned = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("isssssiii", $catParam, $title, $content, $locationName, $visibility, $status, $isPinned, $postId, $userId);
        $stmt->execute();
        $stmt->close();

        if ($status === 'published') {
            bridge_add_points($conn, $userId, 10, 'publish_post', (string)$postId, '글 발행');
        }

        // 3) 본문에 남은 이미지 id (등장 순서)
        preg_match_all('/\[\[(?:img|video):(\d+)/', $content, $rm);
        $refIds = [];
        foreach ($rm[1] as $idStr) {
            $rid = (int)$idStr;
            if (!in_array($rid, $refIds, true)) $refIds[] = $rid;
        }

        // 4) 본문에서 빠진 이미지는 파일·row 삭제
        $stmt = $conn->prepare("SELECT id, stored FROM post_images WHERE post_id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $allImgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($allImgs as $im) {
            if (!in_array((int)$im['id'], $refIds, true)) {
                @unlink($uploadDir . '/' . $im['stored']);
                $stmt = $conn->prepare("DELETE FROM post_images WHERE id = ? AND post_id = ?");
                $stmt->bind_param("ii", $im['id'], $postId);
                $stmt->execute();
                $stmt->close();
            }
        }

        // 5) 남은 이미지 sort_order 를 등장 순서로 재부여 (첫 이미지 = 썸네일)
        $order = 0;
        foreach ($refIds as $rid) {
            $stmt = $conn->prepare("UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?");
            $stmt->bind_param("iii", $order, $rid, $postId);
            $stmt->execute();
            $stmt->close();
            $order++;
        }

        // 6) 태그 재동기화
        $stmt = $conn->prepare("DELETE FROM post_tags WHERE post_id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();

        $names = preg_split('/\s+/', $tagInput, -1, PREG_SPLIT_NO_EMPTY);
        $done = [];
        foreach ($names as $raw) {
            $name = trim(ltrim($raw, '#'));
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if ($name === '' || isset($done[$normalizedName])) continue;
            $done[$normalizedName] = true;

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

            $stmt = $conn->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $postId, $tagId);
            $stmt->execute();
            $stmt->close();
        }

        header('Location: view.php?id=' . $postId);
        exit;
    }
}

/** 저장된 본문([[img:id|W]] 토큰 포함)을 편집기용 HTML 로 — 토큰은 실제 <img>, 텍스트는 escape */
function buildEditorHtml(string $content, array $images): string {
    return buildEditorMediaHtml($content, $images);

    $map = [];
    foreach ($images as $im) $map[(int)$im['id']] = $im['stored'];
    $used  = [];
    $parts = preg_split('/(\[\[img:\d+(?:\|\d+)?\]\])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html  = '';
    foreach ($parts as $part) {
        if (preg_match('/^\[\[img:(\d+)(?:\|(\d+))?\]\]$/', $part, $m)) {
            $id = (int)$m[1];
            if (isset($map[$id])) {
                $used[$id] = true;
                $w = isset($m[2]) ? (int)$m[2] : 30;
                $html .= '<img class="editor-img" contenteditable="false" data-token="' . $id
                       . '" style="width:' . $w . '%" src="../uploads/' . htmlspecialchars($map[$id]) . '">';
            }
        } else {
            $html .= nl2br(htmlspecialchars($part));
        }
    }
    // 본문에 토큰이 없던 기존 이미지도 편집기 끝에 붙여 보이게(옛 글 대비) → 빼면 저장 시 삭제됨
    foreach ($images as $im) {
        if (empty($used[(int)$im['id']])) {
            $html .= '<img class="editor-img" contenteditable="false" data-token="' . (int)$im['id']
                   . '" style="width:30%" src="../uploads/' . htmlspecialchars($im['stored']) . '">';
        }
    }
    return $html;
}

function buildEditorMediaHtml(string $content, array $mediaRows): string {
    $map = [];
    foreach ($mediaRows as $media) {
        $map[(int)$media['id']] = $media;
    }

    $used = [];
    $parts = preg_split('/(\[\[(?:img|video):\d+(?:\|\d+)?\]\])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';

    foreach ($parts as $part) {
        if (!preg_match('/^\[\[(img|video):(\d+)(?:\|(\d+))?\]\]$/', $part, $m)) {
            $html .= nl2br(htmlspecialchars($part));
            continue;
        }

        $id = (int)$m[2];
        if (!isset($map[$id])) {
            continue;
        }

        $used[$id] = true;
        $mediaType = ($map[$id]['media_type'] ?? 'image') === 'video' ? 'video' : 'image';
        $width = isset($m[3]) ? (int)$m[3] : ($mediaType === 'video' ? 70 : 30);
        $stored = htmlspecialchars($map[$id]['stored']);

        if ($mediaType === 'video' || $m[1] === 'video') {
            $html .= '<video class="editor-img editor-video" contenteditable="false" data-token="video:' . $id
                   . '" style="width:' . $width . '%" src="../uploads/' . $stored . '" controls muted preload="metadata"></video>';
        } else {
            $html .= '<img class="editor-img" contenteditable="false" data-token="img:' . $id
                   . '" style="width:' . $width . '%" src="../uploads/' . $stored . '">';
        }
    }

    foreach ($mediaRows as $media) {
        $id = (int)$media['id'];
        if (!empty($used[$id])) {
            continue;
        }

        $mediaType = ($media['media_type'] ?? 'image') === 'video' ? 'video' : 'image';
        $stored = htmlspecialchars($media['stored']);
        if ($mediaType === 'video') {
            $html .= '<video class="editor-img editor-video" contenteditable="false" data-token="video:' . $id
                   . '" style="width:70%" src="../uploads/' . $stored . '" controls muted preload="metadata"></video>';
        } else {
            $html .= '<img class="editor-img" contenteditable="false" data-token="img:' . $id
                   . '" style="width:30%" src="../uploads/' . $stored . '">';
        }
    }

    return $html;
}

$pageTitle = '글 수정 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="write">
  <div class="write-top">
    <a class="write-menu-btn" href="view.php?id=<?= (int)$postId ?>" aria-label="글로 돌아가기">←</a>
    <div class="write-top__meta">
      <strong>글 수정</strong>
      <span>BRIDGE 206</span>
    </div>
    <div class="write-top__actions" aria-label="수정 저장 버튼">
      <button type="submit" form="modifyForm" name="status" value="draft">임시저장</button>
      <button type="submit" form="modifyForm" name="status" value="published">수정 완료</button>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form id="modifyForm" class="write-form write-form--editorial" method="post" action="modify.php" enctype="multipart/form-data">
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

    <label class="wf-checkfield">
      <input type="checkbox" name="is_pinned" value="1" <?= $isPinned ? 'checked' : '' ?>>
      <span>내 블로그 글 목록 상단에 공지로 고정</span>
    </label>

    <section class="ai-write" aria-label="BRIDGE 206 AI 글쓰기 도우미" data-ai-write>
      <div class="ai-write__intro">
        <span>BRIDGE AI</span>
        <h2>기존 글을 더 선명하게 다듬어보세요.</h2>
        <p>메모를 비워두면 현재 제목과 본문을 바탕으로 추천합니다.</p>
      </div>
      <div class="ai-write__workspace">
        <textarea data-ai-topic rows="3" maxlength="2000" placeholder="바꾸고 싶은 방향이나 추가할 내용을 적어보세요."></textarea>
        <div class="ai-write__actions">
          <button type="button" data-ai-mode="title">제목 다시 추천</button>
          <button type="button" data-ai-mode="outline">글 개요 보완</button>
          <button type="button" data-ai-mode="tags">태그 추천</button>
        </div>
        <p class="ai-write__status" data-ai-status aria-live="polite">추천 결과를 누르면 현재 글에 적용됩니다.</p>
        <div class="ai-write__results" data-ai-results></div>
      </div>
    </section>

    <div class="wf-content wf-editor" id="editor" contenteditable="true"
         data-placeholder="내용을 입력하세요. 아래에서 첨부파일을 고른 뒤 미리보기를 본문으로 드래그하면 그 자리에 들어갑니다."><?= buildEditorMediaHtml($content, $postImages) ?></div>
    <input type="hidden" name="content" id="contentField">

    <div class="wf-field">
      <span>태그 (입력 후 Enter)</span>
      <div class="taginput">
        <input type="text" class="taginput__field" placeholder="예: JPOP 시티팝">
        <input type="hidden" name="tags" value="<?= htmlspecialchars($tagInput) ?>">
      </div>
    </div>

    <label class="wf-field">
      <span>장소</span>
      <input type="text" name="location_name" maxlength="120" value="<?= htmlspecialchars($locationName) ?>" placeholder="예: 서울숲, 부산 해운대">
    </label>

    <label class="wf-field">
      <span>본문 첨부 (사진/동영상 선택 → 아래 미리보기를 본문으로 드래그 · 본문에서 빼면 저장 시 삭제됨)</span>
      <small class="wf-hint">여러 장을 한 번에 고르려면 파일 선택 창에서 Ctrl 또는 Shift를 누른 채 선택하세요.</small>
      <input type="file" name="images[]" accept="image/*,video/*" multiple>
    </label>
    <div id="imgTray" class="imgtray"></div>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="view.php?id=<?= (int)$postId ?>">취소</a>
      <button type="submit" name="status" value="draft"     class="btn-ghost-dark">임시저장</button>
      <button type="submit" name="status" value="published" class="btn-primary">수정 완료</button>
    </div>
  </form>
</section>

<script src="../assets/js/taginput.js?v=20260716ai"></script>
<script src="../assets/js/imageinsert.js?v=20260702a"></script>
<script src="../assets/js/aiwrite.js?v=20260716a"></script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

