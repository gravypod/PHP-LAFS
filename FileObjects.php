<?php
	if (!defined("VERSION")) {
		die("Error with LAFS");
	}
	
	class Node {
		
		public $name; // ID of the node
		public $ip; // IP for said node
		
		/**
		 *
		 * Send a file to a node
		 */
		function sendFile($fileID, $data, $part, $browserType) {
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, $browserType);
			curl_setopt($ch, CURLOPT_URL, urlencode($url));
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, array(
				
				"file" => $fileID,
				$fileID => $data,
				"part" => $part
				
			));
			
			$response = curl_exec($ch); // TODO: Check to see if this has worked
			curl_close($ch);
			
		}
	}
	
	class File {
		public $fileID;
		public $name; // Name of the file
		public $directory; // Directory of the file
		public $parts; // Parts of the file (int)
		public $nodes; // Nodes the file is stored on
		function getNodes() {
			return json_decode($this->nodes, true);
		}
	}
	
	class Dir {
		private $name;
		private $superdir;
	}
	
?>