<?php
	
	$fakeSite = "http://www.google.com";
	
	if ($_SERVER['HTTP_USER_AGENT'] != "HEADNode" || (!(isset($_POST["fileID"] && isset($_POST["part"])) || !(isset($_POST["part"]) && isset($_POST["file"]) && isset($_POST[$_POST["file"]])))) {
		die(file_get_contents($fakeSite));
	}
	
	$rootDir = "./files/";
	
	if (!is_dir($rootDir)) {
		mkdir($rootDir);
	}
	
	$status = array();
	
	if (isset($_POST["fileID"])) {
		
		$data = file_get_contents(getFileName($fileID, $_POST["part"])); 
		
		if ($data === false) {
			$status["status"] = false;
		} else {
			$status["status"] = true;
			$status["data"] = $data;
		}
		
	} else {
		
		$fileID = $_POST["file"];
		
		$data = $_POST[$fileID];
		
		$part = $_POST["part"];
		
		$status["status"] = file_put_contents(getFileName($fileID, $part), $data) !== false;
		
	}
	
	function getFileName($id, $part) {
		return $rootDir . $id . "." . $part
	}
	
	die(json_encode($status));
	
?>	