<?php
 /**
 * interface/maxemail/get_fax.php Obtains incoming fax information from maxemail server and stores in DB.
 *
 * Copyright (C) 2012 Medical Information Integration <info@mi-squared.com>
 *
 * LICENSE: This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://opensource.org/licenses/gpl-license.php>;.
 *
 * @package efax
 * @author  Visolve <services@visolve.com>
 * @link    http://www.mi-squared.com
 */
$ignoreAuth=true;

require_once __DIR__.'/bootstrap.php';
require_once("interface/globals.php");
require_once("$srcdir/sql.inc");
require_once 'BackgroundProcess.php';

use Cocur\BackgroundProcess\BackgroundProcess;

function get_param( $key )
{
    $value = '';
    $found = false;
    // Check Post
    if ( !empty( $_POST[$key] ) ) {
        $value = $_POST[$key];
        $found = true;
    }

    if (!$found) {
        if ( !empty( $_GET[$key] ) ) {
            $value = $_GET[$key];
            $found = true;
        }
    }

    return $value;
}

error_log( 'incoming message from'.get_param('m') );

//Get the parameters sent by the maxemail server
$message_id = get_param('m');
$sep_mesg_id=explode('-',$message_id);
$to = empty($sep_mesg_id[0]) ? '--' : $sep_mesg_id[0];
$fax_id = empty($sep_mesg_id[1]) ? '--' : $sep_mesg_id[1];
$time = get_param('t');
$date = date('Y-m-d H:i:s',$time);
$page_count = get_param('p');
$sender_id = get_param('tsi');
$download_link = get_param('u');

$file_parts=explode(".",$download_link);
$no_parts=count($file_parts);
$ext=$file_parts[$no_parts-1];

if($ext == 'tiff' || $ext == 'tif'){
	$download_flag = 1;
} else if ( $ext == 'jpeg' || $ext == 'jpg' ) {
    $download_flag = 1;
}else{
	$download_flag = 1;
}

//For validation of Link
$validate_msg_id = str_replace("-","-",$message_id,$cnt);
$urlregex = "/^(http|https|ftp):\/\/([A-Z0-9][A-Z0-9_-]*(?:\.[A-Z0-9][A-Z0-9_-]*)+):?(\d+)?\/?/i";

//Insert a row for retrieved fax in table 'maxemail'
if ( $message_id == '' || 
    $cnt!=1 || strlen($to) != 10 || strlen($fax_id) != 8 || 
    !preg_match($urlregex, $download_link) || 
    !is_numeric($to) || !is_numeric($time) || strstr($to,".") != FALSE || 
    strstr($time,".") != FALSE || strstr($page_count,".") != FALSE || 
    !is_numeric($page_count) || $sep_mesg_id == '' || $to == '' || 
    $fax_id == '' || $time == '' || $date == '' || $page_count == '' || 
    $download_link == '' ||  $download_flag!=1 ) {
    
	error_log("Error: Please provide proper values to the parameters\n");

	if($cnt != 1){
	   error_log("Invalid Message ID\n");
	}
	if(strlen($fax_id)!=8){
	   error_log("Invalid Fax ID\n");
	}
	if(is_numeric($to)!=1 || strstr($to,".")!=FALSE || strlen($to)!=10){
	   error_log("Invalid MaxEmail Number\n");
	}
	if(!is_numeric($time) || strstr($time,".")!=FALSE){
	   error_log("Invalid Time\n");
	}
	if(!is_numeric($page_count) || strstr($page_count,".")!=FALSE){
	   error_log("Invalid Page Count\n");
	}
	if (!preg_match($urlregex, $download_link)){
	   error_log("URL is not valid\n");
	}
	
	exit();
}

/*
 * Here is the code for XML Parser
 * based on PHP's built-in functionality
 * */

