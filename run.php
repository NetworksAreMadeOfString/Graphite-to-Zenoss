<?php
	/*
	 * Copyright (C) 2011 - Gareth Llewellyn
	 *
	 * This file is part of GraphiteZenossBridge - https://github.com/NetworksAreMadeOfString/Graphite-to-Zenoss
	 *
	 * This program is free software: you can redistribute it and/or modify it
	 * under the terms of the GNU General Public License as published by
	 * the Free Software Foundation, either version 3 of the License, or
	 * (at your option) any later version.
	 *
	 * This program is distributed in the hope that it will be useful, but WITHOUT
	 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
	 * FOR A PARTICULAR PURPOSE. See the GNU General Public License
	 * for more details.
	 *
	 * You should have received a copy of the GNU General Public License along with
	 * this program. If not, see <http://www.gnu.org/licenses/>
	 */
	
	/*
	 This PHP script is designed to iterate through the $MaxChecks, $MinChecks & $ROCChecks arrays,
	 connect to Graphite, compare the Max, Min or ROC Max number returnedagainst the value in the array
	 and then raise an alert accordingly.
	
	 Critical Alerts are dispatched to Zenoss SMS which are then relayed to Pager Duty
	
	 Alert Levels
	 Critical	(red)		(5) Zenoss SMS + Full Pager Duty Alerting
	 Error 		(orange)	(4) Zenoss SMS
	 Warning 	(yellow) 	(3) Zenoss Dashboard
	 Info 		(blue) 		(2) Zenoss Dashboard
	 Debug 		(grey) 		(1) Zenoss Dashboard
	 Clear 		(green) 	(0) Clear
	
	 */
	include(dirname(__FILE__).'/GraphiteZenossBridge.class.php');
	
	$Credentials = array('zenoss_username' => 'none',
						'zenoss_password' => 'none',
						'zenoss_url' => 'http://none.net:8080',
						'zenoss_eventclass' => '/Status',
						'graphite_url' => 'http://none.net:81/',
						'graphite_username' => null,
						'graphite_password' => null);
	
	$Metrics = array(
			'Test 1' => array('Metric' => 'devices.servers.ded2581.goblin.items.read.roc', 'Min' => 100, 'Max' => 2000, 'ROC' => null, 'Severity' => 4),
			'Test 2' => array('Metric' => 'sumSeries(datasift.meteor.*.*.active_connections)', 'Min' => 100, 'Max' => 2000, 'ROC' => null, 'Severity' => 4),
			'Test 3' => array('Metric' => 'sumSeries(devices.servers.*.memcached.fido.cmd_get.roc)', 'Min' => 100, 'Max' => 2000, 'ROC' => null, 'Severity' => 4)
	);
	
	$GraphiteZenossBridge = new GraphiteZenossBridge($Credentials,$Metrics);
	
	if($GraphiteZenossBridge)
	{
		$GraphiteZenossBridge->Run();
	}
	else
	{
		print("There was a problem creating the class.");
	}
	//$GraphiteZenossBridge->SendAlert("Component","Message",2,'Graphite');
	

?>