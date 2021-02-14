#if defined(ESP8266)

  // ESP8266 libs
  #include <ESP8266WiFi.h>          //https://github.com/esp8266/Arduino

#elif defined(ESP32)

  //ESP32
  #include <WiFi.h>
  #include <SPIFFS.h>

#endif


/*
 * This file handles configuration file and wifi connection
 */ 

#define WIFI_NOCONN_RESTART_SEC 600

#define CONFIG_PORTAL_TIMEOUT 1200


// je wifi zapnuto?
bool wifiPoweredOn = false;

// posledni stav wifi
int wifiLastStatus;

// pokud je disconnected: v jakem case (millis()) bylo naposled pripojeni?
// pokud je prilis dlouho disconnected (WIFI_NOCONN_RESTART_SEC), dojde k resetu wifi
long lastConnected = 0;

// callback z wifi manageru, zda se ma ukladat nastaveni
bool shouldSaveConfig;

long wifiPoweredOnMsec = 0;
long wifiPoweredOffMsec = 0;
long lastWifiStatusChangeMsec = 0;

raConfig * wifi_config;

//gets called when WiFiManager enters configuration mode
void configModeCallback (WiFiManager *myWiFiManager) {
}

//callback notifying us of the need to save config
void saveConfigCallback () {
  logger->log("Config changed");
  shouldSaveConfig = true;
}

const char * wifi_ssid;


void displayWifiStats( long lastCycle ) 
{
    char temp[20];
    if( lastCycle > 0 ) {
      sprintf( temp, ", last cycle %d s", lastCycle/1000 );
    } else {
      temp[0] = 0;
    }
    
    long total = wifiPoweredOffMsec + wifiPoweredOnMsec;
    long percent = (total >= 1000) ? ( (wifiPoweredOnMsec/10) / (total/1000) ) : 0;

    if( wifiPoweredOn ) {
      logger->log( "~ wifi: ON [%s], on %d s, off %d s, usage %d %%, uptime %d s%s", 
                    wifi_ssid,
                    (wifiPoweredOnMsec/1000), 
                    (wifiPoweredOffMsec/1000), 
                    percent,
                    millis() / 1000,
                    temp
                    );
    } else {
      logger->log( "~ wifi: OFF, on %d s, off %d s, usage %d %%, uptime %d s%s", 
                    (wifiPoweredOnMsec/1000), 
                    (wifiPoweredOffMsec/1000), 
                    percent,
                    millis() / 1000,
                    temp
                    );
    }
                     
}


void startWifi()
{
    if( wifiPoweredOn ) return;

#ifdef USE_BLINKER
    blinker.setCode( blinkerSearching );
#endif     
    
    wifiPoweredOn = true;
    lastConnected = millis();

    // logger->log("* wifi connecting [%s]", config.ssid );
    WiFi.persistent(false);
    WiFi.softAPdisconnect(true);  // https://stackoverflow.com/questions/39688410/how-to-switch-to-normal-wifi-mode-to-access-point-mode-esp8266
                                  // https://arduino-esp8266.readthedocs.io/en/latest/esp8266wifi/soft-access-point-class.html#softapdisconnect
    WiFi.disconnect(); 
    WiFi.mode(WIFI_STA); // sezere 50 kB RAM
    WiFi.disconnect(); 
    wifi_ssid = config.getString("wifi_ssid","");
    WiFi.begin( wifi_ssid, config.getString("$wifi_pass","") );

    long curTime = millis();
    wifiPoweredOffMsec += curTime - lastWifiStatusChangeMsec;
    lastWifiStatusChangeMsec = curTime;

    wifiLastStatus = WL_DISCONNECTED;
    displayWifiStats( 0 );
    
    wifiStatus_Starting();
}

void stopWifi()
{
    if( ! wifiPoweredOn ) return;
    
    wifiPoweredOn = false;
    WiFi.softAPdisconnect(true);
    WiFi.mode( WIFI_OFF );
#ifdef ESP8266
    WiFi.forceSleepBegin();
#endif

    long curTime = millis();
    long lastCycle = curTime - lastWifiStatusChangeMsec;
    wifiPoweredOnMsec += lastCycle;
    lastWifiStatusChangeMsec = curTime;
    displayWifiStats( lastCycle );
}

