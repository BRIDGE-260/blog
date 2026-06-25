# BRIDGE 206

PHP + MySQL로 만든 네이버 블로그 스타일 학교 실습 프로젝트입니다.

BRIDGE 206은 20대와 60대를 시작점으로 삼아, 서로 다른 세대가 글과 질문으로 자연스럽게 이어지는 블로그를 목표로 합니다.

## 실행 환경

- XAMPP
- PHP
- MySQL 또는 MariaDB
- DB 접근 방식: `mysqli`
- 프론트엔드: 순수 HTML/CSS/JS

## 처음 실행 순서

1. 프로젝트 폴더를 XAMPP 웹 루트 아래에 둡니다.

   ```text
   C:\xampp\htdocs\blog
   ```

2. `app/db.php`를 준비합니다.

   기본 접속 정보는 다음과 같습니다.

   ```php
   host=localhost
   username=user1
   password=1234
   database=blog
   ```

3. phpMyAdmin에서 `blog` 데이터베이스를 만듭니다.

4. SQL 파일을 순서대로 실행합니다.

   새 DB를 만들 때:

   ```text
   database/blog_schema.sql
   database/blog_sample_data.sql
   ```

   기존 DB에 관리자 권한 컬럼만 추가할 때:

   ```text
   database/add_admin_role.sql
   ```

5. 브라우저에서 접속합니다.

   ```text
   http://localhost/blog/
   ```

## 샘플 계정

샘플 계정 비밀번호는 모두 `1234`입니다.

- `stephane@blog.com`
- `yujin@blog.com`
- `mina@blog.com`
- `hoonie@blog.com`
- `sora@blog.com`
- `junho@blog.com`

샘플 데이터 기준 `id = 1` 계정은 관리자 권한(`is_admin = 1`)을 갖습니다.

## 주요 기능

- 회원가입, 로그인, 로그아웃
- 프로필 수정, 비밀번호 변경, 회원 탈퇴
- 전체 공개 글 메인 피드
- 검색, 카테고리 필터, 태그 필터, 인기 글, 인기 태그
- 내 블로그 화면
  - 프로필 카드
  - 카테고리 목록
  - 방문자 수
  - 상태별 글 관리
  - 오른쪽 보조 패널은 보통 글자 크기와 넓은 화면에서만 표시
- 블로그 꾸미기
  - 포인트 색, 배경색, 프로필 카드 색
  - 헤더/배경 이미지
  - 레이아웃, 사이드바 위치, 프로필 모양
  - 목록 스타일, 썸네일 스타일, 폰트 스타일
- 글쓰기/글수정
  - contenteditable 위지윅 편집기
  - 본문 중간 이미지 삽입
  - 이미지 크기 조절 토큰 저장
  - 태그 입력
  - 임시저장/발행
  - 공지글 상단 고정
- 글 상세
  - 조회수
  - 공감
  - 스크랩
  - 공감한 사람 목록
  - 링크 복사
  - 이미지 라이트박스
  - 이전/다음 글
- 댓글
  - AJAX 작성, 수정, 삭제
  - 1단계 대댓글
  - 공통 삭제 확인 모달
- 방명록
  - 블로그별 방명록
  - 로그인 사용자 작성
  - 글쓴이 또는 블로그 주인 삭제
- 이웃
  - 이웃 추가/취소
  - 블로그 찾기
  - 이웃 새 글 보기
- 내 소식
  - 댓글, 공감, 이웃 새 글, 방명록 알림
  - 읽음/안 읽음 구분
  - 항목별 읽음 처리
- 카테고리 관리
  - 추가, 이름 변경, 삭제
  - 위/아래 버튼 순서 변경
  - 드래그 앤 드롭 순서 변경
- 관리자 대시보드
  - 관리자 권한 컬럼: `users.is_admin`
  - 관리자 메뉴는 관리자에게만 표시
  - 직접 `admin.php`로 들어와도 권한 검사
  - 전체 회원/글/댓글/방명록/공감/스크랩/방문 현황 확인
  - 최근 회원, 최근 글, 최근 댓글, 최근 방명록, 인기 태그, 방문 많은 블로그 확인

## BRIDGE 206 접근성/디자인

- 전 화면 글자 크기 설정 지원
  - `보통`
  - `크게`
  - `가장 크게`
- 선택값은 `localStorage`의 `bridge206FontSize`에 저장됩니다.
- 큰 글씨 모드에서는 단순 확대가 아니라 버튼 높이, 카드 여백, 검색창, 카테고리, 블로그 레이아웃이 함께 조정됩니다.
- 로그인/회원가입 화면은 슬라이딩 전환을 유지하면서 글자 크기 설정 팝업을 별도 버튼으로 열도록 수정했습니다.
- 큰 글씨 모드에서 공간이 부족한 보조 패널은 숨겨 레이아웃이 세로로 찢어지지 않게 처리했습니다.

## DB 테이블

현재 사용하는 테이블은 다음 14개입니다.

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

관리자 권한은 `users.is_admin` 컬럼으로 관리합니다.

## 파일 구조

- `app/`: 공통 PHP 파일
- `pages/`: 화면 PHP 파일
- `api/`: AJAX JSON 엔드포인트
- `assets/`: CSS, JS
- `database/`: DB schema/sample/migration SQL
- `tools/`: 실행/점검 도구
- `uploads/`: 업로드 이미지

## 주의 사항

- SQL은 사용자 입력을 직접 붙이지 않고 prepared statement를 사용합니다.
- 비밀번호는 `password_hash()`로 저장하고 `password_verify()`로 검증합니다.
- 사용자 입력 출력은 `htmlspecialchars()`로 감싸 XSS를 방지합니다.
- 화면에서 작성자 이름은 실명 `name`이 아니라 `nickname`을 사용합니다.
- 기존 DB를 쓰는 경우 관리자 화면 사용 전 `database/add_admin_role.sql`을 실행해야 합니다.
