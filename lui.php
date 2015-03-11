<?php

class LODLAM_User_Import {
	protected $cols = array();

	public function __construct() {
		$this->csv_path = __DIR__ . '/users.csv';
		$this->page_url = admin_url( 'admin.php?page=lodlam_user_import' );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	public function add_admin_menu() {
		if ( ! empty( $_FILES['lui_upload'] ) ) {
			$this->process_upload();
		}

		if ( ! empty( $_POST['lodlam_process'] ) ) {
//			$this->start();
		}

		add_menu_page(
			'LODLAM User Import',
			'LODLAM User Import',
			'create_users',
			'lodlam_user_import',
			array( $this, 'admin_menu_markup' )
		);
	}

	public function admin_menu_markup() {
		$results = get_option( 'lui_results' );
		delete_option( 'lui_results' );

		?>
		<div class="wrap">
			<h2>LODLAM User Import</h2>

			<form action="<?php echo $this->page_url ?>" method="post" enctype="multipart/form-data">
				<?php if ( ! $results ) : ?>
					<label for="lui_upload"><?php _e( 'Select the user data file for upload.', 'lodlam-user-import' ) ?></label><br />
					<input id="lui_upload" name="lui_upload" type="file" />
					<p class="description"><?php _e( 'Note that this file must be in CSV format. Please convert before uploading.', 'lodlam-user-import' ) ?></p>
					<?php wp_nonce_field( 'lui_upload', '_lui_upload_nonce' ); ?>

					<p>The CSV file will be read and parsed, and you'll be given information about which accounts will be created, which have already been found in the system, and which records in the CSV are malformed. Then, click the Import button again to run the import.</p>
				<?php else : ?>
					<h3>Results</h3>
					<ol>
					<?php foreach ( $results as $rkey => $r ) : ?>
						<?php if ( ! is_int( $rkey ) ) continue; ?>

						<li>

						<?php if ( $r['status'] === 'success' ) : ?>
							<?php if ( $r['user_exists'] ) : ?>
								<?php echo esc_html( $r['data'][1] ) ?> was matched to existing user <?php echo esc_html( $r['user_exists'] ) ?>. Profile data will be imported to this user.
							<?php else : ?>
								No user was found for <?php echo esc_html( $r['data'][1] ) ?> (<?php echo esc_html( $r['data'][2] ) ?>). A new account will be created, and profile data will be imported.
							<?php endif; ?>
						<?php else: ?>
							The following data could not be parsed. The user will have to be created manually.
							<pre><?php print_r( $r ) ?></pre>
						<?php endif ?>
						</li>
					<?php endforeach ?>
					</ol>

					<input type="hidden" name="run" value="1" />
				<?php endif ?>

				<input type="submit" name="submit" value="Import" />
				<input type="hidden" name="lodlam_process" value="1" />
			</form>
		</div>
		<?php
	}

	public function process_upload() {
		if ( empty( $_POST['_lui_upload_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['_lui_upload_nonce'], 'lui_upload' ) ) {
			return;
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return;
		}

		if ( empty( $_FILES['lui_upload'] ) ) {
			return;
		}

		if ( (int) $_FILES['lui_upload']['error'] > 0 ) {
			return;
		}

		add_filter( 'upload_mimes', array( $this, 'allow_csv_upload' ) );

		// temporarily save the file so we can process
		$upload = wp_handle_upload( $_FILES['lui_upload'], array( 'test_form' => false ) );

		remove_filter( 'upload_mimes', array( $this, 'allow_csv_upload' ) );

		if ( empty( $upload['file'] ) ) {
			return;
		}

		$this->start( $upload['file'] );
	}

	public static function allow_csv_upload( $types ) {
		$types['csv'] = 'text/csv';
		return $types;
	}

	public function start( $file = '' ) {
		if ( ! $file ) {
			$file = $this->csv_path;
		}

		$handle = fopen( $file, 'r' );

//		$dry_run = empty( $_POST['run'] );
		$dry_run = false;

		$row = 0;
		$results = array( 'is_dry_run' => $dry_run );
		while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== FALSE ) {
			$result = array(
				'user_exists' => false,
				'status' => 'success',
				'data' => $data,
			);

			$row++;

			// skip first row
			if ( 1 === $row ) {
				$this->determine_columns( $data );
				continue;
			}

			$user_login = $this->get_col( 'user_login', $data );
			$user_email = $this->get_col( 'user_email', $data );

			$display_name = $this->get_col( 'display_name', $data );
			if ( ! $display_name ) {
				$display_name = $user_login;
			}

			$user = $this->get_user( $user_login, $user_email );

			if ( ! $user ) {
				$user = $this->create_user( $user_login, $user_email, $display_name, $dry_run );
			} else {
				$result['user_exists'] = $user->user_login;
			}

			// couldn't create
			if ( ! $user ) {
				$result['status'] = 'failure';
			}

			if ( ! $dry_run ) {
				// Set a last activity for good measure
				bp_update_user_last_activity( $user->ID, bp_core_current_time() );

				// Map BP profile data
				$this->map_profile_fields( $user->ID, $data );

				// Add to the main blog at lodlam.net (id 1)
				add_user_to_blog( 1, $user->ID, 'author' );

				// Add to the summit2013 blog (id 5)
				add_user_to_blog( 5, $user->ID, 'author' );
			}

			$results[] = $result;
		}

		update_option( 'lui_results', $results );
	}

	/**
	 * Guess who!?!
	 */
	protected function determine_columns( $cols ) {
		foreach ( $cols as $ckey => $cname ) {
			$cname = strtolower( $cname );

			switch ( $cname ) {
				case 'timestamp' :
					$this->cols[ $ckey ] = array(
						'slug' => 'timestamp',
						'name' => 'Timestamp',
						'location' => '',
					);
					break;

				case 'public information' :
					$this->cols[ $ckey ] = array(
						'slug' => 'is_public',
						'name' => 'Public Information',
						'location' => '',
					);
					break;

				case 'full name' :
					$this->cols[ $ckey ] = array(
						'slug' => 'display_name',
						'name' => 'Name',
						'location' => '',
					);
					break;

				case 'first name' :
					$this->cols[ $ckey ] = array(
						'slug' => 'first_name',
						'name' => 'First Name',
						'location' => 'usermeta',
					);
					break;

				case 'username' :
					$this->cols[ $ckey ] = array(
						'slug' => 'user_login',
						'name' => 'Username',
						'location' => 'users',
					);
					break;

				case 'e-mail address' :
				case 'email address' :
					$this->cols[ $ckey ] = array(
						'slug' => 'user_email',
						'name' => 'Email Address',
						'location' => 'users',
					);
					break;

				case 'website' :
					$this->cols[ $ckey ] = array(
						'slug' => 'user_url',
						'name' => 'Website',
						'location' => 'users',
					);
					break;

				case 'twitter handle' :
				case 'twitter' :
					$this->cols[ $ckey ] = array(
						'slug' => 'twitter',
						'name' => 'Twitter',
						'location' => 'xprofile',
					);
					break;

				case 'affilliation' :
				case 'affiliation' :
					$this->cols[ $ckey ] = array(
						'slug' => 'affiliation',
						'name' => 'Affiliation',
						'location' => 'xprofile',
					);
					break;

				case 'sector' :
					$this->cols[ $ckey ] = array(
						'slug' => 'sector',
						'name' => 'Sector',
						'location' => 'xprofile',
					);
					break;

				case 'country' :
					$this->cols[ $ckey ] = array(
						'slug' => 'country',
						'name' => 'Country',
						'location' => 'xprofile',
					);
					break;

				case 'short bio' :
					$this->cols[ $ckey ] = array(
						'slug' => 'short_bio',
						'name' => 'Short Bio',
						'location' => 'xprofile',
					);
					break;

				case 'linked open data projects' :
					$this->cols[ $ckey ] = array(
						'slug' => 'linked_open_data_projects',
						'name' => 'Linked Open Data Projects',
						'location' => 'xprofile',
					);
					break;

				case 'interest in lodlam' :
					$this->cols[ $ckey ] = array(
						'slug' => 'interest_in_lodlam',
						'name' => 'Interest in LODLAM',
						'location' => 'xprofile',
					);
					break;

				case 'are you interested in participating in a lodlam challenge?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'lodlam_challege',
						'name' => 'Are you interested in participating in a LODLAM challenge?',
						'location' => 'xprofile',
					);
					break;

				case 'what work you\'re doing would you like to submit to a lodlam challenge if we ran one for the 2015 summit?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'work_to_submit',
						'name' => 'What work you\'re doing would you like to submit to a LODLAM Challenge if we ran one for the 2015 Summit?',
						'location' => 'xprofile',
					);
					break;

				case 'acceptance and payment' :
					$this->cols[ $ckey ] = array(
						'slug' => 'acceptance_and_payment',
						'name' => 'Acceptance and Payment',
						'location' => 'xprofile',
					);
					break;

				case 'additional notes' :
					$this->cols[ $ckey ] = array(
						'slug' => 'additional_notes',
						'name' => 'Additional Notes',
						'location' => 'xprofile',
					);
					break;

				case 'did you know that the 2015 digital humanities conference is on around the same time and in the same place as the 2015 lodlam summit?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'dh2015_did_you_know',
						'name' => 'Did you know that the 2015 Digital Humanities conference is on around the same time and in the same place as the 2015 LODLAM Summit?',
						'location' => 'xprofile',
					);
					break;

				case 'will you be submitting a paper for the 2015 digital humanities conference?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'dh2015_paper_submit',
						'name' => 'Will you be submitting a paper for the 2015 Digital Humanities conference?',
						'location' => 'xprofile',
					);
					break;

				case 'will you be registering for the 2015 digital humanities conference?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'dh2015_register',
						'name' => 'Will you be registering for the 2015 Digital Humanities conference?',
						'location' => 'xprofile',
					);
					break;

