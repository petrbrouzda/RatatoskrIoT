#ifndef RA_CORE_H
#define RA_CORE_H

#include "raLogger.h"
#include "raConfig.h"
#include "raConnection.h"
#include "raStorage.h"
#include "raTelemetryPayload.h"

#define RA_CORE_VERSION "5.1.2"

// po chybe odesilani se nezkousi komunikovat po urcitou dobu
#define RA_PAUSE_AFTER_ERR 15000

class ratatoskr 
{
  public:
    raLogger* logger;
    raConnection* conn;
    raTelemetryPayload * telemetry;
    raStorage * storage;

    /**
     * Po vytvoreni je nutne jeste nastavit storage - bud createStorage() nebo createRtcRamStorage()
     */ 
    ratatoskr( raConfig * config, int logMode );

    /**
     * Vytvori storage v RAM
     */ 
    void createStorage(  int storageSize );

    #ifdef ESP32
      /**
       * Vytvori storage v RTC RAM - jen ESP32
       */
      void createRtcRamStorage( unsigned char * dataPtr, int blockSize );
    #endif

    /**
     * Definuje telemetricky kanal
     */ 
    int defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec ); 

    /**
     * Definuje telemetricky kanal s prepoctovym faktorem
     */ 
    int defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec, double prepoctovyFaktor ); 

    /**
     * priority 1-15, higher is better
     */
    int postData( int channel, int priority, double value );

    /**
     * priority 1-15, higher is better
     */
    int postImpulseData( int channel, int priority, long impulseTotalSum );
    int sendBlob( unsigned char * blob, int blobSize,  int startTime, char * desc, char * extension );
    bool process();
    bool hasData();
    void setAllDataPreparedFlag();
    void clearAllDataPreparedFlag();
    bool isAllDataPrepared();
    void deepSleep( long usec );
    
    #ifdef ESP32
        /**
         * usec - time to sleep
         * logInfo - true=will print debug info
         */ 
        void lightSleep( long usec, bool logInfo );
    #endif
    
    bool isConnected();
    int getStorageUsage();
    
  private:
    char * identity = (char*)"RA"; 
    long lastErrTime;
    bool allDataPrepared;  
    void checkParam( void* ptr );
};

#endif

