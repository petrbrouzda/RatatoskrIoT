<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;

class CrontaskDataSource 
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
     * Vraci:
     * sensor_id, data_time
     */
    public function getRecordsForProcessing( $batchSize )
    {
        return $this->database->fetchAll(  "
            select * 
            from
            (
            select 
            sensor_id, data_time 
            from measures
            where status=0
            order by data_time asc
            limit $batchSize  
            ) d

            order by sensor_id, data_time
            " );
    }


    public function getImagesForProcessing()
    {
        return $this->database->fetchAll(  "
        select * 
        from blobs
        where extension = 'jpg'
        and description = 'camera'
        and status = 1
        order by id asc
        limit 30
        " );
    }

    public function updateImageAll( $id, $desc )
    {
        $this->database->query('UPDATE blobs SET ', [ 
            'status' => 2,
            'description' => $desc
        ] , 'WHERE id = ?', $id);
    }

    public function updateImageStatus( $id )
    {
        $this->database->query('UPDATE blobs SET ', [ 
            'status' => 2
        ] , 'WHERE id = ?', $id);
    }


    /**
     * id	device_id	channel_id	name	device_class	value_type	msg_rate	desc	display_nodata_interval	preprocess_data	preprocess_factor
     */
    public function getSensor( $sensorId )
    {
        return $this->database->fetch('SELECT * FROM sensors WHERE id = ?', $sensorId );
    }

    public function getRecordsForSensorHour( $sensorId, $date, $hour )
    {
        $dateFrom = $date . " " . $hour . ":00:00";
        $dateTo = $date . " " . $hour . ":59:59";

        return $this->database->fetchAll(  '
            select * from measures
            where sensor_id = ?
            and data_time >= ? 
            and data_time <= ?
            ',
            $sensorId,
            $dateFrom,
            $dateTo
             );
    }
   
    public function updateMeasure( $id )
    {
        $this->database->query('UPDATE measures SET ', [ 
            'status' => 1
        ] , 'WHERE id = ?', $id);
    }

    public function createSummary( $sensorId, $date, $hour, 
                                            $min, $min_time,
                                            $max, $max_time,
                                            $avg, $sum, 
                                            $sum_type, $count
                                        )
    {
        $this->database->query('DELETE FROM sumdata WHERE sensor_id=? AND rec_date=? AND sum_type=? AND rec_hour=?', $sensorId, $date, $sum_type, $hour );
        
        $this->database->query('INSERT INTO sumdata ', [
            'sensor_id' => $sensorId,
            'sum_type' => $sum_type, 
            'rec_date' => $date,
            'rec_hour' => $hour,
            'min_val' => $min,
            'min_time' => $min_time,
            'max_val' => $max,
            'max_time' => $max_time,
            'avg_val' => $avg,
            'sum_val' => $sum,
            'status' => 0,
            'ct_val' => $count 
        ]);
    }
    

    // pro impulzni senzory da celkovou denni sumu do sensor['last_out_value']
    public function updateSensorValue( $sensorId, $sum )
    {
        $this->database->query('UPDATE sensors SET ', [ 
            'last_out_value' => $sum
        ] , 'WHERE id = ?', $sensorId);
    }

    public function updateSumdata( $id )
    {
        $this->database->query('UPDATE sumdata SET ', [ 
            'status' => 1
        ] , 'WHERE id = ?', $id);
    }


    public function deleteNotifications()
    {
        $dt = new DateTime();
        $dt->modify('-14 day');
        
        $this->database->query('
            select n.* 
            from notifications n
            where status<>0
            and event_ts < ?     
        ', $dt );

    }

    public function getNotifications()
    {
        return $this->database->fetchAll(  "
            select  
            n.*, 
            d.user_id, d.last_login, d.name as dev_name, d.desc as dev_desc, 
            s.name as s_name, s.desc as s_desc, 
            u1.username as u1_name, u1.email as u1_email,
            vt.unit
            from notifications n
            
            left outer join devices d 
            on n.device_id = d.id
            
            left outer join sensors s
            on n.sensor_id = s.id
            
            left outer join rausers u1
            on d.user_id = u1.id

            left outer join value_types vt
            on s.value_type = vt.id
            
            where status=0
            order by id asc
            " );
    }

    /**
     * Ukonceni notifikace
     */
    public function closeNotification( $id )
    {
        $this->database->query('UPDATE notifications SET ', [ 
            'status' => 1
        ] , 'WHERE id = ?', $id);
    }

    /**
     * Zalozeni notifikace
     */
    public function insertNotification( $deviceId, $sensorId, $notificationType, $customText, $value, $eventTime )
    {
        $this->database->query('INSERT INTO notifications ', [
            'device_id' =>  $deviceId,
            'sensor_id' => $sensorId,
            'event_type' => $notificationType,
            'event_ts' => $eventTime,
            'custom_text' => $customText,
            'out_value' => $value ,
        ]);
    }


    /**
     *  id	hash	device_id	started	remote_ip	session_key
     */
    public function getOldPrelogins( $limit )
    {
        return $this->database->fetchAll(  
            'select * from prelogin
             where started < ?',
            $limit );
    }

    public function markDeviceLoginProblem( $deviceId, $ltime )
    {
        $this->database->query('UPDATE devices SET ', [ 
            'last_bad_login' => $ltime
        ] , 'WHERE id = ? AND ( (last_login is NULL) OR (last_login < ?) ) ', $deviceId , $ltime );
    }

    public function deletePrelogin( $id )
    {
        $this->database->query('DELETE from prelogin WHERE id = ? ', $id  );
    }

    public function getSensors()
    {
        return $this->database->fetchAll(  "
            select s.* , d.monitoring, d.name as dev_name
            from sensors s 
            left outer join devices d
            on s.device_id = d.id
            where last_out_value is not null
            " );
    }


    public function updateSensorsWarnings( $sensor )
    {
        $this->database->query('UPDATE sensors SET ', [ 
            'warn_max_fired' => $sensor['warn_max_fired'],
            'warn_min_fired' => $sensor['warn_min_fired'],
            'warn_max_sent' => $sensor['warn_max_sent'],
            'warn_min_sent' => $sensor['warn_min_sent'],
            'warn_noaction_fired' => $sensor['warn_noaction_fired'],
        ] , 'WHERE id = ?', $sensor['id'] );
    }


    /**
     * Vraci: sensor_id, rec_date 
     */
    public function getSumsForProcessing( $batchSize )
    {
        return $this->database->fetchAll(  "
            select * 
            from
            (
            select sensor_id, rec_date from sumdata
            where sum_type=1
            and status=0
            order by rec_date asc
            limit $batchSize
            ) d
            order by sensor_id, rec_date
            " );
    }

    /**
     * vraci: id	sensor_id	sum_type	rec_date	rec_hour	min_val	min_time	max_val	max_time	avg_val	sum_val	status
     */
    public function getSumsForSensorDay( $sensorId, $date )
    {
        return $this->database->fetchAll(  '
            select * from sumdata
            where sensor_id = ?
            and rec_date = ? 
            and sum_type = 1
            ',
            $sensorId,
            $date
             );
    }


    


    /**
     *  id, username, measures_retention, sumdata_retention, blob_retention
     */
    public function getAllUserSettings()
    {
        return $this->database->fetchAll(  "
            select id, username, measures_retention, sumdata_retention, blob_retention 
            from rausers
            " );
    }

    public function getSensorIdsForUser( $userId )
    {
        $result = $this->database->query(  "
            select s.id
            from sensors s
            
            left outer join devices d
            on s.device_id = d.id
            
            where d.user_id = ?
        ", $userId );

        $ids = array();
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        return $ids;
    }

    /**
     * id, data_time, filename
     */
    public function getBlobsForUser( $userId, $purgeDate ) 
    {
        return $this->database->fetchAll(  "
            select b.id, b.data_time, b.filename
            from blobs b

            left outer join devices d
            on b.device_id = d.id
            
            where user_id = ?
            and data_time < ?
        ", $userId, $purgeDate );
    }

    public function deleteMeasures( $sensorIds, $purgeDate )
    {
        /*
        $row = $this->database->fetch( "
            select count(*) as ct from measures
            where data_time < ? 
            and sensor_id in ( ? )
        ", $purgeDate, $sensorIds );
        */

        $result = $this->database->query( "
            delete from measures
            where data_time < ? 
            and sensor_id in ( ? )
            and status >= 1
        ", $purgeDate, $sensorIds );

        return $result->getRowCount();
    }

    public function deleteSumdata( $sensorIds, $purgeDate )
    {
        $result = $this->database->query( "
            delete from sumdata
            where rec_date < ? 
            and sensor_id in ( ? )
            and status >= 1
        ", $purgeDate, $sensorIds );

        return $result->getRowCount();
    }

    public function deleteBlob( $id )
    {
        $result = $this->database->query( "
            delete from blobs
            where id = ?
        ", $id );

        return $result->getRowCount();
    }


    public function getExportData()
    {
        return $this->database->fetchAll(  '
            select m.id, m.sensor_id, m.data_time, m.server_time, m.out_value as value, 
            s.device_id as device_id, s.name as sensor_name,
            d.name as device_name, d.user_id
            
            from measures m
            
            left outer join sensors s 
            on m.sensor_id = s.id
            
            left outer join devices d
            on s.device_id = d.id
            
            where m.status=1
            order by m.id asc
            limit 200
        ' );
    }

    public function rowExported( $id )
    {
        $this->database->query('UPDATE measures SET status = 2
            WHERE id = ?', $id);
    }

}



