<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Tracy\Debugger;

final class HomepagePresenter extends Nette\Application\UI\Presenter
{
    public function renderDefault()
    {
        $this->redirect("Inventory:user" );
    }
}
