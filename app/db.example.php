<?php
/**
 * db.example.php - db.php 만들 때 참고하는 예시 파일
 *
 * 이 파일은 커밋해서 팀원에게 공유합니다.
 * 실제 실행 파일은 app/db.php 이고, app/db.php 는 각자 컴퓨터에서 따로 만듭니다.
 *
 * 사용 방법:
 *   1. 이 파일을 복사해서 app/db.php 를 만듭니다.
 *      PowerShell: Copy-Item app\db.example.php app\db.php
 *   2. 아래 DB 접속 정보를 자기 XAMPP/MySQL 환경에 맞게 수정합니다.
 *
 * 주의:
 *   - 이 파일 이름을 직접 db.php 로 바꾸지 말고, 복사본을 만드세요.
 *   - app/db.php 는 .gitignore 에 들어 있어서 커밋되지 않습니다.
 */

// XAMPP 기본 환경 예시입니다. 다르면 각자 수정하세요.
$DB_HOST = 'localhost';
$DB_NAME = 'blog';
$DB_USER = 'user1';
$DB_PASS = '1234';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    exit('DB 연결 실패: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
