<?php
/**
 * Line Settings: lets the business change who answers its calls and set
 * vacation dates, without asking Click Hive.
 *
 * Nothing is stored here. The page asks the Click Hive phone system for this
 * site's lines (one tab per Business number) and saves changes back to it.
 * Every request is signed with the site key, and the phone system only shows
 * or changes lines that belong to this site. Hours and holidays are shown
 * read-only; Click Hive changes those.
 */

defined( 'ABSPATH' ) || exit;

/** The ability to see and change Line Settings. */
const CHC_LINES_CAP = 'chc_edit_lines';

add_action( 'admin_menu', function () {
	$hook = add_submenu_page(
		'clickhive-calls',
		__( 'Line Settings', 'clickhive-calls' ),
		__( 'Line Settings', 'clickhive-calls' ),
		CHC_LINES_CAP,
		'chc-lines',
		'chc_render_lines'
	);
	// Saves are handled before the page starts drawing, so a refused request
	// (expired form, wrong user) gets a proper error page and status.
	if ( $hook ) {
		add_action( 'load-' . $hook, 'chc_lines_handle_post' );
	}
}, 20 );

/** What a save produced, for the page that follows it. */
function chc_lines_state( $set = null ) {
	static $state = array( 'notice' => null, 'errors' => array(), 'posted' => null, 'line' => '' );
	if ( is_array( $set ) ) {
		$state = array_merge( $state, $set );
	}
	return $state;
}

/** The phone system address for a path, next to the playback service. */
function chc_service_url( $path ) {
	$play = (string) chc_setting( 'playback_url' );
	// https only, except a local test copy of the phone system.
	if ( ! preg_match( '#^(https://[^/]+|http://localhost(?::\d+)?)/#', $play, $m ) ) {
		return '';
	}
	return $m[1] . '/' . ltrim( $path, '/' );
}

/**
 * Sends one signed request to the phone system's /line-settings.
 * Returns array( 'code' => HTTP status, 'body' => decoded JSON ) or WP_Error.
 */
