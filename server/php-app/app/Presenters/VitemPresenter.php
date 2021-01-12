<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;
use Nette\Utils\DateTime;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use Nette\Utils\Strings;
use Nette\Application\UI;
use Nette\Application\UI\Form;

use \App\Model\View;
use \App\Model\ViewItem;
use \App\Model\Color;
use App\Services\Logger;

use \App\Services\InventoryDataSource;

final class VitemPresenter extends BaseAdminPresenter
{
    use Nette\SmartObject;

    /** @persistent */
	public $viewid = "";
    
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    public function __construct(\App\Services\InventoryDataSource $datasource, 
                                $appName, $links )
    {
        $this->datasource = $datasource;
        $this->appName = $appName;
        $this->links = $links;
    }

    
    protected function createComponentVitemForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $sensors = array();
        foreach( ($this->datasource->getSensors( $this->getUser()->id )) as $sensor ) {
            $sensors[ $sensor->id ] = "{$sensor->dev_name}:{$sensor->name} · [{$sensor->unit}] · {$sensor->desc}";
        }
        
        $form->addMultiSelect( 'sensorIdsArr', 'Senzory:', $sensors )
            ->setAttribute('size', 20)
            ->setOption('description', 'Můžete zvolit více senzorů měřících stejnou veličinu. Zobrazí se data z toho, který zrovna data mít bude.')
            ->setRequired();

        $viewSources = array();    
        foreach( ($this->datasource->getViewSources()) as $vs ) {
            $viewSources[ $vs->id ] = $vs->desc;
        }
        	
        $form->addSelect('view_source_id', 'Jak načíst data:', $viewSources)
            ->setDefaultValue('1')
            ->setPrompt( '- Zvolte způsob načtení dat -' )
            ->setOption('description', 'Způsobm, jakým se načtou data z databáze. Pokud nevíte, použijte Automatická data.')
            ->setRequired();

        $axes = [
            '1' => '<- Levá',
            '2' => 'Pravá ->',
        ];

        $form->addSelect('y_axis', 'Osa Y:', $axes)
            ->setDefaultValue('1')
            ->setPrompt( '- Zvolte osu Y -' )
            ->setOption('description', 'Nejméně jedna datová řada v grafu musí mít nastavenu levou osu Y.')
            ->setRequired();

        $form->addText('color_1', 'Barva pro aktuální rok:')
            ->setOption('description', 'Barva pro čáru aktuálního roku. Ve tvaru "#<červená><zelená><modrá>" , kde každá barva je číslo 00-ff. #ff0000=červená; #0000ff=modrá; #000000=černá.'  )
            ->addRule(Form::PATTERN, 'Nutný formát: #<červená><zelená><modrá> kde jednotlivé barvy jsou 00-ff', '(#[0-9a-fA-F]{6})')
            ->setDefaultValue('#ff0000')
            ->setHtmlAttribute('type', 'color')
            ->setRequired();

        $form->addText('color_2', 'Barva pro srovnávací rok:')
            ->setOption('description', 'Barva pro čáru srovnávacího roku. Ve tvaru "#<červená><zelená><modrá>" , kde každá barva je číslo 00-ff. #ff0000=červená; #0000ff=modrá; #000000=černá.'  )
            ->addRule(Form::PATTERN, 'Nutný formát: #<červená><zelená><modrá> kde jednotlivé barvy jsou 00-ff', '(#[0-9a-fA-F]{6})')
            ->setDefaultValue('#0000ff')
            ->setHtmlAttribute('type', 'color')
            ->setRequired();

        $form->addInteger('vorder', 'Pořadí:')
            ->setOption('description', 'Pořadí v menu - pokud je více datových řad, řadí se podle této hodnoty. Vyšší číslo = níže.'  )
            ->setDefaultValue(10)
            ->setRequired();

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'vitemFormSucceeded'];
        // debugging formu
        $form->onError[] = [$this, 'flashFormErrors'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    // https://forum.nette.org/cs/29425-formular-nereaguje-na-onsucceeded
    public function flashFormErrors(Nette\Forms\Form $form)
	{
		foreach ($form->getErrors() as $error) {
			$this->flashMessage($error, 'error');
		}
	}

    public function renderCreate(): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '';
        $this->template->viewId = $this->viewid;

        // Debugger::log( "renderVitemcreate $viewid" );
        
        // kontrola vlastnictvi
        $this->datasource->getViews(  $this->getUser()->id );
        if( ! isset($this->datasource->views[$this->viewid]) ) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu viewitem {$id}" );

            $this->error('View nebylo nalezeno');
        }
    }

    public function actionEdit( int $id ): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->viewId = $this->viewid;

        $vievItem = $this->datasource->getViewItem( $id );
        // kontrola vlastnictvi
        $this->checkAcces( $vievItem['user_id'], 'graf' );

        $vievItem['sensorIdsArr'] = explode ( ',' , $vievItem['sensor_ids'] );

        // konverze barev 255,0,0 -> #ff0000
        $color = Color::parseColor( $vievItem['color_1'] );
        $vievItem['color_1'] = $color->getHtmlColor();

        $color = Color::parseColor( $vievItem['color_2'] );
        $vievItem['color_2'] = $color->getHtmlColor();

        $this['vitemForm']->setDefaults($vievItem);
    }

    

    public function vitemFormSucceeded(Form $form, array $values): void
    {
        // $viewid = $this->getHttpRequest()->getPost('viewid');
        $id = $this->getParameter('id');

        $values['sensor_ids'] = implode ( ',' , $values['sensorIdsArr'] );
        unset($values['sensorIdsArr']);
        $values['view_id'] = $this->viewid;
        // Debugger::barDump( $values, 'values' );

        // konverze barev #ff0000 -> 255,0,0
        $color = Color::parseHexColor( $values['color_1'] );
        $values['color_1'] = $color->getTextColor();

        $color = Color::parseHexColor( $values['color_2'] );
        $values['color_2'] = $color->getTextColor();

        if( $id ) {
            // editace
            $this->datasource->updateViewItem( $id, $values );
        } else {
            // zalozeni
            $this->datasource->createViewItem( $values );
        }

        $this->flashMessage("Změny provedeny.", 'success');
        $this->redirect('View:edit', $this->viewid );
    }

    public function actionDelete( int $id ): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->viewId = $this->viewid;

        $vievItem = $this->datasource->getViewItem( $id );
        // kontrola vlastnictvi
        $this->checkAcces( $vievItem['user_id'], 'graf' );
        $this->template->vitem = $id;
    }

    protected function createComponentVitemdeleteForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addSubmit('delete', 'Smazat')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'vitemdeleteFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function vitemdeleteFormSucceeded(Form $form, array $values): void
    {
        // $viewid = $this->getHttpRequest()->getPost('viewid');
        $id = $this->getParameter('id');

        if( $id ) {
            // overeni prav
            $vievItem = $this->datasource->getViewItem( $id );
            $this->checkAcces( $vievItem['user_id'], 'graf' );

            $this->datasource->deleteViewItem( $id );
        } 

        $this->flashMessage("Datová řada smazána.", 'success');
        $this->redirect('View:edit', $this->viewid );
    }
}
