# 블로그 팀 프로젝트 — 작업 지침

PHP + MySQL로 만드는 네이버 블로그 스타일 웹사이트. 학교 실습 프로젝트.

## 기술 스택 / 환경
- PHP + MySQL (XAMPP, localhost)
- DB 접근은 **mysqli** 사용 (PDO 아님 — 수업에서 mysqli를 배웠음)
- 프론트는 순수 HTML/CSS/JS (프레임워크 없음)
- 한 화면 = 한 .php 파일 구조. PHP가 화면도 그리고 폼 처리도 함.

## DB 접속 정보 (db.php)
```
host=localhost, username=user1, password=1234, database=blog
```
- 모든 PHP 파일은 상단에서 `require_once __DIR__ . '/db.php';` 로 `$conn` 사용.

## 코딩 규칙 (중요)
- **SQL은 항상 prepared statement** 사용: `prepare()` → `bind_param()` → `execute()`.
  사용자 입력을 쿼리 문자열에 직접 붙이지 말 것 (SQL 인젝션 방지).
- bind_param 타입 문자: i=정수, s=문자열, d=실수. ? 개수 = 타입 수 = 변수 수 항상 일치.
- 비밀번호는 `password_hash()` 저장 / `password_verify()` 검증.
- 화면 출력 시 사용자 입력은 `htmlspecialchars()` 로 감쌀 것 (XSS 방지).
- 로그인 상태는 `$_SESSION['user_id']`, `$_SESSION['nickname']` 으로 관리.
- 화면에 작성자 표시할 때는 `name`(실명)이 아니라 **`nickname`** 을 사용.

## 작업 스타일 (사용자 선호)
- 완성된 파일 전체를 주는 것을 선호 (코드 조각보다).
- 변경은 외과적으로(surgical) — 요청한 것만, 멋대로 다른 부분 건드리지 말 것.
- 요청하지 않은 기능/파일을 임의로 추가하지 말 것.
- 결정이 갈리는 지점은 먼저 묻고 진행. "왜 그렇게 하는지" 이유 설명을 선호.

## 데이터베이스 구조 (9개 테이블)
blog_schema.sql 로 생성, blog_sample_data.sql 로 샘플 데이터.
실행 순서: schema → sample_data (ALTER 불필요, schema에 전부 반영됨).

1. **users** — id, email(UQ), password, name, nickname(UQ), gender(NULL),
   blog_title, intro, profile_image_original, profile_image_stored, created_at
2. **categories** — id, user_id(FK), name, sort_order, created_at
3. **posts** — id, user_id(FK), category_id(FK,NULL), title, content,
   thumbnail_original, thumbnail_stored, view_count, visibility(all/neighbor/private),
   status(draft/published), created_at, updated_at
4. **comments** — id, post_id(FK), user_id(FK), content, created_at
5. **likes** — id, post_id(FK), user_id(FK), created_at, UNIQUE(post_id,user_id)
6. **neighbors** — id, user_id(FK 추가한 사람), neighbor_id(FK 추가당한 사람),
   created_at, UNIQUE(user_id,neighbor_id)
7. **tags** — id, name(UQ)
8. **post_tags** — post_id(FK), tag_id(FK), PK(post_id,tag_id)  ← 글-태그 N:M
9. **visit_logs** — id, user_id(FK), visit_date, count, UNIQUE(user_id,visit_date)
   방문 처리: INSERT ... ON DUPLICATE KEY UPDATE count=count+1 (매번 카운트 방식)

### 이미지 파일 처리 규칙
- profile_image / thumbnail 은 **원본명(original) + 저장명(stored)** 두 컬럼으로 분리.
- 업로드 시 파일명을 고유한 이름으로 변환해서 디스크 저장 → stored 에 기록.
- 원본 파일명은 original 에 보관 (다운로드 시 원래 이름으로 되돌리기 위함).
- 이미지 없으면 두 컬럼 NULL → 화면에서 노이미지 처리.

### 태그 처리 규칙
- tags.name 에 UNIQUE → 같은 태그 중복 저장 방지 (재사용).
- 글쓰기에서 태그는 "#JPOP #시티팝" 한 줄 문자열로 입력받음.
- PHP에서 파싱: 공백으로 분리 → 각 태그마다 "있으면 그 id, 없으면 INSERT" →
  tag_id 를 post_tags 에 연결.
