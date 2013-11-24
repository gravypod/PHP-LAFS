<?php
	
	/*	
		DATABASE Schema
		
		Nodes:
			- name: Identifier of the node
			- IP: IP Address or hostname or URL to the node
		
		Files:
			- Name: Name of the file
			- Directory: subdirectory (default is /)
			- Parts: Side of the split
			- Nodes: Nodes the file was stored on
				- JSON Array (TODO: AES256 hashed):
					{
						nodename: [
							0, 1, 2 ...etc
						],
						nodename: [
							0, 1, 2 ...etc
						],
					}
		
		
		Storage node protocol
			get:
				
				Sent: - fileID: ID of the file sent to the server in post vars
				Recived: 
					- JSon encoded string containing "status" (true/false); true = good, false = problem. "data" = data stored in DB
			store:
				sent: TODO find out how to send to a storage node
	*/
	require_once "./FileObjects.php";
	
	class LAFS {
		private $db;
		private $addNode;
		private $addFile;
		private $findFile;
		private $findNode;
		private $iv;
		private $findNodeByName;
		private $browserType;
		private $listDirectory;
		private $listFiles;
		private $addDirectory;
		
		function __construct() {
			$browserType = "HEADNode"; // TODO: Use to limit access to file on storage nodes
			$this->iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC), MCRYPT_RAND); // Initiate our algorithm for hashing. TODO: Switch to pure-PHP version for MOAR compatability
			
			/* SQL stuff */
			$this->addNode = "INSERT INTO Nodes(name, ip) VALUES (:name, :ip)";
			$this->addFile = "INSERT INTO Files(fileID, name, directory, parts, nodes) VALUES (:fileID, :name, :directory, :parts, :nodes)";
			$this->findNode = "SELECT * FROM Nodes LIMIT :limit";
			$this->findNodeByName = "SELECT * FROM Nodes WHERE name=:name LIMIT 1";
			$this->listFiles = "SELECT * FROM Files WHERE directory = :dir";
			$this->listDirectory = "SELECT * FROM Directories WHERE superdir = :dir";
			$this->addDirectory = "INSET INTO Directories(name, superdir) VALUES (:name, :superdir)";
			$this->findFile = "SELECT * FROM Files WHERE name=:name AND directory=:directory LIMIT 1";
			
			$this->db = new PDO("sqlite:storage.db");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Nodes(name TEXT, ip TEXT)");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Files(fileID TEXT, name TEXT, directory TEXT, parts INT, nodes TEXT)");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Directories(name TEXT, superdir TEXT)");
		}
		
		function getDirectoryListing($dir = "/") {
			$dirs = $this->db->prepare($this->listDirectoy);
			$dirs->bindParam("dir", $dir);
			$dirs->execute();
			$files = $this->db->prepare($this->listFiles);
			$files->bindParam("dir", $dir);
			$files->execute();
			return array_murge($files->fetchAll(PDO::FETCH_CLASS, "File"), $files->fetchAll(PDO::FETCH_CLASS, "Dir"));
		}
		
		function addDirectory($name, $superdir = "/") {
			$q = $this->db->prepare($this->addDirectory);
			$q->bindParam("superdir", $superdir);
			$q->bindParam("name", $name);
			$q->execute();
		}
		
		function getFile($name, $directory, $pass) {
			
			$q = $this->db->prepare($this->findFile);
			$q->bindParam("name", $name);
			$q->bindParam("directory", $directory);
			$q->execute();
			
			$returned = $q->fetchAll(PDO::FETCH_CLASS, "File");
			
			$file = $returned[0]; // TODO: Handle not found
			
			unset($returned);
			
			$nodes = $file->getNodes();
			$parts = array();
			$nodeIP = array();
			
			for ($x = 0; $x < $file->parts; $x++) { // Check to see if we can reconstitute the file
				$parts[$x] = false;
			}
			
			foreach ($nodes as $k => $v) {
				foreach ($v as $partNum) {
					if (!$parts[$x]) { // TODO: Check if the server is alive
						$parts[$x] = true;
						$nodeIP[$x] = getNode($k);
					}
				}
			}
			
			foreach ($parts as $p) {
				if (!p) {
					return null; // On NULL send a 404, TODO: Handle missing
				}
			}
			
			$data = reconstitute($nodeIP, $file->fileID);
			
			$pass = hash("SHA256", $password, true);
			
			$file = decrypt($data, $pass);
			
			return $file;
			
		}
		
		function reconstitute($nodes, $fileID) {
			
			$data = "";
			
			for ($x = 0; $x < count($nodes); $x++) { // K is part V is ip
				
				$postVars = array(
					"fileID" => $fileID,
					"part" => $x
				);
				
				$responce = getPage($nodes[$x], $postVars);
				
				if (!$responce["status"]) {
					return null; // NULL means could not reconstitute, node was bad. TODO: More than one node looked at per part
				}
				
				$data .= $responce["data"];
				
			}
			
			return $data;
			
		}
		
		/*
		* 
		* Download the contents of page from a node
		* Post must be an array
		*/
		function getPage($url, $postVars) {
			
			$qString = "";
			
			foreach($fields as $key=>$value) {
				$qString .= $key.'='.$value.'&'; 
			}
			
			rtrim($qString, '&');
			
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, $this->browserType);
			curl_setopt($ch, CURLOPT_URL, urlencode($url));
			curl_setopt($ch,CURLOPT_POST, count($postVars));
			curl_setopt($ch,CURLOPT_POSTFIELDS, $qString);
			$response = curl_exec($ch);
			curl_close($ch);
			
			return json_decode($response, true);
			
		}
		
		/**
		* Get a node by its name
		*/
		function getNode($name) {
			$q = $this->db->prepare($this->findNode);
			$q->bindParam("name", $findNodeByName);
			$q->execute();
			$nodes = $q->fetchAll(PDO::FETCH_CLASS, "Node");
			return $nodes[0];
		}
		
		/**
		* Add a new file into the network
		*/
		function storeFile($path, $name, $directory, $password) { // TODO: Test
			
			$pass = hash("SHA256", $password, true);
			
			$fileData = splitData(encryptFile($path, $pass)); // TODO: chop file into 10 MB segments (To align with normal max post size)
			
			$nodes = findNodes(count($fileData) * 2);
			
			$stored = array();
			
			$fileParts = count($fileData);
			
			$storageInfo = array();
			
			$findID = uniqid($fileParts, true);
			
			while (count($stored) < $fileParts) {
				
				shuffle($fileData);
				
				shuffle($nodes);
				
				for ($x = 0; $x < $fileParts; $x++) {
					
					if (!isset($nodes[$x])) {
						continue;
					}
					
					$node = $nodes[$x];
					
					$node->sendFile($findID, $fileData[$x], $x); // TODO Handle file upload errors
					
					if (!isset($storageInfo[$node->name])) { // Dont add if error
						$storageInfo[$node->name] = array();
					}
					
					$storageInfo[$node->name][] = $x;
					
					$stored[$x] = true;
					
				}
				
			}
			
			unset($x);
			unset($fileData);
			unset($nodes);
			
			insertDatabaseInfo($findID, $name, $directory, $fileParts, $storageInfo); // TODO: Encrypt storage info so only the user with the pass can get to the files
			
		}
		
		function insertDatabaseInfo($findID, $name, $directory, $parts, $info) {
			
			$q = $this->db->prepare($this->addFile);
			
			$q->bindParam("fileID", $fileID);
			$q->bindParam("name", $name);
			$q->bindParam("directory", $directory);
			$q->bindParam("parts", $parts);
			$q->bindParam("nodes", json_encode($info));
			
			$q->execute();
			
		}
		
		/**
		* Find nodes to store the files on
		* 
		*/
		function findNodes($ammount = 20) {
			
			$q = $this->db->prepare($this->findNode);
			$q->bindParam("limit", $ammount);
			$q->execute();
			
			return $q->fetchAll(PDO::FETCH_CLASS, "Node");
			
		}
		
		/**
		* Split the file into chunks for sending off to servers
		* 
		*/
		function splitData($data, $peices = 20) {
			
			$chunks = strlen($data) / $peices;
			
			if ($data % $peices > 0) {
				$chunks++;
			}
			
			return chunk_split($data, $chunks);
			
		}
		
		function encryptFile($path, $password) { // Encrypt the file
			return encrypt(file_get_contents($path), $password);
		}
		
		function encrypt($sValue, $sSecretKey) { // Generic AES encryption
			return rtrim(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $sSecretKey, $sValue, MCRYPT_MODE_CBC, $this->iv)), "\0\3");
		}
		
		function decrypt($sValue, $sSecretKey) { // Generic AES decryption
			global $iv;
			return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $sSecretKey, base64_decode($sValue), MCRYPT_MODE_CBC, $this->iv), "\0\3");
		}
	
	}
	

?>