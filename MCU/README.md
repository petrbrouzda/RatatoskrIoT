# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v43d-espcam**
Aplikace pro [modul ESP32-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou.

Periodicky jednou za N sekund se probudí a pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server. Pak se uspí na zadanou dobu do deep sleep.

## **v43d-espcam_a_DS18B20**
Aplikace pro [modul ESP32-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou, a k němu připojené onewire teploměry DS18B20.

Periodicky jednou za N sekund
- načte teplotu ze všech teploměrů,
- pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server,
- pak se uspí na zadanou dobu do deep sleep.

Data z teploměrů jsou kešována do RTCMEM, takže se ukládají, i když není zrovna připojení k WiFi či spojení na server (a odešlou se, až bude spojení k dispozici).

## **v43z-test-suite<#>**

Testovací sady pro kontrolu funkce knihoven.

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















