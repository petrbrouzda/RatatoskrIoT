# Changelog

## 2021-03-10

Server:
- Vylepšení automatického překreslování galerie fotek:
  - Nepřekresluje se, pokud není okno prohlížeče viditelné (úspora dat a requestů)
  - Naopak překreslí se ihned po zobrazení prohlížeče, pokud byla stránka neviditelná po dlouhou dobu (zajištění aktuálnosti)
  - Nepřekresluje se, pokud uživatel v posledních 15 sec hýbal myší nebo posouval stránkou (aby se nepřekreslovalo, když zrovna něco hledáte)
- Na stránky grafů přidáno automatické překreslování po 20 minutách; pro překreslování platí stejná pravidla jako pro galerii výše.

----
## 2021-03-05

MCU: 
- Vylepšená práce s kamerou u ESP32-CAM:
  - Pokud dojde k chybě při inicializaci, na chvíli vypne kameře napájení a zkusí to znovu. Výrazně snižuje šanci, že se inicializace kamery nepodaří.
  - Než sejme fotku, udělá 19 testovacích - tím naučí AE control, které lépe nastaví nasvícení fotky.
  - Možnost nastavení některých parametrů fotky (otočení, gain, awb) z konfigurace.

Server:
- Úprava cron tasku, aby nepadal na JPEGu s nevalidním formátem

----
## 2021-02-14 verze 5.1.2
MCU:
- Ostranění pádu při posílání příliš mnoha záznamů najednou
- Oprava nastavování priority zpráv u v50d-espcam_a_DS18B20 a v50b-low_power-DS18B20__ESP32

----
## 2021-02-12 verze 5.1.1
Server i MCU:
- **Velká změna:** Na pobídnutí @danmaneu změněn způsob přihlašování tak, že používá **ECDH** a je výrazně odolnější proti všem formám útoku.
  - Aktuální verze aplikace podporuje jak přihlášení původní metodou (RA 4.3) tak aktuální (5.x); v další verzi aplikace bude podpora pro původní přihlašovací kanál zrušena.

Server:
- Na stránce s detailem zařízení se nově zobrazuje **síla signálu WiFi,** kterou zařízení vidělo v době přihlašování.
- Na stránce s detailem zařízení se nově zobrazuje **uptime zařízení** při poslední komunikaci.
- Výpis konfigurace, kterou je potřeba zapsat do zařízení, je posunut na samostatnou stránku, aby na běžně používané stránce s detailem zařízení nesvítilo heslo zařízení. Přístup na tuto stránku je zapisován do auditního logu.

MCU:
- Na server se odesílá síla signálu a uptime při loginu.

**Při aktualizaci** z 4.* na 5.x je potřeba provést změnu databáze - změnový skript [server/sql/20210212_diff_4.3_5.0.sql](server/sql/20210212_diff_4.3_5.0.sql)


----
## 2021-01-25 verze 4.3.5
MCU:
- kompilační chyba při zapnutí DETAILED_RA_LOG v raConnection.cpp
- oprava pádu funkce ra->lightSleep() v případě, že není zaplá wifi


----
## 2021-01-19 verze 4.3.4
Server:
- Podpora pro průběžný export záznamů do jiných systémů pomocí exportního pluginu.
- Galerie:
  - Pokud není blob soubor na filesystému, nevracet http 500 ale čitelnou chybovou hlášku.
  - Refresh rate u galerie zvětšena na 10 minut.
- Předávání parametrů do Presenterů přes objekt Config, zjednodušení sekce "services" v config.neon



