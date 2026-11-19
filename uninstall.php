<?php
/**
 * Remove all plugin data when the plugin is deleted from WordPress.
 *
 * On a multisite network every site has its own tables, options, transients and scheduled
 * events: they are removed site by site. Accounts, network options and files are shared by
 * the whole network: they are removed once.
 *
 * @package SiteAgentMCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The Full and Lite editions share their tables and settings. While the other
// edition is still installed, removing this one must not erase its data.
foreach ( array( 'site-agent-mcp/site-agent-mcp.php', 'site-agent-mcp-lite/site-agent-mcp-lite.php' ) as $samcp_edition ) {
	if ( WP_UNINSTALL_PLUGIN !== $samcp_edition && file_exists( WP_PLUGIN_DIR . '/' . $samcp_edition ) ) {
		return;
	}
}

// What one site holds: its tables, options, transients and scheduled events.
$samcp_uninstall_site = static function () {
	global $wpdb;
	foreach ( array( 'clients', 'tokens', 'audit', 'changes' ) as $samcp_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'samcp_' . $samcp_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}
	foreach ( array( 'samcp_db_version', 'samcp_power_enabled', 'samcp_extra_redirects', 'samcp_google_api_key', 'samcp_gtmetrix_api_key', 'samcp_indexnow_key', 'samcp_gsc_client_id', 'samcp_gsc_client_secret', 'samcp_gsc_refresh_token', 'samcp_last_security_audit', 'samcp_access', 'samcp_enabled', 'samcp_pairing_until', 'samcp_allow_extensions', 'samcp_notify', 'samcp_pairing', 'samcp_upgrade_kept', 'samcp_last_security_audit_attempt', 'samcp_header_check' ) as $samcp_option ) {
		delete_option( $samcp_option );
	}
	delete_transient( 'samcp_gsc_access_token' );
	delete_transient( 'samcp_gsc_default_site' );
	delete_transient( 'samcp_gtmetrix_tests' );
	wp_clear_scheduled_hook( 'samcp_daily_cleanup' );
	wp_clear_scheduled_hook( 'samcp_backup_tick' );
	wp_clear_scheduled_hook( 'samcp_release_check' );
	// Site import and export links (Full). Both editions remove them: when Full is deleted while
	// Lite is installed, the lines above return early, and Lite is the last edition left to clean up.
	// An import in progress keeps its folders and tables for the owner.
	delete_option( 'samcp_migration_exports' );
	delete_option( 'samcp_migration_boot' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'samcp_export_revoked_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- revocation marks of export links, one option each
	wp_clear_scheduled_hook( 'samcp_migration_tick' );
	// Backup archives are the owner's data: they stay where they are. Only the pointer goes.
	delete_option( 'samcp_backup_dir' );
	delete_transient( 'samcp_backup_exposure' );
};

if ( is_multisite() ) {
	$samcp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $samcp_sites as $samcp_site ) {
		switch_to_blog( (int) $samcp_site );
		$samcp_uninstall_site();
		restore_current_blog();
	}
} else {
	$samcp_uninstall_site();
}

// Shared by every site of a network: accounts, network options and files.
delete_metadata( 'user', 0, 'samcp_full_info_dismissed', '', true );
delete_metadata( 'user', 0, 'samcp_release_dismissed', '', true );
delete_metadata( 'user', 0, 'samcp_flash_export_link', '', true );
delete_metadata( 'user', 0, 'samcp_flash_migration_detail', '', true );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'samcp_az_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- consent requests never answered, one user meta each
delete_site_option( 'samcp_release_notice' );
delete_site_option( 'samcp_update_expiry_seen' );
delete_site_transient( 'samcp_latest_release' );
if ( is_file( WPMU_PLUGIN_DIR . '/samcp-first-boot.php' ) ) {
	wp_delete_file( WPMU_PLUGIN_DIR . '/samcp-first-boot.php' );
}
// A restorer left at the site root holds the database credentials.
$samcp_restorers = (array) glob( ABSPATH . 'samcp-restore-*.php' );
foreach ( $samcp_restorers as $samcp_restorer ) {
	wp_delete_file( $samcp_restorer );
}
// Without its restorer, nothing could end the maintenance a failed restore left: only that one goes.
$samcp_maintenance = ABSPATH . '.maintenance';
if ( $samcp_restorers && is_file( $samcp_maintenance ) && false !== strpos( (string) file_get_contents( $samcp_maintenance ), '// Site Agent MCP restore.' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
	wp_delete_file( $samcp_maintenance );
}
// The Authorization pass-through block this plugin may have put in .htaccess.
require_once ABSPATH . 'wp-admin/includes/file.php';
$samcp_htaccess = get_home_path() . '.htaccess';
if ( is_file( $samcp_htaccess ) && wp_is_writable( $samcp_htaccess ) ) {
	$samcp_text = (string) file_get_contents( $samcp_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$samcp_new  = (string) preg_replace( '/^# BEGIN Site Agent MCP\R.*?^# END Site Agent MCP\R?/ms', '', $samcp_text );
	if ( $samcp_new !== $samcp_text ) {
		file_put_contents( $samcp_htaccess, $samcp_new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
foreach ( (array) glob( get_home_path() . '.htaccess.samcp-bak-*' ) as $samcp_backup ) {
	wp_delete_file( $samcp_backup );
}
