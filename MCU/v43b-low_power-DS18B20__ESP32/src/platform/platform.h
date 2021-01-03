#ifndef RA_PLATFORM_H
#define RA_PLATFORM_H

void ra__DeepSleep( long usec );
int trng();

#ifdef ESP8266
    #define ESP_ISR_RAM ICACHE_RAM_ATTR
#endif
#ifdef ESP32    
    #define ESP_ISR_RAM IRAM_ATTR
#endif    

#ifdef ESP32
    void ra__LightSleep( long usec );
#endif

#endif