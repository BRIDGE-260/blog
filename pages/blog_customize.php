<?php
/**
 * blog_customize.php — 내 블로그 디자인 커스터마이징.
 *   색상, 배경/헤더 이미지, 레이아웃, 목록 스타일 등을 blog_settings 에 저장한다.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = (int)$_SESSION['user_id'];
$saved = false;
$error = '';
$uploadDir = __DIR__ . '/../uploads';

$defaults = [
    'accent_color' => '#d4af7a',
    'background_color' => '#f5f6f8',
    'background_image_original' => null,
    'background_image_stored' => null,
    'background_repeat' => 'no-repeat',
    'background_position' => 'center',
    'background_size' => 'cover',
    'header_image_original' => null,
    'header_image_stored' => null,
    'header_height' => 220,
    'layout_type' => 'standard',
    'title_align' => 'left',
    'sidebar_position' => 'left',
    'profile_shape' => 'circle',
    'profile_card_color' => '#ffffff',
    'post_list_style' => 'card',
    'thumbnail_style' => 'wide',
    'font_style' => 'sans',
    'show_intro' => 1,
    'show_post_summary' => 1,
    'show_visit_count' => 1,
];

function pickOption($value, $allowed, $fallback) {
    return in_array($value, $allowed, true) ? $value : $fallback;
}
function pickColor($value, $fallback) {
    return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value) ? strtolower($value) : $fallback;
}
function uploadCustomizeImage($field, $prefix, $oldStored, &$error, $uploadDir) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, $oldStored, false];
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $error = '이미지 업로드 중 오류가 발생했어요.';
        return [null, $oldStored, false];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $original = basename($_FILES[$field]['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        $error = '이미지는 jpg, png, gif, webp 만 올릴 수 있어요.';
        return [null, $oldStored, false];
    }

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $stored = uniqid($prefix, true) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . '/' . $stored)) {
        $error = '이미지 업로드에 실패했어요.';
        return [null, $oldStored, false];
    }
    if ($oldStored) @unlink($uploadDir . '/' . $oldStored);
    return [$original, $stored, true];
}

$stmt = $conn->prepare("SELECT * FROM blog_settings WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$settings) {
    $stmt = $conn->prepare("INSERT INTO blog_settings (user_id) VALUES (?)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    $settings = $defaults;
    $settings['user_id'] = $userId;
}
$settings = array_merge($defaults, $settings);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accentColor = pickColor($_POST['accent_color'] ?? '', $defaults['accent_color']);
    $backgroundColor = pickColor($_POST['background_color'] ?? '', $defaults['background_color']);
    $profileCardColor = pickColor($_POST['profile_card_color'] ?? '', $defaults['profile_card_color']);
    $backgroundRepeat = pickOption($_POST['background_repeat'] ?? '', ['no-repeat', 'repeat'], 'no-repeat');
    $backgroundPosition = pickOption($_POST['background_position'] ?? '', ['left', 'center', 'right'], 'center');
    $backgroundSize = pickOption($_POST['background_size'] ?? '', ['cover', 'contain', 'auto'], 'cover');
    $headerHeight = min(360, max(120, (int)($_POST['header_height'] ?? 220)));
    $layoutType = pickOption($_POST['layout_type'] ?? '', ['standard', 'wide', 'compact'], 'standard');
    $titleAlign = pickOption($_POST['title_align'] ?? '', ['left', 'center'], 'left');
    $sidebarPosition = pickOption($_POST['sidebar_position'] ?? '', ['left', 'right'], 'left');
    $profileShape = pickOption($_POST['profile_shape'] ?? '', ['circle', 'rounded', 'square'], 'circle');
    $postListStyle = pickOption($_POST['post_list_style'] ?? '', ['card', 'list'], 'card');
    $thumbnailStyle = pickOption($_POST['thumbnail_style'] ?? '', ['wide', 'square', 'hidden'], 'wide');
    $fontStyle = pickOption($_POST['font_style'] ?? '', ['sans', 'serif', 'rounded'], 'sans');
    $showIntro = isset($_POST['show_intro']) ? 1 : 0;
    $showPostSummary = isset($_POST['show_post_summary']) ? 1 : 0;
    $showVisitCount = isset($_POST['show_visit_count']) ? 1 : 0;

    $bgOriginal = $settings['background_image_original'];
    $bgStored = $settings['background_image_stored'];
    $headerOriginal = $settings['header_image_original'];
    $headerStored = $settings['header_image_stored'];

    if (!empty($_POST['remove_background_image']) && $bgStored) {
        @unlink($uploadDir . '/' . $bgStored);
        $bgOriginal = null;
        $bgStored = null;
    }
    if (!empty($_POST['remove_header_image']) && $headerStored) {
        @unlink($uploadDir . '/' . $headerStored);
        $headerOriginal = null;
        $headerStored = null;
    }

    [$newOriginal, $newStored, $changed] = uploadCustomizeImage('background_image', 'blog_bg_', $bgStored, $error, $uploadDir);
    if ($changed) {
        $bgOriginal = $newOriginal;
        $bgStored = $newStored;
    }
    if ($error === '') {
        [$newOriginal, $newStored, $changed] = uploadCustomizeImage('header_image', 'blog_header_', $headerStored, $error, $uploadDir);
        if ($changed) {
            $headerOriginal = $newOriginal;
            $headerStored = $newStored;
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare(
            "UPDATE blog_settings
                SET accent_color = ?, background_color = ?,
                    background_image_original = ?, background_image_stored = ?,
                    background_repeat = ?, background_position = ?, background_size = ?,
                    header_image_original = ?, header_image_stored = ?, header_height = ?,
                    layout_type = ?, title_align = ?, sidebar_position = ?, profile_shape = ?,
                    profile_card_color = ?, post_list_style = ?, thumbnail_style = ?, font_style = ?,
                    show_intro = ?, show_post_summary = ?, show_visit_count = ?
              WHERE user_id = ?"
        );
        $stmt->bind_param(
            "sssssssssissssssssiiii",
            $accentColor, $backgroundColor, $bgOriginal, $bgStored,
            $backgroundRepeat, $backgroundPosition, $backgroundSize,
            $headerOriginal, $headerStored, $headerHeight,
            $layoutType, $titleAlign, $sidebarPosition, $profileShape,
            $profileCardColor, $postListStyle, $thumbnailStyle, $fontStyle,
            $showIntro, $showPostSummary, $showVisitCount, $userId
        );
        $stmt->execute();
        $stmt->close();
        $saved = true;

        $stmt = $conn->prepare("SELECT * FROM blog_settings WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $settings = array_merge($defaults, $stmt->get_result()->fetch_assoc());
        $stmt->close();
    }
}

$pageTitle = '블로그 꾸미기 · MyBlog';
require_once __DIR__ . '/../app/header.php';
?>

<section class="setting customize">
  <h1>블로그 꾸미기</h1>

  <?php if ($saved): ?>
    <div class="form-ok">블로그 디자인을 저장했어요.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form class="write-form customize-form" method="post" action="blog_customize.php" enctype="multipart/form-data">
    <div class="customize-grid">
      <label class="wf-field">
        <span>포인트 색상</span>
        <input type="color" name="accent_color" value="<?= htmlspecialchars($settings['accent_color']) ?>">
      </label>
      <label class="wf-field">
        <span>배경색</span>
        <input type="color" name="background_color" value="<?= htmlspecialchars($settings['background_color']) ?>">
      </label>
      <label class="wf-field">
        <span>프로필 카드색</span>
        <input type="color" name="profile_card_color" value="<?= htmlspecialchars($settings['profile_card_color']) ?>">
      </label>
    </div>

    <div class="wf-field">
      <span>헤더 배너 이미지</span>
      <?php if (!empty($settings['header_image_stored'])): ?>
        <img class="customize-preview customize-preview--wide" src="../uploads/<?= htmlspecialchars($settings['header_image_stored']) ?>" alt="">
        <label class="wf-checkfield"><input type="checkbox" name="remove_header_image" value="1"><span>현재 헤더 이미지 제거</span></label>
      <?php endif; ?>
      <input type="file" name="header_image" accept="image/*">
    </div>

    <div class="wf-field">
      <span>배경 이미지</span>
      <?php if (!empty($settings['background_image_stored'])): ?>
        <img class="customize-preview" src="../uploads/<?= htmlspecialchars($settings['background_image_stored']) ?>" alt="">
        <label class="wf-checkfield"><input type="checkbox" name="remove_background_image" value="1"><span>현재 배경 이미지 제거</span></label>
      <?php endif; ?>
      <input type="file" name="background_image" accept="image/*">
    </div>

    <div class="wf-row">
      <label>
        <span>배경 반복</span>
        <select name="background_repeat">
          <option value="no-repeat" <?= $settings['background_repeat'] === 'no-repeat' ? 'selected' : '' ?>>반복 없음</option>
          <option value="repeat" <?= $settings['background_repeat'] === 'repeat' ? 'selected' : '' ?>>반복</option>
        </select>
      </label>
      <label>
        <span>배경 위치</span>
        <select name="background_position">
          <option value="left" <?= $settings['background_position'] === 'left' ? 'selected' : '' ?>>왼쪽</option>
          <option value="center" <?= $settings['background_position'] === 'center' ? 'selected' : '' ?>>가운데</option>
          <option value="right" <?= $settings['background_position'] === 'right' ? 'selected' : '' ?>>오른쪽</option>
        </select>
      </label>
      <label>
        <span>배경 크기</span>
        <select name="background_size">
          <option value="cover" <?= $settings['background_size'] === 'cover' ? 'selected' : '' ?>>채우기</option>
          <option value="contain" <?= $settings['background_size'] === 'contain' ? 'selected' : '' ?>>전체 보이기</option>
          <option value="auto" <?= $settings['background_size'] === 'auto' ? 'selected' : '' ?>>원본 크기</option>
        </select>
      </label>
    </div>

    <div class="wf-row">
      <label>
        <span>레이아웃</span>
        <select name="layout_type">
          <option value="standard" <?= $settings['layout_type'] === 'standard' ? 'selected' : '' ?>>기본형</option>
          <option value="wide" <?= $settings['layout_type'] === 'wide' ? 'selected' : '' ?>>넓은형</option>
          <option value="compact" <?= $settings['layout_type'] === 'compact' ? 'selected' : '' ?>>미니형</option>
        </select>
      </label>
      <label>
        <span>사이드바 위치</span>
        <select name="sidebar_position">
          <option value="left" <?= $settings['sidebar_position'] === 'left' ? 'selected' : '' ?>>왼쪽</option>
          <option value="right" <?= $settings['sidebar_position'] === 'right' ? 'selected' : '' ?>>오른쪽</option>
        </select>
      </label>
      <label>
        <span>제목 정렬</span>
        <select name="title_align">
          <option value="left" <?= $settings['title_align'] === 'left' ? 'selected' : '' ?>>왼쪽</option>
          <option value="center" <?= $settings['title_align'] === 'center' ? 'selected' : '' ?>>가운데</option>
        </select>
      </label>
    </div>

    <div class="wf-row">
      <label>
        <span>프로필 이미지 모양</span>
        <select name="profile_shape">
          <option value="circle" <?= $settings['profile_shape'] === 'circle' ? 'selected' : '' ?>>원형</option>
          <option value="rounded" <?= $settings['profile_shape'] === 'rounded' ? 'selected' : '' ?>>둥근 사각형</option>
          <option value="square" <?= $settings['profile_shape'] === 'square' ? 'selected' : '' ?>>사각형</option>
        </select>
      </label>
      <label>
        <span>글 목록</span>
        <select name="post_list_style">
          <option value="card" <?= $settings['post_list_style'] === 'card' ? 'selected' : '' ?>>카드형</option>
          <option value="list" <?= $settings['post_list_style'] === 'list' ? 'selected' : '' ?>>리스트형</option>
        </select>
      </label>
      <label>
        <span>썸네일</span>
        <select name="thumbnail_style">
          <option value="wide" <?= $settings['thumbnail_style'] === 'wide' ? 'selected' : '' ?>>가로형</option>
          <option value="square" <?= $settings['thumbnail_style'] === 'square' ? 'selected' : '' ?>>정사각형</option>
          <option value="hidden" <?= $settings['thumbnail_style'] === 'hidden' ? 'selected' : '' ?>>숨김</option>
        </select>
      </label>
    </div>

    <div class="wf-row">
      <label>
        <span>폰트</span>
        <select name="font_style">
          <option value="sans" <?= $settings['font_style'] === 'sans' ? 'selected' : '' ?>>기본 고딕</option>
          <option value="serif" <?= $settings['font_style'] === 'serif' ? 'selected' : '' ?>>명조</option>
          <option value="rounded" <?= $settings['font_style'] === 'rounded' ? 'selected' : '' ?>>둥근 고딕</option>
        </select>
      </label>
      <label>
        <span>헤더 높이(px)</span>
        <input type="number" name="header_height" min="120" max="360" value="<?= (int)$settings['header_height'] ?>">
      </label>
    </div>

    <div class="customize-checks">
      <label class="wf-checkfield"><input type="checkbox" name="show_intro" value="1" <?= (int)$settings['show_intro'] === 1 ? 'checked' : '' ?>><span>소개글 표시</span></label>
      <label class="wf-checkfield"><input type="checkbox" name="show_post_summary" value="1" <?= (int)$settings['show_post_summary'] === 1 ? 'checked' : '' ?>><span>글 요약 표시</span></label>
      <label class="wf-checkfield"><input type="checkbox" name="show_visit_count" value="1" <?= (int)$settings['show_visit_count'] === 1 ? 'checked' : '' ?>><span>방문자 수 표시</span></label>
    </div>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="blog.php?id=<?= $userId ?>">내 블로그 보기</a>
      <button type="submit" class="btn-primary">저장</button>
    </div>
  </form>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
