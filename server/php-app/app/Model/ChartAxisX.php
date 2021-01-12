<?php

declare(strict_types=1);

namespace App\Model;

use Nette;

class ChartAxisX
{
    use Nette\SmartObject;

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

    /**
     * Delka osy ve dnech
     */
    public $intervalLenDays;

    /**
     * Pro kresleni grafu.
     * Prepocte hodnotu na pozici.
     */
    public function getPosX( $relTime )
    {
        return intval( $relTime * $this->factor ) + $this->offsetPx + 1;
    }

    public function __construct( $sizePx, $offsetPx, $intervalLenDays )
    {
        $this->sizePx = $sizePx-2;
        $this->offsetPx = $offsetPx;
        $this->factor = ($sizePx-2)/$intervalLenDays/86400;
        $this->intervalLenDays = $intervalLenDays;
    }

    public function toString() : string
    {
        return "ChartAxisX [ {$this->sizePx}+{$this->offsetPx}px f={$this->factor} ]";
    }
}



