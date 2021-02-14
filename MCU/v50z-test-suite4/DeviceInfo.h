#ifndef DEVICE_INFO__H
#define DEVICE_INFO__H

#if defined(ESP32)
  void formatDeviceInfo( char * out );
#endif

void read_wakeup_and_print_startup_info( bool print, const char * appInfo );
char * getWifiStatusText( int status, char * desc );

#endif
