#include <Arduino.h>

#include "../aes-sha/CRC32.h"

#define CBC 1
#define ECB 0
#define CTR 0
extern "C" {
    #include "../aes-sha/aes.h"
}

#include "../micro_ecc/uECC.h"

#include "../platform/platform.h"
#include "../platform/Esp8266RTCUserMemory.h"

#include "raConnection.h"

/* --- debug konfigurace - mozno povolit/zakazat -- */
/* detailni logovani bezne komunikace: */
// #define DETAILED_RA_LOG
/* detailni logovani prace s bloby: */
// #define BLOB_DETAIL_LOG


#ifdef ESP32
    RTC_DATA_ATTR long rtcIndicator;
    RTC_DATA_ATTR unsigned char rtcSessionId[32];
    RTC_DATA_ATTR unsigned char rtcSessionKey[32];    
#endif

#ifdef OTA_UPDATE
    #include <Update.h>
#endif

/**
 * ESP8266: je mozne, ale jinak:
    https://arduino-esp8266.readthedocs.io/en/latest/ota_updates/readme.html#http-server
 */

static int RNG(uint8_t *dest, unsigned size) {

    while( size>=4 ) {
        *((int*)dest) = trng();
        size -= 4;
        dest += 4;
    }
    while( size!=0 ) {
        *dest = (unsigned char)( trng() & 0xff );
        size--;
        dest++;
    }

    return 1;
}


#define RAC_RTCMEM_MARKER 0xdeadface

raConnection::raConnection( raConfig * cfg, raLogger* logger  )
{
    this->logger = logger;
    this->cfg = cfg;
    this->connected = false;
    this->sha256=new Sha256();
    this->updateReqId = 0;
    this->shouldRestart = false;
    strcpy( this->appName, "-" );
    uECC_set_rng(&RNG);
    
    // pokud mame v RTC memory data o spojeni, nacteme je
    
    #ifdef ESP8266
        struct rtcData data;
        if( readRtcData( &data ) ) {
            this->logger->log( "RTC:OK" );
            memcpy( (void*)this->session, (const void *)(data.data), 32 );
            this->session[31] = 0;
            memcpy( (void*)this->sessionKey, (const void *)(data.data+32), 32 );
            this->connected = true;
        }
    #endif
    
    #ifdef ESP32
        if( rtcIndicator == RAC_RTCMEM_MARKER ) {
            this->logger->log( "RTC:OK" );
            memcpy( (void*)this->session, (const void *)(rtcSessionId), 32 );
            this->session[31] = 0;
            memcpy( (void*)this->sessionKey, (const void *)(rtcSessionKey), 32 );
            this->connected = true;
        }
    #endif
}


#ifdef DETAILED_RA_LOG
    #define LOG_BUFFER_LENGTH 100
    char log_buffer[LOG_BUFFER_LENGTH+LOG_BUFFER_LENGTH+1];
#endif



/**
 * payload: 
 *      CRC32 of data (4 byte)
 *      length of data (2 byte, low endian)
 *      data
 */
void raConnection::createDataPayload( BYTE *target, BYTE *source, int sourceLen )
{
    BYTE aes_iv[AES_BLOCKLEN];
    random_block( aes_iv, AES_BLOCKLEN );
    // tohle neni debug, ale uz si konstruuji zpravu!
    btohexa( aes_iv, AES_BLOCKLEN, (char *)target, 130 );
    
    BYTE payload[RACONN_MAX_DATA+32+1];
    payload[4] = (sourceLen>>8) & 0xff;
    payload[5] = sourceLen & 0xff;
    memcpy( payload+6, source, sourceLen );
    
    uint32_t crc1 = CRC32::calculate((unsigned char*) source, sourceLen);
    payload[0] = (BYTE)( (crc1>>24) & 0xff);
    payload[1] = (BYTE)( (crc1>>16) & 0xff);
    payload[2] = (BYTE)( (crc1>>8) & 0xff);
    payload[3] = (BYTE)(crc1 & 0xff);
    
    int celkovaDelka = sourceLen + 2 + 4;
    int zbyva = AES_BLOCKLEN - (celkovaDelka % AES_BLOCKLEN);
    if( zbyva>0 ) {  
        random_block( payload+celkovaDelka, zbyva );
    }
    
#ifdef DETAILED_RA_LOG
    this->logger->log( "  delka %d, doplneni %d", celkovaDelka, zbyva );

    btohexa( this->sessionKey, AES_KEYLEN, log_buffer, LOG_BUFFER_LENGTH );
    this->logger->log( "  session key: %s", log_buffer );
    
    this->logger->log( "  IV: %s", target );
#endif        

    celkovaDelka = celkovaDelka + zbyva;

    struct AES_ctx ctx;
    
    AES_init_ctx_iv(&ctx, (unsigned char const*)this->sessionKey, (unsigned char const*)aes_iv);
    AES_CBC_encrypt_buffer(&ctx, (unsigned char *)payload, celkovaDelka );

    char * output_ptr = (char *)target + AES_BLOCKLEN+AES_BLOCKLEN;
    output_ptr[0] = ':';
    output_ptr++;
    btohexa( payload, celkovaDelka, output_ptr, RACONN_MAX_DATA+RACONN_MAX_DATA );
    
#ifdef DETAILED_RA_LOG
    this->logger->log( "  out: %s", output_ptr );
#endif    
}


/**
 * 0 = OK
 * 1 = connection problem
 * 2 = invalid session, reconect later 
 */
