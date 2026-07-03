<?php
/**
 * neighbors.php — 이웃 + 블로그 찾기 (탭 통합).
 *   tab=neighbors(기본): ① 내 이웃(서로이웃 표시·취소) ② 나를 추가한 이웃(추가 가능)
 *   tab=find          : 블로그 찾기 — 전체 사용자 + 검색(닉네임/제목) + 정렬 + 이웃 추가/취소
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$viewerId = $_SESSION['user_id'];
$tab = ($_GET['tab'] ?? '') === 'find' ? 'find' : 'neighbors';
$lastSeenColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'last_seen_at'");
$hasLastSeenColumn = $lastSeenColumnResult && $lastSeenColumnResult->num_rows > 0;
$lastSeenSelect = $hasLastSeenColumn ? "u.last_seen_at" : "NULL AS last_seen_at";

// ── POST: 이웃 추가/취소 ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)($_POST['target'] ?? 0);
    $action   = $_POST['action'] ?? '';
    if ($targetId > 0 && $targetId !== (int)$viewerId) {
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT IGNORE INTO neighbors (user_id, neighbor_id) VALUES (?, ?)");
        } elseif ($action === 'cancel') {
            $stmt = $conn->prepare("DELETE FROM neighbors WHERE user_id = ? AND neighbor_id = ?");
        }
        if (isset($stmt)) {
            $stmt->bind_param("ii", $viewerId, $targetId);
            $stmt->execute();
            $stmt->close();
        }
    }
    // 보던 탭(+검색/정렬)으로 되돌아가기
    $back = 'neighbors.php';
    if (($_POST['rtab'] ?? '') === 'find') {
        $params = ['tab' => 'find'];
        if (!empty($_POST['rq']))    $params['q']    = $_POST['rq'];
        if (!empty($_POST['rsort'])) $params['sort'] = $_POST['rsort'];
        $back .= '?' . http_build_query($params);
    }
    header('Location: ' . $back);
    exit;
}

// ============================================================
// 데이터 조회 — 활성 탭에 맞춰
// ============================================================
$myNeighbors = $addedMe = $users = $recommendedUsers = [];
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'posts';

$viewerCategories = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT c.name
     FROM posts p
     JOIN categories c ON c.id = p.category_id
     WHERE p.user_id = ? AND p.status = 'published'
     ORDER BY c.name"
);
$stmt->bind_param("i", $viewerId);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $viewerCategories[] = $row['name'];
}
$stmt->close();

if ($tab === 'neighbors') {
    // ① 내 이웃 (서로이웃 여부 mutual 포함)
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
                EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = u.id AND r.neighbor_id = ?) AS mutual,
                $lastSeenSelect
         FROM neighbors n
         JOIN users u ON u.id = n.neighbor_id
         WHERE n.user_id = ?
         ORDER BY u.nickname"
    );
    $stmt->bind_param("ii", $viewerId, $viewerId);
    $stmt->execute();
    $myNeighbors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ② 나를 추가했지만 내가 아직 안 추가한 사람
    $stmt = $conn->prepare(
        "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored, $lastSeenSelect
         FROM neighbors n
         JOIN users u ON u.id = n.user_id
         WHERE n.neighbor_id = ?
           AND NOT EXISTS(SELECT 1 FROM neighbors r WHERE r.user_id = ? AND r.neighbor_id = u.id)
         ORDER BY u.nickname"
    );
    $stmt->bind_param("ii", $viewerId, $viewerId);
    $stmt->execute();
    $addedMe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // 블로그 찾기 — 검색어 + 정렬(화이트리스트로 안전하게)
    $orderMap = [
        'match'  => 'shared_count DESC, post_count DESC, u.nickname',
        'posts'  => 'post_count DESC, u.nickname',
        'recent' => 'u.created_at DESC',
        'name'   => 'u.nickname',
    ];
    $order = $orderMap[$sort] ?? $orderMap['match'];
    if (!isset($orderMap[$sort])) $sort = 'match';

    $sharedSelect = "0 AS shared_count";
    $sharedTypes = "";
    $sharedParams = [];
    if ($viewerCategories) {
        $placeholders = implode(',', array_fill(0, count($viewerCategories), '?'));
        $sharedSelect = "(SELECT COUNT(DISTINCT c2.name)
                          FROM posts p2
                          JOIN categories c2 ON c2.id = p2.category_id
                          WHERE p2.user_id = u.id
                            AND p2.status = 'published'
                            AND p2.visibility = 'all'
                            AND c2.name IN ($placeholders)) AS shared_count";
        $sharedTypes = str_repeat('s', count($viewerCategories));
        $sharedParams = $viewerCategories;
    }

    $sql = "SELECT u.id, u.nickname, u.blog_title, u.profile_image_stored,
                   (SELECT COUNT(*) FROM posts p
                    WHERE p.user_id = u.id AND p.status='published' AND p.visibility='all') AS post_count,
                   $sharedSelect,
                   (SELECT p3.title FROM posts p3
                    WHERE p3.user_id = u.id AND p3.status='published' AND p3.visibility='all'
                    ORDER BY p3.created_at DESC LIMIT 1) AS latest_title,
                   $lastSeenSelect,
                   EXISTS(SELECT 1 FROM neighbors n WHERE n.user_id = ? AND n.neighbor_id = u.id) AS is_neighbor
            FROM users u
            WHERE u.id <> ?";
    $types  = $sharedTypes . "ii";
    $params = array_merge($sharedParams, [$viewerId, $viewerId]);
    if ($q !== '') {
        $sql .= " AND (u.nickname LIKE ? OR u.blog_title LIKE ?)";
        $like = '%' . $q . '%';
        $types  .= "ss";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY $order";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $recommendedUsers = $users;
    usort($recommendedUsers, function ($a, $b) {
        $sharedDiff = (int)($b['shared_count'] ?? 0) <=> (int)($a['shared_count'] ?? 0);
        if ($sharedDiff !== 0) return $sharedDiff;
        $postDiff = (int)($b['post_count'] ?? 0) <=> (int)($a['post_count'] ?? 0);
        if ($postDiff !== 0) return $postDiff;
        return strcmp($a['nickname'], $b['nickname']);
    });
    $recommendedUsers = array_slice(array_filter($recommendedUsers, function ($u) {
        return (int)($u['post_count'] ?? 0) > 0;
    }), 0, 3);
}

$pageTitle = ($tab === 'find' ? '블로그 찾기' : '이웃') . ' · BRIDGE 206';
require_once __DIR__ . '/../app/header.php';

/**
 * 사용자 한 명을 카드로 출력.
 *   $u       : 사용자 row
 *   $action  : 'add' | 'cancel'
 *   $label   : 버튼 글자
 *   $class   : 버튼 클래스
 *   $opts    : ['mutual'=>bool, 'meta'=>'글 3', 'latest'=>'...', 'shared'=>1, 'rtab'=>'find', 'rq'=>..., 'rsort'=>...]
 */
