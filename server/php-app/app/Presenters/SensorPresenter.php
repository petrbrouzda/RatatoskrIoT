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

use \App\Services\InventoryDataSource;
use App\Services\Logger;

final class SensorPresenter extends BaseAdminPresenter
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


    protected function createComponentSensorForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addGroup('Základní údaje');

        $form->addTextArea('desc', 'Popis:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addInteger('display_nodata_interval', 'Maximální interval dat [s]:')
            ->setAttribute('size', 20)
            ->setDefaultValue(7200)
            ->setOption('description', 'Pokud budou zaznamenaná data s větším rozestupem, nebudou v grafu spojena čárou; graf bude přerušen.'  )
            ->setRequired();

        $form->addGroup('Násobící faktor');

        $form->addCheckbox('preprocess_data', 'Násobit naměřenou hodnotu')
            ->setOption('description', 'Pokud je zaškrtnuto, bude se naměřená hodnota násobit udaným koeficientem.'  )
            ->addCondition($form::EQUAL, true)
		        ->toggle('factor-id');

        $form->addText('preprocess_factor', 'Koeficient:')
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'factor-id')
            ->addConditionOn($form['preprocess_data'], Form::EQUAL, true)
				->setRequired('Musí být vyplněno');

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'sensorFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }


    protected function createComponentSensorextForm(): Form
    {
        $form = new Form;
        
        $form->addProtection();

        $form->addGroup('Základní údaje');

        $form->addTextArea('desc', 'Popis:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addInteger('display_nodata_interval', 'Maximální interval dat [s]:')
            ->setAttribute('size', 20)
            ->setDefaultValue(7200)
            ->setOption('description', 'Pokud budou zaznamenaná data s větším rozestupem, nebudou v grafu spojena čárou; graf bude přerušen.'  )
            ->setRequired();

        $form->addGroup('Násobící faktor');

        $form->addCheckbox('preprocess_data', 'Násobit naměřenou hodnotu')
            ->setOption('description', 'Pokud je zaškrtnuto, bude se naměřená hodnota násobit udaným koeficientem.'  )
            ->addCondition($form::EQUAL, true)
		        ->toggle('factor-id');

        $form->addText('preprocess_factor', 'Koeficient:')
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'factor-id')
            ->addConditionOn($form['preprocess_data'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');


        $form->addGroup('Poplach při vysoké hodnotě');

        $form->addCheckbox('warn_max', 'Zapnout zasílání')
            ->setOption('description', 'Pokud je zaškrtnuto, bude se zasílat notifikace při dosažení či překročení maxima.'  )
            ->addCondition($form::EQUAL, true)
                ->toggle('warn_max_val')
                ->toggle('warn_max_val_off')
                ->toggle('warn_max_text')
                ->toggle('warn_max_after');

        $form->addText('warn_max_val', 'Spustit varování při:')
            ->setOption('description', 'Poplach se generuje při překročení této hodnoty.'  )
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'warn_max_val')
            ->addConditionOn($form['warn_max'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');

        $form->addText('warn_max_val_off', 'Vypnout varování při:')
            ->setOption('description', 'Poplach končí při poklesu hodnoty pod tento limit. Doporučujeme nastavit o trochu méně, než je hodnota, při které se varování spouští - aby při oscilaci naměřené hodnoty kolem limitu nechodila jedna notifikace za druhou (tzv. hystereze).'  )
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'warn_max_val_off')
            ->addConditionOn($form['warn_max'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');

        $form->addText('warn_max_after', 'Notifikaci poslat po:')
            ->setOption('description', 'Počet sekund, po které musí být hodnota nad limitem, aby byla notifikace zaslána. 0 = hned.'  )
            ->setAttribute('size', 20)
            ->addRule(Form::INTEGER, 'Musí být číslo')
            ->setOption('id', 'warn_max_after')
            ->addConditionOn($form['warn_max'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');

        $form->addText('warn_max_text', 'Informační text (max):')
            ->setOption('description', 'Tento text bude součástí varování'  )
            ->setAttribute('size', 50)
            ->setOption('id', 'warn_max_text');


        $form->addGroup('Poplach při nízké hodnotě');

        $form->addCheckbox('warn_min', 'Zapnout zasílání')
            ->setOption('description', 'Pokud je zaškrtnuto, bude se zasílat notifikace při dosažení či překročení minima.'  )
            ->addCondition($form::EQUAL, true)
                ->toggle('warn_min_val')
                ->toggle('warn_min_val_off')
                ->toggle('warn_min_text')
                ->toggle('warn_min_after');
    
        $form->addText('warn_min_val', 'Spustit varování při:')
            ->setOption('description', 'Poplach se generuje při poklesu hodnoty pod tento limit.'  )
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'warn_min_val')
            ->addConditionOn($form['warn_min'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');

        $form->addText('warn_min_val_off', 'Vypnout varování při:')
            ->setOption('description', 'Poplach končí při nárůstu hodnoty nad tento limit. Doporučujeme nastavit o trochu více, než je hodnota, při které se varování spouští - aby při oscilaci naměřené hodnoty kolem limitu nechodila jedna notifikace za druhou (tzv. hystereze).'  )
            ->setAttribute('size', 20)
            ->addRule(Form::FLOAT, 'Musí být číslo')
            ->setOption('id', 'warn_min_val_off')
            ->addConditionOn($form['warn_min'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');
    
        $form->addText('warn_min_after', 'Notifikaci poslat po:')
            ->setOption('description', 'Počet sekund, po které musí být hodnota nad limitem, aby byla notifikace zaslána. 0 = hned.'  )
            ->setAttribute('size', 20)
            ->addRule(Form::INTEGER, 'Musí být číslo')
            ->setOption('id', 'warn_min_after')
            ->addConditionOn($form['warn_min'], Form::EQUAL, true)
                ->setRequired('Musí být vyplněno');
    
        $form->addText('warn_min_text', 'Informační text (min):')
            ->setOption('description', 'Tento text bude součástí varování'  )
            ->setAttribute('size', 50)
            ->setOption('id', 'warn_min_text');
    


        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'sensorFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }


    public function actionEdit(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';

        $post = $this->datasource->getSensor( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu sensoru {$id}" );
            $this->error('Senzor nebyl nalezeno');
        }

        $this->checkAcces( $post['user_id'], 'senzor' );

        $this->template->name = "{$post['dev_name']}:{$post['name']}";
        $this->template->id = $id;
        $this->template->device_class = $post['device_class'];

        $this['sensorForm']->setDefaults($post);
        $this['sensorextForm']->setDefaults($post);
    }

    public function sensorFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getSensor( $id );
            if (!$device) {
                Logger::log( 'audit', Logger::ERROR ,
                    "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu sensoru {$id}" );
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id , 'senzor');
            $this->datasource->updateSensor( $id, $values );
        } 

        $this->flashMessage("Změny provedeny.", 'success');
        $this->redirect('Device:show', $device->device_id );
    }

    public function renderShow(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getSensor( $id );
        if (!$post) {
            Logger::log( 'audit', Logger::ERROR ,
                "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} zkusil pristoupit k cizimu sensoru {$id}" );
            $this->error('Zařízení nebylo nalezeno');
        }
        $this->checkAcces( $post['user_id'] , 'senzor');

        $submenu = array();
        $submenu[] =   ['id' => '104', 'link' => "device/show/{$post['device_id']}", 'name' => "· Zařízení {$post['dev_name']}" ];
        $submenu[] =   ['id' => '103', 'link' => "sensor/show/{$id}", 'name' => "· · Senzor {$post['name']}" ];
        $submenu[] =   ['id' => '100', 'link' => "chart/sensorstat/show/{$id}", 'name' => "· · · Statistika" ];
        $submenu[] =   ['id' => '101', 'link' => "chart/sensor/show/{$id}", 'name' => "· · · Graf" ];
        $this->populateTemplate( 103, 1, $submenu );
        $this->template->path = '../';

        $this->template->name = "{$post['dev_name']}:{$post['name']}";        
        $this->template->sensor = $post;
    }

    
    }
