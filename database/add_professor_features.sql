-- Extra features requested for the professor demo.
-- Run once on an existing DB after the previous migration files.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS last_seen_at datetime DEFAULT NULL AFTER notifications_read_at;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_banned tinyint(1) NOT NULL DEFAULT 0 AFTER is_admin,
  ADD COLUMN IF NOT EXISTS banned_reason varchar(255) DEFAULT NULL AFTER is_banned,
  ADD COLUMN IF NOT EXISTS banned_at datetime DEFAULT NULL AFTER banned_reason;

ALTER TABLE blog_settings
  ADD COLUMN IF NOT EXISTS blog_mood varchar(30) NOT NULL DEFAULT 'daily' AFTER font_style,
  ADD COLUMN IF NOT EXISTS welcome_message varchar(120) DEFAULT NULL AFTER blog_mood,
  ADD COLUMN IF NOT EXISTS custom_link_label varchar(40) DEFAULT NULL AFTER welcome_message,
  ADD COLUMN IF NOT EXISTS custom_link_url varchar(255) DEFAULT NULL AFTER custom_link_label;

CREATE TABLE IF NOT EXISTS messages (
  id int(11) NOT NULL AUTO_INCREMENT,
  sender_id int(11) NOT NULL,
  receiver_id int(11) NOT NULL,
  content text NOT NULL,
  is_read tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_messages_receiver_read (receiver_id, is_read, created_at),
  KEY idx_messages_pair (sender_id, receiver_id, created_at),
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_events (
  id int(11) NOT NULL AUTO_INCREMENT,
  owner_id int(11) NOT NULL,
  viewer_id int(11) DEFAULT NULL,
  visit_date date NOT NULL,
  visit_hour tinyint unsigned NOT NULL,
  viewer_gender varchar(10) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_visit_events_owner_date_hour (owner_id, visit_date, visit_hour),
  KEY idx_visit_events_owner_gender (owner_id, viewer_gender),
  CONSTRAINT fk_visit_events_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_visit_events_viewer FOREIGN KEY (viewer_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key varchar(60) NOT NULL,
  setting_value text DEFAULT NULL,
  updated_at datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('site_notice', ''),
('main_feature_title', 'BRIDGE 206'),
('main_feature_text', '세대와 관심사를 잇는 블로그'),
('allow_public_join', '1');

CREATE TABLE IF NOT EXISTS moderation_logs (
  id int(11) NOT NULL AUTO_INCREMENT,
  admin_id int(11) NOT NULL,
  target_type varchar(30) NOT NULL,
  target_id int(11) NOT NULL,
  action varchar(40) NOT NULL,
  reason varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_moderation_logs_created (created_at),
  KEY idx_moderation_logs_target (target_type, target_id),
  CONSTRAINT fk_moderation_logs_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