function chc_lines_request( $action, $fields = array() ) {
	$key = (string) chc_setting( 'site_key' );
	$url = chc_service_url( 'line-settings' );
	if ( 64 !== strlen( $key ) || ! $url ) {
		return new WP_Error( 'chc_not_configured', __( 'This site is not connected to the phone system yet.', 'clickhive-calls' ) );
	}
	$f = wp_parse_args(
		$fields,
		array( 'numbers' => '', 'number' => '', 'receiver_1' => '', 'receiver_2' => '', 'vacation' => '' )
	);
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$ts   = (string) time();
	$sig  = hash_hmac(
		'sha256',
		implode( '|', array( $ts, $host, $action, $f['numbers'], $f['number'], $f['receiver_1'], $f['receiver_2'], $f['vacation'] ) ),
		$key
	);

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 20,
			'body'    => array_merge( $f, array( 'action' => $action, 'host' => $host, 'ts' => $ts, 'sig' => $sig ) ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'chc_unreachable', __( 'The phone system could not be reached. Your lines are not affected. Try again in a few minutes.', 'clickhive-calls' ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => is_array( $body ) ? $body : array(),
	);
}

/** Why a request failed, in words for the person using the page. */
function chc_lines_error_text( $res ) {
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$b = $res['body'];
	if ( ! empty( $b['message'] ) ) {
		return (string) $b['message'];
	}
	if ( 401 === $res['code'] ) {
		return __( 'The phone system did not accept this site\'s key. Ask Click Hive to check the site key in Settings, Click Hive Calls.', 'clickhive-calls' );
	}
	return __( 'Something went wrong. Your lines are not affected. Try again, and tell Click Hive if it keeps happening.', 'clickhive-calls' );
}

/** "+13605211487" as "(360) 521-1487" for the form. */
function chc_lines_phone_value( $v ) {
	return '' === (string) $v ? '' : chc_format_phone( $v );
}

/** "2026-12-20" as "Sunday, December 20, 2026". */
function chc_lines_date_text( $d ) {
	$t = strtotime( $d . ' 12:00:00 UTC' );
	return $t ? gmdate( 'l, F j, Y', $t ) : (string) $d;
}

/** Handles a Line Settings save (runs before the page is drawn). */
function chc_lines_handle_post() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['chc_line'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}
	if ( ! current_user_can( CHC_LINES_CAP ) ) {
		wp_die( esc_html__( 'You do not have access to line settings.', 'clickhive-calls' ), 403 );
	}
	$numbers = chc_allowed_numbers();
	$notice  = null; // array( type, text )
	$errors  = array(); // field => message, from a save the phone system refused
	$posted  = null; // what was typed, shown back when a save is refused
	check_admin_referer( 'chc_save_line' );
	$current = preg_replace( '/\D/', '', wp_unslash( $_POST['chc_line'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$first   = isset( $_POST['vacation_start'] ) ? sanitize_text_field( wp_unslash( $_POST['vacation_start'] ) ) : '';
	$last    = isset( $_POST['vacation_end'] ) ? sanitize_text_field( wp_unslash( $_POST['vacation_end'] ) ) : '';
	$posted  = array(
		'receiver_1' => isset( $_POST['receiver_1'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_1'] ) ) : '',
		'receiver_2' => isset( $_POST['receiver_2'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_2'] ) ) : '',
		'start'      => $first,
		'end'        => $last,
	);
	if ( ! empty( $_POST['clear_vacation'] ) ) {
		$first = '';
		$last  = '';
	}

	$vacation = '';
	if ( '' !== $first || '' !== $last ) {
		if ( '' === $first || '' === $last ) {
			$errors['vacation'] = __( 'Choose both a first and a last day, or leave both empty.', 'clickhive-calls' );
		} else {
			$vacation = $first . '/' . $last;
		}
	}

	if ( ! in_array( $current, $numbers, true ) ) {
		$notice = array( 'error', __( 'That number is not one of this site\'s Business numbers.', 'clickhive-calls' ) );
	} elseif ( ! $errors ) {
		$res = chc_lines_request(
			'save',
			array(
				'number'     => $current,
				'receiver_1' => $posted['receiver_1'],
				'receiver_2' => $posted['receiver_2'],
				'vacation'   => $vacation,
			)
		);
		if ( ! is_wp_error( $res ) && 200 === $res['code'] && ! empty( $res['body']['ok'] ) ) {
			$unchanged = ! empty( $res['body']['unchanged'] );
			if ( ! $unchanged ) {
				chc_log_line_change( $current, isset( $res['body']['changed'] ) ? (array) $res['body']['changed'] : array() );
			}
			// Back to the page with a plain GET, so refreshing doesn't save again.
			wp_safe_redirect( add_query_arg( array( 'line' => $current, 'chc_saved' => $unchanged ? 'none' : '1' ), admin_url( 'admin.php?page=chc-lines' ) ) );
			exit;
		} elseif ( ! is_wp_error( $res ) && 422 === $res['code'] && ! empty( $res['body']['problems'] ) ) {
			foreach ( (array) $res['body']['problems'] as $p ) {
				if ( isset( $p['field'], $p['message'] ) && ! isset( $errors[ $p['field'] ] ) ) {
					$errors[ $p['field'] ] = (string) $p['message'];
				}
			}
		} else {
			$notice = array( 'error', chc_lines_error_text( $res ) );
		}
	}
	if ( $errors && ! $notice ) {
		$notice = array( 'error', __( 'Not saved. Fix the highlighted fields and save again.', 'clickhive-calls' ) );
	}
	chc_lines_state( array( 'notice' => $notice, 'errors' => $errors, 'posted' => $posted, 'line' => $current ) );
}

function chc_render_lines() {
	if ( ! current_user_can( CHC_LINES_CAP ) ) {
		wp_die( esc_html__( 'You do not have access to line settings.', 'clickhive-calls' ) );
	}

	$numbers = chc_allowed_numbers();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the tab is read-only navigation
	$current = isset( $_GET['line'] ) ? preg_replace( '/\D/', '', wp_unslash( $_GET['line'] ) ) : '';
	// phpcs:enable

	$state   = chc_lines_state();
	$notice  = $state['notice'];
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which message to show
	$saved = isset( $_GET['chc_saved'] ) ? sanitize_key( wp_unslash( $_GET['chc_saved'] ) ) : '';
	if ( ! $notice && '1' === $saved ) {
		$notice = array( 'success', __( 'Saved. The next call to this line will use these settings.', 'clickhive-calls' ) );
	} elseif ( ! $notice && 'none' === $saved ) {
		$notice = array( 'info', __( 'Nothing changed.', 'clickhive-calls' ) );
	}
	$errors  = $state['errors'];
	$posted  = $state['posted'];
	if ( $state['line'] ) {
		$current = $state['line'];
	}

	// Load every line fresh from the phone system.
	$lines = array();
	$load  = null;
	if ( $numbers ) {
		$load = chc_lines_request( 'list', array( 'numbers' => implode( ',', $numbers ) ) );
		if ( ! is_wp_error( $load ) && 200 === $load['code'] && isset( $load['body']['lines'] ) ) {
			foreach ( (array) $load['body']['lines'] as $l ) {
				if ( isset( $l['number'] ) ) {
					$lines[ (string) $l['number'] ] = $l;
				}
			}
		}
	}
	if ( ! isset( $lines[ $current ] ) ) {
		$current = $lines ? (string) array_key_first( $lines ) : '';
	}
	$base = admin_url( 'admin.php?page=chc-lines' );
	?>
	<div class="wrap chc-lines">
		<h1><?php esc_html_e( 'Line Settings', 'clickhive-calls' ); ?></h1>
		<p class="chc-lines-intro"><?php esc_html_e( 'Choose who answers calls to your business number, and close the line for vacation days. Changes take effect on the next call.', 'clickhive-calls' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $numbers ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'This site has no Business numbers yet. Click Hive adds them in Settings, Click Hive Calls.', 'clickhive-calls' ); ?></p></div>
		<?php elseif ( ! $lines ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( chc_lines_error_text( $load ) ); ?></p></div>
		<?php else : ?>

			<?php if ( count( $lines ) > 1 ) : ?>
				<nav class="nav-tab-wrapper chc-line-tabs">
					<?php foreach ( $lines as $num => $l ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'line', $num, $base ) ); ?>" class="nav-tab<?php echo $num === $current ? ' nav-tab-active' : ''; ?>">
							<?php
							echo esc_html( $l['display'] );
							if ( ! empty( $l['client_name'] ) ) {
								echo ' <span class="chc-tab-name">' . esc_html( $l['client_name'] ) . '</span>';
							}
							?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php chc_render_line_panel( $lines[ $current ], $posted, $errors ); ?>

		<?php endif; ?>
	</div>
	<?php
}

function chc_render_line_panel( $l, $posted, $errors ) {
	if ( 'ok' !== $l['status'] ) {
		echo '<div class="chc-line-panel"><h2>' . esc_html( $l['display'] ) . '</h2>';
		echo '<div class="notice notice-info inline"><p>' . esc_html( isset( $l['message'] ) ? $l['message'] : __( 'This line can\'t be changed here.', 'clickhive-calls' ) ) . '</p></div></div>';
		return;
	}

	$vac   = isset( $l['vacation'] ) && is_array( $l['vacation'] ) ? $l['vacation'] : null;
	$value = array(
		'receiver_1' => $posted ? $posted['receiver_1'] : chc_lines_phone_value( $l['receiver_1'] ),
		'receiver_2' => $posted ? $posted['receiver_2'] : chc_lines_phone_value( $l['receiver_2'] ),
		'start'      => $posted ? $posted['start'] : ( $vac ? $vac['start'] : '' ),
		'end'        => $posted ? $posted['end'] : ( $vac ? $vac['end'] : '' ),
	);
	$today = wp_date( 'Y-m-d' );
	$hours = isset( $l['hours'] ) ? $l['hours'] : array();
	$err   = function ( $field ) use ( $errors ) {
		if ( isset( $errors[ $field ] ) ) {
			echo '<p class="chc-field-error">' . esc_html( $errors[ $field ] ) . '</p>';
		}
	};
	?>
	<div class="chc-line-panel">
		<h2><?php echo esc_html( $l['display'] ); ?></h2>

		<?php if ( $vac && $vac['end'] >= $today ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					$vac['start'] === $vac['end']
						? sprintf( __( 'This line is closed on %s. Callers hear your after-hours message.', 'clickhive-calls' ), chc_lines_date_text( $vac['start'] ) )
						: sprintf( __( 'This line is closed from %1$s through %2$s. Callers hear your after-hours message.', 'clickhive-calls' ), chc_lines_date_text( $vac['start'] ), chc_lines_date_text( $vac['end'] ) )
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( add_query_arg( 'line', $l['number'], admin_url( 'admin.php?page=chc-lines' ) ) ); ?>">
			<?php wp_nonce_field( 'chc_save_line' ); ?>
			<input type="hidden" name="chc_line" value="<?php echo esc_attr( $l['number'] ); ?>">

			<h3><?php esc_html_e( 'Who answers', 'clickhive-calls' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr class="<?php echo isset( $errors['receiver_1'] ) ? 'chc-has-error' : ''; ?>">
					<th scope="row"><label for="chc_r1"><?php esc_html_e( 'Caller 1', 'clickhive-calls' ); ?></label></th>
					<td>
						<input type="tel" id="chc_r1" name="receiver_1" value="<?php echo esc_attr( $value['receiver_1'] ); ?>" class="regular-text" required placeholder="(360) 555-0123">
						<?php $err( 'receiver_1' ); ?>
						<p class="description"><?php esc_html_e( 'Rings first.', 'clickhive-calls' ); ?></p>
					</td>
				</tr>
				<tr class="<?php echo isset( $errors['receiver_2'] ) ? 'chc-has-error' : ''; ?>">
					<th scope="row"><label for="chc_r2"><?php esc_html_e( 'Caller 2', 'clickhive-calls' ); ?></label></th>
					<td>
						<input type="tel" id="chc_r2" name="receiver_2" value="<?php echo esc_attr( $value['receiver_2'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'clickhive-calls' ); ?>">
						<?php $err( 'receiver_2' ); ?>
						<p class="description"><?php esc_html_e( 'Rings if Caller 1 doesn\'t answer. Leave empty if only one person takes calls.', 'clickhive-calls' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Vacation', 'clickhive-calls' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr class="<?php echo isset( $errors['vacation'] ) ? 'chc-has-error' : ''; ?>">
					<th scope="row"><?php esc_html_e( 'Closed', 'clickhive-calls' ); ?></th>
					<td>
						<label for="chc_vs"><?php esc_html_e( 'First day', 'clickhive-calls' ); ?></label>
						<input type="date" id="chc_vs" name="vacation_start" value="<?php echo esc_attr( $value['start'] ); ?>">
						<label for="chc_ve" class="chc-gap"><?php esc_html_e( 'Last day', 'clickhive-calls' ); ?></label>
						<input type="date" id="chc_ve" name="vacation_end" value="<?php echo esc_attr( $value['end'] ); ?>">
						<?php $err( 'vacation' ); ?>
						<?php if ( $vac ) : ?>
							<p><label><input type="checkbox" name="clear_vacation" value="1"> <?php esc_html_e( 'Remove these vacation dates', 'clickhive-calls' ); ?></label></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Both days are included. On these days callers hear your after-hours message and can leave a voicemail. The dates clear themselves once they pass.', 'clickhive-calls' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save line settings', 'clickhive-calls' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Business hours', 'clickhive-calls' ); ?></h3>
		<?php if ( ! empty( $hours['always_open'] ) ) : ?>
			<p><?php esc_html_e( 'No hours are set, so calls ring through at any time.', 'clickhive-calls' ); ?></p>
		<?php elseif ( ! empty( $hours['days'] ) ) : ?>
			<table class="widefat striped chc-hours">
				<tbody>
					<?php foreach ( $hours['days'] as $d ) : ?>
						<tr><th scope="row"><?php echo esc_html( $d['day'] ); ?></th><td><?php echo esc_html( $d['text'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! empty( $hours['timezone'] ) ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Times are %s.', 'clickhive-calls' ), $hours['timezone'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $hours['holidays'] ) ) : ?>
				<h4><?php esc_html_e( 'Holidays (closed)', 'clickhive-calls' ); ?></h4>
				<ul class="chc-holidays">
					<?php foreach ( $hours['holidays'] as $h ) : ?>
						<li><?php echo esc_html( $h['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'To change your regular hours or holidays, contact Click Hive.', 'clickhive-calls' ); ?></p>
	</div>
	<?php
}

/**
 * A note of who changed what on this site, alongside the phone system's own
 * Change log, so the business can see it was one of their own users.
 */
function chc_log_line_change( $number, $changed ) {
	$log   = get_option( 'chc_line_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$user  = wp_get_current_user();
	$log[] = array(
		'time'    => time(),
		'user'    => $user ? $user->user_login : '',
		'number'  => $number,
		'changed' => array_values( array_map( 'sanitize_key', $changed ) ),
	);
	update_option( 'chc_line_log', array_slice( $log, -100 ), false );
}

add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, 'chc-lines' ) ) {
		return;
	}
	echo '<style>
		.chc-lines .chc-lines-intro { max-width: 720px; }
		.chc-lines .chc-line-tabs { margin-bottom: 0; }
		.chc-lines .chc-tab-name { font-weight: 400; color: #646970; margin-left: 4px; }
		.chc-lines .chc-line-panel { background: #fff; border: 1px solid #c3c4c7; padding: 4px 20px 16px; max-width: 900px; }
		.chc-lines .chc-line-tabs + .chc-line-panel { border-top: 0; }
		.chc-lines .chc-gap { margin-left: 16px; }
		.chc-lines .chc-has-error input { border-color: #d63638; }
		.chc-lines .chc-field-error { color: #d63638; margin: 4px 0 0; }
		.chc-lines .chc-hours { max-width: 420px; }
		.chc-lines .chc-hours th { width: 140px; font-weight: 600; }
		.chc-lines .chc-holidays { list-style: disc; padding-left: 20px; }
	</style>';
} );
