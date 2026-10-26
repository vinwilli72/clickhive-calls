<?php
/**
 * The inbox screen: a simple list of calls with playback and follow-up status.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Click Hive mark as a one-colour menu icon. WordPress recolours it to
 * match the admin colour scheme, the same as its own icons.
 */
function chc_menu_icon() {
	$svg = file_get_contents( CHC_DIR . 'assets/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : 'dashicons-phone';
}

add_action( 'admin_menu', function () {
	$count = current_user_can( 'chc_view_calls' ) ? chc_open_count() : 0;
	$title = chc_setting( 'inbox_title' );
	$badge = $count ? sprintf( ' <span class="awaiting-mod chc-count count-%1$d"><span class="pending-count">%1$d</span></span>', $count ) : '';
	add_menu_page( $title, esc_html( $title ) . $badge, 'chc_view_calls', 'clickhive-calls', 'chc_render_inbox', chc_menu_icon(), 3 );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_clickhive-calls' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'chc-inbox', CHC_URL . 'assets/inbox.css', array(), CHC_VERSION );
	wp_enqueue_script( 'chc-inbox', CHC_URL . 'assets/inbox.js', array(), CHC_VERSION, true );
	wp_localize_script(
		'chc-inbox',
		'CHC',
		array(
			'root'  => esc_url_raw( rest_url( CHC_NS . '/calls/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'text'  => array(
				'loading'  => __( 'Loading…', 'clickhive-calls' ),
				'saved'    => __( 'Saved', 'clickhive-calls' ),
				'failed'   => __( 'Could not save. Refresh and try again.', 'clickhive-calls' ),
				'noplay'   => __( 'This voicemail could not be played.', 'clickhive-calls' ),
				'expired'  => __( 'This voicemail is no longer available.', 'clickhive-calls' ),
			),
		)
	);
} );

function chc_render_inbox() {
	if ( ! current_user_can( 'chc_view_calls' ) ) {
		wp_die( esc_html__( 'You do not have access to calls.', 'clickhive-calls' ) );
	}

	$statuses   = chc_options( 'status_options' );
	$categories = chc_options( 'category_options' );
	$outcomes   = CHC_OUTCOMES;
	$can_edit   = current_user_can( 'chc_edit_calls' );
	$can_play   = current_user_can( 'chc_play_recordings' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
	$f_status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$f_outcome = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '';
	$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$f_line    = isset( $_GET['line'] ) ? preg_replace( '/\D/', '', wp_unslash( $_GET['line'] ) ) : '';
	$view      = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
	$search    = isset( $_GET['s'] ) ? substr( preg_replace( '/\D/', '', wp_unslash( $_GET['s'] ) ), 0, 15 ) : '';
	$orderby   = isset( $_GET['orderby'] ) && 'length' === $_GET['orderby'] ? 'length' : 'date';
	$order     = isset( $_GET['order'] ) && 'asc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'ASC' : 'DESC';
	// phpcs:enable

	$views = array(
		'all'       => __( 'All', 'clickhive-calls' ),
		'open'      => sprintf( __( 'Needs follow-up (%s)', 'clickhive-calls' ), chc_option_label( 'status_options', chc_default_status() ) ),
		'unheard'   => __( 'Unheard voicemails', 'clickhive-calls' ),
		'voicemail' => __( 'Voicemails', 'clickhive-calls' ),
		'missed'    => __( 'Missed', 'clickhive-calls' ),
	);
	if ( ! isset( $views[ $view ] ) ) {
		$view = 'all';
	}

	$numbers = chc_allowed_numbers();
	$multi   = count( $numbers ) > 1;
	$lines   = $multi ? chc_line_names( $numbers ) : array();

	$meta = chc_view_meta( $view );
	if ( '' !== $search ) {
		$meta[] = array( 'key' => '_chc_caller', 'value' => $search, 'compare' => 'LIKE' );
	}
	if ( $f_status && isset( $statuses[ $f_status ] ) ) {
		$meta[] = array( 'key' => '_chc_status', 'value' => $f_status );
	}
	if ( $f_outcome && isset( $outcomes[ $f_outcome ] ) ) {
		$meta[] = array( 'key' => '_chc_outcome', 'value' => $f_outcome );
	}
	if ( $multi && $f_line && isset( $lines[ $f_line ] ) ) {
		$meta[] = array( 'key' => '_chc_business_number', 'value' => $f_line );
	}

	$q = new WP_Query(
		array(
			'post_type'      => CHC_CPT,
			'post_status'    => 'private',
			'posts_per_page' => 25,
			'paged'          => $paged,
			'orderby'        => 'length' === $orderby ? array( 'meta_value_num' => $order, 'date' => 'DESC' ) : 'date',
			'meta_key'       => 'length' === $orderby ? '_chc_duration' : '', // phpcs:ignore WordPress.DB.SlowDBQuery
			'order'          => $order,
			'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);

	$base    = admin_url( 'admin.php?page=clickhive-calls' );
	$keep    = array_filter(
		array(
			'view'    => 'all' !== $view ? $view : '',
			'status'  => $f_status,
			'outcome' => $f_outcome,
			'line'    => $f_line,
			's'       => $search,
			'orderby' => 'date' !== $orderby ? $orderby : '',
			'order'   => 'DESC' !== $order ? 'asc' : '',
		)
	);
	$current = add_query_arg( $keep, $base );
	$sort    = function ( $col, $label ) use ( $orderby, $order, $keep, $base ) {
		$active = $orderby === $col;
		$next   = $active && 'DESC' === $order ? 'asc' : 'desc';
		$args   = array_merge( $keep, array( 'orderby' => $col, 'order' => $next ) );
		unset( $args['paged'] );
		$arrow = $active ? ( 'DESC' === $order ? 'arrow-down-alt2' : 'arrow-up-alt2' ) : 'sort';
		$title = 'asc' === $next ? __( 'Sort oldest or shortest first', 'clickhive-calls' ) : __( 'Sort newest or longest first', 'clickhive-calls' );
		return sprintf(
			'<a class="chc-sort%s" href="%s" title="%s">%s <span class="dashicons dashicons-%s" aria-hidden="true"></span></a>',
			$active ? ' chc-sorted' : '',
			esc_url( add_query_arg( $args, $base ) ),
			esc_attr( $title ),
			esc_html( $label ),
			esc_attr( $arrow )
		);
	};
	?>
	<div class="wrap chc-wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( chc_setting( 'inbox_title' ) ); ?></h1>

		<?php if ( chc_setting( 'phi_mode' ) ) : ?>
			<p class="chc-phi-note"><?php esc_html_e( 'This inbox contains patient information. Lock your screen or sign out when you step away.', 'clickhive-calls' ); ?></p>
		<?php endif; ?>

		<ul class="subsubsub chc-views">
			<?php
			$i = 0;
			foreach ( $views as $key => $label ) :
				$n    = chc_count( chc_view_meta( $key ) );
				$args = 'all' === $key ? array() : array( 'view' => $key );
				?>
				<li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url( add_query_arg( $args, $base ) ); ?>" class="<?php echo $view === $key ? 'current' : ''; ?><?php echo 'unheard' === $key && $n ? ' chc-has-unheard' : ''; ?>" data-view="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> <span class="count">(<span class="chc-view-count"><?php echo esc_html( $n ); ?></span>)</span></a></li>
			<?php endforeach; ?>
		</ul>

		<form method="get" class="chc-filters">
			<input type="hidden" name="page" value="clickhive-calls">
			<?php if ( 'all' !== $view ) : ?><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>"><?php endif; ?>
			<?php if ( 'date' !== $orderby || 'DESC' !== $order ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr( strtolower( $order ) ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="chc-search"><?php esc_html_e( 'Search by phone number', 'clickhive-calls' ); ?></label>
			<input type="search" id="chc-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search phone number', 'clickhive-calls' ); ?>" inputmode="tel">
			<label class="screen-reader-text" for="chc-f-status"><?php echo esc_html( chc_setting( 'status_label' ) ); ?></label>
			<select name="status" id="chc-f-status">
				<option value=""><?php echo esc_html( sprintf( __( 'Any %s', 'clickhive-calls' ), strtolower( chc_setting( 'status_label' ) ) ) ); ?></option>
				<?php foreach ( $statuses as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f_status, $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="chc-f-outcome"><?php esc_html_e( 'Result', 'clickhive-calls' ); ?></label>
			<select name="outcome" id="chc-f-outcome">
				<option value=""><?php esc_html_e( 'Any result', 'clickhive-calls' ); ?></option>
				<?php foreach ( $outcomes as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f_outcome, $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $multi ) : ?>
				<label class="screen-reader-text" for="chc-f-line"><?php esc_html_e( 'Line', 'clickhive-calls' ); ?></label>
				<select name="line" id="chc-f-line">
					<option value=""><?php esc_html_e( 'All lines', 'clickhive-calls' ); ?></option>
					<?php foreach ( $lines as $num => $label ) : ?>
						<option value="<?php echo esc_attr( $num ); ?>" <?php selected( $f_line, $num ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<button class="button"><?php esc_html_e( 'Filter', 'clickhive-calls' ); ?></button>
			<?php if ( $f_status || $f_outcome || $f_line || $search || 'all' !== $view ) : ?>
				<a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'clickhive-calls' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( ! $q->have_posts() ) : ?>
			<div class="chc-empty">
				<p><?php echo ( $f_status || $f_outcome || $f_line || $search || 'all' !== $view ) ? esc_html__( 'No calls match these filters.', 'clickhive-calls' ) : esc_html__( 'No calls yet. New calls appear here as soon as they end.', 'clickhive-calls' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat chc-table">
				<thead>
					<tr>
						<th scope="col" aria-sort="<?php echo 'date' === $orderby ? ( 'DESC' === $order ? 'descending' : 'ascending' ) : 'none'; ?>"><?php echo $sort( 'date', __( 'Received', 'clickhive-calls' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $sort ?></th>
						<?php if ( $multi ) : ?>
							<th scope="col"><?php esc_html_e( 'Line', 'clickhive-calls' ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Caller', 'clickhive-calls' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'clickhive-calls' ); ?></th>
						<th scope="col" aria-sort="<?php echo 'length' === $orderby ? ( 'DESC' === $order ? 'descending' : 'ascending' ) : 'none'; ?>"><?php echo $sort( 'length', __( 'Length', 'clickhive-calls' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $sort ?></th>
						<th scope="col"><?php esc_html_e( 'Voicemail', 'clickhive-calls' ); ?></th>
						<th scope="col"><?php echo esc_html( chc_setting( 'status_label' ) ); ?></th>
						<?php if ( $categories ) : ?>
							<th scope="col"><?php echo esc_html( chc_setting( 'category_label' ) ); ?></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Updated by', 'clickhive-calls' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Details', 'clickhive-calls' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $q->posts as $post ) :
					$id       = $post->ID;
					$caller   = get_post_meta( $id, '_chc_caller', true );
					$outcome  = get_post_meta( $id, '_chc_outcome', true );
					$duration = (int) get_post_meta( $id, '_chc_duration', true );
					$rec      = get_post_meta( $id, '_chc_recording_sid', true );
					$unheard  = $rec && ! get_post_meta( $id, '_chc_heard', true );
					$status   = get_post_meta( $id, '_chc_status', true );
					$category = get_post_meta( $id, '_chc_category', true );
					$by       = (int) get_post_meta( $id, '_chc_handled_by', true );
					$by_user  = $by ? get_userdata( $by ) : null;
					$when     = strtotime( $post->post_date_gmt . ' UTC' );
					$tel      = preg_replace( '/[^0-9+]/', '', (string) $caller );
					$line_num = (string) get_post_meta( $id, '_chc_business_number', true );
					$columns  = 8 + ( $multi ? 1 : 0 ) + ( $categories ? 1 : 0 );
					?>
					<tr data-id="<?php echo esc_attr( $id ); ?>" class="chc-row<?php echo $status === chc_default_status() ? ' chc-open' : ''; ?>">
						<td data-label="<?php esc_attr_e( 'Received', 'clickhive-calls' ); ?>">
							<span title="<?php echo esc_attr( wp_date( 'l, F j, Y g:i a', $when ) ); ?>"><?php echo esc_html( wp_date( 'M j, g:i a', $when ) ); ?></span>
						</td>
						<?php if ( $multi ) : ?>
							<td data-label="<?php esc_attr_e( 'Line', 'clickhive-calls' ); ?>" class="chc-line">
								<?php echo esc_html( isset( $lines[ $line_num ] ) ? $lines[ $line_num ] : chc_format_phone( $line_num ) ); ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Caller', 'clickhive-calls' ); ?>">
							<?php if ( $tel ) : ?>
								<a href="tel:<?php echo esc_attr( $tel ); ?>" class="chc-caller"><?php echo esc_html( chc_format_phone( $caller ) ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Unknown', 'clickhive-calls' ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Result', 'clickhive-calls' ); ?>">
							<span class="chc-badge chc-<?php echo esc_attr( $outcome ); ?>"><?php echo esc_html( isset( $outcomes[ $outcome ] ) ? $outcomes[ $outcome ] : $outcome ); ?></span>
							<?php if ( 'text_requested' !== $outcome && get_post_meta( $id, '_chc_text_sent', true ) ) : ?>
								<span class="chc-text-tag"><?php esc_html_e( 'Text sent', 'clickhive-calls' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Length', 'clickhive-calls' ); ?>"<?php echo $duration ? '' : ' class="chc-blank"'; ?>><?php echo $duration ? esc_html( sprintf( '%d:%02d', floor( $duration / 60 ), $duration % 60 ) ) : '&ndash;'; ?></td>
						<td data-label="<?php esc_attr_e( 'Voicemail', 'clickhive-calls' ); ?>" class="chc-play-cell<?php echo ( $rec && $can_play ) ? '' : ' chc-blank'; ?>">
							<?php if ( $rec && $can_play ) : ?>
								<button type="button" class="button chc-play<?php echo $unheard ? ' chc-unheard' : ''; ?>" data-id="<?php echo esc_attr( $id ); ?>"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span> <?php esc_html_e( 'Play', 'clickhive-calls' ); ?></button>
								<?php if ( $unheard ) : ?><span class="chc-unheard-tag"><?php esc_html_e( 'Unheard', 'clickhive-calls' ); ?></span><?php endif; ?>
							<?php else : ?>
								&ndash;
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( chc_setting( 'status_label' ) ); ?>">
							<?php chc_render_select( 'status', $statuses, $status, $can_edit, false ); ?>
						</td>
						<?php if ( $categories ) : ?>
							<td data-label="<?php echo esc_attr( chc_setting( 'category_label' ) ); ?>">
								<?php chc_render_select( 'category', $categories, $category, $can_edit, true ); ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Updated by', 'clickhive-calls' ); ?>" class="chc-by<?php echo $by_user ? '' : ' chc-blank'; ?>">
							<?php echo $by_user ? esc_html( $by_user->display_name ) : '&ndash;'; ?>
						</td>
						<td class="chc-toggle-cell">
							<button type="button" class="button-link chc-toggle" aria-expanded="false" aria-controls="chc-details-<?php echo esc_attr( $id ); ?>">
								<span class="chc-toggle-label"><?php esc_html_e( 'Details', 'clickhive-calls' ); ?></span>
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
							</button>
						</td>
					</tr>
					<tr class="chc-details" id="chc-details-<?php echo esc_attr( $id ); ?>" hidden>
						<td colspan="<?php echo esc_attr( $columns ); ?>"><?php chc_render_details( $id, $when, $lines ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) $q->max_num_pages;
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', $current ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Line names for this site's numbers, taken from the most recent call on each
 * line (Twilio sends the client name from the sheet). Falls back to the number.
 *
 * @return array 10-digit number => label
 */
function chc_line_names( $numbers ) {
	$out = array();
	foreach ( $numbers as $num ) {
		$ids  = get_posts(
			array(
				'post_type'      => CHC_CPT,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_chc_business_number', 'value' => $num ),
					array( 'key' => '_chc_line_name', 'compare' => 'EXISTS' ),
				),
			)
		);
		$name        = $ids ? get_post_meta( $ids[0], '_chc_line_name', true ) : '';
		$out[ $num ] = $name ? $name : chc_format_phone( $num );
	}
	return $out;
}

/** The expanded view of one call: where it went, who answered, who was told, what happened since. */
function chc_render_details( $id, $when, $lines ) {
	$line_num  = (string) get_post_meta( $id, '_chc_business_number', true );
	$line_name = (string) get_post_meta( $id, '_chc_line_name', true );
	$receivers = get_post_meta( $id, '_chc_receivers', true );
	$answered  = (string) get_post_meta( $id, '_chc_answered_by', true );
	$outcome   = (string) get_post_meta( $id, '_chc_outcome', true );
	$after     = get_post_meta( $id, '_chc_after_hours', true );
	$reason    = (string) get_post_meta( $id, '_chc_hours_reason', true );
	$notice    = (string) get_post_meta( $id, '_chc_notice_status', true );
	$notice_to = (string) get_post_meta( $id, '_chc_notice_to', true );
	$log       = get_post_meta( $id, '_chc_access_log', true );
	$has_route = is_array( $receivers ) || '' !== (string) $after;

	$rows = array();
	$rows[ __( 'Received', 'clickhive-calls' ) ] = esc_html( wp_date( 'l, F j, Y \a\t g:i a', $when ) );
	if ( $line_num ) {
		$label = $line_name ? $line_name . ' · ' . chc_format_phone( $line_num ) : chc_format_phone( $line_num );
		$rows[ __( 'Line called', 'clickhive-calls' ) ] = esc_html( $label );
	}
	if ( get_post_meta( $id, '_chc_text_sent', true ) ) {
		$rows[ __( 'Text', 'clickhive-calls' ) ] = 'text_requested' === $outcome
			? esc_html__( 'Caller asked for a text; it was sent from this line.', 'clickhive-calls' )
			: esc_html__( 'Caller asked for a text (sent), then left this voicemail.', 'clickhive-calls' );
	}

	if ( $has_route ) {
		if ( '' !== (string) $after ) {
			$hours = $after
				? ( 'holiday' === $reason ? __( 'After hours (holiday)', 'clickhive-calls' ) : __( 'After hours', 'clickhive-calls' ) )
				: __( 'During business hours', 'clickhive-calls' );
			$rows[ __( 'Business hours', 'clickhive-calls' ) ] = esc_html( $hours );
		}
		if ( is_array( $receivers ) && $receivers ) {
			$rang = array();
			foreach ( $receivers as $i => $r ) {
				$is   = chc_digits( $r ) === chc_digits( $answered );
				$text = sprintf( '%d. %s', $i + 1, chc_format_phone( $r ) ) . ( $is ? ' (' . __( 'answered', 'clickhive-calls' ) . ')' : '' );
				$rang[] = '<span class="chc-receiver' . ( $is ? ' chc-answered' : '' ) . '">' . esc_html( $text ) . '</span>';
			}
			$label = $after ? __( 'Set to ring', 'clickhive-calls' ) : __( 'Rang', 'clickhive-calls' );
			$rows[ $label ] = implode( '<br>', $rang );
			if ( $after ) {
				$rows[ $label ] .= '<br><span class="chc-muted">' . esc_html__( 'After hours, callers go straight to voicemail.', 'clickhive-calls' ) . '</span>';
			}
		}
		if ( 'answered' !== $outcome && ! $answered && ! $after && is_array( $receivers ) && $receivers ) {
			$rows[ __( 'Answered by', 'clickhive-calls' ) ] = esc_html__( 'No one answered', 'clickhive-calls' );
		}
		if ( $notice ) {
			$texts = array(
				'sent'          => sprintf( __( 'Emailed to %s', 'clickhive-calls' ), $notice_to ),
				'failed'        => sprintf( __( 'Email to %s FAILED. Click Hive was alerted.', 'clickhive-calls' ), $notice_to ),
				'skipped_short' => __( 'No email (call under 5 seconds, usually a misdial)', 'clickhive-calls' ),
			);
			$rows[ __( 'Notice', 'clickhive-calls' ) ] = esc_html( isset( $texts[ $notice ] ) ? $texts[ $notice ] : $notice );
		}
	}

	echo '<div class="chc-detail-grid">';
	echo '<dl>';
	foreach ( $rows as $k => $v ) {
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $k ), wp_kses( $v, array( 'br' => array(), 'span' => array( 'class' => array() ) ) ) );
	}
	echo '</dl>';
	if ( ! $has_route ) {
		echo '<p class="chc-muted">' . esc_html__( 'Routing details were not recorded for this call. Calls from now on include them.', 'clickhive-calls' ) . '</p>';
	}

	echo '<div class="chc-history"><h4>' . esc_html__( 'History', 'clickhive-calls' ) . '</h4>';
	if ( is_array( $log ) && $log ) {
		$statuses = chc_options( 'status_options' );
		echo '<ul>';
		foreach ( array_reverse( array_slice( $log, -15 ) ) as $e ) {
			$user = ! empty( $e['user'] ) ? get_userdata( (int) $e['user'] ) : null;
			$name = $user ? $user->display_name : __( 'Someone', 'clickhive-calls' );
			switch ( $e['action'] ) {
				case 'played':
					$what = __( 'played the voicemail', 'clickhive-calls' );
					break;
				case 'status':
					$what = sprintf( __( 'set %1$s to %2$s', 'clickhive-calls' ), strtolower( chc_setting( 'status_label' ) ), chc_option_label( 'status_options', $e['detail'] ) );
					break;
				case 'category':
					$what = '' === (string) $e['detail']
						? sprintf( __( 'cleared %s', 'clickhive-calls' ), strtolower( chc_setting( 'category_label' ) ) )
						: sprintf( __( 'set %1$s to %2$s', 'clickhive-calls' ), strtolower( chc_setting( 'category_label' ) ), chc_option_label( 'category_options', $e['detail'] ) );
					break;
				default:
					$what = (string) $e['action'];
			}
			printf( '<li><strong>%s</strong> %s <span class="chc-muted">%s</span></li>', esc_html( $name ), esc_html( $what ), esc_html( wp_date( 'M j, g:i a', (int) $e['at'] ) ) );
		}
		echo '</ul>';
	} else {
		echo '<p class="chc-muted">' . esc_html__( 'Nothing yet.', 'clickhive-calls' ) . '</p>';
	}
	echo '</div></div>';
}

/**
 * A status or category dropdown. Retired values stay visible on the calls
 * that already have them, but can't be chosen again.
 */
function chc_render_select( $field, $options, $current, $can_edit, $allow_blank ) {
	$setting = 'status' === $field ? 'status_options' : 'category_options';
	if ( ! $can_edit ) {
		echo esc_html( chc_option_label( $setting, $current ) ?: '–' );
		return;
	}
	printf( '<select class="chc-field" data-field="%s" aria-label="%s">', esc_attr( $field ), esc_attr( 'status' === $field ? chc_setting( 'status_label' ) : chc_setting( 'category_label' ) ) );
	if ( $allow_blank ) {
		echo '<option value="">&ndash;</option>';
	}
	if ( $current && ! isset( $options[ $current ] ) ) {
		printf( '<option value="%s" selected disabled>%s</option>', esc_attr( $current ), esc_html( chc_option_label( $setting, $current ) ) );
	}
	foreach ( $options as $k => $label ) {
		printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
	}
	echo '</select>';
}
