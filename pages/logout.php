<?php
/**
 * logout.php — 로그아웃.
 *   세션을 비우고 로그인 화면으로 보낸다.
 */

session_start();
$_SESSION = [];          // 세션 변수 비우기
session_destroy();       // 세션 자체 종료
header('Location: auth.php');
exit;

