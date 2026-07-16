INSERT INTO users (email, password, name, nickname, blog_title, intro, is_admin)
VALUES (
  'admin@blog.com',
  '$2y$10$fJOYLABiJDTvNuwWtluzr.XDSwkZplqwi1lScH9mMXldAMyG/9Zvm',
  '관리자',
  'bridge_admin',
  'BRIDGE 206 운영 블로그',
  '사이트 운영과 공지사항을 관리합니다.',
  1
)
ON DUPLICATE KEY UPDATE is_admin = 1;
