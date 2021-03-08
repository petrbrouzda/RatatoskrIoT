/**
 * Aplikace pro kameru ESP-CAM a pro teplomery DS18B20 na onewire sbernici.
 * Probudi se jednou za nekolik minut, a pokaždé:
 * - změří teplotu z DS18B20
 * - pokud se podaří připojit k internetu, pořídí fotku a pošle jí na server
 * 
 * Naměřené teploty jsou kešovány do RTCMEM, tj. ukládají se, i když není (dočasně) spojení na internet.
 * Jakmile je spojení obnoveno, data jsou odeslány.
 * Data v RTCMEM vydrží přes deep sleep, ale ztratí se při resetu tlačítkem nebo výpadku napájení.
 * 
 * Neuklada fotky na SD kartu.
 * 
  * Aplikace ma vzdalene konfigurovatelne hodnoty: 
 
Nastaveni behu aplikace:

 1) sleep_sec - na jakou ma jit do deep sleepu, tedy interval mezi jednotlivymi behy. [sekundy]

 2) sleep_sec_err - na jakou ma jit do deep sleepu v pripade, ze se nepodarilo odeslat obrazek. [sekundy]

 Nastaveni kamery:

 3) cam_rotate - 0-3, default 0
    bit 0 = hmirror
    bit 1 = vflip
  Tedy:
    0 = primy obraz
    1 = zrcadlove otoceny
    2 = vzhuru nohama 
    3 = vzhuru nohama + zrcadlove otoceny

4) cam_agc_gain - 0-6, default 6
    nastaveni maxima automatickeho gainu 0 (2x) az 6 (128x)

5) cam_awb - nastaveni white balance
    -1 automaticky (awb_gain=0 awb=0)
    0-4 awb_gain=1 awb=hodnota 0 - Auto, 1 - Sunny, 2 - Cloudy, 3 - Office, 4 - Home
    POZOR! Hodnota 0 funguje podivne.

 * 
 * Zapojeni
 * - na pinu 13 je pripojen teplomer (ci vice teplomeru) DS18B20 (standardne, tj. vcc na +3.3V, gnd na gnd, data na 13 a mezi data a vcc rezistor 4.7k)
 * - pin 14 je pres jumper/tlacitko spojovan na GND - pokud je pri resetu sepnut na LOW, je spusten konfiguracni portal
 * 
 * Zde je schema, jak propojit s USB-serial adapterem pro naprogramování:
 * https://randomnerdtutorials.com/esp32-cam-post-image-photo-server/
 *
 * Nastaveni desky:
   - PSRAM on
   - CPU speed 160 MHz
   - Flash speed 80 MHz
 */

/*
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


/**
 * Doba, na jakou budeme uspavat. Z konfigurace "sleep_sec"
 */
long sleepPeriod;

/*
 * Blok pro výpočet priority zprávy s teplotou.
 */ 
RTC_DATA_ATTR int rtcFlag;
RTC_DATA_ATTR int lastHighPrioTime;
int msgPriority = 1;


/* ++++++++++++++++++++++++++++++ nastavení kamery +++++++++++++++++++ */
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"
#include "esp_camera.h"

// CAMERA_MODEL_AI_THINKER
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22
/* ------------------------------ nastavení kamery --------------- */



// podle http://acoptex.com/project/10297/basics-project-084a-esp32-cam-development-board-with-camera-camera-web-server-with-arduino-ide-at-acoptexcom/
// GPIO 4: Data 1 (also connected to the on-board LED)
// _musi_ byt napsane formou "GPIO_NUM_4" a ne "4", jinak nefunguje gpio_hold_en(ONBOARD_LED);
#define ONBOARD_LED   GPIO_NUM_4



/* 
 * Sekvence bliknuti pro jednotlive stavy aplikace - vzdy delka zapnuto/vypnuto.
 * Pokud končí -1, provede se jednou a skončí; pokud končí 0, opakuje se.
 */
int blinkerOdesilam[] = { 3, 100, 3, 100, 3, 1000, 0 };
int blinkerOK[] = { 500, 1000, 500, 1000, -1 };
int blinkerErrCamera[] = { 500, 1000, 250, 500, 250, 500, -1 };
int blinkerErrConn[] = { 500, 1000, 250, 500, 250, 500, 250, 500, 250, 500, -1 };