function userCard($u, $action, $label, $class, $opts = []) {
    $img = !empty($u['profile_image_stored'])
        ? '<img src="../uploads/' . htmlspecialchars($u['profile_image_stored']) . '" alt="">'
        : '<span>' . htmlspecialchars(mb_substr($u['nickname'], 0, 1)) . '</span>';
    $title = $u['blog_title'] ?: $u['nickname'] . '님의 블로그';
    $nick  = htmlspecialchars($u['nickname']);
    if (!empty($opts['meta'])) $nick .= ' · ' . htmlspecialchars($opts['meta']);
    $online = !empty($u['last_seen_at']) && strtotime($u['last_seen_at']) >= time() - 300;
    ?>
    <div class="nbr">
      <a class="nbr__main" href="blog.php?id=<?= (int)$u['id'] ?>">
        <div class="nbr__img"><?= $img ?></div>
        <div class="nbr__text">
          <div class="nbr__title-row">
            <div class="nbr__title"><?= htmlspecialchars($title) ?>
              <?php if (!empty($opts['mutual'])): ?><em class="nbr__mutual">서로이웃</em><?php endif; ?>
            </div>
            <em class="nbr__online <?= $online ? 'is-on' : '' ?>"><?= $online ? '접속 중' : '오프라인' ?></em>
          </div>
          <div class="nbr__nick"><?= $nick ?></div>
          <?php if (!empty($opts['latest']) || !empty($opts['shared'])): ?>
            <div class="nbr__extra">
              <?php if (!empty($opts['shared'])): ?><span>공통 주제 <?= (int)$opts['shared'] ?></span><?php endif; ?>
              <?php if (!empty($opts['latest'])): ?><em>최근 글: <?= htmlspecialchars(mb_strimwidth($opts['latest'], 0, 34, '…')) ?></em><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </a>
      <form method="post" action="neighbors.php">
        <input type="hidden" name="target" value="<?= (int)$u['id'] ?>">
        <input type="hidden" name="action" value="<?= $action ?>">
        <input type="hidden" name="rtab"   value="<?= htmlspecialchars($opts['rtab']  ?? '') ?>">
        <input type="hidden" name="rq"      value="<?= htmlspecialchars($opts['rq']    ?? '') ?>">
        <input type="hidden" name="rsort"   value="<?= htmlspecialchars($opts['rsort'] ?? '') ?>">
        <button type="submit" class="<?= $class ?>"><?= $label ?></button>
      </form>
      <a class="btn-ghost-dark nbr__message" href="messages.php?to=<?= (int)$u['id'] ?>">쪽지</a>
    </div>
    <?php
}
?>

