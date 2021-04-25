<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\Strings;
use Nette\Utils\Random;
use App\Exceptions\NoSessionException;
use App\Services\Logger;
use Nette\Application\Responses\FileResponse;

// https://github.com/phpecc/phpecc

use Mdanter\Ecc\EccFactory;
use Mdanter\Ecc\Serializer\Point\UncompressedPointSerializer;
use Mdanter\Ecc\Crypto\Key\PublicKeyInterface;
use Mdanter\Ecc\Crypto\Key\PrivateKeyInterface;


final class RaPresenter extends Nette\Application\UI\Presenter
{
    use Nette\SmartObject;

    const NAME = 'ra-conn';
    
    /** @var \App\Services\RaDataSource */
    private $datasource;
    
    /** @var \App\Services\MsgProcessor */
    private $msgProcessor;

    /** @var \App\Services\Config */
    private $config;

    private $generator256k1;

    public function __construct(\App\Services\RaDataSource $datasource, \App\Services\MsgProcessor $msgProcessor, \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->msgProcessor = $msgProcessor;
        $this->config = $config;
        $this->generator256k1 = EccFactory::getSecgCurves()->generator256k1();
    }


    private $myPrivateKey;
    
    private function loadPublicKeyFromString( $string ) : PublicKeyInterface
    {
        $x = gmp_init(substr($string, 0, strlen($string) / 2), 16);
        $y = gmp_init(substr($string, strlen($string) / 2), 16);
        $point = $this->generator256k1->getCurve()->getPoint($x, $y);    // vraci PointInterface
        return $this->generator256k1->getPublicKeyFrom($point->getX(), $point->getY());
    }

    private function generateMyKey()
    {
        $adapter = EccFactory::getAdapter();
        $this->myPrivateKey = $this->generator256k1->createPrivateKey();
    }

    private function getPublicForMyKey()
    {
        $point = $this->myPrivateKey->getPublicKey()->getPoint();
        //TODO: Pokud je nektera cast kratsi nez 64 chars, tak by se mela doplnit nulami (a otestovat kompatibilitu!). 
        //TODO: V tento okamzik reseno cyklem, ktery pregeneruje klic, pokud public nema 128 chars.
        return gmp_strval($point->getX(), 16)  . gmp_strval($point->getY(), 16);
    }