/* ++++++++++++++++++++++++++++++ nastavení DS18B20 +++++++++++++++++++ */
#include <OneWire.h> 
// import "OneWire" version 2.3.5 in lib manager !!!
#include <DallasTemperature.h>
// import "DallasTemperature" version 3.8.0 in lib manager !!!

// tady se nastavuje port pro OneWire zarizeni
#define ONE_WIRE_BUS 13

// presnost mereni
#define TEMPERATURE_PRECISION 12

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
/* ------------------------------ nastavení DS18B20 ------------------- */




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

    // pokud je chyba pri cteni, vraci se -127 stupnu
    if( temp > -80.0 ) {
      int ch = ra->defineChannel( DEVCLASS_CONTINUOUS_MINMAXAVG, 1, addr, 3600 );
      ra->postData( ch, msgPriority, temp );
    }
  }
}




bool cameraInitLow()
{
  logger->log("Camera setup" );
  
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sscb_sda = SIOD_GPIO_NUM;
  config.pin_sscb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // init with high specs to pre-allocate larger buffers
  if(psramFound()){
    // pokud je PSRAM, je možné alokovat velký buffer pro velký obrázek
    config.frame_size = FRAMESIZE_UXGA; // ;  FRAMESIZE_SVGA
    config.jpeg_quality = 12;  //0-63 lower number means higher quality
    config.fb_count = 1;
  } else {
    // není PSRAM? pouze malinký obrázek
    config.frame_size = FRAMESIZE_CIF;
    config.jpeg_quality = 12;  //0-63 lower number means higher quality
    config.fb_count = 1;
  }
  
  // camera init
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    logger->log("Camera init failed with error 0x%x", err);
    return false;
  }

  return true;
}


void cameraInit()
{
  if( cameraInitLow() ) {
    return;
  }

  // chyba inicializace

  // nejprve podle https://github.com/espressif/esp32-camera/issues/102#issuecomment-740496660 zkusime reset kamery
  pinMode( PWDN_GPIO_NUM, OUTPUT );
  digitalWrite( PWDN_GPIO_NUM, HIGH );
  delay(1000);
  digitalWrite( PWDN_GPIO_NUM, LOW );
  delay(1000);

  if( cameraInitLow() ) {
    return;
  }

  // ani po resetu kamera nebezi, je treba propadnout panice

  logger->log("Restartuji!" );
  blinker.setCode( blinkerErrCamera );
  // je tu potreba asi 4500 ms pauza je tu, aby LED dokazala vyblikat chybovy kod
  delay( 4500 );

  // pokud nemam zadna data k odeslani, mohu udelat plny reset, treba se to vzpamatuje:
  if( ! ra->hasData() ) {
    logger->log("ESP restart za 30 sec" );
    // delay, abychom neposilali teplotu kazdych 5 sekund
    delay( 30000 );
    logger->log("ESP restart" );
    delay( 1000 );
    ESP.restart();
  } else {
    /* A pokud mam data, nerestartuji, protoze by mi to smazalo pripadna namerena data z teplomeru.
        Misto toho volam funkci pro uspani naprimo, protoze pokud nemam spojeni, tak se neodeslou data z teplomeru a tedy NENI vse odeslano. */
    raAllWasSent();
  }
}


/**
 * Vraci:
 * 0 = OK
 * 1 = chyba odeslani
 * 2 = chyba kamery
 */
