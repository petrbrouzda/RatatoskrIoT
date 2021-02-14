#ifndef RA_WIFI_CONNECTION_H
#define RA_WIFI_CONNECTION_H

void networkConfig( const char * configApPass, bool runWifiOnStartup );
bool checkWifiStatus();
void startWifi();
void stopWifi();
bool isWifiOn();

#define WIFI_POWER_OFF -999

#endif
