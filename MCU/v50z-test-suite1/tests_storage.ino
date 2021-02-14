
#include "src/ra/raLogger.h"
#include "src/ra/raStorage.h"
#include "src/ra/raTelemetryPayload.h"


/*
 * Tests for raStorage().
 * Called from void setup()
 */

// 10 bytes (with terminating zero)
char testString[] = "ABCDEFGHI";
char testString2[] = "abcdefghijk";
char testString3[] = "abcdefghijklmnopqrstuvwxyz";

bool do_storage_tests( raStorage* storage, raLogger * logger ) 
{
    bool rc;

    rc = test_isEmpty( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }
    
    rc = test_invalidPriorities( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }
    
    rc = test_invalidSizes( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }
    
    rc = test_fillStorage( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_traverseStorage1( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_traverseStorage2( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_purge( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_addNext( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_fillStorage2( logger, storage );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    storage->cleanup();

    logger->log( "\n\nAll storage tests OK" );
    return true;
}

bool test_isEmpty( raLogger * logger, raStorage * storage ) 
{
    if( storage->hasData() ) {
        logger->log( "ERROR: hasData should return FALSE" );
        return false;
    }

    storage->startReading();
    if( NULL != storage->readNext() ) {
        logger->log( "ERROR: readNext should return NULL" );
        return false;
    }

    return true;
}


void dumpRecord( raLogger * logger, int i, raStorageRecord * rec )
{
        logger->log( "  %d len=%d prio=%d [%s] %s",
            i,
            rec->data_length,
            rec->priority,
            rec->data,
            rec->markedForDeletion ? "(deleted)" : ""
        );
}

void dumpStorage( raLogger * logger, raStorage * storage ) 
{
    int i = 0;
    raStorageRecord * rec;

    logger->log( "Storage:" );
    storage->startReading();
    i=0;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
    }
}

bool test_traverseStorage1( raLogger * logger, raStorage * storage ) 
{
    if( ! storage->hasData() ) {
        logger->log( "ERROR: hasData should return TRUE" );
        return false;
    }

    logger->log( "\nTraversing data 1:" );
    
    storage->startReading();
    int i = 0;
    raStorageRecord * rec;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( (16-i) != rec->priority ) {
            logger->log( "ERROR: bad prio" );
            return false;
        }
        if( strcmp( (const char*)testString, (const char*)rec->data ) != 0 ) {
            logger->log( "ERROR: bad text" );
            return false;
        }
    }
    if( i!=8 ) {
        logger->log( "ERROR: should read 8 records" );
        return false;
    }

    return true;
}


bool test_traverseStorage2( raLogger * logger, raStorage * storage ) 
{
    logger->log( "\nTraversing data 2:" );

    logger->log( "pass 1 - delete records 5-7:" );
    int i = 0;
    raStorageRecord * rec;

    // deleting record 11, 12, 13
    storage->startReading();
    i=0;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority>=11 && rec->priority<=13) {
            rec->markAsDeleted();
        }
    }

    logger->log( "pass 2 - check if the record is marked correctly" );
    i = 0;
    storage->startReading();
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority>=11 && rec->priority<=13 ) {
            if( !  rec->markedForDeletion ) { logger->log( "ERROR: should be marked as deleted" ); return false; }
        } else {
            if( rec->markedForDeletion ) { logger->log( "ERROR: should not be marked as deleted" ); return false; }
        }
    }

    logger->log( "pass 3 - undelete record 12:" );
    storage->startReading();
    i=0;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority==12) {
            rec->markAsNotDeleted();
        }
    }

    logger->log( "pass 4 - check if the record is marked correctly" );
    i = 0;
    storage->startReading();
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority==11 || rec->priority==13 ) {
            if( !  rec->markedForDeletion ) { logger->log( "ERROR: should be marked as deleted" ); return false; }
        } else {
            if( rec->markedForDeletion ) { logger->log( "ERROR: should not be marked as deleted" ); return false; }
        }
    }

    logger->log( "pass 5 - undelete all" );
    storage->undeleteAll();
    
    logger->log( "pass 6 - check if the record is marked correctly" );
    i = 0;
    storage->startReading();
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->markedForDeletion ) { logger->log( "ERROR: should not be marked as deleted" ); return false; }
    }

    return true;
}


bool test_fillStorage( raLogger * logger, raStorage * storage ) {
    logger->log(  "\nCorrect storage use:" );

    for( int i = 15; i>0; i-- ) {
        int rc = storage->storeString( i, testString );
        bool isOK = true;
        if( i>=8 && rc!=1 ) isOK=false;
        if( i<8 && rc!=0 ) isOK=false;
        logger->log( "%d: rc=%d, %s", i, rc, isOK ? "OK" : "ERROR" );

        if( !isOK ) return false;
    }
}