				case 'would you like to come to the digital humanities conference launch drinks?' :
					$this->cols[ $ckey ] = array(
						'slug' => 'dh2015_launch',
						'name' => 'Would you like to come to the digital humanities conference launch drinks?',
						'location' => 'xprofile',
					);
					break;
			}
		}
	}

	protected function get_user( $user_login, $user_email ) {
		$user = get_user_by( 'login', $user_login );

		if ( ! $user ) {
			$user = get_user_by( 'email', $user_email );
		}

		return $user;
	}

	protected function create_user( $user_login, $user_email, $display_name, $dry_run ) {
		if ( ! $user_login || ! $user_email ) {
			return false;
		}

		if ( $dry_run ) {
			return 'created';
		}

		$user_pass = wp_generate_password( 12, false );

		$user_id = wp_create_user( $user_login, $user_pass, $user_email );
		$user = new WP_User( $user_id );

		$subject = sprintf(
			'Your new account on %s',
			get_option( 'blogname' )
		);

		$message = sprintf(
			'Dear %1$s,

An account on %2$s has been created for you. Here is your login information:

Username: %3$s
Password: %4$s
%5$s

- The %2$s team',
			$display_name,
			get_option( 'blogname' ),
			$user->user_login,
			$user_pass,
			wp_login_url()
		);

		wp_mail( $user_email, $subject, $message );

		return $user;
	}

	protected function map_profile_fields( $user_id, $data ) {
		// (1) WP + BP fields
		wp_update_user( array(
			'ID' => $user_id,
			'display_name' => $this->get_col( 'display_name', $data ),
			'first_name' => $this->get_col( 'first_name', $data ),
			'user_url' => $this->get_col( 'user_url', $data ),
		) );

		$this->map_to_bp_field( 'display_name', $user_id, $data );
		$this->map_to_bp_field( 'user_url', $user_id, $data );

		// (2) BP fields
		//   - 4 => 'Twitter handle',
		//   - 5 => 'Affiliation',
		//   - 6 => 'Short Bio',
		//   - 7 => 'Interest in LODLAM',
		//   - 12 => 'Country',
		//   - 13 => 'Sector',

		$twitter_col = $this->get_col( 'twitter' );
		$data[ $twitter_col ] = $this->sanitize_twitter_handle( $data[ $twitter_col ] );
		foreach ( $data as $dkey => $d ) {
			$dfield = $this->cols[ $dkey ];
			if ( ! empty( $dfield['location'] ) && 'xprofile' === $dfield['location'] ) {
				$this->map_to_bp_field( $dfield['slug'], $user_id, $data );
			}
		}

		add_user_to_blog( get_current_blog_id(), $user_id, 'author' );
	}

	protected function map_to_bp_field( $field, $user_id, $data_array ) {
		global $bp;

		$col_num = $this->get_col( $field );
		$field_data = $this->cols[ $col_num ];

		if ( 'display_name' === $field_data['slug'] ) {
			$field_id = 1;
		} else if ( ! $field_id = xprofile_get_field_id_from_name( $field_data['name'] ) ) {
			$type = 'textbox';
			if ( in_array( $field_data['slug'], array(
				'short_bio',
				'interest_in_lodlam',
				'linked_open_data_projects',
			) ) ) {
				$type = 'textarea';
			}

			// Field doesn't exist, so let's create it
			$field_id = xprofile_insert_field( array(
				'name' => $field_data['name'],
				'type' => $type,
				'field_group_id' => 1,
			) );
		}

		// Overwrite existing data
		if ( isset( $data_array[ $col_num ] ) ) {
			var_Dump( $data_array[ $col_num ] );
			$value = $data_array[ $col_num ];
		} else {
			$value = '';
		}
		xprofile_set_field_data( (int) $field_id, $user_id, $value );
	}

	/**
	 * Turn a twitter handle into a link
	 */
	public static function sanitize_twitter_handle( $handle ) {
		$parts = parse_url( $handle );
		$url = '';

		if ( empty( $parts['host'] ) ) {
			// not a url
			if ( 0 === strpos( $handle, '@' ) ) {
				$handle = substr( $handle, 1 );
			}

			$url = 'https://twitter.com/' . $handle . '/';
		} else {
			$url = trailingslashit( 'https://twitter.com' . $parts['path'] );
		}

		return $url;
	}

	protected function get_col( $col, $data = false ) {
		// Find the column matching the slug.
		$col_num = false;
		foreach ( $this->cols as $ckey => $cdata ) {
			if ( $col === $cdata['slug'] ) {
				$col_num = $ckey;
				break;
			}
		}

		if ( ! $col_num ) {
			return false;
		}

		if ( ! $data ) {
			return $col_num;
		}

		// If the data array is passed, return the proper data from it.
		if ( isset( $data[ $col_num ] ) ) {
			return $data[ $col_num ];
		} else {
			return '';
		}
	}
}
