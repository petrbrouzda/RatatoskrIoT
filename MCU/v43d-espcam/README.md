# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v43d-espcam**
Aplikace pro [modul ESP-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou.

Periodicky jednou za N sekund se probudí a pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server. Pak se uspí na zadanou dobu do deep sleep.

Pokud není spojení, neukládá fotky na SD kartu.

Aplikace má jednu vzdáleně konfigurovatelnou hodnotu - dobu, na jakou má jít do deep sleepu mezi jednotlivými běhy:
- Jméno proměnné: sleep_sec; jednotka: sekundy

Tedy
>  sleep_sec=180

nastaví interval na 3 minuty.


Pokud je pin **14** spojen na GND, je spuštěn konfigurační portál; pokud není zapojen/high, běží aplikace normálně.

Modul ESP-CAM nemá USB port. Zde je schéma, jak propojit s USB-serial adaptérem pro naprogramování: https://randomnerdtutorials.com/esp32-cam-post-image-photo-server/

---

# Knihovny a kód třetích stran

## Nutné knihovny v Arduino IDE
Pro všechny aplikace je nutné mít v library manageru nainstalováno:
- Tasker 2.0.0

## Knihovny a kód třetích stran 

### Tiny AES
- src\aes-sha\aes*
- zdroj: https://github.com/kokke/tiny-AES-c
- licence: public domain

### CRC32
- src\aes-sha\CRC32*
- zdroj: https://github.com/bakercp/CRC32
- licence: MIT

### SHA-256
- src\aes-sha\sha256*
- zdroj: https://github.com/CSSHL/ESP8266-Arduino-cryptolibs
- licence: public domain (dle https://github.com/B-Con/crypto-algorithms/blob/master/sha256.c)

### dtostrg
- src\math\
- zdroj: https://github.com/tmrttmrt/dtostrg
- licence: MIT

### tzapu/WiFiManager
- src\wifiman\
- zdroj: https://github.com/tzapu/WiFiManager
- licence: MIT















