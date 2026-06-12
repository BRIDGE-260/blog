<?php
/**
 * seed.php — 데모용 새 데이터 주입 (CLI 또는 브라우저에서 1회 실행).
 *   기존 데이터를 전부 비우고, 우리 블로그에 맞는 사용자/글/태그/공감/댓글/이웃/방문기록을 채운다.
 *   ※ 모든 계정 비밀번호는 1234.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/categories.php';   // ensureCategory()

header('Content-Type: text/plain; charset=utf-8');

// ── 1) 전부 비우기 ─────────────────────
$conn->query("SET FOREIGN_KEY_CHECKS=0");
foreach (['post_tags','comments','likes','neighbors','visit_logs','posts','tags','categories','users'] as $t) {
    $conn->query("TRUNCATE TABLE $t");
}
$conn->query("SET FOREIGN_KEY_CHECKS=1");

// ── 헬퍼들 ─────────────────────────────
function addUser($conn, $email, $nick, $name, $title, $intro, $gender) {
    $pw = password_hash('1234', PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO users (email, password, name, nickname, gender, blog_title, intro) VALUES (?,?,?,?,?,?,?)"
    );
    $stmt->bind_param("sssssss", $email, $pw, $name, $nick, $gender, $title, $intro);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}
function addTag($conn, $pid, $name) {
    $stmt = $conn->prepare("SELECT id FROM tags WHERE name=?");
    $stmt->bind_param("s", $name); $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($r) { $tid = $r['id']; }
    else {
        $stmt = $conn->prepare("INSERT INTO tags (name) VALUES (?)");
        $stmt->bind_param("s", $name); $stmt->execute();
        $tid = $conn->insert_id; $stmt->close();
    }
    $stmt = $conn->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?,?)");
    $stmt->bind_param("ii", $pid, $tid); $stmt->execute(); $stmt->close();
}
function addPost($conn, $uid, $cat, $title, $content, $views, $daysAgo, $tags) {
    $catId = $cat ? ensureCategory($conn, $uid, $cat) : null;
    $stmt = $conn->prepare(
        "INSERT INTO posts (user_id, category_id, title, content, visibility, status, view_count, created_at, updated_at)
         VALUES (?,?,?,?, 'all','published', ?, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))"
    );
    $stmt->bind_param("iissiii", $uid, $catId, $title, $content, $views, $daysAgo, $daysAgo);
    $stmt->execute(); $pid = $conn->insert_id; $stmt->close();
    foreach ($tags as $tg) addTag($conn, $pid, $tg);
    return $pid;
}
function addLike($conn, $pid, $uid) {
    $stmt = $conn->prepare("INSERT IGNORE INTO likes (post_id, user_id) VALUES (?,?)");
    $stmt->bind_param("ii", $pid, $uid); $stmt->execute(); $stmt->close();
}
function addComment($conn, $pid, $uid, $text) {
    $stmt = $conn->prepare("INSERT INTO comments (post_id, user_id, content, created_at) VALUES (?,?,?, NOW())");
    $stmt->bind_param("iis", $pid, $uid, $text); $stmt->execute(); $stmt->close();
}
function addNeighbor($conn, $uid, $nid) {
    $stmt = $conn->prepare("INSERT IGNORE INTO neighbors (user_id, neighbor_id) VALUES (?,?)");
    $stmt->bind_param("ii", $uid, $nid); $stmt->execute(); $stmt->close();
}

// ── 2) 사용자 ──────────────────────────
$u1 = addUser($conn, 'stephane@blog.com', 'stephane_music', '김민수', '스테판의 음악창고', '시티팝과 J-POP을 좋아합니다.', '남성');
$u2 = addUser($conn, 'yujin@blog.com',    'yujin_dev',      '이유진', '유진의 개발 노트',   '백엔드 공부 기록 중.',        '여성');
$u3 = addUser($conn, 'mina@blog.com',     'mina_daily',     '박미나', '미나의 하루',        '카페·자취·소소한 일상.',      '여성');
$u4 = addUser($conn, 'hoonie@blog.com',   'hoonie_cinema',  '정훈',   '훈이의 시네마',      '영화 보고 기록하는 사람.',    '남성');

// ── 3) 글 (7개 고정 주제 전부 채우기) ──
$p1  = addPost($conn,$u1,'취미',        '[J-POP] Yagami Junko 베스트 10', "야가미 준코의 명곡을 골라봤습니다.\n첫 곡부터 마지막까지 버릴 게 없네요.", 154, 12, ['JPOP','명반']);
$p2  = addPost($conn,$u1,'취미',        '이소라 4집 다시 듣기',           "비 오는 날엔 역시 이소라.\n4집은 들을수록 깊어집니다.",                 203,  9, ['가요','명반']);
$p3  = addPost($conn,$u1,'어학·외국어', '일본어, 가사로 공부하기',         "좋아하는 곡 가사를 외우면 단어가 자연스럽게 늘어요.",                     88,  7, ['일본어','JPOP']);
$p4  = addPost($conn,$u2,'IT·컴퓨터',   'PHP PDO 깔끔 정리',              "prepare → bindValue → execute 흐름만 알면 끝.\n예제 위주로 정리했어요.", 46, 6, ['PHP','백엔드']);
$p5  = addPost($conn,$u2,'IT·컴퓨터',   'Git 입문 30분 컷',               "add, commit, push 세 개면 시작은 충분합니다.",                            72,  5, ['Git','개발']);
$p6  = addPost($conn,$u2,'어학·외국어', '개발자 영어 단어장',             "deploy, rollback, dependency… 자주 보는 단어부터.",                       35,  4, ['영어','개발']);
$p7  = addPost($conn,$u3,'취미',        '성수동 카페 투어',               "주말에 성수동 카페 세 곳을 다녀왔어요.\n사진 잔뜩 찍었습니다.",          320,  8, ['카페','성수동']);
$p8  = addPost($conn,$u3,'인테리어·DIY','원룸 셀프 인테리어 후기',         "셀프로 선반 달고 조명만 바꿨는데 분위기가 확 사네요.",                   150,  3, ['인테리어','자취']);
$p9  = addPost($conn,$u3,'자동차',      '전기차 6개월 솔직 후기',         "충전은 생각보다 편하고, 유지비는 확실히 줄었습니다.",                     210,  2, ['전기차','자동차']);
$p10 = addPost($conn,$u3,'좋은글·이미지','오늘의 한 줄',                   "“오늘 할 수 있는 일에 집중하자.”\n작게라도 시작하기.",                    95,  1, ['명언','좋은글']);
$p11 = addPost($conn,$u4,'영화',        '듄2 리뷰 (스포 없음)',           "스케일은 압도적, 사운드는 극장에서 꼭.\n2부가 기대됩니다.",              180,  5, ['영화','SF']);
$p12 = addPost($conn,$u4,'영화',        '오펜하이머 두 번째 감상',         "두 번 보니 인물 관계가 더 선명하게 들어오네요.",                          130,  3, ['영화','놀란']);
$p13 = addPost($conn,$u4,'좋은글·이미지','영화 명대사 모음',               "마음에 남은 대사들을 모아봤습니다.",                                      60,  1, ['명대사','영화']);

// ── 4) 공감 ────────────────────────────
foreach ([[$p1,$u2],[$p1,$u3],[$p1,$u4],[$p2,$u3],[$p7,$u1],[$p7,$u4],
          [$p9,$u1],[$p9,$u2],[$p11,$u1],[$p11,$u2],[$p11,$u3],[$p8,$u1]] as $l) {
    addLike($conn, $l[0], $l[1]);
}

// ── 5) 댓글 ────────────────────────────
addComment($conn, $p1, $u3, '이 앨범 진짜 명반이죠 👍');
addComment($conn, $p1, $u4, '추천 감사합니다, 바로 들어볼게요!');
addComment($conn, $p7, $u1, '여기 분위기 좋네요, 저도 가봐야겠어요.');
addComment($conn, $p11, $u2, '극장에서 꼭 봐야겠네요.');
addComment($conn, $p9, $u4, '전기차 고민 중인데 도움 됐습니다.');

// ── 6) 이웃 ────────────────────────────
addNeighbor($conn, $u1, $u2); addNeighbor($conn, $u1, $u3);
addNeighbor($conn, $u2, $u1); addNeighbor($conn, $u2, $u3);
addNeighbor($conn, $u3, $u1); addNeighbor($conn, $u3, $u4);
addNeighbor($conn, $u4, $u1);

// ── 7) 방문 기록 (최근 7일) ────────────
foreach ([$u1,$u2,$u3,$u4] as $uid) {
    for ($d = 0; $d < 7; $d++) {
        $c = rand(3, 45);
        $stmt = $conn->prepare("INSERT INTO visit_logs (user_id, visit_date, count) VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?)");
        $stmt->bind_param("iii", $uid, $d, $c);
        $stmt->execute(); $stmt->close();
    }
}

echo "시드 완료!\n";
echo "사용자 4명 / 글 13개 / 태그·공감·댓글·이웃·방문기록 생성됨.\n\n";
echo "로그인 계정 (비밀번호 모두 1234):\n";
echo "  stephane@blog.com  (stephane_music)\n";
echo "  yujin@blog.com     (yujin_dev)\n";
echo "  mina@blog.com      (mina_daily)\n";
echo "  hoonie@blog.com    (hoonie_cinema)\n";
