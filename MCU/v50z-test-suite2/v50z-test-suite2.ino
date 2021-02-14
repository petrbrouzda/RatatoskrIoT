/**
 * Toto je test funkce knihoven.
 * Muze byt sestaven a spusten jen na ESP32!
 */

//+++++ RatatoskrIoT +++++

  #include "AppConfig.h"

  // RA objects
  ratatoskr* ra;
  raLogger* logger;
  raConfig config;
  Tasker tasker;

  // stav WiFi - je pripojena?
  bool wifiOK = false;
  // cas, kdy byla nastartovana wifi; pokud se do N sekund po startu nepripoji a neposlou se data, muzeme je zahodit a jit do sleep mode
  long wifiStartTime = 0;
  // duvod posledniho startu, naplni se automaticky
  int wakeupReason;
  // je aktualni start probuzenim z deep sleep?
  bool deepSleepStart;

  #ifdef RA_STORAGE_RTC_RAM 
    RTC_DATA_ATTR unsigned char ra_storage[RA_STORAGE_SIZE + 10]; // custom uprava pro test!
  #endif

  #ifdef USE_BLINKER
    raBlinker blinker( BLINKER_PIN );
    int blinkerPortal[] = BLINKER_MODE_PORTAL;
    int blinkerSearching[]  = BLINKER_MODE_SEARCHING;
    int blinkerRunning[] = BLINKER_MODE_RUNNING;
    int blinkerRunningWifi[] = BLINKER_MODE_RUNNING_WIFI;;
  #endif  

//----- RatatoskrIoT ----



//++++++ Custom code +++++



//------ Custom code ------




RTC_DATA_ATTR int runCount;
RTC_DATA_ATTR int indicator;

// spocte pocet zaznamu ve storage
int countRecords()
{
  int count = 0;
  ra->storage->startReading();
  while ( 1 ) {
    raStorageRecord* rec = ra->storage->readNext();
    if ( rec == NULL) {
      break;
    }
    count++;
  }
  return count;
}


void test_before()
{
  if ( indicator != 0xdeadface ) {
    logger->log( "--- Prvni start aplikace" );
    indicator = 0xdeadface;
    runCount = 1;
    ra_storage[2048] = 0xaa;
    ra_storage[2049] = 0xbb;
  } else {
    runCount++;
    logger->log( "--- Beh cislo %d", runCount );
  }
}

int chNum;

int test_createChannel()
{
  int ch2;
  int prevRecCount = countRecords();

  // podle cisla behu zvolime ruzne poradi definice kanalu
  if ( runCount & 1 ) {
    logger->log( "poradi A B" );
    chNum = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 7, (char*)"runCount", 3600 );
    ch2 = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 7, (char*)"test", 3600 );
  } else {
    logger->log( "poradi B A" );
    ch2 = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 7, (char*)"test", 3600 );
    chNum = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 7, (char*)"runCount", 3600 );
  }
  int recCount = countRecords();
  if ( runCount == 1 ) {
    // pri prvnim behu se musi pro kanal zapsat zaznam do storage
    if ( prevRecCount != 0 || recCount != 2 )  {
      logger->log( "%s CHYBA pocet zaznamu neni OK. prevRecCount=%d, recCount=%d", __FUNCTION__, prevRecCount, recCount );
      return 1;
    }
  } else {
    // pri dalsim behu by se melo pouzit nakesovani informace o kanalech a nemelo by to zmenit pocet zaznamu
    if ( prevRecCount != recCount )  {
      logger->log( "%s CHYBA pri definici kanalu se navysil pocet zprav. prevRecCount=%d, recCount=%d", __FUNCTION__, prevRecCount, recCount );
      return 1;
    }
  }

  if ( chNum != 1 && ch2 != 2 ) {
    logger->log( "%s CHYBA spatna cisla kanalu. chNum=%d, ch2=%d", __FUNCTION__, chNum, ch2 );
    return 1;
  }

  return 0;
}


int test_postData()
{
  int prevRecCount = countRecords();
  ra->postData( chNum, 1, (double)(runCount) );
  int recCount = countRecords();

  if ( (prevRecCount + 1) != recCount )  {
    logger->log( "%s CHYBA nenavysil se pocet zprav. prevRecCount=%d, recCount=%d", __FUNCTION__, prevRecCount, recCount );
    return 1;
  }

  if ( recCount != (2 + runCount) )  {
    logger->log( "%s CHYBA zprav neodpovida poctu behu. recCount=%d", __FUNCTION__, recCount );
    return 1;
  }

  return 0;
}



