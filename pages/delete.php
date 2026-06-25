<?php
/**
 * delete.php — 글 삭제 (본인 글만).
 *   GET: 삭제 확인 화면.  POST: 실제 삭제(자식 row·이미지 파일까지 정리) 후 index 로.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$userId = $_SESSION['user_id'];
$postId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// 글 조회 + 소유권 확인 (남의 글/없는 글이면 차단)
$stmt = $conn->prepare("SELECT id, user_id, title, thumbnail_stored FROM posts WHERE id = ?");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$post || $post['user_id'] != $userId) {
    header('Location: index.php');
    exit;
}

// ── POST: 실제 삭제 ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 자식 row 먼저 정리 (FK 제약/고아 데이터 방지)
    foreach (['post_tags', 'comments', 'likes'] as $table) {
        $stmt = $conn->prepare("DELETE FROM $table WHERE post_id = ?");
        $stmt->bind_param("i", $postId);
        $stmt->execute();
        $stmt->close();
    }

    // 본문 이미지 파일 삭제 (post_images 행은 글 삭제 시 CASCADE 로 정리됨)
    $stmt = $conn->prepare("SELECT stored FROM post_images WHERE post_id = ?");
    $stmt->bind_param("i", $postId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $im) {
        @unlink(__DIR__ . '/../uploads/' . $im['stored']);
    }
    $stmt->close();

    // 글 삭제 (소유권 한 번 더)
    $stmt = $conn->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $postId, $userId);
    $stmt->execute();
    $stmt->close();

    // 썸네일 파일 삭제
    if (!empty($post['thumbnail_stored'])) {
        @unlink(__DIR__ . '/../uploads/' . $post['thumbnail_stored']);
    }

    header('Location: index.php');
    exit;
}

$pageTitle = '글 삭제 · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';
?>

<section class="confirm">
  <h1>글을 삭제할까요?</h1>
  <p class="confirm__target">“<?= htmlspecialchars($post['title']) ?>”</p>
  <p class="confirm__warn">삭제하면 댓글·공감·태그까지 함께 지워지고 되돌릴 수 없어요.</p>

  <form method="post" action="delete.php" class="confirm__actions">
    <input type="hidden" name="id" value="<?= (int)$postId ?>">
    <a class="btn-ghost-dark" href="view.php?id=<?= (int)$postId ?>">취소</a>
    <button type="submit" class="btn-danger">삭제</button>
  </form>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

