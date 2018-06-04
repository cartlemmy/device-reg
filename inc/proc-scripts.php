<?php

$dynSh = array('termux-init', 'send-upd');
foreach ($dynSh as $n) {
	if (
		!is_file('dl/'.$n.'.sh') ||
		
		filemtime('inc/'.$n.'.sh.php') > 
		filemtime('dl/'.$n.'.sh') ||
		
		filemtime('inc/config.inc.php') > 
		filemtime('dl/'.$n.'.sh')
	) {
		echo 'updating dl/'.$n.'.sh'."\n";
		ob_start();
		include('inc/'.$n.'.sh.php');
		file_put_contents('dl/'.$n.'.sh', ob_get_clean());
	}	
}
