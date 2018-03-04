#!/usr/bin/php
<?php  
// This php program reads data from a growatt inverter
// 
// Thanks to Lennart Kuhlmeier for providing PVOUT_GROWATT.PY on http://www.sisand.dk/?page_id=139 
//

date_default_timezone_set ("Europe/Amsterdam");


include(realpath(dirname(__FILE__))."/../PHP-Serial/src/PhpSerial.php");
require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");

$serialdevice = "/dev/ttyUSB1";
$server = "192.168.2.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid("opentherm_");; // make sure this is unique for connecting to sever - you could use uniqid()
$mqttTopicPrefix = "home/opentherm/";

echo ("Opentherm MQTT gateway started...\n");

$iniarray = parse_ini_file("openthermMQTT.ini",true);
if (($tmp = $iniarray["opentherm"]["serialdevice"]) != "") $serialdevice = $tmp;
if (($tmp = $iniarray["opentherm"]["mqttserver"]) != "") $server = $tmp;
if (($tmp = $iniarray["opentherm"]["mqttport"]) != "") $tcpport = $tmp;
if (($tmp = $iniarray["opentherm"]["mqttusername"]) != "") $username = $tmp;
if (($tmp = $iniarray["opentherm"]["mqttpassword"]) != "") $password = $tmp;


$mqtt = new phpMQTT($server, $port, $client_id);
$mqtt->connect(true, NULL, $username, $password);


$openthermdata["opentherm"]  = array();

exec ('stty -F '.$serialdevice.'  1:0:8bd:0:3:1c:7f:15:4:5:1:0:11:13:1a:0:12:f:17:16:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0');