    public function actionLogina()
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );
        $device = false;

        try {
            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("La;{$remoteIp}");

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "logina+ {$postSize}b IP:{$remoteIp}" );
            //D/ $logger->write( Logger::INFO, "RA:logina+ [{$httpRequest->getRawBody()}]" );

            $radky = explode ( "\n" , $httpRequest->getRawBody(), 10 );
            if( count($radky)<2 ) {
                throw new \Exception("Bad request (1).");                
            }
            $login = Strings::trim($radky[0]);
            $logger->setContext("La;{$remoteIp};{$login}");

            $inToken = Strings::trim($radky[1]); 
            if( Strings::length( $login ) == 0  ) {
                throw new \Exception("Empty login.");
            } 
        
            $device = $this->datasource->getDeviceInfoByLogin( $login );
            if( $device == NULL ) {
                throw new \Exception("Login '{$login}' not found.");
            }
            
            // z hesla udelat hash
            $passphrase = $this->config->decrypt( $device->passphrase, $login );
            $aesKey = hash("sha256", $passphrase, true);

            //D/ $aesKeyHex = bin2hex($aesKey); 
            //D/ $logger->write( Logger::INFO, "pass hash: {$aesKeyHex}");
            
            // payload rozdelit na IV a cryptext
            $payload = explode ( ":" , $inToken, 10 );
            if( count($payload)<2 ) {
                $this->datasource->badLogin( $device->id );
                throw new \Exception("Bad request (2).");                
            }
            $aesIvHex = Strings::trim($payload[0]);
            // $logger->write( Logger::INFO, "iv: {$aesIvHex}");
            $aesIV = hex2bin( $aesIvHex );  

            $aesDataHex = Strings::trim($payload[1]);
            // $logger->write( Logger::INFO, "data: {$aesDataHex}");
            $aesData = hex2bin( $aesDataHex );
            
            $ecdh_mcu_public = openssl_decrypt($aesData, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $aesIV );
            if( $ecdh_mcu_public == FALSE ) {
                $this->datasource->badLogin( $device->id );
                $logger->write( Logger::ERROR,  "nelze rozbalit" );
                throw new \Exception("Bad crypto block (1).");
            }

            $ecdh_mcu_public_hex = bin2hex($ecdh_mcu_public);
            //D/ $logger->write( Logger::INFO, "ECDH MCU public: {$ecdh_mcu_public_hex}" );
            $pubkey = $this->loadPublicKeyFromString( $ecdh_mcu_public_hex );

            while( true ) {
                $this->generateMyKey();
                $mypublic = $this->getPublicForMyKey();
                if( strlen($mypublic)==128 ) {
                    break;
                } 
                // pokud je kratsi, vypocet klicu se nam nesejde s MCU - je treba vygenerovat novy, viz TODO u getPublicForMyKey()
                $logger->write( Logger::INFO, 'public key shorter than 128' );        
            }
            //D/ $logger->write( Logger::INFO,  "my private: " . gmp_strval($this->myPrivateKey->getSecret(), 16) );
            //D/ $logger->write( Logger::INFO,  "my public: " . $mypublic );

            $exchange = $this->myPrivateKey->createExchange($pubkey);
            $shared = $exchange->calculateSharedKey();
            $secretHex = gmp_strval($shared, 16);
            while( strlen($secretHex)<64 ) {
                $secretHex = '0' . $secretHex;
            }
            //D/ $logger->write(  Logger::INFO,  "secret: " .  $secretHex );
            $sessionKeyHex = hash("sha256", hex2bin($secretHex), false );
            //D/ $logger->write(  Logger::INFO,  "session key: {$sessionKeyHex}" );

            // zalozit session
            $hash = Random::generate(8, '0-9A-Za-z');
            $sessionCode = $this->datasource->createLoginaSession( $device->id, 
                                                            $hash, 
                                                            $sessionKeyHex,
                                                            $remoteIp );
            $sessionId = "{$sessionCode}:{$hash}";

            $aesIV = openssl_random_pseudo_bytes ( 16, $cstrong );
            $encrypted = openssl_encrypt( hex2bin($mypublic), 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA , $aesIV );   // | OPENSSL_ZERO_PADDING
            if( $encrypted === FALSE ) {
                $logger->write( Logger::ERROR,  "nelze zasifrovat" );
                throw new \Exception("Chyba sifrovani (s1).");
            }
            
            $this->template->sessionId = $sessionId;
            $this->template->publicKey = bin2hex( $aesIV ) . ':' . bin2hex($encrypted);

            //D/ $logger->write(  Logger::INFO,  "data: {$this->template->publicKey}" );

            $logger->write( Logger::INFO, "logina-OK D:{$device->id} S:{$sessionId}" );

        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb

            $errMsg = $e->getMessage();
            if( get_class($e) == 'Mdanter\Ecc\Exception\PointNotOnCurveException' )  {
                $errMsg = "Bad_password";

                if( $device ) {
                    $this->datasource->badLogin( $device['id'] );
                }
            }

            Logger::log( 'audit', Logger::WARNING, "RA login: " . get_class($e) . ": " . $e->getMessage() );
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$errMsg}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }

    
    private function getAppId( $appName ) 
    {
        $rc = '-';
        if( substr($appName, 0, 1)=='[' ) {
            $pos = strpos($appName, ']' );
            if( $pos!==false ) {
                $rc = substr($appName, 1, $pos-1 );
            }
        }
        return $rc;
    }


    public function actionLoginb( $v=1 )
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );

        try {
            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("Lb;{$remoteIp}");

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "loginb+ v{$v} {$postSize}b IP:{$remoteIp}" );
            //D/ $logger->write( Logger::INFO, "RA:logina+ [{$httpRequest->getRawBody()}]" );

            $radky = explode ( "\n" , $httpRequest->getRawBody(), 10 );
            if( count($radky)<2 ) {
                throw new \Exception("Bad request (1).");                
            }
            $loginSessionId = Strings::trim($radky[0]);
            if( Strings::length( $loginSessionId ) == 0  ) {
                throw new \Exception("Empty login id.");
            }            
            $inDataBlock = Strings::trim($radky[1]); 
            if( Strings::length( $inDataBlock ) == 0  ) {
                throw new \Exception("Empty datablock.");
            }            

            $loginSessionData = explode( ":", $loginSessionId, 3 );
            if( count($loginSessionData)<2 ) {
                throw new \Exception("Bad request (3).");                
            }

            $sessionDevice = $this->datasource->checkLoginSession( $loginSessionData[0], $loginSessionData[1] );
            $logger->setContext("Lb;{$remoteIp};D:{$sessionDevice->deviceId}");

            $appInfoDecoded = $this->decryptDataBlock( $inDataBlock, $sessionDevice->sessionKey, $logger );
            //D/ $logger->write( Logger::INFO, "[{$appInfoDecoded}]" );
            /*
            <poslední síla signálu WiFi>\n
            <uptime v sekundách>\n
            <config file version>\n
            <app name>
            */
            $appInfo = explode ( "\n" , $appInfoDecoded, 10 );
            if( count($appInfo)<4 ) {
                throw new \Exception("Bad request (4).");                
            }
            $rssi = $appInfo[0];
            $uptime  = $appInfo[1];
            $configVer =  $appInfo[2];
            $appName = $appInfo[3];
            $appId = $this->getAppId( $appName );
            $logger->write( Logger::INFO, "uptime={$uptime} rssi={$rssi} cfg={$configVer} [{$appName}]" );

            $device = $this->datasource->getDeviceInfoById( $sessionDevice->deviceId );
            if( $device == NULL ) {
                throw new \Exception("Device '{$sessionDevice->deviceId}' not found.");
            }

            $this->template->config = ($v<3) ? '' : '-';
            
            if( $device['config_ver']!="" && $device['config_data']!="" ) {
                if( $device['config_ver'] != $configVer ) {
                    // poslat do zarizeni zmenu konfigurace
                    $logger->write( Logger::INFO, "cfg ver db:{$device['config_ver']}, dev:{$configVer}" );
                    if( isset($device['config_data']) && strlen($device['config_data'])>0 ) {
                        $config = "{$device['config_ver']}\n{$device['config_data']}";
                        $this->template->config = $this->encryptDataBlock( $config, $sessionDevice->sessionKey, $logger );
                    } else {
                        $logger->write( Logger::WARNING, "zarizeni ma jinou verzi nez ma mit, ale neceka zadna konfigurace k odeslani" );
                        //TODO: nastavit verzi konfigurace v DB na stejnou, jakou ma zarizeni
                    }
                } else {
                    // zarizeni ma spravnou verzi - pokud je vyplnen text k odeslani, je mozno ho smazat
                    if( $device['config_data'] ) {
                        $logger->write( Logger::INFO, "cfg je OK {$configVer}, mazu cekajici pozadavek" );
                        $this->datasource->deleteConfigRequest( $device->id );
                    }
                }
            } 

            $this->template->update = '';
            if( $v>=3 && $appId!=='-') {
                // mame appId a klienta, co umi OTA
                $update = $this->datasource->getUpdate( $sessionDevice->deviceId , $appId);
                if( $update ) {
                    $logger->write( Logger::INFO, "update {$update['id']}" );
                    $this->template->update = $this->encryptDataBlock( '' . $update['id'], $sessionDevice->sessionKey, $logger );
                }
            }
        
            $hash = Random::generate(8, '0-9A-Za-z');
            $sessionCode = $this->datasource->createSessionV2( 
                                                            $sessionDevice->sessionId,
                                                            $sessionDevice->deviceId, 
                                                            $device->first_login==NULL, 
                                                            $hash, 
                                                            $sessionDevice->sessionKey,
                                                            $remoteIp,
                                                            $appName,
                                                            $uptime,
                                                            $rssi );

            $sessionId = "{$sessionCode}:{$hash}";

            $this->template->sessionId = $this->encryptDataBlock( $sessionId, $sessionDevice->sessionKey, $logger );

            $logger->write( Logger::INFO, "loginb-OK D:{$device->id} S:{$sessionCode} cfg:{$configVer}" );


        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb

            Logger::log( 'audit', Logger::WARNING, "RA login: " . get_class($e) . ": " . $e->getMessage() );
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }

    

    /**
     * Dekrypce datoveho bloku.
     * 
     * Datovy blok ma format:
     *   <AES IV>:<AES_encrypted_data>
     * 
     * Obsahem sifrovanych dat je:
     *   CRC32 of data (4 byte)
     *   length of data (2 byte, high endian)
     *   payload_data
     *
     */
    private function decryptDataBlock( $data, $sessionKey, $logger )
    {
        // payload rozdelit na IV a cryptext
        $payload = explode ( ":" , $data, 10 );
        if( count($payload)<2 ) {
            throw new \Exception("Bad request (2).");                
        }
        $aesIvHex = Strings::trim($payload[0]);
        //D $logger->write( Logger::INFO, "iv: {$aesIvHex}");
        $aesIV = hex2bin( $aesIvHex );  

        $aesDataHex = Strings::trim($payload[1]);
        //D $logger->write( Logger::INFO, "data: {$aesDataHex}");
        $aesData = hex2bin( $aesDataHex );
        
        $decrypted = openssl_decrypt($aesData, 'AES-256-CBC', hex2bin($sessionKey), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $aesIV );
        if( $decrypted == FALSE ) {
            $logger->write( Logger::ERROR,  "nelze rozbalit" );
            throw new \Exception("Bad crypto block (1).");
        }

        $dataLen = (ord($decrypted[4])<<8) | ord($decrypted[5]);
        //D $logger->write( Logger::INFO,  "data len {$dataLen}" );
        
        $crcReceived = bin2hex( substr( $decrypted, 0, 4 ));
        $msgTotal = substr( $decrypted, 6, $dataLen );  
        $hash = hash( "crc32b", $msgTotal, FALSE );
        
        if( strcmp( $hash, $crcReceived ) != 0 ) {
            $logger->write( Logger::ERROR,  "Nesouhlasi CRC. Prijato: {$crcReceived} Spocteno: {$hash}" );
            throw new \Exception("Bad CRC.");
        }

        return $msgTotal;
    }


    /**
     * Zasifruje data block a vrati ho jako retezec hexacisel
     * 
     *  Datovy blok ma format:
     *   <AES IV>:<AES_encrypted_data>
     * 
     * Obsahem sifrovanych dat je:
     *   CRC32 of data (4 byte)
     *   length of data (2 byte, high endian)
     *   payload_data
     */
    private function encryptDataBlock( $data, $sessionKey, $logger )
    {
        $aesIV = openssl_random_pseudo_bytes ( 16, $cstrong );
        $aesIvHex = bin2hex( $aesIV );

        // hash vraci hexaretezec
        $hash = hash( "crc32b", $data, FALSE );

        $len = strlen( $data);
        if( $len>65535 ) {
            throw new \Exception("Too long {$len}.");
        }
        $allLen = $len + 4 + 2;
        $zbytek = $allLen % 16;
        if( $zbytek==0 ) {
            $padding = "";
        } else {
            $padding = openssl_random_pseudo_bytes ( $zbytek, $cstrong );
        }

        $plaintext = hex2bin($hash) . chr(($len>>8)&255) . chr($len&255) . $data . $padding;

        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', hex2bin($sessionKey), OPENSSL_RAW_DATA , $aesIV );   // | OPENSSL_ZERO_PADDING
        if( $encrypted === FALSE ) {
            $logger->write( Logger::ERROR,  "nelze zasifrovat" );
            throw new \Exception("Chyba sifrovani (s1).");
        }
        
        return $aesIvHex . ':' . bin2hex($encrypted);
    }


    /** 
     * Serverova strana log shippingu
     */
    public function actionLog()
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );

        try {
            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("LS;{$remoteIp}");

            $payload = $httpRequest->getRawBody();
            $postSize = strlen( $payload );
            $logger->write( Logger::INFO, "data+ post {$postSize}b");

            $session = $httpRequest->getHeader('x-ra-1');
            if( Strings::length( $session ) == 0  ) {
                throw new \Exception("Empty session ID.");
            } 
            
            $sessionData = explode( ":", $session, 3 );
            if( count($sessionData)<2 ) {
                throw new \Exception("Bad request (3).");                
            }
            $sessionDevice = $this->datasource->checkSession( $sessionData[0], $sessionData[1] );
            //D $logger->write( Logger::INFO,  $sessionDevice );
            $logger->setContext("LS;{$remoteIp};D:{$sessionDevice->deviceId}");

            $hash = $this->decryptDataBlock( $httpRequest->getHeader('x-ra-2'), $sessionDevice->sessionKey, $logger );
            //D/ $logger->write( Logger::DEBUG, 'hash incoming: ' . bin2hex($hash) );

            $hashComputed = hash("sha256", $payload, true);
            //D/ $logger->write( Logger::DEBUG, 'hash computed: ' . bin2hex($hashComputed) );

            if( strcmp($hashComputed, $hash) != 0 ) {
                throw new \Exception("Invalid hash.");
            }

            $loggerDevice = new Logger( "dev-{$sessionDevice->deviceId}" );
            $loggerDevice->write( Logger::INFO, "\n" .  $payload );

            $this->template->result = "OK";
            
        } catch (\App\Exceptions\NoSessionException $e) { 

            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();

        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb
        
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S400_BAD_REQUEST );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }


    /**
     * Format data zpravy:
     *      <session_id>\n
     *      <data_payload_block>\n
     *      
     * Format data_paylod_block:
     *      <AES IV>:<AES_encrypted_data>
     * oboji zapsane jako retezec hexitu
     * 
     * Obsah AES_encrypted_data:
     *      <CRC32 z desifrovaneho payloady><delka dat 2 byte><data><random padding>
     *      
     * Result:
     *      200 - OK
     *      403 - re-login, session invalid
     *      400 - other error
    */
    public function actionData()
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );

        try {
            
            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("D;{$remoteIp}");

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "data+ post {$postSize}b");
            //D $logger->write( Logger::INFO, "[" . $httpRequest->getRawBody() ."]" );

            $radky = explode ( "\n" , $httpRequest->getRawBody(), 3 );
            if( count($radky)<2 ) {
                throw new \Exception("Bad request (1).");                
            }
            $session = Strings::trim($radky[0]);
            $data = Strings::trim($radky[1]);
            
            if( Strings::length( $session ) == 0  ) {
                throw new \Exception("Empty session ID.");
            } 
            
            $sessionData = explode( ":", $session, 3 );
            if( count($sessionData)<2 ) {
                throw new \Exception("Bad request (3).");                
            }
            $logger->write( Logger::INFO, "S:{$sessionData[0]}"); 
            $sessionDevice = $this->datasource->checkSession( $sessionData[0], $sessionData[1] );
            $logger->setContext("D;{$remoteIp};D:{$sessionDevice->deviceId}");

            //D $logger->write( Logger::INFO,  $sessionDevice );
            
            $msgTotal = $this->decryptDataBlock( $data, $sessionDevice->sessionKey, $logger );

            //D/ $logger->write( Logger::INFO,  '  celek: ' . bin2hex($msgTotal) );
            $this->msgProcessor->process( $sessionDevice, $msgTotal, $remoteIp, $logger );  

            $logger->write( Logger::INFO, "OK");

            $this->template->result = "OK";
            
        } catch (\App\Exceptions\NoSessionException $e) { 

            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();

        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb
        
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S400_BAD_REQUEST );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }



    /**
     * Format data zpravy:
     *      <session_id>\n
     *      <AES IV>\n
     *      <AES blok hlavicky> 
     *                          Ten obsahuje nasledujici plaintext jako TEXT:
     *                                <time offset>\n       cas porizeni blobu
     *                                <description>
     *                                <extension>           jpg, csv
     *                                <delka dat>
     *                                
     */
    public function actionBlob()
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );

        try {
            $httpRequest = $this->getHttpRequest();
            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("B;{$remoteIp}");            

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "blob+ post {$postSize} B");
            // $logger->write( Logger::INFO, "[" . $httpRequest->getRawBody() ."]" );

            $radky = explode ( "\n" , $httpRequest->getRawBody() );
            if( count($radky)<2 ) {
                throw new \Exception("Bad request (1).");                
            }

            // session ID
            $session = Strings::trim($radky[0]);
            if( Strings::length( $session ) == 0  ) {
                throw new \Exception("Empty session ID.");
            } 
            $sessionData = explode( ":", $session, 3 );
            if( count($sessionData)<2 ) {
                throw new \Exception("Bad request (3).");                
            }
            $logger->write( Logger::INFO, "S:{$sessionData[0]}"); 
            $sessionDevice = $this->datasource->checkSession( $sessionData[0], $sessionData[1] );
            $logger->setContext("B;{$remoteIp};D:{$sessionDevice->deviceId}");

            //D $logger->write( Logger::INFO,  $sessionDevice );
            
            //TODO: kontrolovat zmenu IP adresy a v pripade zmeny vratit 403 ?
            
            // AES IV
            $aesIvHex = Strings::trim($radky[1]);
            //D $logger->write( Logger::INFO, "iv: {$aesIvHex}");
            $aesIV = hex2bin( $aesIvHex );  

            $headerCipherText = Strings::trim($radky[2]);
            $aesData = hex2bin( $headerCipherText );
            $decrypted = openssl_decrypt($aesData, 'AES-256-CBC', hex2bin($sessionDevice->sessionKey), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $aesIV );
            if( $decrypted == FALSE ) {
                $logger->write( Logger::ERROR,  "nelze rozbalit (1)" );
                throw new \Exception("Bad crypto block (1).");
            }
            $header = explode ( "\n" , $decrypted );
            $blobTime = time() - intval($header[0]);
            $blobDesc = $header[1];
            $blobExtension = $header[2];
            $blobLen = intval($header[3]);
            $logger->write( Logger::INFO,  "RA:blob [{$blobDesc}] ext={$blobExtension} t=-{$header[0]} l={$blobLen}" );

            if( 2*$blobLen > ($postSize - strlen($radky[0]) - strlen($radky[1]) - strlen($radky[2])) ) {
                $logger->write( Logger::INFO,  "blob: nekompletni data!" );
                throw new \Exception("Incomplete POST data.");
            }

            // data

            $outData = '';
            $size = $blobLen;
            $row = 3;
            while( $size>0 ) {
                $dataCipherText = Strings::trim($radky[$row]);
                $aesData = hex2bin( $dataCipherText );
                $decrypted = openssl_decrypt($aesData, 'AES-256-CBC', hex2bin($sessionDevice->sessionKey), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $aesIV );
                if( $decrypted == FALSE ) {
                    $logger->write( Logger::ERROR,  "nelze rozbalit (2) {$size}/{$blobLen}" );
                    $logger->write( Logger::INFO, '#' . ($row-3) . ': len=' . strlen($aesData). ' ' . bin2hex($aesData) );
                    //$logger->write( Logger::INFO, "sess key: {$sessionDevice->sessionKey}" );
                    //$logger->write( Logger::INFO, 'IV: ' . bin2hex($aesIV) );
                    throw new \Exception("Bad crypto block (2).");
                }
                if( $size>=512 ) {
                    $outData = $outData . $decrypted;
                    $size = $size - 512;
                } else {
                    $outData = $outData . substr( $decrypted, 0, $size );
                    $size = 0;
                }
                $row++;
            }

            // $logger->write( Logger::INFO, "[" . $outData ."]" );
            $rowId = $this->datasource->saveBlob( $sessionDevice, $blobTime, $blobDesc, $blobExtension, $blobLen, $remoteIp );

            $subpath = "{$sessionDevice->deviceId}/" . date( 'Y-m', $blobTime );
            $deviceDataDir = __DIR__ . "/../../data/" . $subpath;
            if( ! file_exists( $deviceDataDir )) {
                if( ! mkdir( $deviceDataDir, 0700, TRUE ) ) {
                    $logger->write( Logger::ERROR, "Chyba pri vytvareni adresare [{$deviceDataDir}]"); 
                    throw new \Exception("File problem 1.");
                }
            }
            $fileName = "/{$rowId}.{$blobExtension}";
            if( FALSE === file_put_contents( $deviceDataDir . $fileName, $outData) ) {
                $logger->write( Logger::ERROR, "Chyba pri zapisu souboru [{$deviceDataDir}{$fileName}]");    
                throw new \Exception("File problem 2.");
            }
            
            $rowId = $this->datasource->updateBlob( $rowId, $subpath.$fileName ); 

            $logger->write( Logger::INFO, "OK");

            $this->template->result = "OK";
            
        } catch (\App\Exceptions\NoSessionException $e) { 

            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();

        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb
        
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S400_BAD_REQUEST );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }


    private function getUpdateFilename( $deviceId, $updateId ) 
    {
        return __DIR__ . "/../../data/ota/{$deviceId}_{$updateId}.bin";   
    }

    public function actionUpdate( $id )
    {
        Debugger::enable( Debugger::PRODUCTION );
        $logger = new Logger( self::NAME );

        try {
            $httpRequest = $this->getHttpRequest();
            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("U;{$remoteIp}");            

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "update+ id:{$id}");

            // u.id as update_id, u.device_id, u.fileHash, s.id as session_id, s.hash, s.session_key
            $update = $this->datasource->getUpdateById( $id );
            $logger->setContext("U;{$remoteIp};D:{$update['device_id']}");

            // zkontrolovat security header x-ra-1
            $hdr = $httpRequest->getHeader('x-ra-1');
            if( !$hdr || strlen($hdr)==0 ) {
                $logger->write( Logger::ERROR, "Neni header x-ra-1"); 
                throw new \Exception("Auth err 1.");
            }
            $sessionIdRemote = $this->decryptDataBlock( $hdr, $update['session_key'], $logger );
            $sessionIdLocal = "{$update['session_id']}:{$update['hash']}";
            if( strcmp($sessionIdRemote, $sessionIdLocal)!=0 ) {
                $logger->write( Logger::ERROR, "Spatna session remote:{$sessionIdRemote} local:{$sessionIdLocal}"); 
                throw new \Exception("Auth err 2.");
            }

            $file = $this->getUpdateFilename( $update['device_id'], $update['update_id'] );
            if( !file_exists ( $file )) {
                $logger->write( Logger::ERROR, "soubor {$file} nenalezen");
                throw new \Exception("Soubor neexistuje");
            }
            $rsp = new FileResponse($file, 'update.bin', 'application/octet-stream', FALSE );

            // nastavit hlavicku s crc
            $hash = hex2bin( $update['fileHash'] );
            $encHash = $this->encryptDataBlock( $hash, $update['session_key'], $logger );
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setHeader('x-ra-1', $encHash );

            $logger->write( Logger::INFO, "OK");
            
            //TODO nastavit u update stazeno=aktualni cas
            $this->datasource->setUpdateTime( $id );

            $this->sendResponse($rsp);
            
        } catch( \Nette\Application\AbortException $e ) {
            throw $e;
        } catch (\App\Exceptions\NoSessionException $e) { 

            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S403_FORBIDDEN );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();

        } catch (\Exception $e) {
        
            //TODO: zapsat chybu do tabulky chyb
        
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S400_BAD_REQUEST );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }
}
