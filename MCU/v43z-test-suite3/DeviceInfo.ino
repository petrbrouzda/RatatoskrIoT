#include "DeviceInfo.h"

#if defined(ESP32)

  #include <ESP.h>
  
  extern "C" {
    #include <esp_spiram.h>
    #include <esp_himem.h>
  }
  
  void formatMemorySize( char * ptr, long msize )
  {
    float out;
    char * jednotka;
    if( msize > 1048576 ) {
      out = ((float)msize) / 1048576.0;
      jednotka = (char*)"MB";
    } else if( msize > 1024 ) {
      out = ((float)msize) / 1024.0;
      jednotka = (char*)"kB";
    } else if( msize > 0 ) {
      out = ((float)msize);
      jednotka = (char*)"B";
    } else {
      out = 0;
    }
  
    if( out>0 ) {
      sprintf( ptr, "%.1f %s", out, jednotka );
    } else {
      strcpy( ptr, "--" );
    }
  }
  
  #define LOW_PSRAM_ONLY
  
  void formatDeviceInfo( char * out )
  {
    strcpy( out, "RAM " );
    formatMemorySize( out+strlen(out), ESP.getHeapSize() );
  
  #ifdef LOW_PSRAM_ONLY
  
    strcat( out, "; PSRAM " );
    formatMemorySize( out+strlen(out), ESP.getPsramSize() );
  
  #else
  
    long psramtotal = esp_spiram_get_size();
    strcat( out, "; PSRAM [" );
    formatMemorySize( out+strlen(out), psramtotal );
    if( psramtotal>0 ) {
      strcat( out, ": low: " );
      formatMemorySize( out+strlen(out), ESP.getPsramSize() );
      strcat( out, ", high: " );
      formatMemorySize( out+strlen(out), esp_himem_get_phys_size() );
    }
    strcat( out, "]" );
    
  #endif
  
    sprintf( out+strlen(out), "; CPUr%d %d MHz; flash %d MHz; RA %s", 
            ESP.getChipRevision(),
            ESP.getCpuFreqMHz(), 
            (ESP.getFlashChipSpeed()/1000000),
            RA_CORE_VERSION
           );
  }


/*
const char * wakeupReasonText( int wakeup_reason )
{
  switch(wakeup_reason)
  {
    case 1  : return "external RTC_IO"; 
    case 2  : return "external RTC_CNTL"); 
    case 3  : return "timer"); 
    case 4  : return "touchpad"); 
    case 5  : return "ULP program"); 
    default : return "deep sleep"); 
  }
}
*/

#endif


/**
 * Pokud nejde o probuzeni po deep sleepu, vypise informaci o duvodu restartu, aplikaci a ESP cipu.
 * 
 * Zapise kod typu restartu do globalni promenne 'wakeupReason'
 * a do 'deepSleepStart' zapise bool, zda jde o probuzeni z deep sleep.
 */ 
void read_wakeup_and_print_startup_info( bool print, const char * appInfo )
{
  #if defined(ESP8266)

    // https://www.espressif.com/sites/default/files/documentation/esp8266_reset_causes_and_common_fatal_exception_causes_en.pdf
    wakeupReason = ESP.getResetInfoPtr()->reason;
    if( wakeupReason != 5 ) {
      deepSleepStart = false;
      if( print ) {
        // pokud to NENI probuzeni po deep sleep, vypiseme info
        logger->log( "rst rsn %d; CPU %d MHz; RA %s", wakeupReason, ESP.getCpuFreqMHz(), RA_CORE_VERSION );
        logger->log( "app: %s", appInfo );
      }
    } else {
      deepSleepStart = true;
    }

  #elif defined(ESP32)

    esp_sleep_wakeup_cause_t wakeup_reason = esp_sleep_get_wakeup_cause();
    wakeupReason = (int)wakeup_reason;
    if( wakeupReason!=4 ) {
      // pokud to NENI probuzeni po deep sleep, vypiseme info
      deepSleepStart = false;
      if( print ) {
        char buff[120];
        formatDeviceInfo( buff );
        logger->log( "rst rsn %d; %s", wakeupReason, buff );
        logger->log( "app: %s", appInfo );
      }
    } else {
      deepSleepStart = true;
    }

  #endif
}

/**
 * Vraci preklad wifi statusu na text.
 * Pole 'desc' musi mit alespon 32 byte
 */
char * getWifiStatusText( int status, char * desc )
{
  switch ( status ) {
    case WL_IDLE_STATUS:      strcpy( desc, (char*)"IDLE" ); break;                // ESP32,  = 0
    case WL_NO_SSID_AVAIL:    strcpy( desc, (char*)"NO_SSID" ); break;             // WL_NO_SSID_AVAIL    = 1,
    case WL_SCAN_COMPLETED:   strcpy( desc, (char*)"SCAN_COMPL" ); break;          // WL_SCAN_COMPLETED   = 2,
    case WL_CONNECT_FAILED:   strcpy( desc, (char*)"CONN_FAIL" ); break;           // WL_CONNECT_FAILED   = 4,
    case WL_CONNECTION_LOST:  strcpy( desc, (char*)"CONN_LOST" ); break;           // WL_CONNECTION_LOST  = 5,
    case WL_DISCONNECTED:     strcpy( desc, (char*)"DISC" ); break;                // WL_DISCONNECTED     = 6
    case WL_NO_SHIELD:        strcpy( desc, (char*)"NO_SHIELD" ); break;           // ESP32, = 255
    case WIFI_POWER_OFF:      strcpy( desc, (char*)"OFF" ); break;
    default: sprintf( desc, "%d", status ); break;
  }
  return desc;
}
