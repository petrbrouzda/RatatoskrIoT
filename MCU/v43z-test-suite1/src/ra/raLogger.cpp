
#include "raLogger.h"

raLogger::raLogger( int mode )
{
    this->init(mode);
}

raLogger::raLogger()
{
    this->init( RA_LOG_MODE_SERIAL );
}

void raLogger::init( int mode )
{
    this->printed = (char *)malloc( RA_PRINT_BUFFER_SIZE+2 );
    this->printPos = 0;
    this->printed[0] = 0;
    
    if( mode == RA_LOG_MODE_SERIAL ) {
        this->msgBuffer = (char *)malloc( RA_BUFFER_SIZE+2 );
        this->run = true;
    } else {
        this->msgBuffer = NULL;
        this->run = false;
    }
    
    this->log( "" );
}

void raLogger::log( const char * format, ... )
{
    if( !this->run ) {
        // i kdyz nepouzivam, musim smazat pocitadlo znaku v 'printed', jinak by mi preteklo
        this->printPos = 0;
        return;
    }
    
    va_list args;
    va_start( args, format );
    vsnprintf( this->msgBuffer, RA_BUFFER_SIZE, format, args );
    va_end( args );
    this->msgBuffer[RA_BUFFER_SIZE - 1] = 0;
    
    this->printPos = 0;
    this->printed[0] = 0;
    
    Serial.println( this->msgBuffer );
}


size_t raLogger::write(uint8_t ch )
{
    this->printed[ this->printPos++ ] = (char)ch;
    this->printed[ this->printPos ] = 0;
    return 1;
    
}

size_t raLogger::write(const uint8_t* buffer, size_t size)
{
    int remaining = RA_PRINT_BUFFER_SIZE - this->printPos - 2;
    int toCopy = size > remaining ? remaining : size; 
    strncpy( (char*)(this->printed + this->printPos), (const char*)buffer, toCopy );
    this->printPos = this->printPos + toCopy;   
    this->printed[ this->printPos ] = 0;
    return size;
}
