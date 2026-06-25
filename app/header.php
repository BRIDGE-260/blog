<?php
/**
 * header.php — 모든 "일반 페이지" 상단에 include 하는 공통 머리말.
 *
 * 하는 일:
 *   1) 세션 시작 + db.php 로 $conn 준비
 *   2) <!DOCTYPE> ~ <head> ~ 상단바(topbar) 까지 출력
 *
 * 사용법 (페이지에서):
 *   $pageTitle = '글쓰기 · BRIDGE 206';    // (선택) 안 정하면 기본값
 *   require_once __DIR__ . '/header.php';
 *   ... 페이지 내용 ...
 *   require_once __DIR__ . '/footer.php';
 *
 * 주의: 로그인 검사 후 redirect 가 필요한 페이지는, header.php 를 include 하기
 *       "전에" 검사해야 함 (header.php 가 HTML 을 출력하면 header() 리다이렉트 불가).
 */

// 이미 세션이 시작돼 있으면(=페이지에서 먼저 session_start 했으면) 중복 호출 방지
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

// 페이지가 $pageTitle 을 안 정했으면 기본값 사용
$pageTitle = $pageTitle ?? 'BRIDGE 206';
$pageClass = $pageClass ?? '';
// 로그인했으면 닉네임, 아니면 null
$loginNickname = $_SESSION['nickname'] ?? null;
$flashToast = $_SESSION['flash_toast'] ?? '';
unset($_SESSION['flash_toast']);

// 상단바 아바타용 — 로그인 유저의 프로필 이미지(없으면 null)
$loginAvatar = null;
$unreadNotifications = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT profile_image_stored, notifications_read_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $loginUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $loginAvatar = $loginUser['profile_image_stored'] ?? null;

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM (
            SELECT CONCAT('comment:', cm.id) AS nkey
            FROM comments cm
            JOIN posts p ON p.id = cm.post_id
            WHERE p.user_id = ? AND cm.user_id <> ?
            UNION ALL
            SELECT CONCAT('like:', l.id) AS nkey
            FROM likes l
            JOIN posts p ON p.id = l.post_id
            WHERE p.user_id = ? AND l.user_id <> ?
            UNION ALL
            SELECT CONCAT('neighbor_post:', p.id) AS nkey
            FROM posts p
            JOIN neighbors n ON n.neighbor_id = p.user_id AND n.user_id = ?
            WHERE p.status = 'published'
              AND p.visibility IN ('all', 'neighbor')
              AND p.user_id <> ?
            UNION ALL
            SELECT CONCAT('guestbook:', g.id) AS nkey
            FROM guestbook g
            WHERE g.owner_id = ? AND g.user_id <> ?
         ) n
         LEFT JOIN notification_reads nr
           ON nr.user_id = ? AND nr.notification_key = n.nkey
         WHERE nr.id IS NULL"
    );
    $stmt->bind_param(
        "iiiiiiiii",
        $_SESSION['user_id'], $_SESSION['user_id'],
        $_SESSION['user_id'], $_SESSION['user_id'],
        $_SESSION['user_id'], $_SESSION['user_id'],
        $_SESSION['user_id'], $_SESSION['user_id'],
        $_SESSION['user_id']
    );
    $stmt->execute();
    $unreadNotifications = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<script>
(function () {
  var saved = localStorage.getItem('bridge206FontSize') || 'normal';
  if (!/^(normal|large|xlarge)$/.test(saved)) saved = 'normal';
  document.documentElement.setAttribute('data-font-size', saved);
})();
</script>
<link rel="stylesheet" href="../assets/css/style.css?v=20260625a">
</head>
<body <?= $flashToast !== '' ? 'data-flash-toast="' . htmlspecialchars($flashToast, ENT_QUOTES) . '"' : '' ?>>

<header class="topbar">
  <button class="topbar__toggle" type="button" aria-label="메뉴 열기" aria-controls="sideMenu" aria-expanded="false" data-menu-open>
    <span></span>
    <span></span>
    <span></span>
  </button>
  <a class="topbar__brand" href="index.php">BRIDGE<span>206</span></a>
</header>

<div class="menu-dim" data-menu-close hidden></div>
<aside class="side-menu" id="sideMenu" aria-hidden="true" inert>
  <div class="side-menu__head">
    <div class="side-menu__kicker">BRIDGE 206</div>
    <div class="side-menu__brand">BRIDGE<span>206</span></div>
    <p>20대와 60대를 넘어<br>모든 세대를 잇는 블로그</p>
    <div class="side-menu__stripe" aria-hidden="true">
      <span></span><span></span><span></span>
    </div>
    <button class="side-menu__close" type="button" aria-label="메뉴 닫기" data-menu-close>×</button>
  </div>

  <nav class="side-menu__nav" aria-label="전체 메뉴">
    <a href="index.php">블로그 홈</a>
    <?php if ($loginNickname): ?>
      <a href="write.php">글쓰기</a>
      <a href="blog.php?id=<?= (int)$_SESSION['user_id'] ?>">내 블로그</a>
      <a href="neighbors.php">이웃</a>
      <a class="topbar__noti" href="notifications.php">
        소식
        <?php if ($unreadNotifications > 0): ?>
          <span class="topbar__badge"><?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?></span>
        <?php endif; ?>
      </a>
      <a href="scraps.php">스크랩</a>
      <a class="topbar__me" href="profile.php">
        <span class="topbar__avatar">
          <?php if (!empty($loginAvatar)): ?>
            <img src="../uploads/<?= htmlspecialchars($loginAvatar) ?>" alt="">
          <?php else: ?>
            <?= htmlspecialchars(mb_substr($loginNickname, 0, 1)) ?>
          <?php endif; ?>
        </span>
        <span><?= htmlspecialchars($loginNickname) ?>님</span>
      </a>
      <a href="logout.php">로그아웃</a>
    <?php else: ?>
      <a href="auth.php">로그인</a>
    <?php endif; ?>
  </nav>

  <section class="font-tools" aria-label="글자 크기 설정">
    <strong>글자 크기</strong>
    <div class="font-tools__buttons" data-font-size-control>
      <button type="button" data-font-size-option="normal">보통</button>
      <button type="button" data-font-size-option="large">크게</button>
      <button type="button" data-font-size-option="xlarge">가장 크게</button>
    </div>
  </section>

</aside>

<main class="page<?= $pageClass !== '' ? ' ' . htmlspecialchars($pageClass, ENT_QUOTES) : '' ?>">

