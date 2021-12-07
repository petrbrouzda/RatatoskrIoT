<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;
use Tracy\Debugger;

use \App\Model\Device;
use \App\Model\Devices;
use \App\Model\View;
use \App\Model\ViewItem;

class InventoryDataSource 
{
    use Nette\SmartObject;
    
	private $database;
    
	public function __construct(
            Nette\Database\Context $database
            )
	{
		$this->database = $database;
	}

    public function getDevicesUser( $userId ) : Devices
    {
        $rc = new Devices();

        // nacteme zarizeni

        $result = $this->database->query(  '
            select * 
            from devices
            where user_id = ?
            order by name asc
        ', $userId );

        foreach ($result as $row) {
            $dev = new Device( $row );
            $dev->attrs['problem_mark'] = false;
            
            if( $dev->attrs['last_bad_login'] != NULL ) {
                if( $dev->attrs['last_login'] != NULL ) {
                    $lastLoginTs = (DateTime::from( $dev->attrs['last_login']))->getTimestamp();
                    $lastErrLoginTs = (DateTime::from(  $dev->attrs['last_bad_login']))->getTimestamp();
                    if( $lastErrLoginTs >  $lastLoginTs ) {
                        $dev->attrs['problem_mark'] = true;
                    }
                } else {
                    $dev->attrs['problem_mark'] = true;
                }
            }

            $rc->add( $dev );
        }

        // a k nim senzory

        $result = $this->database->query(  '
            select s.*, dc.desc as dc_desc, vt.unit
            from sensors s
            left outer join device_classes dc
            on s.device_class=dc.id
            left outer join value_types vt
            on s.value_type=vt.id
            left outer join devices d
            on s.device_id = d.id
            where d.user_id = ?
            order by s.name asc
        ', $userId );

        foreach ($result as $row) {
            $device = $rc->get( $row['device_id'] );
            $row['warningIcon'] = 0;
            if( $row['last_data_time']  && $row['msg_rate']!=0  ) {
                $utime = (DateTime::from( $row['last_data_time'] ))->getTimestamp();
                if( time()-$utime > $row['msg_rate'] ) {
                    if( $device->attrs['monitoring']==1 ) {
                        $row['warningIcon'] = 1;
                    } else {
                        $row['warningIcon'] = 2;
                    }
                } 
            }
            
            if( isset($device) ) {
                $device->addSensor( $row );
            }
        }

        return $rc;
    }
    

    public function createDevice( $values ) 
    {
        return $this->database->table('devices')->insert($values);
    }

    public function getDevice( $deviceId )
    { 
        return $this->database->table('devices')->get($deviceId);
    }

    public function getSensor( $sensorId )
    { 
        return $this->database->fetch(  '
            select 
                s.*, 
                d.name as dev_name, d.desc as dev_desc, d.user_id,  
                vt.unit
            from sensors s

            left outer join devices d
            on s.device_id = d.id

            left outer join value_types vt
            on s.value_type = vt.id
            
            where s.id = ?
        ', $sensorId );
    }

    public function updateSensor( $id, $values )
    {
        $outvalues = array();
        $outvalues['desc'] = $values['desc'];
        $outvalues['msg_rate'] = $values['msg_rate'];
        $outvalues['display_nodata_interval'] = $values['display_nodata_interval'];
        $outvalues['preprocess_data'] = $values['preprocess_data'];
        $outvalues['preprocess_factor'] =  ( $values['preprocess_data']=='1' ? $values['preprocess_factor'] : "1" );

        if( isset($values['warn_max']) ) {
            $outvalues['warn_max'] = $values['warn_max'];
            $outvalues['warn_max_val'] = ( $values['warn_max']=='1' ? $values['warn_max_val'] : 0 );
            $outvalues['warn_max_val_off'] = ( $values['warn_max']=='1' ? $values['warn_max_val_off'] : 0 );
            $outvalues['warn_max_after'] = ( $values['warn_max']=='1' ? $values['warn_max_after'] : 0 );
            $outvalues['warn_max_text'] = $values['warn_max_text'];
            $outvalues['warn_min'] = $values['warn_min'];
            $outvalues['warn_min_val'] = ( $values['warn_min']=='1' ? $values['warn_min_val'] : 0 ) ;
            $outvalues['warn_min_val_off'] = ( $values['warn_min']=='1' ? $values['warn_min_val_off'] : 0 ) ;
            $outvalues['warn_min_after'] = ( $values['warn_min']=='1' ? $values['warn_min_after'] : 0 ) ;
            $outvalues['warn_min_text'] = $values['warn_min_text'] ;
        }

        $this->database->query('UPDATE sensors SET ', $outvalues, ' WHERE id = ?', $id);
    }

    

    public $views;
    public $tokens;
    public $tokenView;

    public function getViews( $userId ) 
    {
        $this->views = array();
        $this->tokens = array();
        $this->tokenView = array();

        // nacteme pohledy

        $result = $this->database->query(  '
            select * from views
            where user_id = ?
            order by token asc, vorder desc
        ', $userId );

        foreach ($result as $viewMeta) {
            $view = new View( $viewMeta->name, $viewMeta->vdesc, $viewMeta->allow_compare, $viewMeta->app_name, $viewMeta->render );
            $view->token = $viewMeta->token;
            $view->vorder = $viewMeta->vorder;
            $view->render = $viewMeta->render;
            $view->id = $viewMeta->id;
            $this->views[ $view->id ] = $view;

            if( !isset( $this->tokens[ $view->token ] )) {
                $this->tokens[ $view->token ] = $view->token;
            }

            if( isset($this->tokenView[ $view->token ])) {
                $token = $this->tokenView[ $view->token ];
                $token[] = $view;
                $this->tokenView[ $view->token ] = $token;
            } else {
                $token = array();
                $token[] = $view;
                $this->tokenView[ $view->token ] = $token;
            }
        }

        // a k nim nacteme polozky

        $result = $this->database->query(  '
            select vd.* , v.user_id, vs.short_desc as view_source_desc
            from view_detail vd
            left outer join views v
            on vd.view_id = v.id
            left outer join view_source vs
            on vd.view_source_id = vs.id
            where v.user_id = ?
            order by view_id asc, vorder asc
        ', $userId );

        foreach ($result as $row) {
            $vi = new ViewItem();
            $vi->vorder = $row->vorder;
            $vi->axisY = $row->y_axis;
            $vi->source = $row->view_source_id;
            $vi->sourceDesc = $row->view_source_desc;
            $vi->setColor( 1, $row->color_1 );
            $vi->setColor( 2, $row->color_2 );
            $vi->id = $row->id;

            $sids = explode( ',' , $row->sensor_ids );
            $vi->sensorIds = $sids;
            
            $this->views[ $row->view_id ]->items[] = $vi;
        }
    }


    public function getSensors( $userId )
    { 
        $sensors = array();

        $result = $this->database->query(  '
            select 
                s.*, 
                d.name as dev_name, d.desc as dev_desc, d.user_id,
                vt.unit
            from sensors s
            left outer join devices d
            on s.device_id = d.id
            left outer join value_types vt
            on s.value_type = vt.id
            where d.user_id = ?
            order by vt.unit asc, d.name asc, s.name asc
        ', $userId );

        foreach ($result as $row) {
            $sensors[ $row->id ] = $row;
        }

        return $sensors;
    }


    public function updateView( $id, $values )
    {
        $this->database->query('UPDATE views SET ', [ 
            'vdesc' => $values['vdesc'],
            'name' => $values['name'],
            'app_name' => $values['app_name'],
            'token' => $values['token'],
            'render' => $values['render'],
            'vorder' => $values['vorder'],
            'allow_compare' => $values['allow_compare']
        ] , 'WHERE id = ?', $id);
    }


    public function createView( $values ) 
    {
        return $this->database->table('views')->insert($values);
    }

    public function createUser( $values ) 
    {
        return $this->database->table('rausers')->insert($values);
    }

    public function getViewSources()
    {
        return $this->database->table('view_source')->fetchAll();
    }


    public function createViewitem( $values ) 
    {
        return $this->database->table('view_detail')->insert($values);
    }


    public function getViewItem( $viId )
    { 
        return $this->database->fetch(  '
            select 
            vi.*,
            v.user_id
            from view_detail vi
            left outer join views v
            on vi.view_id = v.id
            where vi.id=?
        ', $viId );
    }

    public function updateViewItem( $id, $values )
    {
        $this->database->query('UPDATE view_detail SET ', [ 
            'vorder' => $values['vorder'],
            'sensor_ids' => $values['sensor_ids'],
            'y_axis' => $values['y_axis'],
            'view_source_id' => $values['view_source_id'],
            'color_1' => $values['color_1'],
            'color_2' => $values['color_2']
        ] , 'WHERE id = ?', $id);
    }

    public function deleteViewItem( $id )
    {
        $this->database->query('
            DELETE from view_detail  
            WHERE id = ?
        ', $id);
    }

    public function deleteView( $id )
    {
        $this->database->query('
            DELETE from view_detail  
            WHERE view_id = ?
        ', $id);

        $this->database->query('
            DELETE from views  
            WHERE id = ?
        ', $id);
    }
    
    public function deleteViewsForUser( $id )
    {
        $this->database->query('
            delete from view_detail 
            where view_id in ( select id from views where user_id = ? )
        ', $id);

        $this->database->query('
            delete from views where user_id = ? 
        ', $id);
    }

    public function deleteUser( $id )
    {
        $this->database->query('
            delete from rausers where id = ? 
        ', $id);
    }


    /**
     * sensor_id	pocet	name	desc
     */
    public function getDataStatsMeasures( $id )
    { 
        return $this->database->fetchAll(  '
            select 
            d.*, s.name, s.desc
            from 
            (
            select sensor_id, count(*) as pocet
            from measures
            where 
            sensor_id in (select id from sensors where device_id = ?)
            group by sensor_id
            ) d
            
            left outer join sensors s
            on d.sensor_id = s.id
            
            order by s.name
        ', $id );
    }

    /**
     * sensor_id	pocet	name	desc
     */
    public function getDataStatsSumdata( $id )
    { 
        return $this->database->fetchAll(  '
            select 
            d.*, s.name, s.desc
            from 
            (
            select sensor_id, count(*) as pocet
            from sumdata
            where 
            sensor_id in (select id from sensors where device_id = ?)
            group by sensor_id
            ) d
            
            left outer join sensors s
            on d.sensor_id = s.id
            
            order by s.name
        ', $id );
    }


    public function deleteSession( $id )
    {
        $this->database->query('
            DELETE from sessions
            WHERE device_id = ?
        ', $id);
    }


    public function deleteDevice( $id )
    {
        Logger::log( 'webapp', Logger::DEBUG ,  "Mazu session device {$id}" ); 

        // nejprve zmenit heslo a smazat session, aby se uz nemohlo prihlasit
        $this->database->query("
            update devices
            set passphrase = 'x' 
            WHERE id = ?
        ", $id);

        $this->database->query('
            DELETE from sessions
            WHERE device_id = ?
        ', $id);

        Logger::log( 'webapp', Logger::DEBUG ,  "Mazu measures device {$id}" ); 

        // smazat data
        $this->database->query('
            DELETE from measures  
            WHERE sensor_id in (select id from sensors where device_id = ?)
        ', $id);

        Logger::log( 'webapp', Logger::DEBUG ,  "Mazu sumdata device {$id}" ); 

        $this->database->query('
            DELETE from sumdata
            WHERE sensor_id in (select id from sensors where device_id = ?)
        ', $id);

        Logger::log( 'webapp', Logger::DEBUG ,  "Mazu device {$id}" ); 

        // smazat senzory a zarizeni
        $this->database->query('
            DELETE from sensors
            WHERE device_id = ?
        ', $id);

        $this->database->query('
            DELETE from devices
            WHERE id = ?
        ', $id);

        Logger::log( 'webapp', Logger::DEBUG ,  "Smazano." ); 
    }

    public function getBlobCount( $deviceId )
    { 
        $row = $this->database->fetch(  '
            select count(*) as ct
            from blobs
            where device_id = ?
            and status>0
        ', $deviceId );

        return $row->ct;
    }

    public function getBlobs( $deviceId )
    { 
        return $this->database->fetchAll(  '
            select *
            from  blobs
            where device_id = ?
                and status>0
            order by id desc
        ', $deviceId );
    }


    public function getBlob( $deviceId , $blobId )
    { 
        return $this->database->fetch(  '
            select *
            from  blobs
            where id = ? 
                and device_id = ?
                and status>0
        ', $blobId, $deviceId );
    }

    public function getDeviceSensors( $deviceId )
    { 
        return $this->database->fetchAll(  '
            select s.*,
            vt.unit
            from  sensors s
            left outer join value_types vt
            on s.value_type = vt.id
            where device_id = ?
            order by id asc
        ', $deviceId );
    }


    public function getUsers()
    { 
        return $this->database->fetchAll(  '
            select u.* , us.desc
            from rausers u
            left outer join rauser_state us
            on u.state_id = us.id
            order by username asc
        ' );
    }

    public function getUser( $id )
    {
        return $this->database->table('rausers')
                        ->where('id', $id)
                        ->fetch();
    }

    public function updateUser( $id, $values )
    {
        $this->database->query('UPDATE rausers SET ', [ 
            'email' => $values['email'],
            'monitoring_token' => $values['monitoring_token']
        ] , 'WHERE id = ? ', $id);
    }

    public function updateUserPassword( $id, $phash )
    {
        $this->database->query('UPDATE rausers SET ', [ 
            'phash' => $phash
        ] , 'WHERE id = ? ', $id);
    }


    public function getUnits()
    { 
        return $this->database->fetchAll(  '
            select *
            from value_types
            order by id asc
        ' );
    }


    /**
     * Vraci ID zaznamu NEBO -1, pokud pro dane zarizeni a verzi uz existuje
     */
    public function otaUpdateCreate( $id, $fromVersion, $fileHash )
    {
        $rs1 = $this->database->fetchAll(  '
            select *
            from  updates
            where device_id = ?
                and fromVersion = ?
        ', $id, $fromVersion );        
        if( count($rs1)!=0 ) {
            return -1;
        }

        $this->database->query('INSERT INTO updates ', [ 
            'device_id' => $id,
            'fromVersion' => $fromVersion,
            'fileHash' => $fileHash,
            'inserted' => new \DateTime(),
        ]);
        
        return $this->database->getInsertId(); 
    }


    public function getOtaUpdates( $id )
    {
        return $this->database->fetchAll(  '
            select *
            from updates
            where device_id = ?
            order by id asc
        ', $id );
    }

    public function otaDeleteUpdate( $deviceId, $updateId  )
    {
        $this->database->query('DELETE FROM updates 
            where id = ?
            and device_id= ?
        ', $updateId , $deviceId  );
    }

    
    public function meteoGetWeekData( $sensorId, $dateFrom )
    {
        return $this->database->fetch(  '
            select sum(sum_val) as celkem, max(max_val) as maximum, min(min_val) as minimum 
            from sumdata
            where sensor_id = ?
            and rec_date > ?
            and sum_type = 2
        ', $sensorId, $dateFrom  );
    }

    public function meteoGetDayData( $sensorId, $date )
    {
        return $this->database->fetch(  '
            select sum(sum_val) as celkem, max(max_val) as maximum, min(min_val) as minimum 
            from sumdata
            where sensor_id = ?
            and rec_date = ?
            and sum_type = 2
        ', $sensorId, $date  );
    }

    public function meteoGetNightData( $sensorId, $dateYesterday, $dateToday )
    {
        return $this->database->fetch(  '
            select sum(sum_val) as celkem, max(max_val) as maximum, min(min_val) as minimum from sumdata
            where sensor_id = ?
            and 
            ( ( rec_date = ? and rec_hour >=20 ) 
            or 
            (  rec_date = ? and rec_hour <=6  ) 
            )
            and sum_type = 1
        ', $sensorId, $dateYesterday, $dateToday  );
    }
}


