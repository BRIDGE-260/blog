<?php
/**
 * manage.php — 내 글 관리 (내 블로그로 통합됨).
 *   기존 링크/북마크·임시저장 리다이렉트 호환용.
 *   실제 화면은 blog.php(내 블로그)의 본인 보기 + 상태 탭(전체/발행/임시저장)이 담당.
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
$status = $_GET['status'] ?? '';
$url = 'blog.php?id=' . (int)$_SESSION['user_id'];
if (in_array($status, ['published', 'draft'], true)) $url .= '&status=' . $status;
header('Location: ' . $url);
exit;
