<?php

declare(strict_types=1);

namespace App\Model;

use Nette;

class ChartSeries
{
    use Nette\SmartObject;

    /**
     * SensorDataSeries
     */
    public $data;

    /**
     * objekt \App\Model\Color
     */
    public $color;

    /**
     * 1 = primarni serie, 2 = srovnavaci rok
     */
    public $nr;

    public function __construct( $data, $color, $nr )
    {
        $this->data = $data;
        $this->color = $color;
        $this->nr = $nr;
    }
}



