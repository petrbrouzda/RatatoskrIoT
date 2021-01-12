<?php

declare(strict_types=1);

namespace App\Model;

use Nette;

/**
 * Definice pohledu
 */
class View
{
    use Nette\SmartObject;

    /*
     * Popis pohledu
     */
    public $name;
    public $appName;
    public $desc;

    /**
     * Povoluje porovnavani, tj. vyber alternativniho roku?
     */
    public $allowCompare;

    /**
     * Jednotlive polozky pohledu.
     * Pole objektu ViewItem.
     */
    public $items;

    /*
     * Dalsi vlastnosti pohledu potrebne v Inventory
     */
    public $token;	
    public $vorder;
    public $render;
    public $id;

    public function __construct(  $name, $desc, $allowCompare, $appName, $render )
    {
        $this->appName = $appName;
        $this->name = $name;
        $this->desc = $desc;
        $this->allowCompare = $allowCompare;
        $this->render = $render;
        $this->items = array();
    }
}



