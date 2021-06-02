<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;
use Tracy\Debugger;

use \App\Model\SensorDataSeries;
use \App\Model\ChartPoint;
use \App\Model\View;
use \App\Model\ViewItem;
use \App\Services\Logger;

class ChartDataSource 
{
    use Nette\SmartObject;
    
	private $database;
    
	public function __construct(
            Nette\Database\Context $database
            )
	{
		$this->database = $database;
	}

    private function computeOffset( $date, $time, $startTs ) : int
    {
        $rc = $date->getTimestamp();
        $rc += $time->h * 3600 + $time->i * 60 + $time->s;
        $rc -= $startTs;
        return $rc;
    }




    /**
     * Data pro graf coverage
     */
    public function getSensorCoverageData( $sensor, $year ) 
    {
        $startTs = "{$year}-01-01";
        $endTs = ($year+1) . '-01-01';

        $result = $this->database->query('
            select rec_date	, avg_val , ct_val	
            from sumdata
            where
            sensor_id = ?
            and sum_type = 2
            and rec_date >= ?
            and rec_date < ?
            order by rec_date asc
        ', $sensor->id, $startTs, $endTs  );

        return $result;
    }


    /**
     * Data pro avg - z denni sumarizace
     */
    public function getAvgData( $sensors, $year , $years ) 
    {
        $startTs = "{$year}-01-01";
        $endTs = ($year+$years) . '-01-01';  // 

        $sensorList = "";
        foreach( $sensors as $sensor ) {
            if( strlen($sensorList)>0 ) {
                $sensorList .= ",";
            }
            $sensorList .= intval($sensor->id);
        }

        return $this->database->query( "
            SELECT sensor_id, rec_date, rec_hour, min_val, max_val, avg_val, ct_val
            from sumdata
            where rec_date >= ?
            and rec_date < ?
            and sensor_id in ( $sensorList )
            and sum_type = 2
            order by rec_date asc, sensor_id asc
        ", $startTs , $endTs );
    }


    //TODO: datasource pro denni sumarizace, ktere jeste nemame spoctene


    /**
     * Vraci data pro graf z detailnich dat
     * Hodi se pro graf teploty
     * 
     * Vraci objekt SensorDataSeries
     */
    public function getSensorData_temperature_detail( $sensor, $dateTimeFrom, $intervalLenDays ) : SensorDataSeries
    {
        $startTs = $dateTimeFrom->getTimestamp();
        $dateTimeTo = $dateTimeFrom->modifyClone('+' . $intervalLenDays . ' day');   

        $rc = new SensorDataSeries( $sensor );

        $result = $this->database->query('
            select data_time, out_value
            from measures
            where 
            sensor_id = ?
            and data_time > ?
            and data_time <= ?
            order by data_time asc
        ', $sensor->id, $dateTimeFrom , $dateTimeTo  );

        foreach ($result as $row) {
            // Debugger::log( $row );
            $relTime = $row->data_time->getTimestamp() - $startTs ;
            $rc->pushPoint( new ChartPoint( $relTime, floatval($row->out_value )) );
        }

        // Debugger::log( $rc->toString( TRUE ) );
        return $rc;
    }

    
    /**
     * Vraci data pro graf z min/max hodnot hodinovych sumarizaci.
     * Hodi se tedy pro graf teploty, kde na kazdy den zustane 2x24 = 48 px (na sirku 1500 bodu = 31 dni)
     * 
     * Vraci objekt SensorDataSeries
     */
    public function getSensorData_temperature_summary( $sensors, $dateTimeFrom, $intervalLenDays ) : SensorDataSeries
    {
        $startTs = $dateTimeFrom->getTimestamp();
        $dateTimeTo = $dateTimeFrom->modifyClone('+' . $intervalLenDays . ' day');   

        $rc = new SensorDataSeries( $sensors[0] );

        $sensorList = "";
        foreach( $sensors as $sensor ) {
            if( strlen($sensorList)>0 ) {
                $sensorList .= ",";
            }
            $sensorList .= intval($sensor->id);
        }

        $result = $this->database->query( "
            SELECT sensor_id, rec_date, rec_hour, min_val, min_time, max_val, max_time, avg_val
            from sumdata
            where rec_date >= ?
            and rec_date < ?
            and sensor_id in ( $sensorList )
            and sum_type = 1
            order by rec_date asc, rec_hour asc, sensor_id asc
        ", $dateTimeFrom , $dateTimeTo );

        // poznamka - casy TIME se vraceji jako PHP DateInterval

        $prevDate = NULL;
        $prevHour = NULL;

        foreach ($result as $row) {
            // Debugger::log( $row );

            if( $prevDate === NULL ) {
                $prevDate = $row->rec_date;
                $prevHour = $row->rec_hour;
            } else if( $prevDate==$row->rec_date && $prevHour == $row->rec_hour ) {
                // data z dalsiho senzoru pro stejnou hodinu ignorujeme
                continue;
            }
            $prevDate = $row->rec_date;
            $prevHour = $row->rec_hour;

            $minRelTime = $this->computeOffset( $row->rec_date, $row->min_time, $startTs );
            $maxRelTime = $this->computeOffset( $row->rec_date, $row->max_time, $startTs );

            if( $minRelTime < $maxRelTime ) {
                $rc->pushPoint( new ChartPoint( $minRelTime, floatval($row->min_val) ) );
                $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val) ) );
            } else if( $minRelTime > $maxRelTime ) { 
                $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val) ) );
                $rc->pushPoint( new ChartPoint( $minRelTime, floatval($row->min_val) ) );
            } else {
                // mame jen jeden bod
                $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val) ) );
            }
        }

        // Debugger::log( $rc->toString( TRUE ) );

        return $rc;
    }


    private function computeOffset1200( $date, $startTs ) : int
    {
        $rc = $date->getTimestamp();
        $rc += 12 * 3600;
        $rc -= $startTs;
        return $rc;
    }

    private function computeOffsetWeeksum( $date, $startTs ) : int
    {
        $rc = $date->getTimestamp();
        $rc += 1*86400 + 12*3600;
        $rc -= $startTs;
        return $rc;
    }

    /**
     * Vraci data pro graf z min/max/avg hodnot dennich/hodinovych sumarizaci.
     * 
     * mode: 
     * - 1=denni min
     * - 2=denni max
     * - 3=denni avg
     * - 4=denni sum
     * - 5 = hodinovy sum
     * - 6 = vrati denni minimum A maximum
     * - 7 = hodinove maximum
     * 
     * 
     * Vraci objekt SensorDataSeries
     */
    public function getSensorData_minmaxavg_daysummary( $sensors, $dateTimeFrom, $intervalLenDays, $mode ) : SensorDataSeries
    {
        $startTs = $dateTimeFrom->getTimestamp();
        $dateTimeTo = $dateTimeFrom->modifyClone('+' . $intervalLenDays . ' day');   

        $rc = new SensorDataSeries( $sensors[0] );

        $sensorList = "";
        foreach( $sensors as $sensor ) {
            if( strlen($sensorList)>0 ) {
                $sensorList .= ",";
            }
            $sensorList .= intval($sensor->id);
        }

        $sum_type = 2;
        if( $mode==5 || $mode==7 ) {
            // pouze pro 5 a 7 jsou hodinove sumarizace
            $sum_type = 1;
        }

        $result = $this->database->query("
            SELECT sensor_id, rec_date, rec_hour, min_val, min_time, max_val, max_time, avg_val, sum_val
            from sumdata
            where rec_date >= ?
            and rec_date < ?
            and sensor_id in ( $sensorList )
            and sum_type = ?
            order by rec_date asc, rec_hour asc
        ", $dateTimeFrom , $dateTimeTo , $sum_type );

        // Debugger::log( "loading  $sensorId, $dateTimeFrom, $intervalLenDays, $mode " );

        $prevDate = NULL;
        $prevHour = NULL;

        // poznamka - casy TIME se vraceji jako PHP DateInterval
        foreach ($result as $row) {
            // Debugger::log( $row );

            if( $prevDate === NULL ) {
                $prevDate = $row->rec_date;
                $prevHour = $row->rec_hour;
            } else if( $prevDate==$row->rec_date && $prevHour == $row->rec_hour ) {
                // data z dalsiho senzoru pro stejnou hodinu ignorujeme
                continue;
            }
            $prevDate = $row->rec_date;
            $prevHour = $row->rec_hour;

            $relTime = $this->computeOffset1200( $row->rec_date, $startTs );

            if( $mode == 1 )
            {
                // denni min

                /* Nyni se nastavuje relativni cas 12:00.
                 * Kdyby se povolila nasledujici radka, vykreslovalo by se to ve skutecnem case minima:
                 *  $relTime = $this->computeOffset( $row->rec_date, $row->min_time, $startTs );
                 * jenze se ukazuje, ze v tom pripad se napr. cary maxima a minima prekryvaji.
                 * Aby to fungovalo, je treba v SensorDataSeries->pushPoint nastavit misto 90000 hodnotu 2*86400
                */ 
                $relTime = $this->computeOffset( $row->rec_date, $row->min_time, $startTs );
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->min_val) ), TRUE );
            } else if( $mode == 2 ) {
                // denni max
                $relTime = $this->computeOffset( $row->rec_date, $row->max_time, $startTs );
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->max_val) ), TRUE );
            } else if( $mode == 3 && ($row->avg_val!=NULL) ) {
                // denni avg
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->avg_val) ), TRUE );
            } else if( $mode == 4 ) {
                // denni suma
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->sum_val) ), TRUE );
            } else if( $mode == 5 ) {
                // hodinova suma
                // odecitame 12, protoze vyse se pocita offset pro 12:00
                $relTime += ($row->rec_hour-12)*3600 + 1800;
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->sum_val) ) );

            } else if( $mode == 7 ) {

                // hodinove maximum
                $relTime = $this->computeOffset( $row->rec_date, $row->max_time, $startTs );
                $rc->pushPoint( new ChartPoint( $relTime, floatval($row->max_val) ) );

            } else if( $mode == 6 ) {
                // denni minimum A maximum

                $minRelTime = $this->computeOffset( $row->rec_date, $row->min_time, $startTs );
                $maxRelTime = $this->computeOffset( $row->rec_date, $row->max_time, $startTs );
    
                if( $minRelTime < $maxRelTime ) {
                    $rc->pushPoint( new ChartPoint( $minRelTime, floatval($row->min_val)  ), TRUE );
                    $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val)  ), TRUE );
                } else if( $minRelTime > $maxRelTime ) { 
                    $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val)  ) , TRUE);
                    $rc->pushPoint( new ChartPoint( $minRelTime, floatval($row->min_val)  ) , TRUE);
                } else {
                    // mame jen jeden bod
                    $rc->pushPoint( new ChartPoint( $maxRelTime, floatval($row->max_val)  ) , TRUE);
                } 
            }
        }

        // Debugger::log( $rc->toString( TRUE ) );

        return $rc;
    }



    public function getSensorData_weeksummary( $sensors, $dateTimeFrom, $intervalLenDays ) : SensorDataSeries
    {
        Logger::log( 'webapp', Logger::DEBUG ,  "weeksumary < $dateTimeFrom, $intervalLenDays" ); 

        // pokud startTs neni pondeli, vzit nejblizsi predesle pondeli
        $denVTydnu = intval($dateTimeFrom->format('N'));
        if( $denVTydnu!=1 ) {
            $offset = $denVTydnu-1;
            $dateTimeFrom->modify( "-$offset day");
        }
        $zbytek = $intervalLenDays % 7;
        if( $zbytek!=0 ) {
            $intervalLenDays = 7 * (intval($intervalLenDays / 7)+1);
        }
        Logger::log( 'webapp', Logger::DEBUG ,  "weeksumary > $dateTimeFrom, $intervalLenDays" ); 

        $startTs = $dateTimeFrom->getTimestamp();
        $dateTimeTo = $dateTimeFrom->modifyClone('+' . $intervalLenDays . ' day');   

        $rc = new SensorDataSeries( $sensors[0] );

        $sensorList = "";
        foreach( $sensors as $sensor ) {
            if( strlen($sensorList)>0 ) {
                $sensorList .= ",";
            }
            $sensorList .= intval($sensor->id);
        }

        $result = $this->database->query("
            SELECT sensor_id, rec_date, WEEK(rec_date,3) as week, rec_hour, min_val, min_time, max_val, max_time, avg_val, sum_val
            from sumdata
            where rec_date >= ?
            and rec_date < ?
            and sensor_id in ( $sensorList )
            and sum_type = 2
            order by rec_date asc, rec_hour asc
        ", $dateTimeFrom , $dateTimeTo  );

        // Debugger::log( "loading  $sensorId, $dateTimeFrom, $intervalLenDays, $mode " );

        $prevWeek = NULL;
        $curRelTime = NULL;
        $curSum = 0;

        // poznamka - casy TIME se vraceji jako PHP DateInterval
        foreach ($result as $row) {
            // Debugger::log( $row );

            $relTime = $this->computeOffsetWeeksum( $row->rec_date, $startTs );

            if( $prevWeek===NULL ) {
                $prevWeek = $row->week;
                $curRelTime = $relTime;
                $curSum = floatval($row->sum_val);
            }

            if( $prevWeek != $row->week ) {
                $rc->pushPoint( new ChartPoint( $curRelTime, $curSum, TRUE ) );

                $prevWeek = $row->week;
                $curRelTime = $relTime;
                $curSum = floatval($row->sum_val);
            } else {
                $curSum += floatval($row->sum_val);
            }
        }

        if( $prevWeek!==NULL ) {
            $rc->pushPoint( new ChartPoint( $curRelTime, $curSum, TRUE ) );
        }

        // Debugger::log( $rc->toString( TRUE ) );

        return $rc;
    }


    /**
     * id	desc	short_desc
     */
    public function getViewSource( $id )
    {
        return $this->database->fetch('

            SELECT * 
            from view_source
            WHERE id = ?

        ', $id );
    }


    /**
     * id	device_id	channel_id	name	device_class	value_type	msg_rate	desc	display_nodata_interval	
     * preprocess_data	preprocess_factor	
     * dev_name	dev_desc
     * unit
     */
    public function getSensor( $sensorId )
    {
        return $this->database->fetch('

            SELECT s.*, 
            d.name as dev_name, d.desc as dev_desc, d.user_id , d.first_login, 
            vt.unit
            
            FROM sensors s 

            left outer join devices d
            on d.id = s.device_id

            left outer join value_types vt
            on s.value_type = vt.id

            WHERE s.id = ?

        ', $sensorId );
    }


    public function getView( $id, $token ) : View
    {
        $viewMeta = $this->database->fetch('
            select vdesc, name, render, allow_compare, app_name from views
            where id = ?
            and token = ?
        ', $id, $token );

        if( $viewMeta == NULL ) {
            throw new \Exception( "View {$id} not found or invalid token {$token}.");
        }

        $view = new View( $viewMeta->name, $viewMeta->vdesc, $viewMeta->allow_compare, $viewMeta->app_name, $viewMeta->render );

        /* id	view_id	vorder	sensor_ids	y_axis	view_source_id	color_1	color_2	view_source_desc */
        $result = $this->database->query('
            select vd.*, 
            vs.short_desc as view_source_desc
            
            from view_detail vd
            
            left outer join view_source vs
            on vd.view_source_id = vs.id
            
            where vd.view_id = ?
            order by vorder asc   
        ', $id );

        // poznamka - casy TIME se vraceji jako PHP DateInterval

        foreach ($result as $row) {
            $vi = new ViewItem();

            $sids = explode( ',' , $row->sensor_ids );
            foreach( $sids as $sid ) {
                $vi->pushSensor( $this->getSensor($sid) );
            }

            $vi->axisY = $row->y_axis;
            $vi->source = $row->view_source_id;
            $vi->sourceDesc = $row->view_source_desc;
            $vi->setColor( 1, $row->color_1 );
            $vi->setColor( 2, $row->color_2 );
            // Debugger::log( $vi->toString() );
            
            $view->items[] = $vi;
        }

        return $view;
    }

    public function readViews( $token )
    {
        return $this->database->fetchAll(  '
            select id, name from views
            where token=?
            order by vorder desc
            ', $token  );
    }

    public function getMeasuresStats( $sensorId ) 
    {
        return $this->database->fetch(  '
            select 
            min(data_time) as min_time,
            max(data_time) as max_time,
            count(*) as count
            from measures
            where sensor_id = ?
            ', $sensorId
        );
    }

    public function getSumdataStats( $sensorId ) 
    {
        return $this->database->fetch(  '
            select 
            min(rec_date) as min_time,
            max(rec_date) as max_time
            from sumdata
            where sensor_id = ?
            ', $sensorId
        );
    }

    public function getSumdataCount( $sensorId ) 
    {
        $out = array();

        $rs = $this->database->fetchAll(  '
            select sum_type, count(*) as count
            from sumdata
            where sensor_id = ?
            group by sum_type
            order by sum_type
            ', $sensorId
        );

        foreach( $rs as $row ) {
            if( $row->sum_type == 1 ) {
                $out['hour'] = $row->count;
            } else if( $row->sum_type == 2 ) {
                $out['day'] = $row->count;
            }
        }

        return $out;
    }
}



