
#ifdef LOG_SHIPPING
  #define LOG_SHIPPING_COMPILED "Y"
#else
  #define LOG_SHIPPING_COMPILED "N"
#endif

#ifdef OTA_UPDATE
  #define OTA_UPDATE_COMPILED "Y"
#else
  #define OTA_UPDATE_COMPILED "N"
#endif

#if defined(ESP32)
  #define RA_PLATFORM_NAME "ESP32"
#elif defined(ESP8266)
  #define RA_PLATFORM_NAME "ESP8266"
#endif


int prevFreeHeap;

/**
 * Vola se jednou za minutu a vypise stav heapu
 */
void raPrintHeap()
{
    long freeHeap = ESP.getFreeHeap();
    long delta = freeHeap-prevFreeHeap;
    prevFreeHeap = freeHeap;
#if defined(ESP32)    
    logger->log( "~ heap: %d, delta %d; PSRAM: %d", freeHeap, delta, ESP.getFreePsram() );
#elif defined(ESP8266)
    logger->log( "~ heap: %d, delta %d, frg %d %%, max blk %d", freeHeap, delta, ESP.getHeapFragmentation(), ESP.getMaxFreeBlockSize() );
#endif

}


int prevFreeHeap2;

/**
 * Pro debugovani vyuziti pameti vlozte do kodu:
 *    memInfo( __FUNCTION__, __LINE__ );
 * A vypise se:
 *    ~ [startWifi:107] heap: 183440, delta -49904
 * tj. pozice ve zdrojaku, volny heap a zmena proti poslednimu volani memInfo
 */
void memInfo( const char * func, int line) 
{
    long freeHeap = ESP.getFreeHeap();
    long delta = freeHeap-prevFreeHeap2;
    prevFreeHeap2 = freeHeap;
    logger->log( "~ [%s:%d] heap: %d, delta %d", func, line, freeHeap, delta );
}


/**
 * Device ID se pouziva pro sifrovani konfiguracniho souboru
 * a pro nastaveni jmena konfiguracniho AP.
 * Pro jedno zarizeni musi vracet pokazde stejnou hodnotu.
 */
void raGetDeviceId( char * buf )
{
#ifdef ESP8266
    sprintf( buf, "%d", ESP.getChipId() );
#endif

#ifdef ESP32
    uint64_t macAddressTrunc = ((uint64_t)ESP.getEfuseMac()) << 40;
    long chipId = macAddressTrunc >> 40;
    sprintf( buf, "%d", chipId );
#endif
}


#ifdef DUMP_CONFIG
void dumpConfig() {
    logger->log( "config:");
    for(int i = 0; i < config.length; i++ ) {
        if( config.fields[i][0]!='$' ) {
          logger->log( "  %s='%s'",  config.fields[i], config.values[i] );
        } else {
          logger->log( "  %s=***", config.fields[i] );
        }
    }
}
#endif


#ifdef LOG_SHIPPING
  char * logTextBuffer;
  TextCircularBuffer * logShippingBuffer;
  long lastLogShipTime = 0;
  long lastLogShipError = -LOG_SHIPPING_ERROR_PAUSE-LOG_SHIPPING_ERROR_PAUSE;
  bool doLogShipping;

  void logShippingStart()
  {
    #ifdef ESP32 
      if( ESP.getPsramSize() > 0 ) {
        logTextBuffer = (char*)ps_malloc(LOG_BUFFER_SIZE);  
      } else {
        logTextBuffer = (char*)malloc(LOG_BUFFER_SIZE);
      }
    #else
      logTextBuffer = (char*)malloc(LOG_BUFFER_SIZE);
    #endif
    if( logTextBuffer==NULL ) {
      logger->log( "Chyba alokace logship buff.");
      logShippingBuffer = NULL;
    } else {
      logShippingBuffer = new TextCircularBuffer( logTextBuffer, LOG_BUFFER_SIZE );
      logger->setShippingBuffer( logShippingBuffer );
      doLogShipping = LOG_SHIPPING_DEFAULT;
    }
  }
#endif  





