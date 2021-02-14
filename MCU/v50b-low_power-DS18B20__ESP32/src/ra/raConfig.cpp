#include "raConfig.h"


raConfig::raConfig()
{
    this->fields = (char**) malloc( this->size * sizeof(char *) );
    this->values = (char**) malloc( this->size * sizeof(char *) );
    this->length = 0;
    this->dirty = false;
}

raConfig::~raConfig()
{
    for( int i = 0; i<this->length; i++ ) {
        free( this->fields[i] );
        free( this->values[i] );
    }
    free(this->fields);
    free(this->values);
}


/**
 * Pokud je jiz pole zaplneno, prodlouzi ho.
 */ 
void raConfig::reserveSize()
{
    if( this->length >= this->size ) {
        this->size = this->size * 2;
        this->fields = (char**)realloc( this->fields, this->size * sizeof(char *) );
        this->values = (char**)realloc( this->values, this->size * sizeof(char *) );
    }
}

void trimRight( char * text )
{
    int n = strlen(text) - 1;
    while( n>=0 ) {
        if( text[n]==' ' ) {
            text[n] = 0;
        } else {
            break;
        }
        n--;
    }
}

#include "raLogger.h"

void raConfig::zapisVystup( char * name, int namePos, char * value, int valuePos, char * aes_key )
{
    this->reserveSize();

    name[namePos] = 0;
    trimRight( name );
    value[valuePos] = 0;
    trimRight( value );

    if( name[0]!='$') {
        this->values[this->length] = (char*)malloc( strlen(value)+1 );
        strcpy( this->values[this->length], value );
    } else {
        char buffer[256];
        if( ! this->dectryptItem( aes_key, name, value, buffer, 256 ) ) {
            // neni jak zalogovat
            return;
        }
        this->values[this->length] = (char*)malloc( strlen(buffer)+1 );
        strcpy( this->values[this->length], buffer );
    }

    this->fields[this->length] = (char*)malloc( strlen(name)+1 );
    strcpy( this->fields[this->length], name );

    this->length++;
}

#define NAME_MAX 25
#define VALUE_MAX 255

void raConfig::parseFromString(  char * input )
{
    this->dirty = false;

    char aes_key[AES_KEYLEN+1];
    this->createEncryptionKey( this->deviceId, this->appPassPhrase, aes_key );

    char * p = input;
    char name[NAME_MAX+1];
    int namePos = 0;
    char value[VALUE_MAX+1];
    int valuePos = 0;

    /**
     * 0 = zaciname / pred jmenem
     * 1 = ve jmenu
     * 2 = pred hodnotou
     * 3 = v hodnote
     */ 
    int state = 0;

    while( true )
    {
        if( *p==0 ) {
            // konec vstupniho textu
            if( state==3 ) {
                this->zapisVystup( name, namePos, value, valuePos, aes_key );
            }
            break;
        }
        if( state==0 ) {
            if( *p==' ' || *p==13 || *p==10 || *p==8 ) {
                // nic
            } else {
                state = 1;
                continue; // protoze jinak by se na konci posunulo na dalsi pozici
            }
        } else if( state==1 ) {
            if( *p==13 || *p==10 ) {
                state = 0;
                namePos = 0;
            } else if( *p=='=' )  {
                state = 2;
            } else {
                if( namePos<NAME_MAX ) {
                    name[namePos++] = *p;
                }
            }
        } else if( state==2 ) {
            if( *p == ' ' ) {
                // nedelame nic - preskakujeme mezery na zacatku
            } else {
                state = 3;
                continue; // protoze jinak by se na konci posunulo na dalsi pozici
            }
        } else if( state==3 ) {
            if( *p==13 || *p==10 ) {
                this->zapisVystup( name, namePos, value, valuePos, aes_key );
                namePos = 0;
                valuePos = 0;
                state = 0;
            } else {
                if( valuePos < VALUE_MAX ) {
                    value[valuePos++] = *p;
                }
            }
        }
        p++;
    } // while( true )
}