int raConnection::sendInt( unsigned char * dataKOdeslani, int dataLen )
{
    if( !this->connected ) {
        this->login();
    }
    if( !this->connected ) {
        return 1;           
    }
        
    char url[256];
    sprintf( url, "http://%s/data", this->cfg->getString( "ra_url", "???" ) );
    
    this->logger->log( "%s DATA", this->identity );
    
    sprintf( (char *)this->msg, "%s\n", 
        this->session
         );

    BYTE* target = this->msg + strlen( (char *)this->msg ); 
    //D/ this->logger->log( "%s pridavam %d byte payloadu na offset +%d do this->msg", this->identity, dataLen, strlen( (char *)this->msg ) );
    this->createDataPayload( target, (BYTE*)dataKOdeslani, dataLen );
    
    int rc = this->doRequest( url, this->msg, strlen((char*)this->msg) );
    
    if( rc==0 ) {  
        // 200
        this->logger->log( "%s OK", this->identity );
        return 0; 
    } else if( rc==1 ) {  
        // 403
        this->logger->log( "%s http403 -> relogin", this->identity );
        this->connected = false; 
        return 2; 
    } else {
        this->logger->log( "%s httpERR", this->identity );
        return 1;
    }
}


/**
 * 0 = OK
 * 1 = connection problem
 * 2 = invalid session, reconect later 
 */
int raConnection::send( unsigned char * dataKOdeslani, int dataLen )
{
    int rc = this->sendInt( dataKOdeslani, dataLen );
    if( rc==2 ) {
        // provedeme jeste jednou, tim dame sanci se prihlasit - to je nutne pri zmene konfigurace
        rc = this->sendInt( dataKOdeslani, dataLen );
    }

    return rc;
}



// definovano v aes.h
// #define AES_KEYLEN 32
// #define AES_BLOCKLEN 16



/**
 * Z hesla sestavi sestavi sifrovaci klic pro login blok.
 * Zasifruje s nim verejny klic
 * Vygeneruje cely payload blok pro login a zapise ho na vystupni adresu.
 */
void raConnection::createLoginToken( BYTE *target, BYTE * ecdh_public )
{
    BYTE aes_iv[AES_BLOCKLEN];
    
    this->sha256->init();
    const char * pass = this->cfg->getString( "$ra_pass", "???" );
    this->sha256->update((const BYTE *)pass, strlen((const char *)pass));
    this->sha256->final(this->sessionKey);
    
    random_block( aes_iv, AES_BLOCKLEN );
    // tohle neni debug, ale uz si konstruuji zpravu!
    btohexa( aes_iv, AES_BLOCKLEN, (char *)target, 130 );

#ifdef DETAILED_RA_LOG
    this->logger->log( "  password: '%s'", this->cfg->getString( "$ra_pass", "???" ) );
    btohexa( this->sessionKey, SHA256_BLOCK_SIZE, log_buffer, LOG_BUFFER_LENGTH );
    this->logger->log( "  password hash: %s", log_buffer );
    
    this->logger->log( "  IV: %s", target );
#endif        
    
#define LOGIN_PAYLOAD_LEN 64
    BYTE msg[LOGIN_PAYLOAD_LEN+1] ;
    memcpy( msg, ecdh_public, LOGIN_PAYLOAD_LEN );
    
    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, (unsigned char const*)this->sessionKey, (unsigned char const*)aes_iv);
    AES_CBC_encrypt_buffer(&ctx, (unsigned char *)msg, LOGIN_PAYLOAD_LEN );

    // preskocime za IV
    char * output_ptr = (char *)target + AES_BLOCKLEN+AES_BLOCKLEN;
    output_ptr[0] = ':';
    output_ptr++;
    btohexa( msg, LOGIN_PAYLOAD_LEN, output_ptr, LOGIN_PAYLOAD_LEN+LOGIN_PAYLOAD_LEN+1 );  
    
}




char * parserPtr;

void textParser( char * text )
{
    parserPtr = text;
}

/**
 * Vrati do 'target' jednu radku ze vstupniho textu.
 * Pred spustenim je treba zavolat textParser( source );
 * Vraci true=neco nacteno, false=EOF
 */  
bool getNextLine( char * target, int targetSize )
{
    int targetPos = 0;
    target[0] = 0;

    while(1)
    {
        if( *parserPtr==0 ) {
            if( targetPos==0 ) {
                // jsme na konci a nic jsme nenacetli
                return false;
            } else {
                return true;
            }
        }

        if( *parserPtr==13 || *parserPtr==10 ) {
            if( targetPos==0 ) {
                // zatim jsme nic nenacetli, pokracujeme dal
                parserPtr++;
                continue;
            } else {
                return true;
            }
        }

        if( targetPos < (targetSize-1) ) {
            target[targetPos] = *parserPtr;
            target[targetPos+1] = 0;
            targetPos++;
        }
        parserPtr++;
    }
}

/**
 * Desifruje jeden prijaty balik v nasem formatu:
 *      <AES IV>:<AES_encrypted_data>
 * oboji zapsane jako retezec hexitu
 * 
 * POZOR! Plaintext buffer musi mit o 6+16 byte vice nez samotny obsah;
 * dekodovany obsah zacina na +6.
 * 
 * Obsah plaintextu:
 *      CRC32 of data (4 byte)
 *      length of data (2 byte, low endian)
 *      payload
 * 
 * Parametry:
 *  encryptedData = vstupni data
 *  plainText = buffer pro desifrovana data (reusing bufferu z nadrizene fce)
 * 
 * Vraci:
 *  true = dekodovani OK
 *  false = neco selhalo
 */ 
