# RatatoskrIoT: IoT aplikace na ESP32/ESP8266 a serverová aplikace k tomu

## Základní informace
Informace, motivace a použití jsou popsány zde: https://pebrou.wordpress.com/2021/01/07/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-1-n/

V adresáři [MCU](MCU) jsou ukázkové aplikace pro ESP32 a ESP8266.

V adresáři [server](server) je serverová aplikace.

## Verze 5.0 (2021-02-12)

**Velká změna:** Na pobídnutí @danmaneu změněn způsob přihlašování tak, že používá ECDH a je výrazně odolnější proti všem formám útoku.
Aktuální verze aplikace podporuje jak přihlášení původní metodou (RA 4.3) tak aktuální (5.0); v další verzi aplikace bude podpora pro původní přihlašovací kanál zrušena.

**Při aktualizaci** z 4.* na 5.0 je potřeba provést změnu databáze - změnový skript server/sql/20210212_diff_4.3_5.0.sql .

Detailnější informaci ke změnám najdete v souboru [CHANGELOG.md](CHANGELOG.md)