void networkConfig( const char * configApPass, bool runWifiOnStartup )
{
  WiFi.persistent(false);
  
  wifiLastStatus = -1;
  shouldSaveConfig = false;

  bool connResult = false;
  bool startPortal = false;
  
  // pokud neni validni konfigurace NEBO pokud uzivatel stiskne tlacitko, spustime config portal
  if( (!isConfigValid(&config)) || wifiStatus_ShouldStartConfig() )
  {
      //WiFiManager
      //Local intialization. Once its business is done, there is no need to keep it around
      WiFiManager wifiManager;

      //set callback that gets called when connecting to previous WiFi fails, and enters Access Point mode
      wifiManager.setAPCallback(configModeCallback);
      wifiManager.setSaveConfigCallback(saveConfigCallback);
      wifiManager.setConfigPortalTimeout( CONFIG_PORTAL_TIMEOUT );
      wifiManager.setConnectTimeout(30);

      // pokud se meni IP adresa, je potreba ji dat i do callbacku wifiStatus_StartingConfigPortal() nize!
      wifiManager.setAPStaticIPConfig(IPAddress(10,0,1,1), IPAddress(10,0,1,1), IPAddress(255,255,255,0));
    
      //+++CONFIG+++ custom parameters for WifiManager --------------------------------------------------
          // id/name, placeholder/prompt, default, length
          WiFiManagerParameter custom_text1("<p>RA URL, device name and passphrase.</p>");
          wifiManager.addParameter(&custom_text1);
          WiFiManagerParameter custom_net_url("net_url", "RA URL (without http://)", config.getString("ra_url",""), 63);
          wifiManager.addParameter(&custom_net_url);
          WiFiManagerParameter custom_dev_name("dev_name", "RA device name", config.getString("ra_dev_name",""), 31);
          wifiManager.addParameter(&custom_dev_name);
          // password will not be shown - default value is not set
          WiFiManagerParameter custom_pass("ra_pass", "RA passphrase", "", 31);
          wifiManager.addParameter(&custom_pass);
      //--CONFIG--- custom parameters for WifiManager ----------------------------------------------------  

      char apName[20];
      strcpy( apName, "RA_" );
      raGetDeviceId( apName + strlen(apName) );

      wifiStatus_StartingConfigPortal(  apName, (char*)configApPass, (char*)"10.0.1.1"  );
      wifiManager.startConfigPortal(  apName, configApPass  );
      
      if( shouldSaveConfig ) {
          
          //+++CONFIG+++ custom parameters for WifiManager --------------------------------------------------
              config.setValue( "wifi_ssid", wifiManager.getSsid() );
              config.setValue( "$wifi_pass", wifiManager.getPassword() );
              config.setValue( "ra_url", custom_net_url.getValue());
              config.setValue( "ra_dev_name", custom_dev_name.getValue());
              config.setValue( "$ra_pass", custom_pass.getValue());
          //--CONFIG--- custom parameters for WifiManager ----------------------------------------------------

          // vypis upravene konfigurace:
          // config.printTo( Serial );
          saveConfig( &config );
      }

      stopWifi();
      
  }  // if( !config.isValid() || wifiStatus_ShouldStartConfig() )

   lastWifiStatusChangeMsec = 0;
   wifiPoweredOn = false;
   
   if( runWifiOnStartup ) {

      startWifi();
      
   } else {

#ifdef USE_BLINKER
      blinker.setCode( blinkerRunning );
#endif     
    
      WiFi.mode( WIFI_OFF );
      #ifdef ESP8266
        WiFi.forceSleepBegin();
      #endif    
      wifiLastStatus = WIFI_POWER_OFF;
      checkWifiStatus();
   }

} // void connectWifiOrStartManager( const char * fileName )



bool isWifiOn()
{
  return wifiPoweredOn;
}


bool checkWifiStatus()
{
  int newStatus;
  if( wifiPoweredOn ) {
      newStatus = WiFi.status();
  } else {
      newStatus = WIFI_POWER_OFF;
  }

  // aby nam to pri spousteni neslo pres dva stavy
  if( newStatus == WL_IDLE_STATUS ) {
    newStatus = WL_DISCONNECTED;
  }

  if( wifiLastStatus!=newStatus )
  {
    if( newStatus == WL_CONNECTED) {

      ra->conn->setRssi( WiFi.RSSI() );

      IPAddress ip = WiFi.localIP();
      logger->print( ip );
#ifdef USE_BLINKER
      blinker.setCode( blinkerRunningWifi );
#endif 
      wifiStatus_Connected( newStatus, ((millis()-lastConnected)/1000L), logger->printed );
      
    } else {
      
      if( wifiLastStatus==WL_CONNECTED ) {
        lastConnected = millis();
      }
#ifdef USE_BLINKER
      blinker.setCode( blinkerRunning );
#endif 
      wifiStatus_NotConnected( newStatus, millis()-lastConnected );
      
    }
    
    wifiLastStatus=newStatus;
  }

  if( newStatus!=WL_CONNECTED && newStatus!=WIFI_POWER_OFF)
  {
    long notConn = (millis()-lastConnected)/1000L;
    if( notConn>WIFI_NOCONN_RESTART_SEC ) {
      // restartovat wifi, kdyz se strasne dlouho nepripojilo
      logger->log("* wifi not connected for %d s, restarting for [%s]", notConn, wifi_ssid );

#ifdef ESP8266      
      ETS_UART_INTR_DISABLE();
      wifi_station_disconnect();
      ETS_UART_INTR_ENABLE();
      WiFi.persistent(false);
      WiFi.begin( wifi_ssid, config.getString("$wifi_pass","") );
#endif

#ifdef ESP32
      stopWifi();
      startWifi();
#endif
      
      lastConnected = millis();
    }
  }

  return (newStatus == WL_CONNECTED);
}
