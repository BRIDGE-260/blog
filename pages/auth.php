<?php
session_start();
require_once __DIR__ . '/../app/db.php';

$error  = (($_GET['banned'] ?? '') === '1') ? '관리자에 의해 이용이 제한된 계정입니다.' : '';        // 화면에 보여줄 에러 메시지
$mode   = 'login';   // 처음 열릴 때 보여줄 화면 (login / register)
$oldName = '';
$oldNickname = '';
$oldEmail = '';
$allowPublicJoin = true;
$siteSettingsResult = $conn->query("SHOW TABLES LIKE 'site_settings'");
if ($siteSettingsResult && $siteSettingsResult->num_rows > 0) {
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'allow_public_join'");
    $stmt->execute();
    $allowPublicJoin = (($stmt->get_result()->fetch_assoc()['setting_value'] ?? '1') === '1');
    $stmt->close();
}
$banColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
$hasBanColumn = $banColumnResult && $banColumnResult->num_rows > 0;

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
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $oldName = $name;
        $oldNickname = $nickname;
        $oldEmail = $email;

        if (!$allowPublicJoin) {
            $error = '현재는 관리자 설정으로 신규 회원가입이 닫혀 있습니다.';
        } elseif ($name === '' || $nickname === '' || $email === '' || $password === '' || $passwordConfirm === '') {
            $error = '모든 항목을 입력해주세요.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '이메일 형식이 올바르지 않습니다.';
        } elseif (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 20) {
            $error = '닉네임은 2자 이상 20자 이하로 입력해주세요.';
        } elseif (!preg_match('/^[A-Za-z0-9가-힣_]+$/u', $nickname)) {
            $error = '닉네임은 한글, 영문, 숫자, 밑줄(_)만 사용할 수 있어요.';
        } elseif ($password !== $passwordConfirm) {
            $error = '비밀번호 확인이 일치하지 않습니다.';
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = '비밀번호는 8자 이상, 영문과 숫자를 함께 사용해주세요.';
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
                $_SESSION['flash_toast'] = '회원가입이 완료됐어요. 이제 블로그를 시작해보세요!';
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
            $adminColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
            $hasAdminColumn = $adminColumnResult && $adminColumnResult->num_rows > 0;
            $loginSql = "SELECT id, password, nickname, "
                . ($hasAdminColumn ? "is_admin" : "0 AS is_admin") . ", "
                . ($hasBanColumn ? "is_banned, banned_reason" : "0 AS is_banned, NULL AS banned_reason")
                . " FROM users WHERE email = ?";
            $stmt = $conn->prepare($loginSql);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && (int)($user['is_banned'] ?? 0) === 1) {
                $reason = trim((string)($user['banned_reason'] ?? ''));
                $error = '관리자에 의해 이용이 제한된 계정입니다.' . ($reason !== '' ? ' 사유: ' . $reason : '');
            } elseif ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['nickname'] = $user['nickname'];
                $_SESSION['flash_toast'] = '다시 오신 걸 환영해요.';
                header('Location: ' . ((int)($user['is_admin'] ?? 0) === 1 ? 'admin.php' : 'index.php'));
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
<title>로그인 / 회원가입 · BRIDGE 206</title>
<script>
(function () {
  var saved = localStorage.getItem('bridge206FontSize') || 'normal';
  if (!/^(normal|large|xlarge)$/.test(saved)) saved = 'normal';
  document.documentElement.setAttribute('data-font-size', saved);
  var theme = localStorage.getItem('bridge206Theme') || 'light';
  if (!/^(light|dark)$/.test(theme)) theme = 'light';
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
<link rel="stylesheet" href="../assets/css/auth.css?v=20260626bridge">
</head>
<body>

<a class="auth-home" href="index.php">← 메인으로</a>

<button class="auth-font-toggle" type="button" aria-label="글자 크기 설정 열기" aria-controls="authFontTools" aria-expanded="false" data-font-panel-toggle>가</button>

<section class="auth-font-tools" id="authFontTools" aria-label="글자 크기 설정" hidden>
  <strong>BRIDGE 206</strong>
  <span>글자 크기</span>
  <div data-font-size-control>
    <button type="button" data-font-size-option="normal">보통</button>
    <button type="button" data-font-size-option="large">크게</button>
    <button type="button" data-font-size-option="xlarge">가장 크게</button>
  </div>
  <span>화면 테마</span>
  <div data-theme-control>
    <button type="button" data-theme-option="light">라이트</button>
    <button type="button" data-theme-option="dark">다크</button>
  </div>
</section>

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
        <input type="email" name="email" required data-login-email autocomplete="email">
      </label>
      <label>
        <span>비밀번호</span>
        <span class="password-field">
          <input type="password" name="password" required data-password-input>
          <button type="button" data-password-toggle>보기</button>
        </span>
      </label>
      <label class="check-row">
        <input type="checkbox" data-remember-email>
        <span>이메일 저장</span>
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
          <input type="text" name="name" required autocomplete="name" value="<?= htmlspecialchars($oldName) ?>">
        </label>
        <label>
          <span>닉네임</span>
          <input type="text" name="nickname" required data-duplicate-field="nickname" autocomplete="nickname" value="<?= htmlspecialchars($oldNickname) ?>" minlength="2" maxlength="20" pattern="[A-Za-z0-9가-힣_]+">
          <small class="check-msg" data-check-msg="nickname"></small>
        </label>
        <label>
          <span>이메일</span>
          <input type="email" name="email" required data-duplicate-field="email" autocomplete="email" value="<?= htmlspecialchars($oldEmail) ?>">
          <small class="check-msg" data-check-msg="email"></small>
        </label>
        <label>
          <span>비밀번호</span>
          <span class="password-field">
            <input type="password" name="password" required data-password-input data-password-strength>
            <button type="button" data-password-toggle>보기</button>
          </span>
          <small class="password-meter" data-password-meter>
            <i></i>
            <em>8자 이상, 영문과 숫자를 섞으면 더 안전해요.</em>
          </small>
        </label>
        <label>
          <span>비밀번호 확인</span>
          <span class="password-field">
            <input type="password" name="password_confirm" required data-password-input data-password-confirm>
            <button type="button" data-password-toggle>보기</button>
          </span>
          <small class="check-msg" data-password-match></small>
        </label>
        <button type="submit" class="submit">가입하기</button>
      </form>
    </div>

  </div>
</div>

<script>
  (function () {
    var panel = document.querySelector('[id="authFontTools"]');
    var toggle = document.querySelector('[data-font-panel-toggle]');
    function setFontSizeMode(mode) {
      if (!/^(normal|large|xlarge)$/.test(mode)) mode = 'normal';
      document.documentElement.setAttribute('data-font-size', mode);
      localStorage.setItem('bridge206FontSize', mode);
      document.querySelectorAll('[data-font-size-option]').forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-font-size-option') === mode);
      });
    }
    document.querySelectorAll('[data-font-size-option]').forEach(function (button) {
      button.addEventListener('click', function () {
        setFontSizeMode(button.getAttribute('data-font-size-option'));
      });
    });
    setFontSizeMode(document.documentElement.getAttribute('data-font-size') || 'normal');

    function setThemeMode(mode) {
      if (!/^(light|dark)$/.test(mode)) mode = 'light';
      document.documentElement.setAttribute('data-theme', mode);
      localStorage.setItem('bridge206Theme', mode);
      document.querySelectorAll('[data-theme-option]').forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-theme-option') === mode);
      });
    }

    document.querySelectorAll('[data-theme-option]').forEach(function (button) {
      button.addEventListener('click', function () {
        setThemeMode(button.getAttribute('data-theme-option'));
      });
    });
    setThemeMode(document.documentElement.getAttribute('data-theme') || 'light');

    function closePanel() {
      if (!panel || !toggle) return;
      panel.hidden = true;
      panel.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    function openPanel() {
      if (!panel || !toggle) return;
      panel.hidden = false;
      requestAnimationFrame(function () {
        panel.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
      });
    }

    if (toggle && panel) {
      toggle.addEventListener('click', function () {
        if (panel.hidden) openPanel();
        else closePanel();
      });
      document.addEventListener('click', function (e) {
        if (panel.hidden) return;
        if (panel.contains(e.target) || toggle.contains(e.target)) return;
        closePanel();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
      });
    }
  })();

  // 다크 패널 가운데 버튼을 누르면 로그인 ↔ 회원가입 슬라이딩 전환

  document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var field = button.closest('.password-field');
      var input = field && field.querySelector('[data-password-input]');
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.textContent = show ? '숨김' : '보기';
      input.focus();
    });
  });

  (function () {
    var input = document.querySelector('[data-password-strength]');
    var meter = document.querySelector('[data-password-meter]');
    if (!input || !meter) return;
    var bar = meter.querySelector('i');
    var text = meter.querySelector('em');

    function updateStrength() {
      var value = input.value;
      var score = 0;
      if (value.length >= 8) score++;
      if (/[A-Za-z]/.test(value) && /\d/.test(value)) score++;
      if (/[^A-Za-z0-9]/.test(value)) score++;

      var width = ['0%', '36%', '68%', '100%'][score];
      var color = ['#8792a3', '#ed1c24', '#f58216', '#5a9787'][score];
      var message = [
        '8자 이상, 영문과 숫자를 섞으면 더 안전해요.',
        '조금 약해요. 8자 이상으로 늘려보세요.',
        '괜찮아요. 특수문자를 더하면 더 안전해요.',
        '안전한 비밀번호예요.'
      ][score];

      bar.style.setProperty('--strength', width);
      bar.style.setProperty('--strength-color', color);
      text.textContent = message;
      meter.classList.toggle('is-good', score >= 3);
    }

    input.addEventListener('input', updateStrength);
    updateStrength();
  })();

  (function () {
    var password = document.querySelector('[data-password-strength]');
    var confirm = document.querySelector('[data-password-confirm]');
    var message = document.querySelector('[data-password-match]');
    if (!password || !confirm || !message) return;

    function updateMatch() {
      if (confirm.value === '') {
        message.textContent = '';
        message.className = 'check-msg';
        return;
      }
      var ok = password.value === confirm.value;
      message.textContent = ok ? '비밀번호가 일치해요.' : '비밀번호가 서로 달라요.';
      message.className = 'check-msg ' + (ok ? 'ok' : 'bad');
      confirm.classList.toggle('is-ok', ok);
      confirm.classList.toggle('is-bad', !ok);
    }

    password.addEventListener('input', updateMatch);
    confirm.addEventListener('input', updateMatch);
    updateMatch();
  })();

  (function () {
    var email = document.querySelector('[data-login-email]');
    var remember = document.querySelector('[data-remember-email]');
    var loginForm = document.querySelector('.sign-in form');
    if (!email || !remember || !loginForm) return;

    var savedEmail = localStorage.getItem('bridge206SavedEmail') || '';
    if (savedEmail !== '') {
      email.value = savedEmail;
      remember.checked = true;
    }

    loginForm.addEventListener('submit', function () {
      if (remember.checked) {
        localStorage.setItem('bridge206SavedEmail', email.value.trim());
      } else {
        localStorage.removeItem('bridge206SavedEmail');
      }
    });
  })();
  document.querySelector('.img__btn').addEventListener('click', function () {
    var cont = document.querySelector('.cont');
    if (!cont) return;
    if (cont.classList.contains('s--signup')) {
      cont.classList.add('is-returning');
      requestAnimationFrame(function () {
        cont.classList.remove('s--signup');
        window.setTimeout(function () {
          cont.classList.remove('is-returning');
        }, 900);
      });
    } else {
      cont.classList.remove('is-returning');
      cont.classList.add('s--signup');
    }
  });

  (function () {
    var form = document.querySelector('.sign-up form');
    if (!form) return;

    var submitBtn = form.querySelector('.submit');
    var fields = form.querySelectorAll('[data-duplicate-field]');
    var timers = {};
    var states = { email: null, nickname: null };

    function setMessage(field, type, message) {
      var msg = form.querySelector('[data-check-msg="' + field + '"]');
      var input = form.querySelector('[data-duplicate-field="' + field + '"]');
      if (!msg || !input) return;

      msg.textContent = message || '';
      msg.className = 'check-msg' + (type ? ' ' + type : '');
      input.classList.remove('is-ok', 'is-bad');
      if (type === 'ok') input.classList.add('is-ok');
      if (type === 'bad') input.classList.add('is-bad');
    }

    function updateSubmit() {
      submitBtn.disabled = states.email === false || states.nickname === false;
    }

    function checkDuplicate(input) {
      var field = input.dataset.duplicateField;
      var value = input.value.trim();

      clearTimeout(timers[field]);
      states[field] = null;
      updateSubmit();

      if (value === '') {
        setMessage(field, '', '');
        return;
      }

      setMessage(field, 'pending', '확인 중...');
      timers[field] = setTimeout(async function () {
        try {
          var res = await fetch('../api/auth_check.php?field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value), {
            headers: { 'Accept': 'application/json' }
          });
          var data = await res.json();
          if (input.value.trim() !== value) return;
          states[field] = data.available === true;
          setMessage(field, data.available ? 'ok' : 'bad', data.message || '');
          updateSubmit();
        } catch (err) {
          states[field] = null;
          setMessage(field, 'bad', '중복 확인에 실패했어요.');
          updateSubmit();
        }
      }, 300);
    }

    fields.forEach(function (input) {
      input.addEventListener('input', function () {
        checkDuplicate(input);
      });
      input.addEventListener('blur', function () {
        checkDuplicate(input);
      });
    });

    form.addEventListener('submit', function (e) {
      if (states.email === false || states.nickname === false) {
        e.preventDefault();
        return;
      }

      var password = form.querySelector('[data-password-strength]');
      var confirm = form.querySelector('[data-password-confirm]');
      if (password && confirm && password.value !== confirm.value) {
        e.preventDefault();
        confirm.focus();
      }
    });
  })();
</script>
</body>
</html>

