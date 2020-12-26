
#include "raStorage.h"

// #define LOG_DETAIL
// #define LOG_INFO

/*
  Storage structure: plain array of records.

  One record:
    <header>  - 2 bytes
    <data>

  header:
    header 1 -  bit 7 = "marked for deletion"
                bit 3, 4, 5, 6 = priority 1-15, higher is better
                bit 2 = reserved
                bit 0-1 = high bites of content length
    header 2 - low 8 bites of content length
*/

raStorage::raStorage( int capacity,  raLogger* logger )
{
  this->logger = logger;
  this->capacity = capacity;
  this->data = (unsigned char *)malloc( capacity + 1 );
  this->data[0] = 0;
  this->end_mark = 0;
  this->rtcRamStorage = false;
  this->initStorage();
  // this->logger->log( "%s raStorage, capacity=%d", this->identity, capacity );
}


#define RA_RTCRAM_VALID_DATA_MARKER 0xdead3456

/**
 * Pri pouziti RTC RAM se pouziva nasledujici struktura:
 * [4 byte - 0xdead3456] - marker platnych dat
 * [2 byte - end_mark] - ptr za posledni platny zaznam
 * [data] - datovy blok
 */
raStorage::raStorage( unsigned char * dataPtr, int blockSize, raLogger* logger )
{
  this->logger = logger;
  this->capacity = blockSize - 8;
  this->data = dataPtr + 8;
  
  this->rtcRamStorage = true;
  this->rtcDataPtr = dataPtr;
  this->rtcBlockSize = blockSize;

  int marker = ((int *)dataPtr)[0];
  int end_mark = ((int *)dataPtr)[1];
  if( marker!=RA_RTCRAM_VALID_DATA_MARKER || end_mark<0 || end_mark>this->capacity ) {
    // data v RTC RAM nejsou validni 
    this->logger->log( "%s RTCRAM data invalid", this->identity );
    ((int *)dataPtr)[0] = RA_RTCRAM_VALID_DATA_MARKER;
    ((int *)dataPtr)[1] = 0;  // end marker
    this->data[0] = 0;  // zadatek dat
    this->end_mark = 0;
  } else {
    // RTC RAM data jsou OK
    this->end_mark = end_mark;
    if( end_mark!=0 ) {
        this->logger->log( "%s %d bytes", this->identity, end_mark );    
    }
  }
  this->initStorage();
}

void raStorage::initStorage()
{
  this->userIterator.valid = false;
  this->userIterator.record.init( logger, this->data );
  this->internalIterator.valid = false;
  this->internalIterator.record.init( logger, this->data );
}

/**
 * Validace ukladaneho datoveho bloku
 */ 
int raStorage::validateStoreData( int priority, int data_length ) {
  if ( priority < 1 || priority > 15 ) {
    return RA_ERR_INVALID_PRIORITY;
  }
  if ( data_length < 1 || data_length > 1023 ) {
    return RA_ERR_INVALID_LENGTH;
  }
  if ( data_length > (this->capacity - 3) ) {
    return RA_ERR_TOO_LONG;
  }
  return 0;
}


int createHeader( int priority, int data_length ) {
  return data_length | (priority << 11);
}

unsigned char header_byte1( int header ) {
  return (unsigned char)( (header >> 8) & 0xff);
}

unsigned char header_byte2( int header ) {
  return (unsigned char)(header & 0xff);
}


