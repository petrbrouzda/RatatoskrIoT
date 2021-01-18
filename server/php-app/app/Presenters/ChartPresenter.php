<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;
use Nette\Utils\Image;

use \App\Model\ChartAxisX;
use \App\Model\ChartAxisY;
use \App\Model\ChartSeries;
use \App\Model\SensorDataSeries;
use \App\Model\Chart;
use \App\Model\View;
use \App\Model\ViewItem;
use \App\Model\ChartParameters;
use \App\Model\Color;
use \App\Model\Avg;
use \App\Services\Logger;

final class ChartPresenter extends BasePresenter
{
    use Nette\SmartObject;
    
    /** @var \App\Services\ChartDataSource */
    private $datasource;

    // hodnoty z konfigurace
    private $fontName;
    private $fontNameBold;
    private $dataRetentionDays;
    private $appName;
    private $minYear;
    private $links;

    private $dbRows;
    
    public function __construct(\App\Services\ChartDataSource $datasource, 
                                $fontRegular, $fontBold, $dataRetentionDays, $appName, $minYear, $links )
    {
        $this->datasource = $datasource;
        $this->fontName  = __DIR__ . "/../../www/font/" . $fontRegular;
        $this->fontNameBold = __DIR__ . "/../../www/font/" . $fontBold;
        $this->dataRetentionDays = $dataRetentionDays;
        $this->appName = $appName;
        $this->minYear = $minYear;
        $this->links = $links;
    }

    // duplicita s BaseAdminPresenter->populateTemplate !
    private function populateChartMenu( $sensorId, $sensorName, $activeItem, $devId, $devName )
    {
        $submenu = array();
        $submenu[] =   ['id' => '103', 'link' => "device/show/{$devId}", 'name' => "· Zařízení {$devName}" ];
        $submenu[] =   ['id' => '102', 'link' => "sensor/show/{$sensorId}", 'name' => "· · Senzor {$sensorName}" ];
        $submenu[] =   ['id' => '100', 'link' => "chart/sensorstat/show/{$sensorId}", 'name' => "· · · Statistika" ];
        $submenu[] =   ['id' => '101', 'link' => "chart/sensor/show/{$sensorId}", 'name' => "· · · Graf" ];
        $this->populateMenu( $activeItem, 1, $submenu );
    }


    private $image;
    private $axisX;
    private $axisY1;
    private $axisY2;
    private $chart;

    private function drawSeries( ChartAxisY $axisY, \App\Model\ChartSeries $chartSerie, $poradi )
    {
        /** \App\Model\SensorDataSeries */
        $serie = $chartSerie->data;
        // Debugger::log( $serie->toString( TRUE ) );

        $color = $chartSerie->color->getColor();
        $nr = $chartSerie->nr;

        $prevPointX = NULL;
        $prevPointY = NULL;

        foreach( $serie->points as $point ) {

            $x = $this->axisX->getPosX( $point->relativeTime );
            $y = $axisY->getPosY( $point->value );

            if( $prevPointX!=NULL && $point->connectedFromPrevious ) {
                // spojime carou s predeslym bodem
                if( $nr==1 ) {
                    $this->image->line(
                        $prevPointX, $prevPointY, 
                        $x, $y, 
                        $color );
                } else {
                    $c = $this->image->colorAllocate( $chartSerie->color->r, $chartSerie->color->g, $chartSerie->color->b );
                    $this->image->setStyle( 
                        Array(
                            $c, 
                            $c, 
                            $c, 
                            IMG_COLOR_TRANSPARENT, 
                            IMG_COLOR_TRANSPARENT, 
                            IMG_COLOR_TRANSPARENT )
                    );
                    $this->image->line(
                        $prevPointX, $prevPointY, 
                        $x, $y, 
                        IMG_COLOR_STYLED );
                }
            } else {
                // jen bod by byl neviditelny
                $this->image->line(
                    $x-1, $y,
                    $x+1, $y, 
                    $color );
                $this->image->line(
                    $x, $y-1,
                    $x, $y+1, 
                    $color );
            }

            // udelame NAD grafem oznaceni, kde jsou data
            $prevPointX = $x;
            $prevPointY = $y;

            $y2 = $this->chart->marginYT - 5 - 4*$poradi;
            $this->image->line(
                $x-1, $y2,
                $x+1, $y2, 
                $color );
                
        }
    }



    private function createChart()
    {
        // [0,0] je vlevo _nahore_

        // cela plocha obrazku
        $this->image = Image::fromBlank(
            $this->chart->width(), 
            $this->chart->height(), 
            Image::rgb(242, 242, 242) );

        // plocha pro vykresleni obsahu
        $this->image->filledRectangle( 
            $this->chart->marginXL, $this->chart->marginYT, 
            $this->chart->sizeX + $this->chart->marginXL, $this->chart->sizeY + $this->chart->marginYT, 
            Image::rgb(255, 255, 255) );
    }

    private function createDecoration()
    {
        // zvyrazneni leve osy
        $this->image->line(
            $this->chart->marginXL, $this->chart->marginYT,
            $this->chart->marginXL, $this->chart->marginYT + $this->chart->sizeY,
            Image::rgb(0,0,0) 
        );

        // zvyrazneni prave osy
        $this->image->line(
            $this->chart->marginXL + $this->chart->sizeX , $this->chart->marginYT,
            $this->chart->marginXL + $this->chart->sizeX , $this->chart->marginYT + $this->chart->sizeY,
            Image::rgb(0,0,0) 
        );

        // spodni osa
        $this->image->line(
            $this->chart->marginXL,                        $this->chart->marginYT + $this->chart->sizeY ,
            $this->chart->marginXL + $this->chart->sizeX , $this->chart->marginYT + $this->chart->sizeY ,
            Image::rgb(0,0,0) 
        );
    }

    private function popiskaX( $x,  $text )
    {
        $yPosOffset = 10;
        $xPosOffset = -4;
        $fontSize = 10.0;

        $text = '' . $text;

        $y = $this->chart->marginYT + $this->chart->sizeY + $yPosOffset;
        $sirkaZnaku = $fontSize*3/4;
        $delkaTextu = intval(strlen($text) * $sirkaZnaku);
        $y = $y + $delkaTextu;

        $x = $x + intval($fontSize/2);
        $x = $x - intval($delkaTextu/2) + $xPosOffset;

        $this->image->ttfText( 
                            $fontSize, 
                            60, 
                            $x, $y, 
                            Image::rgb(0, 0, 0 ), 
                            $this->fontName,  
                            $text );
        
    }


