#ifndef RA__APPCONFIG__H_
#define RA__APPCONFIG__H_

// ================================= ID a verze aplikace

  /**
   * ID a verze aplikace. 
   * Smi obsahovat jen "a-z", "A-Z", "0-9" a "-_."
   * Pouziva se pro OTA updaty.
   * Pokud OTA neplanujete pouit, muzete nechat APP_1
   */
  #define APP_VERSION_ID "DEMO_50d_1" 

// ================================= zakladni rezim logovani

  /** 
   * Log mode muze byt RA_LOG_MODE_SERIAL nebo RA_LOG_MODE_NOLOG; ta druha je vhodna pro power-save rezim.
   * I pokud je RA_LOG_MODE_NOLOG, tak je mozne provozovat log shipping (tj. aplikace nic nevypisuje na 
   * seriovy port, ale posila logy na server.).
   * */
  #define LOG_MODE RA_LOG_MODE_SERIAL

  /**
   * POZOR! Pokud pouzivate desku ESP32-C3 bez USB-to-serial cipu, a chcete logovat
   * pres nativni implemehtaci serioveho portu v USB (USB-CDC), zmente v AppFeatures.h definici LOG_SERIAL_PORT
   */ 


// ================================= konfigurace WiFi   

  /** Ma si spravovat wifi sam? Pokud je true, wifi se zapne jen kdyz ma RA neco k odeslani na server. */
  #define MANAGE_WIFI false

  /** Ma spustit wifi pri startu? Pokud je true, hned pri startu se spusti wifi. Ignoruje se pri MANAGE_WIFI == true. */
  #define RUN_WIFI_AT_STARTUP true


// ================================= konfiguracni portal

  /** Spousteni konfiguracniho portalu tlacitkem - parametry pro wifiStatus_ShouldStartConfig().
   * 0 = tlacitko FLASH (vpravo od konektoru, pri pohledu od kabelu) 
   * Pokud se pouziva tlacitko FLASH, je treba v wifiStatus_ShouldStartConfig() povolit delay(), 
   * aby uzivatel mohl stisknout tlacitko az po bootu! */
  #define CONFIG_BUTTON 14
  #define CONFIG_BUTTON_START_CONFIG LOW

  /** heslo pro wifi konfiguracni AP; 8 znaku ci vice! */
  #define CONFIG_AP_PASSWORD "aaaaaaaa"



// ================================= obecna konfigurace

  /** Vypsat pri startu informace: 
   *  - program a timestamp jeho sestaveni
   *  - reset reason 
   *  - informace o hardwaru (jen ESP32)
   * Informace se vypisuji jen pri startu, co neni probuzenim z deep sleep (ESP32). */
  #define STARTUP_INFO true

  /** Maximalni pocet tasku v Taskeru; RA pro sebe potrebuje 2 */
  #define TASKER_MAX_TASKS 10

  /**
   * Heslo k sifrovani konfiguracniho souboru. 
   * Pokud na jednom zarizeni stridate vice aplikaci nad stejnym konfiguracnim souborem, mely by mit stejne heslo.
   * 
   * ESP8266: POZOR! Pokud na zmenite na zarizeni heslo (a jiz bude existovat sifrovany soubor), pri pokusu 
   * o desifrovani souboru dojde pravdepodobne k padu aplikace a boot-loopu!
   * Spravne reseni je pri zmene hesla smazat obsah flash filesystemu nebo zmenit v ConfigProvider.h jmeno
   * konfiguracniho souboru!
   */
  #define RA_CONFIG_PASSPHRASE "Super Tajne Heslo" 

  /** 
   *  Pokud je definovano, vypise pri startu konfiguraci (bez hesel).
   *  Nevypisuje konfiguraci pri probuzeni z deep sleep.
   */  
  #define DUMP_CONFIG

  /**
   * !!! Jmeno konfiguracniho souboru ve filesystemu je v ConfigProvider.h
   */


// ================================= uloziste zprav odesilanych na server

  /*
   * Volba, zda je uloziste zprav v bezne RAM (vymaze se pri sleepu)
   * nebo v RTC RAM (prezije deep sleep) - jen pro ESP32. 
   * Pokud zvolite RTC RAM, pak ve sve INO aplikaci musite definovat promennou
   *      RTC_DATA_ATTR unsigned char ra_storage[RA_STORAGE_SIZE];
   * Zadisablujte nepotrebne, najednou smi byt povoleno jen jedno
   */ 
  /** uloziste: pouziti bezne RAM: */
  #define RA_STORAGE_RAM 
  /** uloziste: pouziti RTC RAM (jen ESP32!): */
  // #define RA_STORAGE_RTC_RAM

  /** velikost uloziste; pokud pouzivate RTC RAM, musi se to do nej vejit! */
  #define RA_STORAGE_SIZE 2000


