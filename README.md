# RatatoskrIoT: IoT aplikace na ESP32/ESP8266 a serverová aplikace k tomu

## Základní informace

**RatatoskrIoT** je IoT framework pro jednoduchou obsluhu **senzorů** postavených na ESP32/ESP8266, včetně serverové aplikace schopné zpracovávat data z mnoha senzorů a za mnoho let.
- Aplikace pro mikrokontroléry je pro Arduino IDE a klade důraz na rychlost vývoje a odstínění programátora od zajištění konfigurace, správy WiFi a vlastní komunikace se serverem.
- Serverová aplikace je pro PHP 7.2+, data ukládá do MySQL.
- Komunikační protokol je nad http, přenášený obsah je šifrovaný (AES-256-CBC) a k výměně klíčů se používá ECDH.

Detailnější popis: 
- [Úvod - k čemu to je, k čemu to není](https://pebrou.wordpress.com/2021/01/07/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-1-n/)
- [Bezpečnost - jak je to udělané](https://pebrou.wordpress.com/2021/01/14/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-2-n/)
- [Aplikace na mikrokontroléru](https://pebrou.wordpress.com/2021/01/15/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-3-n/)
- [Aplikace na serveru - popis, instalace](https://pebrou.wordpress.com/2021/01/18/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-4-4/)
- [Replikace dat do jiného systému](https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/)

Co je v repository?
- V adresáři [MCU](MCU) jsou ukázkové aplikace pro ESP32 a ESP8266.
- V adresáři [server](server) je serverová aplikace.


---
## Informace o změnách

Detailní informace o změnách najdete  v souboru [CHANGELOG.md](CHANGELOG.md)

### **2021-06-02**

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


### **2021-04-23 verze 5.3.2**

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








