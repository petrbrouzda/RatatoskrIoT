-- Adminer 4.7.6 MySQL dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

INSERT INTO `rausers` (`id`, `username`, `phash`, `role`, `email`, `prefix`, `state_id`, `bad_pwds_count`, `locked_out_until`, `measures_retention`, `sumdata_retention`, `blob_retention`, `self_enroll`, `self_enroll_code`, `self_enroll_error_count`, `cur_login_time`, `cur_login_ip`, `cur_login_browser`, `prev_login_time`, `prev_login_ip`, `prev_login_browser`, `last_error_time`, `last_error_ip`, `last_error_browser`, `monitoring_token`) VALUES
(1,	'admin',	'$2y$11$5bCGPCSvLeJ/NudG64ceKuHjQ4CjomrUUyXh2Qw3iCWJxTVRnWeTy',	'admin,user',	'vas.email@gmail.com',	'AA',	10,	0,	NULL,	90,	731,	14,	0,	NULL,	0,	'2021-01-12 13:33:58',	'-',	'-',	'2021-01-12 13:31:19',	'-',	'-',	NULL,	NULL,	NULL,	NULL)
;

