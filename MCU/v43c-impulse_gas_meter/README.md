# RatatoskrIoT: aplikace pro mikrokontroléry ESP8266 a ESP32

## **v43c-impulse_gas_meter**

Základní ukázka aplikace načítající **impulzní vstup** (tedy ne kontinuální, spojitou veličinu, ale počet impulzů). Procesor běží nepřetržitě (neuspává se).

Aplikace nemá žádnou specifickou konfiguraci zaslatelnou ze serveru.

**Konfigurační portál** pro nastavení WiFi a přístupu na server se spustí automaticky, pokud zařízení nemá konfiguraci, nebo je možné jej spustit tlačítkem FLASH na NodeMCU (pin D0) - stisknout a držet tlačítko pod obu 3+ sekund poté, co se při startu rychle rozbliká indikační LED. 

Na rozdíl od ostatních demo aplikací obsahuje **úpravu konfiguračního portálu,** aby se v něm zadával i přepočtový faktor (impulz -> kWh).
Najdete to v WifiConnection.ino a v ConfigProvider.ino uvedené komentářem
> //!!!


---


# Knihovny a kód třetích stran

## Nutné knihovny v Arduino IDE
V library manageru je nutné mít nainstalováno:
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

