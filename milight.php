<?php
/*
PHP Class to communicate with the MiLight Wifi iBox Controller / iBox1 and iBox2 / v6
It is loosely based on the Python script by Strontvlieg: https://github.com/Strontvlieg/miLight-V6-iBox2-Domoticz

Compatible with the RGB+CCT bulbs / spots / strips and the LED of the iBox1 controller

Requires PHP 5.6 or higher

(c) 2019 Bas van der Sluis


Usage example:
$oMiLight = new milight();
$oMiLight->changeLight(["color" => "265", "intensity" => 70], 2);

Possible array key/pair values:
color:
0 = red
85 = green
170 = blue
255 = red
256 = 100% warm white
356 = 100% cold white

intensity:
from 0 (very dim)
to 100 (full brightness)

saturation (applies only to colors):
0 = mix no white
100 = mix 100% white

special:
on = turn lights on
off = turn lights off
night = turn on night mode
speedup = speed up (for disco modes)
speeddown = speed down (for disco modes)

disco:
1 to 9

*/

class milight
{
	// the IP-address of your iBox2
	private $sHost = "192.168.1.30";

	// only change these if you changed them yourself in the iBox
	private $sPortSend = 5987;
	private $sPortReceive = 55054;

	// the amount to add to fix the "real" hue of the lights (mine were 10 points off)
	private $iHueShift = 10;

	// internal variables - do not edit
	private $iLastCommand = 0;
	private $sSessionID1 = "";
	private $sSessionID2 = "";
	private $oSocket = null;

	/**
	 * Change the light color, intensity, mode, etc. for linked LED lamps (not the iBox1 lamp)
	 *
	 * @param array $aInput Key/pair with mode/type and value
	 * @param int $iZone 0 = all, or 1 to 4
	 */
	public function changeLight($aInput, $iZone)
	{
		$aMessages = [];
		foreach($aInput as $sType => $mValue)
		{
			switch($sType)
			{
				case "color":
					if($mValue <= 255)
					{
						// hue color
						if($this->iHueShift)
						{
							$mValue += $this->iHueShift;
							if($mValue > 255) $mValue -= 255;
							if($mValue < 0) $mValue += 255;
						}
						$aMessages[] = "31 00 00 08 01".str_repeat(" ".str_pad(dechex($mValue), 2, "0", STR_PAD_LEFT), 4);
					}
					else
					{
						// warm white
						$mValue -= 255;
						if($mValue > 100) $mValue = 100;

						$aMessages[] = "31 00 00 08 05 ".str_pad(dechex($mValue), 2, "0", STR_PAD_LEFT)." 00 00 00";
					}
					break(1);
				case "saturation":
					$aMessages[] = "31 00 00 08 02 ".str_pad(dechex($mValue), 2, "0", STR_PAD_LEFT)." 00 00 00";
					break(1);
				case "intensity":
					if($mValue > 100) $mValue = 100;
					$aMessages[] = "31 00 00 08 03".str_repeat(" ".str_pad(dechex($mValue), 2, "0", STR_PAD_LEFT), 4);
					break(1);
				case "special":
					$aModes = [
						"on"        => "04 01",
						"off"       => "04 02",
						"night"     => "04 05",
						"speedup"   => "04 03",
						"speeddown" => "04 04"
					];
					// would have loved to use the null coalesce operator here, but wanted this script
					// to be compatible with php 5.6
					if(isset($aModes[$mValue]) == false) continue;
					$sCode = $aModes[$mValue];
					$aMessages[] = "31 00 00 08 ".$sCode." 00 00 00";
					break(1);
				case "disco":
					if($mValue < 1 || $mValue > 9) continue;
					$aMessages[] = "31 00 00 08 06 ".str_pad(dechex($mValue), 2, "0", STR_PAD_LEFT)." 00 00 00";
					break;
			}
		}

		$aSessionIDs = $this->sendMessages($aMessages, str_pad($iZone, 2, "0", STR_PAD_LEFT));
	}


	/**
	 * Change the light color, intensity, mode, etc. for the INTERNAL iBox1 lamp ONLY
	 *
	 * @param string $sMode Mode to set on the lamp, only disco, color and intensity require the $iValue parameter
	 * @param int $iValue value for disco (1-9), color (1-255) or intensity (0-100)
	 */
	public function changeIBoxLight($sMode, $iValue = 0)
	{
		$aModeCodes = [
			"on"        => "03",
			"off"       => "04",
			"speedup"   => "02",
			"speeddown" => "01",
			"white"     => "05"
		];

		switch($sMode)
		{
			case "on":
			case "off":
			case "speedup":
			case "speeddown":
			case "white":
				$this->sendMessages(["31 00 00 00 03 ".$aModeCodes[$sMode]." 00 00 00"], "00");
				break;
			case "disco":
				$this->sendMessages(["31 00 00 00 04 ".str_pad($iValue, 2, "0", STR_PAD_LEFT)." 00 00 00"], "00");
				break;
			case "color":
				$this->sendMessages(["31 00 00 00 01".str_repeat(" ".str_pad(dechex($iValue), 2, "0", STR_PAD_LEFT), 4)], "00");
				break;
			case "intensity":
				$this->sendMessages(["31 00 00 00 02 ".str_pad(dechex($iValue), 2, "0", STR_PAD_LEFT)." 00 00 00"], "00");
				break;
		}
	}


