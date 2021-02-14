<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;

use \App\Exceptions\NoSessionException;
use SessionDevice;
use App\Services\Logger;

class MsgProcessor 
{
    use Nette\SmartObject;
    
	/** @var \App\Services\RaDataSource */
    public $datasource;
    
    public function __construct(\App\Services\RaDataSource $datasource)
    {
        $this->datasource = $datasource;
    }
    

    /*
    b0 - deviceClass
    b1 - valueType
    b2 b3 b4 msgRate
    b5 - deviceName len
    b6... - device name - NO \0 at end
    */
    /**
     * Zpracovani definice kanalu
     */
    public function processChannelDefinition( $sessionDevice, $msg, $remoteIp, $i, Logger $logger  )
    {
        $devClass = ord($msg[$i++]);
        $valueType = ord($msg[$i++]);
        $msgRate = (ord($msg[$i])<<16) | (ord($msg[$i+1])<<8) | ord($msg[$i+2]);
        $i += 3;
        $channel = ord($msg[$i++]);
        $nameLen = ord($msg[$i++]);
        $name = substr( $msg, $i, $nameLen );

        $factor = NULL;

        $c = strpos($name, "|" );
        if( $c===FALSE ) {
            // nedelame nic
        } else {
            $factor = substr( $name, $c+1 );
            $name = substr( $name, 0, $c );
        }

        $logger->write( Logger::INFO,  "ChDef ch:{$channel} class:{$devClass} valType:{$valueType} rate:{$msgRate} factor:{$factor} '{$name}'" );

        $this->datasource->processChannelDefinition( $sessionDevice, $channel, $devClass, $valueType, $msgRate, $name, $factor );
    }


    /**
     * Zpracovani jedne datove zpravy ze zarizeni
     */
    public function processData( $sessionDevice, $msg, $remoteIp, $i, $channel, $timeDiff, Logger $logger   )
    {
        $sensor = $this->datasource->getSensorByChannel( $sessionDevice->deviceId, $channel );
        if( $sensor==NULL ) {  
            throw new \Exception("Ch {$channel} not found for dev {$sessionDevice->deviceId}.");
        }

        $data = substr( $msg, $i );

        if( $sensor['device_class']!=3 ) {
            // senzor DEVCLASS_CONTINUOUS_MINMAXAVG a DEVCLASS_CONTINUOUS
            // s daty nic nedelame
            $value_out = filter_var ( $data , FILTER_VALIDATE_FLOAT );
            $logger->write( Logger::INFO,  "data: ch:{$channel} s:{$sensor['id']} '{$data}' C-> {$value_out} @ -{$timeDiff} s" );
            $dataSession = '';
            $impCount = 0;
        } else {
            // senzor DEVCLASS_IMPULSE_SUM
            // musime pocitat deltu v ramci aktualni session
            $fields = explode( ';', $data, 2 );
            if( count($fields)!=2 ) {
                throw new \Exception("Can't parse '{$data}' for dev {$sessionDevice->deviceId}.");
            }
            $impCount = intval( $fields[0] );
            $prevVal = 'X';
            if( 
                $sensor['data_session']!=NULL && strcmp($sensor['data_session'],$fields[1]) == 0 ) {
                // jde o data v ramci aktualni session; tedy merime rozdil od posledniho ziskaneho
                if( $sensor['imp_count'] > $impCount ) {
                    // nejake divne, ze by se nahodovu vygenerovalo stejne cislo session?
                    $value_out = $impCount;
                    $prevVal = "!{$sensor['imp_count']}!";
                } else {
                    $value_out = $impCount - $sensor['imp_count'];
                    $prevVal = $sensor['imp_count'];
                }
            } else {
                // nova session = zaciname od nuly
                $value_out = $impCount;
            }
            $dataSession = $fields[1];
            $logger->write( Logger::INFO,  "data: ch:{$channel} s:{$sensor['id']} '{$data}' I({$prevVal})-> {$value_out} @ -{$timeDiff} s" );
        }

        $sVal = $value_out;
        if( $sensor->preprocess_data==1 ) {
            // prepocitavat data!
            $value_out *= $sensor->preprocess_factor;
        }

        $this->datasource->saveData( $sessionDevice, $sensor, $timeDiff, $sVal, $remoteIp, $value_out, $impCount, $dataSession );
    }


    /**
     * Zpracuje jeden request; ten ale muze obsahovat vice zprav.
     */
    public function process( $sessionDevice, $msgTotal, $remoteIp, Logger $logger )
    {
        $logData = bin2hex($msgTotal );
        //D/ $logger->write( Logger::INFO, "msg {$logData}");

        // payload send timestamp
        $sendTime = (ord($msgTotal[0])<<16) | (ord($msgTotal[1])<<8) | ord($msgTotal[2]);
        $logger->write( Logger::DEBUG, "uptime:{$sendTime}" );
        $this->datasource->setUptime( $sessionDevice->deviceId, $sendTime );

        // telemetry payload header
        $j = 3;

        while( true ) {

            //---- iterace dalsi zpravy v datovem bloku
            $msgLen = @ord($msgTotal[$j]);
            //D/ $logger->write( Logger::INFO, "  pos={$j}, len={$msgLen}");
            if( $msgLen == 0 ) {
                break;
            }
            $msg = substr( $msgTotal, $j+1, $msgLen );
            $j += 1 + $msgLen;
            
            //---- zpracovani jedne zpravy
            $i = 0;
            $channel = ord($msg[$i++]);
            $msgTime = (ord($msg[$i])<<16) | (ord($msg[$i+1])<<8) | ord($msg[$i+2]);
            $i += 3;
    
            $timeDiff = $sendTime-$msgTime;
            //D/ $logger->write( Logger::INFO,  "msg ch:{$channel} time:-{$timeDiff}" );
    
            if( $channel==0 ) {
                //D $logger->write( Logger::INFO,  "channel definition" );
                $this->processChannelDefinition( $sessionDevice, $msg, $remoteIp, $i, $logger );
            } else {
                //D $logger->write( Logger::INFO,  "data" );
                $this->processData( $sessionDevice, $msg, $remoteIp, $i, $channel, $timeDiff, $logger );
            }
        }


    }
}



