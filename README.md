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

### **2022-09-17**
MCU 5.4.1
- Podpora pro ESP32 arduino core 2.0.5
- Podpora pro ESP32-C3  

### **2021-11-16**
- Podpora pro exportní pluginy pro obrázky. Hotový exportní plugin pro dělání timelapse z kamer. Viz informace v config/local.neon.sample. 
- Na stránce "Statistika" u senzoru se pro impulzní senzory vypisují měsíční a roční sumy, pro senzory spojitých veličin se vypisuje měsíční min/avg/max.
![Statistika pro impulzní senzor - měsíční sumy](/doc/sensor-stat-1.png "Statistika pro impulzní senzor - měsíční sumy")
![Statistika pro senzor spojité veličiny - měsíční min/avg/max](/doc/sensor-stat-2.png "Statistika pro senzor spojité veličiny - měsíční min/avg/max")