int test_storageOverload()
{
    if( ra_storage[2048] != 0xaa || ra_storage[2049] != 0xbb ) {
      logger->log( "%s CHYBA storage memory overwrite. 2048=%x, 2049=%x", __FUNCTION__, ra_storage[2048], ra_storage[2049] );
      return 1;
    }

    if( ! ra->storage->isRtcRamStorage() ) {
      logger->log( "%s CHYBA kompilace, storage neni v RTC RAM", __FUNCTION__ );
      return 1;
    }

    return 0;
}



/*
 * Pokud user code oznaci posledni poslana data znackou 
 *    ra->setAllDataPreparedFlag();
 * automaticky se zavola, jakmile jsou vsechna data odeslana.
 * 
 * Typicke pouziti je ve scenari, kdy chceme po probuzeni odeslat balik dat a zase zarizeni uspat.
 * 
 * Pro pripad, ze se odeslani z nejakeho duvodu nezdari, doporucuji do setup() pridat zhruba toto:
 *    tasker.setTimeout( raAllWasSent, 60000 );
 * s nastavenym maximalnim casem, jak dlouho muze byt zarizeni probuzeno (zde 60 sec).
 * Tim se zajisti, ze dojde k deep sleepu i v pripade, ze z nejakeho duvodu nejde data odeslat.
 */
void raAllWasSent()
{
}

/**
 * Vraci jmeno aplikace a jeji verzi do alokovaneho bufferu.
 */
void raGetAppName( char * target, int size )
{
  snprintf( target, size, "%s - %s %s", __FILE__, __DATE__, __TIME__ );
  target[size-1] = 0;
}



void setup() {

  //+++++ RatatoskrIoT +++++
  // mel by byt jako prvni v setup(), pokud je parametr true, aktivuje seriovy port na 115200
  ratatoskr_startup( true );
  //----- RatatoskrIoT ----

  //++++++ Custom code +++++
#if defined(ESP8266)
  logger->log( "tento test je jen pro ESP32!" );
  return;
#endif

  int errs = 0;
  test_before();
  logger->log( "Zaznamu: %d", countRecords() );

  errs += test_createChannel();
  errs += test_postData();
  errs += test_storageOverload();

  logger->log( "Zaznamu: %d", countRecords() );
  logger->log( "Uloziste %d %%", ra->storage->getUsage() );

  if ( errs == 0 ) {
    
    logger->log( "Zadne chyby pri testech" );
    ra->deepSleep( 1000000 );
    
  } else {
    
    if( ra->storage->getUsage()==100 ) {
      logger->log( "Zaplnene uloziste, konec." );
    } else {
      logger->log( "%d CHYB pri testech", errs );
    }
    logger->log( "HALT!" );
    
  }


  //------ Custom code ------

}


void loop() {
}






//------------------------------------ callbacks from WiFi +++


void wifiStatus_StartingConfigPortal(  char * apName, char *apPassword, char * ipAddress   )
{
}

void wifiStatus_Connected(  int status, int waitTime, char * ipAddress  )
{
}

void wifiStatus_NotConnected( int status, long msecNoConnTime )
{
}


void wifiStatus_Starting()
{
}

/**
   Je zavolano pri startu, pokud jsou v poradku konfiguracni data.
   Pokud vrati TRUE, startuje se config portal.
   Pokud FALSE, pripojuje se wifi.
*/
bool wifiStatus_ShouldStartConfig()
{
}
//------------------------------------ callbacks from WiFi ---

/*
ESP8266 2.7.1:
Použití knihovny ESP8266WiFi ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WiFi 
Použití knihovny DNSServer ve verzi 1.1.1 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\DNSServer 
Použití knihovny ESP8266WebServer ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WebServer 
Použití knihovny Tasker ve verzi 2.0 v adresáři: C:\Users\brouzda\Documents\Arduino\libraries\Tasker 
Použití knihovny ESP8266HTTPClient ve verzi 1.2 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266HTTPClient 

ESP32 1.0.4:
Použití knihovny FS ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\FS 
Použití knihovny WiFi ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WiFi 
Použití knihovny HTTPClient ve verzi 1.2 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\HTTPClient 
Použití knihovny WiFiClientSecure ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WiFiClientSecure 
Použití knihovny WebServer ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WebServer 
Použití knihovny DNSServer ve verzi 1.1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\DNSServer 
Použití knihovny Tasker ve verzi 2.0 v adresáři: C:\Users\brouzda\Documents\Arduino\libraries\Tasker 
Použití knihovny Ticker ve verzi 1.1 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\Ticker 
Použití knihovny SPIFFS ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\SPIFFS 
*/
