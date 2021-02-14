#ifndef RTCUSERMEMORY_H_DEFINED
#define RTCUSERMEMORY_H_DEFINED

#if defined(ESP8266)

  typedef unsigned int uint32_t;
  
  // Structure which will be stored in RTC memory.
  // First field is CRC32, which is calculated based on the
  // rest of structure contents.
  // Any fields can go after CRC32.
  // We use byte array as an example.
  
  // max 508
  #define RTCDATA_PAYLOAD_SIZE 64
  #define RTCDATA_SIZE (RTCDATA_PAYLOAD_SIZE+4)
   
  struct rtcData {
    uint32_t crc32;
    unsigned char data[RTCDATA_PAYLOAD_SIZE];
  };
  
  bool readRtcData( struct rtcData * data );
  void saveRtcData( struct rtcData * data );   

#endif 

#endif