int raStorage::storeData( int priority, int data_length, unsigned char * input_data )
{
  int rc = this->validateStoreData( priority, data_length );

  if ( rc != 0 ) {
    this->logger->log( "%s not stored l:%d !%d, err=%d, capa=%d",
                       this->identity,
                       data_length, priority, rc, this->capacity );
    return rc;
  }

#ifdef LOG_DETAIL
  logger->log( "@sd data_length=%d end_mark=%d capacity=%d", data_length, this->end_mark, this->capacity );
#endif

  boolean anyRecordDeleted = false;

  // is there empty space in storage>
  if ( this->capacity < (this->end_mark + data_length + RA_STORAGE_HEADER_SIZE + RA_STORAGE_HEADER_SIZE ) ) {

    // not enough space, make any!

    // how much bytes I need?
    int empty_space = this->capacity - this->end_mark;
    int need_bytes = (data_length + RA_STORAGE_HEADER_SIZE + RA_STORAGE_HEADER_SIZE) - empty_space - 1;
#ifdef LOG_DETAIL
    logger->log( "@sd empty_space=%d need_bytes=%d", empty_space, need_bytes );
#endif

    int space_freed = 0;

    // look for records that can be deleted
    this->intStartReading( &internalIterator );
    raStorageRecord * rec;
    while ( rec = this->intReadNext(&internalIterator) ) {
      // already marked for deletion? yupee!
      if ( rec->markedForDeletion ) {
        space_freed += rec->data_length + RA_STORAGE_HEADER_SIZE + RA_STORAGE_HEADER_SIZE;
      }
      // lower priority? delete it!
      if ( rec->priority < priority ) {
        rec->markAsDeleted();
        anyRecordDeleted = true;
        space_freed += rec->data_length + RA_STORAGE_HEADER_SIZE + RA_STORAGE_HEADER_SIZE;
      }
      // has I saved enough space?
      if ( space_freed >= need_bytes ) {
        break;
      }
    } // while( rec = this->intReadNext(&internalIterator) )

    if ( space_freed < need_bytes ) {
      // not enough space even after deletion, undelete deleted records (if any)
      if ( anyRecordDeleted ) {
        this->undeleteAll();
      }

      // there is no empty space
      this->logger->log( "%s not stored, no space, l:%d !%d ",
                         this->identity,
                         data_length, priority );
      return RA_NOT_STORED;
    } // if( space_freed < need_bytes )
    else
    {
      // some records deleted, so we purge the storage
      this->purgeDeleted();
    }

    // some records deleted, space created
  } // if( this->capacity < (this->end_mark + data_length + RA_STORAGE_HEADER_SIZE + RA_STORAGE_HEADER_SIZE ) ) {

  // insert data at the end of storage
#ifdef LOG_INFO  
  this->logger->log( "%s +%dB !%d @%d",
                     this->identity,
                     data_length, priority, this->end_mark
                      );
#endif

  int header = createHeader( priority, data_length );
  this->data[this->end_mark++] = header_byte1(header);
  this->data[this->end_mark++] = header_byte2(header);
  memcpy( this->data + this->end_mark, input_data, data_length );
  this->end_mark += data_length;
  if( this->rtcRamStorage ) {
    ((int *)(this->rtcDataPtr))[1] = this->end_mark;
  }
  this->data[this->end_mark] = 0;

  // reset iterators
  this->userIterator.valid = false;
  this->internalIterator.valid = false;

  return anyRecordDeleted ? RA_OK_STORED_DELETED : RA_OK_STORED;

}


int raStorage::storeString( int priority, char * input_string )
{
  return this->storeData( priority, strlen(input_string) + 1, (unsigned char*)input_string );
}

void raStorage::startReading()
{
  this->intStartReading( &userIterator );
}

raStorageRecord* raStorage::readNext()
{
  return this->intReadNext( &userIterator );
}

void raStorage::intStartReading( raStorageIterator* iterator )
{
  iterator->position = 0;
  // if there is no records in storage, iterator is invalid
  iterator->valid = (this->end_mark != 0);
}