bool raConnection::decryptBlock( char * encryptedData, char * plainText, int plainTextBuffSize )
{
    char * q = strchr( encryptedData, ':' );
    if( q==NULL ) {
        return false;
    }
    if( (q-encryptedData) != (AES_BLOCKLEN*2) ) {
        this->logger->log( "%s spatny format, ':' @ %d, total=%d", this->identity, (q-encryptedData), strlen(encryptedData) );
    }
    *q = 0;
    q++;

    BYTE aes_iv[AES_BLOCKLEN+1];
    hexatob( encryptedData, aes_iv, AES_BLOCKLEN );
    hexatob( q, (BYTE*)plainText, plainTextBuffSize );

    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, this->sessionKey, aes_iv );
    AES_CBC_decrypt_buffer(&ctx, (unsigned char*)plainText, strlen(q)/2 );

    uint32_t crcReceived = (plainText[0] << 24) | (plainText[1] << 16) | (plainText[2] << 8) | plainText[3];
    int len = (plainText[4]<<8) | plainText[5];

    plainText[len+6] = 0;

    uint32_t crcComputed = CRC32::calculate((unsigned char*) plainText+6, len );

    if( crcReceived != crcComputed ) {
        this->logger->log( "%s cfg: CRC nesouhlasi moje:%x jejich:%x", this->identity, crcComputed, crcReceived );
        return false;
    }

    return true;
}


void raConnection::decryptPublicKeyBlock( char * encryptedData, char * plainText, int plainTextBuffSize )
{
    char * q = strchr( encryptedData, ':' );
    if( q==NULL ) {
        this->logger->log( "%s spatny format dat" );
        return;
    }
    if( (q-encryptedData) != (AES_BLOCKLEN*2) ) {
        this->logger->log( "%s spatny format, ':' @ %d, total=%d", this->identity, (q-encryptedData), strlen(encryptedData) );
    }
    *q = 0;
    q++;

    BYTE aes_iv[AES_BLOCKLEN+1];
    hexatob( encryptedData, aes_iv, AES_BLOCKLEN );
    hexatob( q, (BYTE*)plainText, plainTextBuffSize );

    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, this->sessionKey, aes_iv );
    AES_CBC_decrypt_buffer(&ctx, (unsigned char*)plainText, strlen(q)/2 );
}


void raConnection::parseUpdateRequest( char * encryptedData, char * plainText, int plainTextBuffSize )
{
    if( ! decryptBlock( encryptedData, plainText, plainTextBuffSize ) ) {
        return;
    }
    // plainText mame dekodovana data
    // v plainText+6 je vlastni payload
    this->logger->log( "%s update req [%s]", this->identity, plainText+6 );
    this->updateReqId = atol( (const char*)(plainText+6) );
}


/**
 * encryptedData = vstupni data
 * plainText = buffer pro desifrovana data (reusing bufferu z nadrizene fce)
 * oneLine = buffer pro jednu radku z desifrovanych dat (reusing bufferu z nadrizene fce)
 */ 
void raConnection::parseConfigData( char * encryptedData, char * plainText, int plainTextBuffSize, char * oneLine, int oneLineSize )
{
    if( ! decryptBlock( encryptedData, plainText, plainTextBuffSize ) ) {
        return;
    }

    //D/ this->logger->log( "%s cfg pt [%s]", this->identity, plainText+6 );

    // plainText mame dekodovana data
    // v plainText+6 je vlastni payload
    textParser( plainText+6 );
    if( getNextLine( oneLine, oneLineSize ) ) {
        //D/ this->logger->log( "%s config ver [%s]", this->identity, oneLine );
        int configVer = atol( oneLine );

        while( getNextLine( oneLine, oneLineSize ) ) {
            char *p = strchr( oneLine, '=' );
            if( p!=NULL ) {
                *p = 0;
                p++;
                //D/ 
                this->logger->log( "%s cfg [%s]=[%s]", this->identity, oneLine, p );
                this->cfg->setValue( oneLine, p );
            }
        }
        sprintf( oneLine, "%d", configVer );
        this->cfg->setValue( "@ver", oneLine );
        this->logger->log( "%s new config %d", this->identity, configVer );
    }
}


/**
 * Provede http POST request, zaloguje vse potrebne a vrati:
 * 0 = bylo http 200
 * 1 = chyba 403
 * 2 = jina chyba
 * Vystupni data jsou v this->httpBuffer
 */ 
int raConnection::doRequest( char * url, unsigned char * postData, int postDataLen, char * auth1, char * auth2 )
{
    HTTPClient http;
    http.begin( url );
    http.addHeader("Content-Type", "application/octet-stream");
    if( auth1 ) {
        http.addHeader("x-ra-1", auth1 );
    }
    if( auth2 ) {
        http.addHeader("x-ra-2", auth2 );
    }
    int httpCode = http.POST( (
#ifdef ESP8266    
            const 
#endif            
            uint8_t*)postData, postDataLen );
    String result = http.getString();
    if( result.length() > RACONN_MSG_LEN ) {
        this->logger->log( "%s WARN: res length %d - more than buffer %d !", this->identity, result.length(), RACONN_MSG_LEN );
    }
    strncpy( this->httpBuffer, result.c_str() , RACONN_MSG_LEN );
    result = "";
    this->httpBuffer[RACONN_MSG_LEN] = 0;
    http.end();

#ifdef DETAILED_RA_LOG
    this->logger->log( "  %s << [%s]", this->identity, this->httpBuffer );
#endif    

    if( httpCode!=200 ) {
        // error description
        this->logger->log( "%s [%s]", this->identity, this->httpBuffer );
        return ( httpCode==403 ) ? 1 : 2;
    }
    return 0;
}



