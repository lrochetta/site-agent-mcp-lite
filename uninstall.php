<?php
/**
 * Remove all plugin data when the plugin is deleted from WordPress.
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

foreach ( array( 'clients', 'tokens', 'audit', 'changes' ) as $samcp_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'samcp_' . $samcp_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
}
foreach ( array( 'samcp_db_version', 'samcp_power_enabled', 'samcp_extra_redirects', 'samcp_google_api_key', 'samcp_gtmetrix_api_key', 'samcp_indexnow_key', 'samcp_gsc_client_id', 'samcp_gsc_client_secret', 'samcp_gsc_refresh_token', 'samcp_last_security_audit', 'samcp_access', 'samcp_enabled', 'samcp_pairing_until', 'samcp_allow_extensions', 'samcp_notify', 'samcp_pairing', 'samcp_upgrade_kept' ) as $samcp_option ) {
	delete_option( $samcp_option );
}
delete_metadata( 'user', 0, 'samcp_full_info_dismissed', '', true );
delete_transient( 'samcp_gsc_access_token' );
delete_transient( 'samcp_gsc_default_site' );
wp_clear_scheduled_hook( 'samcp_daily_cleanup' );
wp_clear_scheduled_hook( 'samcp_backup_tick' );
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
delete_option( 'samcp_header_check' );
foreach ( (array) glob( get_home_path() . '.htaccess.samcp-bak-*' ) as $samcp_backup ) {
	wp_delete_file( $samcp_backup );
}
// Backup archives are the owner's data: they stay where they are. Only the pointer goes.
delete_option( 'samcp_backup_dir' );
