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
		$router->addRoute('chart/chart/<token>/<id>/', 'Chart:chart');
		$router->addRoute('chart/bar/<token>/<id>/', 'Chart:bar');
		$router->addRoute('chart/coverage/<token>/<id>/', 'Chart:coverage');
		$router->addRoute('chart/avgtemp/<token>/<id>/', 'Chart:avgtemp');
		$router->addRoute('chart/avgyears0/<token>/<id>/', 'Chart:avgyears0');
		$router->addRoute('chart/avgyears1/<token>/<id>/', 'Chart:avgyears1');
		$router->addRoute('json/data/<token>/<id>/', 'Json:data');
		$router->addRoute('gallery/<token>/<id>/', 'Gallery:show');		
		$router->addRoute('gallery/<token>/<id>/<blobid>/', 'Gallery:blob');		
		$router->addRoute('monitor/show/<token>/<id>/', 'Monitor:show');
		$router->addRoute('<presenter>/<action>[/<id>]', 'Homepage:default');
		return $router;
	}
}