void raConnection::login() 
{
    /*
    * Potrebuji tri buffery pro rozbaleni prichozi sifrovane odpovedi.
    * - this->msg[RACONN_MSG_LEN 1024] byte pro vstupni sifrovany balik v hexa tvaru
    * - url[URL_BUFFER_LEN 512] pro desifrovany balik
    * - buff[BUFF_LEN 256] pro jednu radku odpovedi v sifrovanem bloku
    * 
    * Tj. cela zmena konfigurace muze mit maximalne 500 byte, a kazda radka (jmeno=hodnota) smi mit max 256 byte
    */ 

#define BUFF_LEN 384
    char buff[BUFF_LEN];

#define URL_BUFFER_LEN 512
    // aby se usetrila pamet, "url" se pouziva i na parsovani prijate sifrovane odpovedi
    char url[URL_BUFFER_LEN+1];  
    sprintf( url, "http://%s/logina", this->cfg->getString( "ra_url", "???" ) );
    this->logger->log( "%s LOGINA", this->identity );


    char ecdh_private[33];
    char ecdh_public[65];
    char remote_ecdh_public[65];
    char ecdh_secret[33];
    const struct uECC_Curve_t * curve = uECC_secp256k1();


    // ---------------------------------------------------------------------------------
    // logina
    
    uECC_make_key( (uint8_t*)ecdh_public, (uint8_t*)ecdh_private, curve);

#ifdef DETAILED_RA_LOG    
    btohexa( (unsigned char*)ecdh_private, 32, buff, 256 );
    this->logger->log( "  ECDH private: %s", buff );
    btohexa( (unsigned char*)ecdh_public, 64, buff, 256 );
    this->logger->log( "  ECDH public: %s", buff );
#endif 

    sprintf( (char *)this->msg, "%s\n", 
        this->cfg->getString( "ra_dev_name", "???" ) );

    BYTE* target = this->msg + strlen( (char *)this->msg ); 
    this->createLoginToken( target, (BYTE*)ecdh_public );

#ifdef DETAILED_RA_LOG
    this->logger->log( "out: '%s'", this->msg );
#endif    
       
    int rc = this->doRequest( url, this->msg, strlen((char*)this->msg) );
    if( rc ) {
        this->connected = false; 
        return;
    }

    // ----------------------------------------------
    // prvni radek odpovedi = login msg id
    textParser( (char*)this->httpBuffer );
    if( ! getNextLine( (char*)this->session, 31 ) ) {
        // ve vystupnim textu neni ani jedna radka textu
        this->logger->log( "%s Neni zde nic 1.", this->identity );
        return;
    }

#ifdef DETAILED_RA_LOG
    this->logger->log( "  login msg id: %s", this->session );
#endif    

    if( ! getNextLine( (char*)this->msg, RACONN_MSG_LEN )  ) {
        this->logger->log( "%s Neni zde nic 2.", this->identity );
        return;
    }
    // v this->msg mame zasifrovany public klic protistrany
    decryptPublicKeyBlock( (char*)this->msg, remote_ecdh_public, 65 );

    int r = uECC_shared_secret( (uint8_t*)remote_ecdh_public, (uint8_t*)ecdh_private, (uint8_t*)ecdh_secret, curve );
    if( !r ) {
        this->logger->log( "chyba ECDH %d", r );
        return;
    }

    this->sha256->init();
    this->sha256->update((const BYTE *)ecdh_secret, 32 );
    this->sha256->final(this->sessionKey);

#ifdef DETAILED_RA_LOG    
    btohexa( (unsigned char*)remote_ecdh_public, 64, buff, 256 );
    this->logger->log( "  ECDH server public: %s", buff );
    btohexa( (unsigned char*)ecdh_secret, 32, buff, 256 );
    this->logger->log( "  ECDH secret: %s", buff );
    btohexa( this->sessionKey, 32, buff, 256 );
    this->logger->log( "  session key: %s", buff );    
#endif 

    // ---------------------------------------------------------------------------------
    // loginb

    sprintf( url, "http://%s/loginb?v=3", this->cfg->getString( "ra_url", "???" ) );
    this->logger->log( "%s LOGINB", this->identity );

    sprintf( (char *)this->msg, "%s\n", this->session );

    target = this->msg + strlen( (char *)this->msg ); 

    /*
    <poslední síla signálu WiFi>\n
    <uptime v sekundách>\n
    <config file version>\n
    <app name>
    */
    sprintf( buff, "%d\n%d\n%d\n%s",
        this->wifiSignalStrength,
        time(NULL),
        this->localConfigVersion,
        this->appName
    );

    this->createDataPayload( (BYTE*)target, (BYTE*)buff, strlen(buff) );

    rc = this->doRequest( url, this->msg, strlen((char*)this->msg) );
    if( rc ) {
        this->connected = false; 
        return;
    }

    textParser( (char*)this->httpBuffer );
    // prvni radka = session ID
    if( ! getNextLine( (char*)this->msg, RACONN_MSG_LEN ) ) {
        // ve vystupnim textu neni ani jedna radka textu
        this->logger->log( "%s Neni zde nic 3.", this->identity );
        return;
    }
    if( ! decryptBlock( (char*)this->msg, url, URL_BUFFER_LEN ) ) {
        return;
    }
    // v url je desifrovany blok, v url+6 je payload
    strncpy( this->session, url+6, 31 );
    this->session[31] = 0;

    // ----------------------------------------------
    // druhy radek odpovedi = zpracujeme zmeny konfigurace, pokud tam jsou
    if( getNextLine( (char*)this->msg, RACONN_MSG_LEN )  ) {
        if( strlen((const char*)this->msg) > 5 ) {
            this->parseConfigData( (char*)this->msg, url, URL_BUFFER_LEN, buff, BUFF_LEN );
        }
    }

    // ----------------------------------------------
    // treti radek odpovedi, pokud je = pozadavek na upgrade
    if( getNextLine( (char*)this->msg, RACONN_MSG_LEN )  ) {
        if( strlen((const char*)this->msg) > 5 ) {
            this->parseUpdateRequest( (char*)this->msg, url, URL_BUFFER_LEN );
        }
    }

    this->logger->log( "%s OK %s", this->identity, this->session );

    // mame session v this->session a klic v this->sessionKey
    this->connected = true;
    
    // Ulozime si data o spojeni do RTC memory
    #ifdef ESP8266
        struct rtcData data;
        memcpy( (void*)(data.data), (const void *)this->session, 32 );
        memcpy( (void*)(data.data+32), (const void *)this->sessionKey, 32 );
        saveRtcData( &data );
    #endif
    
    #ifdef ESP32
        memcpy( (void*)(rtcSessionId), (const void *)this->session, 32 );
        memcpy( (void*)(rtcSessionKey), (const void *)this->sessionKey, 32 );
        rtcIndicator = RAC_RTCMEM_MARKER;
    #endif    
}



