<?php
/**
 * db.php — 데이터베이스 접속 (mysqli)
 * 다른 PHP 파일에서 require 해서 $conn 을 받아 사용합니다.
 *
 *   require_once __DIR__ . '/db.php';
 *   $stmt = $conn->prepare("SELECT ...");
 */

$DB_HOST = 'localhost';
$DB_NAME = 'blog';
$DB_USER = 'user1';
$DB_PASS = '1234';          // 본인 MySQL 비밀번호로 변경

// mysqli 객체 생성
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// 연결 실패 시 중단
if ($conn->connect_error) {
    http_response_code(500);
    exit('DB 연결 실패: ' . $conn->connect_error);
}

// 한글 깨짐 방지 — 문자셋을 utf8mb4 로 설정
$conn->set_charset('utf8mb4');
