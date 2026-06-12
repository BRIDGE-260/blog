# 팀 블로그 프로젝트 — 내 담당 과제 (로그아웃 / 카테고리 관리 / 프로필 수정)

너는 PHP + MySQL 블로그 팀 프로젝트의 일부를 맡는다.
나는 logout.php, category.php, profile.php 3개 파일을 담당한다.
다른 화면(글쓰기, 피드, 뷰 등)은 다른 팀원이 만들고 있으니 건드리지 마라.

## 0. 시작 전에 반드시 읽을 것
다음 파일들을 먼저 읽고 프로젝트 규칙과 코드 스타일을 파악한 뒤 작업해라.
- `blog/CLAUDE.md` — 프로젝트 규칙·DB 구조(중요, 전부 따를 것)
- `blog/db.php` — DB 접속. `require_once`로 `$conn`(mysqli) 사용
- `blog/header.php`, `blog/footer.php` — 공통 머리말/꼬리말 (이 패턴 재사용)
- `blog/auth.php` — 기존 코드 스타일 참고용 (prepared statement, 세션 처리 방식)

## 1. 반드시 지킬 공통 규칙
- DB는 **mysqli** 사용 (PDO 아님). 모든 파일 상단에서 `require_once __DIR__ . '/db.php';`
- **SQL은 항상 prepared statement**: `prepare()` → `bind_param()` → `execute()`.
  사용자 입력을 쿼리 문자열에 직접 붙이지 마라 (SQL 인젝션 방지).
  bind_param 타입: i=정수, s=문자열, d=실수. ? 개수 = 타입 수 = 변수 수 일치.
- 화면 출력 시 사용자 입력은 **`htmlspecialchars()`** 로 감싸라 (XSS 방지).
- 로그인 상태는 `$_SESSION['user_id']`, `$_SESSION['nickname']`.
- 일반 페이지(category.php, profile.php)는 이 패턴으로 만든다:
  ```php
  <?php
  session_start();
  if (!isset($_SESSION['user_id'])) { header('Location: auth.php'); exit; } // 로그인 검사 먼저!
  require_once __DIR__ . '/db.php';
  // ... POST 처리 ...
  $pageTitle = '...';
  require_once __DIR__ . '/header.php';   // 여기서부터 HTML 출력
  ?>
  ... 화면 내용 ...
  <?php require_once __DIR__ . '/footer.php'; ?>
  ```
  ※ 로그인 검사·`header('Location:...')` 리다이렉트는 header.php를 include하기 **전에** 해라.
     header.php가 HTML을 출력하기 시작하면 리다이렉트가 안 된다.
- 한글 사이트다. 화면 문구는 전부 한글로.
- **공유 파일(header.php, footer.php, db.php, style.css)은 수정하지 마라.** 충돌난다.
  내 페이지로 가는 메뉴 링크는 팀장이 나중에 header.php에 추가할 거다.
  테스트할 땐 주소창에 URL을 직접 치거나 임시 `<a>`로 이동해라.
- 요청하지 않은 기능/파일은 임의로 추가하지 마라.

## 2. 과제 F — logout.php (로그아웃)
- 목적: 로그인 상태를 비우고 로그인 화면(auth.php)으로 보낸다.
- 동작: `session_start()` → 세션 비우기(`$_SESSION = []`) → `session_destroy()` → `header('Location: auth.php'); exit;`
- 화면 출력 없음. header/footer 불필요. 아주 짧은 파일이면 된다.
- 완료 기준: 로그인된 상태에서 logout.php 접속 → auth.php로 이동 + 다시 index.php 가면 로그인 화면으로 튕긴다.

## 3. 과제 G — category.php (카테고리 관리)
- 목적: 로그인한 사용자가 자기 블로그 카테고리를 추가/조회/삭제한다.
- 사용 테이블: **categories** (id, user_id, name, sort_order, created_at)
- 기능:
  1) **목록**: 현재 로그인 사용자(`$_SESSION['user_id']`)의 카테고리를 sort_order 순으로 조회해 화면에 리스트로 출력. (name은 htmlspecialchars로)
  2) **추가**: 폼으로 name 입력받아 INSERT.
     - 빈 값이면 막고 안내 문구.
     - sort_order는 맨 뒤로: `SELECT COALESCE(MAX(sort_order),0)+1 FROM categories WHERE user_id=?` 로 구한 값을 넣어라.
  3) **삭제**: 각 카테고리 옆 삭제 버튼. **반드시 본인 것만** 삭제:
     `DELETE FROM categories WHERE id=? AND user_id=?` (user_id 조건 빠뜨리면 남의 카테고리도 지워짐 — 보안상 필수).
- 처리 방식: 추가/삭제는 POST로 받아 처리 후 `header('Location: category.php'); exit;` (새로고침 시 중복 INSERT 방지).
- 완료 기준: 카테고리 추가하면 목록에 뜨고, 삭제하면 사라진다. 로그아웃 상태로 접속하면 auth.php로 튕긴다.

## 4. 과제 H — profile.php (프로필 수정)
- 목적: 로그인한 사용자가 자기 블로그 정보를 수정한다.
- 사용 테이블: **users** — 이 과제에서 수정 대상은 `blog_title`, `intro`, `gender` 3개만.
  ※ `email`, `password`, `nickname`은 건드리지 마라 (UNIQUE·민감 정보라 범위 밖).
  ※ **프로필 이미지 업로드는 이번 범위 아님** (다음 단계). profile_image 컬럼은 손대지 마라.
- 기능:
  1) 현재 로그인 사용자 행을 SELECT 해서 blog_title/intro/gender를 폼 입력칸의 기본값으로 채운다 (value에 htmlspecialchars).
  2) 저장(POST) 시 `UPDATE users SET blog_title=?, intro=?, gender=? WHERE id=?` 로 갱신.
  3) gender는 선택값(NULL 가능). 선택 안 하면 NULL로 저장.
     - **gender 컬럼의 정확한 타입/허용값은 `blog/blog_schema.sql`에서 확인하고 거기에 맞춰라.**
       (짧은 문자열이면 select 옵션 값을 그에 맞게. 미선택은 NULL.)
  4) 저장 후 안내 문구("저장되었습니다") 표시 또는 같은 페이지로 리다이렉트.
- 완료 기준: 값을 바꿔 저장 후 새로고침하면 바뀐 값이 그대로 남아 있다.

## 5. 작업 스타일
- 완성된 파일 전체를 보여줄 것 (조각 X).
- 변경은 외과적으로(surgical) — 요청한 것만. 다른 파일 멋대로 건드리지 마라.
- 결정이 갈리거나 불확실하면 먼저 물어라. "왜 그렇게 했는지" 이유도 같이 설명해라.
- 작업은 GitHub 브랜치를 따로 파서 한다. 위 3개 파일만 새로 만든다.

## 6. 테스트
XAMPP(Apache+MySQL)를 켜고, 회원가입으로 새 계정을 만든 뒤:
- category.php: 카테고리 추가/삭제가 되는지
- profile.php: 값 저장 후 새로고침해도 유지되는지
- logout.php: 로그아웃 후 index.php 접근 시 로그인 화면으로 가는지
