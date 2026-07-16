CREATE TABLE IF NOT EXISTS ai_assist_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  assist_mode VARCHAR(20) NOT NULL,
  input_excerpt VARCHAR(500) NOT NULL,
  used_api TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_assist_user_created (user_id, created_at),
  CONSTRAINT fk_ai_assist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
