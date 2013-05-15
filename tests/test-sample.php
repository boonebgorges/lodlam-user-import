<?php

class SampleTest extends WP_UnitTestCase {

	function test_sanitize_twitter_handle_clean() {
		$handle = 'boone';
		$this->assertEquals( 'https://twitter.com/boone/', LODLAM_User_Import::sanitize_twitter_handle( $handle ) );
	}

	function test_sanitize_twitter_handle_at_sign() {
		$handle = '@boone';
		$this->assertEquals( 'https://twitter.com/boone/', LODLAM_User_Import::sanitize_twitter_handle( $handle ) );
	}

	function test_sanitize_twitter_handle_url_http() {
		$handle = 'http://twitter.com/boone';
		$this->assertEquals( 'https://twitter.com/boone/', LODLAM_User_Import::sanitize_twitter_handle( $handle ) );
	}

	function test_sanitize_twitter_handle_url_https() {
		$handle = 'https://twitter.com/boone';
		$this->assertEquals( 'https://twitter.com/boone/', LODLAM_User_Import::sanitize_twitter_handle( $handle ) );
	}

	function test_sanitize_twitter_handle_url_trailingslash() {
		$handle = 'http://twitter.com/boone/';
		$this->assertEquals( 'https://twitter.com/boone/', LODLAM_User_Import::sanitize_twitter_handle( $handle ) );
	}
}