int cameraPhoto()
{
  logger->log("Taking photo!");

  // pred focenim vypneme blikatko
  blinker.off();

  sensor_t * s = esp_camera_sensor_get();

  long img_rotate = config.getLong( "cam_rotate", 0 );
  int hmirror = img_rotate & 1;
  int vflip = (img_rotate & 2) >> 1;

  int cam_agc_gain = (int)config.getLong( "cam_agc_gain", 6 );
  if( cam_agc_gain>6 ) cam_agc_gain = 6;
  if( cam_agc_gain<0 ) cam_agc_gain = 0;


  int cam_awb = (int)config.getLong( "cam_awb", -1 );
  if( cam_awb<-1 || cam_awb>4 ) {
    cam_awb = -1;
  }
  int awb_gain = 0;
  int awb_mode = 0;
  if( cam_awb >= 0 ) {
    awb_gain = 1;
    awb_mode = cam_awb;
  }

  logger->log("Camera: hmirror=%d vflip=%d gain=%d awb_gain=%d awb=%d",
              hmirror,
              vflip,
              cam_agc_gain,
              awb_gain,
              awb_mode );

  s->set_brightness(s, 0);     // -2 to 2
  s->set_contrast(s, 0);       // -2 to 2
  s->set_saturation(s, 0);     // -2 to 2
  s->set_special_effect(s, 0); // 0 to 6 (0 - No Effect, 1 - Negative, 2 - Grayscale, 3 - Red Tint, 4 - Green Tint, 5 - Blue Tint, 6 - Sepia)

  s->set_whitebal(s, 1);       // 0 = disable , 1 = enable
  s->set_awb_gain(s, awb_gain);       // 0 = disable , 1 = enable - kdyz se da 1, je to strasne saturovane
  s->set_wb_mode(s, awb_mode);        // 0 to 4 - if awb_gain=1 (0 - Auto, 1 - Sunny, 2 - Cloudy, 3 - Office, 4 - Home)

  s->set_exposure_ctrl(s, 1);  // 0 = disable , 1 = enable  "AEC sensor" - pokud je 0, pak plati hodnota aec_value
  s->set_aec2(s, 1);           // 0 = disable , 1 = enable  "AEC DSP"
  s->set_ae_level(s, 0);       // -2 to 2 (kompenzace expozice)
  s->set_aec_value(s, 300);    // 0 to 1200 - pokud je vypnuty "AEC senzor", nastavuje se expozice rucne

  s->set_gain_ctrl(s, 1);      // 0 = disable , 1 = enable    - automaticky gain control - zesileni snimace
  s->set_agc_gain(s, 5 );       // 0 to 30       - fixni hodnota gain, pokud je gain_ctrl=0
  s->set_gainceiling(s, (gainceiling_t)cam_agc_gain);  // 0 to 6       - maximalni hodnota gain (6 = 128x), pokud je gain_ctrl=1

  s->set_bpc(s, 0);            // 0 = disable , 1 = enable  asi black pixel compensation
  s->set_wpc(s, 1);            // 0 = disable , 1 = enable  asi white pixel compensation
  s->set_raw_gma(s, 1);        // 0 = disable , 1 = enable  korekce gama
  s->set_lenc(s, 1);           // 0 = disable , 1 = enable  korekce vinetace
  s->set_hmirror(s, hmirror);        // 0 = disable , 1 = enable
  s->set_vflip(s, vflip);          // 0 = disable , 1 = enable
  s->set_dcw(s, 0);            // 0 = disable , 1 = enable    "downsize" ???
  s->set_colorbar(s, 0);       // 0 = disable , 1 = enable

  camera_fb_t * fb = NULL;

  // nejprve nacteme N fotek, ktere obratem zahodime - ale na nich se probudi AE control a nastavi spravne gain
  for( int i = 1; i<20; i++ ) {
    logger->log("treningove foto %d", i );
    fb = esp_camera_fb_get();
    if(!fb) {
      logger->log("Camera capture failed");
      return 2;
    }
    esp_camera_fb_return( fb );
  }

  // a ted teprve udelame tu potrebnou fotku
  fb = esp_camera_fb_get();
  if(!fb) {
    logger->log("Camera capture failed");
    return 2;
  }

  blinker.setCode( blinkerOdesilam );
  
  if( 0 == ra->sendBlob( fb->buf, fb->len, time(NULL), (char*)"camera", (char*)"jpg"  ) ) {
    logger->log("Data sent.");
    return 0;
  } else {
    logger->log("Send failed.");
    return 1;
  }

}



/**
 * Je volano z wifiStatus_Connected
 * ve chvili, kdy se modul pripoji na internet.
 */
