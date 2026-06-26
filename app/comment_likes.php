<?php
function ensureCommentLikesTable(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS comment_likes (
            id INT(11) NOT NULL AUTO_INCREMENT,
            comment_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_comment_likes_comment_user (comment_id, user_id),
            KEY idx_comment_likes_user (user_id),
            CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
