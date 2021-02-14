
#include <stdlib.h> 
#include "raTelemetryPayload.h"
#include "../math/dtostrg.h"
#include "../platform/platform.h"

// #define LOG_DETAIL


#define MAX_PRIORITY 15
#define CHANEL_DEFINE_CHANNEL 0


#ifdef ESP32
    /*
     Kesovani definice kanalu v RTC mem, aby se usetrily requesty.
     Jen pro ESP32, to ma dost RTC mem.
     U ESP8266 se vzdy posle vse.
    */
    
    // budeme kesovat max 256 byte; pokud bude vice kanalu, holt se ty dalsi budou vzdy posilat na server
    #define RACHANNELS_BUFFER_SIZE 256
    
    RTC_DATA_ATTR long rtcRaIndicator;
    RTC_DATA_ATTR int rtcMaxChannelId;
    RTC_DATA_ATTR int rtcChannelsSize;  
    RTC_DATA_ATTR unsigned char rtcChannels[RACHANNELS_BUFFER_SIZE];
    
    // najde kanal v kesi nebo vrati -1
    int raTplGetChannelFromCache( char * deviceName, raLogger* logger )
    {
        int i = 0;
        
        while( i<rtcChannelsSize )
        {
            int ch = rtcChannels[i];
            //D/ logger->log( "? ch cache pos=%d, ch=%d", i, ch );
            if( ch==0 ) break;
            //D/ logger->log( "? ch [%s]", rtcChannels+i+1 );
            if( strcmp((const char*)(rtcChannels+i+1), (const char*)deviceName) == 0 ) {
                return ch;
            }
            int l = strlen( (const char*)(rtcChannels+i+1) );
            i += l+2; 
        }
        
        return -1;
    }
     
    // zapise kanal do kese
    void raTplSaveChannelToCache( char * deviceName, int channel, raLogger* logger )
    {
        int l = strlen( (const char*)deviceName );
        if( (l+rtcChannelsSize+2) > RACHANNELS_BUFFER_SIZE ) {
            return;
        }
        //D/ logger->log( "+ ch cache save [%s] to %d", deviceName, rtcChannelsSize );
        rtcChannels[rtcChannelsSize] = (unsigned char) channel;
        strcpy( (char*)rtcChannels+rtcChannelsSize+1, (const char*)deviceName );
        rtcChannelsSize += l+2;
        rtcChannels[rtcChannelsSize]=0;
    }
#endif    

#define RA_RTCRAM_VALID_DATA_MARKER 0xdeadface


/*
 * - payloadSize is max payload size (internal buffer size_)
 */
raTelemetryPayload::raTelemetryPayload( raStorage * storage, int payloadSize, raLogger* logger )
{
  this->logger = logger;
  this->storage = storage;
  this->maxPayloadSize = payloadSize;
  this->data = (unsigned char *)malloc( maxPayloadSize + TPLD_HEADER_SIZE + 1 );
  this->maxChannelId = 0;
  this->impulseDataSessionId = (trng()) & 0xffffff;
  
  #ifdef ESP32
        /*
        Kesovani definice kanalu v RTC mem, aby se usetrily requesty.
        Jen pro ESP32, to ma dost RTC mem.
        U ESP8266 se vzdy posle vse.  
        */
      if( rtcRaIndicator!=RA_RTCRAM_VALID_DATA_MARKER) {
          rtcRaIndicator = RA_RTCRAM_VALID_DATA_MARKER;
          rtcChannelsSize = 0;
          rtcChannels[0] = 0;
          rtcChannels[1] = 0;
          rtcMaxChannelId = 0;
      } else {
          this->maxChannelId = rtcMaxChannelId;
      }        
  #endif
  
// this->logger->log( "%s raTelemetryPayload, payloadSize=%d", this->identity, payloadSize );
}


/*
 * priority = priority 1-15, higher is better

 * returns:
 *    >0 ... record stored into storage (RA_OK_STORED or RA_OK_STORED_DELETED)
 *    0 .... record not stored, not enough space
 *    <0 ... error
 */
int raTelemetryPayload::storeRecord( int priority, int channel, unsigned long msgTimeMsec, int payloadLength, unsigned char * payload )
{
  if( channel<=0 || channel>255 ) {
    this->logger->log( "%s Invalid channel %d", this->identity, channel );
    return RA_ERR_CHANNEL_INVALID;
  }
  if( payloadLength<=0 || payloadLength>this->maxPayloadSize ) {
    this->logger->log( "%s Invalid payloadLength %d", this->identity, payloadLength );
    return RA_ERR_INVALID_LENGTH;
  }

  return this->intStoreRecord( priority, channel, msgTimeMsec, payloadLength, payload );
}

