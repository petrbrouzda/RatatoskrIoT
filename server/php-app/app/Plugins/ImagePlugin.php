<?php

declare(strict_types=1);

namespace App\Plugins;

use Nette;

/**
 * Interface pro exportni pluginy. 
 * Exportni plugin dostava k replikaci do jineho systemu kazdou prijatou hodnotu ze zarizeni se zpozdenim cca 1-2 minuty.
 * Informace: https://pebrou.wordpress.com/2021/01/19/ratatoskriot-replikace-dat-do-jineho-systemu/
 */
interface ImagePlugin
{
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
     */
    public function exportImage( 
        $fileName,    
        $imageId,
        $deviceId,
        $dataTime,	
        $description,
        $filesize
    );
}
