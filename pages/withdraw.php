<?php
/**
 * withdraw.php — 회원 탈퇴.
 *   GET: 확인 화면(비밀번호 입력).  POST: 비번 확인 후 계정 삭제.
 *   users 의 모든 자식 FK 가 ON DELETE CASCADE 라, DELETE FROM users 한 번이면
 *   내 글·댓글·공감·이웃·카테고리·방문기록 + (내 글에 달린 남의 댓글/공감/이미지)까지 전부 정리됨.
 *   단, 디스크의 업로드 파일은 FK 와 무관하므로 삭제 전에 목록을 모아 직접 unlink.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];
$error  = '';

// ── POST: 탈퇴 실행 ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    // 비밀번호 확인
    $stmt = $conn->prepare("SELECT password, profile_image_stored FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($password === '' || !$me || !password_verify($password, $me['password'])) {
        $error = '비밀번호가 올바르지 않습니다.';
    } else {
        $uploadDir = __DIR__ . '/../uploads';
        $files = [];   // 삭제할 파일명 모음

        if (!empty($me['profile_image_stored'])) $files[] = $me['profile_image_stored'];

        // 내 글의 썸네일
        $stmt = $conn->prepare("SELECT thumbnail_stored FROM posts WHERE user_id = ? AND thumbnail_stored IS NOT NULL");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $files[] = $r['thumbnail_stored'];
        $stmt->close();

        // 내 글의 본문 이미지
        $stmt = $conn->prepare(
            "SELECT pi.stored FROM post_images pi
             JOIN posts p ON p.id = pi.post_id
             WHERE p.user_id = ?"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $files[] = $r['stored'];
        $stmt->close();

        // 계정 삭제 (자식 데이터는 FK CASCADE 로 함께 삭제)
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        // 디스크 파일 정리
        foreach ($files as $f) @unlink($uploadDir . '/' . $f);

        // 세션 종료 후 메인으로
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$pageTitle = '회원 탈퇴 · MyBlog';
require_once __DIR__ . '/../app/header.php';
?>

<section class="setting">
  <h1>회원 탈퇴</h1>

  <?php if ($error): ?>
    <div class="form-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <p class="confirm__warn">
    탈퇴하면 내가 쓴 글·댓글·공감·이웃·카테고리와 업로드한 이미지가
    모두 삭제되고 되돌릴 수 없어요. 계속하려면 비밀번호를 입력하세요.
  </p>

  <form class="write-form" method="post" action="withdraw.php">
    <label class="wf-field">
      <span>비밀번호 확인</span>
      <input type="password" name="password" required>
    </label>

    <div class="wf-actions">
      <a class="btn-ghost-dark" href="profile.php">취소</a>
      <button type="submit" class="btn-danger">탈퇴하기</button>
    </div>
  </form>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

