# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v43b-low_power-DS18B20__ESP8266**

Základní ukázka low-power aplikace na ESP8266. Každých N sekund se probudí, načte teplotu z teploměrů DS18B20 a odešle ji na server.

Zapojení:
- **D0 (GPIO16) musí být spojena s RST.** Nicméně pro programování je třeba toto spojení **rozpojit.** (!!!)
- Na D4 je připojen teploměr (či více teploměrů) DS18B20
- Bliká indikační LED na pinu 2 (mění se v AppConfig, BLINKER_PIN)
    - 5x za sec = spuštěn konfigurační portál, připojte se na WiFi AP RA_xxxxxx s heslem 'aaaaaaaa' a na 10.0.1.1 najdete konfigurační portál
    - 2x za sec - hledá wifi
- Seriovy port jede na 115200 bps.

Aplikace má jednu vzdáleně konfigurovatelnou hodnotu - dobu, na jakou má jít do deep sleepu mezi jednotlivými běhy:
- Jméno proměnné: **sleep_sec**; jednotka: sekundy; default 60

Tedy
>  sleep_sec=180

nastaví interval na 3 minuty.

**Konfigurační portál** pro nastavení WiFi a přístupu na server se spustí automaticky, pokud zařízení nemá konfiguraci, nebo je možné jej spustit tlačítkem FLASH na NodeMCU (pin D0) - stisknout a držet tlačítko pod obu 3+ sekund poté, co se při startu rychle rozbliká indikační LED. 

Poznámka: Protože se používá tlačítko FLASH, není možné ho držet při startu (přepnulo by modul do programovacího režimu). Proto je vložena třísekundová pauza, během které uživatel může tlačítko zmáčknout. To je pro reálnou low-power aplikaci samozřejmě blbost (prodlužuje délku jednoho cyklu zapnutí z 6 na 9 sekund). Takže přehoďte spouštění konfiguračního portálu na jiný pin (AppConfig, CONFIG_BUTTON) a z wifiStatus_ShouldStartConfig() vyhoďte výpis na serial a čekání.



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

