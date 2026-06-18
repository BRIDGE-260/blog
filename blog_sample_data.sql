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
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` (`id`, `user_id`, `name`, `sort_order`, `created_at`) VALUES (1,1,'취미',1,'2026-06-12 15:23:26'),(2,1,'어학·외국어',2,'2026-06-12 15:23:26'),(3,2,'IT·컴퓨터',1,'2026-06-12 15:23:26'),(4,2,'어학·외국어',2,'2026-06-12 15:23:26'),(5,3,'취미',1,'2026-06-12 15:23:26'),(6,3,'인테리어·DIY',2,'2026-06-12 15:23:26'),(7,3,'자동차',3,'2026-06-12 15:23:26'),(8,3,'좋은글·이미지',4,'2026-06-12 15:23:26'),(9,4,'영화',1,'2026-06-12 15:23:26'),(10,4,'좋은글·이미지',2,'2026-06-12 15:23:26'),(11,5,'취미',1,'2026-06-12 16:21:50'),(12,5,'자동차',2,'2026-06-18 09:24:16'),(13,5,'어학·외국어',3,'2026-06-18 09:39:40');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `comments`
--

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
INSERT INTO `comments` (`id`, `post_id`, `parent_id`, `user_id`, `content`, `created_at`) VALUES (1,1,NULL,3,'이 앨범 진짜 명반이죠 👍','2026-06-12 15:23:26'),(2,1,NULL,4,'추천 감사합니다, 바로 들어볼게요!','2026-06-12 15:23:26'),(3,7,NULL,1,'여기 분위기 좋네요, 저도 가봐야겠어요.','2026-06-12 15:23:26'),(4,11,NULL,2,'극장에서 꼭 봐야겠네요.','2026-06-12 15:23:26'),(5,9,NULL,4,'전기차 고민 중인데 도움 됐습니다.','2026-06-12 15:23:26'),(7,18,NULL,5,'123','2026-06-18 10:06:17'),(8,18,7,5,'123','2026-06-18 11:06:53'),(9,2,NULL,5,'asd','2026-06-18 11:24:38');
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `guestbook`
--

LOCK TABLES `guestbook` WRITE;
/*!40000 ALTER TABLE `guestbook` DISABLE KEYS */;
/*!40000 ALTER TABLE `guestbook` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `likes`
--

LOCK TABLES `likes` WRITE;
/*!40000 ALTER TABLE `likes` DISABLE KEYS */;
INSERT INTO `likes` (`id`, `post_id`, `user_id`, `created_at`) VALUES (1,1,2,'2026-06-12 15:23:26'),(2,1,3,'2026-06-12 15:23:26'),(3,1,4,'2026-06-12 15:23:26'),(4,2,3,'2026-06-12 15:23:26'),(5,7,1,'2026-06-12 15:23:26'),(6,7,4,'2026-06-12 15:23:26'),(7,9,1,'2026-06-12 15:23:26'),(8,9,2,'2026-06-12 15:23:26'),(9,11,1,'2026-06-12 15:23:26'),(10,11,2,'2026-06-12 15:23:26'),(11,11,3,'2026-06-12 15:23:26'),(12,8,1,'2026-06-12 15:23:26'),(14,18,5,'2026-06-18 11:06:22'),(15,2,5,'2026-06-18 11:24:33');
/*!40000 ALTER TABLE `likes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `neighbors`
--

