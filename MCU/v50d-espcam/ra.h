#ifndef _RA__H_
#define _RA__H_

void raLoop();
void ratatoskr_startup( bool initSerial );
void raPrintHeap();
void memInfo( const char * func, int line);
void raGetDeviceId( char * buf );

#endif
