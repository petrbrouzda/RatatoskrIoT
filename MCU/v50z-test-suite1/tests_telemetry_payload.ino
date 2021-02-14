#include "src/ra/raLogger.h"
#include "src/ra/raStorage.h"
#include "src/ra/raTelemetryPayload.h"


/*
 * Tests for raTelemetryPayload().
 * Called from void setup()
 */

bool do_telemetry_payload_tests( raStorage* storage, raTelemetryPayload * payload, raLogger * logger ) 
{
    bool rc;

    logger->log( "\n\ndo_telemetry_payload_tests:" );

    storage->cleanup();

    rc = test_saveAndLoad1( logger, storage, payload );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_paramValidation( logger, storage, payload );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    logger->log( "\n\nAll telemetry_payload tests OK" );
    return true;
}

bool test_saveAndLoad1( raLogger * logger, raStorage * storage, raTelemetryPayload * payload ) 
{
    unsigned char * data = (unsigned char *)"AHOJ";
    unsigned char * data2 = (unsigned char *)"ahoj5aho10ah14";
    int rc;
    raStorageRecord * rec;
  
    logger->log( "\ntest_saveAndLoad1:" );
    
    rc = payload->storeRecord( 1, 2, 0xf0f102f3, strlen((const char*)data)+1, data );
    if( rc<=0 ) { logger->log( "ERROR: storeRecord = %d", rc ); return false; }

    rc = payload->storeRecord( 1, 2, 0xf0f102f3, strlen((const char*)data2)+1, data2 );
    if( rc<=0 ) { logger->log( "ERROR: storeRecord = %d", rc ); return false; }

    storage->startReading();
    if( (rec = storage->readNext()) !=NULL ) {

        rc = payload->parseRecord(rec);
        if( rc!=RA_TPLD_OK ) { logger->log( "ERROR: parseRecord = %d", rc ); return false; }

        if( payload->priority != 1 ) { logger->log( "ERROR: priority = %d", payload->priority ); return false; }
        if( payload->channel != 2 )  { logger->log( "ERROR: channel = %d", payload->channel ); return false; }
        if( payload->msgTime != 0xf102f3 ) { logger->log( "ERROR: msgTime = %lx", payload->msgTime ); return false; }
        if( payload->payloadLength != strlen((const char *)data)+1 )  { logger->log( "ERROR: payloadLength = %d", payload->payloadLength ); return false; }
        if( strcmp( (const char*)data, (const char*)payload->payload ) != 0 )  { logger->log( "ERROR: invalid data %s", payload->payload ); return false; }

        logger->log( "OK 1" );
        
    } else { logger->log( "ERROR: no record found" ); return false; }
    
    if( (rec = storage->readNext()) !=NULL ) {

        rc = payload->parseRecord(rec);
        if( rc!=RA_TPLD_OK ) { logger->log( "ERROR: parseRecord = %d", rc ); return false; }

        if( strcmp( (const char*)data2, (const char*)payload->payload ) != 0 )  { logger->log( "ERROR: invalid data %s", payload->payload ); return false; }

        logger->log( "OK 2" );
        
    } else { logger->log( "ERROR: no record found" ); return false; }
    
    if( storage->readNext() != NULL ) { logger->log( "ERROR: too many records" ); return false; }
    
    storage->cleanup();

    logger->log( "OK" );
    
    return true;
}

bool test_paramValidation( raLogger * logger, raStorage * storage, raTelemetryPayload * payload ) 
{
      unsigned char * data = (unsigned char *)"AHOJ";
      unsigned char * data2 = (unsigned char *)"ahoj5aho10aho15aho20";
    int rc;
    raStorageRecord * rec;
  
    logger->log( "\ntest_paramValidation:" );

    // channel ID -1 - should not work
    rc = payload->storeRecord( 1, -1, 0xf0f102f3, strlen((const char*)data), data );
    if( rc!=RA_ERR_CHANNEL_INVALID ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_CHANNEL_INVALID , rc ); return false; }

    // channel ID 0 - should not work
    rc = payload->storeRecord( 1, 0, 0xf0f102f3, strlen((const char*)data), data );
    if( rc!=RA_ERR_CHANNEL_INVALID ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_CHANNEL_INVALID , rc ); return false; }

    // channel ID 256 - should not work
    rc = payload->storeRecord( 1, 256, 0xf0f102f3, strlen((const char*)data), data );
    if( rc!=RA_ERR_CHANNEL_INVALID ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_CHANNEL_INVALID , rc ); return false; }
    
    // channel ID 1 - should work
    rc = payload->storeRecord( 1, 1, 0xf0f102f3, strlen((const char*)data), data );
    if( rc!=RA_OK_STORED ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_OK_STORED , rc ); return false; }

    // channel ID 255 - should work
    rc = payload->storeRecord( 1, 255, 0xf0f102f3, strlen((const char*)data), data );
    if( rc!=RA_OK_STORED ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_OK_STORED , rc ); return false; }


    // payload length -1 - should not work
    rc = payload->storeRecord( 1, 2, 0xf0f102f3, -1, data );
    if( rc!=RA_ERR_INVALID_LENGTH ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_INVALID_LENGTH , rc ); return false; }
    
    // payload length 0 - should not work
    rc = payload->storeRecord( 1, 2, 0xf0f102f3, 0, data );
    if( rc!=RA_ERR_INVALID_LENGTH ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_INVALID_LENGTH , rc ); return false; }
    
    // payload length 16 - should not work
    rc = payload->storeRecord( 1, 2, 0xf0f102f3, 16, data );
    if( rc!=RA_ERR_INVALID_LENGTH ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_INVALID_LENGTH , rc ); return false; }
    
    // payload length 15 - should work
    rc = payload->storeRecord( 1, 2, 0xf0f102f3, 15, data );
    if( rc!=RA_OK_STORED  ) { logger->log( "ERROR: storeRecord should return %d, returned %d", RA_ERR_INVALID_LENGTH , rc ); return false; }


    storage->cleanup();

    logger->log( "OK" );
    
    return true;
}
