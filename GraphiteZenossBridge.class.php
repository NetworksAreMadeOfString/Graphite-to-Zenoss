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

class GraphiteZenossBridge
{
	//These are all pretty self explainatory
	private $ZenossUserName;
	private $ZenossPassword;
	private $ZenossURL;
	private $GraphiteURL;
	private $GraphiteUserName;
	private $GraphitePassword;
	private $CredentialsBundle = array();
	private $QueryBundle = array();
	private $MetricBundle = array();

	//Stores the failed checks from last the last run to see if they are still down
	private $OldAlerts = array();

	//New alerts that will get stored in the state file
	private $Alerts = array();

	//The date of this run
	private $Date;

	//If a service stops sending stats to statsd then the value will be NaN or None - this is bad
	private $MaxNoneAllowed = 4;

	//If CURL fails to talk to graphite this many times send a critical alert and quit (no point tying up resources)
	private $MaxGraphiteFailures = 2;
	private $GraphiteFailures = 0;

	//This is where we store the state from the previous run
	private $failuresFile = "/tmp/failures.txt";
	
	/**
	 * The default constructor
	 * @param array $CredentialsBundle - A collection of Zenoss and Graphite credentials
	 * @param array $QueryBundle - A collection of graphite metrics and thresholds
	 */
	function __construct($CredentialsBundle, $QueryBundle)
	{
		/*if(!is_array($CredentialsBundle || !is_array($QueryBundle)))
			return false;*/

		$this->Date = date('Y-m-d H:i:s');

		//Credentials
		if(!isset($CredentialsBundle['zenoss_username']) || empty($CredentialsBundle['zenoss_username']))
		return false;

		if(!isset($CredentialsBundle['zenoss_password']) || empty($CredentialsBundle['zenoss_password']))
		return false;
			
		if(!isset($CredentialsBundle['zenoss_url']) || empty($CredentialsBundle['zenoss_url']))
		return false;

		if(!isset($CredentialsBundle['graphite_url']) || empty($CredentialsBundle['graphite_url']))
		return false;
			
		$this->ZenossUserName = $CredentialsBundle['zenoss_username'];
		$this->ZenossPassword = $CredentialsBundle['zenoss_password'];
		$this->ZenossURL = $CredentialsBundle['zenoss_url'];
		$this->GraphiteURL = $CredentialsBundle['graphite_url'];

		//These are optional if people are using HTTP basic auth to protect Graphite
		if(isset($CredentialsBundle['graphite_username']) && !empty($CredentialsBundle['graphite_username']))
		$this->GraphiteUserName = $CredentialsBundle['graphite_username'];

		if(isset($CredentialsBundle['graphite_password']) && !empty($CredentialsBundle['graphite_password']))
		$this->GraphitePassword = $CredentialsBundle['graphite_password'];

		$this->QueryBundle = $QueryBundle;

		return true;
	}

	/***
	 * 
	 */
	public function Run()
	{
		//Populate the OldAlerts array with details from the last run
		$this->ProcessStateFile(false);

		$GraphiteQueryString = '/render/?from=-10minutes&rawData=true';

		foreach($this->QueryBundle as $Title => $Config)
		{
			$GraphiteQueryString .= '&target=' . $Config['Metric'];
		}

		$this->MakeGraphiteRequest($GraphiteQueryString);

		foreach($this->QueryBundle as $Title => $Config)
		{
			if(isset($Config['Max']) && !empty($Config['Max']) && $Config['Max'] != null)
			$this->CheckForMaxValues($Title, $Config['Metric'], $Config['Max'], $Config['Severity']);

			/*if(isset($Config['Min']) && !empty($Config['Min']) && $Config['Min'] != null)
				$this->CheckForMaxValues($Title, $Config['Metric'], $Config['Min'], $Config['Severity']);

			if(isset($Config['ROC']) && !empty($Config['ROC']) && $Config['ROC'] != null)
				$this->CheckForMaxValues($Title, $Config['Metric'], $Config['ROC'], $Config['Severity']);*/
		}

		//Write out the state file
		$this->ProcessStateFile(true);
		return true;
	}

	private function ProcessStateFile($Write = false)
	{
		if($Write)
		{
			$fh = fopen($this->failuresFile, 'w') or die("can't open file");

			foreach($this->Alerts as $Title => $Date)
			{
				fwrite($fh, "$Title|$Date\n");
			}
			fclose($fh);
		}
		else
		{
			
			foreach(file($this->failuresFile) as $Failed)
			{
				$FailedDetails = explode('|', $Failed);
				// 0 = name
				// 1 = Date of first failure
				$this->OldAlerts[$FailedDetails[0]] = $FailedDetails[1];
			}
		}
	}