<nav class="nbr-tabs">
  <a class="<?= $tab === 'neighbors' ? 'on' : '' ?>" href="neighbors.php">내 이웃</a>
  <a class="<?= $tab === 'find' ? 'on' : '' ?>" href="neighbors.php?tab=find">블로그 찾기</a>
</nav>

<?php if ($tab === 'neighbors'): ?>

  <section class="nbr-sec">
    <h1>이웃 <?= count($myNeighbors) ?></h1>
    <?php if (!$myNeighbors): ?>
      <p class="empty">아직 추가한 이웃이 없어요. "블로그 찾기" 탭에서 이웃을 추가해보세요.</p>
    <?php else: ?>
      <div class="nbr-list">
        <?php foreach ($myNeighbors as $u):
            userCard($u, 'cancel', '이웃 취소', 'btn-ghost-dark', ['mutual' => $u['mutual']]);
        endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($addedMe): ?>
    <section class="nbr-sec">
      <h2 class="sec-title">나를 추가한 이웃</h2>
      <div class="nbr-list">
        <?php foreach ($addedMe as $u):
            userCard($u, 'add', '이웃 추가', 'btn-primary');
        endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

<?php else: ?>

  <section class="nbr-sec">
    <h1>블로그 찾기</h1>
    <div class="neighbor-bridge">
      <span>BRIDGE 206 이웃 찾기</span>
      <strong>나이보다 관심사로 먼저 연결되는 공간</strong>
      <p>20대와 60대를 시작점으로, 모든 세대가 서로의 일상과 취미를 발견할 수 있도록 블로그를 찾아보세요.</p>
    </div>

    <form class="nbr-search" method="get" action="neighbors.php">
      <input type="hidden" name="tab" value="find">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
             placeholder="닉네임 또는 블로그 제목 검색">
      <select name="sort" onchange="this.form.submit()">
        <option value="match"  <?= $sort === 'match'  ? 'selected' : '' ?>>추천 순</option>
        <option value="posts"  <?= $sort === 'posts'  ? 'selected' : '' ?>>글 많은 순</option>
        <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>최신 가입 순</option>
        <option value="name"   <?= $sort === 'name'   ? 'selected' : '' ?>>이름 순</option>
      </select>
      <button type="submit" class="btn-primary">검색</button>
    </form>

    <?php if ($q === '' && $recommendedUsers): ?>
      <section class="neighbor-reco" aria-label="관심사 추천 블로그">
        <div class="neighbor-reco__head">
          <span>관심사 추천</span>
          <strong><?= $viewerCategories ? '내가 쓴 주제와 겹치는 블로그' : '지금 읽어볼 만한 블로그' ?></strong>
        </div>
        <div class="neighbor-reco__grid">
          <?php foreach ($recommendedUsers as $u):
              $rd = [
                  'rtab' => 'find',
                  'rq' => $q,
                  'rsort' => $sort,
                  'meta' => '글 ' . (int)$u['post_count'],
                  'latest' => $u['latest_title'] ?? '',
                  'shared' => (int)($u['shared_count'] ?? 0),
              ];
              if ($u['is_neighbor']) {
                  userCard($u, 'cancel', '이웃 취소', 'btn-ghost-dark', $rd);
              } else {
                  userCard($u, 'add', '이웃 추가', 'btn-primary', $rd);
              }
          endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$users): ?>
      <p class="empty"><?= $q !== '' ? '검색 결과가 없어요.' : '아직 다른 사용자가 없어요.' ?></p>
    <?php else: ?>
      <div class="nbr-list">
        <?php foreach ($users as $u):
            $rd = [
                'rtab' => 'find',
                'rq' => $q,
                'rsort' => $sort,
                'meta' => '글 ' . (int)$u['post_count'],
                'latest' => $u['latest_title'] ?? '',
                'shared' => (int)($u['shared_count'] ?? 0),
            ];
            if ($u['is_neighbor']) {
                userCard($u, 'cancel', '이웃 취소', 'btn-ghost-dark', $rd);
            } else {
                userCard($u, 'add', '이웃 추가', 'btn-primary', $rd);
            }
        endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php endif; ?>

<?php require_once __DIR__ . '/../app/footer.php'; ?>

