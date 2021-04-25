/**
 * Ukazka low power aplikace. 
 * Jednou za N minut se probudi, nacte teplotu z teplomeru DS18B20 a zase se uspi.
 * Verze pro ESP32.
 * 
 * Zapojeni:
 * - na pinu 4 je pripojen teplomer (ci vice teplomeru) DS18B20 (standardne, tj. vcc na +3.3V, gnd na gnd, data na D4 a mezi data a vcc rezistor 4.7k)
 * - blika indikacni led na pinu 2
 *      - 5x za sec = spusten konfiguracni portal, pripojte se na RA_xxxxxx s heslem 'aaaaaaaa'
 *        a na 10.0.1.1 najdete konfiguracni portal
 *      - 2x za sec - hleda wifi
 * 
 * Seriovy port jede na 115200 bps.
 * 
 * Konfiguracni portal se spusti automaticky pokud zarizeni nema konfiguraci.
 * Nebo je mozne jej spustit nastavenim pinu 14 na LOW.
 * 
 * Aplikace ma jednu vzdalene konfigurovatelnou hodnotu - dobu, na jakou ma jit do deep sleepu, 
 * tedy interval mezi jednotlivymi behy. 
 *    Jmeno promenne: sleep_sec
 *    Jednotka: sekundy
 * Tedy
 *    sleep_sec=180
 * nastavi interval na 3 minuty.
 * 
 * 
 * Rozdily mezi verzemi aplikace pro ESP8266 a ESP32:
 * 
 * 1)
 * U ESP8266 doporucuji nastavit v AppConfig
 *    MANAGE_WIFI false
 *    RUN_WIFI_AT_STARTUP true
 * protoze na ESP8266 trva vyhledani wifi dlouho, takze je dobre ho zapnout hned pri startu.
 * Na ESP32 naopak lepe
 *    MANAGE_WIFI true
 *    RUN_WIFI_AT_STARTUP false
 * protoze start wifi je rychly, tak nema cenu ho poustet, kdyz jeste nejsou data k odeslani.
 * 
 * 2)
 * Na ESP32 je dale vhodne zapnout ukladani dat do RTCMEM, protoze pak se data
 * neztrati, ani kdyz nejakou dobu neni k dispozici spojeni na server.
 * Tj. zakomentovat RA_STORAGE_RAM a povolit RA_STORAGE_RTC_RAM.
 * 
 * 3)
 * Na ESP32 je pri pouziti RTCMEM vhodne poslat treba jednou za hodinu zpravu s vyssi prioritou.
 * Pokud dlouho neni spojeni na server, jsou zpravy s nizkou prioritou postupne mazany 
 * - a tim uvolni misto pro vice "hodinovych" zaznamu s vyssi prioritou.
 * 
 * 4)
 * Na ESP8266 se pouziva pro spusteni konfiguracniho portalu pin 0 = tlacitlo FLASH na NodeMCU
 * a proto se po bootu 3 sec ceka, zda ho uzivatel nezmackne.
 * 
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
  // cas, kdy byla nastartovana wifi
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




/* ++++++++++++++++++++++++++++++ nastavení DS18B20 +++++++++++++++++++ */
#include <OneWire.h> 
// import "OneWire" version 2.3.5 in lib manager !!!
#include <DallasTemperature.h>
// import "DallasTemperature" version 3.8.0 in lib manager !!!

// tady se nastavuje port pro OneWire zarizeni
#define ONE_WIRE_BUS 4

// presnost mereni
#define TEMPERATURE_PRECISION 12

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
/* ------------------------------ nastavení DS18B20 ------------------- */


/*
 * Blok pro výpočet priority zprávy s teplotou.
 */ 
RTC_DATA_ATTR int rtcFlag;
RTC_DATA_ATTR int lastHighPrioTime;
int msgPriority = 1;




#define ADR_SIZE 4 //byte

/**
 * Vrací adresu čidla jako textový řetězec.
 * Přeskočí první byte (vždy 28) a poslední byte (vždy 00).
 */
char * getAddressStr(DeviceAddress devAdr, uint8_t idx, char * target)
{
  for (uint8_t i = 0; i < ADR_SIZE; i++)
  {
    sprintf( target + (i*2), "%02.2x", devAdr[i+1] );
  }
  return target;
}


/**
 * Zmeri teplotu. 
 * Pro nalezene DS18B20 zalozi kanaly a posle do nich teplotu.
 */
void ds18b20()
{
  sensors.begin();
  DeviceAddress devAdr;
  int nSensors = sensors.getDeviceCount();

  if( nSensors==0 ) {
    logger->log( "0 senzoru, zkusime to za chvili znovu" );
    tasker.setTimeout( ds18b20, 1000 );
    return;
  }
  
  //vypis poctu senzoru
  logger->log( "Senzoru: %d", nSensors );

  sensors.requestTemperatures();

  for (uint8_t i = 0; i < nSensors; i++)
  {
    char addr[20];
    sensors.getAddress(devAdr, i);
    getAddressStr(devAdr, i, addr);

    double temp = sensors.getTempCByIndex(i);
    char charVal[20];
    dtostrf(temp, 6, 2, charVal);  

    logger->log( "#%d %s -> %s C", i, addr, charVal );

    // zalozime kanal pojmenovany po adrese tohoto senzoru
    int ch = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 1, addr, 3600 );
    // posleme do nej data
    ra->postData( ch, msgPriority, temp );
  }

  // Dulezite: rekneme, ze uz nemame zadna dalsi data k odeslani.
  // Po odeslani fronty se tak zavola raAllWasSent() a tim se zarizeni uspi.
  ra->setAllDataPreparedFlag();
}



/**
 * Tuhle funkci zavolam 20 sekund po startu, pokud se nepodari pripojit k wifi,
 * Zajistuje osetreni situace, ze neco neprobehlo spravne - zarizeni se uspi a po dalsim
 * restartu to bude mozna lepsi.
 */
void inactivitySleep()
{
  logger->log( "Prilis dlouha neaktivita, jdu do sleepu" );
  raAllWasSent();
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
  // pokud jsou nejake logy k odeslani, odesleme (pokud je to zapnute)
  raShipLogs();
  // z konfigurace nacteme dobu, na kterou se mame uspavat; pokud nebyla konfigurace nastavena, dáme 60 sec
  long sleepPeriod = config.getLong( "sleep_sec", 60 ) * 1e6L;
  // deep sleep 
  ra->deepSleep( sleepPeriod );
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

  // pokud se do 20 sec nechyti wifi a neodesle data, uspime zarizeni
  tasker.setTimeout( inactivitySleep, 20000 );

  /* 
   * Zvolime prioritu pro odesilane zpravy s teplotou.    
   * Jednou za hodinu je posleme s vyssi prioritou. 
   * Pokud neni spojeni delsi dobu, zpravy s nizsi prioritou se smazou, aby uvolnily misto pro prioritnejsi.
   * 
   * Do 3000 byte se vejde asi 150 zprav, tj. po deseti minutach by to bylo 25 hodin;
   * ale pokud se jednou za hodinu poslou zpravy s vyssi prioritou, vejde se tam 150 hodin.
   */
  if( rtcFlag!=0xdeadface ) {
    // nemame data v RTC pameti
    rtcFlag=0xdeadface;
    lastHighPrioTime = time(NULL);
    msgPriority = 2;
  } else {
    if( (lastHighPrioTime - time(NULL)) > 3600 ) {
      lastHighPrioTime = time(NULL);
      msgPriority = 2;
    } else {
      msgPriority = 1;
    }
  }

  // zmerime teplotu
  ds18b20();
  
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
