<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;

use \App\Exceptions\NoSessionException;
use SessionDevice;


class RaDataSource 
{
    use Nette\SmartObject;
    
	/** @var Nette\Database\Context */
	private $database;
    
	public function __construct(
            Nette\Database\Context $database
            )
	{
		$this->database = $database;
	}


    /**
     * Load information about DEVICE
     */
    public function getDeviceInfoByLogin( $login )
    {
        return $this->database->fetch('SELECT * FROM devices WHERE name = ?', $login );
    }

    public function getDeviceInfoById( $id )
    {
        return $this->database->fetch('SELECT * FROM devices WHERE id = ?', $id );
    }


    public function createLoginaSession( $deviceId, $hash, $key, $remoteIp )
    {
        $this->database->query('INSERT INTO prelogin', [
        	'hash' => $hash,
        	'device_id' => $deviceId,
        	'started' => new \Nette\Utils\DateTime,
            'session_key' => $key,
            'remote_ip' => $remoteIp
        ]);    
        
        return $this->database->getInsertId();
    }
    
    //TODO: Bude smazano - je pro stary login
    /**
     * Delete older sessions for the same device
     * Create session
     * Set first_login and last_login properties of device
     * 
     * Returns session ID
     */ 
    public function createSession( $deviceId, $saveFirstLogin, $hash, $key, $remoteIp, $appName )
    {
        $this->database->query('DELETE FROM sessions WHERE device_id = ?', $deviceId );
        
        $now = new \Nette\Utils\DateTime;
        
        $this->database->query('INSERT INTO sessions', [
        	'hash' => $hash,
        	'device_id' => $deviceId,
        	'started' => $now,
            'session_key' => $key,
            'remote_ip' => $remoteIp
        ]);    
        
        $id = $this->database->getInsertId();

        $values = array();
        $values[ 'last_login' ] = $now;
        $values[ 'app_name' ] = $appName;
        if( $saveFirstLogin ) {
            $values[ 'first_login' ] = $now;
        }        
        $this->database->query('UPDATE devices SET', $values, 'WHERE id = ?', $deviceId);
        
        return $id;            
    }


    public function createSessionV2( $preloginSessionId,
                                    $deviceId, $saveFirstLogin, $hash, $key, $remoteIp, 
                                    $appName, $uptime, $rssi )
    {
        $this->database->query('DELETE FROM prelogin WHERE id = ?', $preloginSessionId );
        $this->database->query('DELETE FROM sessions WHERE device_id = ?', $deviceId );
        
        $now = new \Nette\Utils\DateTime;
        
        $this->database->query('INSERT INTO sessions', [
        	'hash' => $hash,
        	'device_id' => $deviceId,
        	'started' => $now,
            'session_key' => $key,
            'remote_ip' => $remoteIp
        ]);    
        
        $id = $this->database->getInsertId();

        $values = array();
        $values[ 'last_login' ] = $now;
        $values[ 'app_name' ] = $appName;
        $values[ 'rssi' ] = $rssi;
        $values[ 'uptime' ] = $uptime;
        if( $saveFirstLogin ) {
            $values[ 'first_login' ] = $now;
        }        
        $this->database->query('UPDATE devices SET', $values, 'WHERE id = ?', $deviceId);
        
        return $id;            
    }


    public function badLogin( $deviceId ) 
    {
        $this->database->query('UPDATE devices SET', [
            'last_bad_login' => new \Nette\Utils\DateTime
        ], 'WHERE id = ?', $deviceId);
    }

    public function setUptime( $deviceId, $uptime ) 
    {
        $this->database->query('UPDATE devices SET', [
            'uptime' => $uptime
        ], 'WHERE id = ?', $deviceId);
    }

    public function deleteConfigRequest( $deviceId ) 
    {
        $this->database->query('UPDATE devices SET config_data = NULL WHERE id = ?', $deviceId);
    }


    /**
     * Check login session validity. 
     * Throws NoSessionException when session is not valid.
     * Returns SessionDevice.
     */
    public function checkLoginSession( $sessionId, $sessionHash )
    {
        $session = $this->database->fetch('SELECT hash, device_id, started, session_key FROM prelogin WHERE id = ?', $sessionId );
        if( $session==NULL ) { 
            throw new \App\Exceptions\NoSessionException( "session {$sessionId} not found" ); 
        }
        
        if( strcmp( $session->hash, $sessionHash )!=0 ) {
            throw new \App\Exceptions\NoSessionException( "bad hash" );
        }
        
        $now = new DateTime;
        // zivotnost session 1 den
        if( $now->diff( $session->started )->i > 5 ) {
            throw new \App\Exceptions\NoSessionException( "session expired" );
        }
        
        $rc = new \App\Model\SessionDevice();
        $rc->sessionId = $sessionId;
        $rc->sessionKey = $session->session_key;
        $rc->deviceId = $session->device_id;
        
        return $rc;
    }


    
    /**
     * Check session validity. 
     * Throws NoSessionException when session is not valid.
     * Returns SessionDevice.
     */
    public function checkSession( $sessionId, $sessionHash )
    {
        $session = $this->database->fetch('SELECT hash, device_id, started, session_key FROM sessions WHERE id = ?', $sessionId );
        if( $session==NULL ) { 
            throw new \App\Exceptions\NoSessionException( "session {$sessionId} not found" ); 
        }
        
        if( strcmp( $session->hash, $sessionHash )!=0 ) {
            throw new \App\Exceptions\NoSessionException( "bad hash" );
        }
        
        $now = new DateTime;
        // zivotnost session 1 den
        if( $now->diff( $session->started )->days > 0 ) {
            throw new \App\Exceptions\NoSessionException( "session expired" );
        }
        
        $rc = new \App\Model\SessionDevice();
        $rc->sessionId = $sessionId;
        $rc->sessionKey = $session->session_key;
        $rc->deviceId = $session->device_id;
        
        //TODO: potrebujeme jmeno device? 
        // $device = $this->database->fetch('SELECT id, name FROM devices WHERE id = ?', $session->device_id );
        // $rc->deviceName = $device->name;
        
        return $rc;
    }



