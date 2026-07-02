# 블로그 팀 프로젝트 작업 지침

PHP + MySQL로 만드는 네이버 블로그 스타일 웹사이트입니다. 학교 실습 프로젝트이므로 수업에서 배운 `mysqli`, 순수 HTML/CSS/JavaScript, XAMPP localhost 환경을 기준으로 유지합니다.

## 기술 스택 / 환경

- PHP + MySQL 또는 MariaDB
- XAMPP, localhost
- DB 접근은 `mysqli` 사용
- 프론트엔드는 순수 HTML/CSS/JavaScript
- 한 화면은 한 `.php` 파일 구조를 기본으로 하며, PHP가 화면 출력과 폼 처리를 함께 담당합니다.

## DB 접속 정보

`app/db.php` 기준:

```text
host=localhost
username=user1
password=1234
database=blog
```

- 화면 PHP(`pages/`)는 `require_once __DIR__ . '/../app/db.php';`로 `$conn`을 사용합니다.
- 공통 PHP(`app/`)끼리는 `require_once __DIR__ . '/db.php';`를 사용합니다.

## 코딩 규칙

- SQL은 항상 prepared statement를 사용합니다: `prepare()` → `bind_param()` → `execute()`.
- 사용자 입력을 SQL 문자열에 직접 붙이지 않습니다.
- `bind_param` 타입 문자는 `i=정수`, `s=문자열`, `d=실수`를 사용합니다.
- `?` 개수, 타입 문자 개수, 변수 개수는 항상 일치해야 합니다.
- 비밀번호는 `password_hash()`로 저장하고 `password_verify()`로 검증합니다.
- 사용자 입력을 화면에 출력할 때는 `htmlspecialchars()`로 감쌉니다.
- 로그인 상태는 `$_SESSION['user_id']`, `$_SESSION['nickname']`으로 관리합니다.
- 화면에 작성자를 표시할 때는 실명 `name`이 아니라 `nickname`을 사용합니다.
- 요청하지 않은 파일이나 기능을 임의로 추가하지 않습니다.
- 변경은 가능한 한 외과적으로 진행합니다.
- 결정이 갈리는 지점은 먼저 묻고, 왜 그렇게 하는지 짧게 설명합니다.

## 데이터베이스 구조

`database/blog_schema.sql`로 구조를 만들고, `database/blog_sample_data.sql`로 샘플 데이터를 넣습니다.

실행 순서:

```text
database/blog_schema.sql
database/blog_sample_data.sql
```

현재 실제 DB 기준 테이블은 18개입니다. 단, 테이블 수 자체를 목표로 맞추지 않고 단순성/성능/유지보수성이 더 중요하면 증감할 수 있습니다.

1. `users` - 회원 정보. 이메일/닉네임 unique, 프로필 이미지, 블로그 제목/소개, `is_admin`, 소식 확인 시각 포함.
2. `blog_settings` - 블로그 꾸미기 설정. 회원 1명당 1행, 회원 삭제 시 CASCADE.
3. `categories` - 사용자별 카테고리.
4. `posts` - 글. 제목, 본문, 공개범위, 발행 상태, 조회수, 공지 고정(`is_pinned`) 포함.
5. `comments` - 댓글/1단계 답글. `parent_id`가 있으면 답글.
6. `likes` - 글 공감. `UNIQUE(post_id, user_id)`.
7. `neighbors` - 이웃 관계. `UNIQUE(user_id, neighbor_id)`.
8. `tags` - 태그. `name`, `normalized_name`으로 대소문자 차이를 묶음.
9. `post_tags` - 글-태그 N:M 연결.
10. `visit_logs` - 블로그 방문 기록. `UNIQUE(user_id, visit_date)`.
11. `post_images` - 글 본문 첨부 미디어. `media_type`으로 이미지/동영상을 구분하고 원본명과 저장명을 분리.
12. `scraps` - 스크랩/북마크. `UNIQUE(user_id, post_id)`.
13. `guestbook` - 블로그별 방명록.
14. `notification_reads` - 내 소식 항목별 읽음 처리.
15. `messages` - 이웃 간 쪽지.
16. `visit_events` - 시간대/성별 방문 통계 이벤트.
17. `site_settings` - 사이트 공지와 메인 소개 문구 설정.
18. `moderation_logs` - 관리자 운영 조치 기록.

관리자 권한은 별도 테이블이 아니라 `users.is_admin` 컬럼으로 관리합니다. 기존 DB에 컬럼이 없으면 `database/add_admin_role.sql`을 실행합니다.

## 첨부 미디어 처리 규칙

