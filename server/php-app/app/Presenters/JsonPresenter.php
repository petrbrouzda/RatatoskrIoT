<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;
use Nette\Utils\Json;

final class JsonPresenter extends BasePresenter
{
    use Nette\SmartObject;
    
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    
    public function __construct(\App\Services\InventoryDataSource $datasource )
    {
        $this->datasource = $datasource;
    }


    // http://lovecka.info/ra/json/data/aaabbb/2/
    public function renderData( $id, $token )
    {
        $device = $this->datasource->getDevice( $id );
        if( ! $device ) {
            $this->error('Zařízení nebylo nalezeno');
        }
        if( !$token || ($device['json_token'] !== $token) ) {
            $this->error('Token nesouhlasí.');
        }
        $sensors = $this->datasource->getDeviceSensors( $id );
        foreach( $sensors as $sensor ) {
            // $sensor['name'] = $device['name'] . ':' . $sensor['name'];
            $status = 'not_yet_connected';
            if( $sensor['last_data_time'] ) {
                $utime = (DateTime::from( $sensor['last_data_time'] ))->getTimestamp();
                if( time()-$utime > $sensor['msg_rate'] ) {
                    $status = 'too_old_msg';
                } else {
                    $status = 'OK';
                }
            }
            $sensor['status'] = $status;
        }
        $data = [
            'device_id' => $id ,
            'device_name' => $device['name'],
            'device_desc' => $device['desc'],
            'sensors' => $sensors,
        ];

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 sec'); 

        $this->sendJson($data);
    }

    // http://lovecka.info/ra/json/meteo/aaabbb/2/?temp=bd358d05&rain=rain
    public function renderMeteo( $id, $token, $temp, $rain )
    {
        $device = $this->datasource->getDevice( $id );
        if( ! $device ) {
            $this->error('Zařízení nebylo nalezeno');
        }
        if( !$token || ($device['json_token'] !== $token) ) {
            $this->error('Token nesouhlasí.');
        }
        $tempId = -1;
        $rainId = -1;
        $sensors = $this->datasource->getDeviceSensors( $id );
        foreach( $sensors as $sensor ) {
            if( $sensor['name'] === $temp ) {
                $tempId = $sensor['id'];
                $tempCurrent = $sensor['last_out_value'];
                $lastDataTime = $sensor['last_data_time'];
                $maxDataTime = $sensor['msg_rate'];
            }
            if( $sensor['name'] === $rain ) {
                $rainId = $sensor['id'];
            }
        }

        if( isset($lastDataTime) )  {
            if( (time() - ($lastDataTime->getTimestamp())) >  $maxDataTime ) {
                $dataValid = "N";
            } else {
                $dataValid = "Y";
            }
        } else {
            $dataValid = "N";
        }


        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 sec'); 


        if( $tempId==-1 || $rainId==-1) {
            $data = [
                'error' => 'Nenalezen senzor daneho jmena.'
            ];
            $this->sendJson($data);
            $this->terminate();
            return;
        }

        $date = (new DateTime())->modify('-7 day')->format('Y-m-d');
        $rainTyden = $this->datasource->meteoGetWeekData( $rainId, $date );
        $tempTyden = $this->datasource->meteoGetWeekData( $tempId, $date );
        $date = (new DateTime())->modify('-1 day')->format('Y-m-d');
        $rainVcera = $this->datasource->meteoGetDayData( $rainId, $date );
        $tempVcera = $this->datasource->meteoGetDayData( $tempId, $date );
        $date2 = (new DateTime())->format('Y-m-d');
        $rainNoc = $this->datasource->meteoGetNightData( $rainId, $date, $date2 );
        $tempNoc = $this->datasource->meteoGetNightData( $tempId, $date, $date2 );
        $rainDnes = $this->datasource->meteoGetDayData( $rainId, $date2 );
        $tempDnes = $this->datasource->meteoGetDayData( $tempId, $date2 );

        $tyden = [
            'rain' => $rainTyden['celkem'],
            'temp_min' => $tempTyden['minimum'],
            'temp_max' => $tempTyden['maximum'],
        ];
        $vcera = [
            'rain' => $rainVcera['celkem'],
            'temp_min' => $tempVcera['minimum'],
            'temp_max' => $tempVcera['maximum'],
        ];
        $noc = [
            'rain' => $rainNoc['celkem'],
            'temp_min' => $tempNoc['minimum'],
            'temp_max' => $tempNoc['maximum'],
        ];
        $dnes = [
            'rain' => $rainDnes['celkem'],
            'temp_min' => $tempDnes['minimum'],
            'temp_max' => $tempDnes['maximum'],
        ];
        $nyni = [
            'temp' => $tempCurrent,
            'valid' => $dataValid,
            'timestamp' => $lastDataTime
        ];
        $data = [
            'tyden' => $tyden ,
            'vcera' => $vcera,
            'noc' => $noc,
            'dnes' => $dnes,
            'nyni' => $nyni,
            'temp_now' => $tempCurrent,
        ];

        $this->sendJson($data);
    }

}