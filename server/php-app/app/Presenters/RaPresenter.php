<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\Strings;
use Nette\Utils\Random;
use App\Exceptions\NoSessionException;
use App\Services\Logger;

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

    public function __construct(\App\Services\RaDataSource $datasource, \App\Services\MsgProcessor $msgProcessor, \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->msgProcessor = $msgProcessor;
        $this->config = $config;
    }

    public function actionTime()
    {
        Debugger::enable( Debugger::PRODUCTION );

        $httpRequest = $this->getHttpRequest();
        Logger::log( self::NAME, Logger::DEBUG, "[{$httpRequest->getRemoteAddress()}] time" );
        $this->template->time = time();
    }


    /**
     * Format login zpravy:
     *      <device_name>\n
     *      <login_payload_block>\n
     *      
     * Format login_payload_block:
     *      <AES IV>:<AES_encrypted_data>
     * oboji zapsane jako retezec hexitu
     * 
     * Obsah AES_encrypted_data:
     *      <AES session key 32 byte><CRC32 z prvnich 16 byte klice><CRC32 z druhych 16 byte klice><real time 4 byte><random data 4 byte>
     *      
        Output:
            SESSION_ID
    */
    public function actionLogin( $v )
    {
        try {
            Debugger::enable( Debugger::PRODUCTION );
            $logger = new Logger( self::NAME );

            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("{$remoteIp}");

            $postSize = strlen( $httpRequest->getRawBody() );
            //D $logger->write( Logger::INFO, "RA:login {$postSize} B {$httpRequest->getMethod()} IP:{$remoteIp}" );
            $radky = explode ( "\n" , $httpRequest->getRawBody(), 10 );
            if( count($radky)<2 ) {
                throw new \Exception("Bad request (1).");                
            }
            $login = Strings::trim($radky[0]);
            $logger->setContext("{$remoteIp};{$login}");

            $inToken = Strings::trim($radky[1]); 

            if( $v==2 ) {
                $logger->write( Logger::INFO , "RA:loginV2+" ); 
            } else {
                $logger->write( Logger::INFO , "RA:login+" ); 
            }
            
            if( Strings::length( $login ) == 0  ) {
                throw new \Exception("Empty login.");
            } 
        
            $device = $this->datasource->getDeviceInfoByLogin( $login );
            //D $logger->write( Logger::INFO,  $device );
            if( $device == NULL ) {
                throw new \Exception("Login '{$login}' not found.");
            }
            
            $passphrase = $this->config->decrypt( $device->passphrase, $login );
            
            // z hesla udelat hash
            $aesKey = hash("sha256", $passphrase, true);
            $aesKeyHex = bin2hex($aesKey); 
            // $logger->write( Logger::INFO, "pass hash: {$aesKeyHex}");
            
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
            
            $decrypted = openssl_decrypt($aesData, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $aesIV );
            if( $decrypted == FALSE ) {
                $this->datasource->badLogin( $device->id );
                $logger->write( Logger::ERROR,  "nelze rozbalit" );
                throw new \Exception("Bad crypto block (1).");
            }
              
            //$dechex = bin2hex($decrypted);
            //$logger->write( Logger::INFO,  "AES out: {$dechex}" );
            
            $session_key = substr($decrypted,0,32);
            // toto neni (jen) debug, hodnota se dal pouziva!
            $session_key_hex = bin2hex($session_key);
            // $logger->write( Logger::INFO,  "session key: {$session_key_hex}" );
            
            $crcReceived = bin2hex( substr( $decrypted, 32, 8 ));  

            $key_part_1 = substr( $session_key, 0, 16 ); 
            $key_hash_1 = hash( "crc32b", $key_part_1, FALSE );
            $key_part_2 = substr( $session_key, 16, 16 ); 
            $key_hash_2 = hash( "crc32b", $key_part_2, FALSE );
            
            if( strcmp( $key_hash_1 . $key_hash_2, $crcReceived ) != 0 ) {
                $this->datasource->badLogin( $device->id );
                $logger->write( Logger::ERROR,  "Nesouhlasi CRC. Prijato: {$crcReceived} Spocteno: {$key_hash_1}{$key_hash_2}" );
                throw new \Exception("Bad CRC.");
            } 

            $i = 32+8;
            $msgTime = (ord($decrypted[$i])<<24) | (ord($decrypted[$i+1])<<16) | (ord($decrypted[$i+2])<<8) | ord($decrypted[$i+3]);
            $timeDiff = time() - $msgTime;
            if( $timeDiff<0 ) {
                $timeDiff = -$timeDiff;
            }

            $maxTimeDiff = 120;

            if( $timeDiff>$maxTimeDiff ) {
                $localTime = time();
                $logger->write( Logger::ERROR,  "Too old login msg: msg:{$msgTime} local:{$localTime} diff:{$timeDiff}" );
                $this->datasource->badLogin( $device->id );
                throw new \Exception("Too old.");
            }
            
            // zalozit session
            $hash = Random::generate(8, '0-9A-Za-z');

            // zpracovat appInfo !
            $appName = "";
            $configVer = 0;
            $this->template->config = "";
            if( $v==2 ) {
                $appInfo = Strings::trim($radky[2]); 
                $appInfoDecoded = $this->decryptDataBlock( $appInfo, $session_key_hex, $logger );
                $fields = explode ( ";" , $appInfoDecoded, 2 );
                if( count($fields)<2 ) {
                    throw new \Exception("Bad request (1).");                
                }
                $configVer = $fields[0];
                $appName = $fields[1];

                if( $device['config_ver']!="" && $device['config_data']!="" ) {
                    if( $device['config_ver'] != $configVer ) {
                        // poslat do zarizeni zmenu konfigurace
                        $logger->write( Logger::INFO, "cfg ver db:{$device['config_ver']}, dev:{$configVer}" );
                        if( isset($device['config_data']) && strlen($device['config_data'])>0 ) {
                            $config = "{$device['config_ver']}\n{$device['config_data']}";
                            $this->template->config = $this->encryptDataBlock( $config, $session_key_hex, $logger );
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
            }
            
            
            $sessionCode = $this->datasource->createSession( $device->id, 
                                                            $device->first_login==NULL, 
                                                            $hash, 
                                                            $session_key_hex,
                                                            $remoteIp,
                                                            $appName );

            $sessionId = "{$sessionCode}:{$hash}";

            if( $v==2 ) {
                // login info je sifrovane
                $this->template->sessionId = $this->encryptDataBlock( $sessionId, $session_key_hex, $logger );
            } else {
                $this->template->sessionId = $sessionId;
            }

            $logger->write( Logger::INFO, "RA:login-OK D:{$device->id} S:{$sessionId} cfg:{$configVer}" );

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
     * Format data zpravy:
     *      <session_id>\n
     *      <data_payload_block>\n
     *      
     * Format data_paylod_block:
     *      <AES IV>:<AES_encrypted_data>
     * oboji zapsane jako retezec hexitu
     * 
     * Obsah AES_encrypted_data:
     *      <CRC32 z desifrovaneho payloady><delka dat 1 byte><data><random padding>
     *      
     * Result:
     *      200 - OK
     *      403 - re-login, session invalid
     *      400 - other error
    */
    public function actionData()
    {
        try {
            Debugger::enable( Debugger::PRODUCTION );
            $logger = new Logger( self::NAME );
            
            
            $httpRequest = $this->getHttpRequest();

            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("{$remoteIp}");

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "RA:data+ post {$postSize} B");
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
            $sessionDevice = $this->datasource->checkSession( $sessionData[0], $sessionData[1] );
            $logger->setContext("{$remoteIp};D:{$sessionDevice->deviceId}");

            //D $logger->write( Logger::INFO,  $sessionDevice );
            $logger->write( Logger::INFO, "S:{$sessionData[0]}"); 
            
            $msgTotal = $this->decryptDataBlock( $data, $sessionDevice->sessionKey, $logger );

            //D/ $logger->write( Logger::INFO,  '  celek: ' . bin2hex($msgTotal) );
            $this->msgProcessor->process( $sessionDevice, $msgTotal, $remoteIp, $logger );  

            $logger->write( Logger::INFO, "RA:data-OK");

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
        try {
            Debugger::enable( Debugger::PRODUCTION );
            $logger = new Logger( self::NAME );
            
            $httpRequest = $this->getHttpRequest();
            $remoteIp = $httpRequest->getRemoteAddress(); 
            $logger->setContext("{$remoteIp}");            

            $postSize = strlen( $httpRequest->getRawBody() );
            $logger->write( Logger::INFO, "RA:blob+ post {$postSize} B");
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
            $sessionDevice = $this->datasource->checkSession( $sessionData[0], $sessionData[1] );
            $logger->setContext("{$remoteIp};D:{$sessionDevice->deviceId}");

            //D $logger->write( Logger::INFO,  $sessionDevice );
            $logger->write( Logger::INFO, "RA:blob S:{$sessionData[0]}"); 
            
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
                $logger->write( Logger::INFO,  "RA:blob nekompletni data!" );
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

            $logger->write( Logger::INFO, "RA:blob-OK");

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
}
