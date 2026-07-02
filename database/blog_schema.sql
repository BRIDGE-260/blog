-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: blog
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '카테고리 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '카테고리 소유 회원',
  `name` varchar(50) NOT NULL COMMENT '카테고리 이름',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '카테고리 정렬 순서',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '생성 일시',
  PRIMARY KEY (`id`),
  KEY `idx_categories_user` (`user_id`),
  CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `blog_settings`
--

DROP TABLE IF EXISTS `blog_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blog_settings` (
  `user_id` int(11) NOT NULL COMMENT '블로그 주인 회원 id',
  `accent_color` char(7) NOT NULL DEFAULT '#d4af7a' COMMENT '대표 포인트 색상',
  `background_color` char(7) NOT NULL DEFAULT '#ededed' COMMENT '블로그 배경색',
  `background_image_original` varchar(255) DEFAULT NULL COMMENT '배경 이미지 원본 파일명',
  `background_image_stored` varchar(255) DEFAULT NULL COMMENT '배경 이미지 저장 파일명',
  `background_repeat` varchar(20) NOT NULL DEFAULT 'no-repeat' COMMENT '배경 반복 설정(no-repeat/repeat)',
  `background_position` varchar(20) NOT NULL DEFAULT 'center' COMMENT '배경 위치',
  `background_size` varchar(20) NOT NULL DEFAULT 'cover' COMMENT '배경 크기 설정(cover/contain/auto)',
  `header_image_original` varchar(255) DEFAULT NULL COMMENT '헤더 배너 원본 파일명',
  `header_image_stored` varchar(255) DEFAULT NULL COMMENT '헤더 배너 저장 파일명',
  `header_height` int(11) NOT NULL DEFAULT 220 COMMENT '헤더 배너 높이(px)',
  `layout_type` varchar(20) NOT NULL DEFAULT 'standard' COMMENT '레이아웃 프리셋(standard/wide/compact)',
  `title_align` varchar(10) NOT NULL DEFAULT 'left' COMMENT '블로그 제목 정렬(left/center)',
  `sidebar_position` varchar(10) NOT NULL DEFAULT 'left' COMMENT '사이드바 위치(left/right)',
  `profile_shape` varchar(20) NOT NULL DEFAULT 'circle' COMMENT '프로필 이미지 모양(circle/rounded/square)',
  `profile_card_color` char(7) NOT NULL DEFAULT '#ffffff' COMMENT '프로필 카드 배경색',
  `post_list_style` varchar(20) NOT NULL DEFAULT 'card' COMMENT '글 목록 스타일(card/list)',
  `thumbnail_style` varchar(20) NOT NULL DEFAULT 'wide' COMMENT '목록 썸네일 스타일(wide/square/hidden)',
  `font_style` varchar(20) NOT NULL DEFAULT 'sans' COMMENT '폰트 프리셋(sans/serif/rounded)',
  `blog_mood` varchar(30) NOT NULL DEFAULT 'daily' COMMENT '블로그 분위기 프리셋',
  `welcome_message` varchar(120) DEFAULT NULL COMMENT '블로그 환영 문구',
  `custom_link_label` varchar(40) DEFAULT NULL COMMENT '추천 링크 이름',
  `custom_link_url` varchar(255) DEFAULT NULL COMMENT '추천 링크 주소',
  `show_intro` tinyint(1) NOT NULL DEFAULT 1 COMMENT '소개글 표시 여부',
  `show_post_summary` tinyint(1) NOT NULL DEFAULT 1 COMMENT '글 요약 표시 여부',
  `show_visit_count` tinyint(1) NOT NULL DEFAULT 1 COMMENT '방문자 수 표시 여부',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '생성 일시',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT '수정 일시',
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_blog_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '댓글 고유 번호',
  `post_id` int(11) NOT NULL COMMENT '댓글이 달린 글',
  `parent_id` int(11) DEFAULT NULL COMMENT '부모 댓글 id(답글이면 그 댓글, 일반댓글이면 NULL)',
  `user_id` int(11) NOT NULL COMMENT '댓글 작성자',
  `content` text NOT NULL COMMENT '댓글 내용',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '작성 일시',
  PRIMARY KEY (`id`),
  KEY `idx_comments_post` (`post_id`),
  KEY `idx_comments_user` (`user_id`),
  KEY `idx_comments_parent` (`parent_id`),
  KEY `idx_comments_post_parent_created` (`post_id`,`parent_id`,`created_at`,`id`),
  CONSTRAINT `fk_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Table structure for table `guestbook`
--

DROP TABLE IF EXISTS `guestbook`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guestbook` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '방명록 글 고유 번호',
  `owner_id` int(11) NOT NULL COMMENT '방명록 주인(블로그 주인)',
  `user_id` int(11) NOT NULL COMMENT '방명록을 남긴 회원',
  `content` text NOT NULL COMMENT '방명록 내용',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '작성 일시',
  PRIMARY KEY (`id`),
  KEY `idx_guestbook_owner` (`owner_id`),
  KEY `idx_guestbook_user` (`user_id`),
  CONSTRAINT `fk_guestbook_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_guestbook_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `likes`
--

DROP TABLE IF EXISTS `likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '공감 고유 번호',
  `post_id` int(11) NOT NULL COMMENT '공감한 글',
  `user_id` int(11) NOT NULL COMMENT '공감한 회원',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '공감 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_likes_post_user` (`post_id`,`user_id`),
  KEY `idx_likes_user` (`user_id`),
  KEY `idx_likes_user_created` (`user_id`,`created_at`,`post_id`),
  CONSTRAINT `fk_likes_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `neighbors`
--

DROP TABLE IF EXISTS `neighbors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `neighbors` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '이웃 관계 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '이웃을 추가한 회원',
  `neighbor_id` int(11) NOT NULL COMMENT '이웃으로 추가된 회원',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '이웃추가 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_neighbors_pair` (`user_id`,`neighbor_id`),
  KEY `idx_neighbors_neighbor` (`neighbor_id`),
  CONSTRAINT `fk_neighbors_neighbor` FOREIGN KEY (`neighbor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_neighbors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_messages_receiver_read` (`receiver_id`,`is_read`,`created_at`),
  KEY `idx_messages_pair` (`sender_id`,`receiver_id`,`created_at`),
  CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `moderation_logs`
--

DROP TABLE IF EXISTS `moderation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `moderation_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `target_type` varchar(30) NOT NULL,
  `target_id` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_moderation_logs_created` (`created_at`),
  KEY `idx_moderation_logs_target` (`target_type`,`target_id`),
  CONSTRAINT `fk_moderation_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_reads`
--

DROP TABLE IF EXISTS `notification_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '읽은 소식 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '소식을 읽은 회원',
  `notification_key` varchar(80) NOT NULL COMMENT '소식 종류와 원본 id(comment:1 등)',
  `read_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '읽은 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_reads_user_key` (`user_id`,`notification_key`),
  CONSTRAINT `fk_notification_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `post_images`
--

DROP TABLE IF EXISTS `post_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `original` varchar(255) NOT NULL,
  `stored` varchar(255) NOT NULL,
  `media_type` varchar(10) NOT NULL DEFAULT 'image',
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_post_images_post` (`post_id`),
  KEY `idx_post_images_post_type_order` (`post_id`,`media_type`,`sort_order`,`id`),
  CONSTRAINT `fk_post_images_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `post_tags`
--

DROP TABLE IF EXISTS `post_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post_tags` (
  `post_id` int(11) NOT NULL COMMENT '연결된 글',
  `tag_id` int(11) NOT NULL COMMENT '연결된 태그',
  PRIMARY KEY (`post_id`,`tag_id`),
  KEY `idx_post_tags_tag` (`tag_id`),
  CONSTRAINT `fk_post_tags_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_post_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Table structure for table `posts`
--

DROP TABLE IF EXISTS `posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '글 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '작성자',
  `category_id` int(11) DEFAULT NULL COMMENT '글이 속한 카테고리',
  `title` varchar(200) NOT NULL COMMENT '글 제목',
  `content` text NOT NULL COMMENT '글 본문 (HTML)',
  `thumbnail_original` varchar(255) DEFAULT NULL COMMENT '썸네일 원본 파일명',
  `thumbnail_stored` varchar(255) DEFAULT NULL COMMENT '썸네일 저장 파일명(변환됨)',
  `view_count` int(11) NOT NULL DEFAULT 0 COMMENT '조회수',
  `visibility` varchar(10) NOT NULL DEFAULT 'all' COMMENT '공개 설정 (all/neighbor/private)',
  `status` varchar(10) NOT NULL DEFAULT 'draft' COMMENT '글 상태 (draft 임시저장 / published 발행)',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT '블로그 상단 고정 여부',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '작성 일시',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT '수정 일시',
  PRIMARY KEY (`id`),
  KEY `idx_posts_user` (`user_id`),
  KEY `idx_posts_category` (`category_id`),
  KEY `idx_posts_pinned` (`user_id`,`is_pinned`,`created_at`),
  KEY `idx_posts_public_feed` (`status`,`visibility`,`created_at`,`id`),
  KEY `idx_posts_user_status_pinned` (`user_id`,`status`,`is_pinned`,`created_at`,`id`),
  CONSTRAINT `fk_posts_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `scraps`
--

DROP TABLE IF EXISTS `scraps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scraps` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '스크랩 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '스크랩한 회원',
  `post_id` int(11) NOT NULL COMMENT '스크랩된 글',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '스크랩 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scraps_user_post` (`user_id`,`post_id`),
  KEY `idx_scraps_post` (`post_id`),
  KEY `idx_scraps_user_created` (`user_id`,`created_at`,`post_id`),
  CONSTRAINT `fk_scraps_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_scraps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '태그 고유 번호',
  `name` varchar(50) NOT NULL COMMENT '태그 이름',
  `normalized_name` varchar(50) NOT NULL COMMENT '대소문자 구분 없는 태그 묶음 키',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tags_name` (`name`),
  UNIQUE KEY `uq_tags_normalized_name` (`normalized_name`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '회원 고유 번호',
  `email` varchar(100) NOT NULL COMMENT '로그인 ID로 사용하는 이메일',
  `password` varchar(255) NOT NULL COMMENT '암호화된 비밀번호',
  `name` varchar(50) NOT NULL COMMENT '회원 이름(실명)',
  `nickname` varchar(50) NOT NULL COMMENT '블로그 닉네임(화면 표시용)',
  `gender` varchar(10) DEFAULT NULL COMMENT '성별 (여성/남성)',
  `blog_title` varchar(100) DEFAULT NULL COMMENT '내 블로그 제목',
  `intro` varchar(255) DEFAULT NULL COMMENT '블로그 소개글',
  `profile_image_original` varchar(255) DEFAULT NULL COMMENT '프로필 이미지 원본 파일명',
  `profile_image_stored` varchar(255) DEFAULT NULL COMMENT '프로필 이미지 저장 파일명(변환됨)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '가입 일시',
  `notifications_read_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' COMMENT '마지막 소식 확인 시각',
  `last_seen_at` datetime DEFAULT NULL COMMENT '최근 접속 시각',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'admin flag',
  `is_banned` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ban flag',
  `banned_reason` varchar(255) DEFAULT NULL COMMENT '차단 사유',
  `banned_at` datetime DEFAULT NULL COMMENT '차단 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_nickname` (`nickname`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `visit_logs`
--

DROP TABLE IF EXISTS `visit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '방문 기록 고유 번호',
  `user_id` int(11) NOT NULL COMMENT '방문당한 블로그 주인',
  `visit_date` date NOT NULL COMMENT '방문 날짜',
  `count` int(11) NOT NULL DEFAULT 0 COMMENT '그 날의 방문 수',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_user_date` (`user_id`,`visit_date`),
  CONSTRAINT `fk_visit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `visit_events`
--

DROP TABLE IF EXISTS `visit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visit_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `viewer_id` int(11) DEFAULT NULL,
  `visit_date` date NOT NULL,
  `visit_hour` tinyint unsigned NOT NULL,
  `viewer_gender` varchar(10) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_visit_events_owner_date_hour` (`owner_id`,`visit_date`,`visit_hour`),
  KEY `idx_visit_events_owner_gender` (`owner_id`,`viewer_gender`),
  CONSTRAINT `fk_visit_events_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_visit_events_viewer` FOREIGN KEY (`viewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `site_settings`
--

DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
