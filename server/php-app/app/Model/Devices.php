<?php

declare(strict_types=1);

namespace App\Model;

use Nette;

use \App\Model\Device;

class Devices
{
    use Nette\SmartObject;

    public $devices;
    
    public function __construct()
    {
        $this->devices = array();
    }

    public function add( Device $device )
    {
        $this->devices[ $device->attrs['id'] ] = $device;
    }

    public function get( $id ) : Device
    {
        return $this->devices[$id];
    }
}



