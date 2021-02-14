# RatatoskrIoT: Serverová aplikace pro IoT

Informace, motivace a použití jsou popsány zde: https://pebrou.wordpress.com/2021/01/07/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-1-n/

Návod k instalaci serveru najdete zde: https://pebrou.wordpress.com/2021/01/18/kostra-hotove-iot-aplikace-pro-esp32-esp8266-a-k-tomu-nejaky-server-4-4/

Pokud chcete replikovat data do jiného systému: https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/ 

V adresáři [/MCU](/MCU) jsou ukázkové aplikace pro ESP32 a ESP8266.

V souboru [/CHANGELOG.md](/CHANGELOG.md) najdete informace o změnách.


---


# Knihovny a kód třetích stran

Aplikace obsahuje následující kód třetích stran ve formě zdrojových kódů distribuovaných přímo s aplikací (resp. stahovaných přes Composer).

### Nette Framework
- zdroj: https://nette.org/cs/
- licence: New BSD License 
- použito bez úprav

### mdanter/ecc
- zdroj: https://github.com/phpecc/phpecc
- licence: MIT
- použito bez úprav

### fgrosse/PHPASN1
(závislost pro mdanter/ecc)
- zdroj: https://github.com/fgrosse/PHPASN1
- licence: MIT
- použito bez úprav
