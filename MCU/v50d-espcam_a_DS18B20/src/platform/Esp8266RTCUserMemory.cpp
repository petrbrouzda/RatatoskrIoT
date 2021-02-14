// source:
// https://github.com/esp8266/Arduino/blob/master/libraries/esp8266/examples/RTCUserMemory/RTCUserMemory.ino

#if defined(ESP8266)

  #include <stdlib.h>
  #include <Esp.h>
  #include "Esp8266RTCUserMemory.h"
  #include "../aes-sha/CRC32.h"
  
  // Struct data with the maximum size of 512 bytes can be stored
  // in the RTC memory using the ESP-specifc APIs.
  // The stored data can be retained between deep sleep cycles.
  // However, the data might be lost after power cycling the ESP8266.
  // Created Mar 30, 2016 by Macro Yau.
  // This example code is in the public domain.
  
  bool readRtcData( struct rtcData * data )
  {
    if (ESP.rtcUserMemoryRead(0, (uint32_t*) data, RTCDATA_SIZE )) {
      uint32_t crcOfData = CRC32::calculate((unsigned char*)(data->data), RTCDATA_PAYLOAD_SIZE );
      if (crcOfData == data->crc32) {
        return true;
      }
    }
    return false;
  }
  
  void saveRtcData( struct rtcData * data )
  {
    data->crc32 = CRC32::calculate((unsigned char*)(data->data), RTCDATA_PAYLOAD_SIZE );
    ESP.rtcUserMemoryWrite(0, (uint32_t*) data, RTCDATA_SIZE );
  }
  
#endif  
