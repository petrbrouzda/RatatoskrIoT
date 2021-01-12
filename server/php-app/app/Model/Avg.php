<?php

/**
 * Pocitadlo prumeru
 */

declare(strict_types=1);

namespace App\Model;

use Nette;

class Avg
{
    use Nette\SmartObject;

    /**
     *  Pole [12][31] obsahujici prumery pro jednotlive dny
     */
    public $avgs;

    /** [12][31] */
    private $sums;

    /** [12][31] */
    private $counts;

    public function addValue( $m, $d, $value ) {
        if( ! isset($this->sums[$m][$d]) ) {
            $this->sums[$m][$d] = $value;
            $this->avgs[$m][$d] = $value;
            $this->counts[$m][$d] = 1;
        } else {
            $this->sums[$m][$d] += $value;
            $this->counts[$m][$d] += 1;
            $this->avgs[$m][$d] = $this->sums[$m][$d] / $this->counts[$m][$d];
        }
    }

    public function getAvg( $m, $d )
    {
        if( isset($this->avgs[$m][$d]) ) {
            return $this->avgs[$m][$d];
        } else {
            return null;
        }
    }

    public function toString( $m, $d )
    {
        if( isset($this->avgs[$m][$d]) ) {
            return "{$this->avgs[$m][$d]} from {$this->counts[$m][$d]}";
        } else {
            return null;
        }
    }

    public function __construct()
    {
        $this->avgs = array();
        $this->sums = array();
        $this->counts = array();
    }

}



