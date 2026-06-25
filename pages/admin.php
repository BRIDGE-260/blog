<?php
/**
 * admin.php - BRIDGE 206 administrator dashboard.
 *   Only users.is_admin = 1 can enter. This page is read-only for now;
 *   destructive management stays in each owner's existing screens.
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}
require_once __DIR__ . '/../app/db.php';

$adminColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
if (!$adminColumnResult || $adminColumnResult->num_rows === 0) {
    http_response_code(503);
    $pageTitle = '관리자 설정 필요 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">관리자 권한 컬럼이 아직 없습니다. database/add_admin_role.sql 을 먼저 실행해주세요.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$adminUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$adminUser || (int)$adminUser['is_admin'] !== 1) {
    http_response_code(403);
    $pageTitle = '관리자 권한 필요 · BRIDGE 206';
    require_once __DIR__ . '/../app/header.php';
    echo '<p class="empty">관리자 권한이 있는 계정만 들어갈 수 있습니다.</p>';
    require_once __DIR__ . '/../app/footer.php';
    exit;
}

function adminFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function adminFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = adminFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function adminCount(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $row = adminFetchOne($conn, $sql, $types, $params);
    return (int)($row['cnt'] ?? 0);
}

$summary = [
    'users' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM users"),
    'posts' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts"),
    'published' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'published'"),
    'drafts' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM posts WHERE status = 'draft'"),
    'comments' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM comments"),
    'guestbook' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM guestbook"),
    'likes' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM likes"),
    'scraps' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM scraps"),
    'neighbors' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM neighbors"),
    'tags' => adminCount($conn, "SELECT COUNT(*) AS cnt FROM tags"),
    'today_visit' => adminCount($conn, "SELECT COALESCE(SUM(count), 0) AS cnt FROM visit_logs WHERE visit_date = CURDATE()"),
    'total_visit' => adminCount($conn, "SELECT COALESCE(SUM(count), 0) AS cnt FROM visit_logs"),
];

$recentUsers = adminFetchAll(
    $conn,
    "SELECT id, email, name, nickname, blog_title, created_at
     FROM users
     ORDER BY created_at DESC, id DESC
     LIMIT 5"
);

$recentPosts = adminFetchAll(
    $conn,
    "SELECT p.id, p.title, p.status, p.visibility, p.view_count, p.created_at,
            u.id AS user_id, u.nickname,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count
     FROM posts p
     JOIN users u ON u.id = p.user_id
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 8"
);

$recentComments = adminFetchAll(
    $conn,
    "SELECT c.id, c.content, c.created_at, c.post_id,
            u.nickname AS writer_nickname, p.title AS post_title
     FROM comments c
     JOIN users u ON u.id = c.user_id
     JOIN posts p ON p.id = c.post_id
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 5"
);

$recentGuestbook = adminFetchAll(
    $conn,
    "SELECT g.id, g.content, g.created_at, g.owner_id,
            writer.nickname AS writer_nickname,
            owner.nickname AS owner_nickname
     FROM guestbook g
     JOIN users writer ON writer.id = g.user_id
     JOIN users owner ON owner.id = g.owner_id
     ORDER BY g.created_at DESC, g.id DESC
     LIMIT 5"
);

$topTags = adminFetchAll(
    $conn,
    "SELECT t.id, t.name, COUNT(*) AS post_count
     FROM tags t
     JOIN post_tags pt ON pt.tag_id = t.id
     JOIN posts p ON p.id = pt.post_id
     GROUP BY t.id, t.name
     ORDER BY post_count DESC, t.name ASC
     LIMIT 8"
);

$topBlogs = adminFetchAll(
    $conn,
    "SELECT u.id, u.nickname, u.blog_title,
            COALESCE(SUM(v.count), 0) AS visit_count,
            (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count
     FROM users u
     LEFT JOIN visit_logs v ON v.user_id = u.id
     GROUP BY u.id, u.nickname, u.blog_title
     ORDER BY visit_count DESC, post_count DESC, u.nickname ASC
     LIMIT 5"
);

$visibilityLabels = ['all' => '전체공개', 'neighbor' => '이웃공개', 'private' => '비공개'];
$statusLabels = ['published' => '발행', 'draft' => '임시저장'];

$pageTitle = '관리자 대시보드 · BRIDGE 206';
$pageClass = 'page--wide';
require_once __DIR__ . '/../app/header.php';
?>

<section class="admin">
  <div class="admin-hero">
    <div>
      <span class="admin-hero__eyebrow">ADMINISTRATOR</span>
      <h1>관리자 대시보드</h1>
      <p>회원, 글, 댓글, 방문 기록을 한 화면에서 확인하는 BRIDGE 206 운영 현황판입니다.</p>
    </div>
    <div class="admin-hero__note">
      <strong>읽기 전용</strong>
      <span>현재 DB에는 관리자 권한 컬럼이 없어 위험한 삭제/수정 기능은 넣지 않았습니다.</span>
    </div>
  </div>

  <div class="admin-stats">
    <div><span><?= number_format($summary['users']) ?></span><strong>회원</strong></div>
    <div><span><?= number_format($summary['posts']) ?></span><strong>전체 글</strong></div>
    <div><span><?= number_format($summary['published']) ?></span><strong>발행 글</strong></div>
    <div><span><?= number_format($summary['drafts']) ?></span><strong>임시저장</strong></div>
    <div><span><?= number_format($summary['comments']) ?></span><strong>댓글</strong></div>
    <div><span><?= number_format($summary['guestbook']) ?></span><strong>방명록</strong></div>
    <div><span><?= number_format($summary['likes']) ?></span><strong>공감</strong></div>
    <div><span><?= number_format($summary['scraps']) ?></span><strong>스크랩</strong></div>
    <div><span><?= number_format($summary['neighbors']) ?></span><strong>이웃 연결</strong></div>
    <div><span><?= number_format($summary['tags']) ?></span><strong>태그</strong></div>
    <div><span><?= number_format($summary['today_visit']) ?></span><strong>오늘 방문</strong></div>
    <div><span><?= number_format($summary['total_visit']) ?></span><strong>누적 방문</strong></div>
  </div>

  <div class="admin-grid">
    <section class="admin-panel admin-panel--wide">
      <div class="admin-panel__head">
        <h2>최근 가입 회원</h2>
        <span>신규 <?= count($recentUsers) ?>명</span>
      </div>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>회원번호</th>
              <th>닉네임</th>
              <th>이름</th>
              <th>이메일</th>
              <th>블로그</th>
              <th>가입일</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><a href="blog.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nickname']) ?></a></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['blog_title'] ?: $u['nickname'] . '의 블로그') ?></td>
                <td><?= date('Y.m.d H:i', strtotime($u['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentUsers): ?>
              <tr><td colspan="6" class="admin-empty">회원이 없습니다.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel admin-panel--wide">
      <div class="admin-panel__head">
        <h2>최근 게시글</h2>
        <span>최신 <?= count($recentPosts) ?>개</span>
      </div>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>글번호</th>
              <th>제목</th>
              <th>작성자</th>
              <th>상태</th>
              <th>공개</th>
              <th>반응</th>
              <th>작성일</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentPosts as $p): ?>
              <tr>
                <td><?= (int)$p['id'] ?></td>
                <td><a href="view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a></td>
                <td><a href="blog.php?id=<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['nickname']) ?></a></td>
                <td><span class="admin-badge"><?= htmlspecialchars($statusLabels[$p['status']] ?? $p['status']) ?></span></td>
                <td><?= htmlspecialchars($visibilityLabels[$p['visibility']] ?? $p['visibility']) ?></td>
                <td>조회 <?= (int)$p['view_count'] ?> · 공감 <?= (int)$p['like_count'] ?> · 댓글 <?= (int)$p['comment_count'] ?></td>
                <td><?= date('Y.m.d H:i', strtotime($p['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recentPosts): ?>
              <tr><td colspan="7" class="admin-empty">게시글이 없습니다.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <h2>최근 댓글</h2>
        <span><?= count($recentComments) ?>개</span>
      </div>
      <div class="admin-list">
        <?php foreach ($recentComments as $c): ?>
          <a href="view.php?id=<?= (int)$c['post_id'] ?>#comment-<?= (int)$c['id'] ?>">
            <strong><?= htmlspecialchars($c['writer_nickname']) ?></strong>
            <span><?= htmlspecialchars(mb_strimwidth($c['content'], 0, 70, '...')) ?></span>
            <em><?= htmlspecialchars($c['post_title']) ?> · <?= date('m.d H:i', strtotime($c['created_at'])) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentComments): ?><p class="admin-empty">댓글이 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <h2>최근 방명록</h2>
        <span><?= count($recentGuestbook) ?>개</span>
      </div>
      <div class="admin-list">
        <?php foreach ($recentGuestbook as $g): ?>
          <a href="guestbook.php?id=<?= (int)$g['owner_id'] ?>">
            <strong><?= htmlspecialchars($g['writer_nickname']) ?> → <?= htmlspecialchars($g['owner_nickname']) ?></strong>
            <span><?= htmlspecialchars(mb_strimwidth($g['content'], 0, 70, '...')) ?></span>
            <em><?= date('m.d H:i', strtotime($g['created_at'])) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentGuestbook): ?><p class="admin-empty">방명록 글이 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <h2>인기 태그</h2>
        <span>TOP <?= count($topTags) ?></span>
      </div>
      <div class="admin-chips">
        <?php foreach ($topTags as $t): ?>
          <a href="index.php?tag=<?= (int)$t['id'] ?>">#<?= htmlspecialchars($t['name']) ?> <span><?= (int)$t['post_count'] ?></span></a>
        <?php endforeach; ?>
        <?php if (!$topTags): ?><p class="admin-empty">태그가 없습니다.</p><?php endif; ?>
      </div>
    </section>

    <section class="admin-panel">
      <div class="admin-panel__head">
        <h2>방문 많은 블로그</h2>
        <span>TOP <?= count($topBlogs) ?></span>
      </div>
      <div class="admin-rank">
        <?php foreach ($topBlogs as $i => $b): ?>
          <a href="blog.php?id=<?= (int)$b['id'] ?>">
            <span><?= $i + 1 ?></span>
            <strong><?= htmlspecialchars($b['blog_title'] ?: $b['nickname'] . '의 블로그') ?></strong>
            <em>방문 <?= number_format((int)$b['visit_count']) ?> · 글 <?= number_format((int)$b['post_count']) ?></em>
          </a>
        <?php endforeach; ?>
        <?php if (!$topBlogs): ?><p class="admin-empty">블로그가 없습니다.</p><?php endif; ?>
      </div>
    </section>
  </div>
</section>

<?php require_once __DIR__ . '/../app/footer.php'; ?>