void doCamera()
{
  // zrusime deep sleep po triceti sekundach od startu tim, ze ho nastavime na 100 sekund od ted
  tasker.setTimeout( inactivitySleep, 100000 );

  // inicializace kamery
  cameraInit();
  
  logger->log( "Mame spojeni, jdu fotit" );
  int rc = cameraPhoto();
  if( rc==0 ) {
    logger->log( "Odeslano, uspavame." );
    blinker.setCode( blinkerOK );
    // pauza je tu, aby LED dokazala vyblikat OK kod
    delay( 3000 );
    // nevolam deep sleep naprimo, ale takto - tim zajistim, ze se zpracuje pripadna zmena konfigurace prijata pri prihlasovani
    // a nastavim delku spanku na delku pro uspesne odeslani
    sleepPeriod = config.getLong( "sleep_sec", 300 ) * 1e6L;
    ra->setAllDataPreparedFlag();
  } else {
    logger->log( "Chyba pri odesilani, uspim jen na pul minuty." );
    blinker.setCode( blinkerErrConn );
    // pauza je tu, aby LED dokazala vyblikat chybovy kod
    delay( 4500 );
    // volam funkci pro uspani naprimo, protoze pokud nemam spojeni, tak se neodeslou data z teplomeru a tedy NENI vse odeslano.
    raAllWasSent();
  }
}


/**
 * Tuhle funkci zavolam 30 sekund po startu, pokud se nepodari pripojit k wifi,
 * nebo 100 sekund od spusteni kamery, pokud odesilani na server do te doby neskonci.
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
  // podrzime LED pin na LOW i v dobe deep sleep
  // jinak to bude trochu svitit 
  blinker.off();
  gpio_hold_en(ONBOARD_LED);
  gpio_deep_sleep_hold_en();
  // https://github.com/espressif/arduino-esp32/issues/2712
  // https://github.com/espressif/esp32-camera/issues/163
  
  ra->deepSleep( sleepPeriod );
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

  // povolime zase pouziti pinu led
  gpio_deep_sleep_hold_dis();
  gpio_hold_dis(ONBOARD_LED);
  
  //+++++ RatatoskrIoT +++++
    // Mel by byt jako prvni v setup().
    // Pokud je parametr true, zapne Serial pro rychlost 115200. Jinak musi byt Serial zapnuty uz drive, nez je tohle zavolano.
    ratatoskr_startup( true );
  //----- RatatoskrIoT ----

  //++++++ user code here +++++
  if( ESP.getPsramSize()==0 ) {
    logger->log( "!!! CHYBA: ZAPNI PODPORU PSRAM, bez toho nelze snimat velke fotky !!!" );
  }

  // interval mezi starty - nastavime delku pro neuspesne odeslani (a pri uspechu zmenime)
  sleepPeriod = config.getLong( "sleep_sec_err", 30 ) * 1e6L;

  // pokud se do pul minuty nechyti wifi, uspime zarizeni
  tasker.setTimeout( inactivitySleep, 30000 );


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
    logger->log( "prvni start po zapnuti" );
  } else {
    if( (time(NULL) - lastHighPrioTime) > 3600 ) {
      lastHighPrioTime = time(NULL);
      msgPriority = 2;
      logger->log( "priorita 2" );
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

bool runOnce = true;

void wifiStatus_Connected(  int status, int waitTime, char * ipAddress  )
{
  // +++ user code here +++
  logger->log("* wifi [%s], %d dBm, %d s", ipAddress, WiFi.RSSI(), waitTime );
  if( runOnce ) {
    // mame internet; nespustime vsak kameru hned, ale pockame chvili na pripadne namereni teploty, pokud jeste neprobehla
    tasker.setTimeout( doCamera, 2500 );
    runOnce = false;
  }
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
Použití knihovny OneWire ve verzi 2.3.5 v adresáři: C:\Users\brouzda\Documents\Arduino\libraries\OneWire 
Použití knihovny DallasTemperature ve verzi 3.8.0 v adresáři: C:\Users\brouzda\Documents\Arduino\libraries\DallasTemperature 
Použití knihovny SPIFFS ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\SPIFFS 
*/
