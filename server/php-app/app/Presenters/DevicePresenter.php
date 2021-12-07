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
use Nette\Utils\FileSystem;
use Nette\Application\UI;
use Nette\Application\UI\Form;
use Nette\Http\Url;
use Nette\Application\Responses\FileResponse;

use App\Services\Logger;
use \App\Services\InventoryDataSource;
use \App\Services\Config;

final class DevicePresenter extends BaseAdminPresenter
{
    use Nette\SmartObject;

    /** @persistent */
	public $viewid = "";
    
    /** @var \App\Services\InventoryDataSource */
    private $datasource;

    /** @var \App\Services\Config */
    private $config;

    public function __construct(\App\Services\InventoryDataSource $datasource, 
                                \App\Services\Config $config )
    {
        $this->datasource = $datasource;
        $this->config = $config;
        $this->links = $config->links;
        $this->appName = $config->appName;
    }


    public function renderCreate()
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
    }


    protected function createComponentDeviceForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addGroup('Základní údaje');

        $form->addText('name', 'Identifikátor (jméno):')
            ->setRequired()
            ->addRule(Form::PATTERN, 'Jen písmena a čísla', '([0-9A-Za-z]+)')
            ->setOption('description', 'Toto jméno doplněné prefixem bude používáno pro přihlašování zařízení.'  )
            ->setAttribute('size', 50)
            ;

        $form->addText('passphrase', 'Komunikační heslo:')
            ->setAttribute('size', 50)
            ->setRequired();

        $form->addTextArea('desc', 'Popis:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addGroup('Přístup k datům bez přihlášení');

        $form->addText('json_token', 'Bezpečnostní token pro data:')
            ->setOption('description', 'Pokud je vyplněn, kdokoli se znalostí správné adresy se může podívat na JSON s daty. Má smysl jen v případě, že má zařízení nějaké senzory.'  )
            ->setAttribute('size', 50)
            ->setDefaultValue( Random::generate(40) );

        $form->addText('blob_token', 'Bezpečnostní token pro galerii:')
            ->setOption('description', 'Pokud je vyplněn, kdokoli se znalostí správné adresy se může podívat na galerii obrázků. Má smysl jen tehdy, pokud zařízení nahrává obrázky.'  )
            ->setAttribute('size', 50)
            ->setDefaultValue( Random::generate(40) );

        $form->addGroup('Monitoring');

        $form->addCheckbox('monitoring', 'Zařadit do monitoringu funkce')
            ->setOption('description', 'Pokud ze senzorů zařízení nebudou chodit data tak často, jak mají, bude zaslána notifikace.'  )
            ->setDefaultValue(false);

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'deviceFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    
    public function deviceFormSucceeded(Form $form, array $values): void
    {
        $values['name'] = "{$this->getUser()->getIdentity()->prefix}:{$values['name']}";
        $values['user_id'] = $this->getUser()->id;

        $values['passphrase'] = $this->config->encrypt( $values['passphrase'], $values['name'] );

        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getDevice( $id );
            if (!$device) {
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id );
            $device->update( $values );
        } else {
            // zalozeni
            $this->datasource->createDevice( $values );
        }

        $this->flashMessage("Změny provedeny.", 'success');
        if( $id ) {
            $this->redirect("Device:show", $id );
        } else {
            $this->redirect("Inventory:home" );
        }
    }
    
    public function actionEdit(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }

        Logger::log( 'audit', Logger::INFO ,
            "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} edituje/prohlizi konfiguraci/heslo zarizeni {$id}" );

        $post = $post->toArray();
        $post['passphrase'] = $this->config->decrypt( $post['passphrase'], $post['name'] );
        $this->checkAcces( $post['user_id'] );

        $this->template->name = $post['name'];

        $arr = Strings::split($post['name'], '~:~');
        $post['name'] = $arr[1];

        $this['deviceForm']->setDefaults($post);
    }

    //----------------------------------------------------------------------



    public function renderConfig(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $post['passphrase'] = $this->config->decrypt( $post['passphrase'], $post['name'] );
        $this->checkAcces( $post['user_id'] );
        $this->template->device = $post;

        Logger::log( 'audit', Logger::INFO ,
            "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} prohlizi konfiguraci/heslo zarizeni {$id}" );

        $blobCount = $this->datasource->getBlobCount( $id );

        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $url->setScheme('http');
        $url1 = $url->getAbsoluteUrl() . 'ra';
        $this->template->url = substr( $url1 , 7 );

        $submenu = array();
        $submenu[] =   ['id' => '102', 'link' => "device/show/{$id}", 'name' => "· Zařízení {$post['name']}" ];
        if( $blobCount>0 ) {
            $submenu[] =   ['id' => '103', 'link' => "device/blobs/{$id}", 'name' => "· · Soubory ({$blobCount})" ];
        }
        $this->populateTemplate( 102, 1, $submenu );
        $this->template->path = '../';
    }


    private function secondsToTime($inputSeconds) {
        $secondsInAMinute = 60;
        $secondsInAnHour = 3600;
        $secondsInADay = 86400;
    
        // Extract days
        $days = floor($inputSeconds / $secondsInADay);
    
        // Extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours = floor($hourSeconds / $secondsInAnHour);
    
        // Extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes = floor($minuteSeconds / $secondsInAMinute);
    
        // Extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds = ceil($remainingSeconds);
    
        // Format and return
        $timeParts = [];
        $sections = [
            'd' => (int)$days,
            'hod' => (int)$hours,
            'min' => (int)$minutes,
            'sec' => (int)$seconds,
        ];
    
        foreach ($sections as $name => $value){
            if ($value > 0){
                $timeParts[] = $value. ' '.$name;
            }
        }
    
        return implode(', ', $timeParts);
    }


    public function renderShow(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $post['passphrase'] = $this->config->decrypt( $post['passphrase'], $post['name'] );
        $this->checkAcces( $post['user_id'] );

        if( $post['uptime'] ) {
            $this->template->uptime = $this->secondsToTime( $post['uptime'] );
        }

        $lastLoginTs = (DateTime::from( $post['last_login']))->getTimestamp();
        $post['problem_mark'] = false;
        if( $post['last_bad_login'] != NULL ) {
            if( $post['last_login'] != NULL ) {
                $lastErrLoginTs = (DateTime::from(  $post['last_bad_login']))->getTimestamp();
                if( $lastErrLoginTs >  $lastLoginTs ) {
                    $post['problem_mark'] = true;
                }
            } else {
                $post['problem_mark'] = true;
            }
        }

        $blobCount = $this->datasource->getBlobCount( $id );

        $submenu = array();
        $submenu[] =   ['id' => '102', 'link' => "device/show/{$id}", 'name' => "· Zařízení {$post['name']}" ];
        if( $blobCount>0 ) {
            $submenu[] =   ['id' => '103', 'link' => "device/blobs/{$id}", 'name' => "· · Soubory ({$blobCount})" ];
        }
        $this->populateTemplate( 102, 1, $submenu );
        $this->template->path = '../';


        $this->template->device = $post;
        $this->template->soubory = $blobCount;

        $this->template->sensors = $this->datasource->getDeviceSensors( $id );

        $lastTime = $lastLoginTs;

        foreach ($this->template->sensors as $sensor) {
            $sensor['warningIcon'] = 0;
            if( $sensor['last_data_time'] && $sensor['msg_rate']!=0 ) {
                $utime = (DateTime::from( $sensor['last_data_time'] ))->getTimestamp();
                if( time()-$utime > $sensor['msg_rate'] ) {
                    if( $post['monitoring']==1 ) {
                        $sensor['warningIcon'] = 1;
                    } else {
                        $sensor['warningIcon'] = 2;
                    }
                } 
                if( !$lastTime || ($utime > $lastTime) ) {
                    $lastTime = $utime;
                } 
            }
        }
        $this->template->lastComm = $lastTime;

        $this->template->updates = $this->datasource->getOtaUpdates( $id );

        $url = new Url( $this->getHttpRequest()->getUrl()->getBaseUrl() );
        $this->template->jsonUrl = $url->getAbsoluteUrl() . "json/data/{$post['json_token']}/{$id}/";
        $this->template->jsonUrl2 = $url->getAbsoluteUrl() . "json/meteo/{$post['json_token']}/{$id}/?temp=JMENO_TEMP_SENZORU&rain=JMENO_RAIN_SENZORU";
        $this->template->blobUrl = $url->getAbsoluteUrl() . "gallery/{$post['blob_token']}/{$id}/";

    }


    public function renderBlobs(int $id): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $blobs = $this->datasource->getBlobs( $id );
        $blobCount = count( $blobs );

        $submenu = array();
        $submenu[] =   ['id' => '102', 'link' => "device/show/{$id}", 'name' => "· Zařízení {$post['name']}" ];
        $submenu[] =   ['id' => '103', 'link' => "device/blobs/{$id}", 'name' => "· · Soubory ({$blobCount})" ];
        $this->populateTemplate( 103, 1, $submenu );
        $this->template->path = '../';

        $this->template->id = $id;
        $this->template->device = $post;
        $this->template->soubory = $blobCount;
        $this->template->blobs = $blobs;
    }


    public function renderDownload(int $id, int $blobId ): void
    {
        $this->checkUserRole( 'user' );

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $blob = $this->datasource->getBlob( $id, $blobId );
        if( ! $blob ) {
            $this->error('Soubor nenalezen nebo k němu nejsou práva.');
        }

        $fileName = 
            $blob['data_time']->format('Ymd_His') . 
            "_{$id}_" .
            Strings::webalize($blob['description'], '._') .
            ".{$blob['extension']}";

        $contentType = 'application/octet-stream';
        if( $blob['extension']=='csv') {
            $contentType = 'text/csv';
        } else if( $blob['extension']=='jpg') {
            $contentType = 'image/jpeg';
        }

        $file = __DIR__ . "/../../data/" . $blob['filename'];
        $response = new FileResponse($file, $fileName, $contentType);
		$this->sendResponse($response);
    }


    //----------------------------------------------------------------------


    public function actionDelete( int $id ): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $this->checkAcces( $post->user_id );

        $this->template->device = $post;
        $this->template->statMeasures = $this->datasource->getDataStatsMeasures( $id );
        $this->template->statSumdata = $this->datasource->getDataStatsSumdata( $id );
        
    }

    protected function createComponentDeleteForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addCheckbox('potvrdit', 'Potvrdit smazání')
            ->setOption('description', 'Zaškrtnutím potvrďte, že skutečně chcete smazat zařízení a všechna data jím zaznamenaná.'  )
            ->setRequired();

        $form->addSubmit('delete', 'Smazat')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');

        $form->onSuccess[] = [$this, 'deleteFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    public function deleteFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');

        if( $id ) {
            // overeni prav
            $post = $this->datasource->getDevice( $id );
            if (!$post) {
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $post->user_id );
            
            Logger::log( 'audit', Logger::INFO , "[{$this->getHttpRequest()->getRemoteAddress()}, {$this->getUser()->getIdentity()->username}] Mazu zarizeni {$id}" ); 
            $this->datasource->deleteDevice( $id );
        } 

        $this->flashMessage("Zařízení smazáno.", 'success');
        $this->redirect('Inventory:home' );
    }



    //----------------------------------------------------------------------



    public function actionSendconfig(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $this->template->name = $post['name'];

        $arr = Strings::split($post['name'], '~:~');
        $post['name'] = $arr[1];

        $this['sendconfigForm']->setDefaults($post);
    }

    protected function createComponentSendconfigForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addTextArea('config_data', 'Změna konfigurace:')
            ->setAttribute('rows', 4)
            ->setAttribute('cols', 50)
            ->setRequired();

        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'sendconfigFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }

    
    public function sendconfigFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getDevice( $id );
            if (!$device) {
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id );
            if( ! $device['config_ver'] ) {
                $values['config_ver'] = '1';
            } else {
                $values['config_ver'] = intval($device['config_ver']) + 1;
            }
            $device->update( $values );
            $this->datasource->deleteSession( $id );
            $this->flashMessage("Změny provedeny.", 'success');
            $this->redirect("Device:show", $id );
        } else {
            // zalozeni - to se nema stat
            $this->redirect("Inventory:home" );
        }
    }    
    

    //----------------------------------------------------------------------



    public function actionUpdate(int $id): void
    {
        $this->checkUserRole( 'user' );
        $this->populateTemplate( 0 );
        $this->template->path = '../';
        $this->template->id = $id;

        $post = $this->datasource->getDevice( $id );
        if (!$post) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $post = $post->toArray();
        $this->checkAcces( $post['user_id'] );

        $this->template->name = $post['name'];

        $arr = Strings::split($post['name'], '~:~');
        $post['name'] = $arr[1];

        $warningOTA = true;
        $warningVer = true;
        $appName = "";

        // rozebrat $post['app_name'] a udelat z nej post['fromVersion']
        if( substr($post['app_name'], 0, 1)=='[' ) {
            $pos = strpos($post['app_name'], ']' );
            if( $pos!==false ) {
                $post['fromVersion'] = substr($post['app_name'], 1, $pos-1 );
                $warningVer = false;
            }
        }
        // pokud v $post['app_name'] neni 'OTA Y', dat varovani, ze aplikace to asi nepodporuje
        if( false !== strpos($post['app_name'], "; OTA Y" ) ) {
            $warningOTA = false;
        }
        $this->template->warningOTA = $warningOTA;
        $this->template->warningVer = $warningVer;

        $this['updateForm']->setDefaults($post);
    }

    protected function createComponentUpdateForm(): Form
    {
        $form = new Form;
        $form->addProtection();

        $form->addText('fromVersion', 'ID verze aplikace, ze které se aktualizuje:')
            ->setRequired()
            ->addRule(Form::PATTERN, 'Jen písmena, čísla a znaky .-_', '([0-9A-Za-z_\.\-]+)')
            ->setOption('description', 'ID stávající verze aplikace; je uvedeno v hranatých závorkách na začátku jména aplikace.'  )
            ->setAttribute('size', 50);

        $form->addUpload('image', 'Soubor s aktualizací:')
            ->setRequired();
            
        $form->addSubmit('send', 'Uložit')
            ->setHtmlAttribute('onclick', 'if( Nette.validateForm(this.form) ) { this.form.submit(); this.disabled=true; } return false;');
            
        $form->onSuccess[] = [$this, 'updateFormSucceeded'];

        $this->makeBootstrap4( $form );
        return $form;
    }


    private function getUpdateFilename( $deviceId, $updateId ) 
    {
        return __DIR__ . "/../../data/ota/{$deviceId}_{$updateId}.bin";   
    }
    
    public function updateFormSucceeded(Form $form, array $values): void
    {
        $id = $this->getParameter('id');
        if( $id ) {
            // editace
            $device = $this->datasource->getDevice( $id );
            if (!$device) {
                $this->error('Zařízení nebylo nalezeno');
            }
            $this->checkAcces( $device->user_id );

            $file = $values['image'];
            // kontrola, jestli se nahrál dobře
            if(! $file->isOk())
            {
                $this->flashMessage('Něco selhalo.', 'danger');
                $this->redirect('Device:show', $id );
                return;
            }

            // vraci hexastream
            $fileHash = hash("sha256", $file->getContents(), false );

            // ulozit data do tabulky a soubor na disk
            $fileId = $this->datasource->otaUpdateCreate( $id, $values['fromVersion'], $fileHash );
            if( $fileId==-1 ) {
                $this->flashMessage('Pro tohle zařízení a verzi aplikace již požadavek na update existuje.', 'danger');
                $this->redirect('Device:show', $id );
                return;
            }

            // přesunutí souboru z temp složky někam, kam nahráváš soubory
            $file->move( $this->getUpdateFilename( $id, $fileId ) );

            Logger::log( 'webapp', Logger::INFO ,
            "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} posila aktualizaci {$fileId} na zarizeni {$id}" );            
            
            $this->datasource->deleteSession( $id );

            $this->flashMessage("Aktualizace připravena.", 'success');
            $this->redirect("Device:show", $id );
        } else {
            // to se nema stat
            $this->redirect("Inventory:home" );
        }
    }    

    public function renderDeleteupdate( int $device_id, int $update_id ): void
    {
        $device = $this->datasource->getDevice( $device_id );
        if (!$device) {
            $this->error('Zařízení nebylo nalezeno');
        }
        $this->checkAcces( $device->user_id );

        $this->datasource->otaDeleteUpdate( $device_id, $update_id );

        FileSystem::delete( $this->getUpdateFilename( $device_id, $update_id ) );

        Logger::log( 'webapp', Logger::INFO ,
        "Uzivatel #{$this->getUser()->id} {$this->getUser()->getIdentity()->username} maze aktualizaci {$update_id} na zarizeni {$device_id}" );            

        $this->flashMessage("Aktualizace smazána.", 'success');
        $this->redirect("Device:show", $device_id );
    }
}

