<?php
/**
 * categories.php — 고정 카테고리(주제) 목록 + 헬퍼.
 *   글쓰기에서 이 목록 중 하나를 고른다. ('전체'는 메인 필터용이라 목록엔 없음)
 *   주제는 전체 공용이지만 DB(categories)는 유저별이라, 고른 주제를
 *   그 유저의 카테고리로 보장(없으면 생성)해 category_id 로 연결한다.
 */

$FIXED_CATEGORIES = ['자동차', '어학·외국어', 'IT·컴퓨터', '취미', '좋은글·이미지', '인테리어·DIY', '영화'];

/** 해당 유저에게 이름이 $name 인 카테고리를 보장(없으면 생성)하고 id 를 반환 */
function ensureCategory(mysqli $conn, int $userId, string $name): int {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
    $stmt->bind_param("is", $userId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];

    $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM categories WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $sort = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO categories (user_id, name, sort_order) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $userId, $name, $sort);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}