/**
 * Odesle jednu radku hlavicky na server
 */ 
void sendText( WiFiClient * client, char * data )
{
    #ifdef BLOB_DETAIL_LOG
        Serial.printf( "> %s\n", data  );
    #endif
    client->println( data );
}

int sentLen;

/**
 * Odesle jednu radku payloadu na server
 */ 
void sendBin( WiFiClient * client, uint8_t * data, size_t len )
{
    sentLen += len;
    #ifdef BLOB_DETAIL_LOG
        Serial.printf( "> %s\n", data );
        Serial.printf( "> %d .. %d\n", len , sentLen );
    #endif
    client->write( data, len );
}

#define MAX_LINE_LEN 256
char receivedLine[MAX_LINE_LEN+2];
int pos = 0;
bool lineComplete = false;

/**
 * Zpracovani odpovedi ze serveru - slozeni jednotlivych radek z prijatych znaku.
 */ 
void addChar( char c )
{
    if( c=='\n' ) {
        lineComplete = true;
        receivedLine[pos] = 0;
    } else if( c=='\r' ) {
        receivedLine[pos] = 0;
    } else {
        if( pos < MAX_LINE_LEN ) {
            receivedLine[pos++] = c;
            receivedLine[pos] = 0;
        }
    }
}

/**
 * Vrati nactenou radku ze serveru
 */ 
char * getLine()
{
    pos = 0;
    lineComplete = false;
    //D/Serial.printf( "< [%s]\n", receivedLine  );
    return receivedLine;
}

#define BLOCKSIZE_PLAIN 512
#define BLOCKSIZE_CIPHER 1024

/**
 * Parsuje radku s http statusem
 */ 
int parseStatusLine( char * statusLine  )
{
    char * p = strchr( statusLine, ' ' );
    if( p==NULL ) {
        return -1;
    } 
    int status = atoi( p );
    Serial.printf( "\nstatus=%d\n", status );
    if( status==200 ) {
        return 0;
    }
    return status;
}

/**
 * Zpracuje radku s aplikacni odpovedi z RA
 */ 
int parseDataLine( char * dataLine, raLogger * logger )
{
    if( strcmp(dataLine,"OK") == 0 ) {
        return 0;
    } else {
        logger->log( "blob ERR: %s", dataLine );
        return -1;
    }
}

/**
 * Zaloguje sifrovaci klic a IV
 */ 
void raConnection::log_keys( unsigned char * key, unsigned char * iv )
{
#ifdef BLOB_DETAIL_LOG
    char buff[256];
    btohexa( key, AES_KEYLEN, (char*)buff, 100 );
    int pos = AES_KEYLEN+AES_KEYLEN; 
    buff[pos] = ' ';
    btohexa( iv, AES_BLOCKLEN, (char*)buff+pos+1, 100 );
    pos += 1 + AES_BLOCKLEN + AES_BLOCKLEN;
    buff[pos] = 0;
    this->logger->log( "key/iv %s", buff );      
#endif
}

/**
 * Vlastni odeslani blobu; predpoklada, ze klient je prihlaseny.
 */ 
