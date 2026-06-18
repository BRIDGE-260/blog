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
require_once __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$saved  = false;
$error  = '';

// 현재 값 불러오기 (이미지 교체/유지 판단에도 사용)
$stmt = $conn->prepare(
    "SELECT nickname, blog_title, intro, gender, profile_image_original, profile_image_stored
     FROM users WHERE id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── POST: 저장 ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $blogTitle = trim($_POST['blog_title'] ?? '');
    $intro     = trim($_POST['intro'] ?? '');
    $gender    = $_POST['gender'] ?? '';

    $blogTitleParam = ($blogTitle !== '') ? $blogTitle : null;
    $introParam     = ($intro !== '') ? $intro : null;
    $genderParam    = in_array($gender, ['남성', '여성'], true) ? $gender : null;

    // 이미지 최종값 = 기존 값에서 시작
    $imgOriginal = $user['profile_image_original'];
    $imgStored   = $user['profile_image_stored'];
    $uploadDir   = __DIR__ . '/uploads';

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
               SET blog_title = ?, intro = ?, gender = ?,
                   profile_image_original = ?, profile_image_stored = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            "sssssi",
            $blogTitleParam, $introParam, $genderParam, $imgOriginal, $imgStored, $userId
        );
        $stmt->execute();
        $stmt->close();
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

$pageTitle = '프로필 수정 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="setting">
  <h1>프로필 수정</h1>

  <?php if ($saved): ?>
    <div class="form-ok">저장되었습니다.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form class="write-form" method="post" action="profile.php" enctype="multipart/form-data">
    <div class="pf-avatar">
      <div class="pf-avatar__img">
        <?php if (!empty($user['profile_image_stored'])): ?>
          <img src="uploads/<?= htmlspecialchars($user['profile_image_stored']) ?>" alt="">
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
      <span>닉네임 (변경 불가)</span>
      <input type="text" value="<?= htmlspecialchars($user['nickname']) ?>" disabled>
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
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
