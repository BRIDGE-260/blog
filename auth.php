<?php
session_start();
require_once __DIR__ . '/db.php';

$error  = '';        // 화면에 보여줄 에러 메시지
$mode   = 'login';   // 처음 열릴 때 보여줄 화면 (login / register)

// ============================================================
// POST 처리 — action 값으로 로그인/회원가입 분기
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- 회원가입 ----------
    if ($action === 'register') {
        $mode     = 'register';   // 에러 나면 회원가입 화면 유지
        $name     = trim($_POST['name']     ?? '');
        $nickname = trim($_POST['nickname'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $nickname === '' || $email === '' || $password === '') {
            $error = '모든 항목을 입력해주세요.';
        } else {
            // 이메일 중복 확인
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $emailExists = $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            // 닉네임 중복 확인
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE nickname = ?");
            $stmt->bind_param("s", $nickname);
            $stmt->execute();
            $nickExists = $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            if ($emailExists > 0) {
                $error = '이미 가입된 이메일입니다.';
            } elseif ($nickExists > 0) {
                $error = '이미 사용 중인 닉네임입니다.';
            } else {
                // 비밀번호 암호화 후 저장
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "INSERT INTO users (email, password, name, nickname) VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("ssss", $email, $hash, $name, $nickname);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();

                // 가입과 동시에 로그인 처리
                $_SESSION['user_id']  = $newId;
                $_SESSION['nickname'] = $nickname;
                header('Location: index.php');
                exit;
            }
        }
    }

    // ---------- 로그인 ----------
    elseif ($action === 'login') {
        $mode     = 'login';
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = '이메일과 비밀번호를 입력해주세요.';
        } else {
            $stmt = $conn->prepare("SELECT id, password, nickname FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['nickname'] = $user['nickname'];
                header('Location: index.php');
                exit;
            } else {
                $error = '이메일 또는 비밀번호가 올바르지 않습니다.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>로그인 / 회원가입 · MyBlog</title>
<link rel="stylesheet" href="auth.css">
</head>
<body>

<!-- $mode 가 register 면 s--signup 클래스를 줘서 회원가입 화면이 먼저 보이게 함 -->
<div class="cont <?= $mode === 'register' ? 's--signup' : '' ?>">

  <!-- 로그인 폼 -->
  <div class="form sign-in">
    <h2>다시 오셨군요!</h2>
    <form method="post" action="auth.php">
      <?php if ($error && $mode === 'login'): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <input type="hidden" name="action" value="login">
      <label>
        <span>이메일</span>
        <input type="email" name="email" required>
      </label>
      <label>
        <span>비밀번호</span>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="submit">로그인</button>
    </form>
  </div>

  <!-- 오른쪽 슬라이딩 영역: 다크 패널 + 회원가입 폼 -->
  <div class="sub-cont">

    <!-- 다크 이미지 패널 (가운데 버튼으로 폼 전환) -->
    <div class="img">
      <div class="img__text m--up">
        <h2>처음이신가요?</h2>
        <p>간단한 정보만 입력하면<br>나만의 블로그를 시작할 수 있어요.</p>
      </div>
      <div class="img__text m--in">
        <h2>이미 회원이신가요?</h2>
        <p>로그인하고 이어서<br>기록을 남겨보세요.</p>
      </div>
      <div class="img__btn">
        <span class="m--up">회원가입</span>
        <span class="m--in">로그인</span>
      </div>
    </div>

    <!-- 회원가입 폼 -->
    <div class="form sign-up">
      <h2>환영합니다</h2>
      <form method="post" action="auth.php">
        <?php if ($error && $mode === 'register'): ?>
          <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <input type="hidden" name="action" value="register">
        <label>
          <span>이름</span>
          <input type="text" name="name" required>
        </label>
        <label>
          <span>닉네임</span>
          <input type="text" name="nickname" required>
        </label>
        <label>
          <span>이메일</span>
          <input type="email" name="email" required>
        </label>
        <label>
          <span>비밀번호</span>
          <input type="password" name="password" required>
        </label>
        <button type="submit" class="submit">가입하기</button>
      </form>
    </div>

  </div>
</div>

<script>
  // 다크 패널 가운데 버튼을 누르면 로그인 ↔ 회원가입 슬라이딩 전환
  document.querySelector('.img__btn').addEventListener('click', function () {
    document.querySelector('.cont').classList.toggle('s--signup');
  });
</script>
</body>
</html>
