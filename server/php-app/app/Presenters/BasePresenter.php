<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use App\Services\Logger;

class BasePresenter extends Nette\Application\UI\Presenter
{
    use Nette\SmartObject;

    public function checkUserRole( $reqRole ) 
    {
        if( !$this->getUser()->loggedIn ) {
            Logger::log( 'webapp', Logger::ERROR , 
                "[{$this->getHttpRequest()->getRemoteAddress()}] ACCESS: Uzivatel je neprihlaseny, jdeme na login." ); 

            if ($this->getUser()->logoutReason === Nette\Security\IUserStorage::INACTIVITY) {
				$this->flashMessage('Dlouho jste neudělal/a žádnou akci, z bezpečnostních důvodů došlo k odhlášení. Přihlašte se prosím znovu.');
			} else {
                $this->flashMessage('Pro využití této funkce se nejprve přihlašte.');
            }

            $response = $this->getHttpResponse();
            $response->setHeader('Cache-Control', 'no-cache');
            $response->setExpiration('1 sec'); 
    
            // https://pla.nette.org/cs/jak-po-odeslani-formulare-zobrazit-stejnou-stranku
            $this->redirect('Sign:in', ['backlink' => $this->storeRequest()] );
        }

        if( !$this->getUser()->isInRole($reqRole) ) {
            Logger::log( 'audit', Logger::ERROR , 
                "[{$this->getHttpRequest()->getRemoteAddress()}] ACCESS: Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pouzit funkci vyzadujici roli {$reqRole}" ); 
    
            $response = $this->getHttpResponse();
            $response->setHeader('Cache-Control', 'no-cache');
            $response->setExpiration('1 sec'); 
        
                $this->getUser()->logout();
            $this->flashMessage('Vaše úroveň oprávnění nestačí k použití této funkce!');
            $this->redirect('Sign:in');
        }
    }

    private function addMenuItem( $vals, $submenuAfterItem = FALSE, $submenu = NULL )
    {
        $this->template->menu[] = $vals;
        if( $vals['id']==$submenuAfterItem ) {
            foreach( $submenu as $item ) {
                $this->template->menu[] = $item;
            }
        }
    }

    public function populateMenu( $activeItem, $submenuAfterItem = FALSE, $submenu = NULL )
    {
        $this->template->menu = array();

        $this->addMenuItem( ['id' => '3', 'link' => 'inventory/user', 'name' => 'Můj účet'],
            $submenuAfterItem , $submenu );

        if( $this->getUser()->isInRole('admin') ) {
            $this->addMenuItem( ['id' => '6', 'link' => 'user/list', 'name' => 'Uživatelé'],
            $submenuAfterItem , $submenu );
        }

        $this->addMenuItem(  ['id' => '1', 'link' => 'inventory/home', 'name' => 'Zařízení'],
            $submenuAfterItem , $submenu );
        $this->addMenuItem( ['id' => '2', 'link' => 'view/views', 'name' => 'Grafy'],
            $submenuAfterItem , $submenu );
        $this->addMenuItem( ['id' => '5', 'link' => 'inventory/units', 'name' => 'Kódy jednotek'],
            $submenuAfterItem , $submenu );  

        $this->template->menuId = $activeItem ;

        $response = $this->getHttpResponse();
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setExpiration('1 sec'); 
    }

}