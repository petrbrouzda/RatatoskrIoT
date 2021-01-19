<?php

declare(strict_types=1);

namespace App\Plugins;

use Nette;

/**
 * Interface pro exportni pluginy. 
 * Exportni plugin dostava k replikaci do jineho systemu kazdou prijatou hodnotu ze zarizeni se zpozdenim cca 1-2 minuty.
 * Informace: https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/
 */
interface ExportPlugin
{
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
    );
}