- 이미지/동영상 파일은 원본명(`original`)과 저장명(`stored`)을 분리합니다.
- 업로드 시 파일명을 고유한 이름으로 바꾸고 `uploads/`에 저장합니다.
- DB에는 바이너리를 넣지 않고 파일명만 저장합니다.
- `post_images.media_type`은 `image` 또는 `video`입니다. `mime_type`, `file_size`도 함께 저장합니다.
- 이미지는 8MB 이하, 동영상은 50MB 이하만 저장합니다. 동영상은 자동재생하지 않고 `preload="metadata"`로 렌더링합니다.
- 기존 DB에는 `database/add_post_media_fields.sql`을 1회 실행해 첨부 미디어 컬럼과 인덱스를 추가합니다.

### 썸네일 규칙

- 글쓰기에서 썸네일을 따로 받지 않습니다.
- 목록 카드 썸네일은 해당 글의 첫 이미지 첨부(`post_images.media_type='image'`)를 사용합니다.
- 없으면 구 `posts.thumbnail_stored`를 폴백으로 사용합니다.
- view 상단의 큰 썸네일 박스는 사용하지 않습니다.

### 본문 미디어 삽입

- 본문은 `<textarea>`가 아니라 `contenteditable` 에디터를 사용합니다.
- `assets/js/imageinsert.js`가 이미지/동영상 미리보기, 본문 삽입, 토큰 직렬화를 담당합니다.
- 저장 시 본문 안의 `[[img:newK]]`, `[[video:newK]]` 또는 `|너비%`가 붙은 토큰에 쓰인 파일만 업로드합니다.
- 서버는 실제 첨부 id로 토큰을 치환하고, 등장 순서대로 `sort_order`를 부여합니다.
- `view.php`는 `[[img:id]]`를 `<img>`로, `[[video:id]]`를 `<video controls>`로 렌더링합니다.
- 토큰에 쓰이지 않은 첨부는 본문 아래 갤러리로 표시합니다.

## 태그 처리 규칙

- `tags.name`은 화면 표시용 이름입니다.
- `tags.normalized_name`은 대소문자 차이를 묶기 위한 정규화 이름입니다.
- `#jpop`, `#JPOP`, `#Jpop`은 같은 태그로 묶습니다.
- 글쓰기/수정에서 태그는 한 줄 문자열로 입력받습니다.
- PHP에서 공백 기준으로 분리하고, 있으면 기존 id를 쓰고 없으면 새로 INSERT한 뒤 `post_tags`에 연결합니다.

## 공통 구조

- 일반 페이지 흐름: `session_start` → 로그인 검사 → POST 처리 → `$pageTitle` 지정 → `require header.php` → 본문 → `require footer.php`.
- 로그인 검사나 `header('Location: ...')` 리다이렉트는 `header.php` include 전에 해야 합니다.
- 공통 파일:
  - `app/db.php`
  - `app/header.php`
  - `app/footer.php`
  - `assets/css/style.css`
- 화면 파일은 `pages/`, AJAX JSON 엔드포인트는 `api/`, CSS/JS는 `assets/`, SQL은 `database/`, 실행 도구는 `tools/`에 둡니다.
- 루트 `index.php`는 `pages/index.php`로 리다이렉트합니다.
- 예전 루트 URL(`view.php`, `write.php` 등)은 `.htaccess`로 `pages/` 또는 `api/` 경로에 연결합니다.

## 공개 / 로그인 필요 화면

비로그인 열람 가능:

- `index.php`
- `view.php`
- `blog.php`
- `guestbook.php`

로그인 필요:

- `write.php`
- `modify.php`
- `delete.php`
- `profile.php`
- `password.php`
- `withdraw.php`
- `stats.php`
- `activity.php`
- `notifications.php`
- `neighbors.php`
- `liked.php`
- `scraps.php`
- `categories_manage.php`
- `blog_customize.php`
- `admin.php`

게스트는 viewer id를 `0`으로 두고, 글쓰기/공감/스크랩/댓글/이웃 추가 같은 행동만 로그인 요구로 처리합니다.

## 현재 주요 파일

