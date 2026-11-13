<?php
/**
 * Plugin Name:       Click Hive Calls
 * Description:       Call inbox for phone systems managed by Click Hive Marketing. Shows every call to this site's business number, plays voicemails securely, and tracks follow-up.
 * Version:           1.7.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Click Hive Marketing
 * Author URI:        https://clickhivemarketing.com
 * License:           GPL-2.0-or-later
 * Text Domain:       clickhive-calls
 * Update URI:        https://github.com/vinwilli72/clickhive-calls
 */

defined( 'ABSPATH' ) || exit;

define( 'CHC_VERSION', '1.7.1' );
define( 'CHC_FILE', __FILE__ );
define( 'CHC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHC_URL', plugin_dir_url( __FILE__ ) );

require_once CHC_DIR . 'includes/settings.php';
require_once CHC_DIR . 'includes/data.php';
require_once CHC_DIR . 'includes/rest.php';
require_once CHC_DIR . 'includes/inbox.php';
require_once CHC_DIR . 'includes/line-settings.php';

// Updates: each site checks the GitHub releases for vinwilli72/clickhive-calls and
// offers "Update now" under Plugins. Settings and calls live in the database and
// are untouched by updates.
require_once CHC_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$chc_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/vinwilli72/clickhive-calls/',
	__FILE__,
	'clickhive-calls'
);
$chc_updates->getVcsApi()->enableReleaseAssets( '/clickhive-calls\.zip$/' );

// Shortcuts to the inbox and settings on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=clickhive-calls' ) ) . '">' . esc_html__( 'Call Inbox', 'clickhive-calls' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'options-general.php?page=chc-settings' ) ) . '">' . esc_html__( 'Settings', 'clickhive-calls' ) . '</a>'
	);
	return $links;
} );

register_activation_hook( __FILE__, 'chc_activate' );
register_deactivation_hook( __FILE__, 'chc_deactivate' );

/**
 * Creates the Call Manager role and gives administrators the same abilities.
 * Call records are kept if the plugin is deactivated or deleted.
 */
function chc_manager_caps() {
	return array(
		'read'                => true,
		'chc_view_calls'      => true,
		'chc_play_recordings' => true,
		'chc_edit_calls'      => true,
	);
}

function chc_activate() {
	$caps = chc_manager_caps();
	remove_role( 'chc_call_manager' );
	add_role( 'chc_call_manager', __( 'Call Manager', 'clickhive-calls' ), $caps );

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array_keys( $caps ) as $cap ) {
			$admin->add_cap( $cap );
		}
		$admin->add_cap( 'chc_delete_calls' ); // administrators and clients, never Call Managers
		$admin->add_cap( CHC_LINES_CAP );
	}

	if ( ! wp_next_scheduled( 'chc_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'chc_daily_cleanup' );
	}
}

// Updates don't re-run activation, so give administrators any ability added later.
add_action( 'admin_init', function () {
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array( 'chc_delete_calls', CHC_LINES_CAP ) as $cap ) {
			if ( ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
	}
	chc_grant_client_role();
} );

/**
 * Sites using Click Hive Utility's Client Access have a "client" role. Clients get
 * the same call abilities as a Call Manager (see calls, play voicemails, set the
 * status) plus Line Settings (who answers, vacation dates) and deleting calls they
 * don't want to keep, but never the settings page, which stays manage_options.
 * Call Managers (front-desk staff) get neither Line Settings nor delete.
 * Checked on every admin load because Click Hive Utility rebuilds its role from
 * scratch when its settings change, which drops abilities added by other plugins.
 */
function chc_grant_client_role() {
	$client = get_role( 'client' );
	if ( ! $client ) {
		return;
	}
	foreach ( array_merge( array_keys( chc_manager_caps() ), array( CHC_LINES_CAP, 'chc_delete_calls' ) ) as $cap ) {
		if ( ! $client->has_cap( $cap ) ) {
			$client->add_cap( $cap );
		}
	}
}

function chc_deactivate() {
	wp_clear_scheduled_hook( 'chc_daily_cleanup' );
}