LOCK TABLES `neighbors` WRITE;
/*!40000 ALTER TABLE `neighbors` DISABLE KEYS */;
INSERT INTO `neighbors` (`id`, `user_id`, `neighbor_id`, `created_at`) VALUES (1,1,2,'2026-06-12 15:23:26'),(2,1,3,'2026-06-12 15:23:26'),(3,2,1,'2026-06-12 15:23:26'),(4,2,3,'2026-06-12 15:23:26'),(5,3,1,'2026-06-12 15:23:26'),(6,3,4,'2026-06-12 15:23:26'),(7,4,1,'2026-06-12 15:23:26'),(8,5,1,'2026-06-12 16:22:47');
/*!40000 ALTER TABLE `neighbors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `post_images`
--

LOCK TABLES `post_images` WRITE;
/*!40000 ALTER TABLE `post_images` DISABLE KEYS */;
INSERT INTO `post_images` (`id`, `post_id`, `original`, `stored`, `sort_order`) VALUES (9,18,'components.png','img_6a334438bcdca2.52253869.png',0),(10,18,'hero.png','img_6a334438bd7ce2.34798312.png',1),(11,18,'jsx-ui.png','img_6a334438be6793.87254651.png',2);
/*!40000 ALTER TABLE `post_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `post_tags`
--

LOCK TABLES `post_tags` WRITE;
/*!40000 ALTER TABLE `post_tags` DISABLE KEYS */;
INSERT INTO `post_tags` (`post_id`, `tag_id`) VALUES (1,1),(1,2),(2,2),(2,3),(3,1),(3,4),(4,5),(4,6),(5,7),(5,8),(6,8),(6,9),(7,10),(7,11),(8,12),(8,13),(9,14),(9,15),(10,16),(10,17),(11,18),(11,19),(12,18),(12,20),(13,18),(13,21),(18,1);
/*!40000 ALTER TABLE `post_tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `posts`
--

LOCK TABLES `posts` WRITE;
/*!40000 ALTER TABLE `posts` DISABLE KEYS */;
INSERT INTO `posts` (`id`, `user_id`, `category_id`, `title`, `content`, `thumbnail_original`, `thumbnail_stored`, `view_count`, `visibility`, `status`, `created_at`, `updated_at`) VALUES (1,1,1,'[J-POP] Yagami Junko 베스트 10','야가미 준코의 명곡을 골라봤습니다.\n첫 곡부터 마지막까지 버릴 게 없네요.',NULL,NULL,154,'all','published','2026-05-31 15:23:26','2026-05-31 15:23:26'),(2,1,1,'이소라 4집 다시 듣기','비 오는 날엔 역시 이소라.\n4집은 들을수록 깊어집니다.',NULL,NULL,204,'all','published','2026-06-03 15:23:26','2026-06-18 11:24:26'),(3,1,2,'일본어, 가사로 공부하기','좋아하는 곡 가사를 외우면 단어가 자연스럽게 늘어요.',NULL,NULL,88,'all','published','2026-06-05 15:23:26','2026-06-05 15:23:26'),(4,2,3,'PHP PDO 깔끔 정리','prepare → bindValue → execute 흐름만 알면 끝.\n예제 위주로 정리했어요.',NULL,NULL,46,'all','published','2026-06-06 15:23:26','2026-06-06 15:23:26'),(5,2,3,'Git 입문 30분 컷','add, commit, push 세 개면 시작은 충분합니다.',NULL,NULL,72,'all','published','2026-06-07 15:23:26','2026-06-07 15:23:26'),(6,2,4,'개발자 영어 단어장','deploy, rollback, dependency… 자주 보는 단어부터.',NULL,NULL,35,'all','published','2026-06-08 15:23:26','2026-06-08 15:23:26'),(7,3,5,'성수동 카페 투어','주말에 성수동 카페 세 곳을 다녀왔어요.\n사진 잔뜩 찍었습니다.',NULL,NULL,321,'all','published','2026-06-04 15:23:26','2026-06-12 15:30:44'),(8,3,6,'원룸 셀프 인테리어 후기','셀프로 선반 달고 조명만 바꿨는데 분위기가 확 사네요.',NULL,NULL,152,'all','published','2026-06-09 15:23:26','2026-06-12 15:30:44'),(9,3,7,'전기차 6개월 솔직 후기','충전은 생각보다 편하고, 유지비는 확실히 줄었습니다.',NULL,NULL,213,'all','published','2026-06-10 15:23:26','2026-06-12 15:30:44'),(10,3,8,'오늘의 한 줄','“오늘 할 수 있는 일에 집중하자.”\n작게라도 시작하기.',NULL,NULL,96,'all','published','2026-06-11 15:23:26','2026-06-12 15:30:43'),(11,4,9,'듄2 리뷰 (스포 없음)','스케일은 압도적, 사운드는 극장에서 꼭.\n2부가 기대됩니다.',NULL,NULL,180,'all','published','2026-06-07 15:23:26','2026-06-07 15:23:26'),(12,4,9,'오펜하이머 두 번째 감상','두 번 보니 인물 관계가 더 선명하게 들어오네요.',NULL,NULL,130,'all','published','2026-06-09 15:23:26','2026-06-09 15:23:26'),(13,4,10,'영화 명대사 모음','마음에 남은 대사들을 모아봤습니다.',NULL,NULL,61,'all','published','2026-06-11 15:23:26','2026-06-12 15:23:35'),(18,5,12,'123','123','config.png','thumb_6a334438b91991.72970469.png',0,'all','published','2026-06-18 10:04:56',NULL);
/*!40000 ALTER TABLE `posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `scraps`
--

LOCK TABLES `scraps` WRITE;
/*!40000 ALTER TABLE `scraps` DISABLE KEYS */;
INSERT INTO `scraps` (`id`, `user_id`, `post_id`, `created_at`) VALUES (2,5,2,'2026-06-18 11:24:29');
/*!40000 ALTER TABLE `scraps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
INSERT INTO `tags` (`id`, `name`) VALUES (23,'adsf'),(22,'asdf'),(7,'Git'),(1,'JPOP'),(5,'PHP'),(19,'SF'),(3,'가요'),(8,'개발'),(20,'놀란'),(21,'명대사'),(2,'명반'),(16,'명언'),(6,'백엔드'),(11,'성수동'),(9,'영어'),(18,'영화'),(12,'인테리어'),(4,'일본어'),(15,'자동차'),(13,'자취'),(14,'전기차'),(17,'좋은글'),(10,'카페');
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`id`, `email`, `password`, `name`, `nickname`, `gender`, `blog_title`, `intro`, `profile_image_original`, `profile_image_stored`, `created_at`) VALUES (1,'stephane@blog.com','$2y$10$fJOYLABiJDTvNuwWtluzr.XDSwkZplqwi1lScH9mMXldAMyG/9Zvm','김민수','stephane_music','남성','스테판의 음악창고','시티팝과 J-POP을 좋아합니다.',NULL,NULL,'2026-06-12 15:23:26'),(2,'yujin@blog.com','$2y$10$5uoU6d/mU0Xtw7r2uCibwOC8PRqEhIuhGFKZrMV2QI6VWHX4Dxiya','이유진','yujin_dev','여성','유진의 개발 노트','백엔드 공부 기록 중.',NULL,NULL,'2026-06-12 15:23:26'),(3,'mina@blog.com','$2y$10$/KqXlzIBs58tlVc1YqzWJuY6xodi5Bz50Q9t334jCoshe.tJyEQAW','박미나','mina_daily','여성','미나의 하루','카페·자취·소소한 일상.',NULL,NULL,'2026-06-12 15:23:26'),(4,'hoonie@blog.com','$2y$10$KPM2RQhqexuhAAvOOsW59eP3FGLmtkS9TID.g6lJIRZZ7BAA.kFTS','정훈','hoonie_cinema','남성','훈이의 시네마','영화 보고 기록하는 사람.',NULL,NULL,'2026-06-12 15:23:26'),(5,'qwe@gmail.com','$2y$10$JoHfrp4IlwpGmmhbi.y3jeEFM9AcDAy2zGK.T2xPkizA91/WCLnT.','진진진','qwe',NULL,NULL,NULL,NULL,NULL,'2026-06-12 15:24:47');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `visit_logs`
--

LOCK TABLES `visit_logs` WRITE;
/*!40000 ALTER TABLE `visit_logs` DISABLE KEYS */;
INSERT INTO `visit_logs` (`id`, `user_id`, `visit_date`, `count`) VALUES (1,1,'2026-06-12',16),(2,1,'2026-06-11',6),(3,1,'2026-06-10',11),(4,1,'2026-06-09',21),(5,1,'2026-06-08',27),(6,1,'2026-06-07',4),(7,1,'2026-06-06',28),(8,2,'2026-06-12',24),(9,2,'2026-06-11',22),(10,2,'2026-06-10',10),(11,2,'2026-06-09',25),(12,2,'2026-06-08',39),(13,2,'2026-06-07',35),(14,2,'2026-06-06',14),(15,3,'2026-06-12',36),(16,3,'2026-06-11',40),(17,3,'2026-06-10',11),(18,3,'2026-06-09',30),(19,3,'2026-06-08',34),(20,3,'2026-06-07',20),(21,3,'2026-06-06',42),(22,4,'2026-06-12',6),(23,4,'2026-06-11',18),(24,4,'2026-06-10',16),(25,4,'2026-06-09',7),(26,4,'2026-06-08',44),(27,4,'2026-06-07',31),(28,4,'2026-06-06',40);
/*!40000 ALTER TABLE `visit_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
