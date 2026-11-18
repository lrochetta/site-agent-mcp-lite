<?php
/**
 * Whether the Authorization header reaches PHP, and a reversible fix for Apache.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * On PHP behind FastCGI or CGI, Apache passes the Authorization header to PHP only
 * when a rewrite rule copies it, and WordPress's own rule never runs when another
 * rewrite block ends earlier in .htaccess. OAuth then works but every MCP call fails.
 * This class finds that out with a loopback probe, says so, and offers a fix.
 */
class Header_Check {

	const ROUTE     = '/header-probe';
	const OPTION    = 'samcp_header_check';
	const STALE     = 12 * HOUR_IN_SECONDS;
	const PROBE     = 'Bearer samcp-probe';
	const MARKER    = 'Site Agent MCP';

	/** Register hooks. */
	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/** The probe route: says whether a header arrived and how, never its value. */
	public static function register_route() {
		register_rest_route(
			MCP_Server::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'probe' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answer the probe.
	 *
	 * @return \WP_REST_Response
	 */
	public static function probe() {
		$found = MCP_Server::authorization_source();
		$response = new \WP_REST_Response(
			array(
				'received' => self::PROBE === $found['value'],
				'via'      => $found['via'],
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Run the probe now: ok, missing, or unknown when the site cannot call itself.
	 *
	 * @return string
	 */
	public static function run() {
		$response = wp_remote_get(
			apply_filters( 'samcp_loopback_url', rest_url( MCP_Server::NAMESPACE . self::ROUTE ) ),
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array(
					'Authorization' => self::PROBE,
					'Cache-Control' => 'no-cache',
				),
			)
		);
		$body   = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$status = 'unknown';
		if ( is_array( $body ) && isset( $body['received'] ) ) {
			$status = $body['received'] ? 'ok' : 'missing';
		}
		// An option, not a transient: the endpoint relies on it to explain a 401, long after any admin visit.
		Policy::own_write(
			static function () use ( $status ) {
				update_option(
					self::OPTION,
					array(
						'status' => $status,
						'time'   => time(),
					),
					false
				);
			}
		);
		return $status;
	}

	/**
	 * Last result, without a new probe: ok, missing, unknown, or untested.
	 *
	 * @return string
	 */
	public static function cached() {
		$cached = get_option( self::OPTION );
		return is_array( $cached ) && isset( $cached['status'] ) ? $cached['status'] : 'untested';
	}

	/**
	 * Whether the last result is older than 12 hours, or missing.
	 *
	 * @return bool
	 */
	public static function stale() {
		$cached = get_option( self::OPTION );
		return ! is_array( $cached ) || empty( $cached['time'] ) || (int) $cached['time'] < time() - self::STALE;
	}

	/** Forget the last result, so the next administrator screen probes again. */
	public static function forget() {
		Policy::own_write(
			static function () {
				delete_option( self::OPTION );
			}
		);
	}

	/**
	 * Last result, probing first when none is cached. For administrator screens only.
	 *
	 * @return string
	 */
	public static function status() {
		return self::stale() ? self::run() : self::cached();
	}

	/**
	 * Whether the web server is Nginx.
	 *
	 * @return bool
	 */
	public static function is_nginx() {
		return false !== strpos( self::software(), 'nginx' );
	}

	/**
	 * Whether the web server is IIS, which reads web.config, not .htaccess.
	 *
	 * @return bool
	 */
	public static function is_iis() {
		return false !== strpos( self::software(), 'microsoft-iis' );
	}

	/**
	 * Web server name, lowercase.
	 *
	 * @return string
	 */
	private static function software() {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	}

	/**
	 * Lines that fix the problem on this kind of server.
	 *
	 * @return string
	 */
	public static function fix_lines() {
		if ( self::is_nginx() ) {
			return 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;';
		}
		return self::block();
	}

	/**
	 * The .htaccess block.
	 *
	 * @return string
	 */
	private static function block() {
		return "# BEGIN " . self::MARKER . "\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n</IfModule>\n# END " . self::MARKER . "\n";
	}

	/**
	 * Path of the .htaccess that WordPress manages.
	 *
	 * @return string
	 */
	public static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * Whether the one-click fix can be offered: Apache, and a .htaccess this site can write.
	 *
	 * @return bool
	 */
	public static function can_fix() {
		if ( self::is_nginx() || self::is_iis() ) {
			return false;
		}
		// On a network, .htaccess serves every site: only a super admin may change it.
		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}
		$path = self::htaccess_path();
		return is_file( $path ) ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) );
	}

	/**
	 * Remove this plugin's block from .htaccess text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_block( $text ) {
		return (string) preg_replace( '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '\R?/ms', '', (string) $text );
	}

	/**
	 * Put the block at the very top of .htaccess, above every other rewrite block,
	 * check with the probe, and put the file back as it was if the probe still fails.
	 * Called only from an administrator's click (nonce and manage_options checked by the caller).
	 *
	 * @return string fixed, failed or not_writable.
	 */
	public static function fix() {
		if ( ! self::can_fix() ) {
			return 'not_writable';
		}
		$path     = self::htaccess_path();
		$existed  = is_file( $path );
		$original = $existed ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $original ) {
			return 'not_writable';
		}
		if ( $existed ) {
			// One copy only, replaced each time. Apache refuses to serve .ht* files by default.
			self::remove_backups();
			if ( ! copy( $path, $path . '.samcp-bak-' . gmdate( 'YmdHis' ) ) ) {
				return 'not_writable';
			}
		}
		if ( ! self::write( $path, self::block() . self::strip_block( (string) $original ) ) ) {
			return 'not_writable';
		}
		if ( 'ok' === self::run() ) {
			return 'fixed';
		}
		if ( $existed ) {
			self::write( $path, (string) $original );
		} else {
			wp_delete_file( $path );
		}
		self::run();
		return 'failed';
	}

	/**
	 * Replace a file in one step, keeping its permissions: a half-written .htaccess takes the site down.
	 *
	 * @param string $path File.
	 * @param string $text Content.
	 * @return bool
	 */
	private static function write( $path, $text ) {
		$temp = $path . '.samcp-tmp-' . wp_generate_password( 8, false );
		if ( false === file_put_contents( $temp, $text, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( is_file( $path ) ) {
			chmod( $temp, fileperms( $path ) & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $temp );
			return false;
		}
		return true;
	}

	/**
	 * Whether this plugin's block is in .htaccess.
	 *
	 * @return bool
	 */
	public static function has_block() {
		$path = self::htaccess_path();
		return is_file( $path ) && false !== strpos( (string) file_get_contents( $path ), '# BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Delete the copies of .htaccess made before a fix. */
	public static function remove_backups() {
		foreach ( (array) glob( self::htaccess_path() . '.samcp-bak-*' ) as $backup ) {
			wp_delete_file( $backup );
		}
	}

	/** Remove the block, on uninstall. */
	public static function remove() {
		$path = self::htaccess_path();
		if ( is_file( $path ) && wp_is_writable( $path ) ) {
			$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$new  = self::strip_block( $text );
			if ( $new !== $text ) {
				self::write( $path, $new );
			}
		}
		self::remove_backups();
		self::forget();
	}
}
