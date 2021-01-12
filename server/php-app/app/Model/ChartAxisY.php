<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Tracy\Debugger;

use \App\Model\SensorDataSeries;
use \App\Model\ChartSeries;

class ChartAxisY
{
    use Nette\SmartObject;

    /**
     * Minimalni hodnota
     */
    public $minVal;

    /**
     * Maximalni hodnota
     */
    public $maxVal;    

    /**
     * Rozmer Y, na ktery mapujeme
     */
    private $sizePx;

    /**
     * Offset pricitany k vracene hodnote, aby to vracelo primo pozici v canvasu
     */
    private $offsetPx;

    /**
     * Nasobici faktor
     */
    private $factor;


    private function computeFactor() {
        $valueDelta = $this->maxVal - $this->minVal;
        if( $valueDelta==0 ) {
            $valueDelta = 1;
        }
        $this->factor = $this->sizePx / $valueDelta;
    }

    /**
     * Zapocte minimum a maximum datove rady do limitu osy
     */
    public function processSeries( SensorDataSeries $series )
    {
        if( $series->size() == 0 ) {
            // pokud nema serie data, nemam co delat
            return;
        }
        
        if( $this->minVal===NULL || ($this->minVal > $series->minVal) ) {
            $this->minVal = $series->minVal;

            if( $this->minVal > 0 ) {
                $this->minVal *= 0.995;
            } else {
                $this->minVal *= 1.005;
            }
        }
        
        if( $this->maxVal===NULL || ($this->maxVal < $series->maxVal) ) {
            $this->maxVal = $series->maxVal;

            if( $this->maxVal > 0 ) {
                $this->maxVal *= 1.005;
            } else {
                $this->maxVal *= 0.995;
            }
        }

        if( ($this->maxVal - $this->minVal) < 1 ) {
            $this->maxVal += 1;
            $this->minVal -= 1;
        }

        $this->computeFactor();
    }

    /**
     * Pro kresleni grafu.
     * Prepocte hodnotu na pozici.
     * Pozor - pocita s 0 = horni okraj! 
     */
    public function getPosY( $val )
    {
        $relVal = $val - $this->minVal;
        return $this->sizePx - intval( $relVal * $this->factor ) + $this->offsetPx;
    }

    public function __construct( $sizePx, $offsetPx )
    {
        $this->sizePx = $sizePx;
        $this->offsetPx = $offsetPx;
        $this->minVal = NULL;
        $this->maxVal = NULL;
    }

    /**
     * series = pole objektu ChartSeries
     */
    public static function prepareAxisY( $series, $sizeY, $marginYT )
    {
        $axisY = new ChartAxisY( $sizeY, $marginYT );
        foreach( $series as $serie ) {
            $axisY->processSeries( $serie->data );
            // Debugger::log( $axisY->toString() );
        }
        return $axisY;
    }

    public function toString() : string
    {
        return "ChartAxisY [ {$this->sizePx}+{$this->offsetPx}px val={$this->minVal}..{$this->maxVal} f={$this->factor}]";
    }
}



