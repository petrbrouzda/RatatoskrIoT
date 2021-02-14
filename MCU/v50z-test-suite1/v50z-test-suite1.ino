/**
 * Toto je test zakladni funkce knihoven.
 * Muze byt sestaven a spusten na ESP8266 i ESP32.
 */

#include "src/ra/raLogger.h"
#include "src/ra/raStorage.h"
#include "src/ra/raTelemetryPayload.h"
#include "src/ra/raConfig.h"


raLogger* logger;
raStorage* storage;
raTelemetryPayload * payload;
int count = 0;

bool stopTest;

long prevFreeHeap;
long prevFreeHeap2;

void setup() {
    Serial.begin(115200);

    // must be created in setup() in order to conserve RAM
    logger = new raLogger();
    storage = new raStorage( 100,  logger );
    payload = new raTelemetryPayload( storage, 15, logger );

    stopTest = false;

    prevFreeHeap = ESP.getFreeHeap();
    prevFreeHeap2 = prevFreeHeap;
}

void do_test()
{
    bool rc = true;
    count++;

    logger->log( "\n\n" );
    
    //---------------------------------------------------
    // basic logging test
    logger->log( "test of %s logging - number %d", "smart", count );

    //----------------------------------------------------
    rc = do_config_tests( logger );
    if( !rc ) { logger->log( "ERROR 3" ); stopTest = true; return;  }

    //---------------------------------------------------
    // storage tests - tests_storage.ino
    rc = do_storage_tests( storage, logger );
    if( !rc ) { logger->log( "ERROR 1" ); stopTest = true; return; }

    //---------------------------------------------------
    // telemetry payload tests - tests_telemetry_payload.ino
    rc = do_telemetry_payload_tests( storage, payload, logger );
    if( !rc ) { logger->log( "ERROR 2" ); stopTest = true; return;  }

    //---------------------------------------------------
    rc = do_crypto_tests();
    if( !rc ) { logger->log( "ERROR 2" ); stopTest = true; return;  }

    logger->log( "\n\nAll tests OK" );
}


void displayMem( int line )
{
    long freeHeap = ESP.getFreeHeap();
    long delta = freeHeap-prevFreeHeap2;
    prevFreeHeap2 = freeHeap;
    logger->log( ".... free heap: %d, delta %d [line %d]", freeHeap, delta, line );  
}


void loop() {
  if( !stopTest ) {
    do_test();

    long freeHeap = ESP.getFreeHeap();
    long delta = freeHeap-prevFreeHeap;
    prevFreeHeap = freeHeap;
    logger->log( "#%d, free heap: %d, delta %d", count, freeHeap, delta );
  }
  delay(3000);
}

/*
ESP8266 2.7.1:
Použití knihovny ESP8266HTTPClient ve verzi 1.2 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266HTTPClient 
Použití knihovny ESP8266WiFi ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WiFi 
Použití knihovny ESP8266WebServer ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\ESP8266WebServer 
Použití knihovny DNSServer ve verzi 1.1.1 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp8266\hardware\esp8266\2.7.1\libraries\DNSServer 

ESP32 1.0.4:
Použití knihovny WiFi ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WiFi 
Použití knihovny HTTPClient ve verzi 1.2 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\HTTPClient 
Použití knihovny WiFiClientSecure ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WiFiClientSecure 
Použití knihovny WebServer ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\WebServer 
Použití knihovny DNSServer ve verzi 1.1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\DNSServer 
Použití knihovny FS ve verzi 1.0 v adresáři: C:\Users\brouzda\AppData\Local\Arduino15\packages\esp32\hardware\esp32\1.0.4\libraries\FS 
*/
