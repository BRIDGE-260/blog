ALTER TABLE `post_images`
  ADD COLUMN IF NOT EXISTS `media_type` varchar(10) NOT NULL DEFAULT 'image' AFTER `stored`;

ALTER TABLE `post_images`
  ADD COLUMN IF NOT EXISTS `mime_type` varchar(100) DEFAULT NULL AFTER `media_type`;

ALTER TABLE `post_images`
  ADD COLUMN IF NOT EXISTS `file_size` int(10) unsigned DEFAULT NULL AFTER `mime_type`;

ALTER TABLE `post_images`
  ADD KEY IF NOT EXISTS `idx_post_images_post_type_order` (`post_id`, `media_type`, `sort_order`, `id`);
