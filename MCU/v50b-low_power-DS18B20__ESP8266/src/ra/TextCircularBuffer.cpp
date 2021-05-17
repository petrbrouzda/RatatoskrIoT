#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <time.h>

#include "TextCircularBuffer.h"

#ifdef LOG_SHIPPING

TextCircularBuffer::TextCircularBuffer( char * buffer, int bufferSize )
{
    this->ptr = buffer;
    this->size = bufferSize - 1;
    this->currentPos = 0;
    this->overwritten = 0;
    this->lastData = 0;
}

#define OVWR_MARK "\n***OVERWRITE***\n"
#define OVWR_MARK_LEN 17

void TextCircularBuffer::write( char * text )
{
    char timestamp[14];
    sprintf( timestamp, "%d> ", time(NULL) );
    int len = strlen( text );
    int timestampLen = strlen(timestamp);
    if( (len + timestampLen + OVWR_MARK_LEN + 5) > this->size ) {
        return;
    }   
                                                // 2 = \n \0
    int wholeTextLen = timestampLen + len + 2;
    if( this->overwritten ) {
        wholeTextLen += OVWR_MARK_LEN + 1;
    }
    if( ( this->currentPos + wholeTextLen + 1 ) > this->size )  {
        // nemame misto, jedeme znovu od zacatku
        this->overwritten = 1;
        this->currentPos = 0;
    }
    // mame misto - vzdy
    strcpy( this->ptr + this->currentPos, timestamp );
    this->currentPos += timestampLen;
    strcpy( this->ptr + this->currentPos, text );
    this->currentPos += len;
    this->ptr[this->currentPos] = '\n';
    this->currentPos++;
    this->ptr[this->currentPos] = 0;
    if( this->overwritten ) {
        memcpy( this->ptr + this->currentPos + 1, OVWR_MARK, OVWR_MARK_LEN );
        if( this->currentPos + OVWR_MARK_LEN > this->lastData ) {
            this->ptr[this->currentPos + OVWR_MARK_LEN] = 0;
        }
    } 
    if( this->currentPos > this->lastData ) {
        this->lastData = this->currentPos;
    }
}

bool TextCircularBuffer::avail2()
{
    return this->overwritten;
}

char * TextCircularBuffer::getText2()
{
    return this->ptr + this->currentPos + 1;
}

void TextCircularBuffer::delete2()
{
    this->overwritten = 0;
}

bool TextCircularBuffer::avail1()
{
    return this->currentPos!=0;
}

char * TextCircularBuffer::getText1()
{
    return this->ptr;
}

void TextCircularBuffer::delete1()
{
    this->currentPos = 0;
    this->lastData = 0;
    this->overwritten = 0;
}

bool TextCircularBuffer::avail() 
{
    if( this->avail2() ) {
        return true;
    }
    return this->avail1();
}

char * TextCircularBuffer::getText()
{
    // pro jistotu
    this->ptr[ this->size - 1 ] = 0;

    if( this->avail2() ) {
        return this->getText2();
    } else if( this->avail1() ) {
        return this->getText1();
    } else {
        return (char*)"";
    }
}

void TextCircularBuffer::deleteText()
{
    if( this->avail2() ) {
        this->delete2();
    } else {
        this->delete1();
    }
}

int TextCircularBuffer::getUsagePct()
{
    if( this->overwritten ) {
        return 100;
    } else {
        return this->currentPos * 100 / this->size;
    }
}

#endif // LOG_SHIPPING