    /**
     * Zalozeni nebo aktualizace senzoru
     */ 
    public function processChannelDefinition( $sessionDevice, $channel, $devClass, $valueType, $msgRate, $name, $factor )
    {
        $sensor = $this->database->fetch('SELECT id, channel_id FROM sensors WHERE device_id = ? AND name = ?', 
                                                                        $sessionDevice->deviceId,
                                                                        $name  );   
                                                                          
        if( $sensor==NULL ) {
            // neexistuje, zalozit

            if( $factor===NULL ) {
                $process = 0;
            } else {
                $process = 1;
            }

            $this->database->query('INSERT INTO sensors', [
                'device_id' => $sessionDevice->deviceId,
                'channel_id' => $channel,
                'name' => $name,
                'device_class' => $devClass,
                'value_type' => $valueType,
                'msg_rate' => $msgRate,
                'preprocess_data' => $process,
                'preprocess_factor' => $factor
            ]);

        } else {
            // existuje
            if( $sensor->channel_id != $channel ) {
                // existuje, ale ma spatny channel_id -> nastavit
                $this->database->query('UPDATE sensors SET ', [ 
                    'channel_id' => $channel
                ] , 'WHERE id = ?', $sensor->id);
            }
        }

        // a nastavit NULL na channel_id na ostatnich zaznamech stejneho zarizeni se stejnym channel_id
        $this->database->query('UPDATE sensors SET ', [ 
            'channel_id' => null
        ] , ' WHERE device_id = ? AND channel_id = ? AND name <> ?', $sessionDevice->deviceId, $channel, $name );
    }


    /**
     * Vrati sensor pro dany kanal.
     * id, preprocess_data, preprocess_factor, device_class, data_session, imp_count
     */
    public function getSensorByChannel( $deviceId, $channel )
    {
        return $this->database->fetch('
            SELECT id, preprocess_data, preprocess_factor, device_class, imp_count, data_session
            FROM sensors 
            WHERE device_id = ? AND channel_id = ?
        ', $deviceId, $channel  ); 
    }



    


    /**
     * Vlozeni zaznamu ze senzoru do 'measures'
     */ 
    public function saveData( $sessionDevice, $sensor, $timeDiff, $numVal, $remoteIp, $value_out, $impCount, $dataSession )
    {
        $msgTime = new DateTime;
        $msgTime->setTimestamp( time() - $timeDiff );
        
        $this->database->query('INSERT INTO measures ', [
            'sensor_id' => $sensor->id,
            'data_time' => $msgTime,
            'server_time' => new DateTime,
            's_value' => $numVal,
            'session_id' => $sessionDevice->sessionId,
            'remote_ip' => $remoteIp,
            'out_value' => $value_out
        ]);

        $values = array();
        $values['last_data_time'] = $msgTime;
        if( $sensor['device_class'] != 3 ) {
            $values['last_out_value'] = $value_out;
        }
        if( $dataSession!='' ) {
            $values['imp_count'] = $impCount;
            $values['data_session'] = $dataSession;
        }
        $this->database->query('UPDATE sensors SET', $values, 'WHERE id = ? AND ((last_data_time IS NULL) OR (last_data_time < ?))', $sensor->id , $msgTime );
    }


    public function saveBlob( $sessionDevice, $time, $description, $extension, $filesize, $remoteIp )
    {
        $msgTime = new DateTime;
        $msgTime->setTimestamp( $time );
        
        $this->database->query('INSERT INTO blobs ', [
            'device_id' => $sessionDevice->deviceId,
            'data_time' => $msgTime,
            'server_time' => new DateTime,
            'description' => $description,
            'extension' => $extension,
            'session_id' => $sessionDevice->sessionId,
            'remote_ip' => $remoteIp,
            'filesize' => $filesize
        ]);  
        
        return $this->database->getInsertId();
    }

    public function updateBlob( $rowId, $fileName )
    {
        $values = array();
        $values['filename'] = $fileName;
        $values['status'] = 1;
        $this->database->query('UPDATE blobs SET', $values, 'WHERE id = ?', $rowId );
    }

    public function getUpdate( $deviceId, $appId )
    {
        return $this->database->fetch('
            SELECT *
            FROM updates 
            WHERE device_id = ? AND fromVersion = ?
        ', $deviceId, $appId  ); 
    }

    public function getUpdateById( $updateId )
    {
        return $this->database->fetch('
            select u.id as update_id, u.device_id, u.fileHash, s.id as session_id, s.hash, s.session_key
            from updates u
            left outer join sessions s
            on s.device_id = u.device_id
            where u.id=?
        ', $updateId  ); 
    }


    public function setUpdateTime( $updateId )
    {
        $this->database->query('UPDATE updates SET', [
            'downloaded' => new \DateTime(),
        ], 'WHERE id = ?', $updateId );
    }
}



