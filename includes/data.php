<?php
/**
 * Call records: a private post type with one post per call.
 */

defined( 'ABSPATH' ) || exit;

const CHC_CPT = 'chc_call';

const CHC_OUTCOMES = array(
	'answered'       => 'Answered',
	'voicemail'      => 'Voicemail',
	'missed'         => 'Missed',
	'text_requested' => 'Asked for a text',
);

add_action( 'init', function () {
	register_post_type(
		CHC_CPT,
		array(
			'label'               => __( 'Calls', 'clickhive-calls' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false, // shown on the custom inbox page instead
			'show_in_rest'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			'capability_type'     => 'chc_call',
			'map_meta_cap'        => false,
		)
	);
} );

/** Finds the post for a Twilio Call SID. */
function chc_find_call( $call_sid ) {
	$ids = get_posts(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_chc_call_sid',
			'meta_value'     => $call_sid,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Creates or updates a call record. Data comes from the signed REST request.
 * Status and category are never overwritten by Twilio; only staff change those.
 *
 * @return int|WP_Error Post ID.
 */
function chc_upsert_call( array $d ) {
	$call_sid = isset( $d['call_sid'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $d['call_sid'] ) : '';
	if ( ! preg_match( '/^CA[a-f0-9]{32}$/i', $call_sid ) ) {
		return new WP_Error( 'chc_bad_sid', 'Invalid call_sid', array( 'status' => 400 ) );
	}
	$outcome = isset( $d['outcome'] ) ? (string) $d['outcome'] : '';
	if ( ! isset( CHC_OUTCOMES[ $outcome ] ) ) {
		return new WP_Error( 'chc_bad_outcome', 'Invalid outcome', array( 'status' => 400 ) );
	}

	$caller   = substr( preg_replace( '/[^0-9+]/', '', (string) ( isset( $d['caller'] ) ? $d['caller'] : '' ) ), 0, 20 );
	$occurred = isset( $d['occurred_at'] ) ? strtotime( (string) $d['occurred_at'] ) : false;
	$occurred = $occurred ? $occurred : time();

	$id = chc_find_call( $call_sid );
	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'     => CHC_CPT,
				'post_status'   => 'private',
				'post_title'    => sprintf( 'Call %s', $call_sid ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $occurred ),
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $occurred ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_chc_call_sid', $call_sid );
		update_post_meta( $id, '_chc_status', chc_default_status() );
		update_post_meta( $id, '_chc_duration', 0 );
		update_post_meta( $id, '_chc_occurred_at', $occurred );
	}

	// A voicemail is the most useful outcome; never downgrade it to "missed".
	$existing = get_post_meta( $id, '_chc_outcome', true );
	if ( ! ( 'voicemail' === $existing && 'voicemail' !== $outcome ) ) {
		update_post_meta( $id, '_chc_outcome', $outcome );
	}
	// Whether a phone was actually rung. Once true, it stays true.
	if ( array_key_exists( 'rang', $d ) ) {
		if ( $d['rang'] ) {
			update_post_meta( $id, '_chc_rang', 1 );
		} elseif ( '' === (string) get_post_meta( $id, '_chc_rang', true ) ) {
			update_post_meta( $id, '_chc_rang', 0 );
		}
	}
	// The caller asked for a text. Kept even when they then leave a voicemail,
	// so staff know a text already went out.
	if ( 'text_requested' === $outcome || ! empty( $d['text_sent'] ) ) {
		update_post_meta( $id, '_chc_text_sent', 1 );
	}
	if ( $caller ) {
		update_post_meta( $id, '_chc_caller', $caller );
	}
	if ( ! empty( $d['business_number'] ) ) {
		update_post_meta( $id, '_chc_business_number', chc_digits( $d['business_number'] ) );
	}
	if ( isset( $d['duration_seconds'] ) && ( absint( $d['duration_seconds'] ) || '' === (string) get_post_meta( $id, '_chc_duration', true ) ) ) {
		update_post_meta( $id, '_chc_duration', absint( $d['duration_seconds'] ) );
	}
	if ( ! empty( $d['recording_sid'] ) && preg_match( '/^RE[a-f0-9]{32}$/i', (string) $d['recording_sid'] ) ) {
		update_post_meta( $id, '_chc_recording_sid', (string) $d['recording_sid'] );
	}

	// Where the call was sent, as it was at the time of the call.
	if ( ! empty( $d['line_name'] ) ) {
		update_post_meta( $id, '_chc_line_name', sanitize_text_field( (string) $d['line_name'] ) );
	}
	if ( isset( $d['receivers'] ) && is_array( $d['receivers'] ) ) {
		$receivers = array();
		foreach ( array_slice( $d['receivers'], 0, 10 ) as $r ) {
			$r = substr( preg_replace( '/[^0-9+]/', '', (string) $r ), 0, 20 );
			if ( $r ) {
				$receivers[] = $r;
			}
		}
		update_post_meta( $id, '_chc_receivers', $receivers );
	}
	if ( array_key_exists( 'after_hours', $d ) ) {
		update_post_meta( $id, '_chc_after_hours', $d['after_hours'] ? 1 : 0 );
		update_post_meta( $id, '_chc_hours_reason', sanitize_key( isset( $d['hours_reason'] ) ? $d['hours_reason'] : '' ) );
	}
	if ( ! empty( $d['answered_by'] ) ) {
		update_post_meta( $id, '_chc_answered_by', substr( preg_replace( '/[^0-9+]/', '', (string) $d['answered_by'] ), 0, 20 ) );
	}
	if ( isset( $d['notice_status'] ) && in_array( $d['notice_status'], array( 'sent', 'failed', 'skipped_short' ), true ) ) {
		update_post_meta( $id, '_chc_notice_status', $d['notice_status'] );
		update_post_meta( $id, '_chc_notice_to', sanitize_email( isset( $d['notice_sent_to'] ) ? (string) $d['notice_sent_to'] : '' ) );
	}

	update_option( 'chc_last_received', time(), false );
	return (int) $id;
}

/** Appends to a call's access history (who played or changed what, and when). */
function chc_log_access( $post_id, $action, $detail = '' ) {
	$log = get_post_meta( $post_id, '_chc_access_log', true );
	$log = is_array( $log ) ? $log : array();
	$log[] = array(
		'user'   => get_current_user_id(),
		'action' => $action,
		'detail' => $detail,
		'at'     => time(),
	);
	update_post_meta( $post_id, '_chc_access_log', array_slice( $log, -200 ) );

	if ( 'played' === $action && ! get_post_meta( $post_id, '_chc_heard', true ) ) {
		update_post_meta( $post_id, '_chc_heard', time() );
	}
}

/**
 * Query args for each quick view. Shared by the tabs, their counts and the list.
 */
function chc_view_meta( $view ) {
	switch ( $view ) {
		case 'open':
			return array( array( 'key' => '_chc_status', 'value' => chc_default_status() ) );
		case 'unheard':
			return array(
				array( 'key' => '_chc_recording_sid', 'compare' => 'EXISTS' ),
				array( 'key' => '_chc_heard', 'compare' => 'NOT EXISTS' ),
			);
		case 'voicemail':
		case 'missed':
			return array( array( 'key' => '_chc_outcome', 'value' => $view ) );
		default:
			return array();
	}
}

function chc_count( $meta ) {
	$q = new WP_Query(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return (int) $q->found_posts;
}

// 1.2.0: voicemails played before "unheard" tracking existed count as heard.
add_action( 'admin_init', function () {
	if ( version_compare( (string) get_option( 'chc_db_version', '0' ), '1.2.0', '>=' ) ) {
		return;
	}
	$ids = get_posts(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_chc_recording_sid',
		)
	);
	// Every call needs a length (0 if unknown) so sorting by length never hides calls.
	$all = get_posts( array( 'post_type' => CHC_CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( $all as $id ) {
		if ( '' === (string) get_post_meta( $id, '_chc_duration', true ) ) {
			update_post_meta( $id, '_chc_duration', 0 );
		}
	}
	foreach ( $ids as $id ) {
		$log = get_post_meta( $id, '_chc_access_log', true );
		foreach ( (array) $log as $e ) {
			if ( is_array( $e ) && isset( $e['action'] ) && 'played' === $e['action'] ) {
				update_post_meta( $id, '_chc_heard', (int) $e['at'] );
				break;
			}
		}
	}
	update_option( 'chc_db_version', '1.2.0', false );
} );

/** Number of calls still at the first status (shown as a count on the menu). */
function chc_open_count() {
	return chc_count( chc_view_meta( 'open' ) );
}

// Daily cleanup of records older than the retention setting.
add_action( 'chc_daily_cleanup', function () {
	$days   = max( 30, (int) chc_setting( 'retention_days' ) );
	$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$ids    = get_posts(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $before ) ),
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
} );

// Call Managers land on the inbox when they sign in.
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'chc_call_manager', (array) $user->roles, true ) ) {
		return admin_url( 'admin.php?page=clickhive-calls' );
	}
	return $redirect;
}, 10, 3 );
