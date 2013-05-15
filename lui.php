<?php

class LODLAM_User_Import {
	public function __construct() {
		$this->csv_path = __DIR__ . '/users.csv';
		$this->page_url = admin_url( 'admin.php?page=lodlam_user_import' );

		$this->cols = array(
			1 => 'Full Name',
			2 => 'E-mail Address',
			3 => 'Website',
			4 => 'Twitter handle',
			5 => 'Affiliation',
			6 => 'Short Bio',
			7 => 'Interest in LODLAM',
			10 => 'Username',
			12 => 'Country',
			13 => 'Sector',
			14 => 'Linked Open Data Projects',
		);

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	public function add_admin_menu() {
		if ( ! empty( $_POST['lodlam_process'] ) ) {
			$this->start();
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

			<form action="<?php echo $this->page_url ?>" method="post">
				<?php if ( ! $results ) : ?>
					<p>Click 'Import' to run the script.</p>

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

	public function start() {
		$handle = fopen( $this->csv_path, 'r' );

		$dry_run = empty( $_POST['run'] );

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
				continue;
			}

			$user_login = isset( $data[10] ) ? $data[10] : '';
			$user_email = isset( $data[2] ) ? $data[2] : '';
			$display_name = isset( $data[1] ) ? $data[1] : $user_login;
			$user = $this->get_user( $user_email, $user_email );

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
				update_user_meta( $user->ID, 'last_activity', bp_core_current_time() );

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
		//   - 1 => 'Full Name'
		//   - 3 => 'Website'
		wp_update_user( array(
			'ID' => $user_id,
			'display_name' => $data[1],
			'user_url' => $data[3],
		) );

		$this->map_to_bp_field( $this->cols[1], $user_id, $data[1] );
		$this->map_to_bp_field( $this->cols[3], $user_id, $data[3] );

		// (2) BP fields
		//   - 4 => 'Twitter handle',
		//   - 5 => 'Affiliation',
		//   - 6 => 'Short Bio',
		//   - 7 => 'Interest in LODLAM',
		//   - 12 => 'Country',
		//   - 13 => 'Sector',

		$this->map_to_bp_field( $this->cols[4], $user_id, $this->sanitize_twitter_handle( $data[4] ) );
		$this->map_to_bp_field( $this->cols[5], $user_id, $data[5] );
		$this->map_to_bp_field( $this->cols[6], $user_id, $data[6] );
		$this->map_to_bp_field( $this->cols[7], $user_id, $data[7] );
		$this->map_to_bp_field( $this->cols[12], $user_id, $data[12] );
		$this->map_to_bp_field( $this->cols[13], $user_id, $data[13] );
	}

	protected function map_to_bp_field( $field, $user_id, $value ) {
		global $bp;

		if ( 'Full Name' === $field ) {
			$field_id = 1;
		} else if ( ! $field_id = xprofile_get_field_id_from_name( $field ) ) {
			$type = 'textbox';
			if ( in_array( $field, array(
				'Short Bio',
				'Interest in LODLAM',
				'Linked Open Data Projects',
			) ) ) {
				$type = 'textarea';
			}

			// Field doesn't exist, so let's create it
			$field_id = xprofile_insert_field( array(
				'name' => $field,
				'type' => $type,
				'field_group_id' => 1,
			) );
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
}
