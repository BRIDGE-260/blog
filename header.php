<?php
/**
 * header.php — 모든 "일반 페이지" 상단에 include 하는 공통 머리말.
 *
 * 하는 일:
 *   1) 세션 시작 + db.php 로 $conn 준비
 *   2) <!DOCTYPE> ~ <head> ~ 상단바(topbar) 까지 출력
 *
 * 사용법 (페이지에서):
 *   $pageTitle = '글쓰기 · MyBlog';        // (선택) 안 정하면 기본값
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
$pageTitle = $pageTitle ?? 'MyBlog';
// 로그인했으면 닉네임, 아니면 null
$loginNickname = $_SESSION['nickname'] ?? null;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="topbar">
  <a class="topbar__brand" href="index.php">My<span>Blog</span></a>
  <nav class="topbar__nav">
    <?php if ($loginNickname): ?>
      <span class="topbar__user"><?= htmlspecialchars($loginNickname) ?>님</span>
    <?php else: ?>
      <a href="auth.php">로그인</a>
    <?php endif; ?>
  </nav>
</header>

<main class="page">
