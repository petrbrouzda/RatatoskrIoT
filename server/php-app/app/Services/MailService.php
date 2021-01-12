<?php

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;
use Tracy\Debugger;

use \App\Model\Device;
use \App\Model\Devices;
use \App\Model\View;
use \App\Model\ViewItem;

class MailService 
{
    use Nette\SmartObject;
    
    public $mailFrom;
    public $mailAdmin;

    /** @var Nette\Mail\Mailer */
    private $mailer;
    
	public function __construct(
            Nette\Mail\Mailer $mailer, 
            $mailFrom,
            $mailAdmin
            )
	{
        $this->mailFrom = $mailFrom;
        $this->mailAdmin = $mailAdmin;
        $this->mailer = $mailer;
    }

    public function sendMailAdmin( $subject, $text ): void
    {
        $this->sendMail(
            $this->mailAdmin,
            $subject,
            $text
        );
    }

    public function sendMail( $to, $subject, $text )
    {
        $mail = new Nette\Mail\Message;
        $mail->setFrom( $this->mailFrom )
            ->addTo($to)
            ->setSubject( "RatatoskrIot: {$subject}")
            ->setHtmlBody($text);
        $this->mailer->send($mail);
    }



}


