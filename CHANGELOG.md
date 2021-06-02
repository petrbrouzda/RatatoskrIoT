# Changelog

# 2021-06-02

Server
- Nový typ datového zdroje pro grafy - **týdenní součet.** Hodí se pro týdenní sumu srážek. Upraví počáteční datum tak, aby bylo v pondělí; 
  upraví délku grafu na celé týdny. 
  https://lovecka.info/ra/chart/view/LoveckaJablonec/72/?lenDays=31&dateFrom=2021-05-03&draw=Uka%C5%BE%21
- Nový typ datového zdroje pro grafy - **hodinový/denní součet.** Pro grafy kratší než týden vrací hodinové součty; pro delší grafy vrací denní součty. Hodí se pro srážky. 
  https://lovecka.info/ra/chart/view/LoveckaJablonec/53/?lenDays=8&dateFrom=2021-05-01&draw=Uka%C5%BE%21
- Nový **JSON endpoint pro data z meteostanic** - vypisuje aktuální teplotu a max/min teplotu a srážky dnes, včera a za tento týden.
  Detaily jsou popsány na stránce zařízení v administraci.
  URL vypadá takto:
  https://lovecka.info/ra/json/meteo/\<TOKEN\>/\<ID_ZAŘÍZENÍ\>/?temp=\<JMENO_TEMP_SENZORU\>&rain=\<JMENO_RAIN_SENZORU\>
- Při přidávání datové řady do grafu jsou nově senzory seřazeny podle **měřené veličiny**, jména zařízení a pak teprve jména senzoru. 
  Takže jsou u sebe všechny teploměry jednoho uživatele, všechny srážkoměry atd.

- **Pokud upgradujete, je třeba provést změnový skript databáze 20210602_diff_5.3_5.3.1.sql**

----
# 2021-05-23

Server
- Oprava zpracování dat od impulzních senzorů


----
# 2021-05-17 

MCU
- Už v době volání callbacku wifiStatus_* má proměnná **wifiOK** správnou hodnotu (dříve měla správnou hodnotu až **po** skončení callbacku).

----
# 2021-05-13 verze 5.3.3

MCU
- Možnost zrestartovat zařízení zasláním konfigurační položky @restart=1
- Při zapnutém log shippingu porušení zásobníku, pokud má zařízení synchronizovaný čas z internetu.

----
# 2021-05-05

Server:
- U logů ra-conn a cron se vypisuje ID procesu pro snadné párování déletrvajících operací.
- Při příjmu záznamů se neaktualizují informace v tabulce sensors, pokud je záznam starší než předešlý.
- Lépe formátovaný výpis logu ze zařízení.

----
# 2021-04-23 verze 5.3.2

**Velká změna - podpora pro log shipping.** Logy ze zařízení mohou být automaticky předávány na server. 
- Defaultně je to vypnuté, protože to potřebuje nějakou paměť na zařízení a přenáší to data navíc.
- POZOR! Kvůli paměťové náročnosti a rychlosti se logy posílají (zatím) **nešifrovaně**. Jsou podepsané (= nemělo by být možno zapsat log za cizí zařízení), ale nejsou šifrované, takže je možné je po cestě odposlechnout.

**Velká změna - podpora pro OTA updaty, zatím jen pro ESP32.** Z administrace lze snadno poslat OTA update. 
- Update si stahuje zařízení (pull), tj. není třeba mít konektivitu na zařízení.
- POZOR! Kvůli paměťové náročnosti a rychlosti se update posílají (zatím) **nešifrovaně**. Jsou podepsané (= nemělo by být možné je po cestě změnit), ale nejsou šifrované, takže je možné je odposlechnout.

MCU:
- Ověření funkce na ESP32 core 1.0.6, nadále bude vyvíjeno na této verzi.
- Podpora pro log shipping. 
  - Podpora se musí se zapnout v **AppFeatures.h** odkomentováním definice LOG_SHIPPING. Pokud není definováno, kód pro log shipping se ani nezahrne do aplikace.
    - Na ESP32 je defaultně povoleno. Pokud je k dispozici PSRAM, buffer pro ukládání logů se vytváří v ní.
    - Na ESP8266 je defaultně zakázáno.
  - Vlastní log shipping se ovládá ze serveru měnitelnou konfigurační položkou **log_ship** s hodnotou 0 nebo 1 - tj. když v AppFeatures.h podporu zapnete, přidá se kód do aplikace, ale teprve konfigurační položka log_ship určuje, zda se logy budou nebo nebudou posílat na server.
- Podpora pro OTA.
  - Podpora se musí se zapnout v **AppFeatures.h** odkomentováním definice OTA_UPDATE. Pokud není definováno, kód pro OTA se ani nezahrne do aplikace.
  - Na ESP32 je defaultně povoleno.
  - **Na ESP8266 není zatím implementováno.**

Server:
- Podpora pro log shipping. 
  - Logy se ukládají do adresáře s logy aplikace, pro každé zařízení je jeden soubor. Jméno souboru je **dev-\<ID\>.\<datum\>.txt**
- Podpora pro OTA.
- Administrace zařízení: 
  - V detailu zařízení se vypisuje čas poslední komunikace.
  - U posílání změn konfigurace do zařízení jsou uvedeny defaultní konfigurační položky.
  - Senzory, které mají nastavená varování při překročení limitů, jsou v seznamu označeny ikonou.
- **Odstranění podpory pro přihlášení zařízení protokolem v4.**
- **Pokud upgradujete, je třeba provést změnový skript databáze 20210423_diff_5.0_5.3.sql**

----
# 2021-04-05

Server:
- Nové typy grafů 
  - sloupcový https://lovecka.info/ra/chart/view/LoveckaJablonec/54/?dateFrom=2021-04-02&lenDays=1&plus=1 
  - čárový https://lovecka.info/ra/chart/view/LoveckaJablonec/50/?dateFrom=2021-04-04&lenDays=3&minus=1
- V grafech je tenkou zelenou čárou vyznačen aktuální čas, pokud graf obsahuje dnešek.

----
# 2021-03-27

Server:
- Oprava vykreslování pravé osy Y, pokud má výrazně menší rozsah než levá

----
# 2021-03-19

Server:
- Formátování popisek osy Y: pro interval tickeru na ose Y 0.25 se zaokrouhlovaly čísla na jedno desetinné místo = 0.3

----
# 2021-03-18

Server:
- Lepší formátování popisek osy Y: 
  - Použití desetinné čárky místo tečky + mezera jako oddělovač tisícovek.
  - Fixní počet desetinných míst podle odstupu popisek.

----
# 2021-03-17

Server:
- Lepší vykreslení grafů: 
  - Lepší nastavení min/max pro malé rozsahy hodnot. 
  - Doplnění intervalu tickerů na ose Y po 0.1/0.25/0.5.
  - Ddstranění přepisu nuly a hodnoty u levé osy Y.
- Lepší popiska u priority u editace datové řady v grafu.

----
## 2021-03-15

Server:
- V seznamu zařízení a v detailu zařízení se nerozlišovala hodnota senzoru NULL a 0 - tedy pro 0 se ukazovalo "-".

----
## 2021-03-14 verze 5.1.3 

MCU:
- Oprava lightSleep - nově nemění stav WiFi, bluetooth ani ADC.

----
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



