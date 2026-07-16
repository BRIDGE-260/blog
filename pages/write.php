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
require_once __DIR__ . '/../app/media.php';
require_once __DIR__ . '/../app/points.php';

$userId = $_SESSION['user_id'];
$error  = '';

// 폼 값 (에러 시 다시 채우기 위해 변수로 보관)
$title      = '';
$content    = '';
$category   = '';
$visibility = 'all';
$tagInput   = '';
$locationName = '';
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
    $locationName = mb_substr(trim($_POST['location_name'] ?? ''), 0, 120, 'UTF-8');
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
               (user_id, category_id, title, content, location_name, visibility, status, is_pinned)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "iisssssi",
            $userId, $catParam, $title, $content, $locationName, $visibility, $status, $isPinned
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

        // 본문에 넣은 첨부만 저장한다. 이미지는 [[img:id]], 동영상은 [[video:id]] 토큰으로 유지.
        [$newContent, $savedMedia] = bridge_save_inline_media($conn, $postId, $content, $_FILES['images'] ?? null, 0);
        if ($savedMedia > 0) {
            if ($newContent !== $content) {
                $stmt = $conn->prepare("UPDATE posts SET content = ? WHERE id = ?");
                $stmt->bind_param("si", $newContent, $postId);
                $stmt->execute(); $stmt->close();
            }
        }

        if ($status === 'published') {
            bridge_add_points($conn, $userId, 10, 'publish_post', (string)$postId, '글 발행');
        }

        // 임시저장이면 내 블로그 임시저장 탭으로, 발행이면 그 글로 이동
        header('Location: ' . ($status === 'draft'
            ? 'blog.php?id=' . $userId . '&status=draft'
            : 'view.php?id=' . $postId));
        exit;
    }
}

$pageTitle = '글쓰기 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';

$bridgeQuestions = [
    [
        'label' => '추억 잇기',
        'title' => '다른 세대에게 들려주고 싶은 추억은 무엇인가요?',
        'body'  => '내가 오래 기억하고 있는 장소, 노래, 물건, 사람 이야기를 다른 세대에게 소개해보세요.',
    ],
    [
        'label' => '요즘 묻기',
        'title' => '요즘 세대에게 궁금한 것이 있나요?',
        'body'  => '새로운 문화, 기술, 말투, 취미처럼 잘 모르지만 알고 싶은 것을 질문처럼 풀어보세요.',
    ],
    [
        'label' => '함께 추천',
        'title' => '나이와 상관없이 함께 추천하고 싶은 것은 무엇인가요?',
        'body'  => '책, 영화, 음악, 산책 코스, 생활 팁처럼 모든 세대가 같이 즐길 수 있는 것을 적어보세요.',
    ],
];
?>

