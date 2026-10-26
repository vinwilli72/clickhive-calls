<?php
/**
 * Settings: connection to the phone system, wording, and dropdown options.
 * Settings → Click Hive Calls (administrators only).
 */

defined( 'ABSPATH' ) || exit;

const CHC_OPTION = 'chc_settings';

function chc_defaults() {
	return array(
		'site_key'         => '',
		'numbers'          => '',
		'playback_url'     => 'https://clickhive-voice-5810.twil.io/play',
		'inbox_title'      => 'Call Inbox',
		'status_label'     => 'Status',
		'status_options'   => "New\nCallback needed\nHandled",
		'category_label'   => 'Category',
		'category_options' => '',
		'phi_mode'         => 0,
		'retention_days'   => 365,
	);
}

function chc_settings() {
	return wp_parse_args( get_option( CHC_OPTION, array() ), chc_defaults() );
}

function chc_setting( $key ) {
	$s = chc_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/**
 * Dropdown options from a one-per-line setting, as key => label.
 * The key is stored on each call, so renaming a label keeps old records matched
 * as long as the words still produce the same key; removing a line retires it.
 */
function chc_options( $setting ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) chc_setting( $setting ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$label = $parts[0];
		$key   = isset( $parts[1] ) && '' !== $parts[1] ? sanitize_key( $parts[1] ) : sanitize_title( $label );
		if ( $key && ! isset( $out[ $key ] ) ) {
			$out[ $key ] = $label;
		}
	}
	return $out;
}

function chc_default_status() {
	$opts = chc_options( 'status_options' );
	return $opts ? (string) array_key_first( $opts ) : 'new';
}

/** Label for a stored key, including options that were since retired. */
function chc_option_label( $setting, $key ) {
	if ( '' === (string) $key ) {
		return '';
	}
	$opts = chc_options( $setting );
	return isset( $opts[ $key ] ) ? $opts[ $key ] : ucwords( str_replace( '-', ' ', $key ) );
}

/** This site's business numbers as 10-digit strings. */
function chc_allowed_numbers() {
	$out = array();
	// Split on new lines, commas or semicolons only: "(360) 309-0950" contains a space.
	foreach ( preg_split( '/[\r\n,;]+/', (string) chc_setting( 'numbers' ) ) as $n ) {
		$d = chc_digits( $n );
		if ( 10 === strlen( $d ) ) {
			$out[] = $d;
		}
	}
	return array_values( array_unique( $out ) );
}

function chc_digits( $v ) {
	$d = preg_replace( '/\D/', '', (string) $v );
	if ( 11 === strlen( $d ) && '1' === $d[0] ) {
		$d = substr( $d, 1 );
	}
	return $d;
}

function chc_format_phone( $v ) {
	$d = chc_digits( $v );
	return 10 === strlen( $d ) ? sprintf( '(%s) %s-%s', substr( $d, 0, 3 ), substr( $d, 3, 3 ), substr( $d, 6 ) ) : (string) $v;
}

// ---------------------------------------------------------------------------
// Settings page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Click Hive Calls', 'clickhive-calls' ),
		__( 'Click Hive Calls', 'clickhive-calls' ),
		'manage_options',
		'chc-settings',
		'chc_render_settings'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'chc_settings_group', CHC_OPTION, array( 'sanitize_callback' => 'chc_sanitize_settings' ) );
} );

function chc_sanitize_settings( $in ) {
	$old = chc_settings();
	$in  = is_array( $in ) ? $in : array();
	$out = chc_defaults();

	// Keep the existing key when the field is left blank, so it's never shown back in full.
	$key             = isset( $in['site_key'] ) ? trim( (string) $in['site_key'] ) : '';
	$out['site_key'] = '' === $key ? $old['site_key'] : preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );

	$out['numbers']          = sanitize_textarea_field( isset( $in['numbers'] ) ? $in['numbers'] : '' );
	$out['playback_url']     = esc_url_raw( isset( $in['playback_url'] ) ? trim( $in['playback_url'] ) : '', array( 'https' ) );
	$out['inbox_title']      = sanitize_text_field( isset( $in['inbox_title'] ) ? $in['inbox_title'] : '' ) ?: $out['inbox_title'];
	$out['status_label']     = sanitize_text_field( isset( $in['status_label'] ) ? $in['status_label'] : '' ) ?: $out['status_label'];
	$out['status_options']   = sanitize_textarea_field( isset( $in['status_options'] ) ? $in['status_options'] : '' ) ?: $out['status_options'];
	$out['category_label']   = sanitize_text_field( isset( $in['category_label'] ) ? $in['category_label'] : '' ) ?: $out['category_label'];
	$out['category_options'] = sanitize_textarea_field( isset( $in['category_options'] ) ? $in['category_options'] : '' );
	$out['phi_mode']         = empty( $in['phi_mode'] ) ? 0 : 1;
	$out['retention_days']   = max( 30, min( 3650, absint( isset( $in['retention_days'] ) ? $in['retention_days'] : 365 ) ) );

	if ( '' !== $key && 64 !== strlen( $out['site_key'] ) ) {
		add_settings_error( CHC_OPTION, 'chc_key', __( 'The site key should be 64 characters (letters a-f and numbers). It was not changed.', 'clickhive-calls' ) );
		$out['site_key'] = $old['site_key'];
	}
	return $out;
}

function chc_two_factor_active() {
	return class_exists( 'Two_Factor_Core' ) || defined( 'WORDFENCE_LS_VERSION' ) || class_exists( 'ITSEC_Core' ) || defined( 'WP_2FA_VERSION' );
}

