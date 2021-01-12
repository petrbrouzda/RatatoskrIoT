<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\Random;
use Nette\Utils\DateTime;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use Nette\Utils\Strings;
use Nette\Application\UI;
use Nette\Application\UI\Form;

use App\Services\Logger;
use \App\Services\InventoryDataSource;
use \App\Services\Config;

final class UserPresenter extends BaseAdminPresenter
{
    use Nette\SmartObject;

    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    /** @var \App\Services\Config */
    private $config;

    /** @var Nette\Security\Passwords */
    private $passwords;

    public function __construct(\App\Services\InventoryDataSource $datasource, 
                                \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->config = $config;
        $this->links = $config->links;
        $this->appName = $config->appName;
    }

    public function injectPasswords( Nette\Security\Passwords $passwords )
    {
        $this->passwords = $passwords;
    }


    /*
    id	username	phash	role	email	prefix	state_id	bad_pwds_count	locked_out_until	
    measures_retention	sumdata_retention	blob_retention	self_enroll	self_enroll_code	self_enroll_error_count
        cur_login_time	cur_login_ip	cur_login_browser	prev_login_time	prev_login_ip	prev_login_browser
        	last_error_time	last_error_ip	last_error_browser	monitoring_token	desc
    */
    public function renderList(): void
    {
        $this->checkUserRole( 'admin' );
        $this->populateTemplate( 6 );
        $this->template->appName = $this->appName;
        $this->template->links = $this->links;
        $this->template->path = '';
        $this->template->users = $this->datasource->getUsers();
    }

    public function renderShow( $id ): void
    {
        $this->checkUserRole( 'admin' );
        $this->populateTemplate( 6 );
        $this->template->appName = $this->appName;
        $this->template->links = $this->links;
        $this->template->path = '../';
        $this->template->userData = $this->datasource->getUser( $id );
        $this->template->devices = $this->datasource->getDevicesUser( $id );
    }


    public function renderCreate()
    {
        $this->checkUserRole( 'admin' );
        $this->populateTemplate( 6 );
        $this->template->appName = $this->appName;
        $this->template->links = $this->links;
        $this->template->path = '';
    }

    public function actionEdit(int $id): void
    {
        $this->checkUserRole( 'admin' );
        $this->populateTemplate( 6 );
        $this->template->appName = $this->appName;
        $this->template->links = $this->links;
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getUser( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel {$id} nenalezen" );
            $this->error('Uživatel nenalezen');
        }
        $post = $post->toArray();
        $post['role_admin'] = strpos($post['role'], 'admin')!== false;
        $post['role_user'] = strpos($post['role'], 'user')!== false;
        $this->template->name = $post['username'];

        $this['userForm']->setDefaults($post);
    }


    protected function createComponentUserForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addText('username', 'Login name:')
            ->setRequired()
            ->setAttribute('size', 50)
            ;

        $form->addText('prefix', 'Prefix:')
            ->setRequired()
            ->setAttribute('size', 50)
            ;

        $form->addText('password', 'Heslo:')
            ->setOption('description', 'Pokud je vyplněno, bude nastaveno jako nové heslo; pokud se nechá prázdné, heslo se nemění.'  )
            ->setAttribute('size', 50);

        $form->addText('email', 'E-mail:')
            ->setOption('description', 'Adresa pro mailové notifikace.'  )
            ->setAttribute('size', 50);

        $states = [
            '1' => 'čeká na zadání kódu z e-mailu',
            '10' => 'aktivní',
            '90' => 'zakázán administrátorem',
            '91' => 'dočasně uzamčen',
        ];

        $form->addSelect('state_id', 'Stav účtu:', $states)
            ->setDefaultValue('10')
            ->setPrompt('- Zvolte stav -')
            ->setRequired();

        $form->addCheckbox('role_admin', 'Administrátor')
            ->setOption('description', 'Má právo spravovat uživatele.'  );
            
        $form->addCheckbox('role_user', 'Uživatel');

        $form->addText('measures_retention', 'Retence - přímá data:')
            ->setDefaultValue('60')
            ->setOption('description', 'Ve dnech. 0 = neomezeno.'  )
            ->addRule(Form::INTEGER, 'Musí být číslo')
            ->setRequired()
            ->setAttribute('size', 50);

        $form->addText('sumdata_retention', 'Retence - sumární data:')
            ->setDefaultValue('366')
            ->setOption('description', 'Ve dnech. 0 = neomezeno.'  )
            ->addRule(Form::INTEGER, 'Musí být číslo')
            ->setRequired()
            ->setAttribute('size', 50);

        $form->addText('blob_retention', 'Retence - soubory:')
            ->setDefaultValue('8')
            ->setOption('description', 'Ve dnech. 0 = neomezeno.'  )
            ->addRule(Form::INTEGER, 'Musí být číslo')
            ->setRequired()
            ->setAttribute('size', 50);

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'userFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }


    public function userFormSucceeded(Form $form, array $values): void
    {
        $this->checkUserRole( 'admin' );

        $roles = array();
        if( $values['role_admin']==1 ) {
            $roles[] = "admin";
        }
        if( $values['role_user']==1 ) {
            $roles[] = "user";
        }
        $values['role'] = implode ( ',', $roles );

        if( strlen($values['password']) )  {
            $values['phash'] = $this->passwords->hash($values['password']);
        }
        unset($values['password']);
        unset($values['role_user']);
        unset($values['role_admin']);

        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $user = $this->datasource->getUser( $id );
            if (!$user) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel {$id} nenalezen" );
                $this->error('Uživatel nenalezen');
            }
            $user->update( $values );
        } else {
            // zalozeni
            $this->datasource->createUser( $values );
        }

        $this->flashMessage("Změny provedeny.", 'success');
        if( $id ) {
            $this->redirect("User:show", $id );
        } else {
            $this->redirect("User:list" );
        }
    }



    public function actionDelete( int $id ): void
    {
        $this->checkUserRole( 'admin' );
        $this->populateTemplate( 6 );
        $this->template->appName = $this->appName;
        $this->template->links = $this->links;
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getUser( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel {$id} nenalezen" );
            $this->error('Uživatel nenalezen');
        }

        $this->template->userData = $post;
        $this->template->devices = $this->datasource->getDevicesUser( $id );
        
    }

    protected function createComponentDeleteForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addCheckbox('potvrdit', 'Potvrdit smazání')
            ->setOption('description', 'Zaškrtnutím potvrďte, že skutečně chcete smazat uživatele a všechna jeho zařízení, data a grafy.'  )
            ->setRequired();

        $form->addSubmit('delete', 'Smazat')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'deleteFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function deleteFormSucceeded(Form $form, array $values): void
    {
        $this->checkUserRole( 'admin' );
        $id = $this->getParameter('id');

        if( $id ) {
            // overeni prav
            $post = $this->datasource->getUser( $id );
            if (!$post) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel {$id} nenalezen" );
                $this->error('Uživatel nenalezen');
            }
            Logger::log( 'audit', Logger::INFO , "[{$this->getHttpRequest()->getRemoteAddress()}, {$this->getUser()->getIdentity()->username}] Mazu uzivatele {$id}" ); 

            $this->datasource->deleteViewsForUser( $id );            
            $devices = $this->datasource->getDevicesUser( $id );
            foreach( $devices->devices as $device ) {
                
                $this->datasource->deleteDevice( $device->attrs['id'] );
            }
            $this->datasource->deleteUser( $id );
        } 

        $this->flashMessage("Uživatel smazán.", 'success');
        $this->redirect('User:list' );
    }

   
    
}