int raConnection::sendBlobInt( unsigned char * blob, int blobSize, int startTime, char * desc, char * extension )
{
    long start = millis();
    
    char server[200];
    char url[200];
    strcpy( server, this->cfg->getString( "ra_url", "???" ) );
    char * p = strchr( server, '/' );
    if( p==NULL ) { return 1; }
    strcpy(url, p);
    strcat( url, "/blob" );
    *p = 0;
    
    this->logger->log( "%s server=[%s] url=[%s]", this->identity, server, url );
    
    unsigned char begin[256];
    sprintf( (char*)begin, "%s\n", this->session );
    
    BYTE aes_iv[AES_BLOCKLEN];
    random_block( aes_iv, AES_BLOCKLEN );
    btohexa( aes_iv, AES_BLOCKLEN, (char *)(begin+strlen((const char*)begin)), 130 );
    strcat( (char*)begin, "\n");

    unsigned char header[512];
    sprintf( (char*)header, "%d\n%s\n%s\n%d\n",
        time(NULL)-startTime,
        desc,
        extension,
        blobSize
    );

    int len = strlen((const char*)header) ;
    if( (len % AES_BLOCKLEN) != 0 ) {
        len = ((len/AES_BLOCKLEN)+1)*AES_BLOCKLEN;
    } 
    struct AES_ctx ctx;
    AES_init_ctx_iv(&ctx, (unsigned char const*)this->sessionKey, (unsigned char const*)aes_iv);
    AES_CBC_encrypt_buffer(&ctx, (unsigned char *)header, len );

    unsigned char textBuff[BLOCKSIZE_CIPHER+10];

    btohexa( header, len, (char*)textBuff, BLOCKSIZE_PLAIN );
    int headerTextLen = strlen( (const char*)textBuff );
    textBuff[headerTextLen++] = '\n';
    textBuff[headerTextLen++] = 0;

    int beginSize = strlen( (const char*)begin );
    int blobBlocks = blobSize / BLOCKSIZE_PLAIN;
    if( (blobSize % BLOCKSIZE_PLAIN)!=0 ) { blobBlocks++; }
    int blobOutSize = (BLOCKSIZE_CIPHER+1)*blobBlocks;
    int totalLen = blobOutSize + beginSize + headerTextLen ;
    sentLen = 0;

    WiFiClient client;

    if (! client.connect(server, 80)) {
        this->logger->log("blob connect failed.");
        return 2;
    } else {
        Serial.println("Connected" );
    }

    char buf[256];
    sprintf( buf, "POST %s HTTP/1.1", url );
    sendText( &client, buf );

    sprintf( buf, "Host: %s", server );
    sendText( &client, buf );
    sprintf( buf, "Content-Length: %d", totalLen );
    sendText( &client, buf );
    sendText( &client, (char *)"Content-Type: application/octet-stream" );
    sendText( &client, (char *)"" );

    totalLen = 0;
    sendBin( &client, begin, beginSize );
    sendBin( &client, textBuff, headerTextLen );

    unsigned char binData[BLOCKSIZE_PLAIN+2];

    this->logger->log( "len %d kB", blobSize/1024 );

    uint8_t *fbBuf = blob;
    for (size_t n=0; n<blobSize; n=n+BLOCKSIZE_PLAIN) {

        if( !client.connected() ) {
            this->logger->log( "-disc-");
            break;
        }

        if( n % 10240 == 0 ) {
            Serial.printf( "%d kB  ", n/1024 );
        }
        #ifdef BLOB_DETAIL_LOG
            Serial.printf( "n=%d, size=%d\n",n, blobSize );
            log_keys( (unsigned char*)this->sessionKey, (unsigned char*)aes_iv );
        #endif
        if (n+BLOCKSIZE_PLAIN < blobSize) {
            #ifdef BLOB_DETAIL_LOG
                Serial.println( "v1" );
            #endif
            memcpy( binData, fbBuf, BLOCKSIZE_PLAIN );
            AES_init_ctx_iv(&ctx, (unsigned char const*)this->sessionKey, (unsigned char const*)aes_iv);
            AES_CBC_encrypt_buffer(&ctx, binData, BLOCKSIZE_PLAIN );
            btohexa( binData, BLOCKSIZE_PLAIN, (char*)textBuff, 1025 );
            textBuff[1024] = '\n';
            textBuff[1025] = 0;
            sendBin( &client,textBuff, 1025 );
            fbBuf += BLOCKSIZE_PLAIN;
        }
        else if (blobSize % BLOCKSIZE_PLAIN > 0) {
            size_t remainder = blobSize % BLOCKSIZE_PLAIN;
            #ifdef BLOB_DETAIL_LOG
                Serial.printf( "v2 remainder=%d\n", remainder );
            #endif
            memcpy( binData, fbBuf, remainder );
            AES_init_ctx_iv(&ctx, (unsigned char const*)this->sessionKey, (unsigned char const*)aes_iv);
            AES_CBC_encrypt_buffer(&ctx, binData, BLOCKSIZE_PLAIN );
            btohexa( binData, BLOCKSIZE_PLAIN, (char*)textBuff, 1025 );
            textBuff[1024] = '\n';
            textBuff[1025] = 0;
            sendBin( &client,textBuff, 1025 );
        }
    }   

    int timeoutTimer = RA_BLOB_TIMEOUT;
    long startTimer = millis();

    /**
     * Stavovy automat pro zpracovani vysledku. Vnitrni stavy:
     * 0 = cekam na http status line
     * 1 = cekam na konec hlavicky
     * 2 = cekam na prvni radek dat
     * 3 = hotovo
     */
    int status = 0;
    int rc = 3;

    Serial.println("");

    while( (status!=3) && (startTimer + timeoutTimer) > millis() ) {
        if( !client.connected() ) {
            this->logger->log( "-disc-");
            rc = 10;
            status = 3;
            break;
        }

        while (client.available()) {
            addChar( client.read() );
            if( lineComplete ) {
                startTimer = millis();
                char * line = getLine();
                if( status==0 ) {
                    rc=parseStatusLine( line );
                    if( rc!=0 ) {
                        this->logger->log( "blob status %d", rc );
                        status = 3;
                        break;
                    } 
                    status = 1;
                } else if( status==1 ) {
                    if( strlen(line)==0 ) {
                        // prazdna radka = konec hlavicky
                        status = 2;
                    }
                } else if( status==2 ) {
                    if( 0==parseDataLine( line, this->logger ) ) {
                        rc = 0;
                    } else {
                        rc = 4;
                    }
                    status = 3;
                    break;
                }
            }
        } // while (client.available()) {

        delay(100);      
        Serial.print(".");
    }
    client.stop();
    
    this->logger->log( "BLOB rc=%d, %d s", rc, ((millis()-start)/1000) );
    
    return rc;
}

/**
 * Odesle blob. Pokud neni prihlaseno, prihlasi.
 * 0 = OK, jina hodnota = chyba; >=300 http result code.
 */ 
