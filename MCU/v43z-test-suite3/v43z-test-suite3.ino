/**
 * Toto je test funkce knihoven.
 * Musi fungovat na ESP32 i na ESP8266.
 */

/*
 * ESP 8266:
 * - V Arduino IDE MUSI byt nastaveno rozdeleni flash tak, aby bylo alespon 1 M filesystemu SPIFS !
 * - Pokud se ma pouzivat deepsleep, D0 (GPIO16) musi byt spojena s RST. Nicmene pro programovani je treba toto spojeni rozpojit.
 *   According to the ESP8266 SDK, you can only sleep for 4,294,967,295 µs, which is about ~71 minutes.
 * 
 * ESP 32:
 * - V Arduino IDE MUSI byt nastaveno rozdeleni flash tak, aby bylo alespon 1 M filesystemu SPIFS !
 * - V Arduino IDE MUSI byt nastaveno PSRAM: enabled (pokud se ma pouzivat PSRAM)
 * - If you’re using the built-in ADC, don’t forget to turn it back on before using it:
        adc_power_on();
     (pokud se ADC nema vypinat, je treba upravit v platform.cpp)
   - TODO: kouknout na https://github.com/micropython/micropython/issues/4452 rtc_gpio_isolate(GPIO_NUM_12); 
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
    RTC_DATA_ATTR unsigned char ra_storage[RA_STORAGE_SIZE];
  #endif

  #ifdef USE_BLINKER
    raBlinker blinker( BLINKER_PIN );
    int blinkerPortal[] = BLINKER_MODE_PORTAL;
    int blinkerSearching[]  = BLINKER_MODE_SEARCHING;
    int blinkerRunning[] = BLINKER_MODE_RUNNING;
    int blinkerRunningWifi[] = BLINKER_MODE_RUNNING_WIFI;;
  #endif  

//----- RatatoskrIoT ----



int ch1;

void generujAPosliNejakaData()
{
  // odeslu data
  ra->postData( ch1, 1, (double)(time(NULL)) );
}


// wifi zapnu jednou za 2.5 min a za 25 sekund zavolam wifiVypni
void wifiZapni()
{
  logger->log( "ZAPINAM WIFI" );
  tasker.setTimeout( wifiVypni, 25000 );
  startWifi();
}

// po 25 sekundach behu wifi ho zase na 150 sekund vypnu
void wifiVypni()
{
  logger->log( "VYPINAM WIFI" );
  tasker.setTimeout( wifiZapni, 150000 );
  stopWifi();
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
  
  // deep sleep na 15 sec
  ra->deepSleep( 15e6 );
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
    // Mel by byt jako prvni v setup().
    // Pokud je parametr true, zapne Serial pro rychlost 115200. Jinak musi byt Serial zapnuty uz drive, nez je tohle zavolano.
    ratatoskr_startup( true );
  //----- RatatoskrIoT ----

  //++++++ user code here +++++

  // zalozim kanal pro data
  ch1 = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 9, (char*)"test", 3600 );

  // kazdou minutu si vygeneruju data
  tasker.setInterval( generujAPosliNejakaData, 60000 );
  // a hned ted taky
  generujAPosliNejakaData();

  // a wifi zapnu az za 15 sec
  tasker.setTimeout( wifiZapni, 15000 );

  //------ user code here -----
}


void loop() {
  //+++++ RatatoskrIoT +++++
    // do scheduled tasks
    tasker.loop();
  //----- RatatoskrIoT ----

  //++++++ user code here +++++
  //------ user code here ------
}


//------------------------------------ callbacks from WiFi +++


void wifiStatus_StartingConfigPortal(  char * apName, char *apPassword, char * ipAddress   )
{
  // +++ user code here +++
    logger->log( "Starting AP [%s], password [%s]. Server IP [%s].", apName, apPassword, ipAddress );
  // --- user code here ---
}

void wifiStatus_Connected(  int status, int waitTime, char * ipAddress  )
{
  // +++ user code here +++
  logger->log("* wifi [%s], %d s", ipAddress, waitTime );
  // --- user code here ---
}

void wifiStatus_NotConnected( int status, long msecNoConnTime )
{
  // +++ user code here +++
    char desc[32];
    getWifiStatusText( status, desc );
    logger->log("* no wifi (%s), %d s", desc, (msecNoConnTime / 1000L) );
  // --- user code here ---
}

void wifiStatus_Starting()
{
  // +++ user code here +++
  // --- user code here ---
}

/**
   Je zavolano pri startu.
   - Pokud vrati TRUE, startuje se config portal.
   - Pokud FALSE, pripojuje se wifi.
   Pokud nejsou v poradku konfiguracni data, otevira se rovnou config portal a tato funkce se nezavola.
*/
bool wifiStatus_ShouldStartConfig()
{
  // +++ user code here +++
    pinMode(CONFIG_BUTTON, INPUT_PULLUP);

    // Pokud se pouziva FLASH button na NodeMCU, je treba zde dat pauzu, 
    // aby ho uzivatel stihl zmacknout (protoze ho nemuze drzet pri resetu),
    // jinak je mozne usporit cas a energii tim, ze se rovnou pojede.
    /*
    logger->log( "Sepni pin %d pro config portal", CONFIG_BUTTON );
    delay(3000);
    */

    if ( digitalRead(CONFIG_BUTTON) == CONFIG_BUTTON_START_CONFIG ) {
      logger->log( "Budu spoustet config portal!" );
      return true;
    } else {
      return false;
    }
  // --- user code here ---
}
//------------------------------------ callbacks from WiFi ---

/*
ESP8266 2.7.1:
Použití knihovny ESP8266WiFi ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WiFi 
Použití knihovny DNSServer ve verzi 1.1.1 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\DNSServer 
Použití knihovny ESP8266WebServer ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WebServer 
Použití knihovny Tasker ve verzi 2.0 v adresáři: C:\Users\brouzda\Documents\Arduino\libraries\Tasker 
Použití knihovny Ticker ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\Ticker 
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
