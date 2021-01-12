<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Nette\Utils\DateTime;

use \App\Model\ChartAxisY;

/**
 * Konfigurace a data grafu k vykresleni
 */
class Chart
{
    use Nette\SmartObject;

    /**
     * array of ChartSeries objects.
     * Objekty vkladat pres pushSeries.
     * Pro osu Y1
     */
    public $series1;

    /**
     * array of ChartSeries objects.
     * Objekty vkladat pres pushSeries.
     * Pro osu Y2
     */
    public $series2;

    /**
     * Vlozi jednu serii k vykresleni.
     * $axisY je cislo osy - 1 nebo 2
     */
    public function pushSeries( $axisY, ChartSeries $data )
    {
        if( $axisY==1 ) {
            $this->series1[] = $data;
        } else {
            $this->series2[] = $data;
        }
    }

    /**
     * Zacatek grafu
     */
    public $dateTimeFrom;

    // rozmery grafu

    public $sizeY = 500;
    public $marginYT = 35;
    public $marginYB = 100;
    public $sizeX = 1000;
    public $marginXL = 100;
    public $marginXR = 100;
    
    public function changeBorders( ChartAxisY $axisY1, ChartAxisY $axisY2 )
    {
        $maxLen = 1;
        $l = strlen( '' . intval( $axisY1->minVal ) );
        if( $l > $maxLen ) $maxLen = $l;
        $l = strlen( '' . intval( $axisY1->maxVal ) );
        if( $l > $maxLen ) $maxLen = $l;
        $l = strlen( '' . intval( $axisY2->minVal ) );
        if( $l > $maxLen ) $maxLen = $l;
        $l = strlen( '' . intval( $axisY2->maxVal ) );
        if( $l > $maxLen ) $maxLen = $l;

        if ( $maxLen <= 3 ) {
            $this->marginXL -= 40;
            $this->marginXR -= 40;
            $this->sizeX += 80;
        } else if ( $maxLen <= 5 ) {
            $this->marginXL -= 30;
            $this->marginXR -= 30;
            $this->sizeX += 60;
        } else if ( $maxLen <= 6 ) {
            // nic
        } else {
            $this->marginXL += 50;
            $this->marginXR += 50;
            $this->sizeX -= 100;
        }
    }

    public function width()
    {
       return $this->sizeX + $this->marginXL + $this->marginXR; 
    }

    public function height()
    {
       return $this->sizeY + $this->marginYT + $this->marginYB; 
    }

    public function __construct( $dateTimeFrom )
    {
        $this->series1 = array();
        $this->series2 = array();
        $this->dateTimeFrom = $dateTimeFrom;
    }
}



