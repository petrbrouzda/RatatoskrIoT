# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v43b-low_power-DS18B20__ESP32**

Základní ukázka low-power aplikace na ESP32. Každých N sekund se probudí, načte teplotu z teploměrů DS18B20 a odešle ji na server.
Pokud není spojení na server, ukládá data do keše v RTCMEM a odešle je, až spojení bude.

Zapojení:
- Na pinu 4 je připojen teploměr (či více teploměrů) DS18B20
- Bliká indikační LED na pinu 19 (mění se v AppConfig, BLINKER_PIN)
    - 5x za sec = spuštěn konfigurační portál, připojte se na WiFi AP RA_xxxxxx s heslem 'aaaaaaaa' a na 10.0.1.1 najdete konfigurační portál
    - 2x za sec - hledá wifi
- Seriovy port jede na 115200 bps.

Aplikace má jednu vzdáleně konfigurovatelnou hodnotu - dobu, na jakou má jít do deep sleepu mezi jednotlivými běhy:
- Jméno proměnné: **sleep_sec**; jednotka: sekundy; default 60

Tedy
>  sleep_sec=180

nastaví interval na 3 minuty.

**Konfigurační portál** pro nastavení WiFi a přístupu na server se spustí automaticky, pokud zařízení nemá konfiguraci, nebo je možné jej spustit tím, že při startu je pin 14 připojen na LOW (pin se dá nastavit v AppConfig, CONFIG_BUTTON)

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