void ratatoskr_startup( bool initSerial )
{
  if ( initSerial  ) {
#if defined(ESP32)    
    Serial.begin(115200);
#elif defined(ESP8266)
    Serial.begin(115200);  // 74880 jsou bezne boot msgs
#endif
    while (!Serial) {}
  }

  ra = new ratatoskr( &config, LOG_MODE );
  logger = ra->logger;
  logger->log( "#" );

  #ifdef LOG_SHIPPING
    logShippingStart();
  #endif  

  #if defined(RA_STORAGE_RAM)
    // storage v RAM, ztrati se pri deep sleepu - ale muze byt vetsi
    ra->createStorage( RA_STORAGE_SIZE );
  #elif defined(RA_STORAGE_RTC_RAM)
    /* Storage v RTC RAM, prezije deep sleep. Jen pro ESP32, na ESP8266 neni dost RTC RAM.
     * Musite pripravit statickou promennou ulozenou v RTC RAM
     *  RTC_DATA_ATTR unsigned char ra_storage[RA_STORAGE_SIZE];
     */
    ra->createRtcRamStorage( ra_storage, RA_STORAGE_SIZE );
  #else
    #error musi byt definovano RA_STORAGE_RAM nebo RA_STORAGE_RTC_RAM
  #endif

  char buf2[192];
  raGetAppName( buf2, 192 );
  char buf[256];
  snprintf( buf, 256, "[%s]; %s; RA %s; LS %s; OTA %s; %s", 
            APP_VERSION_ID, buf2,
            RA_CORE_VERSION, 
            LOG_SHIPPING_COMPILED, OTA_UPDATE_COMPILED, 
            RA_PLATFORM_NAME
          );  
  buf[255] = 0;

  ra->conn->setAppName( buf );
  read_wakeup_and_print_startup_info( STARTUP_INFO, buf );

  raGetDeviceId( buf );
  config.setInfo( buf, (char*)RA_CONFIG_PASSPHRASE );
  loadConfig( &config );
  ra->conn->setConfigVersion( (int)config.getLong("@ver",0) );

  #ifdef LOG_SHIPPING
    doLogShipping = config.getLong("log_ship",LOG_SHIPPING_DEFAULT);
  #endif

  #ifdef DUMP_CONFIG
    if( ! deepSleepStart ) {
      dumpConfig();
    }
  #endif

  #ifdef USE_BLINKER
    pinMode( BLINKER_PIN, OUTPUT );
    blinker.setCode( blinkerPortal );
  #endif  

  // last parameter = should be wifi started on startup?
  // pokud neni konfigurace, spusti konfiguracni portal
  networkConfig( CONFIG_AP_PASSWORD, MANAGE_WIFI ? false : RUN_WIFI_AT_STARTUP );

  // 2x za sec spustit hlavni obsluhu RA
  tasker.setInterval( raLoop, 495 );
  // jednou za minutu vypsat stav heapu
  tasker.setInterval( raPrintHeap, 60000 );

  prevFreeHeap = ESP.getFreeHeap();
  prevFreeHeap2 = prevFreeHeap;
}


#ifdef LOG_SHIPPING
  /**
   * Posle jeden log na server.
   *  0 = OK odeslano
   *  1 = chyba pri odeslani, lze udelat retry
   *  2 = neni co odesilat
   */ 
  int sendLogData()
  {
    // nejprve posleme prepsanou cast druheho bufferu
    if( logShippingBuffer->avail2() ) {
      Serial.println( " - log 2");
      if( ra->conn->sendLogData( logShippingBuffer->getText2() ) ) {
        logShippingBuffer->delete2();
        Serial.println( " - OK");
        return 0;
      } else {
        return 1;
      }
    }

    // pak posleme prvni buffer
    if( logShippingBuffer->avail1() ) {
      Serial.println( " - log 1");
      if( ra->conn->sendLogData( logShippingBuffer->getText1() ) ) {
        logShippingBuffer->delete1();
        Serial.println( " - OK");
        return 0;
      } else {
        return 1;
      }
    }

    return 2;
  }

  /**
   * Odeslani logu
   */ 
  bool raShipLogs()
  {
    if( !doLogShipping || !wifiOK ) {
      return true;
    }

    if( logShippingBuffer->avail() ) {
      Serial.println( "Log ship:");

      // Cas zacatku posledniho log shipingu
      long logShipingStart = time(NULL);

      for( int i = 0; i < (LOG_SHIPING_MAX_TRIES+LOG_SHIPING_MAX_TRIES); i++ ) {
        int rc = sendLogData();
        if( rc==2 ) return true;
        if( (time(NULL)-logShipingStart) > LOG_SHIPING_TIME_LIMIT ) break;
      }
      return false;
    }
  }
