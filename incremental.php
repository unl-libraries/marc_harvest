<?php

ini_set('memory_limit', -1);

$autoloader = include_once("vendor/autoload.php");

if ( ! $autoloader ) 
{
	echo "\n\n  vendor/autoload.php could not be found. Did you run `php composer.phar install`?\n\n"; exit;
}

//read in the configuration file
$filepath = "harvest.cfg";
$parsed_ini = parse_ini_file($filepath,true);

$sierra_info = $parsed_ini["sierra"];
$location = $parsed_ini["catalog"]["location"];
//$timestamp = time() - (2*24*60*60); // yesterday
$timestamp = $parsed_ini["catalog"]["last_harvested"];
$sierra = new Sierra($sierra_info['host'], $sierra_info['user'], $sierra_info['password']);


if ( !$timestamp || $timestamp==''){
	//full load
	echo "Running full export of marc files as no date specified\n";
	$new_timestamp = time();
	//removed the old include_options array from export functions.  No need to supply the record type here, as the default is 'bib'
	$results = $sierra->exportRecords($location);	
	
}
else{
	echo "Running incremental load to retrieve records after ".$timestamp."\n";	
	//convert to timestamp 
	$contimestamp = strtotime($timestamp);
	echo "Converted timestamp from $timestamp to $contimestamp which is ".date("Y-m-d\TH:i",$contimestamp)."\n";
	//removed the old include_options array from export functions. No need to supply the record type here, as the default is 'bib'
	$results = $sierra->exportRecordsModifiedAfter($contimestamp, $location);	
	$new_timestamp = time();	
}
//we should only update the timestamp if the load was successful
//if ($results){
	$new_timestamp=date("Y-m-d\TH:i",$new_timestamp);
	if (update_cfg($parsed_ini, $filepath, $new_timestamp)) echo "\nWrote last date harvested to file: ". $new_timestamp;
	echo "\nPlacing results in $location\n";
//}
// else{
// 	echo "\nError retrieving records\n";
// }

function update_cfg($parsed_data,$filepath,$timestamp){
	$content = "";
	foreach($parsed_data as $section=>$values){
		//append the section
		$content .= "[".$section."]\n";		
		//append the values
		foreach($values as $key=>$value){
			if (($section=='catalog') &&($key=='last_harvested')) $content .= $key."=".$timestamp."\n";
			else $content .= $key."=".$value."\n";
		}
	}
	
	//write it into file
	if (!$handle = fopen($filepath, 'w')) {
		return false;
	}
	
	$success = fwrite($handle, $content);
	fclose($handle);
	
	return $success;
}