const char * raConfig::getString( const char * fieldName, const char * defaultValue )
{
    int i = this->findKey( fieldName );
    if( i!=-1 ) {
        return this->values[i];
    } else {
        return defaultValue;
    }
}

long raConfig::getLong( const char * fieldName, long defaultValue )
{
    int i = this->findKey( fieldName );
    if( i!=-1 ) {
        return atol(this->values[i]);
    } else {
        return defaultValue;
    }
}

/**
 * Vraci klic v poli nebo -1 
 */ 
int raConfig::findKey( const char * fieldName )
{
    for(int i = 0; i < this->length; i++ ) {
        if( strcmp( this->fields[i], fieldName )== 0 ) {
            return i;
        }
    }
    return -1;
}


/**
 * Umi zmenit hodnotu existujiciho klice ci pridat novy
 */
void raConfig::setValue( const char * fieldName, const char * value )
{
    int i = this->findKey( fieldName );
    if( i!=-1 ) {
        // nalezeno
        free( this->values[i] );
        this->values[i] = (char*)malloc( strlen(value)+1 );
        strcpy( this->values[i], value );
    } else {
        // nenalezena polozka
        this->reserveSize();
        this->fields[this->length] = (char*)malloc( strlen(fieldName)+1 );
        strcpy( this->fields[this->length], fieldName );
        this->values[this->length] = (char*)malloc( strlen(value)+1 );
        strcpy( this->values[this->length], value );
        this->length++;
    }
    this->dirty = true;
}


void raConfig::setInfo( char * deviceId, char * appPassPhrase )
{
    strncpy( this->deviceId, deviceId, 32 );
    this->deviceId[32] = 0;
    strncpy( this->appPassPhrase, appPassPhrase, 32 );
    this->appPassPhrase[32] = 0;
}


void raConfig::printTo( Print& p )
{
    char aes_key[AES_KEYLEN];
    char buffer[256];

    this->createEncryptionKey( this->deviceId, this->appPassPhrase, aes_key );

    for(int i = 0; i < this->length; i++ ) {
        p.print( this->fields[i] );
        p.print( '=' );
        if( this->fields[i][0]!='$' ) {
            p.print( this->values[i] );
        } else {
            this->encryptItem( aes_key, this->fields[i], this->values[i], buffer, 256 );
            p.print( buffer );
        }
        p.print( '\n' );
    }
    this->dirty = false;
}

bool raConfig::isDirty()
{
    return this->dirty;
}


void raConfig::createEncryptionKey( char * deviceId, char * appPassphrase, char * aes_key )
{
    char fullPwd[256];
    snprintf( fullPwd, 255, "%s%s",  deviceId, appPassphrase );
    fullPwd[255] = 0;

    Sha256 sha256;
    sha256.init();
    sha256.update((const BYTE *)fullPwd, strlen((const char *)fullPwd));
    sha256.final((unsigned char*)aes_key);
}

void createItemIv( char * item, char *aes_iv )
{
    BYTE hash[AES_KEYLEN];

    Sha256 sha256;
    sha256.init();
    sha256.update((const BYTE *)item, strlen((const char *)item));
    sha256.final(hash);
    memcpy( aes_iv, hash, AES_BLOCKLEN );
}