bool test_purge(raLogger * logger, raStorage * storage ) 
{
    logger->log( "\nPurge test");

    logger->log( "pass 1 - delete records 8, 12, 13, 15:" );
    int i = 0;
    raStorageRecord * rec;

    // deleting records 
    storage->startReading();
    i=0;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority==8 || rec->priority==12 || rec->priority==13 || rec->priority==15 ) {
            rec->markAsDeleted();
        }
    }

    logger->log( "purge" );
    storage->purgeDeleted();

    logger->log( "pass 2 - should not be there" );
    storage->startReading();
    i=0;
    while( rec = storage->readNext() ) {
        i++;
        dumpRecord( logger, i, rec );
        if( rec->priority==8 || rec->priority==12 || rec->priority==13 || rec->priority==15 ) {
            logger->log( "ERROR should not be there" );
            return false;
        }
    }
    return true;
}

bool test_addNext( raLogger * logger, raStorage * storage ) {
    int rc;
    
    logger->log(  "\nAdd record with higher prio:" );

    rc = storage->storeString( 10, testString3 );
    if( rc!=RA_OK_STORED ) { logger->log( "ERROR: should return %d, returned %d", RA_OK_STORED, rc ); return false; }
    dumpStorage( logger, storage);

    rc = storage->storeString( 11, testString2 );
    if( rc!=RA_OK_STORED ) { logger->log( "ERROR: should return %d, returned %d", RA_OK_STORED, rc ); return false; }
    dumpStorage( logger, storage);
    
    rc = storage->storeString( 13, testString3 );
    if( rc!=RA_OK_STORED_DELETED ) { logger->log( "ERROR: should return %d, returned %d", RA_OK_STORED_DELETED, rc ); return false; }
    dumpStorage( logger, storage);
    
    return true;      
}

bool test_invalidPriorities( raLogger * logger, raStorage * storage ) {
    int rc;

    logger->log(  "\nInvalid priorities:" );
    
    rc = storage->storeString( -1, testString );
    logger->log( "rc=%d, %s", rc, rc==-1 ? "OK" : "ERROR" );
    if( rc!=-1 ) return false;

    rc = storage->storeString( 0, testString );
    logger->log( "rc=%d, %s", rc, rc==-1 ? "OK" : "ERROR" );
    if( rc!=-1 ) return false;

    rc = storage->storeString( 16, testString );
    logger->log( "rc=%d, %s", rc, rc==-1 ? "OK" : "ERROR" );
    if( rc!=-1 ) return false;
        
    rc = storage->storeString( 17, testString );
    logger->log( "rc=%d, %s", rc, rc==-1 ? "OK" : "ERROR" );
    if( rc!=-1 ) return false;

    return true;
}

bool test_invalidSizes( raLogger * logger, raStorage * storage ) {
    int rc;

    logger->log(  "\nInvalid sizes:" );

    rc = storage->storeData( 1, -1, (unsigned char *)testString );
    logger->log( "rc=%d, %s", rc, rc==-2 ? "OK" : "ERROR" );
    if( rc!=-2 ) return false;

    rc = storage->storeData( 1, 0, (unsigned char *)testString );
    logger->log( "rc=%d, %s", rc, rc==-2 ? "OK" : "ERROR" );
    if( rc!=-2 ) return false;

    rc = storage->storeData( 1, 1024, (unsigned char *)testString );
    logger->log( "rc=%d, %s", rc, rc==-2 ? "OK" : "ERROR" );
    if( rc!=-2 ) return false;

    rc = storage->storeData( 1, 105, (unsigned char *)testString );
    logger->log( "rc=%d, %s", rc, rc==-3 ? "OK" : "ERROR" );
    if( rc!=-3 ) return false;

    return true;
}

bool test_fillStorage2( raLogger * logger, raStorage * storage ) {
    int rc;

    storage->cleanup();
    
    logger->log(  "\nFill storage 2:" );

    logger->log( "initial" );
    for( int i = 1; i<9; i++ ) {
        rc = storage->storeString( i, testString );
        logger->log( "%d: rc=%d", i, rc  );
        if( rc!=RA_OK_STORED ) { logger->log( "ERROR rc" ); return false; }
    }

    dumpStorage( logger, storage );

    for( int i = 0; i<7; i++ ) {
        logger->log( "overfill %d", i );
        rc = storage->storeString( 10, testString2 );
        logger->log( "%d: rc=%d", i, rc );
        if( rc!=RA_OK_STORED_DELETED ) { logger->log( "ERROR rc" ); return false; }
        dumpStorage( logger, storage );
    }

    return true;
}
