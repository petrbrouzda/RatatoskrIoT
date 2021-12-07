<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Nette\Utils\Image;

use App\Services\Logger;

final class CrontaskPresenter extends Nette\Application\UI\Presenter
{
    use Nette\SmartObject;

    const NAME = 'cron';

    /** Doba drzeni beznych logu, dny  */
    const LOG_RETENTION_BASE = 7;
    /** Doba drzeni audit logu, dny */
    const LOG_RETENTION_AUDIT = 31;
    
    /** Kolik radku se nacte z DB pro zacatek zpracovani  */
    public $batchSize = 4000;
    /** Maximalni delka behu jednoho tasku v sekundach */
    public $maxRunTime1 = 40;
    /** Maximalni delka behu jednoho tasku v sekundach */
    public $maxRunTime2 = 15;
    /** Maximalni delka behu jednoho tasku v sekundach */
    public $maxRunTime3 = 25;

    /**
     * Nutny konec prace
     */
    public $endTime;

    public $startTime;
    public $processedBatches = 0;
    public $processedRecords = 0;



    /** @var \App\Services\CrontaskDataSource */
    private $datasource;

    private $mailService;

    private $config;
    
    public function __construct(    \App\Services\CrontaskDataSource $datasource, 
                                    \App\Services\MailService $mailsv, 
                                    \App\Services\Config $cfg )
    {
        $this->datasource = $datasource;
        $this->mailService = $mailsv;
        $this->config = $cfg;
    }