	/**
	 * Method to change the IP-address of the MiLight-controller (in case you have more controllers)
	 *
	 * @param string $sIP ip-address of the controller
	 */
	public function setController($sIP)
	{
		if($this->oSocket) socket_close($this->oSocket);
		$this->sSessionID1 = $this->sSessionID2 = "";

		$this->sHost = $sIP;
	}


	/**
	 * Links a new light bulb to the Wifi iBox Controller and sets it to the specified zone
	 * Use this function within 3 seconds after the light has switched on
	 *
	 * @param int $iZone 0 = all, or 1 to 4
	 */
	public function linkBulb($iZone)
	{
		$this->sendMessages("3D 00 00 08 00 00 00 00 00", str_pad($iZone, 2, "0", STR_PAD_LEFT));
	}


	/**
	 * Unlinks all linked controllers and/or remotes paired on this zone from the light just switched on
	 * Use this function within 3 seconds after the light has switched on
	 *
	 * @param int $iZone 0 = all, or 1 to 4
	 */
	public function unlinkBulb($iZone)
	{
		$this->sendMessages("3E 00 00 08 00 00 00 00 00", str_pad($iZone, 2, "0", STR_PAD_LEFT));
	}


	/**
	 * Sends a list (array) of commands to the wifi box.
	 *
	 * @param array $aCommands Array of commands. If it's a string, a single command will be assumed
	 * @param string $sZone 00, 01, 02, 03 or 04
	 */
	private function sendMessages($aCommands, $sZone)
	{
		if(empty($aCommands)) return false;

		if($this->iLastCommand == 0 || (time() - 55) > $this->iLastCommand) $this->sSessionID1 = $this->sSessionID2 = "";

		$this->iLastCommand = time();

		if(!$this->sSessionID1 && !$this->sSessionID2)
		{
			$this->oSocket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

			socket_set_option($this->oSocket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 1, "usec" => 0]);
			socket_set_option($this->oSocket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 1, "usec" => 0]);

			// kept the spaces for readability
			$sHexSessionInit = "20 00 00 00 16 02 62 3A D5 ED A3 01 AE 08 2D 46 61 41 A7 F6 DC AF D3 E6 00 00 1E";
			$sMessage = pack("H*" , str_replace(" ", "", $sHexSessionInit));

			socket_sendto($this->oSocket, $sMessage, strlen($sMessage), 0, $this->sHost, $this->sPortSend);

			socket_recvfrom($this->oSocket, $sOutput, 1024, 0, $this->sHost, $this->sPortReceive);

			$aSessionMessage = unpack("H*", $sOutput);
			$sSessionMessage = $aSessionMessage[1];

			$this->sSessionID1 = substr($sSessionMessage, 38, 2);
			$this->sSessionID2 = substr($sSessionMessage, 40, 2);
		}

		if(is_string($aCommands)) $aCommands = [$aCommands];

		foreach($aCommands as $sBulbCommand)
		{
			// it is never nessesary to increase the cycle number, not even when the sending failed
			$sCycleNr = "00";
			$sChecksum = $this->calcChecksum($sBulbCommand, $sZone);

			$sFullCommand = "80 00 00 00 11 ".$this->sSessionID1." ".$this->sSessionID2." 00 ".$sCycleNr." 00 ".$sBulbCommand." ".$sZone." 00 ".$sChecksum;

			$sMessage = pack("H*" , str_replace(" ", "", $sFullCommand));
			socket_sendto($this->oSocket, $sMessage, strlen($sMessage), 0, $this->sHost, $this->sPortSend);

			// receive the output, but ignore it for now
			socket_recvfrom($this->oSocket, $sOutput, 1024, 0, $this->sHost, $this->sPortReceive);
		}

		//socket_close($this->oSocket);

		return [$this->sSessionID1, $this->sSessionID2];
	}


	/**
	 * Calculates the checksum for a command
	 *
	 * @param string $sBulbCommand String with command for the bulbs (hex, space seperated)
	 * @param string $sZone 00, 01, 02, 03 or 04
	 */
	private function calcChecksum($sBulbCommand, $sZone)
	{
		$sFullString = $sBulbCommand." ".$sZone." 00";

		$aFull = explode(" ", $sFullString);
		$iTotal = 0;
		foreach($aFull as $sPart)
		{
			$iTotal += hexdec($sPart);
		}

		$sChecksum = dechex($iTotal);
		if(strlen($sChecksum) > 2)
			return substr(dechex($iTotal), 1);
		else
			return $sChecksum;
	}
}