<section class="write">
  <div class="write-top">
    <button type="button" class="write-menu-btn" aria-label="글쓰기 메뉴">☰</button>
    <div class="write-top__meta">
      <strong>글쓰기</strong>
      <span>BRIDGE 206</span>
    </div>
    <div class="write-top__actions" aria-label="저장 버튼">
      <button type="submit" form="writeForm" name="status" value="draft">저장</button>
      <button type="submit" form="writeForm" name="status" value="published">발행</button>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form id="writeForm" class="write-form write-form--editorial" method="post" action="write.php" enctype="multipart/form-data" data-autosave-form data-autosave-key="bridge206WriteDraft">
    <input class="wf-title" type="text" name="title" placeholder="제목을 입력하세요"
           value="<?= htmlspecialchars($title) ?>" required>

    <section class="bridge-write" aria-label="BRIDGE 206 글감 질문">
      <div class="bridge-write__head">
        <span>BRIDGE 206 글감</span>
        <strong>세대를 잇는 질문으로 글을 시작해보세요.</strong>
      </div>
      <div class="bridge-write__grid">
        <?php foreach ($bridgeQuestions as $q): ?>
          <button type="button"
                  class="bridge-write__card"
                  data-bridge-question="<?= htmlspecialchars($q['title'], ENT_QUOTES) ?>"
                  data-bridge-guide="<?= htmlspecialchars($q['body'], ENT_QUOTES) ?>">
            <span><?= htmlspecialchars($q['label']) ?></span>
            <strong><?= htmlspecialchars($q['title']) ?></strong>
            <em><?= htmlspecialchars($q['body']) ?></em>
          </button>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ai-write" aria-label="BRIDGE 206 AI 글쓰기 도우미" data-ai-write>
      <div class="ai-write__intro">
        <span>BRIDGE AI</span>
        <h2>생각의 첫 문장을 같이 찾아볼까요?</h2>
        <p>쓰고 싶은 내용을 짧게 적으면 제목, 글의 흐름, 태그를 제안합니다.</p>
      </div>
      <div class="ai-write__workspace">
        <textarea data-ai-topic rows="3" maxlength="2000" placeholder="예: 오랜만에 아버지와 LP를 들으며 세대마다 음악을 듣는 방식이 다르다는 생각이 들었다."></textarea>
        <div class="ai-write__actions">
          <button type="button" data-ai-mode="title">제목 추천</button>
          <button type="button" data-ai-mode="outline">글 개요</button>
          <button type="button" data-ai-mode="tags">태그 추천</button>
        </div>
        <p class="ai-write__status" data-ai-status aria-live="polite">추천 결과를 누르면 글에 바로 적용됩니다.</p>
        <div class="ai-write__results" data-ai-results></div>
      </div>
    </section>

    <aside class="write-side-tools" aria-label="글쓰기 도구">
      <button type="button" data-tool-target="images" title="사진/동영상 첨부">▧</button>
      <button type="button" data-tool-target="prompts" title="글감 질문">?</button>
      <button type="button" data-tool-target="settings" title="글 설정">⚙</button>
      <button type="button" data-tool-target="tags" title="태그">#</button>
    </aside>

    <div class="wf-row write-settings" data-write-block="settings">
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
         data-placeholder="오늘 나누고 싶은 이야기를 적어보세요."><?= htmlspecialchars(preg_replace('/\[\[(?:img|video):[^\]]+\]\]/', '', $content)) ?></div>
    <input type="hidden" name="content" id="contentField">
    <div class="write-editor-status">
      <span class="write-count" data-write-count>0자 · 0단어</span>
    </div>

    <div class="wf-field" data-write-block="tags">
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

    <label class="wf-field" data-write-block="images">
      <span>본문 첨부 (사진/동영상 선택 → 아래 미리보기를 본문으로 드래그하면 그 자리에 들어갑니다 · 안 넣은 파일은 저장 안 됨)</span>
      <small class="wf-hint">여러 장을 한 번에 고르려면 파일 선택 창에서 Ctrl 또는 Shift를 누른 채 선택하세요.</small>
      <input type="file" name="images[]" accept="image/*,video/*" multiple>
    </label>
    <div id="imgTray" class="imgtray"></div>

    <p class="write-helper" aria-live="polite">상단의 저장 또는 발행 버튼으로 글을 마무리할 수 있어요.</p>
  </form>
</section>

