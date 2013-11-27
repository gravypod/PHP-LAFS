<?php
	
	
	
	global $CONFIG;
	$CONFIG = array();
	/**
	 * Config starts here
	 * 
	 */
	
	$CONFIG["pdostring"] = "sqlite:./store.db";
	$CONFIG["maxpartsize"] = 9437000; // ~9 MB (under the default max post size with room for )
	$CONFIG["filterbtype"] = "PHPLAFSNode-Generic"; // Null for off
	$CONFIG["btype"] = "PHPLAFSNode-Generic"; // Type of node / node id thing
	
	class LAFS {
		
		private $iv;
		
		private $browserType;
		
		private $database;
		
		private $key;
		
		private $postsize;
		
		function __construct() {
			
			$this->key = null;
			
			global $CONFIG;
			
			$this->postsize = $CONFIG["maxpartsize"];
			$this->database = new Database($CONFIG["pdostring"], $this);
			
			$this->iv = mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC), MCRYPT_RAND); // Initiate our algorithm for hashing. TODO: Switch to pure-PHP version for MOAR compatability
			
			$this->browserType = $CONFIG["btype"];
			
		}
		
		function getPart($fileid, $part) {
			return $this->database->getPart($fileid, $part);
		}
		
		function storePart($fileid, $part, $data, $nodes) {
			$file = $this->database->getPart($fileid, $part, $data, $nodes);
			return $file->data;
		}
		
		function getFile($fileid, $dir, $pass) {
			$fileEntry = $this->database->findFile($fileid, $dir);
			$nodes = $fileEntry->getNodes($this, $pass);
			
			$data = array();
			
			foreach ($nodes as $name => $data) {
				$parts = $data["parts"];
				$node = $this->database->findNode($name);
				foreach ($parts as $part) {
					if (!isset($data[$part])) {
						$data[$part] = $node->getFile($fileid, $part);
					}
				}
			}
			
			$reconstructed = '';
			if (count($data) != $fileEntry->parts) { // todo: make sure this recognizes ->parts as an int
				return null;
			}
			
			for ($x = 0; $x < count($data); $x++) {
				
				if ($data[$x] == null) {
					return null;
				}
				
				$reconstructed .= $data[$x];
			}
			
			return array("data" => $this->decrypt($reconstructed), "name" => $fileEntry->name);
			
		}
		
		function storeFile($path, $name, $dir, $pass) {
			
			$data = $this->encryptFile($path, $pass);
			
			$parts = $this->partsNeeded($data);
			
			$fileid = uniqid(microtime(true), true);
			
			$splitData = $this->splitData($data, $parts);
			
			$nodedata = $this->sendData($splitData, $fileid);
			
			$this->database->createFile($fileid, $name, $dir, count($parts), $nodedata);
			
		}
		
		function sendData($parts, $fileid) {
			
			$nodes = $this->database->getNodes(count($parts) * 2);
			
			$perserver = count($parts) / count($nodes);
			
			$nodeinfo = array();
			
			foreach ($nodes as $node) {
				$nodeinfo[$node->name] = array("ip" => $node->ip, "parts" => array());
			}
			
			shuffle($parts);
			shuffle($nodes);
			
			$x = 0;
			foreach ($nodes as $n) { // Find out where we will send out files
				for (; $x < ($perserver + $x); $x++) {
					$nodeinfo[$node->name]["parts"][] = $x; 
				}
			}
			
			$nodedata = $this->encrypt(json_encode($nodeinfo), $this->createKey());
			
			$x = 0;
			foreach ($nodes as $n) {
				for (; $x < ($perserver + $x); $x++) {
					$n->sendFile($parts[$x], $fileid, $x, $nodedata);
				}
			}
			
			return $nodedata;
		}
		
		function splitData($data, $parts) {
			
			$dataPerChunk = strlen($data) / $parts;
			
			if ($this->isDecimal($dataPerChunk)) {
				$dataPerChunk = 1 + floor($dataPerChunk);
			}
			
			$split = chunk_split($data, $dataPerChunk);
			return $split;
		}
		
		function partsNeeded($data) {
			
			$size = strlen($data);
			
			$parts = $size / $this->postsize;
			
			if ($this->isDecimal($parts)) {
				$parts = floor($parts) + 1;
			}
			
			return $parts;
			
		}
		
		function createKey($k = "") {
			
			if (!($this->key == null)) {
				return $this->key;
			}
			
			$pass = hash("SHA256", $k, true); // TODO: use full PHP version for more compatibility
			
			$this->key = $pass;
			
			return $pass;
			
		}
		
		function isDecimal($val) {
			return is_numeric($val) && (floor($val) != $val);
		}
		function encryptFile($path, $key) {
			return $this->encrypt(file_get_contents($path), $this->createKey($key));
		}
		
		function encrypt($sValue, $sSecretKey = $this->createKey()) { // Generic AES encryption
			return rtrim(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $sSecretKey, $sValue, MCRYPT_MODE_CBC, $this->iv)), "\0\3");
		}
		
		function decrypt($sValue, $sSecretKey = $this->createKey()) { // Generic AES decryption
			return rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $sSecretKey, base64_decode($sValue), MCRYPT_MODE_CBC, $this->iv), "\0\3");
		}
		
	}
	
	class Database {
		
		private $db;
		
		private $addNode;
		private $findNode;
		private $randomNodes;
		
		private $listFiles;
		private $addFile;
		private $findFile;
		
		private $listDirectory;
		private $addDirectory;
		
		private $findPart;
		private $addPart;
		
		private $lafs;
		
		function __construct($pdoString, $lafs) {
			$this->lafs = $lafs;
			$this->db = new PDO($pdoString);
			$this->db->exec("CREATE TABLE IF NOT EXISTS Nodes(name TEXT, ip TEXT)");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Files(fileID TEXT, name TEXT, directory TEXT, parts INT, nodes TEXT)");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Directories(name TEXT, superdir TEXT)");
			$this->db->exec("CREATE TABLE IF NOT EXISTS Parts(id TEXT, part INT, data BLOB, nodes TEXT)");
			
			/* SQL stuff */
			$this->randomNodes = "SELECT * FROM Nodes LIMIT :limit";
			$this->addNode = "INSERT INTO Nodes(name, ip) VALUES (:name, :ip)";
			$this->findNode = "SELECT * FROM Nodes WHERE name=:name LIMIT 1";
			
			$this->addFile = "INSERT INTO Files(fileID, name, directory, parts, nodes) VALUES (:fileID, :name, :directory, :parts, :nodes)";
			$this->listFiles = "SELECT * FROM Files WHERE directory = :dir";
			$this->findFile = "SELECT * FROM Files WHERE fileID=:fileid AND directory=:directory LIMIT 1";
			
			$this->findPart = "SELECT * FROM Parts WHERE id=:id AND part=:part LIMIT 1";
			$this->addPart = "INSERT INTO Parts(id, part, data, nodes) VALUES (:id, :part, :data, :nodes)";
			
			$this->addDirectory = "INSET INTO Directories(name, superdir) VALUES (:name, :superdir)";
			$this->listDirectory = "SELECT * FROM Directories WHERE superdir = :dir";
			
		}
		
		function getPart($fileid, $part) {
			$file = $this->pre($this->findPart);
			$this->bindPArams($file, array(
				"id" => $fileid,
				"part" => $part
			));
			$this->classFetch($file, "Part");
		}
		
		function storePart($fileid, $ip, $data, $nodes) {
			$file = $this->pre($this->addPart);
			$this->bindParams($file, array(
				"fileid" => $fileid,
				"part" => $part,
				"data" => $data,
				"nodes" => $nodes
			));
			$file->execute();
		}
		
		function getNodes($ammount) {
			$node = $this->pre($this->randomNodes);
			$this->bindParams($node, array(
				"limit" => $ammount
			));
			return $this->classFetchAll($node, "Node");
		}
		
		function findNode($name) {
			$node = $this->pre($this->findNode);
			$this->bindParams($node, array(
				"name" => $name
			));
			return $this->classFetch($node, "Node");
		}
		
		function addNode($name, $ip) {
			$node = $this->pre($this->addNode);
			$this->bindParams($node, array(
				"name" => $name,
				"ip" => $ip
			));
			$node->execute();
		}
		
		function findFile($fileid, $directory="/") {
			
			$file = $this->pre($this->findFile);
			
			$this->bindParams($file, array(
				"fileID" => $fileid,
				"directory" => $directory
			));
			
			return $this->classFetch($file, "File");
			
		}
		
		function createFile($fileid, $name, $directory, $parts, $nodes) {
			$file = $this->pre($this->addFile);
			$nodetext = $this->lafs->encrypt(json_encode($nodes), $this->lafs->createKey());
			$this->bindParams($file, array(
				"fileID" => $fileid,
				"name" => $name,
				"directory" => $directory,
				"parts" => $parts,
				"nodes" => $nodetext
			));
			$file->execute();
		}
		
		/**
		 * Collect an array of Directories who are subdirectories of $dir
		 */
		function listDirectory($dir = "/") {
			$directoryListing = $this->pre($this->listDirectory);
			$this->bindParams($directoryListing, array(
				"dir" => $dir
			));
			
			return $this->classFetchAll($directoryListing, "Dir");
			
		}
		
		/**
		 * Collect an array of Files who are subfiles of $dir
		 */
		function listDirectory($dir = "/") {
			$directoryListing = $this->pre($this->listFiles);
			$this->bindParams($directoryListing, array(
				"dir" => $dir
			));
			
			return $this->classFetchAll($directoryListing, "Dir");
			
		}
		
		function createDirectory($name, $superdir = "/") {
			
			$directory = $this->pre($this->addDirectory);
			
			$this->bindParams($directory, array(
				"name" => $name,
				"superdir" => $superdir
			));
			
			$directory->execute();
			
		}
		
		/**
		 * Bind an array of parameters to a prepared statement
		 * $q = Prepared statment
		 * $params = an array( "item" => "value" );
		 */
		function bindParams($q, $params) {
			
			if ($q == null || !is_array($params)) {
				return false;
			}
			
			foreach ($params as $param => $var) {
				$q->bindParam($param, $var);
			}
			return true;
		}
		
		/**
		 * Prepare a query
		 */
		function pre($q) {
			return $this->db->prepare($q);
		}
		
		function classFetchAll($q, $c) {
			$q->execute();
			$v = $q->fetchAll(PDO::FETCH_CLASS, $c);
			return $v;
		}
		
		function classFetch($q, $c) {
			
			$q->setFetchMode(PDO::FETCH_CLASS, $c);
			
			$q->execute();
			
			$v = $q->fetch(PDO::FETCH_CLASS);
			
			$q->closeCursor();
			
			return $v;
		}
		
	}
	
	class API {
		
		// TODO Compress all data in API
		
		public static function getFile($fileid, $part) {
			return array(
				"api" => "pget",
				"fileid" => $fileid,
				"part" => $part
			);
		}
		
		public static function sendFile($data, $fileid, $part, $nodes) {
			return array(
				"api" => "pstore",
				"data" => $data,
				"fileid" => $fileid,
				"part" => $part,
				"nodes" => $nodes
			);
		}
		
	}
	
	class Node {
		public $name;
		public $ip;
		
		function getFile($fileid, $part) {
			$fields = API::getFile($fileid, $part);
			return $this->curl($fields);
		}
		
		function sendFile($data, $fileid, $part, $nodes) {
			
			$fields = API::sendFile($data, $fileid, $part, $nodes); 
			$this->curl($fields);
		}
		
		function curl($fields) {
			global $CONFIG;
			
			//url-ify the data for the POST
			foreach($fields as $key=>$value) { 
				$fields_string .= $key . '=' . $value . '&'; 
			}
			
			rtrim($fields_string, '&');
			
			//open connection
			$ch = curl_init();
			
			//set the url, number of POST vars, POST data
			curl_setopt($ch, CURLOPT_URL, $this->ip);
			curl_setopt($ch, CURLOPT_USERAGENT, $CONFIG["btype"]);
			curl_setopt($ch, CURLOPT_POST, count($fields));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
			
			//execute post
			$result = curl_exec($ch);
			
			//close connection
			curl_close($ch);
			return $result;
		}
		
	}
	
	class Dir {
		public $name;
		public $superdir;
		
	}
	
	class File {
		public $fileID;
		public $name;
		public $directory;
		public $nodes;
		
		function getNodes($lafs, $password) {
			$pass = $lafs->createKey($password);
			$text = $lafs->decrypt($this->nodes, $password); // decrypt it
			return json_decode($text, true); // Decode the stored json
		}
		
	}
	class Part {
		public id;
		public part;
		public data;
		public nodes;
	}
	
	if (isset($_POST["api"])) {
		
		switch ($_POST["api"]) {
			case "pget":
				if (isset($_POST["fileid"]) && isset($_POST["part"]) && nodeCallAllowed()) {
					$partnumber = $_POST["part"];
					$fileid = $_POST["fileid"];
					$lafs = new LAFS();
					die($lafs->getPart($fileid, $partnumber));
				} else {
					fake();
				}
				break;
			case "pstore":
				if (isset($_POST["data"]) && isset($_POST["fileid"]) && isset($_POST["part"]) && isset($_POST["nodes"]) && nodeCallAllowed()) {
					$lafs = new LAFS();
					$lafs->storePart($_POST["fileid"], $_POST["part"], $_POST["data"], $_POST["nodes"]);
					die("ok"); // TODO: Error messages?
				} else {
					fake();
				}
				break;
			case "get":
				if (isset($_POST["fileid"]) && isset($_POST["dir"]) && isset($_POST["pass"]) && $CONFIG["managementnode"]) {
					
					$fileid = $_POST["fileid"];
					$dir = $_POST["dir"];
					$pass = $_POST["pass"];
					
					$lafs = new LAFS();
					$data = $lafs->getFile($fileid, $dir, $pass);
					header('Content-Description: File Transfer'); // TODO: content length
					header('Content-Type: application/octet-stream');
					header('Content-Disposition: attachment; filename="' . $data["name"] . '"');
					header('Content-Transfer-Encoding: binary');
					header('Expires: 0');
					header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
					header('Pragma: public');
					die($data["data"]); // TODO: check for error
					
				} else {
					// show error
				}
				break;
			case "store":
				if (isset($_FILES["file"]) && isset($_POST["pass"]) && isset($_POST["dir"]) && $CONFIG["managementnode"]) {
					$file = $_FILES["file"];
					$pass = $_POST["pass"];
					$dir = $_POST["dir"];
					$lafs = new LAFS();
					$lafs->storeFile($file["path"], $file["name"], $dir, $pass); // TODO: check for error
				} else {
					// TODO: Show error
				}
				
				break;
		/*
			case "update":
				
				break;
			case "sync":
				
				break;*/
		}
		
		die();
	}
	
	function nodeCallAllowed() {
		global $CONFIG;
		$filterType = $CONFIG["filterbtype"];
		if ($filterType === null) {
			return true;
		}
		return strpos($filterType, $_SERVER["HTTP_USER_AGENT"]) !== FALSE;
	}
	function fake() {
		echo file_get_contents("http://www.google.com");
	}
	
?>

<!-- TODO: some UI -->