    private function decorateAxisXdays( $tickerSizeSec, $timeSkip )
    {
        $carkaPresah = 3;

        $limitPos = $this->chart->dateTimeFrom->modifyClone( '+' . $this->axisX->intervalLenDays . ' day' );

        $lastTicker = -1000000;
        $curPos = $this->chart->dateTimeFrom->modifyClone();
        $startPosTs = $curPos->getTimestamp();

        while( $curPos < $limitPos ) {
            // Debugger::log( $curPos->format('d.m.Y h:i:s') );

            $curTs = $curPos->getTimestamp() - $startPosTs;
            if( ($curTs-$lastTicker) > $tickerSizeSec ) {
                $lastTicker = $curTs;

                // Debugger::log( 'kreslim' );

                // vykreslit caru a popisku
                $x = $this->axisX->getPosX( $curTs );
                $this->image->line(
                    $x, $this->chart->marginYT + $this->chart->sizeY - $carkaPresah , 
                    $x, $this->chart->marginYT + $this->chart->sizeY + $carkaPresah , 
                    Image::rgb(150,150,150)
                );  
                
                $this->image->dashedLine(
                    $x, $this->chart->marginYT +1 ,
                    $x, $this->chart->marginYT + $this->chart->sizeY -1 ,
                    Image::rgb(200,200,200)
                );

                $this->popiskaX( $x, $curPos->format('d.m.y H') );
            }

            $curPos->modify( $timeSkip );
        }
        
    }


    private function decorateAxisXhours( $tickerSizeSec, $timeSkip )
    {
        $carkaPresah = 3;

        $limitPos = $this->chart->dateTimeFrom->modifyClone( '+' . $this->axisX->intervalLenDays . ' day' );

        $lastTicker = -1000000;
        $curPos = $this->chart->dateTimeFrom->modifyClone();
        $startPosTs = $curPos->getTimestamp();

        while( $curPos < $limitPos ) {
            // Debugger::log( $curPos->format('d.m.Y h:i:s') );

            $curTs = $curPos->getTimestamp() - $startPosTs;
            if( ($curTs-$lastTicker) > $tickerSizeSec ) {
                $lastTicker = $curTs;

                // Debugger::log( 'kreslim' );

                // vykreslit caru a popisku
                $x = $this->axisX->getPosX( $curTs );
                $this->image->line(
                    $x, $this->chart->marginYT + $this->chart->sizeY - $carkaPresah , 
                    $x, $this->chart->marginYT + $this->chart->sizeY + $carkaPresah , 
                    Image::rgb(150,150,150)
                );  
                
                if( $curPos->format('H') === '00' ) {
                    // kulaty den = cela cara
                    $this->image->line(
                        $x, $this->chart->marginYT +1 ,
                        $x, $this->chart->marginYT + $this->chart->sizeY -1 ,
                        Image::rgb(200,200,200)
                    );
                } else {
                    // hodiny
                    $this->image->dashedLine(
                        $x, $this->chart->marginYT +1 ,
                        $x, $this->chart->marginYT + $this->chart->sizeY -1 ,
                        Image::rgb(200,200,200)
                    );
                }

                $this->popiskaX( $x, $curPos->format('d.m.y H') );
            }

            $curPos->modify( $timeSkip );
        }
        
    }


    private function decorateAxisX() 
    {
        // dodelat tickery na levou osu
        // kolik tickeru se nam tam asi vleze
        $minTickerSize = 25;
        $numTickers = intval( $this->chart->sizeX / $minTickerSize );
        // velikost tickeru 
        $tickerSizeSec = intval( ($this->axisX->intervalLenDays * 86400) / $numTickers );

        // pro male grafy vykreslujeme po hodinach
        if( $this->axisX->intervalLenDays <= 2 ) {
            // Debugger::log( '1 hours' );
            $modeHours = TRUE;
            if( $tickerSizeSec < 3600 ) $tickerSizeSec = 3000;
            $this->decorateAxisXhours( $tickerSizeSec, '+1 hour' );
        } else if( $this->axisX->intervalLenDays <= 7 ) {
            // Debugger::log( 'x/3 hours' );
            $modeHours = TRUE;
            if( $tickerSizeSec < 3600 ) $tickerSizeSec = 3000;
            $this->decorateAxisXhours( $tickerSizeSec, '+3 hour' );
        } else if( $this->axisX->intervalLenDays <= 14 ) {
            // Debugger::log( 'x/6 hours' );
            $modeHours = TRUE;
            if( $tickerSizeSec < 3600 ) $tickerSizeSec = 3000;
            $this->decorateAxisXhours( $tickerSizeSec, '+6 hour' );
        } else {
            // Debugger::log( 'x days' );
            $modeHours = FALSE;
            if( $tickerSizeSec < 80000 ) $tickerSizeSec = 80000;
            $this->decorateAxisXdays( $tickerSizeSec, '+1 day' );
        }
    }

    private function popiskaY( $y, $levaOsa, $text, $bold = FALSE )
    {
        $xposOffset = 8;
        $fontSize = $bold ? 14.0 : 10.0;

        $text = '' . $text;

        if( $levaOsa ) {
            $x = $this->chart->marginXL - $xposOffset;
            $sirkaZnaku = $fontSize*3/4;
            $x = $x - intval(strlen($text) * $sirkaZnaku);
        } else {
            $x = $this->chart->marginXL + $this->chart->sizeX + $xposOffset;
            if( $bold ) $x += $xposOffset;
        }

        $y = $y + intval($fontSize/2);

        $this->image->ttfText( 
                            $fontSize, 
                            0, 
                            $x, $y, 
                            Image::rgb(0, 0, 0 ), 
                            $bold ? $this->fontNameBold : $this->fontName,  
                            $text );
        
    }

    private function decorateAxisY1( ) 
    {
        $carkaPresah = 3;

        // pokud je graf pres nulu, vytiskneme pro nulu vodorovnou caru
        // vlevo presahuje, ale vpravo o kousek se nedotyka
        if( $this->axisY1->minVal <= 0 && $this->axisY1->maxVal >= 0 ) {
            $y = $this->axisY1->getPosY(0);
            $this->image->line(
                $this->chart->marginXL - $carkaPresah                      , $y,
                $this->chart->marginXL + $this->chart->sizeX - $carkaPresah , $y,
                Image::rgb(150,150,150)
            );          
            $this->popiskaY( $y, true, 0 );
        }

        // dodelat tickery na levou osu
        // kolik tickeru se nam tam asi vleze
        $minTickerSize = 30;
        $numTickers = intval( $this->chart->sizeY / $minTickerSize );
        // velikost tickeru 
        $tickerSize = intval( ($this->axisY1->maxVal - $this->axisY1->minVal) / $numTickers );
        if( $tickerSize == 0 ) {
            $tickerSize = 1;
        }
        $tickerVal = intval($this->axisY1->minVal + (($this->axisY1->minVal > 0) ? 1 : 0 ) );
        
        while( $tickerVal < $this->axisY1->maxVal )
        {
            $y = $this->axisY1->getPosY($tickerVal);
            $this->image->line(
                $this->chart->marginXL - $carkaPresah , $y,
                $this->chart->marginXL + $carkaPresah , $y,
                Image::rgb(150,150,150)
            );          
            $this->popiskaY( $y, true, $tickerVal );

            if( $tickerVal!=0 ) {
                // vodorovna cara
                $this->image->dashedLine(
                    $this->chart->marginXL +1                      , $y,
                    $this->chart->marginXL + $this->chart->sizeX -1 , $y,
                    Image::rgb(200,200,200)
                );
            }

            $tickerVal += $tickerSize;
        }

        // vypsat nahoru jednotky
        $jednotka = $this->chart->series1[0]->data->firstSensor->unit;
        $this->popiskaY( $this->chart->marginYT - 18, true, $jednotka, true );

    }


