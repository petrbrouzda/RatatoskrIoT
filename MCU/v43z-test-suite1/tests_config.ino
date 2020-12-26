
#include "src/ra/raLogger.h"
#include "src/ra/raConfig.h"

bool do_config_tests(  raLogger * logger ) 
{
    bool rc;

    rc = test_empty_config( logger );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = crypto_test1();
    if( !rc ) { logger->log( "ERROR" ); return false; }

    rc = test_load_and_write( logger, (char *)"\n  \n   \r\npol1=val1\r     pol2 = val2    \r\n 1=1 \n2=   \n \n3= \n4=\n5=5\n$6=209e92ea3e71f7ba2e87304372c7f0fa7569362b23c3922e20829d5df386566f\n7=7\n \n 8=8\n9=9\n10=10\npolA=valA=valB" );
    if( !rc ) { logger->log( "ERROR" ); return false; }
    rc = test_load_and_write( logger, (char *)"\n  \n   \r\npol1=val1\r     pol2 = val2    \r\n 1=1 \n2=   \n \n3= \n4=\n5=5\n$6=209e92ea3e71f7ba2e87304372c7f0fa7569362b23c3922e20829d5df386566f\n7=7\n \n 8=8\n9=9\n10=10\npolA=valA=valB\n" );
    if( !rc ) { logger->log( "ERROR" ); return false; }
    rc = test_load_and_write( logger, (char *)"\n  \n   \r\npol1=val1\r     pol2 = val2    \r\n 1=1 \n2=   \n \n3= \n4=\n5=5\n$6=209e92ea3e71f7ba2e87304372c7f0fa7569362b23c3922e20829d5df386566f\n7=7\n \n 8=8\n9=9\n10=10\npolA=valA=valB\n  " );
    if( !rc ) { logger->log( "ERROR" ); return false; }

    logger->log( "\n\nAll config tests OK" );
    return true;
}


bool crypto_test1()
{
  logger->log( "\n---\n%s", __FUNCTION__ );
  BYTE aes_key[AES_KEYLEN];
  
  char * itemName = "ahoj";
  char * deviceID = "12234567";
  char * appPass = "Moje dlouhe heslo";
  
  raConfig * cfg = new raConfig();
  cfg->createEncryptionKey( deviceID, appPass, (char*)aes_key );

  char * src = "Tohle je text k zasifrovani";
  
  char enctext[256];
  char dectext[256];
  
  cfg->encryptItem( (char*)aes_key, itemName, src, enctext, 256 );
  logger->log( "> '%s'", enctext );
  if( ! cfg->dectryptItem( (char*)aes_key, itemName, enctext, dectext, 256 ) ) {
    logger->log( "chyba dekodovani!" );
    return false;
  }
  logger->log( "< '%s'", dectext );
  if( strcmp(dectext,src) != 0 ) {
    logger->log( "jiny vysledek!" );
    return false;
  }

  delete cfg;

  return true;
}

bool test_empty_config(  raLogger * logger ) 
{
    logger->log( "--- %s", __FUNCTION__ );

    raConfig * cfg = new raConfig();
    
    const char * val = cfg->getString( "neniTam", NULL );
    if( val != NULL ) {
      logger->log( "CHYBA polozka neniTam != NULL" ); 
      return false;
    }

    delete cfg;

    return true;
}



void traverseConfig( raLogger * logger, raConfig * cfg )
{
    logger->log( "raConfig:");
    for(int i = 0; i < cfg->length; i++ )
    {
        logger->log( "  (%d) '%s' = '%s'", i, cfg->fields[i], cfg->values[i] );
    }
}


bool checkItem( raLogger * logger, raConfig * cfg, char * itemName, char * expectedValue )
{
    const char * val = cfg->getString( itemName, NULL );
    if( val==NULL ) {
        logger->log( "CHYBA nenalezena polozka '%s'", itemName );
        return false;
    }
    if( strcmp( val, expectedValue ) != 0 ) {
        logger->log( "CHYBA polozka '%s': '%s' <> '%s' ", itemName, val, expectedValue );
        return false;
    }

    return true;
}


bool test_load_and_write(  raLogger * logger, char * testData ) 
{
    logger->log( "--- %s", __FUNCTION__ );
    raConfig * cfg = new raConfig();
    cfg->setInfo( "123456789", "MojeTajneHeslo" );
    
    cfg->parseFromString( testData );

    if( cfg->isDirty() ) {
      logger->log( "CHYBA nema byt dirty" ); 
      return false;
    }
    
    traverseConfig( logger, cfg );
    
    Serial.println( "---[" );
    cfg->printTo( Serial );
    Serial.println( "\n]---" );

    if( ! checkItem( logger, cfg, (char*)"pol1", (char*)"val1" )) return false;
    if( ! checkItem( logger, cfg, (char*)"pol2", (char*)"val2" )) return false;
    if( ! checkItem( logger, cfg, (char*)"polA", (char*)"valA=valB" )) return false;
    if( ! checkItem( logger, cfg, (char*)"1", (char*)"1" )) return false;
    if( ! checkItem( logger, cfg, (char*)"$6", (char*)"TajneHeslo" )) return false;
    if( 5 != cfg->getLong( (const char*)"5", -1 ) ) {
      logger->log( "CHYBA polozka 5 != 5" ); 
      return false;
    }

    if( -1 != cfg->getLong( (const char*)"555", -1 ) ) {
      logger->log( "CHYBA polozka 555 != -1" ); 
      return false;
    }

    const char * val = cfg->getString( "neniTam", NULL );
    if( val != NULL ) {
      logger->log( "CHYBA polozka neniTam != NULL" ); 
      return false;
    }

    cfg->setValue( "6", "666" );
    if( ! checkItem( logger, cfg, (char*)"6", (char*)"666" )) return false;

    if( ! cfg->isDirty() ) {
      logger->log( "CHYBA ma byt dirty" ); 
      return false;
    }
    
    cfg->setValue( "77", "7777" );
    if( ! checkItem( logger, cfg, (char*)"77", (char*)"7777" )) return false;

    if( ! cfg->isDirty() ) {
      logger->log( "CHYBA ma byt dirty" ); 
      return false;
    }
    
    traverseConfig( logger, cfg );   

    Serial.println( "---[" );
    cfg->printTo( Serial );
    Serial.println( "\n]---" );

    if( cfg->isDirty() ) {
      logger->log( "CHYBA nema byt dirty" ); 
      return false;
    }

    delete cfg;
    
    return true;
}
