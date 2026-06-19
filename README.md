# MyBlog

PHP + MySQL로 만든 네이버 블로그 스타일 학교 실습 프로젝트입니다.

## 실행 환경

- XAMPP
- PHP
- MySQL 또는 MariaDB
- DB 접속은 `mysqli` 사용

## 처음 실행 순서

1. 프로젝트 폴더를 XAMPP의 웹 루트 아래에 둡니다.
   예: `C:\xampp\htdocs\blog`

2. DB 접속 파일을 만듭니다.

   PowerShell 기준:

   ```powershell
   Copy-Item app\db.example.php app\db.php
   ```

   그 다음 `app/db.php`에서 본인 컴퓨터의 DB 정보에 맞게 수정합니다.

   기본 예시:

   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'blog';
   $DB_USER = 'user1';
   $DB_PASS = '1234';
   ```

3. phpMyAdmin에서 `blog` 데이터베이스를 만듭니다.

4. SQL 파일을 순서대로 가져오기 합니다.

   1. `database/blog_schema.sql`
   2. `database/blog_sample_data.sql`

5. 브라우저에서 접속합니다.

   ```text
   http://localhost/blog/
   ```

## 샘플 계정

샘플 데이터의 계정 비밀번호는 모두 `1234`입니다.

- `stephane@blog.com`
- `yujin@blog.com`
- `mina@blog.com`
- `hoonie@blog.com`
- `sora@blog.com`
- `junho@blog.com`

## DB 점검 / 재시드

기본 데이터는 `database/blog_schema.sql` → `database/blog_sample_data.sql` 순서로 가져오기 하면 됩니다.

DB 연결이나 테이블 생성이 제대로 됐는지 확인하려면 아래 파일을 브라우저에서 엽니다.

```text
http://localhost/blog/tools/check_setup.php
```

개발 중 데이터를 다시 만들고 싶을 때는 선택적으로 아래 파일을 실행할 수 있습니다.

```text
http://localhost/blog/tools/seed.php
```

단, 이 파일은 기존 데이터를 비우고 데모 데이터를 다시 넣는 용도이므로 제출 직전에는 SQL 파일 기준으로 확인하는 것을 권장합니다.

## 주요 기능

- 회원가입 / 로그인 / 로그아웃
- 프로필 수정, 비밀번호 변경, 회원 탈퇴
- 블로그 메인: 전체 공개 글, 인기글 6개, 인기 태그, 카테고리 필터, 검색
- 내 블로그: 프로필, 방문자 수, 카테고리, 글 목록, 임시저장 글 관리
- 글쓰기 / 글수정
  - 본문 위지윅 편집기
  - 본문 중간 이미지 삽입
  - 이미지 드래그 이동
  - 꼭짓점 핸들로 이미지 크기 조절
  - 이미지 여러 장 선택 안내
  - 태그 입력
  - 공지글 상단 고정
- 글 상세
  - 조회수
  - 공감 / 스크랩
  - 공감한 사람 목록
  - 링크 복사
  - 이미지 클릭 확대
  - 이전 / 다음 글
- 댓글
  - AJAX 댓글 작성 / 수정 / 삭제
  - 1단계 대댓글
  - 유튜브식으로 줄인 댓글 UI
  - 삭제 확인 모달
- 방명록
  - 블로그별 방명록
  - 내 방명록 직접 작성 방지
  - 삭제 확인 모달
- 이웃
  - 이웃 추가 / 취소
  - 블로그 찾기
  - 이웃 새 글 보기
- 내 소식
  - 댓글, 공감, 이웃 새 글, 방명록 알림
  - 읽음 / 안 읽음 구분
  - 소식 항목 클릭 시 개별 읽음 처리
  - 상단 네비 소식 뱃지
  - 소식에서 들어간 글/방명록의 `소식으로 돌아가기` 링크
- 카테고리 관리
  - 추가 / 이름 변경 / 삭제
  - 위아래 버튼 순서 변경
  - 드래그 앤 드롭 순서 변경
- 공통 UX
  - 회원가입 / 로그인 성공 토스트
  - 화면 상단 중앙 토스트
  - 공통 확인 모달

## DB 테이블

현재 SQL 기준으로 사용하는 주요 테이블은 다음과 같습니다.

- `users`
- `blog_settings`
- `categories`
- `posts`
- `comments`
- `likes`
- `neighbors`
- `tags`
- `post_tags`
- `visit_logs`
- `post_images`
- `scraps`
- `guestbook`
- `notification_reads`

## 파일 구조

- `app/` 공통 PHP 파일
- `pages/` 화면 PHP 파일
- `api/` AJAX JSON 처리 파일
- `assets/` CSS, JS
- `database/` DB schema/sample SQL
- `uploads/` 업로드 이미지

## 주의

- `app/db.php`는 개인 DB 비밀번호가 들어갈 수 있어서 커밋하지 않습니다.
- 대신 `app/db.example.php`를 커밋하고, 각자 복사해서 `app/db.php`를 만듭니다.
- SQL 실행은 반드시 `blog_schema.sql` 다음 `blog_sample_data.sql` 순서로 합니다.
- 기존 DB가 있다면 새 기능을 위해 `notification_reads` 테이블이 필요합니다. 새로 세팅하는 경우에는 `blog_schema.sql`에 포함되어 있습니다.
