<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Nette\Utils\DateTime;

/**
 * Zpracovava parametry pro grafy - pro filtrovaci hlavicku
 */
class ChartParameters
{
    use Nette\SmartObject;

    public $dateTimeFrom;
    public $lenDays;
    public $altYear;
    public $altDateFrom;

    public $minYear;

    public function allowCompare( $allowCompare ) 
    {
        if( ! $allowCompare ) {
            $this->altYear = NULL;
        } else {
            $this->altDateFrom = $this->dateTimeFrom->modifyClone();
            $this->altDateFrom->setDate( intval($this->altYear), intval($this->altDateFrom->format('m')), intval($this->altDateFrom->format('d')) );
        }
    }

    /**
     * Maji se vykreslit srovnavaci serie z jineho roku?
     */
    public function altSeries()
    {
        return ($this->altYear != NULL) && (intval($this->altYear)>2000);
    }

    public function __construct(
        $dateFrom, $lenDays, $altYear, 
        $plus, $minus, 
        $altplus, $altminus ,
        $current, $currentweek, $currentmonth, $currentyear,
        $plusMon, $minusMon,
        $plusYear, $minusYear,
        $currentday,

        $minYear
    )
    {
        $this->lenDays = $lenDays;
        $this->altYear = $altYear;
        $this->minYear = $minYear;

        if( strlen($dateFrom)>0 ) {
            $this->dateTimeFrom = DateTime::from( $dateFrom );
        } else {
            $this->dateTimeFrom = (new DateTime( 'now' ))->modify( '-2 days' );
            $this->lenDays = 3;
        }

        if( strlen($plus)>0 ) {
            $this->dateTimeFrom->modify( '+' . intval($this->lenDays) . ' days' );
        }
        if( strlen($minus)>0 ) {
            $this->dateTimeFrom->modify( '-' . intval($this->lenDays) . ' days' );
        }
        if( strlen($plusMon)>0 ) {
            $this->dateTimeFrom->modify( '+1 month' );
        }
        if( strlen($minusMon)>0 ) {
            $this->dateTimeFrom->modify( '-1 month' );
        }
        if( strlen($plusYear)>0 ) {
            $this->dateTimeFrom->modify( '+1 year' );
        }
        if( strlen($minusYear)>0 ) {
            $this->dateTimeFrom->modify( '-1 year' );
        }

        // kontrola limitů

        if( $this->dateTimeFrom->getTimestamp() > time() ) {
            $this->dateTimeFrom=(new DateTime('now'))->modify("-1 day");
        }
        $minDate = DateTime::from( "{$minYear}-01-01" );
        if( $this->dateTimeFrom->getTimestamp() < $minDate->getTimestamp() ) {
            $this->dateTimeFrom = $minDate;
        }


        // srovnávací rok

        if( strlen($altminus)>0 ) {
            if( $altYear==NULL ) {
                $this->altYear = (new DateTime('now'))->modify("-1 year")->format("Y");
            } else {
                $this->altYear = intval($altYear) - 1;
                if( $this->altYear < $this->minYear ) {
                    $this->altYear = $this->minYear;
                }
            }
        }
        if( strlen($altplus)>0 ) {
            if( $this->altYear==NULL ) {
                $this->altYear = (new DateTime('now'))->modify("-1 year")->format("Y");
            } else {
                $this->altYear = intval($altYear) + 1;
                $max = intval( (new DateTime('now'))->format("Y") );
                if( $this->altYear > $max ) {
                    $this->altYear = $max;
                }
            }
        }


        // předvolby

        if( strlen($currentday)>0 ) {
            $this->lenDays = 1;
            $this->dateTimeFrom = new DateTime('now');
        }
        if( strlen($current)>0 ) {
            $this->lenDays = 3;
            $this->dateTimeFrom = (new DateTime('now'))->modify('-' . ($this->lenDays-1) . ' days');
        }
        if( strlen($currentweek)>0 ) {
            $this->lenDays = 8;
            $this->dateTimeFrom = (new DateTime('now'))->modify('-' . ($this->lenDays-1) . ' days');
        }
        if( strlen($currentmonth)>0 ) {
            $this->lenDays = 31;
            $this->dateTimeFrom = (new DateTime('now'))->modify('-' . ($this->lenDays-1) . ' days');
        }
        if( strlen($currentyear)>0 ) {
            $this->lenDays = 366;
            $this->dateTimeFrom = DateTime::from( 
                                        (new DateTime('now'))->format("Y") 
                                        . '-01-01' 
                                    );
        }
    }

    public function getAltYearsList()
    {
        $years = array();
        for( $i =  intval( (new DateTime('now'))->format("Y") ); $i >= $this->minYear; $i-- ) {
            $years[] = ''.$i;
        }
        return $years;
    }

}



