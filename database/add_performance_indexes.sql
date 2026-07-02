ALTER TABLE `posts`
  ADD KEY IF NOT EXISTS `idx_posts_public_feed` (`status`, `visibility`, `created_at`, `id`);

ALTER TABLE `posts`
  ADD KEY IF NOT EXISTS `idx_posts_user_status_pinned` (`user_id`, `status`, `is_pinned`, `created_at`, `id`);

ALTER TABLE `comments`
  ADD KEY IF NOT EXISTS `idx_comments_post_parent_created` (`post_id`, `parent_id`, `created_at`, `id`);

ALTER TABLE `likes`
  ADD KEY IF NOT EXISTS `idx_likes_user_created` (`user_id`, `created_at`, `post_id`);

ALTER TABLE `scraps`
  ADD KEY IF NOT EXISTS `idx_scraps_user_created` (`user_id`, `created_at`, `post_id`);
