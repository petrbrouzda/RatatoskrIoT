<?php

declare(strict_types=1);

namespace App\Plugins;

use Nette;
use Tracy\Debugger;
use App\Services\Logger;
use Nette\Utils\FileSystem;
use Nette\Utils\DateTime;
use Nette\Utils\Strings;

/**
 * Exportni plugin dostava k replikaci do jineho systemu kazdou prijatou hodnotu ze zarizeni se zpozdenim cca 1-2 minuty.
 * Toto je ukazka implementace - nic nedela, jen zaloguje do souboru export.<datum>.txt
 * Informace: https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/
 */
class ImageTimelapse implements \App\Plugins\ImagePlugin
{
    use Nette\SmartObject;

    private $logger;

    private $deviceId;
    private $timeFrom;
    private $timeTo;
    private $minimalIntervalMin;

    private $path;

    private $lastTimestamp;

    /**
     * Konstruktor 
     */
    public function __construct( $name, $deviceId, $timeFrom, $timeTo, $minimalIntervalMin )
    {
        $this->deviceId = $deviceId;
        $this->timeFrom = $timeFrom;
        $this->timeTo = $timeTo;
        $this->minimalIntervalMin = $minimalIntervalMin;

        $this->logger = new Logger( "timelapse" );
        $this->logger->setContext( "${name}" );
        $this->logger->write( Logger::DEBUG, "startuji ${name}, device ${deviceId}, time ${timeFrom}-${timeTo} interval ${minimalIntervalMin}" );

        $this->path = FileSystem::normalizePath( __DIR__ . "/../../data/_timelapse/${name}" );
        if( ! file_exists( $this->path )) {
            if( ! mkdir( $this->path, 0700, TRUE ) ) {
                $logger->write( Logger::ERROR, "Chyba pri vytvareni adresare [${deviceDataDir}]"); 
                throw new \Exception("Dir problem 1.");
            }
        }

        // najdeme nejvyssi timestamp souboru v adresari
        $this->lastTimestamp = 0;
        $files1 = scandir($this->path);
        foreach( $files1 as $file ) {
            // 01234567890123456789
            // YYYY-MM-DD_hh-mm-ss_
            if( substr($file, -4) == '.jpg' ) {
                $time = DateTime::createFromFormat('Y-m-d_H-i-s', substr( $file, 0, 19) );
                if( $time->getTimestamp() > $this->lastTimestamp ) {
                    $this->lastTimestamp = $time->getTimestamp();
                }
            }
        }
        $this->logger->write( Logger::DEBUG, 'Nejvyssi znamy timestamp je ' . date( 'Y-m-d_H-i-s', $this->lastTimestamp ) );
    }

    /**
     * Je predavan zaznam:
        $fileName,    
        $imageId,
        $deviceId,
        $dataTime,	
        $description,
        $filesize
     * 
     * Funkce musi vratit bud 0 (OK) nebo <>0 (chyba).
     * 
     * V pripade chyby se proces ukonci a volani se bude opakovat priste.
     * 
     * Vytvari soubory data/_timelapse/YYYY-MM-DD_hh-mm-ss_deviceId_description.jpg
     * 
     */
    public function exportImage( 
        $fileName,    
        $imageId,
        $deviceId,
        $dataTime,	
        $description,
        $filesize
    )
    {
        $this->logger->write( Logger::INFO,  "${imageId} D:${deviceId} TS:[${dataTime}] [${description}] F:[${fileName}]" );
        if( $deviceId != $this->deviceId ) {
            $this->logger->write( Logger::INFO, "  neni moje" );
            return 0;
        }
        $imgTime = $dataTime->format('H:i');
        if( $imgTime < $this->timeFrom || $imgTime > $this->timeTo ) {
            $this->logger->write( Logger::INFO, "  cas ${imgTime} je mimo casove okno" );
            return 0;
        }
        if( $dataTime->getTimestamp() < ($this->lastTimestamp + $this->minimalIntervalMin*60) ) {
            $this->logger->write( Logger::INFO, '  posledni timestamp je ' . date( 'Y-m-d_H-i-s', $this->lastTimestamp ) . ' a tohle je moc blizko' );
            return 0;
        }
        $newFileName = $this->path . '/' . $dataTime->format('Y-m-d_H-i-s') . "_${deviceId}_" . Strings::webalize($description) . '.jpg';
        $this->logger->write( Logger::INFO, "  -> ${newFileName}" );
        FileSystem::copy( $fileName, $newFileName, true);
        $this->lastTimestamp = $dataTime->getTimestamp();
        $this->logger->write( Logger::DEBUG, "  OK" );
        return 0;
    }
}