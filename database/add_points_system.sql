CREATE TABLE IF NOT EXISTS point_wallets (
  user_id INT NOT NULL,
  balance INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_point_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_transactions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  amount INT NOT NULL,
  action_type VARCHAR(30) NOT NULL,
  ref_key VARCHAR(100) NOT NULL,
  description VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_point_action (user_id, action_type, ref_key),
  KEY idx_point_user_created (user_id, created_at),
  CONSTRAINT fk_point_transaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roulette_spins (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  spin_date DATE NOT NULL,
  reward INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roulette_daily (user_id, spin_date),
  CONSTRAINT fk_roulette_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_point_badges (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  badge_code VARCHAR(30) NOT NULL,
  is_equipped TINYINT(1) NOT NULL DEFAULT 0,
  purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_point_badge (user_id, badge_code),
  KEY idx_user_badge_equipped (user_id, is_equipped),
  CONSTRAINT fk_user_point_badge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
