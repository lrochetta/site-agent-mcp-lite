<?php
/**
 * Plugin Name:       Site Agent MCP Lite
 * Plugin URI:        https://mcp.wordpress-custom.com/
 * Description:       Remote MCP server with OAuth 2.1. Lets AI agents work on this site within the access you choose for each role and account.
 * Version:           0.9.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Laurent Rochetta
 * Author URI:        https://rochetta.fr/
 * License:           GPL-2.0-or-later
 * Text Domain:       site-agent-mcp-lite
 * Domain Path:       /languages
 *
 * @package SiteAgentMCP
 */

defined( 'ABSPATH' ) || exit;

// Site Agent MCP Full and Site Agent MCP Lite share their code: when the other
// edition is already loaded, this one stays idle and asks for one to be deactivated.
if ( defined( 'SAMCP_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Site Agent MCP Full and Site Agent MCP Lite are both active. They cannot run together, so only one of them works now. Deactivate one of them in Plugins.', 'site-agent-mcp-lite' ) . '</p></div>';
		}
	);
	return;
}

define( 'SAMCP_VERSION', '0.9.0' );
define( 'SAMCP_FILE', __FILE__ );
define( 'SAMCP_DIR', plugin_dir_path( __FILE__ ) );
// SAMCP_EDITION: 'full' here, 'lite' in the wordpress.org build. See includes/edition.php.
require_once SAMCP_DIR . 'includes/edition.php';

require_once SAMCP_DIR . 'includes/class-i18n.php';
require_once SAMCP_DIR . 'includes/class-store.php';
require_once SAMCP_DIR . 'includes/class-policy.php';
require_once SAMCP_DIR . 'includes/class-oauth.php';
require_once SAMCP_DIR . 'includes/class-mcp-server.php';
require_once SAMCP_DIR . 'includes/class-header-check.php';
require_once SAMCP_DIR . 'includes/class-admin-bridge.php';
require_once SAMCP_DIR . 'includes/class-plugin-inspector.php';
require_once SAMCP_DIR . 'includes/class-journal.php';
require_once SAMCP_DIR . 'includes/class-tools.php';
require_once SAMCP_DIR . 'includes/class-seo-tools.php';
require_once SAMCP_DIR . 'includes/class-search-console.php';
require_once SAMCP_DIR . 'includes/class-site-kit.php';
require_once SAMCP_DIR . 'includes/class-security-audit.php';
require_once SAMCP_DIR . 'includes/class-backup.php';
require_once SAMCP_DIR . 'includes/class-admin-page.php';
require_once SAMCP_DIR . 'includes/class-release-notice.php';

register_activation_hook(
	__FILE__,
	static function () {
		SiteAgentMCP\Store::install();
		// Find out at once whether this server passes the Authorization header, before any app connects.
		SiteAgentMCP\Header_Check::run();
	}
);
register_deactivation_hook( __FILE__, array( 'SiteAgentMCP\Store', 'deactivate' ) );

SiteAgentMCP\I18n::boot();
SiteAgentMCP\Store::boot();
SiteAgentMCP\Policy::boot();
SiteAgentMCP\OAuth::boot();
SiteAgentMCP\MCP_Server::boot();
SiteAgentMCP\Header_Check::boot();
SiteAgentMCP\Seo_Tools::boot();
SiteAgentMCP\Search_Console::boot();
SiteAgentMCP\Backup::boot();
SiteAgentMCP\Admin_Page::boot();
// The Full edition has its own updater; Lite only offers an opt-in notice of new versions.
if ( 'lite' === SAMCP_EDITION ) {
	SiteAgentMCP\Release_Notice::boot();
}
