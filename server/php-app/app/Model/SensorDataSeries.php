<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Tracy\Debugger;

/**
 * Device session
 */
class SensorDataSeries
{
    use Nette\SmartObject;
    
    /**
     * Data sensoru
     * 
     * Properties: id	device_id	channel_id	name	device_class	value_type	msg_rate	desc	display_nodata_interval	 preprocess_data	preprocess_factor dev_name	dev_desc
     */
    public $firstSensor;

    /**
     * Pole objektu ChartPoint.
     * Objekty vkladat pres pushPoint() !
     */
    public $points;


    /**
     * Max hodnota pro skalovani grafu
     */
    public $maxVal;

    /**
     * Min hodnota pro skalovani grafu
     */
    public $minVal;


    /**
     * Relativni cas predesleho bodu. Pro pushPoint.
     */
    private $prevPointTime;

    /**
     * Vlozi bod do pole. 
     * Vyplni, zda je propojen s predeslym nebo neni.
     * Nastavuje max/min hodnoty v serii.
     */
    public function pushPoint( ChartPoint $point, $dailySum=FALSE )
    {
        if( is_null($point->value) ) {
            return;
        }

        $point->connectedFromPrevious = FALSE;

        $maxDiff = $dailySum ? 180000 : $this->firstSensor->display_nodata_interval;

        if( $this->prevPointTime != NULL ) {
            // pokud existuje predesly bod a je casove bliz nez zobrazovaci limit, oznacime si, ze je propojen
            if( ($point->relativeTime - $this->prevPointTime ) < $maxDiff ) {
                $point->connectedFromPrevious = TRUE;    
            }
        } 

        if( $this->minVal===NULL || $point->value < $this->minVal ) {
            $this->minVal = $point->value;
        } 

        if( $this->maxVal===NULL || $point->value > $this->maxVal ) {
            $this->maxVal = $point->value;
        } 

        $this->prevPointTime = $point->relativeTime;
        $this->points[] = $point;

        // Debugger::log( ' + ' . $point->toString() );
    }

    

    public function __construct( $sensor )
    {
        $this->firstSensor = $sensor;
        $this->points = array();
        $this->maxVal = NULL;
        $this->minVal = NULL;
        $this->prevPointTime = NULL;
    }

    /**
     * Pocet zaznamu v poli
     */
    public function size() : int
    {
        return sizeof($this->points);
    }

    public function toString( $verbose = FALSE ) : string
    {
        $rc = "SensorDataSeries [sensor {$this->firstSensor->id} '{$this->firstSensor->dev_name}:{$this->firstSensor->name}'; ct={$this->size()}" ;
        $rc .= " min={$this->minVal} max={$this->maxVal}";
        if( $verbose ) {
            $rc .= "; data:";
            foreach( $this->points as $point ) {
                $rc .= ' ' . $point->toString();
            }
        }
        $rc .= ' ]';

        return $rc;
    }

}



