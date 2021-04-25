/**
 * Toto je test funkce knihoven.
 * Musi fungovat na ESP32 i na ESP8266.
 * Testuje log shipping.
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
// v teto aplikaci se nepouziva
}

/**
 * Vraci jmeno aplikace do alokovaneho bufferu.
 * Jmeno aplikace by nemelo obsahovat strednik.
 */
void raGetAppName( char * target, int size )
{
  snprintf( target, size, "%s, %s %s", 
            __FILE__, __DATE__, __TIME__ 
            );  
  target[size-1] = 0;
}


void setup() {
  //+++++ RatatoskrIoT +++++
    // Mel by byt jako prvni v setup().
    // Pokud je parametr true, zapne Serial pro rychlost 115200. Jinak musi byt Serial zapnuty uz drive, nez je tohle zavolano.
    ratatoskr_startup( true );
  //----- RatatoskrIoT ----

  //++++++ user code here +++++


#ifdef LOG_SHIPPING

  #define BUFFER_SIZE 200
  char * textBuffer = (char*)malloc(BUFFER_SIZE);
  TextCircularBuffer buffer( textBuffer, BUFFER_SIZE );
  buffer.write( "-11111111111111111-");
  Serial.printf( "\n1: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );

  delay(1000);
  buffer.write( "-222222-");
  Serial.printf( "\n2: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  
  delay(1000);
  buffer.write( "-33333333333333333333333333333333333333333333333-");
  Serial.printf( "\n3: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  
  delay(1000);
  buffer.write( "-4444444444444444444444444444444444444444444444444444444444444444-");
  Serial.printf( "\n4: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  
  delay(1000);
  buffer.write( "-4544444444444444444444444444444444444444444444444444444444444444-");
  Serial.printf( "\n5: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  
  delay(1000);
  buffer.write( "-4644444444444444444444444444444444444444444444444444444444444444-");
  Serial.printf( "\n6: [%s]\n", textBuffer );
  Serial.printf( "avail: 1=%s 2=%s\n", 
    buffer.avail1() ? "YES" : "no",
    buffer.avail2() ? "YES" : "no"
  );
  Serial.printf( "1=[%s]\n", buffer.getText1() );
  Serial.printf( "2=[%s]\n", buffer.avail2() ? buffer.getText2() : "" );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  buffer.deleteText();
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  buffer.deleteText();
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  buffer.deleteText();
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );

  for( int i = 0; i<100; i++ ) {
    char tmp[20];
    sprintf( tmp, "-%d-%s-", i, (i%3==0 ? "AAAAAAAAA" : "") );
    buffer.write( tmp );
  }
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  buffer.deleteText();
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  buffer.deleteText();
  Serial.printf( "usage %d pct\n", buffer.getUsagePct() );
  Serial.printf( "\nget() [%s]\n", buffer.getText() );
  buffer.deleteText();

#endif

  // zalozim kanal pro data
  ch1 = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 9, (char*)"test", 3600 );

  // kazdou chvili s poslu nejaka data
  tasker.setInterval( generujAPosliNejakaData, 10000 );

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
    logger->log( "Starting AP [%s], password [%s], server IP [%s].", apName, apPassword, ipAddress );
  // --- user code here ---
}

void wifiStatus_Connected(  int status, int waitTime, char * ipAddress  )
{
  // +++ user code here +++
  logger->log("* wifi [%s], %d dBm, %d s", ipAddress, WiFi.RSSI(), waitTime );
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
Using library ESP8266WiFi at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.4\libraries\ESP8266WiFi 
Using library DNSServer at version 1.1.1 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.4\libraries\DNSServer 
Using library ESP8266WebServer at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.4\libraries\ESP8266WebServer 
Using library Tasker at version 2.0 in folder: c:\Users\brouzda\Documents\Arduino\libraries\Tasker 
Using library Ticker at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.4\libraries\Ticker 
Using library ESP8266HTTPClient at version 1.2 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.4\libraries\ESP8266HTTPCl

ESP32 1.0.6:
Using library FS at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\FS 
Using library WiFi at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\WiFi 
Using library HTTPClient at version 1.2 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\HTTPClient 
Using library WiFiClientSecure at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\WiFiClientSecure 
Using library WebServer at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\WebServer 
Using library DNSServer at version 1.1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\DNSServer 
Using library Tasker at version 2.0 in folder: c:\Users\brouzda\Documents\Arduino\libraries\Tasker 
Using library Ticker at version 1.1 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\Ticker 
Using library SPIFFS at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\SPIFFS 
Using library Update at version 1.0 in folder: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.6\libraries\Update 
*/
