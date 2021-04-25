#ifndef TEXT_CIRCULLAR_BUFFER__H
#define TEXT_CIRCULLAR_BUFFER__H

#include "../../AppFeatures.h"
#ifdef LOG_SHIPPING

class TextCircularBuffer
{
  public:
    TextCircularBuffer( char * buffer, int bufferSize );
    void write( char * text );

    bool avail();
    char * getText();
    void deleteText(); 

    int getUsagePct();
    
    /** testing only! */
    bool avail2();
    /** testing only! */
    char * getText2();
    /** testing only! */
    void delete2();
    /** testing only! */
    bool avail1();
    /** testing only! */
    char * getText1();
    /** testing only! */
    void delete1();

  private:
    char * ptr;
    int size;

    int currentPos;
    int lastData;
    int overwritten;
};

#endif // LOG_SHIPPING
#endif // TEXT_CIRCULLAR_BUFFER__H