<?php

declare(strict_types=1);

namespace App\Router;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	use Nette\StaticClass;

	public static function createRouter(): RouteList
	{
		$router = new RouteList;
		$router->addRoute('chart/view/<token>/<id>/', 'Chart:view');
		$router->addRoute('chart/sensor/show/<id>/', 'Chart:sensor');
		$router->addRoute('chart/sensorstat/show/<id>/', 'Chart:sensorstat');
		$router->addRoute('chart/sensorchart/show/<id>/', 'Chart:sensorchart');
		$router->addRoute('chart/<action>/<token>/<id>/', 'Chart:coverage');
		$router->addRoute('json/<action>/<token>/<id>/', 'Json:data');
		$router->addRoute('gallery/<token>/<id>/', 'Gallery:show');		
		$router->addRoute('gallery/<token>/<id>/<blobid>/', 'Gallery:blob');		
		$router->addRoute('monitor/show/<token>/<id>/', 'Monitor:show');
		$router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');
		return $router;
	}
}
