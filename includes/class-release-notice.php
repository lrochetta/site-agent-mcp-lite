<?php
/**
 * New version notice of the Lite edition, off unless an administrator switches it on.
 *
 * Site Agent MCP Lite has no updater. When the option is on, the plugin asks GitHub,
 * at most twice a day, for the latest release of the public Lite repository, and shows
 * a notice with a link to it when that version is newer. It never downloads or installs
 * anything: the administrator updates the plugin as they installed it.
 *
 * The request runs from WP-Cron, never while a screen is drawn, and its answer, a failure
 * included, is kept 12 hours: a slow or unreachable GitHub never slows the dashboard and
 * is asked twice a day at most.
 *
 * Sent: one HTTPS GET to api.github.com, with WordPress's usual User-Agent (WordPress
 * version and site address). Nothing else.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

final class Release_Notice {

	const OPTION    = 'samcp_release_notice';
	const TRANSIENT = 'samcp_latest_release';
	const DISMISSED = 'samcp_release_dismissed';
	const HOOK      = 'samcp_release_check';
	const API       = 'https://api.github.com/repos/lrochetta/site-agent-mcp-lite/releases/latest';
	const PAGE      = 'https://github.com/lrochetta/site-agent-mcp-lite/releases/latest';

	public static function boot() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_samcp_release_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
	}

	/** Whether an administrator switched the check on. */
	public static function enabled() {
		return (bool) get_site_option( self::OPTION, 0 );
	}

	/**
	 * Whether the current user may switch the check on or off. On a network the setting
	 * is network-wide, so only a network administrator may change it.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Switch the check on or off. Switching off forgets what was fetched.
	 *
	 * @param bool $on New state.
	 * @return bool Whether the setting was saved: false when the current user may not change it.
	 */
	public static function set_enabled( $on ) {
		if ( ! self::can_manage() ) {
			return false;
		}
		update_site_option( self::OPTION, $on ? 1 : 0 );
		if ( ! $on ) {
			delete_site_transient( self::TRANSIENT );
			wp_clear_scheduled_hook( self::HOOK );
		}
		return true;
	}

	/**
	 * The latest released version, from the cache only. Empty string when unknown. When
	 * nothing is cached, a check is scheduled with WP-Cron, so drawing a screen never waits
	 * for GitHub.
	 *
	 * @return string
	 */
	public static function latest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return (string) $cached['version'];
		}
		if ( self::enabled() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		return '';
	}

	/**
	 * Ask GitHub for the latest release and keep the answer, or the failure, 12 hours.
	 * Runs from WP-Cron.
	 *
	 * @return string The latest version, empty when unknown.
	 */
	public static function refresh() {
		if ( ! self::enabled() ) {
			return '';
		}
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return (string) $cached['version'];
		}
		$version  = '';
		$response = wp_safe_remote_get(
			self::API,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$tag  = is_array( $json ) && isset( $json['tag_name'] ) && is_string( $json['tag_name'] ) ? $json['tag_name'] : '';
			if ( preg_match( '/^v?(\d+\.\d+\.\d+)$/', $tag, $match ) ) {
				$version = $match[1];
			}
		}
		// A failed check is cached as long as a good one: twice a day at most, as the settings say.
		set_site_transient( self::TRANSIENT, array( 'version' => $version ), 12 * HOUR_IN_SECONDS );
		return $version;
	}

	/** Show the notice to people who can update plugins, on the dashboard, Plugins and Site Agent screens. */
	public static function notice() {
		if ( ! self::enabled() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'dashboard', 'dashboard-network', 'plugins', 'plugins-network' ), true ) && false === strpos( $id, Admin_Page::SLUG ) ) {
			return;
		}
		$latest = self::latest();
		if ( '' === $latest || ! version_compare( $latest, SAMCP_VERSION, '>' ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISSED, true ) === $latest ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=samcp_release_dismiss&version=' . rawurlencode( $latest ) ), 'samcp_release_dismiss' );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				/* translators: 1: new version number, 2: installed version number. */
				echo esc_html( sprintf( __( 'Site Agent MCP Lite %1$s is available. This site runs %2$s.', 'site-agent-mcp-lite' ), $latest, SAMCP_VERSION ) );
				?>
				<a href="<?php echo esc_url( self::PAGE ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See the new version', 'site-agent-mcp-lite' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'site-agent-mcp-lite' ); ?></span></a>
				· <a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Hide until the next version', 'site-agent-mcp-lite' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** Hide the notice for this version, for the current user. */
	public static function dismiss() {
		check_admin_referer( 'samcp_release_dismiss' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';
		if ( preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			update_user_meta( get_current_user_id(), self::DISMISSED, $version );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