    private function decorateAxisY2( $unit ) 
    {
        $carkaPresah = 3;

        // dodelat tickery na pravou osu
        // kolik tickeru se nam tam asi vleze
        $minTickerSize = 30;
        $numTickers = intval( $this->chart->sizeY / $minTickerSize );
        // velikost tickeru 
        $tickerSize = intval( ($this->axisY2->maxVal - $this->axisY2->minVal) / $numTickers );
        if( $tickerSize == 0 ) {
            $tickerSize = 1;
        }
        $tickerVal = intval($this->axisY2->minVal + (($this->axisY2->minVal > 0) ? 1 : 0 ) );
        
        while( $tickerVal < $this->axisY2->maxVal )
        {
            $y = $this->axisY2->getPosY($tickerVal);
            $this->image->line(
                $this->chart->marginXL + $this->chart->sizeX - $carkaPresah , $y,
                $this->chart->marginXL + $this->chart->sizeX + $carkaPresah , $y,
                Image::rgb(150,150,150)
            );          

            $this->popiskaY( $y, false, $tickerVal );

            $tickerVal += $tickerSize;
        }

        // vypsat nahoru jednotky
        $this->popiskaY( $this->chart->marginYT - 18, false, $unit, true );

    }


    /**
     * Vykresluje graf zadany v \App\Model\Chart
     */
    private function drawChart( $src, $intervalLenDays )
    {
        // spocteme rozsah jednotlivych os
        $this->axisY1 = ChartAxisY::prepareAxisY( $this->chart->series1, $this->chart->sizeY, $this->chart->marginYT );

        $this->axisY2 = ChartAxisY::prepareAxisY( 
            sizeof($this->chart->series2)!=0 ? $this->chart->series2 : $this->chart->series1, 
            $this->chart->sizeY, 
            $this->chart->marginYT 
        );

        $this->chart->changeBorders( $this->axisY1, $this->axisY2 );

        $this->createChart();

        $this->decorateAxisY1( $this->chart->series1[0]->data->firstSensor->unit );

        $this->decorateAxisY2( 
            sizeof($this->chart->series2)!=0 ? $this->chart->series2[0]->data->firstSensor->unit : $this->chart->series1[0]->data->firstSensor->unit
        );

        $this->axisX = new ChartAxisX( $this->chart->sizeX, $this->chart->marginXL, $intervalLenDays );
        $this->decorateAxisX();
        
        $this->createDecoration();

        $poradi = 0;

        // nejprve prava osa
        for($i = count($this->chart->series2) - 1; $i >= 0; $i--)
        {
            $serie = $this->chart->series2[$i];
            $this->drawSeries( $this->axisY2, $serie, $poradi++ );
        }
        
        // a pak to prepiseme levou
        for($i = count($this->chart->series1) - 1; $i >= 0; $i--)
        {
            $serie = $this->chart->series1[$i];
            $this->drawSeries( $this->axisY1, $serie, $poradi++ );
        }

        $time = intval( ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] ) * 1000.0 );
        Logger::log( 'webapp', Logger::DEBUG ,  "Chart: {$src}, {$intervalLenDays} d, t={$time} ms" ); 
        
        $this->image->send(Image::PNG);

    }

    
    /**
     * Nacte jedno ViewItem ve forme pole
     */
    private function loadSerie( $item, $startDateTime, $lenDays, $nr, $color ) 
    {
        $dataSeries=NULL;

        if( $item->source==1 ) {
            /*
                Pro několikadenní pohledy použije zdrojová data; pro delší použije hodinové sumarizace. 
                Pro data starsi nez 'dataRetentionDays' pouzije vzdy sumarizaci.
                Pro kompozity použije vždy sumarizaci.
                Pro velmi dlouhé pohledy použije denní minimum a maximum
                Vhodné pro teplotu.
            */
            $dateAge = intval( (new DateTime('now'))->diff($startDateTime)->format('%a') );
            // Debugger::log( "age {$dateAge} for {$startDateTime}" );

            if( ($lenDays <= 5) && ($dateAge < $this->dataRetentionDays) && sizeof($item->sensors)==1 ) {
                $dataSeries = $this->datasource->getSensorData_temperature_detail( $item->sensors[0], $startDateTime, $lenDays );    
            } else if($lenDays <= 90 ) {
                $dataSeries = $this->datasource->getSensorData_temperature_summary( $item->sensors, $startDateTime, $lenDays );    
            } else {
                $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 6 );
            }

        } else if( $item->source==2 ) {
            /*
                Maximalni denni hodnoty z dennich sumaru
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 2 );

        } else if( $item->source==3 ) {
            /*
                Minimalni denni hodnoty z dennich sumaru
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 1 );

        } else if( $item->source==4 ) {
            /*
                Prumerna denni hodnota z denniho sumare
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 3 );

        } else if( $item->source==5 ) {
            /*
                Vždy použije detailní data. Pokud nejsou k dispozici, nevraci data.
                Neumi kompozitni data!
                */
            $dataSeries = $this->datasource->getSensorData_temperature_detail( $item->sensors[0], $startDateTime, $lenDays );    

        } else if( $item->source==6 ) {
            /*
                Sumarni denni hodnota z denniho sumare
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 4 );

        } else if( $item->source==7 ) {
            /*
                Sumarni hodinova hodnota z denniho sumare
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 5 );

        } else if( $item->source==8 ) {
            /*
            * Hodinova maxima
            */
            $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 7 );

        } else if( $item->source==9 ) {
            /*
            * Hodinova maxima pro grafy do 90 dnu. 
            * Denni maxima pro grafy nad 90 dnu.
            */
            if($lenDays <= 90 ) {
                $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 7 );
            } else {
                $dataSeries = $this->datasource->getSensorData_minmaxavg_daysummary( $item->sensors, $startDateTime, $lenDays , 2 );
            }
        } 

        if( $dataSeries!=NULL ) {
            // Debugger::log(  'source:'. $item->source . ' ' . $dataSeries->toString() );
            $this->chart->pushSeries( 
                $item->axisY, 
                new ChartSeries($dataSeries, $color, $nr ) 
            );
        } 
    }

    /**
     * Nacita data serii podle zvolene strategie.
     */
    private function loadSeries( $view, $startDateTime, $lenDays, $nr )
    {
        foreach( $view->items as $item )
        {
            $this->loadSerie( $item, $startDateTime, $lenDays, $nr, $item->getColor($nr) );
        }
    }

    /**
     * Generuje obrazek - carovy graf
     */
    public function renderChart( $id, $token, $dateFrom, $lenDays, $altYear=NULL )
    {
        $view = $this->datasource->getView( $id, $token );
        
        // vypocet datumu
        $dateTimeFrom = DateTime::from( $dateFrom );

        $this->chart = new Chart( $dateTimeFrom );

        $this->loadSeries( $view, $dateTimeFrom, $lenDays, 1 );
        
        if( $altYear!=NULL and intval($altYear>2000)) {
            $altDateFrom = $dateTimeFrom->modifyClone();
            $altDateFrom->setDate( intval($altYear), intval($altDateFrom->format('m')), intval($altDateFrom->format('d')) );
            $this->loadSeries( $view, $altDateFrom, $lenDays, 2 );
        }       
        
        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 min'); 

        $this->drawChart( "ch={$id}", $lenDays );
    }



    /**
     * vrati pole [12][31] pro data jednotlivych dni; v kazde polozce bude klasifikace daneho pole
     *  1 nejvyssi, 4 nejnizsi
     */
    private function loadCoverageSeries( $sensor, $year )
    {
        $rc = array();

        $ct = 0;

        $data = $this->datasource->getSensorCoverageData( $sensor, $year );

        foreach ($data as $row) {
            $dts = DateTime::from( $row->rec_date );
            $m = $dts->format('n'); // aby to nebylo formatovane na dve mista
            $d = $dts->format('j'); // aby to nebylo formatovane na dve mista
            
            $out = 0;

            if( $sensor->device_class == 1 ) {
                if( $row['ct_val']>21 && $row['avg_val']!=null ) {
                    $out = 1; // nejvyssi kvalita
                } else if( $row['ct_val']>11 && $row['avg_val']!=null ) {
                    $out = 2; // jeste mam prumer
                } else if( $row['ct_val']>11  ) {
                    $out = 3; // nejaka data
                } else {
                    $out = 4; // minimum dat
                }
            } else if( $sensor->device_class == 3 ) {
                // pro impulzni senzor nemame prumer!

                if( $row['ct_val']>22  ) {
                    $out = 1; // nejvyssi kvalita
                } else if( $row['ct_val']>10  ) {
                    $out = 2; // jeste mam prumer
                } else {
                    $out = 4; // minimum dat
                }
            }

            $rc[$m][$d] = $out;
            $ct++;
        }

        return $rc;
    }


    const COVERAGE_PIXEL = 10;
    const COVERAGE_MARGIN_H = 20;
    const COVERAGE_MARGIN_V = 40;
    const COVERAGE_COL_SIZE = 330;     // MARGIN_H + 31*PIXEL
    const COVERAGE_ROW_SIZE = 160;     // MARGIN_V + 12*PIXEL

    private function drawCoverageSeries( $col, $row, $serie, $year, $sensor, $colors )
    {
        //D/Logger::log( 'webapp', Logger::DEBUG ,  "Coverage: renderCoverageSeries c={$col} r={$row} sensor={$sensor->id}" ); 

        $x = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE );
        $y = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) - 5;
        $text = "{$year}";
        $this->image->ttfText( 15, 0, $x, $y,  Image::rgb(0, 0, 0 ), $this->fontNameBold, $text );

        $x = $x + 60;
        $y = $y - 1;
        $text = substr( $sensor->desc, 0, 31 );
        $this->image->ttfText( 10, 0, $x, $y,  Image::rgb(0, 0, 0 ), $this->fontName, $text );

        $days = [ 0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
        for( $m = 1; $m<=12; $m++ ) {

            $mdays = $days[$m];
            if( $m==2 && ($year%4 == 0) ) {
                $mdays = 29;
            }

            $pixelWidth = 10;
            $pixelWidthCompensation = 1;
            if( $mdays == 30 ) {
                $pixelWidth = 10.3333;
                $pixelWidthCompensation = 0;
            } else if( $mdays == 29 ) {
                $pixelWidth = 10.6896;
                $pixelWidthCompensation = 0;
            } else if( $mdays == 28 ) {
                $pixelWidth = 11.071;
                $pixelWidthCompensation = -1;
            }

            if( $m!=12 ) {
                $x1 = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE );
                $x2 = $x1 + 31*self::COVERAGE_PIXEL - 1;
                $y1 = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) + ( $m * self::COVERAGE_PIXEL );
                $this->image->line(
                    $x1, $y1, 
                    $x2, $y1, 
                    Image::rgb(200,200,200) 
                );
            }
                
            for( $d = 1; $d<=$mdays; $d++ ) {
                if( isset($serie[$m][$d]) ) {
                    $color = $colors[ $serie[$m][$d] ];
                } else {
                    $color = $colors[0];
                }

                $x1 = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE ) + intval( ($d-1) * $pixelWidth );
                $x2 = $x1 + self::COVERAGE_PIXEL - $pixelWidthCompensation;
                
                $y1 = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) + ( ($m-1) * self::COVERAGE_PIXEL );
                $y2 = $y1 + self::COVERAGE_PIXEL - 2;
                
                $this->image->filledRectangle( $x1, $y1, $x2, $y2, $color );
            }
        }
    }


    /**
     * Generuje obrazek - coverage
     */
    public function renderCoverage( $id, $token, $dateFrom, $lenDays, $altYear=NULL )
    {
        $view = $this->datasource->getView( $id, $token );
        
        // zjistime historii, kterou mame vykreslovat
        $yearFrom = 2999;
        foreach( $view->items as $item ) {
            $dt = DateTime::from( $item->sensors[0]->first_login );
            Logger::log( 'webapp', Logger::DEBUG ,  "Coverage: sensor={$item->sensors[0]->id} from={$dt}" ); 
            $y = $dt->format('Y');
            if( $y < $yearFrom ) {
                $yearFrom = $y;
            }
        }

        $years = (new DateTime('now'))->format('Y') - $yearFrom + 1;

        $columns = count($view->items);
        Logger::log( 'webapp', Logger::DEBUG ,  "Coverage: {$id} cols={$columns} {$years} years from {$yearFrom}" ); 

        $imgWidth = self::COVERAGE_MARGIN_H + ( $columns * self::COVERAGE_COL_SIZE );
        $imgHeight = self::COVERAGE_MARGIN_V + ( $years * self::COVERAGE_ROW_SIZE ) ;

        // [0,0] je vlevo _nahore_

        // cela plocha obrazku
        $this->image = Image::fromBlank(
            $imgWidth, 
            $imgHeight, 
            Image::rgb(242, 242, 242) );

        $colors = array();
        $colors[0] = $this->image->colorAllocate( 255, 255, 255 );
        $colors[1] = $this->image->colorAllocate( 0, 68, 0 );
        $colors[2] = $this->image->colorAllocate( 17, 102, 17 );
        $colors[3] = $this->image->colorAllocate( 85, 170, 85 );
        $colors[4] = $this->image->colorAllocate( 136, 204, 136);

        $col = 0;
        foreach( $view->items as $item )
        {
            for( $i = 0; $i<$years; $i++ ) {
                // vrati pole [12][31] pro data jednotlivych dni; v kazde polozce bude klasifikace daneho pole
                $serie = $this->loadCoverageSeries( $item->sensors[0], $yearFrom + $i );
                $this->drawCoverageSeries( $col, $i, $serie, $yearFrom + $i, $item->sensors[0], $colors );
            }
            $col++;
        }

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 min');   
        
        $time = intval( ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] ) * 1000.0 );
        Logger::log( 'webapp', Logger::DEBUG ,  "Coverage: ch={$id}, t={$time} ms" ); 
        
        $this->image->send(Image::PNG);
    }    


    /** objekt Avg */
    private $avg;

    /** pole [year][month][day] s hodnotou pro dany den*/
    private $avgSeries;


    /**
     * Pokud je vice polozek, vybere tu, ktera ma nejvic ct_val
     * a posle hodnotu do objektu Avg a pole avgSeries
     */
    private function processAvgs( $values, $counts, $date )
    {
        $ct = count($values);
        //D/Logger::log( 'webapp', Logger::DEBUG ,  "Avg: {$date} ct={$ct}" ); 

        if( $ct==0 ) {
            return;
        } else if( $ct == 1 ) {
            $outVal = $values[0];
        } else {
            $max_ct = 0;
            for( $i=0; $i<$ct; $i++ ) {
                if( $counts[$i]>$max_ct ) {
                    $max_ct = $counts[$i];
                    $outVal = $values[$i];
                }
            }
        }

        $dt = DateTime::from( $date );
        $m = intval($dt->format('n'));
        $d = intval($dt->format('j'));
        $y = intval($dt->format('Y'));
        $this->avg->addValue( $m, $d, $outVal );
        $this->avgSeries[$y][$m][$d] = $outVal;
    }


    /**
     * Zpracuje prumery; vyplni objekt Avg a pole avgSeries
     * 
     * mode = 0 ... denni prumer
     * mode = 1 ... denni minimum
     */
    private function prepareAvgData( $sensors, $yearFrom, $years, $mode )
    {
        $this->avg = new Avg();
        $this->avgSeries = array();

        $result = $this->datasource->getAvgData( $sensors, $yearFrom, $years );

        $prevDate = NULL;
        $values = array();
        $counts = array();

        foreach ($result as $row) {
            //Debugger::log( $row );
            $this->dbRows++;

            if( $prevDate === NULL ) {
                // prvni radek na zacatku
                $prevDate = $row->rec_date;
            } 
            
            if( $prevDate!=$row->rec_date  ) {
                $this->processAvgs( $values, $counts, $prevDate );
                $values = array();
                $counts = array();
                $prevDate = $row->rec_date;
            }

            if( $row->avg_val!=null ) {
                if( $mode==0 ) {
                    $values[] = $row->avg_val;
                } else if( $mode==1 ) {
                    $values[] = $row->min_val;
                }
                $counts[] = $row->ct_val;
            }
        }
        $this->processAvgs( $values, $counts, $prevDate );
    }


    private function generateColors( $colorsText ) {
        $rc  = array();
        foreach( $colorsText as $color ) {
            $c = Color::parseHexColor( $color );
            $rc[] = $this->image->colorAllocate( $c->r, $c->g, $c->b );
        }
        return $rc;
    }

    private $colorsPlus;
    private $colorsMinus;
    private $colorZero;
    private $colorNoData;

    /**
     * Naplni promenne
     *     $colorsPlus;
           $colorsMinus;
           $colorZero;

     */
    private function generateHeatmapColors()
    {
        // cervena od nejsvetlejsi
        $this->colorsPlus = $this->generateColors( [ '#ffdbcd', '#ffc8b2', '#ffb597', '#ffa27e', '#ff8d66', '#ff774e', '#ff5f37', '#ff411f', '#ff0000' ] );

        // modra od nejsvetlejsi
        $this->colorsMinus = $this->generateColors( ['#c1eeff', '#aad8ec', '#93c3d8',  '#7daec6',  '#6799b3', '#5185a1',  '#3b7290',  '#235e7e',  '#004c6d' ] );

        $this->colorZero = $this->image->colorAllocate( 220,220,220 );;
        $this->colorNoData = $this->image->colorAllocate( 255,255,255 );;
    }


    /**
     * Generuje obrazek AVGTEMP
     */
    public function renderAvgtemp( $id, $token, $dateFrom, $lenDays, $altYear=NULL )
    {
        $view = $this->datasource->getView( $id, $token );
        
        // zjistime historii, kterou mame vykreslovat
        $yearFrom = 2999;
        $item = $view->items[0];
        foreach( $item->sensors as $sensor ) {
            $dt = DateTime::from( $sensor->first_login );
            Logger::log( 'webapp', Logger::DEBUG ,  "Avg: sensor={$sensor->id} from={$dt}" ); 
            $y = $dt->format('Y');
            if( $y < $yearFrom ) {
                $yearFrom = $y;
            }
        }

        $years = (new DateTime('now'))->format('Y') - $yearFrom + 1;

        $columns = 1; // count($view->items);
        Logger::log( 'webapp', Logger::DEBUG ,  "Avg: {$id} cols={$columns} {$years} years from {$yearFrom}" ); 

        $imgWidth = self::COVERAGE_MARGIN_H + self::COVERAGE_COL_SIZE;
        $imgHeight = self::COVERAGE_MARGIN_V + self::COVERAGE_ROW_SIZE ;

        // [0,0] je vlevo _nahore_

        // cela plocha obrazku
        $this->image = Image::fromBlank(
            $imgWidth, 
            $imgHeight, 
            Image::rgb(242, 242, 242) );

        // vyplni $this->avg a $this->avgSeries
        $this->prepareAvgData( $view->items[0]->sensors, $yearFrom, $years, 0 );

        /*D
        for( $m = 1; $m<=12; $m++ ) {
            for( $d = 1; $d<=31; $d++ ) {
                Logger::log( 'webapp', Logger::DEBUG ,  "Avg {$m}/{$d} = {$this->avg->toString($m,$d)}" );
            }
        }
        */

        $this->generateHeatmapColors();

        $col = 0;
        $row = 0;

        $days = [ 0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
        for( $m = 1; $m<=12; $m++ ) {

            $mdays = $days[$m];

            $pixelWidth = 10;
            $pixelWidthCompensation = 1;
            if( $mdays == 30 ) {
                $pixelWidth = 10.3333;
                $pixelWidthCompensation = 0;
            } else if( $mdays == 29 ) {
                $pixelWidth = 10.6896;
                $pixelWidthCompensation = 0;
            } else if( $mdays == 28 ) {
                $pixelWidth = 11.071;
                $pixelWidthCompensation = -1;
            }

            if( $m!=12 ) {
                $x1 = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE );
                $x2 = $x1 + 31*self::COVERAGE_PIXEL - 1;
                $y1 = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) + ( $m * self::COVERAGE_PIXEL );
                $this->image->line(
                    $x1, $y1, 
                    $x2, $y1, 
                    Image::rgb(200,200,200) 
                );
            }
                
            for( $d = 1; $d<=$mdays; $d++ ) {
                
                $val = $this->avg->getAvg($m,$d);
                
                if( $val==null ) {
                    $color = $this->colorNoData;
                } else if( $val>=0 ) {
                    $idx = intval( $val * 9 / 25 );
                    if( $idx>8 ) $idx=8;
                    $color = $this->colorsPlus[ $idx ];
                } else if( $val<0 ) {
                    $idx = intval( -$val * 9 / 15 );
                    if( $idx>8 ) $idx=8;
                    $color = $this->colorsMinus[ $idx ];
                }

                $x1 = self::COVERAGE_MARGIN_H + intval( ($d-1) * $pixelWidth );
                $x2 = $x1 + self::COVERAGE_PIXEL - $pixelWidthCompensation;
                
                $y1 = self::COVERAGE_MARGIN_V + ( ($m-1) * self::COVERAGE_PIXEL );
                $y2 = $y1 + self::COVERAGE_PIXEL - 2;
                
                $this->image->filledRectangle( $x1, $y1, $x2, $y2, $color );
            }
        }

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 min');   
        
        $time = intval( ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] ) * 1000.0 );
        Logger::log( 'webapp', Logger::DEBUG ,  "Avgtemp: ch={$id} rows={$this->dbRows} t={$time} ms" ); 
        
        $this->image->send(Image::PNG);
    } 

    public function renderAvgyears0( $id, $token, $dateFrom, $lenDays, $altYear=NULL )
    {
        $this->render_Avgyears( $id, $token, $dateFrom, $lenDays, $altYear, 0 );
    }

    public function renderAvgyears1( $id, $token, $dateFrom, $lenDays, $altYear=NULL )
    {
        $this->render_Avgyears( $id, $token, $dateFrom, $lenDays, $altYear, 1 );
    }

    /**
     * Generuje obrazek AVGYEARS
     * mode = 0 ... denni prumer
     * mode = 1 ... denni minimum
     */
    public function render_Avgyears( $id, $token, $dateFrom, $lenDays, $altYear=NULL, $mode )
    {
        $view = $this->datasource->getView( $id, $token );
        
        // zjistime historii, kterou mame vykreslovat
        $yearFrom = 2999;
        $item = $view->items[0];
        foreach( $item->sensors as $sensor ) {
            $dt = DateTime::from( $sensor->first_login );
            Logger::log( 'webapp', Logger::DEBUG ,  "Avg: sensor={$sensor->id} from={$dt}" ); 
            $y = $dt->format('Y');
            if( $y < $yearFrom ) {
                $yearFrom = $y;
            }
        }

        $years = (new DateTime('now'))->format('Y') - $yearFrom + 1;

        $columns = 1; // count($view->items);
        Logger::log( 'webapp', Logger::DEBUG ,  "Avg: {$id} cols={$columns} {$years} years from {$yearFrom}" ); 

        $imgWidth = self::COVERAGE_MARGIN_H + ( $columns * self::COVERAGE_COL_SIZE );
        $imgHeight = self::COVERAGE_MARGIN_V + ( $years * self::COVERAGE_ROW_SIZE ) ;

        // [0,0] je vlevo _nahore_

        // cela plocha obrazku
        $this->image = Image::fromBlank(
            $imgWidth, 
            $imgHeight, 
            Image::rgb(242, 242, 242) );

        // vyplni $this->avg a $this->avgSeries
        $this->prepareAvgData( $view->items[0]->sensors, $yearFrom, $years, $mode );

        $this->generateHeatmapColors();
        $this->colorsPlus[0] = $this->colorNoData;
        $this->colorsMinus[0] = $this->colorNoData;

        $col = 0;
        for( $row = 0; $row<$years; $row++ ) {

            $year = $yearFrom + $row;
            $ctPlus = 0; $ctPlusMoc = 0; $ctMinus = 0; $ctMinusMoc = 0;

            $x = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE );
            $y = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) - 5;
            $text = "{$year}";
            $this->image->ttfText( 15, 0, $x, $y,  Image::rgb(0, 0, 0 ), $this->fontNameBold, $text );

            $days = [ 0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
            for( $m = 1; $m<=12; $m++ ) {

                $mdays = $days[$m];
                if( $m==2 && ($year%4 == 0) ) {
                    $mdays = 29;
                }

                $pixelWidth = 10;
                $pixelWidthCompensation = 1;
                if( $mdays == 30 ) {
                    $pixelWidth = 10.3333;
                    $pixelWidthCompensation = 0;
                } else if( $mdays == 29 ) {
                    $pixelWidth = 10.6896;
                    $pixelWidthCompensation = 0;
                } else if( $mdays == 28 ) {
                    $pixelWidth = 11.071;
                    $pixelWidthCompensation = -1;
                }

                if( $m!=12 ) {
                    $x1 = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE );
                    $x2 = $x1 + 31*self::COVERAGE_PIXEL - 1;
                    $y1 = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) + ( $m * self::COVERAGE_PIXEL );
                    $this->image->line(
                        $x1, $y1, 
                        $x2, $y1, 
                        Image::rgb(200,200,200) 
                    );
                }
                    
                for( $d = 1; $d<=$mdays; $d++ ) {
                    
                    $avgVal = $this->avg->getAvg($m,$d);
                    $val = isset($this->avgSeries[$year][$m][$d]) ? $this->avgSeries[$year][$m][$d] : null;
                    
                    if( $val==null || $avgVal==null ) {
                        $color = $this->colorNoData;
                    } else if( $val-$avgVal >= 0 ) {
                        $idx = intval( $val-$avgVal );
                        if( $idx>8 ) $idx=8;
                        if( $idx>0 ) $ctPlus++;
                        if( $idx>4 ) $ctPlusMoc++;
                        $color = $this->colorsPlus[ $idx ];
                    } else if( $val-$avgVal < 0 ) {
                        $idx = intval( $avgVal-$val );
                        if( $idx>8 ) $idx=8;
                        if( $idx>0 ) $ctMinus++;
                        if( $idx>4 ) $ctMinusMoc++;
                        $color = $this->colorsMinus[ $idx ];
                    }

                    $x1 = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE ) + intval( ($d-1) * $pixelWidth );
                    $x2 = $x1 + self::COVERAGE_PIXEL - $pixelWidthCompensation;
                    
                    $y1 = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) + ( ($m-1) * self::COVERAGE_PIXEL );
                    $y2 = $y1 + self::COVERAGE_PIXEL - 2;
                    
                    $this->image->filledRectangle( $x1, $y1, $x2, $y2, $color );
                }
            }

            $x = self::COVERAGE_MARGIN_H + ( $col * self::COVERAGE_COL_SIZE ) + 60;
            $y = self::COVERAGE_MARGIN_V + ( $row * self::COVERAGE_ROW_SIZE ) - 4;
            if( $ctMinus == $ctPlus ) {
                $znak = "==";
            } else if( $ctMinus < $ctPlus ) {
                $znak = "<<";
            } else {
                $znak = ">>";
            }
            $text = "--{$ctMinusMoc} -{$ctMinus} {$znak} +{$ctPlus} ++{$ctPlusMoc}";
            $this->image->ttfText( 10, 0, $x, $y,  Image::rgb(0, 0, 0 ), $this->fontName, $text );
        }

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 min');  
        
        $time = intval( ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] ) * 1000.0 );
        Logger::log( 'webapp', Logger::DEBUG ,  "Avgyears: ch={$id} rows={$this->dbRows} t={$time} ms" ); 
        
        $this->image->send(Image::PNG);
    } 


    /**
     * Generuje obrazek - carovy graf pro jeden senzor, autorizovany (z administrace)
     */
    public function renderSensorchart( $id, $dateFrom, $lenDays, $altYear=NULL )
    {
        $this->checkUserRole( 'user' );

        // vypocet datumu
        $dateTimeFrom = DateTime::from( $dateFrom );

        $sensor = $this->datasource->getSensor( $id );
        $this->checkSensorAccess( $sensor!=NULL ? $sensor->user_id : NULL , $id );

        $viewitem = new ViewItem ();
        $viewitem->pushSensor( $sensor );
        $viewitem->source = $this->getViewSourceId( $sensor->device_class, $lenDays );
        $viewitem->axisY = 1;
        
        $this->chart = new Chart( $dateTimeFrom );

        $this->loadSerie( $viewitem, $dateTimeFrom, $lenDays, 1, new Color( 255, 0, 0 ) );
        
        if( $altYear!=NULL and intval($altYear>2000)) {
            $altDateFrom = $dateTimeFrom->modifyClone();
            $altDateFrom->setDate( intval($altYear), intval($altDateFrom->format('m')), intval($altDateFrom->format('d')) );
            $this->loadSerie( $viewitem, $altDateFrom, $lenDays, 2, new Color( 0, 0, 255 ) );
        }        

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 min'); 

        $this->drawChart( "s={$id}", $lenDays );
    }


    private $devices;


    private function addViewItems( $outView, $view, $dateFrom, $nr )
    {
        foreach( $view->items as $item )
        {
            if( $item->source==1 ) {
                $this->template->source1 = TRUE;
            }
            $vi = $item->toArray();
            $vi['color'] = $item->getColor($nr)->getHtmlColor();
            $vi['date'] = $dateFrom->format('d.m.Y');
            $vi['nr'] = $nr;
            $outView[] = $vi;

            // podklady pro text na strance
            foreach( $item->sensors as $sensor ) {
                $device['name'] = $sensor->dev_name;
                $device['desc'] = $sensor->dev_desc;
                $this->devices[$sensor->device_id] = $device;
            }

            if( $item->isKompozit() ) {
                $this->template->isKompozit = TRUE;
            }
            
        }
        return $outView;
    }


    // http://lovecka.info/ra/chart/view/aaabbb/2/?dateFrom=2020-01-02&lenDays=10
    // http://lovecka.info/ra/chart/view/aaabbb/2/?dateFrom=2020-01-02&lenDays=10&altYear=2016
    public function renderView( $id, $token, $dateFrom="", $lenDays=7, $altYear="", 
                $plus="", $minus="", 
                $altplus="", $altminus="" ,
                $current="", $currentweek="", $currentmonth="", $currentyear="",
                $plusMon="", $minusMon="",
                $plusYear="", $minusYear="",
                $currentday=""
                )
    {
        // Debugger::log( "view:{$id} from:{$dateFrom} alt:{$altYear} len:{$lenDays}"  );
        
        $params = new ChartParameters( $dateFrom, $lenDays, $altYear, 
                        $plus, $minus, 
                        $altplus, $altminus ,
                        $current, $currentweek, $currentmonth, $currentyear,
                        $plusMon, $minusMon,
                        $plusYear, $minusYear,
                        $currentday,

                        $this->minYear);

        $view = $this->datasource->getView( $id, $token );
        $params->allowCompare(  $view->allowCompare );

        if( $view->render==="coverage" ) {
            $this->template->renderControls = false;
            $this->template->renderSeriesInfo = false;
            $this->template->renderCoverageInfo = true;
            $this->template->renderAvgInfo = false;
            $this->template->renderSensorsInfo = true;
        } else if( $view->render==="avgtemp" || $view->render==="avgyears0" || $view->render==="avgyears1" ) {
            $this->template->renderControls = false;
            $this->template->renderSeriesInfo = false;
            $this->template->renderCoverageInfo = false;
            $this->template->renderAvgInfo = true;
            $this->template->renderSensorsInfo = true;

            /*
            $this->image = Image::fromBlank( 10,10, Image::rgb(242, 242, 242) );
            $this->generateHeatmapColors();
            $this->template->colorsPlus = $this->colorsPlus;
            $this->template->colorsMinus = $this->colorsMinus;
            */
        } else {
            $this->template->renderControls = true;
            $this->template->renderSeriesInfo = true;
            $this->template->renderCoverageInfo = false;
            $this->template->renderAvgInfo = false;
            $this->template->renderSensorsInfo = false;
        }

        $this->template->view = $view;
        $this->template->id = $id;
        $this->template->token = $token;
        $this->template->dateFrom = $params->dateTimeFrom->format('Y-m-d');
        $this->template->lenDays = $params->lenDays;
        $this->template->altYear = $params->altYear;
        $this->template->appName = $view->appName;
        $this->template->menu = $this->datasource->readViews( $token );
        $this->template->dataRetentionDays = $this->dataRetentionDays;
        $this->template->links = $this->links;

        $chart = new Chart( NULL );
        $this->template->chW = $chart->width();
        $this->template->chH = $chart->height();
        // sirka sloupce pro vykresleni - obrazek + mala rezerva
        $this->template->maxW = $chart->width() + 85;

        $this->template->source1 = FALSE;
        $this->template->isKompozit = FALSE;

        $outView = array();
        $outView = $this->addViewItems( $outView, $view, $params->dateTimeFrom, 1 );
        if( $params->altSeries() ) {
            $outView = $this->addViewItems( $outView, $view, $params->altDateFrom, 2 );
        }
        $this->template->items = $outView;
        $this->template->devices = $this->devices;
        $this->template->years = $params->getAltYearsList();
        $this->template->minYear = $this->minYear;

    }


    /**
     * Pouziva se pro jen vykresleni automaticky pripraveneho grafu volaneho z detailu zarizeni.
     */
    private function getViewSourceId( $device_class, $lenDays )
    {
        if( $device_class==1 ) {    
            // CONTINUOUS_MINMAXAVG
            $vsId = 1; // Automatická data
        } else if( $device_class==2 ) {    
            // CONTINUOUS
            $vsId = 5; // Detailní data
        } else {
            // IMPULSE_SUM
            if( $lenDays > 30 ) {
                $vsId = 6;  // denni suma
            } else {
                $vsId = 7;  // hodinova suma
            }
        }
        return $vsId;
    }


    
    /**
     * Pouziva se pro jen vykresleni automaticky pripraveneho grafu volaneho z detailu zarizeni.
     */
    private function checkSensorAccess( $sensorUserId, $sensorId )
    {
        if( $this->getUser()->id != $sensorUserId ) {
            Logger::log( 'audit', Logger::ERROR , 
                "[{$this->getHttpRequest()->getRemoteAddress()}] ACCESS: Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pouzit senzor #{$sensorId} patrici jinemu uzivateli" ); 
                
            $this->getUser()->logout();
            $this->flashMessage('K tomuto sensoru nemáte práva!');
            $this->redirect('Sign:in');
        }
    }


    // http://lovecka.info/ra/chart/sensor/show/6/
    /**
     * Rendering grafu pro senzor - volano z administrace, autentizovany uzivatel.
     */
    public function renderSensor( $id, $dateFrom="", $lenDays=7, $altYear="", 
                $plus="", $minus="", 
                $altplus="", $altminus="" ,
                $current="", $currentweek="", $currentmonth="", $currentyear="",
                $plusMon="", $minusMon="",
                $plusYear="", $minusYear="",
                $currentday=""
                )
    {
        // Debugger::log( "view:{$id} from:{$dateFrom} alt:{$altYear} len:{$lenDays}"  );

        $this->checkUserRole( 'user' );
        
        $params = new ChartParameters( $dateFrom, $lenDays, $altYear, 
                        $plus, $minus, 
                        $altplus, $altminus ,
                        $current, $currentweek, $currentmonth, $currentyear,
                        $plusMon, $minusMon,
                        $plusYear, $minusYear,
                        $currentday,

                        $this->minYear);

        $params->allowCompare( TRUE );
        $this->template->allowCompare = TRUE;
        $this->template->id = $id;
        $this->template->dateFrom = $params->dateTimeFrom->format('Y-m-d');
        $this->template->lenDays = $params->lenDays;
        $this->template->altYear = $params->altYear;
        $this->template->appName = $this->appName;

        $this->template->dataRetentionDays = $this->dataRetentionDays;
        $this->template->links = $this->links;

        $chart = new Chart( NULL );
        $this->template->chW = $chart->width();
        $this->template->chH = $chart->height();
        // sirka sloupce pro vykresleni - obrazek + mala rezerva
        $this->template->maxW = $chart->width() + 85;

        $sensor = $this->datasource->getSensor( $id );
        $this->checkSensorAccess( $sensor!=NULL ? $sensor->user_id : NULL, $id );
        $this->template->sensor = $sensor;

        $device['name'] = $sensor->dev_name;
        $device['desc'] = $sensor->dev_desc;
        $this->devices[$sensor->device_id] = $device;

        $viewSource = $this->datasource->getViewSource( $this->getViewSourceId( $sensor->device_class, $params->lenDays ) );

        $this->template->source1 = TRUE;
        $this->template->isKompozit = FALSE;
        $outView = array();
        $vi = array();
        $vi['sensor_ids'] = $id;
        $vi['axis'] = 1;
        $vi['name'] = $sensor->desc;
        $vi['sensor_name'] = $sensor->dev_name . ':' . $sensor->name;
        $vi['unit'] = $sensor->unit;
        $vi['source_desc'] = $viewSource->short_desc;
        $vi['color'] = (new Color( 255, 0, 0 ))->getHtmlColor();
        $vi['date'] = $params->dateTimeFrom->format('d.m.Y');
        $vi['nr'] = 1;
        $outView[] = $vi;

        if( $params->altSeries() ) {
            $vi2 = array();
            $vi2['sensor_ids'] = $id;
            $vi2['axis'] = 1;
            $vi2['name'] = $sensor->desc;
            $vi2['sensor_name'] = $sensor->dev_name . ':' . $sensor->name;
            $vi2['unit'] = $sensor->unit;
            $vi2['source_desc'] = $viewSource->short_desc;
            $vi2['color'] = (new Color( 0, 0, 255 ))->getHtmlColor();
            $vi2['date'] = $params->altDateFrom->format('d.m.Y');
            $vi2['nr'] = 1;
            $outView[] = $vi2;
        }

        $this->template->items = $outView;
        $this->template->devices = $this->devices;
        $this->template->years = $params->getAltYearsList();
        $this->template->minYear = $this->minYear;

        $this->template->name = $vi['sensor_name'];
        $this->template->desc = $vi['name'];

        $this->populateChartMenu( $id, $sensor->name, 101, $sensor->device_id, $sensor->dev_name );

        $this->template->isChart = TRUE;

        $this->template->path = "../../../";

    }


    /**
     * Rendering statistiky pro senzor - volano z administrace, autentizovany uzivatel.
     */
    public function renderSensorstat( $id, $dateFrom="", $lenDays=7, $altYear="", 
                $plus="", $minus="", 
                $altplus="", $altminus="" ,
                $current="", $currentweek="", $currentmonth="", $currentyear="",
                $plusMon="", $minusMon="",
                $plusYear="", $minusYear="",
                $currentday=""
                )
    {
        // Debugger::log( "view:{$id} from:{$dateFrom} alt:{$altYear} len:{$lenDays}"  );

        $this->checkUserRole( 'user' );
        
        $params = new ChartParameters( $dateFrom, $lenDays, $altYear, 
                        $plus, $minus, 
                        $altplus, $altminus ,
                        $current, $currentweek, $currentmonth, $currentyear,
                        $plusMon, $minusMon,
                        $plusYear, $minusYear,
                        $currentday,

                        $this->minYear);

        $params->allowCompare( TRUE );
        $this->template->allowCompare = TRUE;
        $this->template->id = $id;
        $this->template->dateFrom = $params->dateTimeFrom->format('Y-m-d');
        $this->template->lenDays = $params->lenDays;
        $this->template->altYear = $params->altYear;
        $this->template->appName = $this->appName;
        
        $chart = new Chart( NULL );
        $this->template->chW = $chart->width();
        $this->template->chH = $chart->height();
        // sirka sloupce pro vykresleni - obrazek + mala rezerva
        $this->template->maxW = $chart->width() + 85;

        $this->template->dataRetentionDays = $this->dataRetentionDays;
        $this->template->links = $this->links;

        $sensor = $this->datasource->getSensor( $id );
        $this->template->sensor = $sensor;
        $this->checkSensorAccess( $sensor!=NULL ? $sensor->user_id : NULL , $id);

        $device['name'] = $sensor->dev_name;
        $device['desc'] = $sensor->dev_desc;
        $this->devices[$sensor->device_id] = $device;

        $viewSource = $this->datasource->getViewSource( $this->getViewSourceId( $sensor->device_class, $params->lenDays ) );

        $this->template->source1 = TRUE;
        $this->template->isKompozit = FALSE;
        $outView = array();
        $vi = array();
        $vi['sensor_ids'] = $id;
        $vi['axis'] = 1;
        $vi['name'] = $sensor->desc;
        $vi['sensor_name'] = $sensor->dev_name . ':' . $sensor->name;
        $vi['unit'] = $sensor->unit;
        $vi['source_desc'] = $viewSource->short_desc;
        $vi['color'] = (new Color( 255, 0, 0 ))->getHtmlColor();
        $vi['date'] = $params->dateTimeFrom->format('d.m.Y');
        $vi['nr'] = 1;
        $outView[] = $vi;

        $this->template->items = $outView;
        $this->template->devices = $this->devices;
        $this->template->years = $params->getAltYearsList();
        $this->template->minYear = $this->minYear;

        $this->template->name = $vi['sensor_name'];
        $this->template->desc = $vi['name'];

        $this->populateChartMenu( $id, $sensor->name, 100, $sensor->device_id, $sensor->dev_name );

        $this->template->isChart = TRUE;

        $this->template->path = "../../../";

        $this->template->measureStats = $this->datasource->getMeasuresStats( $id ); 
        $this->template->sumdataStats = $this->datasource->getSumdataStats( $id ); 
        $this->template->sumdataCount = $this->datasource->getSumdataCount( $id ); 

    }

}
