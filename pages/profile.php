<?php
/**
 * profile.php — 프로필 수정 (블로그 제목 / 소개 / 성별 / 프로필 이미지).
 *   이메일·비밀번호·닉네임은 건드리지 않음.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];
$saved  = false;
$error  = '';

// 현재 값 불러오기 (이미지 교체/유지 판단에도 사용)
$stmt = $conn->prepare(
    "SELECT name, nickname, blog_title, intro, gender, profile_image_original, profile_image_stored
     FROM users WHERE id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: auth.php');
    exit;
}

// ── POST: 저장 ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nickname  = trim($_POST['nickname'] ?? '');
    $blogTitle = trim($_POST['blog_title'] ?? '');
    $intro     = trim($_POST['intro'] ?? '');
    $gender    = $_POST['gender'] ?? '';

    $blogTitleParam = ($blogTitle !== '') ? $blogTitle : null;
    $introParam     = ($intro !== '') ? $intro : null;
    $genderParam    = in_array($gender, ['남성', '여성'], true) ? $gender : null;

    // 닉네임 검증: 비어있지 않고, 남이 쓰고 있지 않아야 함(UNIQUE)
    if ($nickname === '') {
        $error = '닉네임을 입력해주세요.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE nickname = ? AND id <> ?");
        $stmt->bind_param("si", $nickname, $userId);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($dup) $error = '이미 사용 중인 닉네임이에요.';
    }

    // 이미지 최종값 = 기존 값에서 시작
    $imgOriginal = $user['profile_image_original'];
    $imgStored   = $user['profile_image_stored'];
    $uploadDir   = __DIR__ . '/../uploads';

    // 1) 제거 체크 → 기존 파일 삭제 후 NULL
    if (!empty($_POST['remove_img']) && $imgStored) {
        @unlink($uploadDir . '/' . $imgStored);
        $imgOriginal = null;
        $imgStored   = null;
    }
    // 2) 새 파일 업로드 → 교체
    elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext     = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $error = '이미지는 jpg, png, gif, webp 만 올릴 수 있어요.';
        } else {
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newStored = uniqid('profile_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadDir . '/' . $newStored)) {
                if ($imgStored) @unlink($uploadDir . '/' . $imgStored);
                $imgOriginal = basename($_FILES['profile_image']['name']);
                $imgStored   = $newStored;
            } else {
                $error = '이미지 업로드에 실패했어요.';
            }
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare(
            "UPDATE users
               SET nickname = ?, blog_title = ?, intro = ?, gender = ?,
                   profile_image_original = ?, profile_image_stored = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            "ssssssi",
            $nickname, $blogTitleParam, $introParam, $genderParam, $imgOriginal, $imgStored, $userId
        );
        $stmt->execute();
        $stmt->close();
        $_SESSION['nickname'] = $nickname;   // 상단바 등 표시용 세션도 갱신
        $saved = true;

        // 화면에 갱신된 값 다시 불러오기
        $stmt = $conn->prepare(
            "SELECT nickname, blog_title, intro, gender, profile_image_original, profile_image_stored
             FROM users WHERE id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$pageTitle = '프로필 수정 · BRIDGE 206';
$pageClass = 'page--settings page--profile';
require_once __DIR__ . '/../app/header.php';
?>

<section class="setting profile-setting">
  <h1>프로필 수정</h1>

  <?php if ($saved): ?>
    <div class="form-ok">저장되었습니다.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="profile-bridge" aria-label="BRIDGE 206 프로필 도우미">
    <div class="profile-bridge__head">
      <span>BRIDGE 206 프로필</span>
      <strong>내 블로그가 어떤 세대와도 대화를 시작할 수 있게 소개를 잡아보세요.</strong>
    </div>
    <div class="profile-bridge__cards">
      <button type="button" data-title="세대가 함께 읽는 일상 기록" data-intro="20대의 오늘과 60대의 기억이 함께 머물 수 있는 일상과 생각을 기록합니다. 서로 다른 경험이 편하게 만나는 블로그예요.">
        <span>일상 연결</span>
        <strong>오늘의 이야기와 오래된 기억을 같이 남기기</strong>
      </button>
      <button type="button" data-title="취향을 이어주는 작은 기록실" data-intro="음악, 영화, 책, 취미처럼 나이와 상관없이 함께 이야기할 수 있는 취향을 모읍니다. 댓글로 서로의 추천도 나누고 싶어요.">
        <span>취향 연결</span>
        <strong>세대마다 다른 추천과 감상을 모으기</strong>
      </button>
      <button type="button" data-title="서로에게 묻는 BRIDGE 노트" data-intro="다른 세대에게 궁금했던 질문을 가볍게 꺼내고, 내 경험으로 답해보는 블로그입니다. 낯선 생각도 천천히 읽을 수 있게 씁니다.">
        <span>질문 연결</span>
        <strong>궁금한 것을 묻고 경험으로 답하기</strong>
      </button>
    </div>
  </div>

  <form class="write-form profile-form" method="post" action="profile.php" enctype="multipart/form-data">
    <div class="pf-avatar">
      <div class="pf-avatar__img">
        <?php if (!empty($user['profile_image_stored'])): ?>
          <img src="../uploads/<?= htmlspecialchars($user['profile_image_stored']) ?>" alt="">
        <?php else: ?>
          <span><?= htmlspecialchars(mb_substr($user['nickname'], 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="pf-avatar__field">
        <label class="wf-field">
          <span>프로필 이미지</span>
          <input type="file" name="profile_image" accept="image/*">
        </label>
        <?php if (!empty($user['profile_image_stored'])): ?>
          <label class="wf-checkfield">
            <input type="checkbox" name="remove_img" value="1">
            <span>현재 이미지 제거</span>
          </label>
        <?php endif; ?>
      </div>
    </div>

    <label class="wf-field">
      <span>이름 (변경 불가)</span>
      <input type="text" value="<?= htmlspecialchars($user['name']) ?>" disabled>
    </label>

    <label class="wf-field">
      <span>닉네임</span>
      <input type="text" name="nickname" value="<?= htmlspecialchars($user['nickname']) ?>"
             placeholder="블로그에 표시될 이름" required>
    </label>

    <label class="wf-field">
      <span>블로그 제목</span>
      <input type="text" name="blog_title" value="<?= htmlspecialchars($user['blog_title'] ?? '') ?>"
             placeholder="예: 유진의 음악 일기">
    </label>

    <label class="wf-field">
      <span>소개</span>
      <textarea class="wf-content" name="intro" rows="4" placeholder="블로그를 소개하는 한두 줄"><?= htmlspecialchars($user['intro'] ?? '') ?></textarea>
    </label>

    <label class="wf-field">
      <span>성별</span>
      <select name="gender">
        <option value="">선택 안 함</option>
        <option value="남성" <?= ($user['gender'] ?? '') === '남성' ? 'selected' : '' ?>>남성</option>
        <option value="여성" <?= ($user['gender'] ?? '') === '여성' ? 'selected' : '' ?>>여성</option>
      </select>
    </label>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="password.php">비밀번호 변경</a>
      <button type="submit" class="btn-primary">저장</button>
    </div>
  </form>

  <p class="setting__danger"><a class="btn-withdraw" href="withdraw.php">회원 탈퇴</a></p>
</section>

<script>
(function () {
  const helper = document.querySelector('.profile-bridge');
  if (!helper) return;

  const titleInput = document.querySelector('input[name="blog_title"]');
  const introInput = document.querySelector('textarea[name="intro"]');

  helper.addEventListener('click', function (event) {
    const button = event.target.closest('button[data-title]');
    if (!button) return;

    if (titleInput && titleInput.value.trim() === '') {
      titleInput.value = button.dataset.title || '';
    }
    if (introInput) {
      introInput.value = button.dataset.intro || '';
      introInput.focus();
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