    /**
     * Zkontroluje prekroceni min/max limitu
     */
    private function checkMinMaxLimits( $sensor, $value_out, $data_ts )
    {
        $zapisWarningy = 0;

        if( isset($sensor['warn_max']) && $sensor['warn_max'] ) { // mame hlidat maximum
            if( $value_out >= $sensor['warn_max_val'] ) { // prekrocene maximum

                // zacatek udalosti
                if( ! $sensor['warn_max_fired'] ) { // pokud to nemame zapsane
                    $sensor['warn_max_fired'] = $data_ts;
                    $sensor['warn_max_sent'] = 0;
                    $zapisWarningy = 1;
                    Logger::log( self::NAME,  Logger::INFO,  "MAX reached: {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] {$value_out} >= {$sensor['warn_max_val']}" );
                }

                // poslani notifikace
                if( $sensor['warn_max_fired'] && $sensor['warn_max_sent']==0 ) {
                    // casova vzdalenost!
                    $utime = (DateTime::from( $sensor['warn_max_fired'] ))->getTimestamp();
                    if( time()-$utime > $sensor['warn_max_after'] ) {
                        $sensor['warn_max_sent'] = 1;
                        $zapisWarningy = 1;
                        $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], 1, $sensor['warn_max_text'], $value_out, $sensor['warn_max_fired'] );
                        Logger::log( self::NAME,  Logger::INFO,  "MAX notification: {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] {$value_out} >= {$sensor['warn_max_val']} delay={$sensor['warn_max_after']} s" );
                    }
                }

            } else if( $value_out < $sensor['warn_max_val_off'] ) { // jsme OK pod vypinacim limitem
                if( $sensor['warn_max_fired'] ) { // ale mame znacku, ze jsme nad => smazat
                    $sensor['warn_max_fired'] = null;
                    $zapisWarningy = 1;
                    $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], -1, $sensor['warn_max_text'], $value_out, $data_ts );
                    Logger::log( self::NAME,  Logger::INFO,  "MAX cleared {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] " );
                }
            }
        }

        if( isset($sensor['warn_min']) && $sensor['warn_min']  ) { // mame hlidat minimum
            if( $value_out <= $sensor['warn_min_val'] ) { // prekrocene minimum

                // zacatek udalosti
                if( ! $sensor['warn_min_fired'] ) { // pokud to nemame zapsane
                    $sensor['warn_min_fired'] = $data_ts;
                    $sensor['warn_min_sent'] = 0;
                    $zapisWarningy = 1;
                    Logger::log( self::NAME, Logger::INFO,  "MIN reached: {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] {$value_out} <= {$sensor['warn_min_val']}" );
                }

                // poslani notifikace
                if( $sensor['warn_min_fired'] && $sensor['warn_min_sent']==0 ) {
                    // casova vzdalenost!
                    $utime = (DateTime::from( $sensor['warn_min_fired'] ))->getTimestamp();
                    if( time()-$utime > $sensor['warn_min_after'] ) {
                        $sensor['warn_min_sent'] = 1;
                        $zapisWarningy = 1;
                        $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], 2, $sensor['warn_min_text'], $value_out, $sensor['warn_min_fired'] );
                        Logger::log( self::NAME,  Logger::INFO,  "MIN notification: {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] {$value_out} <= {$sensor['warn_min_val']} delay={$sensor['warn_min_after']} s" );
                    }
                }

            } else if( $value_out > $sensor['warn_min_val_off'] ) { // jsme OK nad limitem
                if( $sensor['warn_min_fired'] ) { // ale mame znacku, ze jsme pod => smazat
                    $sensor['warn_min_fired'] = null;
                    $zapisWarningy = 1;
                    $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], -2, $sensor['warn_min_text'], $value_out, $data_ts );
                    Logger::log( self::NAME, Logger::INFO,  "MIN cleared {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] " );
                }
            }
        }

        return $zapisWarningy;
    }

    /**
     * Zkontroluje neaktivitu
     */
    private function checkLastDataTs( $sensor )
    {
        $zapisWarningy = 0;

        $utime = (DateTime::from( $sensor['last_data_time'] ))->getTimestamp();
        if( time()-$utime > $sensor['msg_rate'] ) { // nechodi zpravy
            if( ! $sensor['warn_noaction_fired'] ) { // nemame to zapsane
                $sensor['warn_noaction_fired'] = new DateTime();
                $zapisWarningy = 1;
                $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], 4, "{$sensor['last_data_time']}", 0, new DateTime() );
                Logger::log( self::NAME,  Logger::INFO,  "Notification NO_DATA: {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] " );
            }
        } else { // zpravy chodi
            if( $sensor['warn_noaction_fired'] ) { // ale mame znacku, ze nechodi => smazat
                $sensor['warn_noaction_fired'] = null;
                $zapisWarningy = 1;
                $this->datasource->insertNotification( $sensor['device_id'], $sensor['id'], -4, "{$sensor['last_data_time']}", 0 , new DateTime() );
                Logger::log( self::NAME,  Logger::INFO,  "Notification NO_DATA cleared {$sensor['id']} [{$sensor['dev_name']}:{$sensor['name']}] " );
            }
        }

        return $zapisWarningy;
    }

    /**
     * Zkontroluje stav senzoru a pripravi notifikace, pokud jsou nejake ve spatnem stavu
     */
    private function checkSensors( $logger )
    {   
        $rows = $this->datasource->getSensors();
        foreach ($rows as $sensor) {
            
            $zapisWarningy = 0;

            $value_out = isset($sensor['last_out_value']) ? $sensor['last_out_value'] : null;
            if( $value_out !== null ) {
                $zapisWarningy += $this->checkMinMaxLimits( $sensor, $value_out, $sensor['last_data_time'] );
            }

            if( $sensor['last_data_time'] && $sensor['monitoring']  && $sensor['msg_rate']!=0  ) {
                $zapisWarningy += $this->checkLastDataTs( $sensor );
            }

            if( $zapisWarningy ) {
                $this->datasource->updateSensorsWarnings( $sensor );
            }
        }
    }



    /**
     * Zpracuje cekajici notifikace
     */
    private function sendNotificationMails( $logger )
    {
        // id	rauser_id	device_id	sensor_id	event_type	event_ts	status	custom_text	out_value	
        // user_id	last_login	dev_name	dev_desc	s_name	s_desc	u1_name	u1_email
        $rows = $this->datasource->getNotifications();
        foreach ($rows as $row)
        {
            $type = abs($row['event_type']);
            $eventStart = $row['event_type'] > 0;
            if( $eventStart ) {
                $prefix = "VAROVÁNÍ: ";
            } else {
                $prefix = "Konec poplachu: ";
            }

            if( $type==1 ) {
                $subject = "Hodnota moc vysoká - {$row['dev_name']}:{$row['s_name']}";
                $text = 
                    "<p>{$prefix} {$subject}</p>
                    <p><b>{$row['custom_text']}<b></p>
                    <p>
                    Hodnota: <b>{$row['out_value']} {$row['unit']}</b>
                    <br>Zařízení: <b>{$row['dev_name']}</b> ({$row['dev_desc']})
                    <br>Sensor: <b>{$row['s_name']}</b> ({$row['s_desc']})
                    <br>Čas: <b>{$row['event_ts']}</b>
                    </p>
                    ";
            } else if( $type==2 ) {
                $subject = "Hodnota příliš nízká - {$row['dev_name']}:{$row['s_name']}";
                $text = 
                    "<p>{$prefix} {$subject}</p>
                    <p><b>{$row['custom_text']}<b></p>
                    <p>
                    Hodnota: <b>{$row['out_value']} {$row['unit']}</b>
                    <br>Zařízení: <b>{$row['dev_name']}</b> ({$row['dev_desc']})
                    <br>Sensor: <b>{$row['s_name']}</b> ({$row['s_desc']})
                    <br>Čas: <b>{$row['event_ts']}</b>
                    </p>
                    ";
            } else if( $type==4 ) {
                $subject = "Ze senzoru nepřichází data - {$row['dev_name']}:{$row['s_name']}";
                $text = 
                    "<p>{$prefix} {$subject}</p>
                    <p>
                    Zařízení: <b>{$row['dev_name']}</b> ({$row['dev_desc']})
                    <br>Sensor: <b>{$row['s_name']}</b> ({$row['s_desc']})
                    <br>Poslední data: <b>{$row['custom_text']}<b>
                    <br>Aktuální čas: <b>{$row['event_ts']}</b>
                    </p>
                    ";
            }

            $logger->write(  Logger::INFO, "Notifikace #{$row['id']} '{$prefix} {$subject}' pro {$row['u1_email']}" );

            $this->mailService->sendMail( 
                $row['u1_email'],
                "{$prefix} {$subject}",
                $text
            );

            $this->datasource->closeNotification($row['id']);
        }
    }


    private function shouldExit()
    {
        return (time() > $this->endTime);
    }


    /**
     * projde vsechny zaznamy za danou hodinu a 
     * - pro zaznamy, kde neni status=1:
     *      - nastavi status=1
     * - spocte hodinove prumery/sumy a ulozi je do tabulky, pokud to pro dany senzor ma delat
     */
    private function processSensorHour( $sensorId, $date, $hour, $logger )
    {
        $logger->write(  Logger::DEBUG,  "Measures for sensor=$sensorId; $date $hour" );

        $min = NULL;
        $min_time = NULL;
        $max = NULL;
        $max_time = NULL;
        $avg=NULL;
        $sum = 0;

        $sensor = $this->datasource->getSensor($sensorId);
        if( ! $sensor ) {
            $logger->write(  Logger::ERROR, "Nenalezen senzor {$sensorId}!" );
            return;
        }
        $rows = $this->datasource->getRecordsForSensorHour( $sensorId, $date, $hour );
        foreach ($rows as $rec)
        {
            //D/ Logger::log( self::NAME, Logger::DEBUG,  $rec );

            if( $sensor->device_class == 1 ) {
                // maji se pocitat prumery hodnot
                if( $min===NULL ) {
                    // prvni zaznam
                    $min = $rec->out_value;
                    $min_time = $rec->data_time;     
                    $max = $rec->out_value;
                    $max_time = $rec->data_time;
                } else {
                    if( $min > $rec->out_value ) {
                        $min = $rec->out_value;
                        $min_time = $rec->data_time;     
                    }
                    if( $max < $rec->out_value ) {
                        $max = $rec->out_value;
                        $max_time = $rec->data_time;
                    }
                }
            } else if( $sensor->device_class == 3 ) {
                // ma se pocitat sumarizace hodnot
                $sum += $rec->out_value;
            }

            if( $rec->status==0 ) {
                // novy zaznam, musi byt upraven
                $this->datasource->updateMeasure( $rec->id );
                $this->processedRecords++;
            }
        }

        // spocten min,max -> udelame si stred (to se tyka jen hodinovych zaznamu, u dennich se pocita jinak!)
        if( $sensor->device_class == 1 ) {
            $avg = ($min+$max)/2;
        }

        // pokud se nejedna o class 2, kde se nepocitaji sumarizace
        if( $sensor->device_class != 2 ) {
            // vsechny zaznamy zpracovany, je treba vytvorit sumarni zaznam
            $this->datasource->createSummary( $sensorId, $date, $hour, 
                                                $min, $min_time,
                                                $max, $max_time,
                                                $avg, $sum, 1, 0
                                            );
        }
    }


    /**
     * Zpracovava zdrojova data a pocita z nich hodinove sumarizace
     */
    private function processMeasures( $logger )
    {
        $records = $this->datasource->getRecordsForProcessing( $this->batchSize );

        $currentSensor = FALSE;
        $currentDate = FALSE;
        $currentHour = FALSE;

        foreach ($records as $rec) {
            
            //D/ Logger::log( self::NAME, Logger::DEBUG,  $rec );
            if( $this->shouldExit() ) {
                break;
            }
            
            $date = $rec->data_time->format('Y-m-d');
            $hour = $rec->data_time->format('H');
            if( $currentSensor!=$rec->sensor_id || $currentDate!=$date || $currentHour!=$hour )
            {
                $currentSensor=$rec->sensor_id;
                $currentDate=$date; 
                $currentHour=$hour;

                // zpracujeme danou hodinu a dany senzor 
                $this->processSensorHour( $currentSensor, $currentDate, $currentHour, $logger );

                $this->processedBatches++;
            }
            // vsechny ostatni zaznamy ze stejne hodiny a senzoru v poli preskocime, protoze ty uz jsme zpracovali
        }

        $this->template->batches = $this->processedBatches;
        $this->template->records = $this->processedRecords;
        $this->template->time = time() - $this->startTime;

        $logger->write(  Logger::INFO,  "Measures: done. {$this->processedBatches} batches, {$this->processedRecords} records in {$this->template->time} sec" );
    }


    /**
     * projde vsechny sumarizace za dany den a 
     * - pro zaznamy, kde neni status=1:
     *      - nastavi status=1
     * - spocte  prumery a ulozi je do tabulky
     */
    private function processSensorSummary( $sensorId, $date, $logger )
    {
        $logger->write(  Logger::DEBUG,  "Summary for sensor=$sensorId; $date" );

        $min = NULL;
        $min_time = NULL;
        $max = NULL;
        $max_time = NULL;
        $avg=NULL;
        $count = 0;
        $sum = 0;

        $val0 = NULL;
        $val6 = NULL;
        $val12 = NULL;
        $val18 = NULL;


        $sensor = $this->datasource->getSensor($sensorId);
        $rows = $this->datasource->getSumsForSensorDay( $sensorId, $date );
        foreach ($rows as $rec)
        {
            //D/ Logger::log( self::NAME, Logger::DEBUG,  (array)$rec );

            $count++;

            //  id	sensor_id	sum_type	rec_date	rec_hour	min_val	min_time	max_val	max_time	avg_val	sum_val	status
            if( $sensor->device_class == 1 ) {
                // maji se pocitat prumery hodnot
                if( $min===NULL ) {
                    // prvni zaznam
                    $min = $rec->min_val;
                    $min_time = $rec->min_time;     
                    $max = $rec->max_val;
                    $max_time = $rec->max_time;
                } else {
                    if( $min > $rec->min_val ) {
                        $min = $rec->min_val;
                        $min_time = $rec->min_time;     
                    }
                    if( $max < $rec->max_val ) {
                        $max = $rec->max_val;
                        $max_time = $rec->max_time;
                    }
                }

                if( $rec->rec_hour == 0 ) {
                    $val0 = $rec->avg_val;
                } else if( $rec->rec_hour == 6 ) {
                    $val6 = $rec->avg_val;
                } else if( $rec->rec_hour == 12 ) {
                    $val12 = $rec->avg_val;
                } else if( $rec->rec_hour == 18 ) {
                    $val18 = $rec->avg_val;
                }

            } else if( $sensor->device_class == 3 ) {
                // ma se pocitat sumarizace hodnot
                $sum += $rec->sum_val;
            }

            if( $rec->status==0 ) {
                // novy zaznam, musi byt upraven
                $this->datasource->updateSumdata( $rec->id );
                $this->processedRecords++;
            }
        }

        // spocist prumer, pokud mame data
        if( $sensor->device_class == 1 ) {
            if( $val0!==NULL && $val6!==NULL && $val12!==NULL && $val18!==NULL ) {
                $avg = ($val0+$val6+$val12+$val18) / 4;
            }
        }

        // vsechny zaznamy zpracovany, je treba vytvorit sumarni zaznam
        $this->datasource->createSummary( $sensorId, $date, -1, 
                                            $min, $min_time,
                                            $max, $max_time,
                                            $avg, $sum, 2, $count
                                        );

        if( $sensor->device_class == 3 ) {
            // pro impulzni senzory da celkovou denni sumu do sensor['last_out_value']
            $this->datasource->updateSensorValue( $sensorId, $sum );
        }
    }

    /**
     * Zpracovava hodinove sumarizace a pocita z nich denni sumy
     */
    private function processSumdata( $logger )
    {
        $this->processedBatches = 0;
        $this->processedRecords = 0;

        $records = $this->datasource->getSumsForProcessing( $this->batchSize );

        $currentSensor = FALSE;
        $currentDate = FALSE;

        foreach ($records as $rec) {
            
            //D/ Logger::log( self::NAME, Logger::DEBUG, (array)$rec );
            if( $this->shouldExit() ) {
                break;
            }
            
            $date = $rec->rec_date->format('Y-m-d');
            if( $currentSensor!=$rec->sensor_id || $currentDate!=$date  )
            {
                $currentSensor=$rec->sensor_id;
                $currentDate=$date; 

                // zpracujeme danou hodinu a dany senzor 
                $this->processSensorSummary( $currentSensor, $currentDate, $logger );

                $this->processedBatches++;
            }
            // vsechny ostatni zaznamy ze stejne hodiny a senzoru v poli preskocime, protoze ty uz jsme zpracovali
        }

        $this->template->batches = $this->processedBatches;
        $this->template->records = $this->processedRecords;
        $this->template->time = time() - $this->startTime;

        $logger->write( Logger::INFO,  "Summary: done. {$this->processedBatches} batches, {$this->processedRecords} records in {$this->template->time} sec" );
    }

    const RESIZE_X = 150;
    const RESIZE_Y = 150;
    const COLOR_THRESHOLD1 = 70;
    const COLOR_THRESHOLD2 = 150;
    const COUNT_THRESHOLD = 6;

    /**
     * 0 = black
     * 1 = non-black
     * 2 = black+lamp
     */ 
    private function testImage(  $filename, $logger )
    {
        $logger->write( Logger::DEBUG , "  $filename" );
        $count = 0;

        try {

            $image = Image::fromFile($filename);
            $image->resize(self::RESIZE_X, self::RESIZE_Y, Image::STRETCH);

            for( $x = 0; $x<self::RESIZE_X; $x++ ) {
                for( $y = 0; $y<self::RESIZE_Y; $y++ )
                {
                    $rgb = $image->colorAt($x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    if( 
                        ($r>self::COLOR_THRESHOLD1 || $g>self::COLOR_THRESHOLD1 || $b>self::COLOR_THRESHOLD1 ) 
                        &&
                        ( ($r+$g+$b) > self::COLOR_THRESHOLD2 )
                    ) {
                        $count++;
                        if( $count > self::COUNT_THRESHOLD ) {
                            $logger->write( Logger::DEBUG , "    at $x,$y : $r,$g,$b" );
                            return 1;
                        }
                    }
                }
            }

            if( $count==0 ) {
                $logger->write(  Logger::DEBUG , "    black" );
                return 0;
            } else {
                $logger->write(  Logger::DEBUG , "    black+lamp {$count} px" );
                return 2;
            }

        } catch (\Nette\Utils\ImageException $e) {
            $logger->write(  Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            return 1;
        }

    }


    /**
     * Zpracovava obrazky.
     * Pokud je description='camera', udela kontrolu, zda fotka neobsahuje jen cernou a oznaci ji tak.
     */
    private function processImages( $logger )
    {
        $ctImages = 0;
        $ctBlack = 0;

        $images = $this->datasource->getImagesForProcessing();
        foreach( $images as $image ) {

            if( $this->shouldExit() ) {
                break;
            }

            $logger->write(  Logger::DEBUG , "img {$image['id']} {$image['description']}" );

            if( $image['description']=='camera' ) {
                $file = FileSystem::normalizePath( __DIR__ . "/../../data/" . $image['filename'] );
                $out = $this->testImage( $file, $logger );
                $ctImages++;
                if( $out==0 ) {
                    $this->datasource->updateImageProperties( $image['id'], $image['description'] . ' BLACK');
                    $ctBlack++;
                } else if ( $out==1 )  {
                    $this->datasource->updateImageStatus( $image['id'], 2 );
                } else {
                    $this->datasource->updateImageProperties( $image['id'], $image['description'] . ' BLACK LAMP');
                    $ctBlack++;
                }
            } else {
                $this->datasource->updateImageStatus( $image['id'], 2 );
            }
        }

        $logger->write(  Logger::INFO,  "Images: done. $ctImages images, $ctBlack black." );
    }


    private function processPrelogin( $logger )
    {
        $limit = (new DateTime())->modify('-3 min');
        $rows = $this->datasource->getOldPrelogins( $limit );
        foreach ($rows as $row)
        {
            $logger->write( Logger::INFO, "Unused prelogin for dev {$row['device_id']} from IP {$row['remote_ip']}" );
            $this->datasource->markDeviceLoginProblem( $row['device_id'], $row['started'] );
            $this->datasource->deletePrelogin( $row['id'] );
        }
    }


    private function checkIp()
    {
        $remoteIp = $this->getHttpRequest()->getRemoteAddress(); 
        foreach( $this->config->cronAllowedIPs as $ip ) {
            if( strcmp( $ip, $remoteIp) == 0 ) {
                $this->template->ip_not_allowed=false;
                return true;
            }
        }
        Logger::log( self::NAME,  Logger::WARNING,  "Crontask fired from {$remoteIp}, not allowed." );
        $this->template->ip_not_allowed=true;
        return false;
    }

    

    /**
     * Task spousteny kazdou minutu; bezi maximalne minutu.
     * 
     * Provadi akce:
     * - zkontroluje stav senzoru a nafrontuje pozadavky na notifikacni maily:
     *      - prekroceni min/max limitu
     *      - neprichazejici data
     * - odesle notifikacni maily, pokud nejake jsou
     * - smaze stare zaznamy v 'prelogin' a pokud jsou, aktualizuje odpovidajici zaznamy v 'devices'
     * - zpracuje 'measures' 
     *      - vygeneruje z novych zaznamu hodinova 'sumdata' 
     *      - vygeneruje ze zmenenych hodinovych 'sumdata' denni 'sumdata'
     * - projde nove obrazky (bloby s typem 'jpg' a nazvem 'camera' a otaguje ty, co jsou cerne)
     */
    public function renderDefault()
    {
        if( ! $this->checkIp() ) return;

        try {

            $logger = new Logger( "cron" );

            $totalEnde = time() + $this->maxRunTime2  + $this->maxRunTime1;
            $this->startTime = time();
            $this->endTime = time() + $this->maxRunTime1;
            
            $this->checkSensors( $logger );
            $this->sendNotificationMails( $logger );
            $this->processPrelogin( $logger );

            $this->processMeasures( $logger );

            $this->startTime = time();
            $this->endTime = time() + $this->maxRunTime2;
            $this->processSumdata( $logger );

            // nechame na obrazky zbytek do celkoveho maxima delky behu
            $this->endTime = time() + $this->maxRunTime3;
            if( $this->endTime >  $totalEnde ) {
                $this->endTime = $totalEnde;
            }
            $this->processImages( $logger );
        } catch (\Exception $e) {
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
        }
    }






    private function deleteData( $logger )
    {
        $users = $this->datasource->getAllUserSettings();
        foreach( $users as $user )
        {
            //  id, username, measures_retention, sumdata_retention, blob_retention
            $logger->write( Logger::INFO, "#{$user['id']} {$user['username']} measures:{$user['measures_retention']} sumdata:{$user['sumdata_retention']} blobs:{$user['blob_retention']}" );
            // per-uzivatel
            $sensors = $this->datasource->getSensorIdsForUser( $user['id'] );
            if( count($sensors) > 0 ) {
                // smazat measures
                if( $user['measures_retention'] > 0 ) {
                    $purgeDate = new DateTime();
                    $retention = $user['measures_retention'] + 15;
                    $purgeDate->modify( "- {$retention} day" );
                    $ct = $this->datasource->deleteMeasures( $sensors, $purgeDate );
                    $logger->write( Logger::INFO, "  measures older than {$purgeDate}: {$ct}" );
                }

                // smazat sumdata
                if( $user['sumdata_retention'] > 0 ) {
                    $purgeDate = new DateTime();
                    $retention = $user['sumdata_retention'] + 15;
                    $purgeDate->modify( "- {$retention} day" );
                    $ct = $this->datasource->deleteSumdata( $sensors, $purgeDate );
                    $logger->write( Logger::INFO, "  sumdata older than {$purgeDate}: {$ct}" );
                }
            }

            // mazat blobs + soubory
            if( $user['blob_retention'] > 0 ) {
                $purgeDate = new DateTime();
                $retention = $user['blob_retention'] + 1;
                $purgeDate->modify( "- {$retention} day" );
                $blobs = $this->datasource->getBlobsForUser( $user['id'], $purgeDate );
                foreach( $blobs as $blob ) {
                    $logger->write( Logger::INFO, "  blob {$blob['id']}; {$blob['data_time']}; {$blob['filename']}" );
                    $file = FileSystem::normalizePath( __DIR__ . "/../../data/" . $blob['filename'] );
                    $logger->write( Logger::INFO, "    {$file}" );
                    FileSystem::delete( $file );
                    if( 1 != $this->datasource->deleteBlob( $blob['id'] ) ) {
                        $logger->write( Logger::WARNING, '    nelze smazat z DB?' );
                    }
                }
            }
        }
    }

    private function deleteLogs( $logger )
    {
        $dir = FileSystem::normalizePath( __DIR__ . "/../../log/" );

        $logger->write( Logger::INFO, 'Deleting base logs older than ' . self::LOG_RETENTION_BASE . ' days:' );
        foreach (
            Finder::findFiles('*.txt','*.html')
                ->exclude('audit*')
                ->date('<', '- ' . self::LOG_RETENTION_BASE . ' days' ) 
                ->in($dir)
            as $key => $file )  
        {
            $logger->write( Logger::DEBUG, "  {$file->getPathname()}" );
            FileSystem::delete( $file->getPathname() );
        }

        $logger->write( Logger::INFO, 'Deleting audit logs older than ' . self::LOG_RETENTION_AUDIT . ' days:' );
        foreach (
            Finder::findFiles('audit*.txt')
                ->date('<', '- ' . self::LOG_RETENTION_AUDIT . ' days' ) 
                ->in($dir)
            as $key => $file )  
        {
            $logger->write( Logger::DEBUG, "  {$file->getPathname()}" );
            FileSystem::delete( $file->getPathname() );
        }

        $logger->write( Logger::INFO, 'Logs done.' );
    }


    /**
     * Task spousteny jednou denne; delka behu neomezena.
     * 
     * Denni tasky:
     * - smaze odeslane notifikace z tabulky notifications (starsi nez 14 dni)
     * - smaze stara data
     *      - measures
     *      - sumdata
     *      - bloby (db i soubory)
     * - smaze stare logy
     */
    public function renderDaily()
    {
        if( ! $this->checkIp() ) return;

        try {
        
            $logger = new Logger( "cron", "daily" );
            
            $this->datasource->deleteNotifications();
            $this->deleteData( $logger );
            $this->deleteLogs( $logger );

            $this->template->result = "OK";
            $logger->write( Logger::INFO, "Done."  );

        } catch (\Exception $e) {
            Logger::log( self::NAME, Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
        }

    }



    /**
     * Export novych zaznamu do sluzeb implementujicich rozhrani ExportPlugin
     */
    private function exportRecords( $logger, $timeLimit )
    {
        try {

            $ct = 0;

            $exporter = $this->context->getService('exportPlugin');
            while( true ) {
                $rows = $this->datasource->getExportData();
                if (count($rows) < 1) {
                    break;
                }
                foreach( $rows as $row ) {
                    $rc = $exporter->exportRecord( 
                        $row->id,		
                        $row->data_time,
                        $row->server_time,
                        $row->value,	
                        $row->sensor_id, 
                        $row->sensor_name,	
                        $row->device_id,
                        $row->device_name,	
                        $row->user_id                     
                    );
                    if( $rc == 0 ) {
                        $this->datasource->rowExported( $row->id );
                        $ct++;
                    } else {
                        $logger->write( Logger::DEBUG,  "#{$row->id} time={$row->data_time} value={$row->value} sensor={$row->sensor_id}={$row->sensor_name} device={$row->device_id}={$row->device_name} user={$row->user_id}" );                    
                        $logger->write( Logger::WARNING, "Chyba exportu #{$rc}."  );
                        $logger->write( Logger::INFO, "Stopping, {$ct} records done."  );
                        $this->template->result = "ERROR #{$rc}";
                        return;
                    }
                    if( time() > $timeLimit ) {
                        // prekrocena maximalni delka behu
                        break;
                    }
                }
                if( time() > $timeLimit ) {
                    // prekrocena maximalni delka behu
                    break;
                }
            }
            
            $logger->write( Logger::INFO, "Done, {$ct} records."  );

            $this->template->result = "OK";

        } catch( \Nette\DI\MissingServiceException $e ) {
            $logger->write( Logger::DEBUG, "No export plugin found." );
        } catch (\Exception $e) {
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
        }
    }


    /**
     * Export novych obrazku do sluzeb implementujicich rozhrani ImagePlugin
     */
    private function exportImages( $logger, $timeLimit )
    {
        try {
            $ct = 0;

            $imagePlugins = $this->context->findByType( 'App\Plugins\ImagePlugin' );

            while( true ) {
                $images = $this->datasource->getImagesForExport();
                if (count($images) < 1) {
                    break;
                }

                foreach( $images as $image ) {
        
                    $logger->write(  Logger::DEBUG , "img {$image['id']}" );
                    $file = FileSystem::normalizePath( __DIR__ . "/../../data/" . $image['filename'] );

                    foreach ($imagePlugins as $name ) {
                        $rc = $this->context->getService($name)->exportImage(
                            $file,    
                            $image['id'],
                            $image['device_id'],
                            $image['data_time'],	
                            $image['description'],
                            $image['filesize']
                        );
                    }

                    if( $rc == 0 ) {
                        $this->datasource->updateImageStatus( $image['id'], 3 );
                        $ct++;
                    } else {
                        $logger->write( Logger::DEBUG,  "#{$image->id} " );                    
                        $logger->write( Logger::WARNING, "Chyba exportu #{$rc}."  );
                        $logger->write( Logger::INFO, "Stopping, {$ct} records done."  );
                        $this->template->result = "ERROR #{$rc}";
                        return;
                    }

                    if( time() > $timeLimit ) {
                        // prekrocena maximalni delka behu
                        break;
                    }
                }

                if( time() > $timeLimit ) {
                    // prekrocena maximalni delka behu
                    break;
                }
            }

            $logger->write( Logger::INFO, "Done, {$ct} images."  );
            $this->template->result = "OK";

        } catch( \Nette\DI\MissingServiceException $e ) {
            $logger->write( Logger::DEBUG, "No image plugin found. " );
        } catch (\Exception $e) {
            $logger->write( Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
        }
    }


    public $maxRunTimeExport = 55;

    /**
     * Export novych zaznamu do externiho systemu.
     * Viz popis https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/
     */
    public function renderExport()
    {
        if( ! $this->checkIp() ) return;

        $timeLimit = time() + $this->maxRunTimeExport;
        
        $logger = new Logger( "cron" );
        $logger->setContext("exp");

        $this->template->result = "ERROR";

        $this->exportRecords( $logger, $timeLimit-10 );
        $this->exportImages( $logger, $timeLimit );
    }

}