// ================================= log shipping

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * Zde se nastavuje velikost bufferu pro drzeni logu pred poslanim na server.
   * Pokud existuje PSRAM, buffer se alokuje v ni.
   *
   * Pro ESP32 doporucuji 20-50 kB. Pokud mate PSRAM, muzete dat i vice.
   * Pro ESP8266 zmensete treba na 5 kB!    */
  #define LOG_BUFFER_SIZE 10000

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * Jak casto se shippuje log. Sekundy. 
   * Muze se poslat i casteji, pokud je buffer hodne zaplnen (> 50 %); cislo zde dava maximalni odstup odesilani.
   * 
   * 0 = nepretrzite, pokud je signal (doporuceno pro aplikace, ktere probehnou, odeslou a uspi zarizeni).
   * 
   * POZOR! Log shipping si samo nezapina wifi. Tj. pokud je povolen log shipping, je potreba cas od casu pustit wifi, aby se to odeslalo.    */
  #define LOG_SHIPPING_MAX_INTERVAL 0

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * - Pokud je 0, je log shipping vypnuty, pokud se explicitne nezapne konfiguracni promennou. Doporuceny rezim.
   * - Pokud je 1, je defaultne zapnuty (pokud neni vypnuty konfiguracni promennou).    */
  #define LOG_SHIPPING_DEFAULT 0

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * Pokud dojde k chybe pri posilani logu na server, za jak dlouho to nejdrive aplikace smi zkusit znovu. (sec)    */
  #define LOG_SHIPPING_ERROR_PAUSE 15

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * Maximalni delka log shipingu v ramci jednoho volani raLoop(), sekundy    */ 
  #define LOG_SHIPING_TIME_LIMIT 15

  /** (Pokud je v AppFeatures.h povoleno LOG_SHIPPING)
   * Kolikrat se maximalne pokusi odeslat kazdou cast logu    */ 
  #define LOG_SHIPING_MAX_TRIES 2




// ================================= blikani stavovou diodou pro signalizaci stavu aplikace?

  /** 
   * Ma se pouzivat stavova dioda pro signalizaci stavu aplikace?
   * Defaultne rozlisuje:
   * 1) spusten konfiguracni portal - rychle blikani
   * 2) aplikace bezi, WiFi vypnute - blik jednou za 2 sec - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER nebo BLINKER_LOW_POWER
   * 3) aplikace bezi, WiFi zapnute - dvoublik jednou za 2 sec  - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER nebo BLINKER_LOW_POWER
   * 4) cekame na pripojeni na WiFi - blik 2x za sekundu  - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER 
   */
  #define USE_BLINKER
  /** (pokud je USE_BLINKER) pin, na kterem je stavova LED (muzete zadat LED_BUILTIN, pokud to na vasem zarizeni funguje) */
  #define BLINKER_PIN 33
  /** (pokud je USE_BLINKER) kdy je LED vypnuta? (ESP32 - nejcasteji LOW, ESP8266 - nejcasteji HIGH ) */
  #define BLINKER_LED_OFF HIGH
  /** (pokud je USE_BLINKER) je-li definovano, blika se jen pro konfiguracni portal a pro situaci, kdy je zaple wifi ale neni pripojene k AP */
  // #define BLINKER_LOW_POWER
  /** (pokud je USE_BLINKER) je-li definovano, blika se jen pro konfiguracni portal */
  // #define BLINKER_ULTRA_LOW_POWER

  /** definice blikani pro jednotlive rezimy blinkeru (popis cisel je v Blinker.h !) */
  #define BLINKER_MODE_PORTAL { 15, 200, 0 }
  #if defined(BLINKER_ULTRA_LOW_POWER)
    #define BLINKER_MODE_SEARCHING { -1 }
    #define BLINKER_MODE_RUNNING { -1 }
    #define BLINKER_MODE_RUNNING_WIFI { -1 }
  #elif defined(BLINKER_LOW_POWER)
    #define BLINKER_MODE_SEARCHING { 15, 500, 0 }
    #define BLINKER_MODE_RUNNING { -1 }
    #define BLINKER_MODE_RUNNING_WIFI { -1 }
  #else
    #define BLINKER_MODE_SEARCHING { 15, 500, 0 }
    #define BLINKER_MODE_RUNNING { 15, 2000, 0 }
    #define BLINKER_MODE_RUNNING_WIFI { 15, 200, 15, 2000, 0 }
  #endif

// ================================= Zde konci konfigurace. Dale lepe nic nemenit. ==========================================
#endif


#include <FS.h>                   //this needs to be first, or it all crashes and burns...
#include "AppFeatures.h"

#if defined(ESP8266)
  // ESP8266 libs
  #include <ESP8266WiFi.h>          //https://github.com/esp8266/Arduino
  #include <DNSServer.h>
  #include <ESP8266WebServer.h>
#elif defined(ESP32)
  //ESP32
  #include <WiFi.h>
  #include <HTTPClient.h>

  // tohle je potreba, abych v deep sleep vypnul GPIO pro ledku na ESP-cam
  // #include "esp_sleep.h"
#endif

// https://github.com/tzapu/WiFiManager
// patched version included in src/ folder
#include "src/wifiman/WiFiManager.h"

// ne-interrupt reseni planovani uloh
// https://github.com/joysfera/arduino-tasker
// import "Tasker" 2.0.3 in lib manager !!!
#include <Tasker.h>

//Ticker Library - pro Blinker
#include <Ticker.h>  

#include "ra.h"
#include "ConfigProvider.h"
#include "DeviceInfo.h"
#include "WifiConnection.h"
#include "Blinker.h"

// included in src/ folder
#include "src/ra/ratatoskr.h"
#include "src/platform/platform.h"