$toBeParsedXml = $_POST['xml']; // expecting the xml param in a $_POST
if (!empty($toBeParsedXml)) {

    $parsed = simplexml_load_string($toBeParsedXml);
    $toBeUsed = array(
        'fax_id' => '',
        'status' => '',
        'received_date' => '',
        'page_count' => '',
        'to' => '',
        'sender_id' => '',
        'download_link' => '',
        'read' => '',
        'patient_id' => '',
        'archived_id' => ''
    );
    // traversing through the tree
    // 1st level of tree
    foreach($parsed as $key_lvl1 => $value_lvl1) {
        // 2nd level of tree: fax_id, status, page_count, received_date Fields
        foreach($value_lvl1 as $key_lvl2 => $value_lvl2) {
            if($key_lvl2 == 'MCFID' OR $key_lvl2 == 'Status' OR $key_lvl2 == 'PageCount' OR $key_lvl2 == 'DateReceived') {
                switch ($key_lvl2) {
                    case 'MCFID':
                        $toBeUsed['fax_id'] = strval($value_lvl2);
                        break;
                    case 'Status':
                        $toBeUsed['status'] = strval($value_lvl2);
                        break;
                    case 'PageCount':;
                        $toBeUsed['page_count'] = strval($value_lvl2);
                        break;
                    case 'DateReceived':
                        $toBeUsed['received_date'] = strval($value_lvl2);
                        break;
                }
            }
            // 3rd level of tree
            foreach($value_lvl2 as $key_lvl3 => $value_lvl3) {
                // 4th level of tree
                foreach($value_lvl3 as $key_lvl4 => $value_lvl4) {

                    $triggered = false;
                    $field = '';
                    // 5th level of tree: Custom User Fields: ?? This part has to be clarified
                    foreach($value_lvl4 as $key_lvl5 => $value_lvl5) {

                        if ($triggered) {
                            switch($field) {
                                case 'Customer Name':
                                    $toBeUsed['patient_id'] = strval($value_lvl5);
                                    break;
                                case 'PIN Number':
                                    $toBeUsed['archived_id'] = strval($value_lvl5);
                                    break;
                                default:
                                    break;
                            }
                            $triggered = false;
                        }

                        if ($value_lvl5 == 'Customer Name' OR $value_lvl5 == 'PIN Number') {
                            $triggered = true;
                            $field = $value_lvl5;
                        }
                    }
                }
            }
        }
    }

} else{
    error_log("Xml info to be parsed is not provided\n");
}
$qstring = "INSERT INTO maxemail ( `to`, `fax_id`, `received_date`, `page_count`, `sender_id`, `download_link`, `read`, `patient_id`, `archived`, `status` ) ";
$qstring .= "VALUES( ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )";
// next string can replace the old approach
// $result = sqlInsert( $qstring, array( $toBeUsed['to'], $toBeUsed['fax_id'], $toBeUsed['received_date'], $toBeUsed['page_count'], $toBeUsed['sender_id'], $toBeUsed['download_link'], 0, 0, 0, 0 ) );

$result = sqlInsert( $qstring, array( $to, $fax_id, $date, $page_count, $sender_id, $download_link, 0, 0, 0, 0 ) );

// As long as we inserted, and download link is valid, start the download
// next string can replace the old approach
// if ( $result && preg_match($urlregex, $toBeUsed['download_link'])
if ( $result && preg_match($urlregex, $download_link)
) {
    
    // Download the fax in a background process
    $cwd = __DIR__;
    $php = 'php';
    //$php = '/Applications/MAMP/bin/php/php5.4.10/bin/php';
    // next string can replace the old one approach
    // $backgroundProcess = new BackgroundProcess("$php $cwd/DownloadFax.php $result $toBeUsed['download_link']");
    $backgroundProcess = new BackgroundProcess("$php $cwd/DownloadFax.php $result $download_link");
    $backgroundProcess->run('/tmp/maxemail.log');
} else {
    error_log("Error inserting values into Database\n");
}

// Always return success
echo "success";

?>