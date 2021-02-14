# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v50d-espcam_a_DS18B20**
Aplikace pro [modul ESP32-CAM](https://www.banggood.com/ESP32-CAM-WiFi-+-bluetooth-Camera-Module-Development-Board-ESP32-With-Camera-Module-OV2640-IPEX-2_4G-SMA-Anten-p-1549751.html?p=FY1402881924201411VQ&zf=881924) - tj. ESP32 s PSRAM a 2 Mpix kamerou, a k němu připojené onewire teploměry DS18B20.

Periodicky jednou za N sekund
- načte teplotu ze všech teploměrů,
- pokud se podaří připojení na WiFi, sejme fotku a odešle ji na server,
- pak se uspí na zadanou dobu do deep sleep.

Data z teploměrů jsou kešována do RTCMEM, takže se ukládají, i když není zrovna připojení k WiFi či spojení na server (a odešlou se, až bude spojení k dispozici). V RTCMEM je 3000 byte místa, což je asi 150 naměřených hodnot. Jednou za hodinu se naměřené hodnoty ukládají s vyšší prioritou, takže když dojde místo v úložišti, jsou smazány méně prioritní záznamy (např. po pěti minutách) a hodinové záznamy zůstanou.

Pokud není spojení, neukládá fotky na SD kartu.

Aplikace má jednu vzdáleně konfigurovatelnou hodnotu - dobu, na jakou má jít do deep sleepu mezi jednotlivými běhy:
- Jméno proměnné: **sleep_sec**; jednotka: sekundy

Tedy
>  sleep_sec=180

nastaví interval na 3 minuty.


Pokud je pin **14** spojen na GND, je spuštěn konfigurační portál; pokud není zapojen/high, běží aplikace normálně.

Modul ESP32-CAM nemá USB port. Zde je schéma, jak propojit s USB-serial adaptérem pro naprogramování: https://randomnerdtutorials.com/esp32-cam-post-image-photo-server/


---

# Knihovny a kód třetích stran

## Nutné knihovny v Arduino IDE
V library manageru je nutné mít nainstalováno:
- Tasker 2.0.0
- **OneWire 2.3.5**
- **DallasTemperature  3.8.0**

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

