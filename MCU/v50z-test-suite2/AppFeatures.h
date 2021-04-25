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

#endif