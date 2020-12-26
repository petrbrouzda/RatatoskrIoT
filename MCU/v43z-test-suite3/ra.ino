
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

  char buf[256];
  raGetAppName( buf, 256 );
  ra->conn->setAppName( buf );
  read_wakeup_and_print_startup_info( STARTUP_INFO, buf );

  raGetDeviceId( buf );
  config.setInfo( buf, (char*)RA_CONFIG_PASSPHRASE );
  loadConfig( &config );
  ra->conn->setConfigVersion( (int)config.getLong("@ver",0) );

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
      // pokud jsme ze serveru dostali aktualizaci konfigurace, je treba ji ulozit
      if( config.isDirty() ) {
        // config.printTo( Serial );
        saveConfig( &config );
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