- 저장 전 정규화(앞뒤 공백/# 제거) 정도만. 오타 보정은 안 함(실습 범위 밖).

## 화면 목록 (기능 정의서 기준)
- 블로그 메인: 전체 공개 글 피드 + 검색 + 카테고리 + 페이징
- 내 블로그 메인: 특정 블로거 페이지 (사이드바: 프로필→닉네임→홈/검색/프로필 아이콘→카테고리)
- 로그인/회원가입: auth.php (슬라이딩 전환 폼, 완성됨)
- 글쓰기 화면: 카테고리/공개설정/태그/임시저장/발행
- 블로그 뷰: 글 상세 + 공감 + 댓글 + 이전/다음 글 + 본인 글 수정/삭제

## 디자인 톤
- 흰색 기반 + 플랫(은은한 드롭섀도) 카드, 포인트는 **탄색(#d4af7a)**.
  (배경 #ededed, 본문 글자 #2d3436, 포인트/버튼 #d4af7a — auth 로그인 화면과 통일)
- 공통 변수는 `style.css` 의 `:root` (`--accent` 등). auth 화면은 전용 `auth.css`.
- 모바일 반응형 고려. 사이드 메뉴 구조는 네이버 블로그/티스토리 참고.

## 공통 구조 (include 패턴)
- 일반 페이지: `session_start` → 로그인 검사 → (POST 처리) → `$pageTitle` 지정
  → `require header.php` → 본문 → `require footer.php`.
- 로그인 검사·`header('Location:..')` 리다이렉트는 **header.php include 전에** 할 것
  (header.php 가 HTML 출력 시작하면 리다이렉트 불가).
- 공통 파일: `db.php`(DB), `header.php`(head+상단바), `footer.php`, `style.css`.

## 현재까지 만든 파일 (전부 완성, prepared statement 준수)
- `db.php` — mysqli 접속
- `header.php` / `footer.php` / `style.css` — 공통 레이아웃·스타일
- `auth.php` (+`auth.css`) — 로그인/회원가입 슬라이딩 폼(탄색+사진). 성공 시 index.php
- `index.php` — 블로그 메인: 이웃 새 글 + 인기 태그 + 정렬(최신/인기) + 검색 + 태그필터 + 페이징
- `write.php` — 글쓰기: 카테고리/공개설정/태그(N:M)/썸네일 업로드/임시저장·발행
- `view.php` — 글 상세: 조회수+1, 태그, 공감(likes 토글), 댓글(작성/본인삭제),
  이전/다음 글, 공개범위 권한체크, 본인글 수정/삭제 진입
- `modify.php` / `delete.php` — 글 수정(태그 재동기화·썸네일 교체)/삭제(확인 후 자식row·파일 정리)
- `blog.php` — 내 블로그 메인: 사이드바(프로필·카테고리·방문자수) + 글목록 + 카테고리필터
  + 이웃 추가/취소(neighbors) + 방문 카운트(visit_logs)
- `neighbors.php` — 이웃 목록(내 이웃·서로이웃 / 나를 추가한 사람)
- `notifications.php` — 내 소식: 내 글의 최근 댓글+공감 타임라인
- `categories.php` — 고정 카테고리(주제) 목록 + 글쓰기에서 유저 카테고리로 매핑하는 헬퍼
- `profile.php` — 프로필 수정(블로그제목/소개/성별)
- `logout.php` — 로그아웃
- ※ DB 9개 테이블 전부 사용 중. 이미지 업로드 폴더는 `uploads/`(자동 생성).

## 다음 할 일 (후보)
- 프로필 이미지 업로드(`profile_image_*` 컬럼은 아직 미사용)
- 방문자 통계(visit_logs 추이) / 인기글 위젯 / 이웃 새 글 전용 페이지
- `blog_schema.sql` / `blog_sample_data.sql` 파일은 레포에 아직 없음(DB엔 반영됨)

## 샘플 계정 (테스트용, 비밀번호는 직접 가입해서 만들어야 함 — 샘플은 해시 더미값)
- 닉네임 예시: stephane_music, yujin_dev, mina_daily, hoonie_cinema
- ※ 샘플 데이터의 password 는 '$hash$' 더미라 로그인 안 됨.
  실제 테스트는 회원가입으로 새 계정 만들어서 할 것.
