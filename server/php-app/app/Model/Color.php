<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Nette\Utils\Image;

class Color
{
    use Nette\SmartObject;

    public $r;
    public $g;
    public $b;
    
    public function getColor()
    {
        return Image::rgb($this->r, $this->g, $this->b);
    }

    public function getHtmlColor()
    {
        return '#' . substr('00000' . dechex( $this->r<<16 | $this->g<<8 | $this->b ), -6 );
    }

    public function getTextColor()
    {
        return "{$this->r},{$this->g},{$this->b}";
    }

    public static function parseColor( $colorString ) : Color
    {
        $pars = explode( ',' , $colorString );
        if( sizeof($pars)!=3 ) {
            throw new \Exception( "Can't parse [{$colorString}] to color.");
        }
        $r = intval( $pars[0] );
        $g = intval( $pars[1] );
        $b = intval( $pars[2] );
        return new Color( $r, $g, $b );
    }

    public static function parseHexColor( $colorString ) : Color
    {
        $r = hexdec( substr($colorString,1,2) );
        $g = hexdec( substr($colorString,3,2) );
        $b = hexdec( substr($colorString,5,2) );
        return new Color( $r, $g, $b );
    }

    public function __construct( $r, $g, $b )
    {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
    }
}



