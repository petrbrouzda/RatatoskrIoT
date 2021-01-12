<?php

declare(strict_types=1);

namespace App\Model;

use Nette;

use \App\Model\Color;

/**
 * Položka pohledu
 */
class ViewItem
{
    use Nette\SmartObject;

    /**
     * Pole senzorů. Každý senzor má vlastnosti:
     * 
     * id	device_id	channel_id	name	device_class	value_type	msg_rate	desc	display_nodata_interval	
     * preprocess_data	preprocess_factor	
     * dev_name	dev_desc dev_id
     * unit
     */
    public $sensors;

    public function isKompozit()
    {
        return sizeof($this->sensors)>1;
    }

    public function getSensorName( $i )
    {
        return "{$this->sensors[0]->dev_name}:{$this->sensors[0]->name}";
    }

    public function getSensorsName()
    {
        $out = "";
        foreach( $this->sensors as $sensor ) {
            if( strlen($out)>0 ) {
                $out .= "+";
            }
            $out .= "{$sensor->dev_name}:{$sensor->name}";
        }
        return $out;
    }

    public function getSensorsDesc()
    {
        $out = "";
        foreach( $this->sensors as $sensor ) {
            if( strlen($out)>0 ) {
                $out .= " | ";
            }
            $out .= $sensor->desc;
        }
        if( $this->isKompozit() ) {
            $out = "Kompozit: " . $out;
        }
        return $out;
    }

    public function pushSensor( $sensor )
    {
        $this->sensors[] = $sensor;
    }
    
    public $axisY;
    public $source;
    public $sourceDesc;
    public $colors;

    // jen pro editaci
    public $id;
    public $vorder;

    public function toArray()
    {
        $vi = array();
        $vi["name"] = $this->getSensorsDesc();
        $vi["sensor_name"] = $this->getSensorsName();
        $vi["unit"] = $this->getUnit();
        $vi["source"] = $this->source;
        $vi["source_desc"] = $this->sourceDesc;
        $vi["axis"] = $this->axisY;
        return $vi;
    }

    public function getUnit() {
        return $this->sensors[0]->unit;
    }

    public function setColor( $nr, $colorText )
    {
        $this->colors[$nr] = Color::parseColor( $colorText );
    }

    public function getColor( $nr )
    {
        if( ! isset($this->colors[$nr]) ) {
            throw new \Exception( "Color #{$nr} has not been defined.");
        }
        return $this->colors[$nr];
    }

    public function __construct()
    {
        $this->colors=array();
        $this->sensors=array();
    }

    public function toString()
    {
        return "Y:{$this->axisY} src:{$this->source}";
    }

    /*
     * Jen pro inventory
     */
    public $sensorIds;
}



