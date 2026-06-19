<?php
/**
 * check_setup.php - local setup checker.
 *
 * Open in a browser:
 *   http://localhost/blog/tools/check_setup.php
 *
 * This script does not change data. It only checks DB connection, required tables,
 * and a few columns that are easy to miss after schema changes.
 */

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../app/db.php';

$requiredTables = [
    'users',
    'categories',
    'posts',
    'comments',
    'likes',
    'neighbors',
    'tags',
    'post_tags',
    'visit_logs',
    'post_images',
    'scraps',
    'guestbook',
];

$requiredColumns = [
    'posts' => ['is_pinned'],
    'tags' => ['normalized_name'],
    'users' => ['notifications_read_at'],
];

echo "MyBlog setup check\n";
echo "==================\n\n";
echo "DB connection: OK\n\n";

$ok = true;

echo "Tables\n";
foreach ($requiredTables as $table) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();

    echo ($exists ? "[OK]   " : "[MISS] ") . $table . "\n";
    if (!$exists) $ok = false;
}

echo "\nColumns\n";
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
        $stmt->close();

        echo ($exists ? "[OK]   " : "[MISS] ") . $table . "." . $column . "\n";
        if (!$exists) $ok = false;
    }
}

echo "\nSample data\n";
foreach (['users', 'posts', 'tags'] as $table) {
    $result = $conn->query("SELECT COUNT(*) AS cnt FROM `$table`");
    $count = (int)($result->fetch_assoc()['cnt'] ?? 0);
    echo "[INFO] " . $table . ": " . $count . "\n";
}

echo "\nResult: " . ($ok ? "OK" : "Needs setup") . "\n";
if (!$ok) {
    echo "\nRun SQL files in this order:\n";
    echo "1. database/blog_schema.sql\n";
    echo "2. database/blog_sample_data.sql\n";
}
