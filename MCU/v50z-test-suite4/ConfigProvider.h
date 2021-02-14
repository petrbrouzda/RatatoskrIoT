#include <FS.h>                   //this needs to be first, or it all crashes and burns...

#ifndef CONFIGPROVIDER_H
#define CONFIGPROVIDER_H

#include <stdio.h>
#include <stdlib.h>
#include "src/ra/raConfig.h"

// jmeno konfiguracniho souboru ve SPIFS
#define CONFIG_FILE_NAME (char*)"/config2.ra"

bool isConfigValid( raConfig * config );
void loadConfig( raConfig * cfg );
void saveConfig( raConfig * cfg );

#endif   
