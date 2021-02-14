#include "ratatoskr.h"
#include "../platform/platform.h"


ratatoskr::ratatoskr( raConfig * config, int logMode  )
{
    this->logger = new raLogger( logMode );
    this->conn = new raConnection( config, this->logger );
    this->storage = NULL;
    this->telemetry = NULL;
    this->lastErrTime = -2 * RA_PAUSE_AFTER_ERR;
    this->allDataPrepared = false;
}

void ratatoskr::createStorage(  int storageSize )
{
    this->storage = new raStorage( storageSize, this->logger );
    this->telemetry = new raTelemetryPayload( this->storage, RACONN_MAX_DATA, this->logger );
}

#ifdef ESP32
    void ratatoskr::createRtcRamStorage( unsigned char * dataPtr, int blockSize )
    {
        this->storage = new raStorage( dataPtr, blockSize, this->logger );
        this->telemetry = new raTelemetryPayload( this->storage, RACONN_MAX_DATA, this->logger );
    }
#endif

void ratatoskr::checkParam( void* ptr )
{
    if( ptr==NULL ) { 
        this->logger->log( "ERROR: no storage set!" ); 
    }
}

int ratatoskr::defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec, double prepoctovyFaktor )
{
    char buffer[256];
    sprintf( buffer, "%s|%f", deviceName, prepoctovyFaktor );
    return this->defineChannel(  deviceClass,  valueType, buffer,  msgRateSec );
}

int ratatoskr::defineChannel( int deviceClass, int valueType, char * deviceName, long msgRateSec )
{
    this->checkParam( this->telemetry );
    return this->telemetry->defineChannel( deviceClass, valueType, deviceName, msgRateSec );
}

/**
 * priority 1-15, higher is better
 */
int ratatoskr::postData( int channel, int priority, double value )
{
    this->checkParam( this->telemetry );
    return this->telemetry->send( channel, priority, value );
}

/**
 * priority 1-15, higher is better
 */ 
int ratatoskr::postImpulseData( int channel, int priority, long impulseTotalSum )
{
    this->checkParam( this->telemetry );
    return this->telemetry->sendImpulse( channel, priority, impulseTotalSum );
}

bool ratatoskr::hasData()
{
    this->checkParam( this->storage );
    return this->storage->hasData();
}

bool ratatoskr::process()
{
    this->checkParam( this->storage );

    bool necoPoslano = false;
    
    if( (millis() - this->lastErrTime) < RA_PAUSE_AFTER_ERR ) {
        // this->logger->log( "err delay %d", millis() - this->lastErrTime );
        return false;
    }
    
    this->storage->startReading();
    raStorageRecord* rec = this->storage->readNext();
    if( rec!=NULL) {

        // je potreba pouzivat time(), protoze ten funguje na ESP32 v deep sleep!
        int nyni = time(NULL);
        
        unsigned char data[RACONN_MAX_DATA+10];
        int maxLen = RACONN_MAX_DATA;
        int pos = 0;
        int len = 3;
        int recs = 0;

        data[0] = (unsigned char)( (nyni >> 16) & 0xff );
        data[1] = (unsigned char)( (nyni >> 8) & 0xff );
        data[2] = (unsigned char)( nyni & 0xff );
        pos = 3;
        
        while( rec!=NULL ) {
            //D/ this->logger->log( "%s pridavam zaznam #%d len=%d na pos=%d", this->identity, recs, rec->data_length, pos );
            if( pos + rec->data_length + 2 >= maxLen ) {
                //D/ this->logger->log( "%s nepridavam, moc dat", this->identity );
                break;
            }
            data[pos++] = rec->data_length;
            memcpy( data+pos, rec->data, rec->data_length );
            len += rec->data_length + 1;
            pos += rec->data_length;
            recs++;

            rec->markAsDeleted();
            rec = this->storage->readNext();
        }
        data[pos++] = 0;

        this->logger->log( "%s recs:%d len:%d", this->identity, recs, len );
        if( 0 == conn->send( data, len ) ) {
            necoPoslano = true; 
            this->storage->purgeDeleted();
        } else {
            this->logger->log( "%s not sent", this->identity );
            this->lastErrTime = millis();
            this->storage->undeleteAll();
        }
    } else if( this->storage->hasData() ) {
        this->logger->log( "??? ratatoskr E101");
    }
    return necoPoslano;
}

void ratatoskr::setAllDataPreparedFlag()
{
    this->allDataPrepared = true;
}

void ratatoskr::clearAllDataPreparedFlag()
{
    this->allDataPrepared = false;
}

bool ratatoskr::isAllDataPrepared()
{
    return this->allDataPrepared;
}

void ratatoskr::deepSleep( long usec )
{
    this->logger->log( "deep sleep for %d s, uptime %d.%d s, time %d s", (usec/1000000), (millis()/1000), (millis()%1000)/100, time(NULL) );
    ra__DeepSleep( usec );
}

#ifdef ESP32
void ratatoskr::lightSleep( long usec, bool logInfo )
{
    if (logInfo) {
        this->logger->log( "light sleep for %d sec...", (usec/1000000));
    } 
    ra__LightSleep( usec );
    if (logInfo) {
        this->logger->log( "woke!" );
    }
}
#endif


int ratatoskr::sendBlob( unsigned char * blob, int blobSize, int startTime, char * desc, char * extension  )
{
    return this->conn->sendBlob( blob, blobSize, startTime, desc, extension );
}

bool ratatoskr::isConnected()
{
    return this->conn->isConnected();
}

int ratatoskr::getStorageUsage()
{
    return this->storage->getUsage();
}