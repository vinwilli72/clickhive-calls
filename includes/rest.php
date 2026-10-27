<?php
/**
 * REST routes.
 *
 *   POST /wp-json/clickhive-calls/v1/calls               Twilio sends a call record (signed).
 *   GET  /wp-json/clickhive-calls/v1/calls/{id}/audio     Staff play a voicemail (logged in).
 *   POST /wp-json/clickhive-calls/v1/calls/{id}           Staff change status or category.
 */

defined( 'ABSPATH' ) || exit;

const CHC_NS = 'clickhive-calls/v1';

add_action( 'rest_api_init', function () {
	register_rest_route(
		CHC_NS,
		'/calls',
		array(
			'methods'             => 'POST',
			'callback'            => 'chc_rest_receive',
			'permission_callback' => 'chc_verify_signature',
		)
	);
	register_rest_route(
		CHC_NS,
		'/calls/(?P<id>\d+)/audio',
		array(
			'methods'             => 'GET',
			'callback'            => 'chc_rest_audio',
			'permission_callback' => function () {
				return current_user_can( 'chc_play_recordings' );
			},
		)
	);
	register_rest_route(
		CHC_NS,
		'/calls/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'chc_rest_update',
			'permission_callback' => function () {
				return current_user_can( 'chc_edit_calls' );
			},
		)
	);
	register_rest_route(
		CHC_NS,
		'/calls/(?P<id>\d+)',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'chc_rest_delete',
			'permission_callback' => function () {
				return current_user_can( 'chc_delete_calls' );
			},
		)
	);
} );

/**
 * Deletes one call record from the inbox (admins only). The recording itself
 * stays with the phone provider and follows its own retention.
 */
function chc_rest_delete( WP_REST_Request $req ) {
	$id   = (int) $req['id'];
	$post = get_post( $id );
	if ( ! $post || CHC_CPT !== $post->post_type ) {
		return new WP_Error( 'chc_not_found', 'Not found', array( 'status' => 404 ) );
	}
	wp_delete_post( $id, true );
	return array( 'ok' => true, 'open_count' => chc_open_count() );
}

/**
 * Accepts a request only if it was signed with this site's key in the last
 * five minutes. Twilio signs HMAC-SHA256( key, "{timestamp}.{raw body}" ).
 */
function chc_verify_signature( WP_REST_Request $request ) {
	$key = (string) chc_setting( 'site_key' );
	if ( 64 !== strlen( $key ) ) {
		return new WP_Error( 'chc_not_configured', 'Site key not set', array( 'status' => 503 ) );
	}
	$ts  = (string) $request->get_header( 'x-ch-timestamp' );
	$sig = strtolower( (string) $request->get_header( 'x-ch-signature' ) );
	if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > 300 ) {
		return new WP_Error( 'chc_stale', 'Missing or expired timestamp', array( 'status' => 401 ) );
	}
	$expected = hash_hmac( 'sha256', $ts . '.' . $request->get_body(), $key );
	if ( ! $sig || ! hash_equals( $expected, $sig ) ) {
		return new WP_Error( 'chc_bad_signature', 'Invalid signature', array( 'status' => 401 ) );
	}
	return true;
}

function chc_rest_receive( WP_REST_Request $request ) {
	$data = json_decode( $request->get_body(), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'chc_bad_json', 'Invalid JSON', array( 'status' => 400 ) );
	}

	// Only accept calls to this site's own numbers.
	$allowed = chc_allowed_numbers();
	$number  = chc_digits( isset( $data['business_number'] ) ? $data['business_number'] : '' );
	if ( ! $allowed || ! in_array( $number, $allowed, true ) ) {
		return new WP_Error( 'chc_wrong_number', 'This site does not accept calls for that number', array( 'status' => 403 ) );
	}

	$id = chc_upsert_call( $data );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/**
 * Streams a voicemail to a signed-in user. The audio comes from the Twilio
 * playback service through this server; no playable link is ever sent to the
 * browser, and every play is logged.
 */
function chc_rest_audio( WP_REST_Request $request ) {
	$id = absint( $request['id'] );
	if ( CHC_CPT !== get_post_type( $id ) ) {
		return new WP_Error( 'chc_not_found', 'Call not found', array( 'status' => 404 ) );
	}
	$rec = (string) get_post_meta( $id, '_chc_recording_sid', true );
	if ( ! $rec ) {
		return new WP_Error( 'chc_no_recording', 'No voicemail on this call', array( 'status' => 404 ) );
	}
	$key = (string) chc_setting( 'site_key' );
	$url = (string) chc_setting( 'playback_url' );
	if ( 64 !== strlen( $key ) || ! $url ) {
		return new WP_Error( 'chc_not_configured', 'Playback is not set up', array( 'status' => 503 ) );
	}

	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$ts   = (string) time();
	$sig  = hash_hmac( 'sha256', $ts . '.' . $rec . '.' . $host, $key );

	$response = wp_remote_get(
		add_query_arg( array( 'recording_sid' => $rec, 'host' => $host, 'ts' => $ts, 'sig' => $sig ), $url ),
		array( 'timeout' => 25 )
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'chc_playback_failed', 'Could not reach the playback service', array( 'status' => 502 ) );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
	if ( 200 !== $code || 0 !== strpos( $type, 'audio/' ) ) {
		$status = 404 === $code ? 410 : 502;
		return new WP_Error( 'chc_playback_failed', 410 === $status ? 'This voicemail is no longer available' : 'Playback failed', array( 'status' => $status ) );
	}

	chc_log_access( $id, 'played' );

	$body = wp_remote_retrieve_body( $response );
	nocache_headers();
	header( 'Content-Type: audio/mpeg' );
	header( 'Content-Length: ' . strlen( $body ) );
	header( 'Cache-Control: no-store, private, max-age=0' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- binary audio
	exit;
}

function chc_rest_update( WP_REST_Request $request ) {
	$id = absint( $request['id'] );
	if ( CHC_CPT !== get_post_type( $id ) ) {
		return new WP_Error( 'chc_not_found', 'Call not found', array( 'status' => 404 ) );
	}
	$changed = array();

	if ( null !== $request->get_param( 'status' ) ) {
		$status = sanitize_key( $request->get_param( 'status' ) );
		if ( ! isset( chc_options( 'status_options' )[ $status ] ) ) {
			return new WP_Error( 'chc_bad_status', 'That status is not available', array( 'status' => 400 ) );
		}
		update_post_meta( $id, '_chc_status', $status );
		update_post_meta( $id, '_chc_handled_by', get_current_user_id() );
		update_post_meta( $id, '_chc_handled_at', time() );
		chc_log_access( $id, 'status', $status );
		$changed['status'] = $status;
	}

	if ( null !== $request->get_param( 'category' ) ) {
		$cat = sanitize_key( $request->get_param( 'category' ) );
		if ( '' !== $cat && ! isset( chc_options( 'category_options' )[ $cat ] ) ) {
			return new WP_Error( 'chc_bad_category', 'That category is not available', array( 'status' => 400 ) );
		}
		update_post_meta( $id, '_chc_category', $cat );
		chc_log_access( $id, 'category', $cat );
		$changed['category'] = $cat;
	}

	$user = wp_get_current_user();
	return rest_ensure_response(
		array(
			'ok'         => true,
			'changed'    => $changed,
			'handled_by' => $user ? $user->display_name : '',
			'open_count' => chc_open_count(),
		)
	);
}
