<?php

declare(strict_types=1);

namespace App\Services;

use Nette;

class Config
{
    use Nette\SmartObject;

    /**
     * Seznam povolenych IP adres, ze kterych je mo6n0 spustit cron tasky.
     */
    public $cronAllowedIPs;

    /**
     * Seznam linku v paticce stranky
     */
    public $links;


    /**
     * Jmeno aplikace
     */
    public $appName;


    public $fontRegular;
    public $fontBold; 
    public $dataRetentionDays;
    public $minYear;

    private $masterPassword;
    
	public function __construct(
            $ips,
            $masterPassword,
            $links,
            $appName, 
            $fontRegular, 
            $fontBold, 
            $dataRetentionDays, 
            $minYear
            )
	{
        $this->cronAllowedIPs = $ips;
        $this->masterPassword = $masterPassword;
        $this->links = $links;
        $this->appName = $appName;
        $this->fontRegular = $fontRegular;
        $this->fontBold = $fontBold;
        $this->dataRetentionDays = $dataRetentionDays;
        $this->minYear = $minYear;
	}


    private function getMasterKey()
    {
        return hash("sha256", $this->masterPassword . 'RatatoskrIoT', true);
    }

    public function encrypt( $data, $fieldName )
    {
        $aesIV = substr( hash("sha256", $fieldName, true), 0, 16 );
        $aesKey = $this->getMasterKey();
        $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA , $aesIV );   
        if( $encrypted === FALSE ) {
            Logger::log( 'webapp', Logger::ERROR, "nelze zasifrovat" );
        }
        return bin2hex($encrypted);
    }

    public function decrypt( $data, $fieldName )
    {
        $aesIV = substr( hash("sha256", $fieldName, true), 0, 16 );
        $aesKey = $this->getMasterKey();
        
        $decrypted = openssl_decrypt( hex2bin($data), 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $aesIV );
        if( $decrypted == FALSE ) {
            Logger::log( 'webapp', Logger::ERROR, "nelze desifrovat" );
            return "";
        }
        return $decrypted;
    }
}




