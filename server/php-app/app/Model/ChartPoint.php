<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use \Nette\Utils\DateTime;


/**
 * Device session
 */
class ChartPoint
{
    use Nette\SmartObject;
    
    /**
     * Time from start of interval, sec
     */
    public $relativeTime;

    /**
     * Value at this time
     */
    public $value;

    /**
     * true = ma byt spojeno s predeslym;
     * false = nema
     */
    public $connectedFromPrevious;

    public function __construct( int $relativeTime, float $value )
    {
        $this->relativeTime = $relativeTime;
        $this->value = $value;
    }

    public function toString() : string
    {
        return "[t=+{$this->relativeTime} v={$this->value} c=" . ( $this->connectedFromPrevious ? 'Y' : 'N' ) . ']';
    }
}



