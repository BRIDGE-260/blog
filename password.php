<?php
/**
 * password.php — 비밀번호 변경.
 *   현재 비밀번호 확인(password_verify) → 새 비밀번호 저장(password_hash).
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

// ── POST: 변경 ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password']     ?? '';
    $new     = $_POST['new_password']         ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $error = '모든 항목을 입력해주세요.';
    } elseif ($new !== $confirm) {
        $error = '새 비밀번호가 서로 일치하지 않습니다.';
    } elseif ($new === $current) {
        $error = '새 비밀번호가 현재 비밀번호와 같습니다.';
    } else {
        // 현재 비밀번호 확인
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $hash = $stmt->get_result()->fetch_assoc()['password'] ?? '';
        $stmt->close();

        if (!password_verify($current, $hash)) {
            $error = '현재 비밀번호가 올바르지 않습니다.';
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $newHash, $userId);
            $stmt->execute();
            $stmt->close();
            $saved = true;
        }
    }
}

$pageTitle = '비밀번호 변경 · MyBlog';
require_once __DIR__ . '/header.php';
?>

<section class="setting">
  <h1>비밀번호 변경</h1>

  <?php if ($saved): ?>
    <div class="form-ok">비밀번호가 변경되었습니다.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form class="write-form" method="post" action="password.php">
    <label class="wf-field">
      <span>현재 비밀번호</span>
      <input type="password" name="current_password" required>
    </label>

    <label class="wf-field">
      <span>새 비밀번호</span>
      <input type="password" name="new_password" required>
    </label>

    <label class="wf-field">
      <span>새 비밀번호 확인</span>
      <input type="password" name="new_password_confirm" required>
    </label>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="profile.php">취소</a>
      <button type="submit" class="btn-primary">변경</button>
    </div>
  </form>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
