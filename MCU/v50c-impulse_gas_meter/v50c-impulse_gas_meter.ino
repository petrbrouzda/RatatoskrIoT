/**
 * Ukazka uplne zakladni aplikace pro cteni impulzu - napr impulzniho vystupu plynomeru
 * Bezi nepretrzite, neuspava se.
 * Pocita impulzy (spojeni na LOW) na zvolenem vstupu - prednastaveno D5 (#define IMPULSE_INPUT D5).
 * 
 * Funguje na ESP8266 i na ESP32.
 * 
 * Zapojeni:
 * - blika indikacni led na pinu 2
 *      - 5x za sec = spusten konfiguracni portal, pripojte se na RA_xxxxxx s heslem 'aaaaaaaa'
 *        a na 10.0.1.1 najdete konfiguracni portal
 *      - 2x za sec - hleda wifi
 *      - 1x za 2 sec - bezi, wifi vypnuta
 *      - dvoublik 1x za 2 sec - bezi, wifi zapnuta
 * 
 * Seriovy port jede na 115200 bps.
 * 
 * Konfiguracni portal se spusti automaticky pokud zarizeni nema konfiguraci.
 * Nebo je mozne jej spustit tlacitkem FLASH (D0) - stisknout a drzet tlacitko >3 sec pote, co se pri startu rychle rozblika indikacni LED. 
 * 
 * POZOR! Na rozdil od jinych demo aplikaci ma i upravu konfiguracniho portalu - zadava se v nem prepoctovy faktor z impulzu na kWh.
 * Je to v WifiConnection oznaceno komentarem
 *    //!!!
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


#include "DebouncedInput.h"

// ktery vstup se nacita
#define IMPULSE_INPUT D5
DebouncedInput impulse1 = { IMPULSE_INPUT, LOW, 0, 0, 0 };

// cislo kanalu pro plynomer
int ch1;

/*
 * Konkretne pro nas:
 * Plynomer meri po 0,1 m3 = 1,05 kWh
 * Kotel ma max. vykon 28 kW
 * Tedy maximalne 28 kW/hod .. 27 impulzu za hodinu ... jeden impulz za cca 2 minuty
 */ 

// po kazdem N-tem pulzu (maximalni tempo 8 minut) posleme
#define SEND_EVERY_IMPULSE_NR 4

// pokud bude mene nez N impulzu, posleme do 15 minut
#define SEND_IMPULSE_AFTER 900

// i kdyz se nic nebude dit, posleme heartbeat
#define HEART_BEAT 1800


long lastSentImpCount = 0;
long lastSentTime = 0;



/**
 * Posleme neco na server?
 */ 
void handleState()
{
  bool posli = false;
  long ct;

  // po dobu cteni z promenne zakazeme interupty, aby se hodnota nahodou zrovna nemenila
  noInterrupts();
  ct = impulse1.impulseCount;
  interrupts();

  logger->log( "stav:%d pocet:%d", impulse1.currentState, ct );

  if( lastSentImpCount==ct ) {
    // nic se nezmenilo
    if( time(NULL)-lastSentTime > HEART_BEAT ) {
      // sice se nic nezmenilo, ale jednou za cas posleme stav, aby bylo videt, ze komunikace bezi
      posli = true;
    } else {
      // nic se nezmenilo, neposilame
      return;
    }
  }

  // pokud pribylo nejmene N impulzu
  if( ct - lastSentImpCount >= SEND_EVERY_IMPULSE_NR) {
    posli = true;
  }
  // pokud jich pribylo mene, ale uteklo vice nez N sec
  if( (ct - lastSentImpCount > 0) && (time(NULL)-lastSentTime > SEND_IMPULSE_AFTER) ) {
    posli = true;
  }

  if( posli ) {
    /* Stav impulzu se posila na server jinou funkci nez bezne mereni spojite veliciny. Posila se celkovy pocet od startu aplikace. */
    ra->postImpulseData( ch1, 5, ct );
    lastSentTime = time(NULL);
    lastSentImpCount = ct;
  }
}


/**
 * Obsluha preruseni.
 */ 
// ESP_ISR_RAM = ICACHE_RAM_ATTR resp. IRAM_ATTR podle platformy 
void ESP_ISR_RAM isrImpulse1()
{
  impulse( &impulse1 );
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

  /* 
   *  definujeme kanal pro senzor 
   * - je zde jina trida zarizeni DEVCLASS_IMPULSE_SUM
   * - jednotka 6 = kWh
   * - 3600 = jaky je maximalni interval mezi zpravami, nez zacne kricet monitoring
   * - z konfigurace (vyplnene z konfiguracniho portalu) se bere prepoctovy faktor impuls->kWh (1,05 pro 0,1 m3/imp; 0,105 pro 0,01 m3/imp)
   */
  ch1 = ra->defineChannel( DEVCLASS_IMPULSE_SUM, 
                            6, 
                            (char*)"plyn", 
                            3600, 
                            atof( config.getString( "prepocet", "1.05" ) ) 
                         );

  pinMode( impulse1.pin, INPUT_PULLUP );
  impulseStart( &impulse1 );
  attachInterrupt( impulse1.pin, isrImpulse1, CHANGE);

  tasker.setInterval( handleState, 5000 );
  
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
    logger->log( "Sepni pin %d pro config portal", CONFIG_BUTTON );
    delay(3000);

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
*/
