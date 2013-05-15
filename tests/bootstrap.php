<?php

if ( ! defined( 'BP_TESTS_DIR' ) ) {
	define( 'BP_TESTS_DIR', __DIR__ . '/../../buddypress/tests' );
}

if ( file_exists( BP_TESTS_DIR . '/bootstrap.php' ) ) {
	require_once getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php';

	function _manually_load_plugin() {
		require BP_TESTS_DIR . '/includes/loader.php';
		require dirname( __FILE__ ) . '/../lodlam-user-import.php';
	}
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

	require getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
}
