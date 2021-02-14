-- zmena z verze 4.3 na 5.0

CREATE TABLE `prelogin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(20) COLLATE utf8_czech_ci NOT NULL,
  `device_id` smallint(6) NOT NULL,
  `started` datetime NOT NULL,
  `remote_ip` varchar(32) COLLATE utf8_czech_ci NOT NULL,
  `session_key` varchar(255) COLLATE utf8_czech_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci COMMENT='Sem se ukládají session po akci LOGINA - pøed tím, než je zaøízení potvrdí via LOGINB';

alter table `devices` add
  `uptime` int(11) DEFAULT NULL;

alter table `devices` add
  `rssi` smallint(6) DEFAULT NULL;
