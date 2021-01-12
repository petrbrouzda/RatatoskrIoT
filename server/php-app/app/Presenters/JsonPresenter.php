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

}