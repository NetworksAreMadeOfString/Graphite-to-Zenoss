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
	private $ZenossEventClass = '/Status';
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

	private $CurlHandle;

	// For how many seconds do we consider metrics for alerting
	private $MonitorWindow = 600;

	// What size (in seconds ) window do we retrieve from Graphite (this is needed for functions that performs function on metrics in the past, e.g. movingAverage)
	private $GrabWindow = 1800;
	
	/**
	 * The default constructor
	 * @param array $CredentialsBundle - A collection of Zenoss and Graphite credentials
	 * @param array $QueryBundle - A collection of graphite metrics and thresholds
	 */
	function __construct($CredentialsBundle, $QueryBundle)
	{
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

		if (isset($CredentialsBundle['graphite_url_vanity'])) {
			$this->GraphiteURLVanity = $CredentialsBundle['graphite_url_vanity'];
		} else {
			$this->GraphiteURLVanity = $CredentialsBundle['graphite_url'];
		}

		//These are optional if people are using HTTP basic auth to protect Graphite
		if(isset($CredentialsBundle['graphite_username']) && !empty($CredentialsBundle['graphite_username']))
		$this->GraphiteUserName = $CredentialsBundle['graphite_username'];

		if(isset($CredentialsBundle['graphite_password']) && !empty($CredentialsBundle['graphite_password']))
		$this->GraphitePassword = $CredentialsBundle['graphite_password'];

		$this->QueryBundle = $QueryBundle;

		return true;
	}

	/**
	 * Reads through the statefile to find old alerts and stores them for later evaluation then
	 * iterates through the $QueryBundle looking for graphite URI's and crafting a query string,
	 * it then queries Graphite in one hit and stores the resulting data.
	 * Then it iterates through the QueryBundle again looking for Min, Max and ROC keys that aren't
	 * null and passes the info through to other functions that check the returned graphite data against
	 * the threshold limits specified in the QueryBundle and if neccessary raises alerts.
	 * Once that's all done it saves the current alerts to the statefile and finishes
	 */
	public function Run()
	{
		//Populate the OldAlerts array with details from the last run
		$this->ProcessStateFile(false);

		$GraphiteQueryString = '/render/?from=-' . $this->GrabWindow . 'seconds&rawData=true';

		$Targets = array();
		//Make a massive query string so we only have to hit Graphite once
		foreach($this->QueryBundle as $Title => $Config)
		{
			$Id = md5($Config['Metric']);
			echo " Adding $Id with query " . $Config['Metric'] . PHP_EOL;

			//Wrap each one with an alias of the md5 of the metric, so we have a deterministic response
			$Targets[$Id] = 'target=alias(' . $Config['Metric'] . ',"' . $Id . '")';
		}

		$GraphiteQueryString .= '&' . implode('&', $Targets);

		//Call Graphite
		$this->MakeGraphiteRequest($GraphiteQueryString);

		//Now lets start finding stuff to check
		foreach($this->QueryBundle as $Title => $Config)
		{
			// First check to see if metric has too many None values
			if ($this->CheckForNoneValues($Title, $Config['Metric'], $Config['Severity'])) {
				// Below threshold, perform checks
				if(isset($Config['Max']) && $Config['Max'] !== null)
					$this->CheckForMaxValues($Title, $Config['Metric'], $Config['Max'], $Config['Severity']);

				if(isset($Config['Min']) && $Config['Min'] !== null)
					$this->CheckForMinValues($Title, $Config['Metric'], $Config['Min'], $Config['Severity']);

				if(isset($Config['ROC']) && $Config['ROC'] !== null)
					$this->CheckROCValues($Title, $Config['Metric'], $Config['ROC'], $Config['Severity']);
			}
		}

		//Write out the state file
		$this->ProcessStateFile(true);
		
		//This story. It is true.
		return true;
	}

	/**
	 * Reads or writes to/from state file
	 * If reading it populates the OldAlerts array
	 * If writing it pulls alerts from Alerts array and stores them in the state file
	 * @param boolean $Write Whether to read from the file or write to the file
	 */
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
	/**
	 * Checks the metricbundle for the key $Metric and then checks to see
	 * if it has returned too many 'None' values
	 *
	 * @param String $Title - The friendly name of the test
	 * @param String $Metric - The graphite metric being tested a.b.c.foo.bar
	 * @param int $Severity - What severity level to send to Zenoss
	 */
	private function CheckForNoneValues($Title, $Metric, $Severity = 5)
	{
		$NoneCounter = 0;
		$StateTitle = $Title .'-none';

		if(!isset($this->MetricBundle[md5($Metric)]))
		{
			print("! Undefined index for Max check $Metric\r\n");
			$this->SendAlert($Title, "Metric [ $Metric ] does not exist", $Severity, $Metric);
			return false;
		}

		foreach($this->MetricBundle[md5($Metric)] as $Value)
		{
			//Check if Graphite is reporting the value 'None' which would mean
			//that no values were present which is BAD
			if(trim($Value) === 'None')
				$NoneCounter++;
		}

		//Check how many 'None' results were actually received and alert if neccessary
		if($NoneCounter > $this->MaxNoneAllowed)
		{
			$this->SendNoneAlert($Title, $Metric, $NoneCounter, $Severity);

			//Add to the state array (for later saving to state file)
			$this->Alerts[$StateTitle] = $this->Date;

			return false;
		} elseif(isset($this->OldAlerts[$StateTitle]) && !empty($this->OldAlerts[$StateTitle])){
			$this->SendClear($Title);
		}
		
		// below threshold, ok to continue
		return true;
	}

	/**
	 * Checks the metricbundle for the key $Metric and then evaluates the values against
	 * $Threshold. If any one value exceeds the threshold then an alert at Zenoss
	 * severity $Severity (Default: 2) is raised unde the name $Title with a custom
	 * message against the /Status class.
	 * 
	 * @param String $Title - The friendly name of the test
	 * @param String $Metric - The graphite metric being tested a.b.c.foo.bar
	 * @param int $Threshold - The maximum threshold of the metric
	 * @param int $Severity - What severity level to send to Zenoss
	 */
	private function CheckForMaxValues($Title, $Metric, $Threshold, $Severity = 2)
	{
		$MaxValue = null;
		//Any one metric can have up to 3 individual checks so we need to differentiate between them
		$StateTitle = $Title .'-max';

		if(!isset($this->MetricBundle[md5($Metric)]))
		{
			print("! Undefined index for Max check $Metric\r\n");		
			$this->SendAlert($Title, "Metric [ $Metric ] does not exist", $Severity, $Metric);
			return;
		}

		foreach($this->MetricBundle[md5($Metric)] as $Value)
		{
			// ignore None values
			if(trim($Value) === 'None')
				continue;
			
			if (is_null($MaxValue))
				$MaxValue = (int)$Value;
			else
				$MaxValue = max($MaxValue, (int)$Value);
		}

		
		if($MaxValue > $Threshold)
		{
			//Send Alert
			$this->SendMaxAlert($Title,$MaxValue,$Threshold,$Metric, $Severity);

			//Add to the state array (for later saving to state file)
			$this->Alerts[$StateTitle] = $this->Date;
		}
		else
		{
			//Send clear if we've previously alerted on this
			if(isset($this->OldAlerts[$StateTitle]) && !empty($this->OldAlerts[$StateTitle]))
			{
				$this->SendClear($Title);
			}
			else
			{
				//The check isn't down now and wasn't down earlier
				print(". $Title is OK ( under $MaxValue ) and hasn't been down previously\r\n");
			}
		}
	}

	/**
	 * Checks the metricbundle for the key $Metric and then evaluates the values against
	 * $Threshold. If any one value exceeds the threshold then an alert at Zenoss
	 * severity $Severity (Default: 2) is raised unde the name $Title with a custom
	 * message against the /Status class.
	 * 
	 * @param String $Title - The friendly name of the test
	 * @param String $Metric - The graphite metric being tested a.b.c.foo.bar
	 * @param int $Threshold - The minimum threshold of the metric
	 * @param int $Severity - What severity level to send to Zenoss
	 */
	function CheckForMinValues($Title, $Metric, $Threshold, $Severity = 2)
	{
		$MinValue = null;
		$StateTitle = $Title .'-min';

		if(!isset($this->MetricBundle[md5($Metric)]))
                {
                        print("! Undefined index for Min check $Metric\r\n");
						$this->SendAlert($Title, "Metric [ $Metric ] does not exist", $Severity, $Metric);
                        return;
                }
		

		foreach($this->MetricBundle[md5($Metric)] as $Value)
		{
			// ignore None values
			if(trim($Value) === 'None')
				continue;
			
			if (is_null($MinValue))
				$MinValue = (int)$Value;
			else
				$MinValue = min($MinValue, (int)$Value);			
		}

		
		if($MinValue < $Threshold)
		{
			//Send Alert
			$this->SendMinAlert($Title,$MinValue,$Threshold,$Metric, $Severity);

			//Add to the state array (for later saving to file)
			$this->Alerts[$StateTitle] = $this->Date;
		}
		else
		{
			//Send clear if we've previously alerted on this
			if(isset($this->OldAlerts[$StateTitle]) && !empty($this->OldAlerts[$StateTitle]))
			{
				$this->SendClear($Title);
			}
			else
			{
				//The check isn't down now and wasn't down earlier
				print(". $Title is OK ( above $MinValue ) and hasn't been down previously\r\n");
			}
		}
	}

	/**
	* Checks the metricbundle for the key $Metric and then evaluates the values against
	* $Threshold. If the Rate of Change [ROC] between the maximum value and the minimum 
	* exceeds the threshold then an alert at Zenoss severity $Severity (Default: 2) is 
	* raised unde the name $Title with a custom message against the /Status class.
	*
	* @param String $Title - The friendly name of the test
	* @param String $Metric - The graphite metric being tested a.b.c.foo.bar
	* @param int $Threshold - The maximum ROC difference threshold of the metric
	* @param int $Severity - What severity level to send to Zenoss
	*/
	private function CheckROCValues($Title, $Metric, $Threshold, $Severity = 2)
	{
		$MaxValue = null;
		$MinValue = null;
		$StateTitle = $Title .'-ROC';
		
		if(!isset($this->MetricBundle[md5($Metric)]))
                {
                        print("! Undefined index for ROC check $Metric\r\n");
						$this->SendAlert($Title, "Metric [ $Metric ] does not exist", $Severity, $Metric);
                        return;
                }

		
		foreach($this->MetricBundle[md5($Metric)] as $Value)
		{
			// ignore None values
			if(trim($Value) === 'None')
				continue;

			if (is_null($MaxValue))
				$MaxValue = (int)$Value;
			else
				$MaxValue = max($MaxValue, (int)$Value);

			if (is_null($MinValue))
				$MinValue = (int)$Value;
			else
				$MinValue = min($MinValue, $Value);
		}

		$Calc = (int)($MaxValue - $MinValue);
		if($Calc > $Threshold)
		{
			$this->SendROCAlert($Title,$Calc, $Threshold, $Metric, $Severity);
			$this->Alerts[$StateTitle] = $this->Date;
		}
		else
		{
			//Send clear if we've previously alerted on this
			if(isset($this->OldAlerts[$StateTitle]) && !empty($this->OldAlerts[$StateTitle]))
			{
				$this->SendClear($Title);
			}
			else
			{
				//The check isn't down now and wasn't down earlier
				print(". $Title is OK ( within $Calc ) and hasn't been down previously\r\n");
			}
		}
	}

	/**
	 * 
	 * Takes $GraphiteQueryString and appends it to GraphiteURL to create a GET
	 * request against Graphite.
	 * If a Graphite username and password were set in the credentials bundle then
	 * they are set too.
	 * 
	 * The resulting newline seperated strings are exploded into their component
	 * parts and then added to the MetricBundle array keyed by the MetricName
	 *  
	 * @param String $GraphiteQueryString
	 */
	private function MakeGraphiteRequest($GraphiteQueryString)
	{
		$URL = $this->GraphiteURL . $GraphiteQueryString;

		//print($URL);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $URL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Graphite to Zenoss');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if(isset($this->GraphiteUserName) && !empty($this->GraphiteUserName)  && isset($this->GraphitePassword) && !empty($this->GraphitePassword))
		curl_setopt($ch, CURLOPT_USERPWD, $this->GraphiteUserName . ":" . $this->GraphitePassword);

		$output = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code != 200) {
			echo "! Failed graphite request with HTTP code $code\n";
			$this->SendGraphiteFailAlert();
			exit();
		}

		$ArrayTest = explode("\n",$output);
		if(isset($ArrayTest[1]) && !empty($ArrayTest[1]))
		{
			$output = array();
			foreach($ArrayTest as $Line)
			{
				if ($Line === '') {
					// ignore blank lines
					continue;
				}
				if (preg_match('/^(.*),\d+,\d+,(\d+)\|(.*)/',$Line, $Matches)) {
					$MetricIdentifier = $Matches[1];
					$BucketSize = $Matches[2];
					
					//Calculate number of metrics we should keep so we only consider the last X seconds
					$MetricsKeep = $this->MonitorWindow / $BucketSize;

					if (isset($output[$MetricIdentifier])) {
						echo "! Metric returns multiple values for identifier $MetricIdentifier\n";
					}
	
					$output[$MetricIdentifier] = array_slice(explode(',', $Matches[3]), -$MetricsKeep);
				} else {
					echo "! Failed to parse line from Graphite: '$Line'\n";
				}
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

	/**
	 * 
	 * Sends an alert to Zenoss with a custom 'over maximum' status
	 * message at the specified severity (or 2 if not set)
	 * 
	 * @param String $Title - Friendly name of the metric / check
	 * @param int $Value - The value that caused the check to trip
	 * @param int $Trip - The Threshold value
	 * @param String $Metric - The graphite Metric URI
	 * @param int $Severity - The severity at which this alert will be raised at within Zenoss
	 */
	private function SendMaxAlert($Title, $Value, $Trip, $Metric, $Severity = 2)
	{
		print("> Sending an alert that $Title ($Metric) is over $Trip at $Value\r\n");
		$Summary = "$Title is over its threshold: " . number_format($Value) . ' / ' . number_format($Trip);
		$this->SendAlert($Title,"$Summary\r\n$Metric",$Severity, $Metric, $Trip, $Summary);
		return 0;
	}

	/**
	*
	* Sends an alert to Zenoss with a custom 'under minimum' status
	* message at the specified severity (or 2 if not set)
	*
	* @param String $Title - Friendly name of the metric / check
	* @param int $Value - The value that caused the check to trip
	* @param int $Trip - The Threshold value
	* @param String $Metric - The graphite Metric URI
	* @param int $Severity - The severity at which this alert will be raised at within Zenoss
	*/
	private function SendMinAlert($Title, $Value, $Trip, $Metric, $Severity = 2)
	{
		print("< Sending an alert that $Title ($Metric) is under $Trip ($Value)\r\n");
		$Summary = "$Title is under its threshold: " . number_format($Value) . ' / ' . number_format($Trip);
		$this->SendAlert($Title,"$Summary [$Metric]",$Severity, $Metric, $Trip, $Summary);
		return 0;
	}

	/**
	*
	* Sends an alert to Zenoss with a custom outside ROC' status
	* message at the specified severity (or 2 if not set)
	*
	* @param String $Title - Friendly name of the metric / check
	* @param int $Value - The value that caused the check to trip
	* @param int $Trip - The Threshold value
	* @param String $Metric - The graphite Metric URI
	* @param int $Severity - The severity at which this alert will be raised at within Zenoss
	*/
	private function SendROCAlert($Title, $Value, $Trip, $Metric, $Severity = 2)
	{
		print("R Sending an alert that $Title ($Metric) is outside of $Trip ($Value)\r\n");
		$Summary = "The ROC of $Title is outside its threshold: " . number_format($Value) . ' / ' . number_format($Trip);
		$this->SendAlert($Title,"$Summary [$Metric]",$Severity, $Metric, $Trip, $Summary);
		return 0;
	}

	/**
	 * 
	 * Raises a 'Critical' level alert in Zenoss indicating that a metric is reporting
	 * no values (usually an indication that the service in question is dead therefore
	 * incapable of reporting values)
	 * 
	 * @param String $Title - Friendly name of the metric / check
	 * @param String $Metric - The graphite Metric URI
	 * @param int $NoneCounter Number of 'None' responses encountered
	 */
	private function SendNoneAlert($Title, $Metric, $NoneCounter, $Severity = 5)
	{
		print("N Sending an alert that $Title ($Metric) is reporting too many 'None' values: $NoneCounter\r\n");
		$Summary = "$Title is reporting too many 'None' values: $NoneCounter";
		$this->SendAlert($Title,"$Summary [$Metric]", $Severity, $Metric, null, $Summary);
		return 0;
	}

	/**
	 * 
	 * Raises a 'Critical' level alert in Zenoss indicating that this script was
	 * unable to contact Graphite at all
	 */
	private function SendGraphiteFailAlert()
	{
		print("! Sending an alert that the CURL request to Graphite failed too many times\r\n");
		$this->SendAlert("GraphiteZenossBridge","The CURL requests to Graphite are failing!", 5, $Metric); //Always 5 no matter what
		return 0;
	}

	/**
	 * 
	 * 'Clears' an alert from Zenoss
	 * @param String $Title - Friendly name of the metric / check
	 */
	private function SendClear($Title)
	{
		print("C Clearing $Title\r\n");
		$this->SendAlert($Title,"Clearing $Title",0);
		return 0;
	}

	/**
	 * 
	 * Actually sends the alert to Zenoss via CURL using data generated by the earlier SendXXXXAlert() functions
	 * 
	 * @param String $Component - Friendly name of the metric / check (turned into the Zenoss Component that has failed)
	 * @param String $Message - The custom message dictated by one of the other SendXXXXAlert() functions above
	 * @param int $Severity - THe Zenoss Severity
	 * @param String $Device - Allows a check to override what Device this alert is raised against [ unused - defaults to Graphite ]
	 */
	private function SendAlert($Component, $Message, $Severity, $Metric = '', $Trip = null, $Summary = null)
	{
		// if summary was not specifed set it to message, but before we (may) add HTML tags below for $Metric to message
		if (is_null($Summary)) {
			$Summary = $Message;
		}

		if(!empty($Metric)) {
			if (is_null($Trip))
				$Trip = 10;

			$url = $this->GraphiteURLVanity . "/render/?target=$Metric&target=alias(threshold($Trip),\"Threshold\")&height=300&width=500&from=-2hours";
			$Message .= "\r\n<br /><img src='$url' />";
			$Message .= "\r\n<br /><a href='$url' target='_blank'>$url</a>";
		}

		$Message = urlencode($Message);
		$Summary = urlencode($Summary);
		
		$Severity = (int)$Severity;
		$Component = urlencode($Component);
		$Device = 'Graphite';
		
		//Old style
		$URL = "https://". $this->ZenossUserName .":".$this->ZenossPassword."@".str_replace('http://','',$this->ZenossURL)."/zport/dmd/ZenEventManager/manage_addEvent?device=$Device&component=$Component&summary=$Summary&message=$Message&severity=$Severity&eventClass=".urlencode($this->ZenossEventClass)."&eventKey=GraphiteZenossBridge";
		//print("\t\t$URL\r\n");
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $URL);
		curl_setopt($ch, CURLOPT_HEADER,0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $this->ZenossUserName . ":" . $this->ZenossPassword);
		$data = curl_exec($ch);
		//print($data);
		curl_close($ch);
		
		/*
		//New Style
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);;
		$this->AuthWithZenoss($ch);
		$this->SendZenossAlert($ch);
		
		curl_close($ch);*/
	}
	
	/**
	 * 
	 * Ignore me - I'm not here - stop it
	 * stop reading this
	 * I won't tell you nuffin'
	 * @param unknown_type $ch
	 */
	private function AuthWithZenoss($ch)
	{
		$AuthDetails = array('__ac_name' => $this->ZenossUserName, 
							'__ac_password' => $this->ZenossPassword, 
							'submitted' => 'true', 
							'came_from' => $this->ZenossURL .'/zport/dmd');
		
		print_r($AuthDetails);
		
		curl_setopt($ch, CURLOPT_URL, $this->ZenossURL . '/zport/acl_users/cookieAuthHelper/login');
		curl_setopt($ch,CURLOPT_POST,true);
		curl_setopt($ch,CURLOPT_POSTFIELDS,$AuthDetails);
		
		$data = curl_exec($ch);
		print_r($data);
		print("----- \r\n\r\n-----");
		print_r($ch);
	}
	
	/**
	 * 
	 * WOOP WOOP...that's the sound of da police...
	 * @param unknown_type $ch
	 */
	private function SendZenossAlert($ch)
	{
		//{"action":"EventsRouter","method":"add_event","data":[{"summary":"SummaryTest","device":"DeviceTest","component":"ComponentTest","severity":"Critical","evclasskey":"","evclass":""}],"type":"rpc","tid":470}
	}
}
