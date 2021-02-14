#include "AppConfig.h"
#include "ConfigProvider.h"

#if defined(ESP32)
  #include <SPIFFS.h>
#endif

bool isConfigValid( raConfig * cfg )
{
    if( 
        NULL == cfg->getString( "wifi_ssid", NULL ) ||
        NULL == cfg->getString( "$wifi_pass", NULL ) ||
        NULL == cfg->getString( "ra_url", NULL ) ||
        NULL == cfg->getString( "ra_dev_name", NULL ) ||
        NULL == cfg->getString( "$ra_pass", NULL ) 
    ) 
        return false;
    else
        return true;
}

void loadConfig( raConfig * config )
{
  // true na ESP32 = format on fail. Bez toho neprobehne prvotni incializace u ESP-32.
  // na ESP8266 parametr neni
  if (! SPIFFS.begin(
#ifdef ESP32    
      true
#endif
  )) {
    logger->log("@ failed to mount FS: nastavte rozdeleni flash, aby tam bylo alespon 1M SPIFS");
    return;
  }

  const char * fileName = CONFIG_FILE_NAME;
   
  if (! SPIFFS.exists( fileName )) {
    logger->log("@ config file not found: %s", fileName);
    return;
  }
  
  File configFile = SPIFFS.open( fileName, "r");
  if( !configFile) {
    logger->log("@ problem opening config file: %s", fileName);
    return; 
  }

  // Allocate a buffer to store contents of the file.
  size_t size = configFile.size();
  char * buf = (char*)malloc( size+1 );
  configFile.readBytes(buf, size);
  buf[size] = 0;

  config->parseFromString( buf );
  free( buf );

  // logger->log("@ config loaded");
}

void saveConfig( raConfig * cfg )
{
    logger->log("@ saving config");
    
    File configFile = SPIFFS.open( CONFIG_FILE_NAME, "w");
    if (!configFile) {
        logger->log("@ failed to write config file");
    } else {
        cfg->printTo(configFile);
        configFile.close();
        logger->log("@ config saved");
    }
}
