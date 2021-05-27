-- Adminer 4.7.6 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';



DROP TABLE IF EXISTS `updates`;
CREATE TABLE `updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` smallint(6) NOT NULL COMMENT 'ID zařízení',
  `fromVersion` varchar(200) COLLATE utf8_czech_ci NOT NULL COMMENT 'verze, ze které se aktualizuje',
  `fileHash` varchar(100) COLLATE utf8_czech_ci NOT NULL COMMENT 'hash souboru',
  `inserted` datetime NOT NULL COMMENT 'timestamp vložení',
  `downloaded` datetime DEFAULT NULL COMMENT 'timestamp stažení',
  PRIMARY KEY (`id`),
  KEY `device_id_fromVersion` (`device_id`,`fromVersion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;


INSERT INTO `value_types` (`id`, `unit`) VALUES
(16,	'lx'),
(17,	'°'),
(18,	'm/s'),
(19,	'mm');


-- 2021-04-22 19:31:38