int raConnection::sendBlob( unsigned char * blob, int blobSize, int startTime, char * desc, char * extension )
{
    this->logger->log( "%s BLOB+", this->identity );

    if( !this->connected ) {
        this->login();
    }
    if( !this->connected ) {
        this->logger->log( "%s BLOB-ERR no login, no sendin'", this->identity );
        return 1;           
    }
    
    int rc = this->sendBlobInt( blob, blobSize, startTime, desc, extension );
    if( rc!=0 ) {
        this->logger->log( "%s BLOB ERR %d, reconnect", this->identity, rc );
        this->login();
        if( this->connected ) {
            rc = this->sendBlobInt( blob, blobSize, startTime, desc, extension );               
        }
    }

    if( rc==0 ) {    
        this->logger->log( "%s BLOB-OK", this->identity );
    } else {
        this->logger->log( "%s BLOB-ERR %d", this->identity, rc );
    } 
    return rc;
}
    

/**
 * Pokud neni spojene, prihlasi se
 * Vraci true = prihlaseno; false = neprihlaseno
 */ 
bool raConnection::isConnected()
{
    if( !this->connected ) {
        this->login();
    }
    if( !this->connected ) {
        return false;           
    }
    return true;
}

void raConnection::setAppName( const char * appName )
{
    strncpy( this->appName, appName, RA_APP_NAME_SIZE );
    this->appName[RA_APP_NAME_SIZE-1] = 0;
}

void raConnection::setConfigVersion( int version )
{
    this->localConfigVersion = version;
}

void raConnection::setRssi( int rssi )
{
    this->wifiSignalStrength = rssi;
}

#ifdef LOG_SHIPPING
    bool raConnection::sendLogData( char * data )
    {
        if( !this->connected ) {
            this->login();
        }
        if( !this->connected ) {
            return false;           
        }

        char url[256];
        sprintf( url, "http://%s/log", this->cfg->getString( "ra_url", "???" ) );

        int dataLen = strlen(data);

        BYTE sha256hash[AES_KEYLEN];
        BYTE header[256];
        
        this->sha256->init();
        this->sha256->update((const BYTE *)data, dataLen );
        this->sha256->final(sha256hash);

        //D/ btohexa( sha256hash, AES_KEYLEN, (char *)header, 255 );
        //D/ this->logger->log( "hash: %s", header );

        this->createDataPayload( header, sha256hash, AES_KEYLEN );

        return 0 == this->doRequest( url, (unsigned char*)data, dataLen, this->session, (char*)header );
    }
#endif

