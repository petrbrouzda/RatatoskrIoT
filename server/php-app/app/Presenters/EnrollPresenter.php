<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Nette\Application\UI\Form;
use Nette\Security\Passwords;
use Nette\Mail\Mailer;
use Nette\Mail\Message; 
use Nette\Http\Url;

use App\Services\Logger;

class EnrollPresenter extends Nette\Application\UI\Presenter
{
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    /** @var Nette\Security\Passwords */
    private $passwords;

    private $mailService;

    /** @persistent */
    public $email = "";

    public function __construct( \App\Services\EnrollDataSource $datasource, \App\Services\MailService $mailsv, Nette\Security\Passwords $passwords  )
	{
        $this->datasource = $datasource;
        $this->passwords = $passwords;
        $this->mailService = $mailsv;
    }

    protected function createComponentEnrollForm(): Form
	{
		$form = new Form;
		$form->addEmail('email', 'E-mail adresa:')
            ->setOption('description', 'E-mail adresa bude použita jako přihlašovací jméno. Do e-mailu vám bude zaslán potvrzovací kód.')
            ->setRequired();

		$form->addPassword('password', 'Heslo:')
            ->setOption('description', 'Prosím vyplňte své heslo.')
            ->setRequired();

        $form->addPassword('password2', 'Opakujte heslo:')
            ->setOption('description', 'Zadejte heslo ještě jednou.')
	        ->addRule($form::EQUAL, 'Heslo musí být zadáno dvakrát stejně!', $form['password'])
            ->setRequired();

        $form->addSubmit('send', 'Založit účet')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'enrollFormSucceeded'];
        
		return $form;
    }

    public function enrollFormSucceeded(Form $form, array $values ): void
    {
        $hash = $this->passwords->hash($values['password']);

        $arr = preg_split( '/[_.@\\-\\+]/', $values['email'], 0, PREG_SPLIT_NO_EMPTY );
        $prefixBase = '';
        $prefix = '';
        foreach( $arr as $str ) {
            $prefixBase .= substr( $str, 0, 1 );
            if( strlen($prefixBase)==2 ) break;
        }
        for( $i=0; ; $i++ ) {
            $prefix = $prefixBase . ($i>0 ? $i : '' );
            if( ! $this->datasource->getPrefix( $prefix ) ) {
                break;
            }
        }
        $prefix = strtolower( $prefix );

        $code = Nette\Utils\Random::generate(4, '0-9');

        if( -1 == $this->datasource->createUser( $values, $hash, $prefix, $code ) ) {
            $this->flashMessage('Zadanou e-mail adresu již používá jiný uživatel. Zvolte prosím jinou.');
            $this->redirect("Enroll:step1" );
        }

        Logger::log( 'audit', Logger::INFO , 
            "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: zalozen {$values['email']} prefix=[{$prefix}] [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 
        
        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $mailUrl = "{$url->getAbsoluteUrl()}enroll/step2?email={$values['email']}&code={$code}";

        $this->mailService->sendMail( 
            $values['email'],
            'Registrace',
            "<p>Dobrý den,</p>
            <p>Váš aktivační kód je: <b>{$code}</b><p>
            <p><a href=\"{$mailUrl}\">Kliknutím provedete aktivaci.</a></p>
            ");

        $this->flashMessage("Mail poslán na {$values['email']}");
        $this->redirect("Enroll:step2", $values['email'] );
    }
    


    
    public function actionStep2( $email, $code=NULL )
    {
        $this->email = $email;

        // pokud jsou vyplneny oba parametry, rovnou na overovani
        if( $code ) {
            $this->validujKod( $email, $code );
        }
        $this->template->email = $email;
    }


    protected function createComponentStep2Form(): Form
	{
		$form = new Form;
		$form->addText('code', 'Kód z e-mailu:')
            ->setOption('description', 'zadejte kód z e-mailu')
            ->setRequired();

        $form->addSubmit('send', 'Potvrdit účet')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'step2FormSucceeded'];
        
		return $form;
    }

    public function step2FormSucceeded(Form $form, array $values ): void
    {
        $this->validujKod( $this->email, $values['code'] );
    }

    private function validujKod( $email, $code ) {
        
        $userdata = $this->datasource->getUserByUsername( $email );

        if( !$userdata ) {
            Logger::log( 'audit', Logger::ERROR , 
                "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: nenalezen $email [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 

            $this->flashMessage("Uživatel {$email} neexistuje.");
            $this->redirect("Enroll:step2" );
        }

        if( $userdata['state_id']!=1) {
            Logger::log( 'audit', Logger::ERROR , 
                "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: spatny stav {$userdata['state_id']} pro $email [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 

            $this->flashMessage("Aktivační kód pro uživatele {$email} byl zadán, můžete se přihlásit.");
            $this->redirect("Sign:in", $email );
        }

        if( $userdata['self_enroll_code']!==$code) {

            if( $userdata['self_enroll_error_count']==3 ) { 
                // smazat ucet
                Logger::log( 'audit', Logger::ERROR , 
                    "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: MAZU UCET, spatny kod pro $email [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 

                $this->datasource->deleteUser( $email );
                $this->flashMessage("Opakovaně špatný kód. Tento účet byl smazán.");
                $this->redirect("Sign:in" );
            } else {
                // navysit pocet chyb
                Logger::log( 'audit', Logger::ERROR , 
                    "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: spatny kod pro $email [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 

                $this->datasource->updateUser( $email, 1, 1+$userdata['self_enroll_error_count'] );
                $this->flashMessage("Špatný e-mail nebo kód.");
                $this->redirect("Enroll:step2", $email );
            }

        }
        // aktivovat
        Logger::log( 'audit', Logger::INFO , 
            "[{$this->getHttpRequest()->getRemoteAddress()}] Enroll: AKTIVACE $email [{$this->getHttpRequest()->getHeader('User-Agent')} / {$this->getHttpRequest()->getHeader('Accept-Language')}]" ); 

        $this->datasource->updateUser( $email, 10, 0 );
        $this->mailService->sendMailAdmin( 
            'Nový uživatel',
            "<p>Uživatel <b>{$email}</b> udělal self-enroll. </p>" );

        $this->flashMessage("Aktivace provedena, můžete se přihlásit.");
        $this->redirect("Sign:in", $email );
    }


}