#else
  // prazdna funkce, aby ji mohla zakaznicka aplikace volat
  bool raShipLogs()
  { 
    return false; 
  }
#endif


// started periodically via Tasker
void raLoop()
{
  /*
   * Poradi musi byt:
   * 1) kontrola wifi a odeslani
   * 2) zmena stavu wifi
   * 3) callback pro zpracovani vsech dat
   */
   
  // must be called! handles wifi status callbacks!
  wifiOK = checkWifiStatus();
  // logger->log( "   -S: W:%s d:%s" , wifiOK?"Y":"N", ra->hasData()?"Y":"N" );
  if( wifiOK ) {
      
      // pokud je spojene wifi, mohu odesilat
      while( ra->process() ) {
        // nic, to je spravne
      }

      long restart = 0;
      
      // pokud jsme ze serveru dostali aktualizaci konfigurace, je treba ji ulozit
      if( config.isDirty() ) {
        restart = config.getLong("@restart",0);
        if( restart==1 ) {
          logger->log( "restart request!");
          config.setValue( "@restart", "0" );
        }
        ra->conn->setConfigVersion( (int)config.getLong("@ver",0) );
        saveConfig( &config );

        #ifdef LOG_SHIPPING
          doLogShipping = config.getLong("log_ship",LOG_SHIPPING_DEFAULT);
        #endif

        #ifdef DUMP_CONFIG
          dumpConfig();
        #endif        
      }

      // pokud je pozadavek na aktualizaci, meli bychom ji udelat
      #ifdef OTA_UPDATE
        if( ra->conn->updateReqId ) {
          logger->log( "update #%d", ra->conn->updateReqId );
          ra->conn->doUpdate( ra->conn->updateReqId );
          ra->conn->updateReqId = 0;
          if( ra->conn->shouldRestart ) {
            #ifdef LOG_SHIPPING
              raShipLogs();
            #endif
            logger->log( "update -> RESTART");
            delay(1000);
            ESP.restart();
          }
        }
      #endif

      // posleme logy
      #ifdef LOG_SHIPPING
        if( logShippingBuffer!=NULL && (time(NULL)>(lastLogShipError + LOG_SHIPPING_ERROR_PAUSE)) ) {
          if( (time(NULL)-lastLogShipTime)>=LOG_SHIPPING_MAX_INTERVAL || logShippingBuffer->getUsagePct()>50 ) {
            if( raShipLogs() ) {
              lastLogShipTime = time(NULL);
            } else {
              // chyba pri odesilani, zkusime za 30 sec
              lastLogShipError = time(NULL);
            }
          }
        }
      #endif

      if( restart==1 ) {
        ESP.restart();
      }
  }

  // spravuje si RA samo wifi?
  if( MANAGE_WIFI ) {
    // zapnu wifi, pokud mam co odesilat
    if( ra->hasData() && !isWifiOn() ) {
       startWifi();
       wifiStartTime = millis();
    }
    // vypnu wifi pokud ve fronte na odeslani nic neni
    if( !(ra->hasData()) && isWifiOn() ) {
       stopWifi();
    }
  }

  if( ra->isAllDataPrepared() && !ra->hasData() )
  {
      // zpracovali jsme vsechna data a muzeme koncit
      logger->log( "-> raAllWasSent()" );
      ra->clearAllDataPreparedFlag();
      raAllWasSent();
  }
}
