<?php
	
	require_once "./FileObjects.php";
	require_once "./lafslib.php"; 
	
	$listing = null;
	
	$dir = "/";
	
	if (isset($_POST["dir"])) {
		
		$dir = $_POST["dir"];
		
		if (isset($_POST["file"])) {
			$file = $_POST["file"];
		} else {
			
			$listing = getDirectoryListing($_POST["dir"]);
			
		}
		
	} else {
		
		$listing = getDirectoryListing($_POST["dir"]);
		
	}
?>
<html>
	
	<head>
		<link href="//netdna.bootstrapcdn.com/bootstrap/3.0.2/css/bootstrap.min.css" rel="stylesheet">
	</head>
	
	<body>
		
		<div class="container">
			
			<table class="table">
				
				<thead>
					
					<tr>
						
						<th>Name</th>
						<!-- TODO: Creation date? -->
						
					</tr>
					
				</thead>
				
				<tbody>
					
					<?php
						
						foreach ($listing as $item) {
							
							$name = $item->name;
							
							if ($item instanceof Dir) {
								$name .= "/";
							} 
							
							echo '<tr><th><a href="./?dir=' . $dir . '&file=' . $name . '">' . $name  . '</a></th></tr>';
							
						}
						
					?>
					
				</tbody>
				
			</table>
			
		</div>
		
	</body>
	
	<script src="//netdna.bootstrapcdn.com/bootstrap/3.0.2/js/bootstrap.min.js"></script>
	
</html>