int raTelemetryPayload::intStoreRecord( int priority, int channel, unsigned long msgTimeSec, int payloadLength, unsigned char * payload )
{
  this->data[0] = (unsigned char)( channel );
  this->data[1] = (unsigned char)( (msgTimeSec>>16) & 0xff  );
  this->data[2] = (unsigned char)( (msgTimeSec>>8) & 0xff  );
  this->data[3] = (unsigned char)( msgTimeSec & 0xff );
  memcpy( this->data + TPLD_HEADER_SIZE, payload, payloadLength );
  return storage->storeData( priority, payloadLength + TPLD_HEADER_SIZE,  this->data );
}

/**
 * 1 ...... OK
 * <=0 .... error
 */
int raTelemetryPayload::parseRecord( raStorageRecord * rec )
{
  this->priority = rec->priority;
  this->channel = (int)(rec->data[0]);
  this->msgTime = (rec->data[1]<<16) | (rec->data[2]<<8) | rec->data[3];
  this->payloadLength  = rec->data_length - TPLD_HEADER_SIZE;
  this->payload = rec->data+TPLD_HEADER_SIZE;
  
  return RA_TPLD_OK;
}





#define CHANNEL_DEF_HDR_LEN 7

/* channel definition:
b0 - deviceClass
b1 - valueType
b2 b3 b4 msgRate
b5 - deviceName len
b6 - channel ID
b7... - device name - NO \0 at end
*/
int raTelemetryPayload::defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec )
{
    int channel = -1;
    
#ifdef ESP32
    channel = raTplGetChannelFromCache( deviceName, logger );
    if( channel!=-1) {
        this->logger->log( "%s cached ch #%d for [%s]", this->identity, channel, deviceName );
        return channel;
    }
#endif

    this->maxChannelId++;
    channel = this->maxChannelId;
        
    int len = strlen( deviceName ); 
    unsigned char chanDef[100];
    chanDef[0] = deviceClass & 0xff;
    chanDef[1] = valueType & 0xff;
    chanDef[2] = (unsigned char)( (msgRateSec>>16) & 0xff );
    chanDef[3] = (unsigned char)( (msgRateSec>>8) & 0xff );
    chanDef[4] = (unsigned char)( msgRateSec & 0xff );
    chanDef[5] = (unsigned char) this->maxChannelId;
    chanDef[6] = len & 0xff;
    strcpy( (char*)chanDef+CHANNEL_DEF_HDR_LEN, deviceName );
    
    if( RA_NOT_STORED == this->intStoreRecord( MAX_PRIORITY, CHANEL_DEFINE_CHANNEL, 0, CHANNEL_DEF_HDR_LEN+len, chanDef ) )
    {
        this->logger->log( "%s ERR can't store channel def", this->identity  );
        channel = -1;
    } else {                                                           
        this->logger->log( "%s ch #%d for [%s]", this->identity, channel, deviceName );
    }
    
    #ifdef ESP32
        rtcMaxChannelId = this->maxChannelId;
        if( channel!=-1 ) {
            raTplSaveChannelToCache( deviceName, channel, logger );
        }
    #endif
    
    return channel;
}



int raTelemetryPayload::send( int channel, int priority, double value )
{
    unsigned char data[30];

    // +++ number to string conversion
    
    // fixed precision:
    // dtostrf( value, 10, 10, (char*) data);
    
    // exponential:
    dtostre( value, (char*)data, 6, 0 );
    
    // --- number to string conversion 
    
    // je potreba pouzivat time(), protoze ten funguje na ESP32 v deep sleep!
    int rc = this->intStoreRecord( priority, channel, time(NULL), strlen((const char *)data), data );
    if( rc == RA_NOT_STORED )
    {
        this->logger->log( "%s ERR data lost", this->identity );
    } else {  
        //D                                                         
        this->logger->log( "%s #%d <= %s", this->identity, channel, data ); 
    } 
    
    return rc;
}


int raTelemetryPayload::sendImpulse( int channel, int priority, long value )
{
    unsigned char data[30];

    sprintf( (char*)data, "%d;%x", value, this->impulseDataSessionId );
    
    // je potreba pouzivat time(), protoze ten funguje na ESP32 v deep sleep!
    int rc = this->intStoreRecord( priority, channel, time(NULL), strlen((const char *)data), data );
    if( rc == RA_NOT_STORED )
    {
        this->logger->log( "%s ERR can't store data", this->identity );
    } else {  
        //D                                                         
        this->logger->log( "%s #%d <= %s", this->identity, channel, data ); 
    } 
    
    return rc;
}