<script src="../assets/js/taginput.js?v=20260716ai"></script>
<script src="../assets/js/imageinsert.js?v=20260702a"></script>
<script src="../assets/js/aiwrite.js?v=20260716a"></script>
<script>
(function () {
  var editor = document.getElementById('editor');
  var titleInput = document.querySelector('.wf-title');
  var cards = document.querySelectorAll('[data-bridge-question]');
  if (!editor || !cards.length) return;

  function appendQuestion(question, guide) {
    var text = 'BRIDGE 206 질문: ' + question + '\n' + guide + '\n\n';
    editor.focus();
    if (editor.innerText.trim() !== '' || editor.querySelector('.editor-img')) {
      editor.appendChild(document.createElement('br'));
      editor.appendChild(document.createElement('br'));
    }
    text.split('\n').forEach(function (line, index, arr) {
      if (line !== '') editor.appendChild(document.createTextNode(line));
      if (index < arr.length - 1) editor.appendChild(document.createElement('br'));
    });
    if (titleInput && titleInput.value.trim() === '') {
      titleInput.value = question;
    }
    window.showToast && window.showToast('글감 질문을 본문에 추가했어요.', false);
  }

  cards.forEach(function (card) {
    card.addEventListener('click', function () {
      appendQuestion(card.getAttribute('data-bridge-question'), card.getAttribute('data-bridge-guide'));
    });
  });
})();
</script>
<script>
(function () {
  var form = document.querySelector('[data-autosave-form]');
  var editor = document.getElementById('editor');
  if (!form || !editor || !window.localStorage) return;

  var key = form.getAttribute('data-autosave-key') || 'bridge206WriteDraft';
  var title = form.querySelector('input[name="title"]');
  var category = form.querySelector('select[name="category"]');
  var visibility = form.querySelector('select[name="visibility"]');
  var tags = form.querySelector('input[name="tags"]');
  var pinned = form.querySelector('input[name="is_pinned"]');
  var status = form.querySelector('.write-editor-status');
  var countLabel = form.querySelector('[data-write-count]');
  var notice = document.createElement('p');
  notice.className = 'autosave-note';
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  if (status) status.appendChild(notice);

  function updateCount() {
    if (!countLabel) return;
    var text = editor.innerText.replace(/\s+/g, ' ').trim();
    var chars = text.replace(/\s/g, '').length;
    var words = text ? text.split(' ').length : 0;
    countLabel.textContent = chars + '자 · ' + words + '단어';
  }

  function hasServerValue() {
    return Boolean((title && title.value.trim()) || editor.innerText.trim() || editor.querySelector('.editor-img'));
  }

  function readDraft() {
    try {
      return JSON.parse(localStorage.getItem(key) || 'null');
    } catch (e) {
      return null;
    }
  }

  function writeDraft() {
    var draft = {
      title: title ? title.value : '',
      category: category ? category.value : '',
      visibility: visibility ? visibility.value : 'all',
      tags: tags ? tags.value : '',
      pinned: pinned ? pinned.checked : false,
      editorHtml: editor.innerHTML,
      savedAt: new Date().toISOString()
    };
    localStorage.setItem(key, JSON.stringify(draft));
    var time = new Date(draft.savedAt);
    notice.textContent = '자동 임시저장됨 ' + String(time.getHours()).padStart(2, '0') + ':' + String(time.getMinutes()).padStart(2, '0');
  }

  function restoreDraft() {
    if (hasServerValue()) return;
    var draft = readDraft();
    if (!draft) return;
    if (title) title.value = draft.title || '';
    if (category) category.value = draft.category || '';
    if (visibility) visibility.value = draft.visibility || 'all';
    if (tags) {
      tags.value = draft.tags || '';
      tags.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (pinned) pinned.checked = Boolean(draft.pinned);
    if (draft.editorHtml) editor.innerHTML = draft.editorHtml;
    notice.textContent = '자동 임시저장 글을 불러왔어요.';
  }

  var timer = null;
  function scheduleSave() {
    clearTimeout(timer);
    updateCount();
    timer = setTimeout(writeDraft, 500);
  }

  restoreDraft();
  updateCount();
  form.addEventListener('input', scheduleSave);
  form.addEventListener('change', scheduleSave);
  editor.addEventListener('keyup', scheduleSave);
  editor.addEventListener('mouseup', scheduleSave);
  form.addEventListener('submit', function () {
    if (title && title.value.trim() !== '' && editor.innerText.trim() !== '') {
      localStorage.removeItem(key);
    }
  });

  document.addEventListener('click', function (event) {
    var tool = event.target.closest('[data-tool-target]');
    if (!tool) return;
    var target = tool.getAttribute('data-tool-target');
    if (target === 'images') {
      var file = document.querySelector('input[type="file"][name="images[]"]');
      if (file) file.click();
      return;
    }
    if (target === 'prompts') {
      var promptBlock = document.querySelector('.bridge-write');
      if (promptBlock) promptBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    var block = document.querySelector('[data-write-block="' + target + '"]');
    if (block) block.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

