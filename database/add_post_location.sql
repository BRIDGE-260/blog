ALTER TABLE posts
  ADD COLUMN IF NOT EXISTS location_name VARCHAR(120) NULL COMMENT '글과 관련된 장소명' AFTER content;