function chc_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s        = chc_settings();
	$host     = wp_parse_url( home_url(), PHP_URL_HOST );
	$last     = get_option( 'chc_last_received' );
	$numbers  = chc_allowed_numbers();
	$problems = array();
	if ( ! $s['site_key'] ) {
		$problems[] = __( 'No site key yet. Calls cannot be received until one is saved.', 'clickhive-calls' );
	}
	if ( ! $numbers ) {
		$problems[] = __( 'No business numbers yet. Calls are refused until this site\'s number is listed.', 'clickhive-calls' );
	}
	if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) {
		$problems[] = __( 'This site is not using HTTPS.', 'clickhive-calls' );
	}
	if ( $s['phi_mode'] && ! chc_two_factor_active() ) {
		$problems[] = __( 'Minimum-PHI mode is on but no two-factor login plugin is active. Install one (for example, Two Factor) and require it for Call Managers.', 'clickhive-calls' );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Click Hive Calls', 'clickhive-calls' ); ?> <span class="chc-version" style="font-size:13px;font-weight:400;color:#646970;"><?php echo esc_html( sprintf( __( 'Version %s', 'clickhive-calls' ), CHC_VERSION ) ); ?></span></h1>
		<?php settings_errors( CHC_OPTION ); ?>

		<?php if ( $problems ) : ?>
			<div class="notice notice-warning"><ul style="list-style:disc;padding-left:20px;">
				<?php foreach ( $problems as $p ) : ?><li><?php echo esc_html( $p ); ?></li><?php endforeach; ?>
			</ul></div>
		<?php endif; ?>

		<p>
			<?php
			echo $last
				? esc_html( sprintf( __( 'Last call received: %s', 'clickhive-calls' ), wp_date( 'M j, Y g:i a', (int) $last ) ) )
				: esc_html__( 'No calls received yet.', 'clickhive-calls' );
			?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'chc_settings_group' ); ?>
			<h2><?php esc_html_e( 'Connection', 'clickhive-calls' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="chc_site_key"><?php esc_html_e( 'Site key', 'clickhive-calls' ); ?></label></th>
					<td>
						<input type="password" id="chc_site_key" name="<?php echo esc_attr( CHC_OPTION ); ?>[site_key]" class="regular-text code" autocomplete="off" placeholder="<?php echo $s['site_key'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'clickhive-calls' ) : ''; ?>">
						<p class="description"><?php echo esc_html( sprintf( __( 'Provided by Click Hive for %s. Only this site can use it.', 'clickhive-calls' ), $host ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_numbers"><?php esc_html_e( 'Business numbers', 'clickhive-calls' ); ?></label></th>
					<td>
						<textarea id="chc_numbers" name="<?php echo esc_attr( CHC_OPTION ); ?>[numbers]" rows="2" class="regular-text"><?php echo esc_textarea( $s['numbers'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'This site\'s phone number(s), one per line. Calls to any other number are refused.', 'clickhive-calls' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_playback_url"><?php esc_html_e( 'Playback service', 'clickhive-calls' ); ?></label></th>
					<td><input type="url" id="chc_playback_url" name="<?php echo esc_attr( CHC_OPTION ); ?>[playback_url]" value="<?php echo esc_attr( $s['playback_url'] ); ?>" class="regular-text code"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Minimum-PHI mode', 'clickhive-calls' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( CHC_OPTION ); ?>[phi_mode]" value="1" <?php checked( $s['phi_mode'] ); ?>> <?php esc_html_e( 'This practice handles patient calls. Show a reminder to sign out, and warn if two-factor login is missing.', 'clickhive-calls' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_retention"><?php esc_html_e( 'Keep call records for', 'clickhive-calls' ); ?></label></th>
					<td><input type="number" min="30" max="3650" id="chc_retention" name="<?php echo esc_attr( CHC_OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'clickhive-calls' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Wording', 'clickhive-calls' ); ?></h2>
			<p><?php esc_html_e( 'Change how the inbox reads for this business. One dropdown choice per line, in the order shown. The first status is given to every new call. Removing a line retires it: older calls keep it, new calls can\'t pick it.', 'clickhive-calls' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="chc_inbox_title"><?php esc_html_e( 'Inbox title', 'clickhive-calls' ); ?></label></th>
					<td><input type="text" id="chc_inbox_title" name="<?php echo esc_attr( CHC_OPTION ); ?>[inbox_title]" value="<?php echo esc_attr( $s['inbox_title'] ); ?>" class="regular-text" placeholder="Call Inbox, Patient Messages, New Inquiries"></td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_status_label"><?php esc_html_e( 'Status label', 'clickhive-calls' ); ?></label></th>
					<td><input type="text" id="chc_status_label" name="<?php echo esc_attr( CHC_OPTION ); ?>[status_label]" value="<?php echo esc_attr( $s['status_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_status_options"><?php esc_html_e( 'Status choices', 'clickhive-calls' ); ?></label></th>
					<td><textarea id="chc_status_options" name="<?php echo esc_attr( CHC_OPTION ); ?>[status_options]" rows="4" class="regular-text"><?php echo esc_textarea( $s['status_options'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_category_label"><?php esc_html_e( 'Category label', 'clickhive-calls' ); ?></label></th>
					<td><input type="text" id="chc_category_label" name="<?php echo esc_attr( CHC_OPTION ); ?>[category_label]" value="<?php echo esc_attr( $s['category_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="chc_category_options"><?php esc_html_e( 'Category choices', 'clickhive-calls' ); ?></label></th>
					<td>
						<textarea id="chc_category_options" name="<?php echo esc_attr( CHC_OPTION ); ?>[category_options]" rows="4" class="regular-text" placeholder="New patient&#10;Existing patient&#10;Other"><?php echo esc_textarea( $s['category_options'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave empty to hide the category column.', 'clickhive-calls' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
