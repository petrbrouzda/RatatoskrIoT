#ifndef APP_FEATURES__H
#define APP_FEATURES__H

  #if defined(ESP32)

      /** 
      * Zapina podporu pro automaticke odesilani logu na server.
      */ 
      #define LOG_SHIPPING

      /**
       * Zapina podporu pro OTA updaty
       */
      #define OTA_UPDATE 

  #elif defined(ESP8266)

      /** 
      * Zapina podporu pro automaticke odesilani logu na server.
      * Pro ESP8266 defaultne vypnuto. Zapnout samozrejme lze, ale v AppConfig zmensete velikost LOG_BUFFER_SIZE treba na 5 kB.
      */ 
      #define LOG_SHIPPING

      // Pro ESP8266 zatim neni podpora pro OTA update!

  #endif


// Zde se definuje Serial port, ktery bude pouzit pro logovani:

  /* toto pouzijte, 
   * - pokud pouzivate procesor s fyzickym seriovym portem (a USB-to-serial konvertorem)
   * - pokud pouzijete ESP32-C3 a v nastaveni zvolite USB-CDC on boot: Enabled
   */
   #define LOG_SERIAL_PORT Serial
  
  /* toto pouzijte,
   *  - pokud pouzivate ESP32-C3, nemate na desce USB-to-serial konvertor, a v nastaveni zvolite USB-CDC on boot: Disabled
   */  
   // #define LOG_SERIAL_PORT USBSerial

#endif