- `app/db.php` - mysqli 접속
- `app/header.php`, `app/footer.php` - 공통 상단/하단, 사이드 메뉴, 글자 크기, 다크 모드, 토스트, 확인 모달
- `assets/css/style.css` - 공통 스타일, 큰 글씨 모드, 다크 모드, 관리자/내 활동 스타일
- `assets/css/auth.css` - 로그인/회원가입 전용 스타일
- `assets/js/imageinsert.js` - 글쓰기/수정 본문 미디어 삽입
- `assets/js/taginput.js` - 태그 입력 보조
- `pages/auth.php` - 로그인/회원가입
- `pages/index.php` - 메인 피드, 검색, 태그 필터, 인기 태그, BRIDGE 206 소개
- `pages/write.php` - 글쓰기, 본문 이미지/동영상, 태그, 임시저장/발행, 공지 고정
- `pages/modify.php` - 글 수정, 미디어/태그 재동기화
- `pages/view.php` - 글 상세, 공감, 스크랩, 댓글/답글, 이미지 라이트박스, 동영상 렌더링
- `pages/delete.php` - 글 삭제와 업로드 파일 정리
- `pages/blog.php` - 내 블로그, 글 목록, 방문 카운트, 내 글 관리
- `pages/blog_customize.php` - 블로그 꾸미기
- `pages/neighbors.php` - 이웃 및 블로그 찾기
- `pages/notifications.php` - 내 소식
- `pages/activity.php` - 내가 댓글 단 글, 공감한 글, 스크랩한 글 모아보기
- `pages/stats.php` - 블로그 현황
- `pages/admin.php` - 관리자 대시보드, 회원/글 권한과 사이트 설정
- `pages/messages.php` - 이웃 쪽지
- `pages/categories_manage.php` - 카테고리 관리
- `pages/scraps.php` - 스크랩한 글 모아보기
- `pages/liked.php` - 공감한 글 모아보기
- `pages/guestbook.php` - 방명록
- `pages/profile.php` - 프로필 수정
- `pages/password.php` - 비밀번호 변경
- `pages/withdraw.php` - 회원 탈퇴
- `api/api.php` - 공감/스크랩/댓글 AJAX 엔드포인트
- `api/notification_read.php` - 소식 항목별 읽음 처리
- `database/blog_schema.sql` - 18개 테이블 schema
- `database/blog_sample_data.sql` - 샘플 데이터
- `database/add_admin_role.sql` - 기존 DB용 관리자 권한 컬럼 추가

## BRIDGE 206 / 접근성 방향

- 공통 상단/하단 브랜드는 `BRIDGE 206`으로 통일합니다.
- 글자 크기 설정은 `보통`, `크게`, `가장 크게` 3단계입니다.
- 선택값은 `localStorage`의 `bridge206FontSize`에 저장합니다.
- 다크 모드는 `라이트`, `다크` 2단계입니다.
- 선택값은 `localStorage`의 `bridge206Theme`에 저장합니다.
- 큰 글씨 모드에서는 글자 크기뿐 아니라 여백, 버튼 높이, 카드 배치, 검색창, 카테고리, 썸네일 영역이 깨지지 않게 조정합니다.
- 메인 소개 영역의 BRIDGE 206 배지/기능 배지는 클릭 가능한 안내 요소로 사용합니다.
- 글쓰기 화면에는 BRIDGE 206 글감 질문 카드를 제공합니다.
- 이웃/블로그 찾기 화면은 세대와 관심사를 잇는 탐색 화면으로 유지합니다.

## 완료된 주요 작업

- DB SQL 파일 정리: schema → sample 실행 기준 유지
- 내 글 관리 기능을 `blog.php`에 통합
- 본문 첫 이미지 기반 목록 썸네일(동영상은 썸네일 후보에서 제외)
- 글쓰기/수정 contenteditable 에디터 통합
- 공감/스크랩/댓글 AJAX 처리
- 댓글 수정/삭제, 1단계 답글
- 조회수 세션 중복 방지
- 글 삭제 시 업로드 파일 정리
- 이미지 라이트박스
- URL 복사
- 카테고리 관리
- 스크랩/공감 모아보기
- 방명록
- 내 소식 항목별 읽음 처리
- 이웃 새 글 알림
- 관리자 대시보드
- 이웃 접속 중 표시와 쪽지
- 이웃 새 글만 보기
- 시간대/성별 방문 통계와 엑셀 다운로드
- 관리자 회원/글 권한 및 사이트 설정 관리
- 관리자 회원 밴/해제, 글/댓글 강제 삭제, 운영 로그
- 내 활동 모아보기
- 다크 모드

## 남은 후보

- 다크 모드는 구현됐으므로 제출 전 실제 브라우저에서 주요 화면 색 대비와 버튼 가독성을 점검합니다.
- 관리자 페이지는 회원/글 권한, 회원 밴, 글/댓글 강제 삭제, 사이트 설정, 운영 로그를 다룹니다.
- 댓글 좋아요는 현재 18개 테이블 기준에서 제외합니다. 구현하려면 새 테이블 추가 여부를 먼저 결정해야 합니다.

## 작업 환경 메모

- 학교 컴퓨터와 집 컴퓨터에서 같은 GitHub 저장소를 pull/push하며 작업합니다.
- 작업 시작 전 `git pull`로 최신 내용을 받고, 작업 후 `git status`로 변경 파일을 확인한 뒤 commit/push합니다.
- DB나 XAMPP 설정은 환경마다 다를 수 있으므로 PHP 코드에는 로컬 절대경로나 개인 PC 전용 설정을 넣지 않습니다.
- DB 접속 정보는 수업용 공통 설정(`localhost / user1 / 1234 / blog`)을 기준으로 유지합니다.
