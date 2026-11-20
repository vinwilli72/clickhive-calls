<?php
/**
 * Scorecard above the call inbox, and a compact calls summary for Dashboard > Home
 * and the Click Hive Client Dashboard.
 */

defined( 'ABSPATH' ) || exit;

/** Calls received in a calendar month (site time zone). $months_back 0 = this month. */
function chc_count_month( $months_back = 0, $meta = array() ) {
	$month = current_datetime()->modify( 'first day of this month' )->setTime( 0, 0 );
	if ( $months_back ) {
		$month = $month->modify( '-' . absint( $months_back ) . ' month' );
	}
	$q = new WP_Query(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'date_query'     => array(
				array(
					'year'  => (int) $month->format( 'Y' ),
					'month' => (int) $month->format( 'n' ),
				),
			),
		)
	);
	return (int) $q->found_posts;
}

/** Numbers for the scorecard and the dashboard summary. */
function chc_scorecard_numbers() {
	$completed = chc_completed_statuses();
	return array(
		'this_month' => chc_count_month( 0 ),
		'completed'  => $completed ? chc_count_month( 0, array( array( 'key' => '_chc_status', 'value' => $completed, 'compare' => 'IN' ) ) ) : 0,
		'unheard'    => chc_count( chc_view_meta( 'unheard' ) ),
		'last_month' => chc_count_month( 1 ),
		'open'       => chc_open_count(),
	);
}

/**
 * One scorecard tile.
 *
 * @param string $label Tile label.
 * @param int    $value Number.
 * @param string $url   Optional link.
 * @param string $class Optional extra class.
 */
function chc_tile( $label, $value, $url = '', $class = '' ) {
	$tag   = $url ? 'a' : 'div';
	$href  = $url ? ' href="' . esc_url( $url ) . '"' : '';
	$class = trim( 'chc-tile ' . $class );
	return sprintf(
		'<%1$s class="%2$s"%3$s><span class="chc-tile-value">%4$s</span><span class="chc-tile-label">%5$s</span></%1$s>',
		$tag,
		esc_attr( $class ),
		$href,
		esc_html( number_format_i18n( (int) $value ) ),
		esc_html( $label )
	);
}

/** The four-tile scorecard shown above the call inbox. */
function chc_render_scorecard() {
	$n    = chc_scorecard_numbers();
	$base = admin_url( 'admin.php?page=clickhive-calls' );
	$done = chc_completed_statuses();

	echo '<div class="chc-scorecard" role="group" aria-label="' . esc_attr__( 'Call summary', 'clickhive-calls' ) . '">';
	echo chc_tile( __( 'Calls this month', 'clickhive-calls' ), $n['this_month'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in chc_tile()
	echo chc_tile( __( 'Completed calls', 'clickhive-calls' ), $n['completed'], 1 === count( $done ) ? add_query_arg( 'status', $done[0], $base ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo chc_tile( __( 'Unheard voicemails', 'clickhive-calls' ), $n['unheard'], add_query_arg( 'view', 'unheard', $base ), $n['unheard'] ? 'chc-tile-alert' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo chc_tile( __( 'Calls last month', 'clickhive-calls' ), $n['last_month'] ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

// ---------------------------------------------------------------------------
// Dashboard summary
// ---------------------------------------------------------------------------

function chc_dashboard_enabled() {
	return (bool) chc_setting( 'dashboard_card' ) && current_user_can( 'chc_view_calls' );
}

/** The newest call, or 0. */
function chc_latest_call_id() {
	$ids = get_posts(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/** Compact summary: calls this month, needing follow-up, unheard voicemails, latest call. */
function chc_render_dashboard_card() {
	$n    = chc_scorecard_numbers();
	$base = admin_url( 'admin.php?page=clickhive-calls' );

	echo '<div class="chc-dash">';
	echo '<div class="chc-scorecard chc-scorecard-compact">';
	echo chc_tile( __( 'Calls this month', 'clickhive-calls' ), $n['this_month'], $base ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo chc_tile( __( 'Need follow-up', 'clickhive-calls' ), $n['open'], add_query_arg( 'view', 'open', $base ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo chc_tile( __( 'Unheard voicemails', 'clickhive-calls' ), $n['unheard'], add_query_arg( 'view', 'unheard', $base ), $n['unheard'] ? 'chc-tile-alert' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';

	$id = chc_latest_call_id();
	if ( $id ) {
		$outcome = (string) get_post_meta( $id, '_chc_outcome', true );
		$label   = isset( CHC_OUTCOMES[ $outcome ] ) ? CHC_OUTCOMES[ $outcome ] : '';
		$caller  = (string) get_post_meta( $id, '_chc_caller', true );
		$when    = get_post_time( 'U', true, $id );
		echo '<p class="chc-dash-latest"><span class="chc-dash-latest-label">' . esc_html__( 'Latest call', 'clickhive-calls' ) . '</span> ';
		echo '<strong>' . esc_html( $caller ? chc_format_phone( $caller ) : __( 'Unknown caller', 'clickhive-calls' ) ) . '</strong>';
		echo ' &middot; ' . esc_html( sprintf( __( '%s ago', 'clickhive-calls' ), human_time_diff( (int) $when ) ) );
		if ( $label ) {
			echo ' <span class="chc-badge chc-' . esc_attr( $outcome ) . '">' . esc_html( $label ) . '</span>';
		}
		echo '</p>';
	} else {
		echo '<p class="chc-dash-latest">' . esc_html__( 'No calls yet.', 'clickhive-calls' ) . '</p>';
	}

	echo '<p class="chc-dash-actions"><a class="button" href="' . esc_url( $base ) . '">' . esc_html( sprintf( __( 'Open %s', 'clickhive-calls' ), chc_setting( 'inbox_title' ) ) ) . '</a></p>';
	echo '</div>';
}

// Dashboard > Home widget.
add_action( 'wp_dashboard_setup', function () {
	if ( chc_dashboard_enabled() ) {
		wp_add_dashboard_widget( 'chc_calls_summary', chc_setting( 'inbox_title' ), 'chc_render_dashboard_card' );
	}
} );

// Click Hive Utility's full Client Dashboard page (Home already has the widget above).
add_action( 'chu_client_dashboard_after_links', function ( $full_page = true ) {
	if ( $full_page && chc_dashboard_enabled() ) {
		echo '<h2>' . esc_html( chc_setting( 'inbox_title' ) ) . '</h2><section class="chm-card chc-dash-card">';
		chc_render_dashboard_card();
		echo '</section>';
	}
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$dashboards = array( 'index.php', 'dashboard_page_chm-client-dashboard' );
	if ( 'toplevel_page_clickhive-calls' === $hook || ( in_array( $hook, $dashboards, true ) && chc_dashboard_enabled() ) ) {
		wp_enqueue_style( 'chc-scorecard', CHC_URL . 'assets/scorecard.css', array(), CHC_VERSION );
	}
} );
