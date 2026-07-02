# BRIDGE 206

PHP + MySQL로 만든 네이버 블로그 스타일 학교 실습 프로젝트입니다.

BRIDGE 206은 20대와 60대를 출발점으로 삼되, 특정 세대만 강조하지 않고 모든 세대가 글과 질문으로 이어지는 블로그를 목표로 합니다.

## 실행 환경

- XAMPP
- PHP
- MySQL 또는 MariaDB
- DB 접근: `mysqli`
- 프론트엔드: 순수 HTML/CSS/JavaScript

## 처음 실행 순서

1. 프로젝트 폴더를 XAMPP 웹 루트 아래에 둡니다.

   ```text
   C:\xampp\htdocs\blog
   ```

2. phpMyAdmin에서 `blog` 데이터베이스를 만듭니다.

3. SQL 파일을 순서대로 실행합니다.

   ```text
   database/blog_schema.sql
   database/blog_sample_data.sql
   ```

   기존 DB를 유지한 채 갱신할 때는 `database/add_post_media_fields.sql`, `database/add_performance_indexes.sql`, `database/add_professor_features.sql`을 1회 실행합니다.

4. 브라우저에서 접속합니다.

   ```text
   http://localhost/blog/
   ```

## DB 접속 정보

`app/db.php` 기준 접속 정보는 다음과 같습니다.

```text
host=localhost
username=user1
password=1234
database=blog
```

## 샘플 계정

샘플 계정 비밀번호는 모두 `1234`입니다.

- `stephane@blog.com`
- `yujin@blog.com`
- `mina@blog.com`
- `hoonie@blog.com`
- `sora@blog.com`
- `junho@blog.com`

샘플 데이터 기준 `id = 1` 계정은 관리자 권한(`users.is_admin = 1`)을 가집니다. 기존 DB에 관리자 권한 컬럼만 추가해야 하면 `database/add_admin_role.sql`을 실행합니다.

## 주요 기능

- 회원가입, 로그인, 로그아웃
- 프로필 수정, 비밀번호 변경, 회원 탈퇴
- 전체 공개 글 메인 피드, 검색, 태그 필터, 인기 태그
- 내 블로그 화면: 프로필, 카테고리, 방문자 수, 글 목록, 내 글 관리
- 블로그 꾸미기: 색상, 헤더/배경 이미지, 레이아웃, 사이드바, 프로필 모양, 목록 스타일
- 글쓰기/글수정: contenteditable 에디터, 본문 중간 이미지/동영상 삽입, 첨부 크기 조절, 태그 입력, 임시저장/발행, 공지글 상단 고정
- 글 상세: 조회수, 공감, 스크랩, 공감자 목록, 링크 복사, 이미지 라이트박스, 동영상 재생, 댓글/답글, 댓글 수정/삭제, 이전/다음 글
- 이웃: 이웃 추가/취소, 서로이웃 표시, 블로그 찾기
- 이웃 접속 상태 표시, 이웃에게 쪽지 보내기
- 내 소식: 댓글, 공감, 이웃 새 글, 방명록 알림과 항목별 읽음 처리, 이웃 새 글만 보기
- 내 활동: 내가 댓글 단 글, 공감한 글, 스크랩한 글 모아보기
- 블로그 현황: 시간대별/성별 방문 통계, CSV 엑셀 다운로드
- 방명록: 블로그별 방명록 작성/삭제
- 카테고리 관리: 추가, 이름 변경, 순서 변경, 삭제
- 관리자 대시보드: 회원 관리자 권한, 회원 밴/해제, 글 공개/발행/공지 권한, 글/댓글 강제 삭제, 사이트 공지와 메인 문구 관리

## 접근성 / 테마

- 전 화면 공통 글자 크기 설정: `보통`, `크게`, `가장 크게`
- 선택값은 `localStorage`의 `bridge206FontSize`에 저장됩니다.
- 다크 모드 지원: `라이트`, `다크`
- 선택값은 `localStorage`의 `bridge206Theme`에 저장됩니다.
- 큰 글씨 모드에서는 글자 크기뿐 아니라 버튼 높이, 카드 간격, 검색창, 카테고리, 썸네일 영역도 함께 조정합니다.

## DB 테이블

현재 실제 DB 기준 테이블은 18개입니다.

1. `blog_settings`
2. `categories`
3. `comments`
4. `guestbook`
5. `likes`
6. `neighbors`
7. `notification_reads`
8. `posts`
9. `post_images` - 본문 이미지/동영상 첨부(`media_type`)
10. `post_tags`
11. `scraps`
12. `tags`
13. `users`
14. `visit_logs`
15. `messages` - 이웃 쪽지
16. `visit_events` - 시간대/성별 방문 통계 이벤트
17. `site_settings` - 사이트 공지와 메인 문구 설정
18. `moderation_logs` - 관리자 운영 조치 기록

관리자 권한은 별도 테이블이 아니라 `users.is_admin` 컬럼으로 관리합니다.

## 파일 구조

- `app/`: 공통 PHP 파일
- `pages/`: 화면 PHP 파일
- `api/`: AJAX JSON 엔드포인트
- `assets/`: CSS, JavaScript, 이미지
- `database/`: DB schema/sample/migration SQL
- `tools/`: 실행/점검 도구
- `uploads/`: 업로드 이미지/동영상

## 주의 사항

- SQL은 사용자 입력을 직접 붙이지 않고 prepared statement를 사용합니다.
- 비밀번호는 `password_hash()`로 저장하고 `password_verify()`로 검증합니다.
- 사용자 입력을 화면에 출력할 때는 `htmlspecialchars()`로 감싸 XSS를 방지합니다.
- 화면에서 작성자 이름은 실명 `name`이 아니라 `nickname`을 사용합니다.
- PHP 코드에는 개인 PC 전용 절대경로나 로컬 환경별 설정을 넣지 않습니다.
