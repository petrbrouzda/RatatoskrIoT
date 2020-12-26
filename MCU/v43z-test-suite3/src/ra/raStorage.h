
#ifndef RA_STORAGE_H
#define RA_STORAGE_H

#include <stdio.h>
#include <stdlib.h>
#include <pgmspace.h>

#include "raLogger.h"



//----- return codes and errors

// allowed priority values are from 1 to 15
#define RA_ERR_INVALID_PRIORITY -1
// length must be from 1 to 1023
#define RA_ERR_INVALID_LENGTH -2
// length is bigger than data buffer at all
#define RA_ERR_TOO_LONG -3

// data stored into storage, nothing has to be deleted
#define RA_OK_STORED 1
// data stored into storage, some record(s) with lower prio has been deleted
#define RA_OK_STORED_DELETED 2
// data not stored into storage, there is no space left
//    and all records in storage has higher priority than this record
//    (not an error)
#define RA_NOT_STORED 0


//------

#define RA_STORAGE_HEADER_SIZE 2

class raStorageRecord {
  public:
    int priority;
    int data_length;
    unsigned char * data;
    bool markedForDeletion;

    void markAsDeleted();
    void markAsNotDeleted();

    void init( raLogger * logger, unsigned char * data_start );
    void setPtr( unsigned char * ptr );

  private:
    unsigned char * record_ptr;
    raLogger * logger;
    unsigned char * data_start;
    char * identity = (char*)"SREC";
};

class raStorageIterator {
  public:
    int position;
    bool valid;
    raStorageRecord record;
};

class raStorage {
  public:
    /** alokuje novou storage v RAM */
    raStorage( int capacity, raLogger* logger );

    /** pouziva predalokovanou storage v RTC RAM - jen ESP32 */
    raStorage( unsigned char * dataPtr, int blockSize, raLogger* logger );

    int storeData( int priority, int data_length, unsigned char * data );
    int storeString( int priority, char * input_string );

    bool hasData();
    int getUsage();

    void startReading();
    raStorageRecord* readNext();

    void cleanup();
    void undeleteAll();
    void purgeDeleted();

    bool isRtcRamStorage();

  private:

    void initStorage();    
    int validateStoreData( int priority, int data_length );

    void intStartReading( raStorageIterator* iterator );
    raStorageRecord* intReadNext( raStorageIterator* iterator );

    int capacity;
    unsigned char* data;
    raLogger* logger;
    char * identity = (char*)"STRG";

    // points after the last valid record; should point to record with priority=0
    int end_mark;

    // iterators for user and for internal use
    raStorageIterator userIterator;
    raStorageIterator internalIterator;

    // nasledujici blok se pouziva pro storage v RTC RAM
    bool rtcRamStorage;
    unsigned char * rtcDataPtr;
    int rtcBlockSize;
};


#endif   // RA_STORAGE_H
