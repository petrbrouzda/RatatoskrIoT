<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;
use Nette\Utils\Random;
use Tracy\Debugger;

use \App\Model\Device;
use \App\Model\Devices;
use \App\Model\View;
use \App\Model\ViewItem;


class EnrollDataSource 
{
    use Nette\SmartObject;
    
    private $database;
    
	public function __construct(
            Nette\Database\Context $database
            )
	{
        $this->database = $database;
	}

    public function updateUser( $email, $status, $errCount )
    {
        $this->database->query('UPDATE rausers SET ', [ 
            'state_id' => $status,
            'self_enroll_error_count' => $errCount
        ] , 'WHERE username = ?   and   state_id = 1', $email);
    }

    public function deleteUser( $email )
    {
        $this->database->query('DELETE from rausers WHERE username = ? and state_id = 1', $email);
    }
    
    public function createUser( $values, $hash, $prefix, $code )
    {
        $user = $this->database->fetch(  '
            select * from rausers
            where username = ?
        ', $values['email'] );

        if( $user!=NULL ) {
            return -1;
        }

        $this->database->query('INSERT INTO rausers ', [
            'username' => $values['email'],
            'phash' => $hash,
            'role' => 'user',
            'email' => $values['email'],
            'prefix' => $prefix,
            'state_id' => 1,
            'self_enroll' => 1,
            'self_enroll_code' => $code,
            'measures_retention' => 90,
            'sumdata_retention' => 366,
            'blob_retention' => 7,
            'monitoring_token' => Random::generate(40)
        ]);

        return 0;
    }

    public function getPrefix( $prefix )
    {
        return $this->database->fetch(  '
            select * from rausers
            where prefix = ?
        ', $prefix );
    }
    
    public function getUserByUsername( $email )
    {
        return $this->database->fetch(  '
            select * from rausers
            where username = ?
        ', $email );
    }
}


