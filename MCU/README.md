# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

Informace, motivace a použití jsou popsány zde: https://pebrou.wordpress.com/2021/01/07/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-1-n/

Serverová aplikace je v adresáři [/server/php-app](/server/php-app) .

V souboru [/CHANGELOG.md](/CHANGELOG.md) najdete informace o změnách.

**Všechny aplikace "v50-*" vyžadují server verze 5.x (2021-02-12 a novější)!** 

Zdrojáky jsou určené pro Arduino IDE.
* ESP32 bylo testováno s ESP32 core verze 1.0.4; prvotní testy s 1.0.5 vypadají také OK
* ESP8266 bylo testováno s ESP8266 core 2.7.1


## **v50a-demo-power_always_on**

Nejjednodušší kostra aplikace, kde **procesor běží nepřetržitě** (neuspává se). Aplikace periodicky měří hodnotu (zde napětí na analogovém vstupu) a odesílá ji na server.


## **v50b-low_power-DS18B20__ESP32**

Základní ukázka **low-power aplikace** na ESP32. Každých N sekund se probudí, načte teplotu z teploměrů DS18B20 a odešle ji na server.

Pokud není spojení na server, ukládá data do keše v RTCMEM a odešle je, až spojení bude.


## **v50b-low_power-DS18B20__ESP8266**

Základní ukázka **low-power aplikace** na ESP8266. Každých N sekund se probudí, načte teplotu z teploměrů DS18B20 a odešle ji na server.

Pokud není spojení na server, data se ztratí, protože procesor se po zadaném čase uspí a ESP8266 nemá dost RTCMEM, aby se tam daly kešovat data k odeslání.


## **v50c-impulse_gas_meter**

Základní ukázka aplikace načítající **impulzní vstup** (tedy ne kontinuální, spojitou veličinu, ale počet impulzů). Procesor běží nepřetržitě (neuspává se).

Je zde předvedena i **úprava konfiguračního portálu,** aby se v něm dala zadat další hodnota.



## **v50d-espcam**
Aplikace pro [modul ESP32-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou.

Periodicky jednou za N sekund se probudí a pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server. Pak se uspí na zadanou dobu do deep sleep.

## **v50d-espcam_a_DS18B20**
Aplikace pro [modul ESP32-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou, a k němu připojené onewire teploměry DS18B20.

Periodicky jednou za N sekund
- načte teplotu ze všech teploměrů,
- pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server,
- pak se uspí na zadanou dobu do deep sleep.

Data z teploměrů jsou kešována do RTCMEM, takže se ukládají, i když není zrovna připojení k WiFi či spojení na server (a odešlou se, až bude spojení k dispozici).

## **v50z-test-suite<#>**

Testovací sady pro kontrolu funkce knihoven.

---


# Knihovny a kód třetích stran

## Nutné knihovny v Arduino IDE
Pro všechny aplikace je nutné mít v library manageru nainstalováno:
- Tasker 2.0.0

## Knihovny a kód třetích stran 

Aplikace obsahují následující kód třetích stran ve formě zdrojových kódů distribuovaných přímo s aplikací (= nepoužívají se z library manageru):

### Tiny AES
- src\aes-sha\aes*
- zdroj: https://github.com/kokke/tiny-AES-c
- licence: public domain
- použito bez úprav

### CRC32
- src\aes-sha\CRC32*
- zdroj: https://github.com/bakercp/CRC32
- licence: MIT
- použito bez úprav

### SHA-256
- src\aes-sha\sha256*
- zdroj: https://github.com/CSSHL/ESP8266-Arduino-cryptolibs
- licence: public domain (dle https://github.com/B-Con/crypto-algorithms/blob/master/sha256.c)
- použito bez úprav

### dtostrg
- src\math\
- zdroj: https://github.com/tmrttmrt/dtostrg
- licence: MIT
- použito bez úprav

### tzapu/WiFiManager
- src\wifiman\
- zdroj: https://github.com/tzapu/WiFiManager
- licence: MIT
- provedeny úpravy (např. možnost načtení SSID a hesla)

### kmackay/micro-ecc
- src\micro_ecc\
- zdroj: https://github.com/kmackay/micro-ecc
- licence: BSD-2-Clause License
- přejmenovány .inc -> .h, jinak žádné úpravy















