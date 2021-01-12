-- Adminer 4.7.6 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `blobs`;
CREATE TABLE `blobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` smallint(6) NOT NULL,
  `data_time` datetime NOT NULL,
  `server_time` datetime NOT NULL,
  `description` varchar(255) COLLATE utf8_czech_ci NOT NULL,
  `extension` varchar(50) COLLATE utf8_czech_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `session_id` mediumint(9) DEFAULT NULL,
  `remote_ip` varchar(32) COLLATE utf8_czech_ci DEFAULT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `filesize` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;


DROP TABLE IF EXISTS `devices`;
CREATE TABLE `devices` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `passphrase` varchar(100) COLLATE utf8_czech_ci NOT NULL,
  `name` varchar(100) COLLATE utf8_czech_ci NOT NULL,
  `desc` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `first_login` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `last_bad_login` datetime DEFAULT NULL,
  `user_id` smallint(6) NOT NULL,
  `json_token` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `blob_token` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `monitoring` tinyint(4) DEFAULT NULL,
  `app_name` varchar(256) COLLATE utf8_czech_ci DEFAULT NULL,
  `config_ver` smallint(6) DEFAULT NULL,
  `config_data` text COLLATE utf8_czech_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='List of devices. Device has one or more Sensors.';


DROP TABLE IF EXISTS `device_classes`;
CREATE TABLE `device_classes` (
  `id` int(11) NOT NULL,
  `desc` varchar(100) COLLATE utf8_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;

INSERT INTO `device_classes` (`id`, `desc`) VALUES
(1,	'CONTINUOUS_MINMAXAVG'),
(2,	'CONTINUOUS'),
(3,	'IMPULSE_SUM');

DROP TABLE IF EXISTS `measures`;
CREATE TABLE `measures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sensor_id` smallint(6) NOT NULL,
  `data_time` datetime NOT NULL COMMENT 'timestamp of data recording',
  `server_time` datetime NOT NULL COMMENT 'timestamp where data has been received by server',
  `s_value` double NOT NULL COMMENT 'data measured (raw)',
  `session_id` mediumint(9) DEFAULT NULL,
  `remote_ip` varchar(32) COLLATE utf8_czech_ci DEFAULT NULL,
  `out_value` double DEFAULT NULL COMMENT 'processed value',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0 = received, 1 = processed',
  PRIMARY KEY (`id`),
  KEY `device_id_sensor_id_data_time_id` (`sensor_id`,`data_time`,`id`),
  KEY `status_id` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Recorded data - raw. SUMDATA are created from recorded data, and old data are deleted from MEASURES.';


DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rauser_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `sensor_id` int(11) DEFAULT NULL,
  `event_type` tinyint(4) NOT NULL COMMENT '1 sensor max, 2 sensor min, 3 device se nepripojuje, 4 senzor neposila data',
  `event_ts` datetime NOT NULL,
  `status` int(11) NOT NULL DEFAULT '0' COMMENT '0 vygenerováno, 1 odeslán mail',
  `custom_text` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `out_value` double DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;


DROP TABLE IF EXISTS `rausers`;
CREATE TABLE `rausers` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8_bin NOT NULL,
  `phash` varchar(255) COLLATE utf8_bin NOT NULL,
  `role` varchar(100) COLLATE utf8_bin NOT NULL,
  `email` varchar(255) COLLATE utf8_bin NOT NULL,
  `prefix` varchar(20) COLLATE utf8_bin NOT NULL,
  `state_id` tinyint(4) NOT NULL DEFAULT '10',
  `bad_pwds_count` smallint(6) NOT NULL DEFAULT '0',
  `locked_out_until` datetime DEFAULT NULL,
  `measures_retention` int(11) NOT NULL DEFAULT '90' COMMENT 'jak dlouho se drží data v measures',
  `sumdata_retention` int(11) NOT NULL DEFAULT '731' COMMENT 'jak dlouho se drží data v sumdata',
  `blob_retention` int(11) NOT NULL DEFAULT '14' COMMENT 'jak dlouho se drží bloby',
  `self_enroll` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1 = self-enrolled',
  `self_enroll_code` varchar(255) COLLATE utf8_bin DEFAULT NULL,
  `self_enroll_error_count` tinyint(4) DEFAULT '0',
  `cur_login_time` datetime DEFAULT NULL,
  `cur_login_ip` varchar(32) COLLATE utf8_bin DEFAULT NULL,
  `cur_login_browser` varchar(255) COLLATE utf8_bin DEFAULT NULL,
  `prev_login_time` datetime DEFAULT NULL,
  `prev_login_ip` varchar(32) COLLATE utf8_bin DEFAULT NULL,
  `prev_login_browser` varchar(255) COLLATE utf8_bin DEFAULT NULL,
  `last_error_time` datetime DEFAULT NULL,
  `last_error_ip` varchar(32) COLLATE utf8_bin DEFAULT NULL,
  `last_error_browser` varchar(255) COLLATE utf8_bin DEFAULT NULL,
  `monitoring_token` varchar(100) COLLATE utf8_bin DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;


DROP TABLE IF EXISTS `rauser_state`;
CREATE TABLE `rauser_state` (
  `id` tinyint(4) NOT NULL,
  `desc` varchar(100) COLLATE utf8_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;

INSERT INTO `rauser_state` (`id`, `desc`) VALUES
(1,	'čeká na zadání kódu z e-mailu'),
(10,	'aktivní'),
(90,	'zakázán administrátorem'),
(91,	'dočasně uzamčen');

DROP TABLE IF EXISTS `sensors`;
CREATE TABLE `sensors` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `device_id` smallint(6) NOT NULL,
  `channel_id` smallint(6) DEFAULT NULL,
  `name` varchar(100) COLLATE utf8_czech_ci NOT NULL,
  `device_class` tinyint(4) NOT NULL,
  `value_type` tinyint(4) NOT NULL,
  `msg_rate` int(11) NOT NULL COMMENT 'expected delay between messages',
  `desc` varchar(256) COLLATE utf8_czech_ci DEFAULT NULL,
  `display_nodata_interval` int(11) NOT NULL DEFAULT '7200' COMMENT 'how long interval will be detected as "no data"',
  `preprocess_data` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0 = no, 1 = yes',
  `preprocess_factor` double DEFAULT NULL COMMENT 'out = factor * sensor_data',
  `last_data_time` datetime DEFAULT NULL,
  `last_out_value` double DEFAULT NULL,
  `data_session` varchar(20) COLLATE utf8_czech_ci DEFAULT NULL,
  `imp_count` bigint(20) DEFAULT NULL,
  `warn_max` tinyint(4) NOT NULL DEFAULT '0',
  `warn_max_after` int(11) NOT NULL DEFAULT '0' COMMENT 'za jak dlouho se má poslat',
  `warn_max_val` double DEFAULT NULL,
  `warn_max_val_off` double DEFAULT NULL COMMENT 'vypínací hodnota',
  `warn_max_text` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `warn_max_fired` datetime DEFAULT NULL,
  `warn_max_sent` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0 = ne, 1 = posláno',
  `warn_min` tinyint(4) NOT NULL DEFAULT '0',
  `warn_min_after` int(11) NOT NULL DEFAULT '0' COMMENT 'za jak dlouho se má poslat',
  `warn_min_val` double DEFAULT NULL,
  `warn_min_val_off` double DEFAULT NULL COMMENT 'vypínací hodnota',
  `warn_min_text` varchar(255) COLLATE utf8_czech_ci DEFAULT NULL,
  `warn_min_fired` datetime DEFAULT NULL,
  `warn_min_sent` tinyint(4) DEFAULT '0' COMMENT '0 = ne, 1 = posláno',
  `warn_noaction_fired` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `device_id_name` (`device_id`,`name`),
  KEY `device_id_channel_id_name` (`device_id`,`channel_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='List of sensors. Each sensor is a part of one DEVICE.';


DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` mediumint(9) NOT NULL AUTO_INCREMENT,
  `hash` varchar(20) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `device_id` smallint(6) NOT NULL,
  `started` datetime NOT NULL,
  `remote_ip` varchar(32) NOT NULL,
  `session_key` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Sessions on IoT interface.';


DROP TABLE IF EXISTS `sumdata`;
CREATE TABLE `sumdata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sensor_id` smallint(6) NOT NULL,
  `sum_type` tinyint(4) NOT NULL COMMENT '1 = hour, 2 = day',
  `rec_date` date NOT NULL,
  `rec_hour` tinyint(4) NOT NULL COMMENT '-1 if day value',
  `min_val` double DEFAULT NULL,
  `min_time` time DEFAULT NULL,
  `max_val` double DEFAULT NULL,
  `max_time` time DEFAULT NULL,
  `avg_val` double DEFAULT NULL,
  `sum_val` double DEFAULT NULL,
  `ct_val` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Počet započtených hodnot (pro denní sumy)',
  `status` tinyint(4) DEFAULT '0' COMMENT '0 = created hourly stat (= daily stat should be recomputed), 1 = used',
  PRIMARY KEY (`id`),
  KEY `sensor_id_rec_date_sum_type` (`sensor_id`,`rec_date`,`sum_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Day and hour summaries. Computed from MEASURES. Data from MEASURES are getting deleted some day; but SUMDATA are here for stay.';


DROP TABLE IF EXISTS `value_types`;
CREATE TABLE `value_types` (
  `id` int(11) NOT NULL,
  `unit` varchar(100) COLLATE utf8_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Units for any kind of recorder values.';

INSERT INTO `value_types` (`id`, `unit`) VALUES
(1,	'°C'),
(2,	'%'),
(3,	'hPa'),
(4,	'dB'),
(5,	'ppm'),
(6,	'kWh'),
(7,	'#'),
(8,	'V'),
(9,	'sec'),
(10,	'A'),
(11,	'Ah'),
(12,	'W'),
(13,	'Wh'),
(14,	'mA'),
(15,	'mAh');

DROP TABLE IF EXISTS `views`;
CREATE TABLE `views` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8_czech_ci NOT NULL COMMENT 'Chart name - title in view window, name in left menu.',
  `vdesc` varchar(1024) COLLATE utf8_czech_ci NOT NULL COMMENT 'Description',
  `token` varchar(100) COLLATE utf8_czech_ci NOT NULL COMMENT 'Security token. All charts (views) with the same token will be displayed in together (with left menu for switching between)',
  `vorder` smallint(6) NOT NULL COMMENT 'Order - highest on top.',
  `render` varchar(10) COLLATE utf8_czech_ci NOT NULL DEFAULT 'view' COMMENT 'Which renderer to use ("view" is only available now)',
  `allow_compare` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Allow to select another year for compare?',
  `user_id` smallint(6) NOT NULL,
  `app_name` varchar(100) COLLATE utf8_czech_ci NOT NULL DEFAULT 'RatatoskrIoT' COMMENT 'Application name in top menu',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Chart views. Every VIEW (chart) has 0-N series defined in VIEW_DETAILS.';


DROP TABLE IF EXISTS `view_detail`;
CREATE TABLE `view_detail` (
  `id` mediumint(9) NOT NULL AUTO_INCREMENT,
  `view_id` smallint(6) NOT NULL COMMENT 'Reference to VIEWS',
  `vorder` smallint(6) NOT NULL COMMENT 'Order in chart',
  `sensor_ids` varchar(30) COLLATE utf8_czech_ci NOT NULL COMMENT 'List of SENSORS, comma delimited',
  `y_axis` tinyint(4) NOT NULL COMMENT 'Which Y-axis to use? 1 or 2',
  `view_source_id` tinyint(4) NOT NULL COMMENT 'Which kind of data to load (references to VIEW_SOURCE)',
  `color_1` varchar(20) COLLATE utf8_czech_ci NOT NULL DEFAULT '255,0,0' COMMENT 'Color (R,G,B) for primary data',
  `color_2` varchar(20) COLLATE utf8_czech_ci NOT NULL DEFAULT '0,0,255' COMMENT 'Color (R,G,B) for comparison year',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='One serie for chart (VIEW).';


DROP TABLE IF EXISTS `view_source`;
CREATE TABLE `view_source` (
  `id` tinyint(4) NOT NULL,
  `desc` varchar(255) COLLATE utf8_czech_ci NOT NULL,
  `short_desc` varchar(255) COLLATE utf8_czech_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Types of views. Referenced from VIEW_DETAIL.';

INSERT INTO `view_source` (`id`, `desc`, `short_desc`) VALUES
(1,	'Automatická data',	'Automatická data'),
(2,	'Denní maximum',	'Denní maximum'),
(3,	'Denní minimum',	'Denní minimum'),
(4,	'Denní průměr',	'Denní průměr'),
(5,	'Vždy detailní data - na delších pohledech pomalé!',	'Detailní data'),
(6,	'Denní součet',	'Denní suma'),
(7,	'Hodinový součet',	'Hodinová suma'),
(8,	'Hodinové maximum',	'Hodinové maximum'),
(9,	'Hodinové/denní maximum',	'Do 90denních pohledů hodinové maximum, pro delší denní maximum');

-- 2021-01-10 22:16:02