	private function CheckForMaxValues($Title, $Metric, $Threshold, $Severity = 2)
	{
		$MaxValue = 0;
		$NoneCounter = 0;

		foreach($this->MetricBundle[$Metric] as $Value)
		{
			if($Value != 'None' && $Value != "None\n")
			{
				if((int)$Value > (int)$MaxValue)
				$MaxValue = (int)$Value;
			}
			else
			{
				$NoneCounter++;
			}
		}

		if($NoneCounter > $this->MaxNoneAllowed)
		{
			//SendNoneAlert($Title, $MetricName, $NoneCounter);
			print("None Alert");
		}
		else
		{
			if($MaxValue > $Threshold)
			{
				//Send Alert
				$this->SendMaxAlert($Title,$MaxValue,$Threshold,$Metric, $Severity);

				//Add to the state array (for later saving to file)
				$this->Alerts[$Title] = $this->Date;
			}
			else
			{
				//Send clear if we've previously alerted on this
				if(isset($this->OldAlerts[$Title]) && !empty($this->OldAlerts[$Title]))
				{
					$this->SendClear($Title);
				}
				else
				{
					//The check isn't down now and wasn't down earlier
					print("$Title is OK ($MaxValue) and hasn't been down previously\r\n");
				}
			}
		}
	}

	private function MakeGraphiteRequest($GraphiteQueryString)
	{
		$URL = $this->GraphiteURL . $GraphiteQueryString;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $URL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Graphite to Zenoss');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if(isset($this->GraphiteUserName) && !empty($this->GraphiteUserName)  && isset($this->GraphitePassword) && !empty($this->GraphitePassword))
		curl_setopt($ch, CURLOPT_USERPWD, $this->GraphiteUserName . ":" . $this->GraphitePassword);


		$output = curl_exec($ch);
		curl_close($ch);

		$ArrayTest = explode("\n",$output);
		if(isset($ArrayTest[1]) && !empty($ArrayTest[1]))
		{
			$output = array();
			foreach($ArrayTest as $Line)
			{
				$Temp = explode("|",$Line);
				$LineDetails = explode(",", $Temp[0]);
				if(isset($Temp[1]) && !empty($Temp[1]))
				$output[$LineDetails[0]] = explode(",",$Temp[1]);
			}
		}
		else
		{
			$orig = $output;
			$output = explode("|",$output);
			if(isset($output[1]))
			{
				$output = explode(",",$output[1]);
			}
			else
			{
				$GLOBALS['GraphiteFailures']++;

				if($GLOBALS['GraphiteFailures'] > $GLOBALS['MaxGraphiteFailures'])
				{
					$this->SendGraphiteFailAlert();
					exit();
				}
				return "Error";
			}
		}

		$this->MetricBundle = $output;
	}

	private function SendMaxAlert($Title, $Value, $Trip, $Metric, $Severity = 2)
	{
		print("Sending an alert that $Title ($Metric) is over $Trip at $Value\r\n");
		//SendToZenoss($Title,"$Title is over its threshold of $Trip [ $Metric ]",$Severity);
		return 0;
	}

	private function SendMinAlert($Title, $Value, $Trip, $Metric, $Severity = 2)
	{
		print("Sending an alert that $Title ($Metric) is under $Trip ($Value)\r\n");
		//SendToZenoss($Title,"$Title is under its threshold of $Trip [ $Metric ]",$Severity);
		return 0;
	}

	private function SendNoneAlert($Title, $Metric, $NoneCounter)
	{
		print("Sending an alert that $Title ($Metric) is reporting too many 'None' values: $NoneCounter\r\n");
		//SendToZenoss($Title,"$Title is reporting too many 'None' values [ $Metric ] ($NoneCounter) [This alert will not auto clear]", 5); //Always 5 no matter what
		return 0;
	}

	private function SendGraphiteFailAlert()
	{
		print("Sending an alert that the CURL request to Graphite failed too many times\r\n");
		//SendToZenoss("GraphiteZenossBridge","The CURL requests to Graphite are failing! [This alert will not auto clear]", 5); //Always 5 no matter what
		return 0;
	}

	private function SendClear($Title)
	{
		print("Clearing $Title\r\n");
		//SendToZenoss($Title,"Clearing $Title",0);
		return 0;
	}
}
?>
