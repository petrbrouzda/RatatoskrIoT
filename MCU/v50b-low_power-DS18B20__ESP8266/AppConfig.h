#include <FS.h>                   //this needs to be first, or it all crashes and burns...

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

#ifndef RA__APPCONFIG__H_
#define RA__APPCONFIG__H_

  /** log mode muze byt RA_LOG_MODE_SERIAL nebo RA_LOG_MODE_NOLOG; ta druha je vhodna pro power-save rezim */
  #define LOG_MODE RA_LOG_MODE_SERIAL

  /** Ma si spravovat wifi sam? Pokud je true, wifi se zapne jen kdyz ma RA neco k odeslani na server. */
  #define MANAGE_WIFI false

  /** Ma spustit wifi pri startu? Pokud je true, hned pri startu se spusti wifi. Ignoruje se pri MANAGE_WIFI == true. */
  #define RUN_WIFI_AT_STARTUP true

  /** heslo pro wifi konfiguracni AP; 8 znaku ci vice! */
  #define CONFIG_AP_PASSWORD "aaaaaaaa"

  /** Spousteni konfiguracniho portalu tlacitkem - parametry pro wifiStatus_ShouldStartConfig().
   * 0 = tlacitko FLASH (vpravo od konektoru, pri pohledu od kabelu) 
   * Pokud se pouziva tlacitko FLASH, je treba v wifiStatus_ShouldStartConfig() povolit delay(), 
   * aby uzivatel mohl stisknout tlacitko az po bootu! */
  #define CONFIG_BUTTON 0
  #define CONFIG_BUTTON_START_CONFIG LOW

  /** Vypsat pri startu informace: 
   *  - program a timestamp jeho sestaveni
   *  - reset reason 
   *  - informace o hardwaru (jen ESP32)
   * Informace se vypisuji jen pri startu, co neni probuzenim z deep sleep (ESP32). */
  #define STARTUP_INFO true

  /** Maximalni pocet tasku v Taskeru; RA pro sebe potrebuje 3 */
  #define TASKER_MAX_TASKS 10


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

  /**
   * Jmeno konfiguracniho souboru ve filesystemu je v ConfigProvider.h
   */

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
   * Ma se pouzivat stavova dioda pro signalizaci stavu aplikace?
   * Defaultne rozlisuje:
   * 1) spusten konfiguracni portal - rychle blikani
   * 2) aplikace bezi, WiFi vypnute - blik jednou za 2 sec - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER nebo BLINKER_LOW_POWER
   * 3) aplikace bezi, WiFi zapnute - dvoublik jednou za 2 sec  - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER nebo BLINKER_LOW_POWER
   * 4) cekame na pripojeni na WiFi - blik 2x za sekundu  - vypnuto pokud je definovano BLINKER_ULTRA_LOW_POWER 
   */
  #define USE_BLINKER
  /** (pokud je USE_BLINKER) pin, na kterem je stavova LED (muzete zadat LED_BUILTIN, pokud to na vasem zarizeni funguje) */
  #define BLINKER_PIN 2
  /** (pokud je USE_BLINKER) kdy je LED vypnuta? (ESP32 - nejcasteji LOW, ESP8266 - nejcasteji HIGH ) */
  #define BLINKER_LED_OFF HIGH
  /** (pokud je USE_BLINKER) je-li definovano, blika se jen pro konfiguracni portal a pro situaci, kdy je zaple wifi ale neni pripojene k AP */
  #define BLINKER_LOW_POWER
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

#endif

// https://github.com/tzapu/WiFiManager
// patched version included in src/ folder
#include "src/wifiman/WiFiManager.h"

// ne-interrupt reseni planovani uloh
// https://github.com/joysfera/arduino-tasker
// import "Tasker" 2.0.0 in lib manager !!!
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
