#include <Arduino.h>

#ifndef RA_CONFIG__H
#define RA_CONFIG__H

#include "../aes-sha/sha256.h"
#include "../aes-sha/CRC32.h"

#define CBC 1
#define ECB 0
#define CTR 0
extern "C" {
    #include "../aes-sha/aes.h"
}

#include "../platform/platform.h"


class raConfig
{
  public:
    raConfig();
    ~raConfig();
    void setInfo( char * deviceId, char * appPassPhrase );
    
    void parseFromString( char * input );
    void printTo( Print& p );
    const char * getString( const char * fieldName, const char * defaultValue );
    long getLong( const char * fieldName, long defaultValue );

    /**
     * Umi zmenit hodnotu existujiciho klice ci pridat novy
     */
    void setValue( const char * fieldName, const char * value );

    bool isDirty();

    char ** fields;
    char ** values;
    int length;

    /** je public jen kvuli testovani, nepouzivat */
    bool dectryptItem( char * aes_key, char * itemName, char * ciphertext, char * target, int targetSize );
    /** je public jen kvuli testovani, nepouzivat */
    void encryptItem( char * aes_key, char * itemName, char * value, char * target, int targetSize );
    /** je public jen kvuli testovani, nepouzivat */
    void createEncryptionKey( char * deviceId, char * appPassphrase, char * aes_key );

  private:
    void zapisVystup( char * name, int namePos, char * value, int valuePos, char * aes_key );
    
    /**
     * Vraci klic v poli nebo -1 
     */ 
    int findKey( const char * fieldName );

    /**
     * Pokud je jiz pole zaplneno, prodlouzi ho.
     */ 
    void reserveSize();

    int size = 10;
    bool dirty;

    char deviceId[33];
    char appPassPhrase[33];
};

void random_block( BYTE * target, int size );
void btohexa( unsigned char * bytes, int inLength, char * output, int outLength );
int hexatob( char * input, unsigned char * output, int outLength );

#endif