#ifdef OTA_UPDATE
    /*
    23:30:28.526 -> < Date: Thu, 22 Apr 2021 21:30:28 GMT
    23:30:28.558 -> < Server: Apache/2.4.29 (Ubuntu)
    23:30:28.577 -> < X-Powered-By: Nette Framework 3
    23:30:28.603 -> < X-Content-Type-Options: nosniff
    23:30:28.624 -> < X-XSS-Protection: 1; mode=block
    23:30:28.645 -> < X-Frame-Options: SAMEORIGIN
    23:30:28.662 -> < Set-Cookie: nette-samesite=1; path=/; SameSite=Strict; HttpOnly
    23:30:28.707 -> < Vary: X-Requested-With
    23:30:28.731 -> < Content-Disposition: inline; filename="update.bin"; filename*=utf-8''update.bin
    23:30:28.800 -> < Accept-Ranges: bytes
    23:30:28.820 -> < Content-Range: bytes 0-671777/671778
    23:30:28.850 -> < Content-Length: 671778
    23:30:28.877 -> < Content-Type: application/octet-stream
    */

    char * headerName;
    char * headerContent;
    
    /**
     * Rozlozi header na jmeno a obsah
     * Vraci true = podarilo se, false = spatny format
     */ 
    bool splitHeader( char * header ) 
    {
        char * p = strchr( header, ':' );
        if( p==NULL ) return false;
        *p = 0;
        p++;
        if(*p != ' ' ) return false;
        p++;
        headerName = header;
        headerContent = p;
        return true;
    }

    bool hdrContentTypeOK;
    int hdrContentLength;

    /**
     * Analyzuje header a plni content-type a content-length promenne
     */ 
    void analyzeHeader( char * header, char * secHeaderContent )
    {
        if( ! splitHeader( header )) return;
        
        if( strcmp( headerName, "Content-Type" ) == 0 ) {
            if( strcmp(headerContent, "application/octet-stream" ) == 0 ) {
                hdrContentTypeOK = true;
                Serial.print( " CT:OK ");
            } else {
                Serial.printf( " CT:[%s] ", headerContent );
            }
        } else if( strcmp( headerName, "Content-Length" ) == 0 ) {
            hdrContentLength = atoi( headerContent );
            Serial.print( " CL ");
        } else if( strcmp( headerName, "x-ra-1" ) == 0 ) {
            strcpy( secHeaderContent, headerContent );
            Serial.print( " XR1 ");
        }
    }



    /**
     * OTA update
     *  1 = obecna chyba
     *  2 = chyba spojeni
     *  3 = chyba nacitani dat
     *  0 = OK
     */ 
    bool raConnection::doUpdate( long updateId )
    {
        long start = millis();
        
        char server[200];
        char url[200];
        strcpy( server, this->cfg->getString( "ra_url", "???" ) );
        char * p = strchr( server, '/' );
        if( p==NULL ) { return 1; }
        strcpy(url, p);
        sprintf( url+strlen(url), "/update/%d", updateId );
        *p = 0;
        
        this->logger->log( "UPD server=[%s] url=[%s]", server, url );
        
        WiFiClient client;

        if (! client.connect(server, 80)) {
            this->logger->log("blob connect failed.");
            return 2;
        } else {
            Serial.println("Connected" );
        }

        char buf[256];
        sprintf( buf, "GET %s HTTP/1.1", url );
        sendText( &client, buf );

        sprintf( buf, "Host: %s", server );
        sendText( &client, buf );

        // autorizacni hlavicka
        char authHeader[65];
        this->createDataPayload( (BYTE*)authHeader, (BYTE*)this->session, strlen(this->session) );
        sprintf( buf, "x-ra-1: %s", authHeader );
        sendText( &client, buf );

        sendText( &client, (char *)"" );

        long endTimer = millis() + RA_UPDATE_TIMEOUT;

        /**
         * Stavovy automat pro zpracovani vysledku. Vnitrni stavy:
         * 0 = cekam na http status line
         * 1 = cekam na konec hlavicky
         * 2 = konec hlavicky
         */
        int status = 0;
        int rc = 3;

        Serial.println("");

        hdrContentTypeOK = false;
        hdrContentLength = 0;
        buf[0] = 0;

        while( (status!=2) && endTimer > millis() ) {
            if( !client.connected() ) {
                this->logger->log( "-disc-");
                rc = 10;
                status = 2;
                break;
            }
            while (client.available()) {
                addChar( client.read() );
                if( lineComplete ) {
                    endTimer = millis() + RA_UPDATE_TIMEOUT;
                    char * line = getLine();
                    if( status==0 ) {
                        rc=parseStatusLine( line );
                        if( rc!=0 ) {
                            this->logger->log( "UPD status %d", rc );
                            status = 2;
                            break;
                        } 
                        status = 1;
                    } else if( status==1 ) {
                        //D/ this->logger->log( "< %s", line );
                        if( strlen(line)==0 ) {
                            // prazdna radka = konec hlavicky
                            status = 2;
                            break;
                        }
                        analyzeHeader( line, buf  );
                    }
                }
            } // while (client.available()) {
            Serial.print(".");
            delay(100);      
        }
        
        Serial.println("");

        if( rc==0 && ( (!hdrContentTypeOK) || (hdrContentLength==0) ) ) {
            this->logger->log( "UPD bad content type / length" );
            rc = 11;
        }

        BYTE serverHashBuffer[32+6+17];
        BYTE * serverHash = serverHashBuffer + 6;
        if( rc==0 ) {
            if( ! decryptBlock( (char*)buf, (char*)serverHashBuffer, 32+6+17 ) ) {
                this->logger->log( "UPD can't decrypt" );
                rc = 14;
            }
        }

        bool updateStarted = false;
        if( rc==0 ) {
            if( ! Update.begin(hdrContentLength) ) {
                this->logger->log( "UPD Nemohu zacit update o velikosti %d b!", hdrContentLength );
                rc = 21;
            }
            this->logger->log( "UPD start" );
            updateStarted = true;
        }

        if( rc==0 ) {
            this->logger->log( "UPD len=%d", hdrContentLength );

            this->sha256->init();
            
            // jsme na konci hlavicky, nacitat po blocich data¨
            #define DATABUF_LEN 2048
            byte dataBuf[DATABUF_LEN];

            int dataRemaining = hdrContentLength;
            int lastLog = dataRemaining;

            while( dataRemaining>0 && endTimer > millis() ) {
                if( !client.connected() ) {
                    this->logger->log( "-disc-");
                    rc = 12;
                    break;
                }
                if( !client.available() ) {
                    Serial.print(".");
                    delay(100);   
                    continue;
                }
                int bytesRead = client.readBytes( dataBuf, dataRemaining>DATABUF_LEN ? DATABUF_LEN : dataRemaining );
                if( bytesRead>0 ) {
                    endTimer = millis() + RA_UPDATE_TIMEOUT;
                    // pocitat sha256 hash
                    this->sha256->update((const BYTE *)dataBuf, bytesRead );
                    dataRemaining = dataRemaining - bytesRead;
                    if( lastLog-dataRemaining > 50000 ) {
                        lastLog = dataRemaining;
                        Serial.printf( " %d ", dataRemaining/1000 );
                    }
                    // poslat data do update
                    Update.write( (BYTE *)dataBuf, bytesRead );
                }
            } // while( dataRemaining>0 ) {

            if( dataRemaining == 0 ) {
                Serial.println("");

                this->logger->log( "UPD read %d b", hdrContentLength );
                // zkontrolovat hash
                this->sha256->final( (BYTE*)server);                // reuse buffer
                if( memcmp(serverHash,server,32)!=0 ) {
                    this->logger->log( "UPD invalid hash" );
                    btohexa( (unsigned char*)server, 32, (char *)url, 200 );    // reuse buffer
                    this->logger->log( "UPD our hash: %s", url );
                    //D/ btohexa( (unsigned char*)serverHash, 32, (char *)url, 200 );    // reuse buffer
                    //D/ this->logger->log( "UPD their hash: %s", url );

                    Update.abort();
                    rc = 15;
                } else {
                    // dokoncit update
                    if (Update.end() ) {
                        this->logger->log( "UPD end" );
                        if (Update.isFinished()) {
                            this->logger->log( "UPD finished OK!" );
                            // nastavit znacku, ze je treba restartovat (ale bylo by dobre zkusit log shiping)
                            this->shouldRestart = true;
                            rc = 0;
                        } else {
                            this->logger->log( "UPD ERR not finished, err #%d %s", Update.getError(), Update.errorString() );
                            rc = 22;
                        }
                    } else {
                        this->logger->log( "UPD ERR #%d %s", Update.getError(), Update.errorString() );
                        rc = 23;
                    }
                }
            } else {
                this->logger->log( "UPD nenacteno %d b", dataRemaining );
                rc = 13;
            }
        } // if( status==2 && rc==0 ) {

        if( updateStarted && rc!=0 ) {
            this->logger->log( "UPD aborting" );
            Update.abort();
        }

        client.stop();
        
        this->logger->log( "UPD rc=%d; %d s", rc, ((millis()-start)/1000) );
        
        return rc;        
    }
#endif
