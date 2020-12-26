
#ifndef RA_TELEMETRY_PAYLOAD_H
#define RA_TELEMETRY_PAYLOAD_H

#include <stdio.h>
#include <stdlib.h>
#include <pgmspace.h>

#include "raLogger.h"
#include "raStorage.h"

//----- return codes and errors

// channel number is invalid
#define RA_ERR_CHANNEL_INVALID -11 

// data read from storage are not consistent
#define RA_ERR_INCONSISTENT_DATA -12

#define RA_TPLD_OK 0

//------


//------- channel definitions

// continuous floating point data with postprocessing - min, max and avg values for hours and days will be generated
#define DEVCLASS_CONTINUOUS_MINMAXAVG 1

// continuous floating point data without postprocessing (only raw data will be stored)
#define DEVCLASS_CONTINUOUS 2 

// Counting of impulses - with postprocessing. Impulses will be summed per hour and day.
// Device is expected to sum impulses within one session.
#define DEVCLASS_IMPULSE_SUM 3       

//--------



/*
  up to 255 channels (channel 0 is device configuration)
  data structure:
      B0 = channel
      B1..B3 = message time (sec from boot), high endian
      B4... = payload data

  Payload size and priority are used from underlying raStorage.
*/

// header size
#define TPLD_HEADER_SIZE 4


class raTelemetryPayload {
  public:
    raTelemetryPayload( raStorage * storage, int payloadSize, raLogger* logger );
    int storeRecord( int priority, int channel, unsigned long msgTime, int payloadLength, unsigned char * payload );
    raStorage* storage;
    
    // returns channel ID
    int defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec ); 
    
    int send( int channel, int priority, double value );
    int sendImpulse( int channel, int priority, long value );

    int parseRecord( raStorageRecord * rec );
    int channel;
    int payloadLength;
    unsigned long msgTime;
    unsigned char * payload;
    int priority;

  private:
    int intStoreRecord( int priority, int channel, unsigned long msgTime, int payloadLength, unsigned char * payload );
    
    int maxChannelId;

    int maxPayloadSize;
    unsigned char* data;
    raLogger* logger;
    char * identity = (char*)"TP";
    int impulseDataSessionId;

};


#endif   // RA_STORAGE_H
