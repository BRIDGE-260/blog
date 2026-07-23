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
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isIndexPage = $currentPage === 'index.php';
// 로그인했으면 닉네임, 아니면 null
$loginNickname = $_SESSION['nickname'] ?? null;
$flashToast = $_SESSION['flash_toast'] ?? '';
unset($_SESSION['flash_toast']);
$siteNotice = '';
$siteSettingsResult = $conn->query("SHOW TABLES LIKE 'site_settings'");
if ($siteSettingsResult && $siteSettingsResult->num_rows > 0) {
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'site_notice'");
    $stmt->execute();
    $siteNotice = trim((string)($stmt->get_result()->fetch_assoc()['setting_value'] ?? ''));
    $stmt->close();
}

// 상단바 아바타용 — 로그인 유저의 프로필 이미지(없으면 null)
$loginAvatar = null;
$loginIsAdmin = false;
$unreadNotifications = 0;
$unreadMessages = 0;
$loginPoints = null;
$loginBadgeLabel = null;
if (isset($_SESSION['user_id'])) {
    $adminColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
    $hasAdminColumn = $adminColumnResult && $adminColumnResult->num_rows > 0;
    $banColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    $hasBanColumn = $banColumnResult && $banColumnResult->num_rows > 0;
    $lastSeenColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'");
    $hasLastSeenColumn = $lastSeenColumnResult && $lastSeenColumnResult->num_rows > 0;
    if ($hasLastSeenColumn) {
        $stmt = $conn->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->close();
    }
    $loginUserSql = "SELECT profile_image_stored, notifications_read_at, "
        . ($hasAdminColumn ? "is_admin" : "0 AS is_admin") . ", "
        . ($hasBanColumn ? "is_banned, banned_reason" : "0 AS is_banned, NULL AS banned_reason")
        . " FROM users WHERE id = ?";
    $stmt = $conn->prepare($loginUserSql);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $loginUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $loginAvatar = $loginUser['profile_image_stored'] ?? null;
    $loginIsAdmin = (int)($loginUser['is_admin'] ?? 0) === 1;
    if ((int)($loginUser['is_banned'] ?? 0) === 1) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: auth.php?banned=1');
        exit;
    }

    $commentLikesTableResult = $conn->query("SHOW TABLES LIKE 'comment_likes'");
    $hasCommentLikes = $commentLikesTableResult && $commentLikesTableResult->num_rows > 0;
    $commentLikeUnreadSql = $hasCommentLikes
        ? "UNION ALL
            SELECT CONCAT('comment_like:', cl.id) AS nkey
            FROM comment_likes cl
            JOIN comments cm ON cm.id = cl.comment_id AND cm.user_id = ?
            WHERE cl.user_id <> ?"
        : "";
    $unreadSql = "SELECT COUNT(*) AS cnt
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
            $commentLikeUnreadSql
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
         WHERE nr.id IS NULL";
    $stmt = $conn->prepare($unreadSql);
    if ($hasCommentLikes) {
        $stmt->bind_param(
            "iiiiiiiiiii",
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id']
        );
    } else {
        $stmt->bind_param(
            "iiiiiiiii",
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id'], $_SESSION['user_id'],
            $_SESSION['user_id']
        );
    }
    $stmt->execute();
    $unreadNotifications = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    $messagesTableResult = $conn->query("SHOW TABLES LIKE 'messages'");
    if ($messagesTableResult && $messagesTableResult->num_rows > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $unreadMessages = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $pointTableResult = $conn->query("SHOW TABLES LIKE 'point_wallets'");
    if ($pointTableResult && $pointTableResult->num_rows > 0) {
        require_once __DIR__ . '/points.php';
        bridge_daily_visit_points($conn, (int)$_SESSION['user_id']);
        $loginPoints = bridge_point_balance($conn, (int)$_SESSION['user_id']);
        $badgeTableResult = $conn->query("SHOW TABLES LIKE 'user_point_badges'");
        if ($badgeTableResult && $badgeTableResult->num_rows > 0) {
            $stmt = $conn->prepare("SELECT badge_code FROM user_point_badges WHERE user_id = ? AND is_equipped = 1 LIMIT 1");
            $headerUserId = (int)$_SESSION['user_id'];
            $stmt->bind_param("i", $headerUserId);
            $stmt->execute();
            $equippedCode = $stmt->get_result()->fetch_assoc()['badge_code'] ?? '';
            $stmt->close();
            $badgeMap = bridge_point_badges();
            $loginBadgeLabel = $badgeMap[$equippedCode]['label'] ?? null;
        }
    }
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
  var theme = localStorage.getItem('bridge206Theme') || 'light';
  if (!/^(light|dark)$/.test(theme)) theme = 'light';
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
<link rel="stylesheet" href="../assets/css/style.css?v=20260716ai">
</head>
<body <?= $flashToast !== '' ? 'data-flash-toast="' . htmlspecialchars($flashToast, ENT_QUOTES) . '"' : '' ?>>

<header class="topbar">
  <a class="topbar__brand" href="index.php" aria-label="BRIDGE 206 home">
    <img class="site-logo site-logo--light" src="../assets/images/bridge206-logo.png" alt="BRIDGE 206">
    <img class="site-logo site-logo--dark" src="../assets/images/bridge206-logo-white.png" alt="" aria-hidden="true">
  </a>
    <nav class="topbar__nav" aria-label="주요 메뉴">
      <?php if ($loginNickname): ?>
        <a href="write.php">글쓰기</a>
        <a href="neighbors.php">이웃</a>
        <a class="topbar__noti" href="messages.php">
          쪽지
          <?php if ($unreadMessages > 0): ?>
            <span class="topbar__badge"><?= $unreadMessages > 99 ? '99+' : (int)$unreadMessages ?></span>
          <?php endif; ?>
        </a>
        <a class="topbar__noti" href="notifications.php">
          소식
          <?php if ($unreadNotifications > 0): ?>
            <span class="topbar__badge"><?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?></span>
          <?php endif; ?>
        </a>
        <details class="topbar-profile">
          <summary aria-label="내 메뉴 열기">
            <span class="topbar__avatar">
              <?php if (!empty($loginAvatar)): ?>
                <img src="../uploads/<?= htmlspecialchars($loginAvatar) ?>" alt="">
              <?php else: ?>
                <?= htmlspecialchars(mb_substr($loginNickname, 0, 1)) ?>
              <?php endif; ?>
            </span>
            <span><?= htmlspecialchars($loginNickname) ?>님</span>
            <?php if ($loginBadgeLabel): ?><b class="topbar-point-badge"><?= htmlspecialchars($loginBadgeLabel) ?></b><?php endif; ?>
          </summary>
          <div class="topbar-profile__menu">
            <a href="blog.php?id=<?= (int)$_SESSION['user_id'] ?>">내 블로그</a>
            <a href="profile.php">프로필 수정</a>
            <a class="topbar-profile__logout" href="logout.php">로그아웃</a>
          </div>
        </details>
      <?php else: ?>
        <a href="auth.php">로그인</a>
      <?php endif; ?>
    </nav>
  <button class="topbar__toggle" type="button" aria-label="Open menu" aria-controls="sideMenu" aria-expanded="false" data-menu-open>
    <span></span>
    <span></span>
    <span></span>
  </button>
</header>

<div class="menu-dim" data-menu-close hidden></div>
<aside class="side-menu" id="sideMenu" aria-hidden="true" inert>
  <div class="side-menu__head">
    <div class="side-menu__kicker">BRIDGE 206</div>
    <div class="side-menu__brand">
      <img class="site-logo site-logo--light" src="../assets/images/bridge206-logo.png" alt="BRIDGE 206">
      <img class="site-logo site-logo--dark" src="../assets/images/bridge206-logo-white.png" alt="" aria-hidden="true">
    </div>
    <p>20대와 60대를 넘어<br>모든 세대를 잇는 블로그</p>
    <button class="side-menu__close" type="button" aria-label="Close menu" data-menu-close>×</button>
  </div>

  <nav class="side-menu__nav" aria-label="전체 메뉴">
    <a class="side-menu__home" href="index.php">블로그 홈</a>
    <?php if ($loginNickname): ?>
      <a href="write.php">글쓰기</a>
      <a href="blog.php?id=<?= (int)$_SESSION['user_id'] ?>">내 블로그</a>
      <a href="neighbors.php">이웃</a>
      <a class="side-menu__point-link" href="points.php">
        <span>포인트</span>
        <?php if ($loginPoints !== null): ?><b><?= number_format($loginPoints) ?>P</b><?php endif; ?>
      </a>
      <a class="topbar__noti" href="notifications.php">
        소식
        <?php if ($unreadNotifications > 0): ?>
          <span class="topbar__badge"><?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?></span>
        <?php endif; ?>
      </a>
      <?php if ($loginIsAdmin): ?>
        <a href="admin.php">관리자</a>
      <?php endif; ?>
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

  <section class="font-tools theme-tools" aria-label="화면 테마 설정">
    <strong>화면 테마</strong>
    <div class="font-tools__buttons" data-theme-control>
      <button type="button" data-theme-option="light">라이트</button>
      <button type="button" data-theme-option="dark">다크</button>
    </div>
  </section>

</aside>

<main class="page<?= $pageClass !== '' ? ' ' . htmlspecialchars($pageClass, ENT_QUOTES) : '' ?>">
<?php if ($siteNotice !== ''): ?>
  <div class="site-notice"><?= nl2br(htmlspecialchars($siteNotice)) ?></div>
<?php endif; ?>

