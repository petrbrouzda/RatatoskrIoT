<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use \Nette\Utils\DateTime;


/**
 * Device session
 */
class SessionDevice 
{
    use Nette\SmartObject;
    
	public $sessionId;
    public $sessionKey;
    public $deviceId;

    /**
     * V checkSession se NEVYPLNUJE!
     */
    public $deviceName;
}



