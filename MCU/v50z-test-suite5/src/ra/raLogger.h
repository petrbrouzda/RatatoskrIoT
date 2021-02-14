
#ifndef RA_LOGGER_H
#define RA_LOGGER_H

#include <stdio.h>
#include <stdlib.h>
#include <stdarg.h>
#include <Arduino.h>


/*
    Simple logging interface to Serial port.
    Implements printf()-like functionality.
    
    If you want to log non-trivial objects (for example String or IPaddress) you can't pass them asi object:
        error: cannot pass objects of non-trivially-copyable type 'class IPAddress' through '...'
    raLogger implements Print interface for this.
    Printed data are stored into public char* logger->printed.
    Buffer is deleted after call to ->log, so this is the correct usage and you don't need to think of buffer used:

        IPAddress ip = WiFi.localIP();
        logger->print( ip );
        logger->log( "IP address = %s", logger->printed );
*/



/*
 * Configuration values for callers
 */
// no logging at all
#define RA_LOG_MODE_NOLOG 0
// serial logger 
#define RA_LOG_MODE_SERIAL 1
// provided logger - used in raStorage 
#define RA_LOG_MODE_EXTERNAL_LOGGER 2


/*
 * Internal buffer for log messages
 */
#define RA_BUFFER_SIZE 500
#define RA_PRINT_BUFFER_SIZE 100



class raLogger : public Print
{
  public:
    raLogger();
    raLogger( int mode );
    void log ( const char * format, ... );
    
    // these two functions are needed for Print interface
    size_t write(uint8_t);
    size_t write(const uint8_t* buffer, size_t size);

    // content written by Print interface - erased after next log() call
    char * printed;

  private:
    void init( int mode );
    bool run;
    char * msgBuffer;
    int printPos; 
};

#endif   // RA_LOGGER_H
