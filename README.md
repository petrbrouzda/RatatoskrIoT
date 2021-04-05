# RatatoskrIoT: IoT aplikace na ESP32/ESP8266 a serverová aplikace k tomu

## Základní informace

**RatatoskrIoT** je IoT framework pro jednoduchou obsluhu **senzorů** postavených na ESP32/ESP8266, včetně serverové aplikace schopné zpracovávat data z mnoha senzorů a za mnoho let.
- Aplikace pro mikrokontroléry je pro Arduino IDE a klade důraz na rychlost vývoje a odstínění programátora od zajištění konfigurace, správy WiFi a vlastní komunikace se serverem.
- Serverová aplikace je pro PHP 7.2+, data ukládá do MySQL.
- Komunikační protokol je nad http, přenášený obsah je šifrovaný (AES-256-CBC) a k výměně klíčů se používá ECDH.

Detailnější popis "k čemu to je", "k čemu to není" a "jak to použít" najdete zde: https://pebrou.wordpress.com/2021/01/07/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-1-n/

Co je v repository?
- V adresáři [MCU](MCU) jsou ukázkové aplikace pro ESP32 a ESP8266.
- V adresáři [server](server) je serverová aplikace.


---
## Informace o změnách

Detailní informace o změnách najdete  v souboru [CHANGELOG.md](CHANGELOG.md)

### 2021-04-05

Server:
- Nové typy grafů 
  - sloupcový https://lovecka.info/ra/chart/view/LoveckaJablonec/54/?dateFrom=2021-04-02&lenDays=1&plus=1 
  - čárový https://lovecka.info/ra/chart/view/LoveckaJablonec/50/?dateFrom=2021-04-04&lenDays=3&minus=1
- V grafech je tenkou zelenou čárou vyznačen aktuální čas, pokud graf obsahuje dnešek.







