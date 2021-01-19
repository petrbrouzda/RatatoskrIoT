<?php

declare(strict_types=1);

namespace App\Plugins;

use Nette;
use Tracy\Debugger;
use App\Services\Logger;

/**
 * Exportni plugin dostava k replikaci do jineho systemu kazdou prijatou hodnotu ze zarizeni se zpozdenim cca 1-2 minuty.
 * Toto je ukazka implementace - nic nedela, jen zaloguje do souboru export.<datum>.txt
 * Informace: https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/
 */
class DemoExportPlugin implements \App\Plugins\ExportPlugin
{
    use Nette\SmartObject;

    /**
     * Konstruktor - navazani spojeni atd.
     */
    public function __construct()
    {
    }

    /**
     * Je predavan zaznam:
     *      id		        -- ID zaznamu
     *      data_time	    -- cas zaznamenani dat na zarizeni
     *      server_time	    -- cas prijmu dat na server
     *      value	
     *      sensor_id 
     *      sensor_name	
     *      device_id
     *      device_name	
     *      user_id 
     * 
     * Funkce musi vratit bud 0 (OK) nebo <>0 (chyba).
     * 
     * V pripade chyby se proces ukonci a volani se bude opakovat priste.
     */
    public function exportRecord( 
        $id,		
        $data_time,
        $server_time,
        $value,	
        $sensor_id, 
        $sensor_name,	
        $device_id,
        $device_name,	
        $user_id                     
    )
    {
        /*
        Ukazka vznikleho zapisu v export.<datum>.txt 
        11:51:12 -d- exportuji: #3290471 time=2020-06-28 09:36:46 value=92 sensor=7=ext_humidity device=3=pb:netatmo user=1
        */
        Logger::log( 'export',  Logger::DEBUG,  "exportuji: #{$id} time={$data_time} value={$value} sensor={$sensor_id}={$sensor_name} device={$device_id}={$device_name} user={$user_id}" );
        return 0;
    }
}