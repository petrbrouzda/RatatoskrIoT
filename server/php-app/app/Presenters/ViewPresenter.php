<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Nette\Application\UI;
use Nette\Application\UI\Form;
use Nette\Http\Url;

use \App\Model\View;
use \App\Model\ViewItem;
use App\Services\Logger;

use \App\Services\InventoryDataSource;

final class ViewPresenter extends BaseAdminPresenter
{
    use Nette\SmartObject;

    /** @persistent */
	public $viewid = "";
    
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    public function __construct(\App\Services\InventoryDataSource $datasource, 
                                \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->links = $config->links;
        $this->appName = $config->appName;
    }


    
    private function doViewsHome( $detailed )
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 2 );
        $this->template->path = '';

        $this->datasource->getViews( $this->getUser()->id );
        $this->template->views = $this->datasource->views;
        $this->template->tokens = $this->datasource->tokens;
        $this->template->tokenView = $this->datasource->tokenView;
        $this->template->sensors = $this->datasource->getSensors( $this->getUser()->id );
    }

    public function renderViewsdetail(): void
    {
        $this->doViewsHome( true );
    }

    public function renderViews(): void
    {
        $this->doViewsHome( false );
    }


    protected function createComponentViewForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addText('name', 'Jméno grafu:')
        ->setOption('description', 'Bude zobrazeno jako nadpis nad grafem a jako jméno volby v levém menu.'  )
            ->setAttribute('size', 50)
            ->setRequired();

        $form->addText('app_name', 'Jméno aplikace:')
            ->setOption('description', 'Bude zobrazeno v šedém pruhu nahoře.'  )
            ->setAttribute('size', 50)
            ->setRequired();
    
        $form->addTextArea('vdesc', 'Popis:')
            ->setAttribute('rows', 8)
            ->setAttribute('cols', 80)
            ->setRequired();

        $form->addText('token', 'Zabezpečovací token:')
            ->setOption('description', 'Stane se součástí URL. Zadejte dlouhý náhodný text. Všechny grafy se stejným tokenem budou vidět v jednom bloku a budou mít společné levé menu.'  )
            ->addRule(Form::PATTERN, 'Jen písmena, čísla, pomlčka', '([0-9A-Za-z\-]+)')
            ->setAttribute('size', 50)
            ->setDefaultValue( Random::generate(40) )
            ->setRequired();

        $form->addCheckbox('allow_compare', 'Povolit srovnávání')
            ->setOption('description', 'Pokud je zaškrtnuto, bude nabídnuta možnost srovnávání s jiným rokem.'  );

        $renders = [
            'chart' => 'Základní graf',
            'coverage' => 'Zobrazení pokrytí dat',
            'avgtemp' => 'Průměrná teplota',
            'avgyears0' => 'Porovnání průměrné teploty',
            'avgyears1' => 'Porovnání minimální teploty',
            'line' => 'Vodorovné čáry - vhodné pro směr větru',
            'bar' => 'Sloupcový graf - vhodné pro srážky',
        ];

        $form->addSelect('render', 'Vykreslovací stroj:', $renders)
            ->setDefaultValue('chart')
            ->setPrompt('- Zvolte způsob vykreslení -')
            ->setRequired();

        $form->addInteger('vorder', 'Pořadí:')
            ->setOption('description', 'Pořadí v menu - pokud je více grafů se stejným tokenem, řadí se podle této hodnoty. Vyšší číslo = více nahoře.'  )
            ->setDefaultValue(10)
            ->setRequired();

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'viewFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function renderCreate(): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '';
    }

    public function actionEdit(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';

        $this->datasource->getViews( $this->getUser()->id );
        $this->template->view = $this->datasource->views[$id];

        $this->template->noneLeft = TRUE;
        foreach( $this->template->view->items as $item ) {
            if( $item->axisY == 1 ) {
                $this->template->noneLeft = FALSE;
            }
        }

        $this->template->sensors = $this->datasource->getSensors( $this->getUser()->id );

        $post = array();
        $post['name'] = $this->template->view->name;
        $post['app_name'] = $this->template->view->appName;
        $post['vdesc'] = $this->template->view->desc;
        $post['token'] = $this->template->view->token;
        $post['render'] = $this->template->view->render;
        $post['vorder'] = $this->template->view->vorder;
        $post['allow_compare'] = $this->template->view->allowCompare;
        $this['viewForm']->setDefaults($post);

        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $this->template->url = $url->getAbsoluteUrl() . "chart/view/{$post['token']}/{$id}/?currentweek=1";
    }

    public function viewFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');

        if( $id ) {
            // editace
            $this->datasource->getViews(  $this->getUser()->id );
            if( ! isset($this->datasource->views[$id]) ) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu view {$id}" );
                $this->error('View nebylo nalezeno');
            }
            $this->datasource->updateView( $id, $values );
        } else {
            // zalozeni
            $values['user_id'] =  $this->getUser()->id ;
            $row = $this->datasource->createView( $values );
            $id = $row->id;
        }

        $this->flashMessage("Změny provedeny.", 'success');
        $this->redirect('View:edit', $id );
    }


    public function actionDelete( int $id ): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';

        $this->datasource->getViews( $this->getUser()->id );
        $this->template->view = $this->datasource->views[$id];
    }

    protected function createComponentViewdeleteForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addSubmit('delete', 'Smazat')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'viewdeleteFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function viewdeleteFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');

        if( $id ) {
            // tohle je overeni prav, proto to tu musi byt
            $this->datasource->getViews( $this->getUser()->id );
            $view = $this->datasource->views[$id];

            $this->datasource->deleteView( $id );
        } 

        $this->flashMessage("Graf smazán.", 'success');
        $this->redirect('View:views' );
    }

}