void raConfig::encryptItem( char * aes_key, char * itemName, char * value, char * target, int targetSize )
{
    BYTE aes_iv[AES_BLOCKLEN];
    createItemIv( itemName, (char*)aes_iv );

    BYTE payload[257];
    int sourceLen = strlen(value);
    payload[4] = (sourceLen>>8) & 0xff;
    payload[5] = sourceLen & 0xff;
    memcpy( payload+6, value, sourceLen );
    
    uint32_t crc1 = CRC32::calculate((unsigned char*) value, sourceLen);
    payload[0] = (BYTE)( (crc1>>24) & 0xff);
    payload[1] = (BYTE)( (crc1>>16) & 0xff);
    payload[2] = (BYTE)( (crc1>>8) & 0xff);
    payload[3] = (BYTE)(crc1 & 0xff);
    
    int celkovaDelka = sourceLen + 2 + 4;
    int zbyva = AES_BLOCKLEN - (celkovaDelka % AES_BLOCKLEN);
    if( zbyva>0 ) {  
        random_block( payload+celkovaDelka, zbyva );
    }

    celkovaDelka = celkovaDelka + zbyva;

    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, (unsigned char const*)aes_key, (unsigned char const*)aes_iv);
    AES_CBC_encrypt_buffer(&ctx, (unsigned char *)payload, celkovaDelka );

    btohexa( payload, celkovaDelka, target, targetSize );
}

bool raConfig::dectryptItem( char * aes_key, char * itemName, char * ciphertext, char * target, int targetSize )
{
    BYTE aes_iv[AES_BLOCKLEN];
    createItemIv( itemName, (char*)aes_iv );

    BYTE plainText[257];
    hexatob( ciphertext, (BYTE*)plainText, 256 );

    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, (unsigned char*)aes_key, aes_iv );
    AES_CBC_decrypt_buffer(&ctx, (unsigned char*)plainText, strlen(ciphertext)/2 );

    uint32_t crcReceived = (plainText[0] << 24) | (plainText[1] << 16) | (plainText[2] << 8) | plainText[3];
    int len = (plainText[4]<<8) | plainText[5];

    plainText[len+6] = 0;

    uint32_t crcComputed = CRC32::calculate((unsigned char*) plainText+6, len );

    if( crcReceived != crcComputed ) {
        return false;
    }

    strncpy( target, (char*)(plainText+6), targetSize );
    target[targetSize-1] = 0;

    return true;
}


unsigned char btohexa_high(unsigned char b)
{
    b >>= 4;
    return (b>0x9u) ? b+87 : b+'0';     // 87 = 'a'-10
}

unsigned char btohexa_low(unsigned char b)
{
    b &= 0x0F;
    return (b>9u) ? b+87 : b+'0';   // 87 = 'a'-10
}

void btohexa( unsigned char * bytes, int inLength, char * output, int outLength )
{
    char * out = output;
    for( int i = 0; i<inLength && i<outLength; i++ )
    {
        *out = btohexa_high( bytes[i] );
        out++;
        *out = btohexa_low( bytes[i] );
        out++;
    }
    *out = 0;
}

int hexatob( char * input, unsigned char * output, int outLength )
{
    unsigned char * out = output;
    unsigned char * outEnd = output + outLength;
    int inLength = strlen( input );
    while( (*input!=0) && (out<outEnd) )
    {
        char ch = *input;
        input++;
        char cl = *input;
        if( cl==0 ) break;
        input++;
        unsigned char b =   ch>='a' ? 
                            (ch-'a'+10)<<4 : 
                            (  
                                ch>='A' ?
                                (ch-'A'+10)<<4 : 
                                (ch-'0')<<4
                            );
        b |=    cl>='a' ? 
                (cl-'a'+10) : 
                (
                    cl>='A' ?
                    (cl-'A'+10) :
                    (cl-'0')
                );
        *out = b;
        out++;
    }
    return (out-output);
}


/**
 * Vygeneruje blok nahodnych bytes; delka musi byt delitelna 4!
 */
void random_block( BYTE * target, int size )
{
    for( int i = 0; i<size;  ) {
        
        // definovano v platform/esp8266-trng.h platform/esp32-trng.h
        long l = trng();
            
        target[i++] = (unsigned char)(l & 0xff);
        target[i++] = (unsigned char)( (l>>8) & 0xff);
        target[i++] = (unsigned char)( (l>>16) & 0xff);
        target[i++] = (unsigned char)( (l>>24) & 0xff);
    }
}