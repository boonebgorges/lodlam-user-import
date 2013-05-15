<?php
/*
Plugin Name: LODLAM User Import
Version: 1.0
Author: Boone B Gorges
Author URI: http://boone.gorg.es
*/

function lodlam_user_import_init() {
	global $bp;
	include __DIR__ . '/lui.php';
	$bp->lodlam_user_import = new LODLAM_User_Import();
}
add_action( 'bp_include', 'lodlam_user_import_init' );