raStorageRecord* raStorage::intReadNext( raStorageIterator* iterator )
{
#ifdef LOG_DETAIL
  logger->log( "@rn iterator: valid=%s position=%d", iterator->valid ? "true" : "false",  iterator->position );
#endif

  if ( ! iterator->valid ) return NULL;

  unsigned char header_byte1 = this->data[ iterator->position++ ];
  unsigned char header_byte2 = this->data[ iterator->position++ ];

#ifdef LOG_DETAIL
  logger->log( "@rn header: %02.2x %02.2x", header_byte1, header_byte2 );
#endif

  if ( header_byte1 == 0 ) {
    // end of data
    iterator->valid = false;
    return NULL;
  }

  iterator->record.data_length = (((int)header_byte1 & 3) << 8) | (int)header_byte2;
  iterator->record.priority = ((int)header_byte1 >> 3) & 15;
  iterator->record.markedForDeletion = header_byte1 & 0x80;
  iterator->record.data = this->data + iterator->position;
  iterator->record.setPtr(  this->data + iterator->position - RA_STORAGE_HEADER_SIZE );

  iterator->position += iterator->record.data_length;

  return &(iterator->record);
}

bool raStorage::hasData()
{
  return (this->end_mark != 0);
}

void raStorage::undeleteAll()
{
  this->intStartReading( &internalIterator );
  raStorageRecord * rec;
  while ( rec = this->intReadNext(&internalIterator) ) {
    if ( rec->markedForDeletion ) {
      rec->markAsNotDeleted();
    }
  }
  return;
}



void raStorage::cleanup()
{
  this->logger->log( "%s cleanup", this->identity );

  this->end_mark = 0;
  if( this->rtcRamStorage ) {
    ((int *)(this->rtcDataPtr))[1] = this->end_mark;
  }

  this->data[0] = 0;
  this->userIterator.valid = false;
  this->internalIterator.valid = false;

  return;
}


void raStorage::purgeDeleted()
{
  int prev_end_mark = this->end_mark;

  while ( true )
  {
    if ( this->end_mark == 0 ) break;

    unsigned char * target = NULL;
    unsigned char * source = NULL;

    // search for first deleted
    this->intStartReading( &internalIterator );
    raStorageRecord * rec;
    while ( rec = this->intReadNext(&internalIterator) ) {
      if ( rec->markedForDeletion ) {
        target = rec->data - RA_STORAGE_HEADER_SIZE;
        break;
      }
    }

    if ( target == NULL ) break;

    // search for first non-deleted
    while ( rec = this->intReadNext(&internalIterator) ) {
      if ( ! rec->markedForDeletion ) {
        source = rec->data - RA_STORAGE_HEADER_SIZE;
        break;
      }
    }

    if ( source == NULL ) {
      // no not-deleted record found after deleted
      // just save end_of_data marker there
      target[0] = 0;
      target[1] = 0;

      this->end_mark = target - this->data;

    } else {

      int source_offset = source - this->data;
      int amount = this->end_mark - source_offset + 1;
      memcpy( target, source, amount );
      this->end_mark -= (source - target);

    }
  }

  if( this->rtcRamStorage ) {
    ((int *)(this->rtcDataPtr))[1] = this->end_mark;
  }

  this->userIterator.valid = false;
  this->internalIterator.valid = false;

  this->logger->log( "%s purge %dB, %d/%d", this->identity, (prev_end_mark - this->end_mark), this->end_mark, this->capacity );

  return;
}



void raStorageRecord::setPtr( unsigned char * ptr )
{
  this->record_ptr = ptr;
}

void raStorageRecord::markAsDeleted()
{
  this->record_ptr[0] |= 0x80;
#ifdef LOG_INFO
  this->logger->log( "%s - @%d !%d", this->identity, (this->record_ptr - this->data_start), this->priority );
#endif
}

void raStorageRecord::markAsNotDeleted()
{
  this->record_ptr[0] &= 0x7F;
#ifdef LOG_INFO
  this->logger->log( "%s (-) @%d !%d", this->identity, (this->record_ptr - this->data_start), this->priority );
#endif
}

void raStorageRecord::init( raLogger * logger, unsigned char * data_start )
{
  this->logger = logger;
  this->data_start = data_start;
}

int raStorage::getUsage()
{
  int rc = this->end_mark * 100 / this->capacity;
  if( rc > 50 && rc < 100 ) {
      rc++;
  }
  return rc;
}

 bool raStorage::isRtcRamStorage()
 {
   return this->rtcRamStorage;
 }
 