$serial = new PhpSerial;

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$sendtimer = 0;
$dataready = 0;
$message=""; 
$buienradartime = 0;
while(1)
{
 if ($serial->_dState != SERIAL_DEVICE_OPENED)
 {
   echo "Opening Serial Port '".$serialdevice."'...\n";

   // First we must specify the device. This works on both linux and windows (if
   // your linux serial device is /dev/ttyS0 for COM1, etc)
   $serial->deviceSet($serialdevice);

   // We can change the baud rate, parity, length, stop bits, flow control
   $serial->confBaudRate(9600);
   $serial->confParity("none");
   $serial->confCharacterLength(8);
   $serial->confStopBits(1);
   $serial->confFlowControl("none");
   
   if (!$serial->deviceOpen())
   {
     echo ("Serial Port could not be opened...\n");
   }
   else
   {
    echo "Opened Serial Port.\n";
    $serial->sendMessage("\r\nAA=28\r\n"); 
    }
 }

        $readmask = array();
        array_push($readmask, $serial->_dHandle);
        $writemask = NULL;
        $errormask = NULL;
        $nroffd = stream_select($readmask, $writemask, $errormask, 1);
        
        $mqtt->proc();

        foreach ($readmask as $i) 
        {
           if ($i == $serial->_dHandle)
           {
 
              $message .= $serial->readPort();
              
              if (strlen($message) > 0) 
              {
               while (strpos($message, "\r\n") !== FALSE)
               { 
                // Filter first message from serial data
                //$messages = explode("\r\n", $message);
                //$firstmessage = $messages[0];
                $firstmessage = strtok ($message, "\r\n");
                // Remove first message from serial data
                $message = substr($message, strlen($firstmessage) + 2);
                echo ("Message='".$firstmessage."'\n");
                
                $data = array();
                
                
                                
                // Check for messsage from boiler
                if ($firstmessage[0] == "B")
                {
                 switch (hexdec($firstmessage[3].$firstmessage[4]))
                 {
                   case 14: 
                    publishmqtt("burner/modulation/maxlevel", floatvalue($firstmessage));
                   break;
                   case 17: 
                    publishmqtt("burner/modulation/level", floatvalue($firstmessage));
                   break;
                   case 116: 
                    publishmqtt("burner/starts", uintvalue($firstmessage));
                   break;
                   case 120: 
                    publishmqtt("burner/hours", uintvalue($firstmessage));
                   break;
                   case 19: 
                    publishmqtt("dhw/flowrate", floatvalue($firstmessage));
                   break;
                   case 26: 
                     publishmqtt("dhw/temperature", floatvalue($firstmessage));
                   break;
                   case 118: 
                    publishmqtt("dhw/pump/starts", uintvalue($firstmessage));
                   break;
                   case 122: 
                    publishmqtt("dhw/pump/hours", uintvalue($firstmessage));
                   break;
                   case 119: 
                    publishmqtt("dhw/burner/starts", uintvalue($firstmessage));
                   break;
                   case 123: 
                    publishmqtt("dhw/burner/hours", uintvalue($firstmessage));
                   break;
                   case 25:
                    publishmqtt("boiler/temperature", floatvalue($firstmessage));
                   break;
                   case 18: 
                    publishmqtt("ch/water/pressure", floatvalue($firstmessage));
                   break; 
                   case 117: 
                    publishmqtt("ch/pump/starts", uintvalue($firstmessage));
                   break; 
                   case 121: 
                    publishmqtt("ch/pump/hours", uintvalue($firstmessage));
                   break; 
                   case 19: 
                    publishmqtt("dhw/flowrate", floatvalue($firstmessage));
                   break; 
                   case 56: 
                    publishmqtt("dhw/setpoint", floatvalue($firstmessage));
                   break; 
                   case 57: 
                    publishmqtt("ch/water/maxsetpoint", floatvalue($firstmessage));
                   break; 
                   case 28: 
                    publishmqtt("ch/water/returntemperature", floatvalue($firstmessage));
                   break;
                   case 27: 
                    publishmqtt("outside/temperature", floatvalue($firstmessage));
                   break;
                   case 33:
                    publishmqtt("exhausttemperature", intvalue($firstmessage));
                   break;
                 }
                }
  
                // Check for message from Thermostat              
                if ($firstmessage[0] == "T")
                {
                 if (0 === strpos($firstmessage, 'TT: ')) 
                 {
                   $data["opentherm"]["thermostat"]["setpoint"] = substr($firstmessage, 4);
                 }
                 else
                 {
                  switch (hexdec($firstmessage[3].$firstmessage[4]))
                  {
                   case 1: 
                    publishmqtt("thermostat/ch/water/setpoint", floatvalue($firstmessage));
                   break;
                   case 16: 
                    publishmqtt("thermostat/setpoint", floatvalue($firstmessage));
                   break;
                   case 24: 
                    publishmqtt("thermostat/temperature", floatvalue($firstmessage));
                   break;
                  }
                 }
                }
               }
              }
            }
            else
            {
                $sock_data = fread($i, 1024);
                if (strlen($sock_data) === 0) { // connection closed
                } else if ($sock_data === FALSE) { //socket error
                    fclose($i);
                } else {
              }
            }
          }

              if ($buienradartime <= time())
              {
               $buienradartime = time() + 600; // Next update in 10 minutes
               $url = "https://xml.buienradar.nl";
               $xml = simplexml_load_file($url);
               foreach($xml->weergegevens->actueel_weer->weerstations->weerstation as $weer)
               {
                  if ($weer->stationcode == "6370")
                  {
                    //$data["opentherm"]["outside"]["temperature"] = (string)$weer->temperatuurGC;
                    echo ("Buienradar outsidetemp=".(string)$weer->temperatuurGC."\n");
                    //$msg = array();
                    //$msg["opentherm"]["outside"]["temperature"] = $data["opentherm"]["outside"]["temperature"];
                    //sendToAllTcpSocketClients($tcpsocketClients, json_encode($msg)."\n\n");
                    $serial->sendMessage("OT=".(string)$weer->temperatuurGC."\r\n"); 
                    break;
                  } 
               }
              }

           
}

$serial->deviceClose();
exit(1);

function publishmqtt ($topic, $msg)
{
        global $mqtt;
        global $mqttTopicPrefix;
        echo ($topic.": ".$msg."\n");
        $mqtt->publishwhenchanged($mqttTopicPrefix.$topic,$msg,0,1);
}


function twobytestosignedfloat($decimal, $fractional)
{
  return (($decimal & 127)  +
    (($fractional&128) ? 1/2 : 0) +
      (($fractional&64) ? 1/4 : 0) +
        (($fractional&32) ? 1/8 : 0) +
          (($fractional&16) ? 1/16 : 0) +
            (($fractional&8) ? 1/32 : 0) +
              (($fractional&4) ? 1/64 : 0) +
                (($fractional&2) ? 1/128 : 0) +
                  (($fractional&1) ? 1/265 : 0)) * (($decimal & 128) ? -1 : 1);
                  }
                  
                  

function floatvalue ($firstmessage)
{
 return round(twobytestosignedfloat(hexdec($firstmessage[5].$firstmessage[6]), hexdec($firstmessage[7].$firstmessage[8])),1);
}

function uintvalue ($firstmessage)
{
 return hexdec($firstmessage[5].$firstmessage[6]) << 8 | hexdec($firstmessage[7].$firstmessage[8]);
}

function intvalue ($firstmessage)
{
 return (hexdec($firstmessage[5].$firstmessage[6]) & 0x127) << 8 | hexdec($firstmessage[7].$firstmessage[8]) * (hexdec($firstmessage[5].$firstmessage[6])&0x128 ? -1 : 1);
}
?>  

