<?php
/**
 * Settings, connections and setup instructions.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * The Site Agent screen. Administrators get a top-level menu with tabs; other
 * users get "AI connections" under Profile, for their own connections.
 */
class Admin_Page {

	const SLUG = 'site-agent-mcp';

	/** Transient holding the last connection checks. */
	const CHECKS = 'samcp_checks';

	/** User meta set when an administrator dismissed the Lite edition's block about Full. */
	const FULL_INFO_DISMISSED = 'samcp_full_info_dismissed';

	/** Rows per page of the activity table. */
	const ACTIVITY_PER_PAGE = 25;

	/**
	 * Hook suffix of the screen, to load assets there only.
	 *
	 * @var string
	 */
	private static $hook = '';

	/** Register hooks. */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'redirect_old_url' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_filter( 'admin_title', array( __CLASS__, 'admin_title' ), 10, 2 );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_args' ) );
		add_action( 'admin_post_samcp_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_samcp_access', array( __CLASS__, 'save_access' ) );
		add_action( 'admin_post_samcp_pairing', array( __CLASS__, 'pairing' ) );
		add_action( 'admin_post_samcp_switch', array( __CLASS__, 'switch_on' ) );
		add_action( 'admin_post_samcp_fix_header', array( __CLASS__, 'fix_header' ) );
		add_action( 'admin_post_samcp_unfix_header', array( __CLASS__, 'unfix_header' ) );
		add_action( 'admin_post_samcp_services', array( __CLASS__, 'save_services' ) );
		add_action( 'admin_post_samcp_test_services', array( __CLASS__, 'test_services' ) );
		add_action( 'admin_post_samcp_revoke', array( __CLASS__, 'revoke' ) );
		add_action( 'admin_post_samcp_client_create', array( __CLASS__, 'client_create' ) );
		add_action( 'admin_post_samcp_client_delete', array( __CLASS__, 'client_delete' ) );
		add_action( 'admin_post_samcp_checks', array( __CLASS__, 'run_checks' ) );
		if ( 'lite' === SAMCP_EDITION ) {
			add_action( 'admin_post_samcp_dismiss_full', array( __CLASS__, 'dismiss_full_info' ) );
		}
	}

	/* ---------------------------------------------------------------- navigation */

	/**
	 * Tabs of the administrator screen.
	 *
	 * @return array<string,string>
	 */
	private static function tabs() {
		$tabs = array(
			'overview' => __( 'Overview', 'site-agent-mcp-lite' ),
			'connect'  => __( 'Connect', 'site-agent-mcp-lite' ),
			'services' => __( 'Services', 'site-agent-mcp-lite' ),
			'backups'  => __( 'Backups', 'site-agent-mcp-lite' ),
			'activity' => __( 'Activity', 'site-agent-mcp-lite' ),
			'access'   => __( 'Access', 'site-agent-mcp-lite' ),
			'settings' => __( 'Settings', 'site-agent-mcp-lite' ),
		);
		// On a network, backups hold every site: only super admins see them.
		if ( ! Backup::can_manage() ) {
			unset( $tabs['backups'] );
		}
		return $tabs;
	}

	/**
	 * Tab asked for in the URL.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'overview';
	}

	/**
	 * Menu slug of a tab's submenu entry: a plain link into the screen.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_slug( $tab ) {
		return 'admin.php?page=' . self::SLUG . '&tab=' . $tab;
	}

	/**
	 * Address of the screen for the current user. Works in admin-post requests too,
	 * where no menu is registered.
	 *
	 * @param string $tab  Tab, for administrators.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		if ( current_user_can( 'manage_options' ) ) {
			$base = admin_url( 'admin.php?page=' . self::SLUG );
			if ( '' !== $tab && 'overview' !== $tab ) {
				$args = array( 'tab' => $tab ) + $args;
			}
		} else {
			// Profile sits under Users for people who can list users.
			$base = admin_url( ( current_user_can( 'list_users' ) ? 'users.php' : 'profile.php' ) . '?page=' . self::SLUG );
		}
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * The screen used to live in Tools. Old links and bookmarks land on the new one.
	 * Runs on admin_menu: WordPress refuses an unknown Tools page before admin_init.
	 */
	public static function redirect_old_url() {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( 'tools.php' !== $pagenow || ! isset( $_GET['page'] ) || self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$args = array();
		if ( isset( $_GET['samcp_notice'] ) ) {
			$args['samcp_notice'] = sanitize_key( wp_unslash( $_GET['samcp_notice'] ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			$tab = 'overview';
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/** Add the screens. */
	public static function menu() {
		if ( current_user_can( 'manage_options' ) ) {
			self::$hook = (string) add_menu_page(
				__( 'Site Agent MCP', 'site-agent-mcp-lite' ),
				__( 'Site Agent', 'site-agent-mcp-lite' ),
				'manage_options',
				self::SLUG,
				array( __CLASS__, 'render' ),
				'dashicons-admin-links',
				81
			);
			foreach ( self::tabs() as $tab => $label ) {
				if ( 'overview' === $tab ) {
					add_submenu_page( self::SLUG, __( 'Site Agent MCP', 'site-agent-mcp-lite' ), $label, 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
				} else {
					add_submenu_page( self::SLUG, $label, $label, 'manage_options', self::tab_slug( $tab ) );
				}
			}
			return;
		}
		// Only accounts the Access settings let connect see the screen.
		if ( OAuth::user_can_connect( get_current_user_id() ) ) {
			self::$hook = (string) add_submenu_page( 'profile.php', __( 'AI connections', 'site-agent-mcp-lite' ), __( 'AI connections', 'site-agent-mcp-lite' ), 'read', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Highlight the submenu entry of the open tab.
	 *
	 * @param string|null $file Submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $file ) {
		global $plugin_page;
		if ( self::SLUG !== $plugin_page || ! current_user_can( 'manage_options' ) ) {
			return $file;
		}
		$tab = self::current_tab();
		return 'overview' === $tab ? self::SLUG : self::tab_slug( $tab );
	}

	/**
	 * Name the open tab in the browser title.
	 *
	 * @param string $admin_title Full title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public static function admin_title( $admin_title, $title ) {
		global $plugin_page;
		unset( $title );
		if ( self::SLUG !== $plugin_page || ! current_user_can( 'manage_options' ) ) {
			return $admin_title;
		}
		$tabs = self::tabs();
		return $tabs[ self::current_tab() ] . ' &lsaquo; ' . $admin_title;
	}

	/**
	 * Drop the one-time arguments from the address bar once shown.
	 *
	 * @param string[] $args Arguments.
	 * @return string[]
	 */
	public static function removable_args( $args ) {
		$args[] = 'samcp_notice';
		$args[] = 'samcp_restore';
		return $args;
	}

	/**
	 * Load the stylesheet and script on this screen only.
	 *
	 * @param string $hook Hook suffix of the screen.
	 */
	public static function assets( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'samcp-admin', plugins_url( 'assets/admin.css', SAMCP_FILE ), array(), SAMCP_VERSION );
		wp_enqueue_script( 'samcp-admin', plugins_url( 'assets/admin.js', SAMCP_FILE ), array(), SAMCP_VERSION, true );
		$data = array(
			'strings' => array(
				'copy'        => __( 'Copy', 'site-agent-mcp-lite' ),
				'copied'      => __( 'Copied', 'site-agent-mcp-lite' ),
				'copyDone'    => __( 'Copied to the clipboard.', 'site-agent-mcp-lite' ),
				'copyFailed'  => __( 'Copy failed. The text is selected: press Ctrl+C, or Cmd+C on a Mac.', 'site-agent-mcp-lite' ),
				'confirm'     => __( 'Confirm', 'site-agent-mcp-lite' ),
				'inProgress'  => __( 'Backup in progress', 'site-agent-mcp-lite' ),
				'lostContact' => __( 'Lost contact with the site after 3 attempts. The backup goes on in the background.', 'site-agent-mcp-lite' ),
				'retrying'    => __( 'Trying again.', 'site-agent-mcp-lite' ),
				/* translators: Backup progress. 1: phase, such as "Archiving files", 2: count, such as "120 of 3,400 files". */
				'withDetail'  => __( '%1$s (%2$s)', 'site-agent-mcp-lite' ),
				'phases'      => array(
					'listing'  => __( 'Listing files', 'site-agent-mcp-lite' ),
					'database' => __( 'Saving the database', 'site-agent-mcp-lite' ),
					'files'    => __( 'Archiving files', 'site-agent-mcp-lite' ),
					'manifest' => __( 'Finishing the backup', 'site-agent-mcp-lite' ),
				),
			),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$data['ajaxUrl']     = admin_url( 'admin-ajax.php' );
			$data['backupNonce'] = wp_create_nonce( 'samcp_backup_step' );
			$data['backupsUrl']  = self::url( 'backups' );
		}
		wp_localize_script( 'samcp-admin', 'samcpAdmin', $data );
	}

	/* ---------------------------------------------------------------- actions */

	/**
	 * Return to the screen, on the tab the action belongs to.
	 *
	 * @param string $notice Notice key.
	 * @param string $tab    Tab.
	 */
	private static function back( $notice, $tab ) {
		wp_safe_redirect( self::url( $tab, array( 'samcp_notice' => $notice ) ) );
		exit;
	}

	/** Save site settings. */
	public static function save_settings() {
		check_admin_referer( 'samcp_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$lines = isset( $_POST['extra_redirects'] ) ? explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['extra_redirects'] ) ) ) : array();
		$valid = array();
		foreach ( $lines as $line ) {
			$line   = trim( $line );
			$scheme = strtolower( (string) wp_parse_url( $line, PHP_URL_SCHEME ) );
			// HTTPS and app schemes only: script-like schemes would run in the browser on redirect.
			if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'javascript', 'data', 'vbscript', 'file', 'about', 'blob' ), true ) ) {
				$valid[] = $line;
			}
		}
		update_option( 'samcp_extra_redirects', implode( "\n", array_unique( $valid ) ), false );
		self::back( 'saved', 'settings' );
	}

	/** Save who may connect agents and how far connections go. */
	public static function save_access() {
		check_admin_referer( 'samcp_access' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		update_option( 'samcp_enabled', isset( $_POST['enabled'] ) ? 1 : 0, false );
		update_option( 'samcp_allow_extensions', isset( $_POST['allow_extensions'] ) ? 1 : 0, false );
		update_option( 'samcp_notify', isset( $_POST['notify'] ) ? 1 : 0, false );

		$roles = array();
		$posted_roles = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each key and value is checked below.
		foreach ( $posted_roles as $role => $level ) {
			$role = sanitize_key( (string) $role );
			if ( isset( wp_roles()->roles[ $role ] ) && Policy::is_level( $level ) ) {
				$roles[ $role ] = $level;
			}
		}
		$users = array();
		$posted_users = isset( $_POST['users'] ) && is_array( $_POST['users'] ) ? wp_unslash( $_POST['users'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each key and value is checked below.
		foreach ( $posted_users as $user_id => $level ) {
			if ( Policy::is_level( $level ) ) {
				$users[ (int) $user_id ] = $level;
			}
		}
		$notice = 'access_saved';
		$who    = isset( $_POST['new_user'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['new_user'] ) ) ) : '';
		$level  = isset( $_POST['new_level'] ) ? sanitize_key( wp_unslash( $_POST['new_level'] ) ) : '';
		if ( '' !== $who ) {
			$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
			if ( $user && Policy::is_level( $level ) && ( ! is_multisite() || is_user_member_of_blog( $user->ID ) ) ) {
				$users[ $user->ID ] = $level;
			} else {
				$notice = 'user_unknown';
			}
		}
		Policy::save_settings( $roles, $users );
		delete_option( 'samcp_upgrade_kept' );
		self::back( $notice, 'access' );
	}

	/** Open or close new connections for a while. Any account that may connect can do it. */
	public static function pairing() {
		check_admin_referer( 'samcp_pairing' );
		if ( ! OAuth::user_can_connect( get_current_user_id() ) || ! Policy::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		if ( isset( $_POST['pairing'] ) && 'close' === $_POST['pairing'] ) {
			Policy::close_pairing( get_current_user_id(), true );
			self::back( 'pairing_closed', 'connect' );
		}
		Policy::open_pairing( get_current_user_id() );
		self::back( 'pairing_open', 'connect' );
	}

	/** Switch agents on from the Overview banner. */
	public static function switch_on() {
		check_admin_referer( 'samcp_switch' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		update_option( 'samcp_enabled', 1, false );
		self::back( 'switched_on', 'access' );
	}

	/** Put the Authorization pass-through rule at the top of .htaccess, checked and reverted on failure. */
	public static function fix_header() {
		check_admin_referer( 'samcp_fix_header' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$result = Header_Check::fix();
		self::back( 'fixed' === $result ? 'header_fixed' : ( 'failed' === $result ? 'header_fix_failed' : 'header_not_writable' ), 'overview' );
	}

	/** Take the Authorization block and its backup copy out of .htaccess. */
	public static function unfix_header() {
		check_admin_referer( 'samcp_unfix_header' );
		if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		Header_Check::remove();
		self::back( 'header_removed', 'settings' );
	}

	/** Lite edition: hide the block about Site Agent MCP Full for this administrator. */
	public static function dismiss_full_info() {
		check_admin_referer( 'samcp_dismiss_full' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		update_user_meta( get_current_user_id(), self::FULL_INFO_DISMISSED, 1 );
		wp_safe_redirect( self::url( 'settings' ) );
		exit;
	}

	/** Save service credentials. A blank field keeps the stored value, since the page never shows it. A dash removes it. */
	public static function save_services() {
		check_admin_referer( 'samcp_services' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$old_client = (string) get_option( Search_Console::ID_OPTION, '' );
		$user       = wp_get_current_user();
		$autofilled = false;
		foreach ( array(
			'google_api_key'    => Seo_Tools::GOOGLE_OPTION,
			'gtmetrix_api_key'  => Seo_Tools::GTMETRIX_OPTION,
			'gsc_client_id'     => Search_Console::ID_OPTION,
			'gsc_client_secret' => Search_Console::SECRET_OPTION,
		) as $field => $option ) {
			$raw   = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$value = sanitize_text_field( $raw );
			// Browsers fill these fields with the WordPress login and password. Never store those.
			if ( '' !== $raw && '-' !== $raw && ( $raw === $user->user_login || wp_check_password( $raw, $user->user_pass, $user->ID ) ) ) {
				$autofilled = true;
				continue;
			}
			if ( '-' === $value ) {
				delete_option( $option );
			} elseif ( '' !== $value ) {
				update_option( $option, $value, false );
			}
		}
		// A token belongs to the OAuth client that issued it.
		if ( (string) get_option( Search_Console::ID_OPTION, '' ) !== $old_client ) {
			Search_Console::forget_tokens();
		}
		self::back( $autofilled ? 'autofilled' : 'saved', 'services' );
	}

	/** Try each configured service and keep the results for the next page load. */
	public static function test_services() {
		check_admin_referer( 'samcp_test_services' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$results = array();
		$attempt = static function ( $label, $callback ) use ( &$results ) {
			try {
				$results[] = array(
					'ok'     => true,
					'label'  => $label,
					'detail' => (string) $callback(),
				);
			} catch ( \Throwable $e ) {
				$results[] = array(
					'ok'     => false,
					'label'  => $label,
					'detail' => $e->getMessage(),
				);
			}
		};
		if ( '' !== Seo_Tools::api_key( Seo_Tools::GOOGLE_OPTION, 'SAMCP_GOOGLE_API_KEY' ) ) {
			$attempt(
				'PageSpeed Insights',
				static function () {
					$result = Seo_Tools::performance_test( array( 'url' => home_url( '/' ), 'categories' => array( 'seo' ) ) );
					/* translators: %s: Lighthouse SEO score, 0 to 100. */
					return sprintf( __( 'SEO score %s', 'site-agent-mcp-lite' ), isset( $result['scores']['seo'] ) ? number_format_i18n( $result['scores']['seo'] ) : '?' );
				}
			);
			$attempt(
				'Chrome UX Report',
				static function () {
					$result = Seo_Tools::crux_query( array( 'url' => untrailingslashit( home_url() ) ) );
					return $result['found'] ? __( 'Real-user data available', 'site-agent-mcp-lite' ) : __( 'The key works. Chrome has too few real visits to this site yet.', 'site-agent-mcp-lite' );
				}
			);
		}
		if ( '' !== Seo_Tools::api_key( Seo_Tools::GTMETRIX_OPTION, 'SAMCP_GTMETRIX_API_KEY' ) ) {
			$attempt( 'GTmetrix', array( 'SiteAgentMCP\\Seo_Tools', 'gtmetrix_status' ) );
		}
		if ( Search_Console::is_connected() ) {
			$attempt(
				'Search Console',
				static function () {
					$sites = Search_Console::gsc_sites();
					$count = count( $sites['sites'] );
					/* translators: %s: number of Search Console properties. */
					return sprintf( _n( '%s property readable', '%s properties readable', $count, 'site-agent-mcp-lite' ), number_format_i18n( $count ) );
				}
			);
		}
		set_transient( 'samcp_service_tests_' . get_current_user_id(), $results, 5 * MINUTE_IN_SECONDS );
		self::back( 'tested', 'services' );
	}

	/** Revoke a connection. Users can revoke their own, administrators any. */
	public static function revoke() {
		check_admin_referer( 'samcp_revoke' );
		$family = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : '';
		if ( '' === $family || ( Store::family_owner( $family ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		Store::revoke_family( $family );
		self::back( 'revoked', 'connect' );
	}

	/** Create a client for applications that need pre-registered credentials. */
	public static function client_create() {
		check_admin_referer( 'samcp_client_create' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$name = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
		$uris = isset( $_POST['redirect_uris'] ) ? array_values( array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['redirect_uris'] ) ) ) ) ) ) : array();
		$uris = array_values(
			array_filter(
				$uris,
				static function ( $uri ) {
					return in_array( strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) ), array( 'https', 'http' ), true );
				}
			)
		);
		if ( '' === $name || ! $uris ) {
			self::back( 'client_invalid', 'connect' );
		}
		$client = Store::create_client( $name, $uris, 'manual', true );
		// Shown once on the next page load, then gone.
		set_transient( 'samcp_new_client_' . get_current_user_id(), $client, 5 * MINUTE_IN_SECONDS );
		self::back( 'client_created', 'connect' );
	}

	/** Delete a client. */
	public static function client_delete() {
		check_admin_referer( 'samcp_client_delete' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		Store::delete_client( isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '' );
		self::back( 'client_deleted', 'connect' );
	}

	/** Run the connection checks now and keep the result for 12 hours. */
	public static function run_checks() {
		check_admin_referer( 'samcp_checks' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		set_transient(
			self::CHECKS,
			array(
				'time'    => time(),
				'results' => self::diagnostics(),
			),
			12 * HOUR_IN_SECONDS
		);
		self::back( 'checked', 'overview' );
	}

	/* ---------------------------------------------------------------- checks */

	/**
	 * Checks that catch the usual reasons a connection fails. Two of them call the
	 * site over the network, so they run on demand, never while a page renders.
	 *
	 * @return array<string,bool> Result by check id.
	 */
	private static function diagnostics() {
		$args     = array(
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);
		$metadata = wp_remote_get( OAuth::issuer() . '/.well-known/oauth-authorization-server', $args );
		$document = is_wp_error( $metadata ) ? null : json_decode( wp_remote_retrieve_body( $metadata ), true );
		$endpoint = wp_remote_post( OAuth::resource(), $args );
		$challenge = is_wp_error( $endpoint ) ? '' : (string) wp_remote_retrieve_header( $endpoint, 'www-authenticate' );

		$results = array(
			'https'      => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'permalinks' => '' !== (string) get_option( 'permalink_structure' ),
			'discovery'  => is_array( $document ) && isset( $document['issuer'] ) && OAuth::issuer() === $document['issuer'],
			'endpoint'   => ! is_wp_error( $endpoint ) && 401 === (int) wp_remote_retrieve_response_code( $endpoint ) && false !== strpos( $challenge, 'resource_metadata' ),
		);
		// When the site cannot call itself, the header cannot be checked: no verdict rather than a wrong one.
		$header = Header_Check::run();
		if ( 'unknown' !== $header ) {
			$results['header'] = 'ok' === $header;
		}
		return $results;
	}

	/**
	 * Wording of a check, true to its result.
	 *
	 * @param string $id Check id.
	 * @param bool   $ok Passed.
	 * @return array{0:string,1:string} Statement and fix.
	 */
	private static function check_text( $id, $ok ) {
		$texts = array(
			'https'      => array(
				__( 'The site uses HTTPS.', 'site-agent-mcp-lite' ),
				__( 'The site does not use HTTPS.', 'site-agent-mcp-lite' ),
				__( 'AI apps refuse to connect without it. Turn on HTTPS at your host, then update the site address in Settings > General.', 'site-agent-mcp-lite' ),
			),
			'permalinks' => array(
				__( 'Pretty permalinks are on.', 'site-agent-mcp-lite' ),
				__( 'Permalinks are set to Plain.', 'site-agent-mcp-lite' ),
				__( 'Choose anything but "Plain" in Settings > Permalinks.', 'site-agent-mcp-lite' ),
			),
			'discovery'  => array(
				__( 'The discovery address answers.', 'site-agent-mcp-lite' ),
				__( 'The discovery address does not answer.', 'site-agent-mcp-lite' ),
				__( 'The web server is blocking /.well-known. Let it pass to WordPress. A security plugin or an Nginx rule on dot paths is the usual cause.', 'site-agent-mcp-lite' ),
			),
			'header'     => array(
				__( 'The Authorization header reaches WordPress.', 'site-agent-mcp-lite' ),
				__( 'The Authorization header does not reach WordPress.', 'site-agent-mcp-lite' ),
				__( 'AI apps can be approved but every call fails. The fix is shown at the top of this screen.', 'site-agent-mcp-lite' ),
			),
			'endpoint'   => array(
				__( 'The MCP endpoint asks for sign-in.', 'site-agent-mcp-lite' ),
				__( 'The MCP endpoint does not ask for sign-in.', 'site-agent-mcp-lite' ),
				__( 'A firewall or a plugin is blocking the REST API.', 'site-agent-mcp-lite' ),
			),
		);
		if ( ! isset( $texts[ $id ] ) ) {
			return array( $id, '' );
		}
		return $ok ? array( $texts[ $id ][0], '' ) : array( $texts[ $id ][1], $texts[ $id ][2] );
	}

	/* ---------------------------------------------------------------- shared parts */

	/**
	 * Language and direction of the screen's text, where they differ from the admin's.
	 */
	private static function lang_attribute() {
		echo I18n::container_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed attribute strings.
	}

	/**
	 * Status badge. The text carries the meaning; the icon only repeats it.
	 *
	 * @param string $state success, warning, error, info or neutral.
	 * @param string $text  Text.
	 */
	public static function badge( $state, $text ) {
		$icons = array(
			'success' => 'yes-alt',
			'warning' => 'warning',
			'error'   => 'dismiss',
			'info'    => 'update',
		);
		echo '<span class="samcp-badge is-' . esc_attr( $state ) . '">';
		if ( isset( $icons[ $state ] ) ) {
			echo '<span class="dashicons dashicons-' . esc_attr( $icons[ $state ] ) . '" aria-hidden="true"></span>';
		}
		echo esc_html( $text ) . '</span>';
	}

	/**
	 * Copy button for the text of another element. Hidden until the script runs.
	 *
	 * @param string $target Id of the element holding the text.
	 * @param string $label  Accessible name, starting with "Copy".
	 */
	public static function copy_button( $target, $label ) {
		?>
		<button type="button" class="button samcp-copy" data-samcp-copy="<?php echo esc_attr( $target ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" hidden><span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <span class="samcp-copy-text"><?php esc_html_e( 'Copy', 'site-agent-mcp-lite' ); ?></span></button>
		<?php
	}

	/**
	 * Read-only field with a copy button.
	 *
	 * @param string $id         Field id.
	 * @param string $label      Label.
	 * @param string $value      Value.
	 * @param string $copy_label Accessible name of the copy button.
	 */
	private static function copy_field( $id, $label, $value, $copy_label ) {
		?>
		<div class="samcp-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="samcp-copy-field">
				<input id="<?php echo esc_attr( $id ); ?>" class="large-text code" type="text" readonly value="<?php echo esc_attr( $value ); ?>">
				<?php self::copy_button( $id, $copy_label ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Tick box that confirms a destructive action when the dialog cannot open.
	 *
	 * @param string $id    Unique id.
	 * @param string $label What the person confirms.
	 */
	public static function confirm_fallback( $id, $label ) {
		?>
		<label class="samcp-confirm-fallback" for="<?php echo esc_attr( $id ); ?>"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" required> <?php echo esc_html( $label ); ?></label>
		<?php
	}

	/**
	 * Attributes that make a form ask for confirmation first.
	 *
	 * @param string $title  Question.
	 * @param string $body   Consequence.
	 * @param string $button Label of the confirming button.
	 * @return string
	 */
	public static function confirm_attributes( $title, $body, $button ) {
		return ' data-samcp-confirm="' . esc_attr( $title ) . '" data-samcp-confirm-body="' . esc_attr( $body ) . '" data-samcp-confirm-button="' . esc_attr( $button ) . '"';
	}

	/**
	 * Link to an outside page, opening in a new tab, and saying so.
	 *
	 * @param string $url  URL.
	 * @param string $text Link text.
	 * @return string
	 */
	private static function external_link( $url, $text ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'site-agent-mcp-lite' ) . '</span>'
			. '<span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
	}

	/**
	 * One step: text with an optional link.
	 *
	 * @param string $text Step text.
	 * @param string $url  Link URL.
	 * @param string $link Link text.
	 */
	private static function step( $text, $url = '', $link = '' ) {
		echo '<li>' . esc_html( $text ) . ( '' !== $url ? ' ' . self::external_link( $url, $link ) : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Role names of a user.
	 *
	 * @param \WP_User|false $user User.
	 * @return string
	 */
	private static function role_names( $user ) {
		if ( ! $user ) {
			return '';
		}
		$names = array();
		$roles = wp_roles()->roles;
		foreach ( (array) $user->roles as $role ) {
			$names[] = isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ]['name'] ) : $role;
		}
		return implode( ', ', $names );
	}

	/**
	 * Messages shown after an action.
	 *
	 * @return array<string,string>
	 */
	private static function messages() {
		return array(
			'saved'             => __( 'Changes saved.', 'site-agent-mcp-lite' ),
			'access_saved'      => __( 'Access saved. It applies to existing connections at once.', 'site-agent-mcp-lite' ),
			'user_unknown'      => __( 'No account of this site matches that login or email, so no exception was added. The other changes were saved.', 'site-agent-mcp-lite' ),
			'pairing_open'      => __( 'New connections are open for 15 minutes. Start the connection from your AI app now.', 'site-agent-mcp-lite' ),
			'pairing_closed'    => __( 'New connections are closed.', 'site-agent-mcp-lite' ),
			'switched_on'       => __( 'AI agents are switched on. Check who may connect below.', 'site-agent-mcp-lite' ),
			'header_fixed'      => __( 'The server configuration is fixed: the Authorization header now reaches WordPress. A copy of the previous .htaccess was kept next to it.', 'site-agent-mcp-lite' ),
			'header_fix_failed' => __( 'The change did not help, so .htaccess was put back as it was. Ask your host to pass the Authorization header to PHP, with the lines below.', 'site-agent-mcp-lite' ),
			'header_not_writable' => __( 'WordPress cannot write .htaccess on this server. Add the lines below yourself, or ask your host.', 'site-agent-mcp-lite' ),
			'header_removed'    => __( 'The Authorization block and its backup copy were removed from .htaccess.', 'site-agent-mcp-lite' ),
			'autofilled'        => __( 'Your browser filled a field with your WordPress login or password. That value was not saved. Paste the key from the service instead.', 'site-agent-mcp-lite' ),
			'revoked'           => __( 'Disconnected. The app no longer has access to this site.', 'site-agent-mcp-lite' ),
			'client_invalid'    => __( 'The app was not registered: a name and at least one return address are required.', 'site-agent-mcp-lite' ),
			'client_created'    => __( 'App registered.', 'site-agent-mcp-lite' ),
			'client_deleted'    => __( 'App deleted. Connections made with it no longer work.', 'site-agent-mcp-lite' ),
			'tested'            => __( 'Connection tests finished. Each service shows its result below.', 'site-agent-mcp-lite' ),
			'checked'           => __( 'Checks finished. The results are below.', 'site-agent-mcp-lite' ),
			'gsc_connected'     => __( 'Search Console connected.', 'site-agent-mcp-lite' ),
			'gsc_disconnected'  => __( 'Search Console disconnected.', 'site-agent-mcp-lite' ),
			'gsc_missing'       => __( 'Enter and save the client ID and secret first.', 'site-agent-mcp-lite' ),
			'gsc_refused'       => __( 'Google did not grant access, so Search Console is not connected.', 'site-agent-mcp-lite' ),
			'gsc_failed'        => __( 'Google did not return a lasting access. Check the client ID, secret and return address, then connect again.', 'site-agent-mcp-lite' ),
			'backup_started'    => __( 'Backup started. It goes on if you leave this page, and goes faster while the page is open.', 'site-agent-mcp-lite' ),
			'backup_complete'   => __( 'Backup complete. It is listed below.', 'site-agent-mcp-lite' ),
			'backup_job_failed' => __( 'The backup failed. The reason is shown in the list below. Try again.', 'site-agent-mcp-lite' ),
			'backup_verified'   => __( 'The backup reads back correctly.', 'site-agent-mcp-lite' ),
			'backup_deleted'    => __( 'Backup deleted.', 'site-agent-mcp-lite' ),
			'backup_failed'     => __( 'The backup action did not complete.', 'site-agent-mcp-lite' ),
		);
	}

	/**
	 * Notice for the action that led here, at the top of the screen. It takes the
	 * focus, so keyboard and screen reader users meet it first.
	 *
	 * @param bool $focus Give it the focus.
	 */
	private static function render_notice( $focus ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice   = isset( $_GET['samcp_notice'] ) ? sanitize_key( wp_unslash( $_GET['samcp_notice'] ) ) : '';
		$messages = self::messages();
		$text     = isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
		$detail   = get_transient( 'samcp_backup_detail_' . get_current_user_id() );
		if ( false !== $detail ) {
			delete_transient( 'samcp_backup_detail_' . get_current_user_id() );
			if ( '' !== $text ) {
				$text .= ' ' . $detail;
			}
		}
		if ( '' === $text ) {
			return;
		}
		$type = 'success';
		if ( in_array( $notice, array( 'client_invalid', 'gsc_missing', 'gsc_refused', 'gsc_failed', 'backup_failed', 'backup_job_failed' ), true ) ) {
			$type = 'error';
		} elseif ( 'autofilled' === $notice ) {
			$type = 'warning';
		}
		?>
		<div id="samcp-notice" class="notice notice-<?php echo esc_attr( $type ); ?> inline samcp-notice<?php echo 'success' === $type ? ' is-dismissible' : ''; ?>" role="<?php echo 'success' === $type ? 'status' : 'alert'; ?>" tabindex="-1"<?php echo $focus ? ' data-samcp-focus' : ''; ?>>
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * Credentials of an app just registered, shown once.
	 *
	 * @param array $client Client with its plain secret.
	 */
	private static function render_new_client( array $client ) {
		?>
		<div class="notice notice-warning inline samcp-notice" role="alert" tabindex="-1" data-samcp-focus aria-labelledby="samcp-new-client-title">
			<p id="samcp-new-client-title"><strong><?php esc_html_e( 'Copy these credentials now. The secret will not be shown again.', 'site-agent-mcp-lite' ); ?></strong></p>
			<?php
			self::copy_field( 'samcp-new-client-id', __( 'Client ID', 'site-agent-mcp-lite' ), $client['client_id'], __( 'Copy the client ID', 'site-agent-mcp-lite' ) );
			self::copy_field( 'samcp-new-client-secret', __( 'Client secret', 'site-agent-mcp-lite' ), $client['client_secret'], __( 'Copy the client secret', 'site-agent-mcp-lite' ) );
			?>
		</div>
		<?php
	}

	/** Confirmation dialog shared by every destructive action. */
	private static function render_dialog() {
		?>
		<dialog id="samcp-confirm" class="samcp-dialog" role="alertdialog" aria-labelledby="samcp-confirm-title" aria-describedby="samcp-confirm-body">
			<h2 id="samcp-confirm-title" data-samcp-dialog-title></h2>
			<p id="samcp-confirm-body" data-samcp-dialog-body></p>
			<div class="samcp-dialog-actions">
				<button type="button" class="button" data-samcp-cancel><?php esc_html_e( 'Cancel', 'site-agent-mcp-lite' ); ?></button>
				<button type="button" class="button samcp-button-danger-solid" data-samcp-ok><?php esc_html_e( 'Confirm', 'site-agent-mcp-lite' ); ?></button>
			</div>
		</dialog>
		<p id="samcp-live" class="screen-reader-text" aria-live="polite"></p>
		<?php
	}

	/* ---------------------------------------------------------------- screens */

	/** Render the screen. */
	public static function render() {
		$new = get_transient( 'samcp_new_client_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'samcp_new_client_' . get_current_user_id() );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::render_profile();
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap samcp"<?php self::lang_attribute(); ?>>
			<h1><?php esc_html_e( 'Site Agent MCP', 'site-agent-mcp-lite' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::render_notice( ! $new ); ?>
			<?php
			if ( is_array( $new ) ) {
				self::render_new_client( $new );
			}
			if ( ! Policy::enabled() ) {
				self::render_switched_off();
			}
			if ( 'missing' === Header_Check::status() ) {
				self::render_header_problem();
			}
			$kept = (int) get_option( 'samcp_upgrade_kept', 0 );
			if ( $kept ) {
				echo '<div class="notice notice-info inline"><p>';
				/* translators: %d: number of accounts. */
				echo esc_html( sprintf( _n( 'Site Agent MCP now sets access per role. To keep things as they were, %d account that already had an AI connection keeps full access as an exception. Check it in Access.', 'Site Agent MCP now sets access per role. To keep things as they were, %d accounts that already had an AI connection keep full access as exceptions. Check them in Access.', $kept, 'site-agent-mcp-lite' ), $kept ) );
				echo ' <a href="' . esc_url( self::url( 'access' ) ) . '">' . esc_html__( 'Open Access', 'site-agent-mcp-lite' ) . '</a></p></div>';
			}
			?>
			<nav class="nav-tab-wrapper samcp-tabs" aria-label="<?php esc_attr_e( 'Site Agent sections', 'site-agent-mcp-lite' ); ?>">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="samcp-content">
				<?php
				switch ( $tab ) {
					case 'connect':
						self::render_connect( true );
						break;
					case 'services':
						self::render_services();
						break;
					case 'backups':
						do_action( 'samcp_backups_tab_top' );
						Backup::render_section();
						do_action( 'samcp_render_backups_tab' );
						break;
					case 'activity':
						self::render_activity();
						break;
					case 'access':
						self::render_access();
						break;
					case 'settings':
						self::render_settings();
						break;
					default:
						self::render_overview();
				}
				?>
			</div>
			<?php self::render_dialog(); ?>
		</div>
		<?php
	}

	/** The server drops the Authorization header: say what fails, how to fix it, and offer the fix. */
	private static function render_header_problem() {
		$nginx = Header_Check::is_nginx();
		?>
		<div class="notice notice-error inline samcp-header-problem" id="samcp-header-problem">
			<p><strong><?php esc_html_e( 'Your server does not pass the Authorization header to WordPress.', 'site-agent-mcp-lite' ); ?></strong> <?php esc_html_e( 'AI apps can be approved, but every call they make will fail.', 'site-agent-mcp-lite' ); ?></p>
			<p>
				<?php
				if ( Header_Check::is_iis() ) {
					esc_html_e( 'This server runs IIS: ask your host to pass the Authorization header to PHP.', 'site-agent-mcp-lite' );
				} elseif ( $nginx ) {
					esc_html_e( 'Add this line to the PHP location block of the site\'s Nginx configuration, then reload Nginx:', 'site-agent-mcp-lite' );
				} else {
					esc_html_e( 'Add these lines at the very top of the .htaccess file at the root of the site, above every other rewrite block:', 'site-agent-mcp-lite' );
				}
				?>
			</p>
			<pre class="samcp-code"><code><?php echo esc_html( Header_Check::fix_lines() ); ?></code></pre>
			<?php if ( Header_Check::can_fix() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'samcp_fix_header' ); ?>
					<input type="hidden" name="action" value="samcp_fix_header">
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Fix the server configuration', 'site-agent-mcp-lite' ); ?></button>
					<span class="samcp-muted"><?php esc_html_e( 'A copy of .htaccess is kept first; the change is checked at once and undone if it does not work.', 'site-agent-mcp-lite' ); ?></span></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Banner shown while agents are switched off, with the switch. */
	private static function render_switched_off() {
		?>
		<div class="notice notice-warning inline samcp-switched-off">
			<p><strong><?php esc_html_e( 'AI agents are switched off on this site.', 'site-agent-mcp-lite' ); ?></strong> <?php esc_html_e( 'No app can connect or act until an administrator switches them on.', 'site-agent-mcp-lite' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'samcp_switch' ); ?>
				<input type="hidden" name="action" value="samcp_switch">
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Switch on AI agents', 'site-agent-mcp-lite' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Access: who may connect, how far each connection goes, and the site-wide switches. */
	private static function render_access() {
		$settings = Policy::settings();
		$levels   = array_keys( Policy::LEVELS );
		$roles    = wp_roles()->roles;
		?>
		<section class="samcp-panel" aria-labelledby="samcp-access-title">
			<h2 id="samcp-access-title"><?php esc_html_e( 'Access for AI agents', 'site-agent-mcp-lite' ); ?></h2>
			<p class="samcp-lead"><?php esc_html_e( 'Choose who can connect AI apps and how far each connection can go. On the consent screen, each person picks a level, never above what is set here. Changes reach existing connections at once.', 'site-agent-mcp-lite' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'samcp_access' ); ?>
				<input type="hidden" name="action" value="samcp_access">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'AI agents', 'site-agent-mcp-lite' ); ?></th>
						<td>
							<label for="samcp-enabled"><input id="samcp-enabled" type="checkbox" name="enabled" value="1" aria-describedby="samcp-enabled-help" <?php checked( Policy::enabled() ); ?>>
							<?php esc_html_e( 'Allow AI apps to connect to this site', 'site-agent-mcp-lite' ); ?></label>
							<p class="description" id="samcp-enabled-help"><?php esc_html_e( 'Switched off, every connection stops at once and no new one can start.', 'site-agent-mcp-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugins and themes', 'site-agent-mcp-lite' ); ?></th>
						<td>
							<label for="samcp-extensions"><input id="samcp-extensions" type="checkbox" name="allow_extensions" value="1" aria-describedby="samcp-extensions-help" <?php checked( Policy::extensions_allowed() ); ?>>
							<?php esc_html_e( 'Let agents install plugins and themes from wordpress.org, update, switch and remove them, and update WordPress', 'site-agent-mcp-lite' ); ?></label>
							<p class="description" id="samcp-extensions-help"><?php esc_html_e( 'Only for connections with "Everything the account can do", and only for accounts that have these rights in WordPress.', 'site-agent-mcp-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notification', 'site-agent-mcp-lite' ); ?></th>
						<td>
							<label for="samcp-notify"><input id="samcp-notify" type="checkbox" name="notify" value="1" <?php checked( Policy::notify_enabled() ); ?>>
							<?php esc_html_e( 'Email the account and the site administrator for each new connection', 'site-agent-mcp-lite' ); ?></label>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'By role', 'site-agent-mcp-lite' ); ?></h3>
				<table class="widefat striped samcp-table samcp-access-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Highest access level for each role', 'site-agent-mcp-lite' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Role', 'site-agent-mcp-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Highest level', 'site-agent-mcp-lite' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $roles as $slug => $role ) : ?>
						<?php $current = isset( $settings['roles'][ $slug ] ) ? $settings['roles'][ $slug ] : 'none'; ?>
						<tr>
							<th scope="row"><label for="samcp-role-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label></th>
							<td>
								<select id="samcp-role-<?php echo esc_attr( $slug ); ?>" name="roles[<?php echo esc_attr( $slug ); ?>]">
									<?php foreach ( $levels as $level ) : ?>
										<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $level, $current ); ?>><?php echo esc_html( Policy::level_label( $level ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<dl class="samcp-levels">
					<?php foreach ( $levels as $level ) : ?>
						<dt><?php echo esc_html( Policy::level_label( $level ) ); ?></dt>
						<dd><?php echo esc_html( Policy::level_help( $level ) ); ?></dd>
					<?php endforeach; ?>
				</dl>

				<h3><?php esc_html_e( 'Exceptions for specific accounts', 'site-agent-mcp-lite' ); ?></h3>
				<p class="description"><?php esc_html_e( 'An exception replaces the role level for one account, higher or lower. Choose "Remove the exception" to go back to the role level.', 'site-agent-mcp-lite' ); ?></p>
				<table class="widefat striped samcp-table samcp-access-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Access level exceptions for specific accounts', 'site-agent-mcp-lite' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Account', 'site-agent-mcp-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Level', 'site-agent-mcp-lite' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $settings['users'] as $user_id => $current ) : ?>
						<?php
						$user = get_userdata( (int) $user_id );
						if ( ! $user ) {
							continue;
						}
						?>
						<tr>
							<th scope="row"><label for="samcp-user-<?php echo esc_attr( (string) $user_id ); ?>"><?php echo esc_html( $user->user_login ); ?></label><br><span class="samcp-muted"><?php echo esc_html( self::role_names( $user ) ); ?></span></th>
							<td>
								<select id="samcp-user-<?php echo esc_attr( (string) $user_id ); ?>" name="users[<?php echo esc_attr( (string) $user_id ); ?>]">
									<?php foreach ( $levels as $level ) : ?>
										<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $level, $current ); ?>><?php echo esc_html( Policy::level_label( $level ) ); ?></option>
									<?php endforeach; ?>
									<option value=""><?php esc_html_e( 'Remove the exception', 'site-agent-mcp-lite' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="samcp-new-user"><?php esc_html_e( 'Add an account (login or email)', 'site-agent-mcp-lite' ); ?></label></th>
						<td>
							<input id="samcp-new-user" name="new_user" type="text" class="regular-text" autocomplete="off">
							<label for="samcp-new-level" class="screen-reader-text"><?php esc_html_e( 'Level for this account', 'site-agent-mcp-lite' ); ?></label>
							<select id="samcp-new-level" name="new_level">
								<?php foreach ( $levels as $level ) : ?>
									<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $level, 'none' ); ?>><?php echo esc_html( Policy::level_label( $level ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save access', 'site-agent-mcp-lite' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * New connections: closed by default, opened for a while by a person here.
	 */
	private static function render_pairing() {
		if ( ! Policy::enabled() ) {
			return;
		}
		$open    = Policy::pairing_open( get_current_user_id() );
		$minutes = (int) ceil( Policy::pairing_left( get_current_user_id() ) / 60 );
		?>
		<section class="samcp-panel samcp-pairing" aria-labelledby="samcp-pairing-title">
			<div class="samcp-panel-head">
				<h2 id="samcp-pairing-title"><?php esc_html_e( 'New connections', 'site-agent-mcp-lite' ); ?></h2>
				<?php
				if ( $open ) {
					/* translators: %d: minutes. */
					self::badge( 'success', sprintf( _n( 'Open for %d more minute', 'Open for %d more minutes', $minutes, 'site-agent-mcp-lite' ), $minutes ) );
				} else {
					self::badge( 'neutral', __( 'Closed', 'site-agent-mcp-lite' ) );
				}
				?>
			</div>
			<p><?php echo $open ? esc_html__( 'Start the connection from your AI app now. New connections close by themselves.', 'site-agent-mcp-lite' ) : esc_html__( 'To connect an app, open new connections here, then start from the app within 15 minutes. A link received by email or chat can never connect an app on its own.', 'site-agent-mcp-lite' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'samcp_pairing' ); ?>
				<input type="hidden" name="action" value="samcp_pairing">
				<?php if ( $open ) : ?>
					<input type="hidden" name="pairing" value="close">
					<p><button type="submit" class="button"><?php esc_html_e( 'Close now', 'site-agent-mcp-lite' ); ?></button></p>
				<?php else : ?>
					<input type="hidden" name="pairing" value="open">
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Allow a new connection', 'site-agent-mcp-lite' ); ?></button></p>
				<?php endif; ?>
			</form>
		</section>
		<?php
	}

	/** Profile > AI connections, for users who are not administrators. */
	private static function render_profile() {
		?>
		<div class="wrap samcp"<?php self::lang_attribute(); ?>>
			<h1><?php esc_html_e( 'AI connections', 'site-agent-mcp-lite' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::render_notice( true ); ?>
			<div class="samcp-content">
				<?php self::render_connect( false ); ?>
			</div>
			<?php self::render_dialog(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- overview */

	/** Overview: status tiles, first steps, checks and latest activity. */
	private static function render_overview() {
		$connections = Store::connections( null );
		$last_used   = 0;
		$power       = 0;
		foreach ( $connections as $row ) {
			$last_used = max( $last_used, (int) $row['last_used_at'] );
			if ( false !== strpos( (string) $row['scope'], OAuth::SCOPE_POWER ) ) {
				++$power;
			}
		}
		$backups       = Backup::all();
		$latest        = $backups ? $backups[0] : null;
		$last_complete = null;
		foreach ( $backups as $backup ) {
			if ( 'complete' === $backup['state'] ) {
				$last_complete = $backup;
				break;
			}
		}
		$audit    = Security_Audit::overview_hint();
		$checks   = get_transient( self::CHECKS );
		$checks   = is_array( $checks ) && isset( $checks['results'] ) ? $checks : null;
		$passed   = $checks ? count( array_filter( $checks['results'] ) ) : 0;
		$total    = $checks ? count( $checks['results'] ) : 0;
		$services = '' !== Seo_Tools::api_key( Seo_Tools::GOOGLE_OPTION, 'SAMCP_GOOGLE_API_KEY' ) || '' !== Seo_Tools::api_key( Seo_Tools::GTMETRIX_OPTION, 'SAMCP_GTMETRIX_API_KEY' ) || Search_Console::is_connected();

		// Connections.
		$count = count( $connections );
		if ( $power ) {
			/* translators: %d: number of connections. */
			$conn = array( 'warning', sprintf( _n( '%d with server tools', '%d with server tools', $power, 'site-agent-mcp-lite' ), $power ) );
		} else {
			$conn = $count ? array( 'success', __( 'Connected', 'site-agent-mcp-lite' ) ) : array( 'neutral', __( 'Not connected', 'site-agent-mcp-lite' ) );
		}
		$tiles = array(
			array(
				'icon'  => 'admin-links',
				'label' => __( 'Connections', 'site-agent-mcp-lite' ),
				/* translators: %d: number of connections. */
				'value' => $count ? sprintf( _n( '%d active', '%d active', $count, 'site-agent-mcp-lite' ), $count ) : __( 'None yet', 'site-agent-mcp-lite' ),
				/* translators: %s: date. */
				'meta'  => $last_used ? sprintf( __( 'Last used %s', 'site-agent-mcp-lite' ), wp_date( 'Y-m-d H:i', $last_used ) ) : __( 'Not used yet', 'site-agent-mcp-lite' ),
				'state' => $conn[0],
				'badge' => $conn[1],
				'url'   => self::url( 'connect' ),
				'link'  => $count ? __( 'Manage connections', 'site-agent-mcp-lite' ) : __( 'Connect an app', 'site-agent-mcp-lite' ),
			),
		);

		// Backups.
		$backup_tile = array(
			'icon'  => 'backup',
			'label' => __( 'Last backup', 'site-agent-mcp-lite' ),
			'value' => __( 'None yet', 'site-agent-mcp-lite' ),
			'meta'  => __( 'Create one before letting an agent change the site.', 'site-agent-mcp-lite' ),
			'state' => 'neutral',
			'badge' => __( 'No backup yet', 'site-agent-mcp-lite' ),
			'url'   => self::url( 'backups' ),
			'link'  => __( 'Create a backup', 'site-agent-mcp-lite' ),
		);
		if ( $latest && ! in_array( $latest['state'], array( 'complete', 'failed' ), true ) ) {
			$backup_tile['value'] = __( 'Backing up', 'site-agent-mcp-lite' );
			$backup_tile['meta']  = __( 'A backup is running.', 'site-agent-mcp-lite' );
			$backup_tile['state'] = 'info';
			$backup_tile['badge'] = __( 'In progress', 'site-agent-mcp-lite' );
			$backup_tile['link']  = __( 'Show progress', 'site-agent-mcp-lite' );
		} elseif ( $latest && 'failed' === $latest['state'] ) {
			$backup_tile['value'] = wp_date( 'Y-m-d H:i', strtotime( $latest['created'] ) );
			$backup_tile['meta']  = __( 'The last backup did not finish.', 'site-agent-mcp-lite' );
			$backup_tile['state'] = 'error';
			$backup_tile['badge'] = __( 'Failed', 'site-agent-mcp-lite' );
			$backup_tile['link']  = __( 'Open backups', 'site-agent-mcp-lite' );
		} elseif ( $last_complete ) {
			$age                  = time() - (int) strtotime( $last_complete['created'] );
			$backup_tile['value'] = wp_date( 'Y-m-d H:i', strtotime( $last_complete['created'] ) );
			/* translators: %s: size in megabytes, such as 12.5. */
			$backup_tile['meta']  = sprintf( __( '%s MB', 'site-agent-mcp-lite' ), number_format_i18n( (float) $last_complete['size_mb'], 1 ) );
			$backup_tile['state'] = $age < 7 * DAY_IN_SECONDS ? 'success' : 'warning';
			$backup_tile['badge'] = $age < 7 * DAY_IN_SECONDS ? __( 'Recent', 'site-agent-mcp-lite' ) : __( 'Over a week old', 'site-agent-mcp-lite' );
			$backup_tile['link']  = __( 'Open backups', 'site-agent-mcp-lite' );
		}
		$tiles[] = $backup_tile;

		// Security audit.
		if ( null === $audit['last_audit'] ) {
			$audit_state = array( 'neutral', __( 'Never run', 'site-agent-mcp-lite' ) );
		} else {
			$audit_state = $audit['audit_due'] ? array( 'warning', __( 'Due', 'site-agent-mcp-lite' ) ) : array( 'success', __( 'Up to date', 'site-agent-mcp-lite' ) );
		}
		$tiles[] = array(
			'icon'  => 'shield',
			'label' => __( 'Security audit', 'site-agent-mcp-lite' ),
			'value' => null === $audit['last_audit'] ? __( 'Never run', 'site-agent-mcp-lite' ) : $audit['last_audit'],
			'meta'  => __( 'Your AI app runs it with its security_audit tool, which changes nothing.', 'site-agent-mcp-lite' ),
			'state' => $audit_state[0],
			'badge' => $audit_state[1],
			'url'   => '',
			'link'  => '',
		);

		// Checks.
		$first_problem = '';
		if ( $checks ) {
			foreach ( $checks['results'] as $id => $ok ) {
				if ( ! $ok ) {
					$first_problem = self::check_text( $id, false )[0];
					break;
				}
			}
		}
		$tiles[] = array(
			'icon'  => 'yes-alt',
			'label' => __( 'Connection checks', 'site-agent-mcp-lite' ),
			/* translators: 1: checks passed, 2: checks run. */
			'value' => $checks ? sprintf( __( '%1$d of %2$d passed', 'site-agent-mcp-lite' ), $passed, $total ) : __( 'Not checked yet', 'site-agent-mcp-lite' ),
			/* translators: %s: date. */
			'meta'  => '' !== $first_problem ? $first_problem : ( $checks ? sprintf( __( 'Checked %s', 'site-agent-mcp-lite' ), wp_date( 'Y-m-d H:i', (int) $checks['time'] ) ) : '' ),
			'state'  => $checks ? ( $passed === $total ? 'success' : 'error' ) : 'neutral',
			'badge'  => $checks ? ( $passed === $total ? __( 'All passed', 'site-agent-mcp-lite' ) : __( 'Problem', 'site-agent-mcp-lite' ) ) : __( 'Not checked yet', 'site-agent-mcp-lite' ),
			'url'    => '',
			'link'   => '',
			'checks' => true,
		);

		$steps = array(
			array(
				'done' => $checks && $passed === $total,
				'text' => __( 'Run the connection checks and fix any problem.', 'site-agent-mcp-lite' ),
				'url'  => '#samcp-checks',
				'link' => __( 'Go to the checks', 'site-agent-mcp-lite' ),
			),
			array(
				'done' => $count > 0,
				'text' => __( 'Connect your first AI app.', 'site-agent-mcp-lite' ),
				'url'  => self::url( 'connect' ),
				'link' => __( 'Connect an app', 'site-agent-mcp-lite' ),
			),
			array(
				'done' => null !== $last_complete,
				'text' => __( 'Create a first backup, before letting an agent change the site.', 'site-agent-mcp-lite' ),
				'url'  => self::url( 'backups' ),
				'link' => __( 'Create a backup', 'site-agent-mcp-lite' ),
			),
			array(
				'done' => $services,
				'text' => __( 'Optional: connect SEO services. Agents work without them.', 'site-agent-mcp-lite' ),
				'url'  => self::url( 'services' ),
				'link' => __( 'Set up services', 'site-agent-mcp-lite' ),
			),
		);
		$open = ! ( $steps[0]['done'] && $steps[1]['done'] && $steps[2]['done'] );
		?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Status', 'site-agent-mcp-lite' ); ?></h2>
		<ul class="samcp-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<li class="samcp-tile is-<?php echo esc_attr( $tile['state'] ); ?>">
					<div class="samcp-tile-head">
						<h3><span class="dashicons dashicons-<?php echo esc_attr( $tile['icon'] ); ?>" aria-hidden="true"></span><?php echo esc_html( $tile['label'] ); ?></h3>
						<?php self::badge( $tile['state'], $tile['badge'] ); ?>
					</div>
					<p class="samcp-tile-value"><?php echo esc_html( $tile['value'] ); ?></p>
					<?php if ( '' !== $tile['meta'] ) : ?>
						<p class="samcp-tile-meta"><?php echo esc_html( $tile['meta'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $tile['url'] ) : ?>
						<p class="samcp-tile-link"><a href="<?php echo esc_url( $tile['url'] ); ?>"><?php echo esc_html( $tile['link'] ); ?></a></p>
					<?php endif; ?>
					<?php if ( ! empty( $tile['checks'] ) ) : ?>
						<div class="samcp-tile-checks" id="samcp-checks">
							<?php if ( $checks ) : ?>
								<details class="samcp-tile-details"<?php echo $passed === $total ? '' : ' open'; ?>>
									<summary><?php esc_html_e( 'See the checks', 'site-agent-mcp-lite' ); ?></summary>
									<ul class="samcp-status-list">
										<?php foreach ( $checks['results'] as $id => $ok ) : ?>
											<?php $text = self::check_text( $id, $ok ); ?>
											<li data-samcp-check="<?php echo esc_attr( $id ); ?>" data-samcp-ok="<?php echo $ok ? '1' : '0'; ?>">
												<?php self::badge( $ok ? 'success' : 'error', $ok ? __( 'OK', 'site-agent-mcp-lite' ) : __( 'Problem', 'site-agent-mcp-lite' ) ); ?>
												<span class="samcp-status-text">
													<strong><?php echo esc_html( $text[0] ); ?></strong>
													<?php if ( '' !== $text[1] ) : ?>
														<span class="samcp-fix"><?php echo esc_html( $text[1] ); ?></span>
													<?php endif; ?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="samcp-tile-form">
								<?php wp_nonce_field( 'samcp_checks' ); ?>
								<input type="hidden" name="action" value="samcp_checks">
								<button type="submit" class="button<?php echo $checks ? '' : ' button-primary'; ?>" aria-describedby="samcp-checks-time"><?php echo $checks ? esc_html__( 'Run checks again', 'site-agent-mcp-lite' ) : esc_html__( 'Run the checks', 'site-agent-mcp-lite' ); ?></button>
								<span id="samcp-checks-time" class="description"><?php esc_html_e( 'Takes up to 20 seconds.', 'site-agent-mcp-lite' ); ?></span>
							</form>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $open ) : ?>
			<section class="samcp-panel samcp-start" aria-labelledby="samcp-start-title">
				<h2 id="samcp-start-title"><?php esc_html_e( 'Get started', 'site-agent-mcp-lite' ); ?></h2>
				<ol class="samcp-stepper">
					<?php foreach ( $steps as $step ) : ?>
						<li class="samcp-step <?php echo $step['done'] ? 'is-done' : 'is-todo'; ?>">
							<?php self::badge( $step['done'] ? 'success' : 'neutral', $step['done'] ? __( 'Done', 'site-agent-mcp-lite' ) : __( 'To do', 'site-agent-mcp-lite' ) ); ?>
							<span class="samcp-step-text"><?php echo esc_html( $step['text'] ); ?></span>
							<?php if ( ! $step['done'] ) : ?>
								<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['link'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<section class="samcp-panel" aria-labelledby="samcp-recent-title">
			<div class="samcp-panel-head">
				<h2 id="samcp-recent-title"><?php esc_html_e( 'Latest activity', 'site-agent-mcp-lite' ); ?></h2>
				<a href="<?php echo esc_url( self::url( 'activity' ) ); ?>"><?php esc_html_e( 'View all activity', 'site-agent-mcp-lite' ); ?></a>
			</div>
			<?php self::render_audit( Store::recent_audit( 5 ), __( 'The 5 latest tool calls by AI apps', 'site-agent-mcp-lite' ), __( 'No agent activity yet.', 'site-agent-mcp-lite' ), true ); ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------- connect */

	/**
	 * How to connect, and the connections themselves.
	 *
	 * @param bool $is_admin Show every user's connections and the advanced parts.
	 */
	private static function render_connect( $is_admin ) {
		$url      = OAuth::resource();
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name     = sanitize_key( str_replace( '.', '-', $host ) );
		$commands = array(
			array( 'Claude Code', 'claude mcp add --transport http ' . $name . ' ' . $url, __( 'Copy the Claude Code command', 'site-agent-mcp-lite' ), 'Claude Code' ),
			array( 'Codex CLI', 'codex mcp add ' . $name . ' --url ' . $url . "\ncodex mcp login " . $name, __( 'Copy the Codex CLI commands', 'site-agent-mcp-lite' ), 'Codex CLI' ),
			array( 'Gemini CLI', 'gemini mcp add --transport http ' . $name . ' ' . $url, __( 'Copy the Gemini CLI command', 'site-agent-mcp-lite' ), 'Gemini CLI' ),
			array( 'Antigravity (mcp_config.json)', wp_json_encode( array( 'mcpServers' => array( $name => array( 'serverUrl' => $url ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), __( 'Copy the Antigravity configuration', 'site-agent-mcp-lite' ), 'Antigravity' ),
		);
		// One entry per AI app: tab label, heading, then either a sentence or a command to copy.
		$clients = array(
			array(
				'id'    => 'claude',
				'tab'   => 'Claude',
				'title' => __( 'Claude (web, desktop, mobile)', 'site-agent-mcp-lite' ),
				'text'  => __( 'Customize > Connectors > Add custom connector, then paste the server URL.', 'site-agent-mcp-lite' ),
				'code'  => '',
			),
			array(
				'id'    => 'chatgpt',
				'tab'   => 'ChatGPT',
				'title' => __( 'ChatGPT', 'site-agent-mcp-lite' ),
				'text'  => __( 'Plugins > Add custom MCP server, then paste the server URL.', 'site-agent-mcp-lite' ),
				'code'  => '',
			),
		);
		foreach ( $commands as $index => $command ) {
			$clients[] = array(
				'id'    => 'command-' . $index,
				'tab'   => $command[3],
				'title' => $command[0],
				'text'  => '',
				'code'  => $command[1],
				'copy'  => $command[2],
				'index' => $index,
			);
		}
		$roles = self::role_names( wp_get_current_user() );
		self::render_pairing();
		?>
		<section class="samcp-panel" aria-labelledby="samcp-connect-title">
			<h2 id="samcp-connect-title"><?php esc_html_e( 'Connect an AI app', 'site-agent-mcp-lite' ); ?></h2>
			<p class="samcp-lead">
				<?php
				if ( $is_admin ) {
					esc_html_e( 'The AI app gets the rights of the WordPress account that approves it, within the level chosen on the consent screen and set in Access.', 'site-agent-mcp-lite' );
				} else {
					/* translators: %s: role names. */
					echo esc_html( sprintf( __( 'The AI app gets at most the rights of your WordPress account (%s), within the level you choose when you approve it.', 'site-agent-mcp-lite' ), $roles ) );
				}
				?>
			</p>
			<?php self::copy_field( 'samcp-url', __( 'Server URL', 'site-agent-mcp-lite' ), $url, __( 'Copy the server URL', 'site-agent-mcp-lite' ) ); ?>
			<?php /* Tabs once the script runs (WAI-ARIA tabs pattern); every panel stays visible without it. */ ?>
			<div class="samcp-clients" data-samcp-tabs>
				<div class="samcp-client-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Choose your AI app', 'site-agent-mcp-lite' ); ?>" hidden>
					<?php foreach ( $clients as $position => $client ) : ?>
						<button type="button" class="button samcp-client-tab" role="tab" id="samcp-client-tab-<?php echo esc_attr( $client['id'] ); ?>" aria-controls="samcp-client-panel-<?php echo esc_attr( $client['id'] ); ?>" aria-selected="<?php echo 0 === $position ? 'true' : 'false'; ?>" tabindex="<?php echo 0 === $position ? '0' : '-1'; ?>"><?php echo esc_html( $client['tab'] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $clients as $client ) : ?>
					<div class="samcp-snippet samcp-client-panel" id="samcp-client-panel-<?php echo esc_attr( $client['id'] ); ?>" role="tabpanel" aria-labelledby="samcp-client-tab-<?php echo esc_attr( $client['id'] ); ?>">
						<div class="samcp-snippet-head">
							<h3><?php echo esc_html( $client['title'] ); ?></h3>
							<?php
							if ( '' !== $client['code'] ) {
								self::copy_button( 'samcp-command-' . $client['index'], $client['copy'] );
							}
							?>
						</div>
						<?php if ( '' !== $client['code'] ) : ?>
							<pre class="samcp-code" id="samcp-command-<?php echo esc_attr( (string) $client['index'] ); ?>"><code><?php echo esc_html( $client['code'] ); ?></code></pre>
						<?php else : ?>
							<p><?php echo esc_html( $client['text'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="samcp-panel" aria-labelledby="samcp-connections-title">
			<h2 id="samcp-connections-title"><?php echo $is_admin ? esc_html__( 'Active connections', 'site-agent-mcp-lite' ) : esc_html__( 'Your connections', 'site-agent-mcp-lite' ); ?></h2>
			<?php self::render_connections( $is_admin ); ?>
		</section>

		<?php if ( $is_admin ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$notice = isset( $_GET['samcp_notice'] ) ? sanitize_key( wp_unslash( $_GET['samcp_notice'] ) ) : '';
			$open   = in_array( $notice, array( 'client_invalid', 'client_created', 'client_deleted' ), true );
			?>
			<section class="samcp-section-advanced" aria-labelledby="samcp-manual-title">
				<details class="samcp-advanced samcp-manual"<?php echo $open ? ' open' : ''; ?>>
					<summary><span id="samcp-manual-title"><?php esc_html_e( 'Advanced: register an application manually', 'site-agent-mcp-lite' ); ?></span></summary>
					<p class="samcp-lead"><?php esc_html_e( 'Most apps register themselves. Register one here only if an app asks for a client ID and secret.', 'site-agent-mcp-lite' ); ?></p>
					<?php self::render_clients(); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'samcp_client_create' ); ?>
						<input type="hidden" name="action" value="samcp_client_create">
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="samcp-client-name"><?php esc_html_e( 'App name', 'site-agent-mcp-lite' ); ?></label></th>
								<td><input id="samcp-client-name" name="client_name" class="regular-text" type="text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="samcp-client-uris"><?php esc_html_e( 'Return addresses (redirect URIs)', 'site-agent-mcp-lite' ); ?></label></th>
								<td>
									<textarea id="samcp-client-uris" name="redirect_uris" class="large-text code" rows="2" required aria-describedby="samcp-client-uris-help"></textarea>
									<p class="description" id="samcp-client-uris-help"><?php esc_html_e( 'One per line, as the app gives them. https addresses, or http for an app running on your own computer.', 'site-agent-mcp-lite' ); ?></p>
								</td>
							</tr>
						</table>
						<p><button type="submit" class="button"><?php esc_html_e( 'Register app', 'site-agent-mcp-lite' ); ?></button></p>
					</form>
				</details>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render connections.
	 *
	 * @param bool $is_admin Show every user's connections.
	 */
	private static function render_connections( $is_admin ) {
		$rows = Store::connections( $is_admin ? null : get_current_user_id() );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No AI app connected yet. Follow the instructions above for your app; it shows up here once you approve it.', 'site-agent-mcp-lite' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped samcp-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Connected AI apps', 'site-agent-mcp-lite' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'App', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Account', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Access', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Connected', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last used', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'site-agent-mcp-lite' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$user      = get_userdata( (int) $row['user_id'] );
				$login     = $user ? $user->user_login : '#' . $row['user_id'];
				$power     = false !== strpos( (string) $row['scope'], OAuth::SCOPE_POWER );
				$created   = $row['created_at'] ? wp_date( 'Y-m-d H:i', (int) $row['created_at'] ) : '';
				$used      = $row['last_used_at'] ? wp_date( 'Y-m-d H:i', (int) $row['last_used_at'] ) : '';
				$used_text = '' !== $used ? $used : __( 'never', 'site-agent-mcp-lite' );
				// Seconds keep two connections made the same minute apart for screen readers.
				$created_full = $row['created_at'] ? wp_date( 'Y-m-d H:i:s', (int) $row['created_at'] ) : '-';
				/* translators: 1: app name, 2: account, 3: connection date, 4: last use. */
				$name = sprintf( __( 'Disconnect %1$s (%2$s, connected %3$s, last used %4$s)', 'site-agent-mcp-lite' ), $row['client_name'], $login, $created_full, $used_text );
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $row['client_name'] ); ?></th>
					<td><?php echo esc_html( $login ); ?><?php if ( $user ) : ?><br><span class="samcp-muted"><?php echo esc_html( self::role_names( $user ) ); ?></span><?php endif; ?></td>
					<td>
						<?php
						$granted   = Policy::scope_level( (string) $row['scope'] );
						$effective = Policy::lower( $granted, Policy::user_max_level( (int) $row['user_id'] ) );
						echo esc_html( Policy::level_label( $effective ) );
						if ( $effective !== $granted ) {
							echo '<br><span class="samcp-muted">' . esc_html__( 'Limited by the Access settings', 'site-agent-mcp-lite' ) . '</span>';
						}
						if ( $power && 'full' === $effective ) {
							echo '<br>';
							self::badge( 'warning', __( 'Server tools (files, SQL, PHP)', 'site-agent-mcp-lite' ) );
						}
						?>
					</td>
					<td class="samcp-nowrap"><?php echo '' !== $created ? esc_html( $created ) : '&mdash;'; ?></td>
					<td class="samcp-nowrap"><?php echo '' !== $used ? esc_html( $used ) : '&mdash;'; ?></td>
					<td>
						<?php
						/* translators: 1: app name, 2: account. */
						$question = sprintf( __( 'Disconnect %1$s (%2$s)?', 'site-agent-mcp-lite' ), $row['client_name'], $login );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="samcp-inline-form"<?php echo self::confirm_attributes( $question, __( 'The app loses access to this site at once. To use it again, connect it again from the app.', 'site-agent-mcp-lite' ), __( 'Disconnect', 'site-agent-mcp-lite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<?php wp_nonce_field( 'samcp_revoke' ); ?>
							<input type="hidden" name="action" value="samcp_revoke">
							<input type="hidden" name="family" value="<?php echo esc_attr( $row['family'] ); ?>">
							<?php self::confirm_fallback( 'samcp-confirm-' . $row['family'], __( 'Confirm', 'site-agent-mcp-lite' ) ); ?>
							<button type="submit" class="button button-small samcp-button-danger" aria-label="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Disconnect', 'site-agent-mcp-lite' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Render administrator-created clients. */
	private static function render_clients() {
		$rows = Store::manual_clients();
		if ( ! $rows ) {
			return;
		}
		?>
		<table class="widefat striped samcp-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Apps registered by hand', 'site-agent-mcp-lite' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'App', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Client ID', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'site-agent-mcp-lite' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row['client_name'] ); ?></th>
					<td class="samcp-break"><code><?php echo esc_html( $row['client_id'] ); ?></code></td>
					<td>
						<?php
						/* translators: %s: app name. */
						$question = sprintf( __( 'Delete the app %s?', 'site-agent-mcp-lite' ), $row['client_name'] );
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="samcp-inline-form"<?php echo self::confirm_attributes( $question, __( 'Its client secret stops working and every connection made with it ends. This cannot be undone.', 'site-agent-mcp-lite' ), __( 'Delete app', 'site-agent-mcp-lite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
							<?php wp_nonce_field( 'samcp_client_delete' ); ?>
							<input type="hidden" name="action" value="samcp_client_delete">
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $row['client_id'] ); ?>">
							<?php self::confirm_fallback( 'samcp-confirm-client-' . md5( $row['client_id'] ), __( 'Confirm', 'site-agent-mcp-lite' ) ); ?>
							<?php /* translators: %s: app name. */ ?>
							<button type="submit" class="button button-small samcp-button-danger" aria-label="<?php echo esc_attr( sprintf( __( 'Delete the app %s', 'site-agent-mcp-lite' ), $row['client_name'] ) ); ?>"><?php esc_html_e( 'Delete', 'site-agent-mcp-lite' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------- services */

	/**
	 * A secret field that never shows its stored value. Its state and how to remove
	 * it are visible text, tied to the field.
	 *
	 * @param string $id    Field id and name.
	 * @param string $label Label.
	 * @param bool   $saved A value is stored.
	 */
	private static function secret_field( $id, $label, $saved ) {
		?>
		<div class="samcp-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="password" autocomplete="new-password" spellcheck="false" data-1p-ignore data-lpignore="true" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
			<p class="description" id="<?php echo esc_attr( $id ); ?>-help">
				<?php if ( $saved ) : ?>
					<strong><?php esc_html_e( 'Saved.', 'site-agent-mcp-lite' ); ?></strong>
					<?php esc_html_e( 'Leave the field blank to keep it. Type a new value to replace it, or a single dash (-) to remove it.', 'site-agent-mcp-lite' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Not saved yet. Paste it from the service.', 'site-agent-mcp-lite' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Results of the last connection test for one service.
	 *
	 * @param array[] $tests Results.
	 */
	private static function render_tests( array $tests ) {
		if ( ! $tests ) {
			return;
		}
		?>
		<ul class="samcp-status-list samcp-tests">
			<?php foreach ( $tests as $test ) : ?>
				<li data-samcp-test="<?php echo esc_attr( $test['label'] ); ?>" data-samcp-ok="<?php echo $test['ok'] ? '1' : '0'; ?>">
					<?php self::badge( $test['ok'] ? 'success' : 'error', $test['ok'] ? __( 'OK', 'site-agent-mcp-lite' ) : __( 'Problem', 'site-agent-mcp-lite' ) ); ?>
					<?php /* translators: %s: service name followed by a colon, such as "GTmetrix:". */ ?>
					<span class="samcp-status-text"><strong><?php echo esc_html( sprintf( __( '%s:', 'site-agent-mcp-lite' ), $test['label'] ) ); ?></strong> <?php echo esc_html( $test['detail'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/** Button that tests every configured service. */
	private static function render_test_button() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'samcp_test_services' ); ?>
			<input type="hidden" name="action" value="samcp_test_services">
			<button type="submit" class="button"><?php esc_html_e( 'Test connections', 'site-agent-mcp-lite' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(every service set up on this page)', 'site-agent-mcp-lite' ); ?></span></button>
		</form>
		<?php
	}

	/**
	 * Badge of a service.
	 *
	 * @param bool    $ready Set up.
	 * @param array[] $tests Last test results.
	 * @return array{0:string,1:string}
	 */
	private static function service_state( $ready, array $tests ) {
		foreach ( $tests as $test ) {
			if ( ! $test['ok'] ) {
				return array( 'error', __( 'Needs attention', 'site-agent-mcp-lite' ) );
			}
		}
		return $ready ? array( 'success', __( 'Ready', 'site-agent-mcp-lite' ) ) : array( 'neutral', __( 'Not set up', 'site-agent-mcp-lite' ) );
	}

	/** Setup of outside services: one card each. */
	private static function render_services() {
		$google_const = defined( 'SAMCP_GOOGLE_API_KEY' );
		$gtm_const    = defined( 'SAMCP_GTMETRIX_API_KEY' );
		$google       = '' !== Seo_Tools::api_key( Seo_Tools::GOOGLE_OPTION, 'SAMCP_GOOGLE_API_KEY' );
		$gtmetrix     = '' !== Seo_Tools::api_key( Seo_Tools::GTMETRIX_OPTION, 'SAMCP_GTMETRIX_API_KEY' );
		$client_id    = (string) get_option( Search_Console::ID_OPTION, '' );
		$secret       = '' !== (string) get_option( Search_Console::SECRET_OPTION, '' );
		$connected    = Search_Console::is_connected();
		$credentials  = '' !== $client_id && $secret;
		$tests        = get_transient( 'samcp_service_tests_' . get_current_user_id() );
		if ( $tests ) {
			delete_transient( 'samcp_service_tests_' . get_current_user_id() );
		}
		$results = array(
			'gsc'       => array(),
			'pagespeed' => array(),
			'gtmetrix'  => array(),
		);
		$map     = array(
			'Search Console'     => 'gsc',
			'PageSpeed Insights' => 'pagespeed',
			'Chrome UX Report'   => 'pagespeed',
			'GTmetrix'           => 'gtmetrix',
		);
		foreach ( (array) $tests as $test ) {
			if ( is_array( $test ) && isset( $test['label'] ) ) {
				$results[ isset( $map[ $test['label'] ] ) ? $map[ $test['label'] ] : 'pagespeed' ][] = $test;
			}
		}
		$post = admin_url( 'admin-post.php' );

		if ( $connected ) {
			$gsc_state = self::service_state( true, $results['gsc'] );
			$gsc_state = 'success' === $gsc_state[0] ? array( 'success', __( 'Connected', 'site-agent-mcp-lite' ) ) : $gsc_state;
		} elseif ( $credentials ) {
			$gsc_state = array( 'info', __( 'Ready to connect', 'site-agent-mcp-lite' ) );
		} else {
			$gsc_state = array( 'neutral', __( 'Not set up', 'site-agent-mcp-lite' ) );
		}
		$google_state = self::service_state( $google, $results['pagespeed'] );
		$gtm_state    = self::service_state( $gtmetrix, $results['gtmetrix'] );
		// Setup steps open only for a first-time setup: nothing configured anywhere yet.
		$first_time = ! ( $credentials || $connected || $google || $gtmetrix );
		?>
		<p class="samcp-lead"><?php esc_html_e( 'All optional. Agents work without them, but say what data they lack. Keys stay on this site, and agents never see them.', 'site-agent-mcp-lite' ); ?></p>

		<div class="samcp-services">
			<section class="samcp-panel samcp-service" aria-labelledby="samcp-gsc-title">
				<div class="samcp-service-head">
					<h2 id="samcp-gsc-title"><?php esc_html_e( 'Google Search Console', 'site-agent-mcp-lite' ); ?></h2>
					<?php self::badge( $gsc_state[0], $gsc_state[1] ); ?>
				</div>
				<p class="samcp-service-purpose"><?php esc_html_e( 'Real clicks, impressions and positions in Google, the only real ranking data. Most useful of the three.', 'site-agent-mcp-lite' ); ?></p>
				<?php self::render_tests( $results['gsc'] ); ?>
				<details class="samcp-steps"<?php echo $first_time ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Setup steps (about 10 minutes)', 'site-agent-mcp-lite' ); ?></summary>
					<ol>
						<?php
						self::step( __( 'Check that this site is verified in Search Console, with the Google account you will connect.', 'site-agent-mcp-lite' ), 'https://search.google.com/search-console', __( 'Open Search Console', 'site-agent-mcp-lite' ) );
						self::step( __( 'Create a project in Google Cloud, or pick an existing one.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/projectcreate', __( 'Create a project', 'site-agent-mcp-lite' ) );
						self::step( __( 'Enable the Google Search Console API in that project.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com', __( 'Enable the API', 'site-agent-mcp-lite' ) );
						self::step( __( 'Set up the consent screen: choose External, then fill in the app name and your email.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/auth/overview', __( 'Open the consent screen settings', 'site-agent-mcp-lite' ) );
						self::step( __( 'Publish the app under Audience. While it stays in Testing status, Google ends the connection every 7 days.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/auth/audience', __( 'Open Audience', 'site-agent-mcp-lite' ) );
						self::step( __( 'Create a client: choose Web application, and add the return address below under Authorized redirect URIs. Copy the client secret right away: Google shows it only once.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/auth/clients', __( 'Create a client', 'site-agent-mcp-lite' ) );
						?>
					</ol>
					<?php self::copy_field( 'samcp-gsc-redirect', __( 'Return address (redirect URI) to copy', 'site-agent-mcp-lite' ), Search_Console::redirect_uri(), __( 'Copy the return address', 'site-agent-mcp-lite' ) ); ?>
				</details>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<?php wp_nonce_field( 'samcp_services' ); ?>
					<input type="hidden" name="action" value="samcp_services">
					<div class="samcp-field">
						<label for="gsc_client_id"><?php esc_html_e( 'Client ID', 'site-agent-mcp-lite' ); ?></label>
						<input id="gsc_client_id" name="gsc_client_id" class="large-text code" type="text" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" value="<?php echo esc_attr( $client_id ); ?>"<?php echo $connected ? ' aria-describedby="gsc_client_id-help"' : ''; ?>>
						<?php if ( $connected ) : ?>
							<p class="description" id="gsc_client_id-help"><?php esc_html_e( 'Changing the client ID disconnects Search Console.', 'site-agent-mcp-lite' ); ?></p>
						<?php endif; ?>
					</div>
					<?php self::secret_field( 'gsc_client_secret', __( 'Client secret', 'site-agent-mcp-lite' ), $secret ); ?>
					<?php if ( ! $connected ) : ?>
						<p class="description"><?php esc_html_e( 'Save, then use Connect Search Console. Google may warn that the app is not verified: it is your own app, so you can continue through Advanced.', 'site-agent-mcp-lite' ); ?></p>
					<?php endif; ?>
					<p><button type="submit" class="button<?php echo $credentials && ! $connected ? '' : ' button-primary'; ?>"><?php esc_html_e( 'Save Search Console settings', 'site-agent-mcp-lite' ); ?></button></p>
				</form>
				<?php if ( $connected || $credentials ) : ?>
					<div class="samcp-actions">
						<?php if ( $connected ) : ?>
							<form method="post" action="<?php echo esc_url( $post ); ?>"<?php echo self::confirm_attributes( __( 'Disconnect Search Console?', 'site-agent-mcp-lite' ), __( 'Agents lose your real search data until you connect again. The access is also revoked at Google.', 'site-agent-mcp-lite' ), __( 'Disconnect Search Console', 'site-agent-mcp-lite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
								<?php wp_nonce_field( 'samcp_gsc_disconnect' ); ?>
								<input type="hidden" name="action" value="samcp_gsc_disconnect">
								<?php self::confirm_fallback( 'samcp-confirm-gsc', __( 'Confirm', 'site-agent-mcp-lite' ) ); ?>
								<button type="submit" class="button samcp-button-danger"><?php esc_html_e( 'Disconnect Search Console', 'site-agent-mcp-lite' ); ?></button>
							</form>
							<?php self::render_test_button(); ?>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( $post ); ?>">
								<?php wp_nonce_field( 'samcp_gsc_connect' ); ?>
								<input type="hidden" name="action" value="samcp_gsc_connect">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect Search Console', 'site-agent-mcp-lite' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="samcp-panel samcp-service" aria-labelledby="samcp-pagespeed-title">
				<div class="samcp-service-head">
					<h2 id="samcp-pagespeed-title"><?php esc_html_e( 'PageSpeed Insights and Chrome UX Report', 'site-agent-mcp-lite' ); ?></h2>
					<?php self::badge( $google_state[0], $google_state[1] ); ?>
				</div>
				<p class="samcp-service-purpose"><?php esc_html_e( 'Speed tests, and speed measured on real visitors. Tested URLs are sent to Google.', 'site-agent-mcp-lite' ); ?></p>
				<?php self::render_tests( $results['pagespeed'] ); ?>
				<details class="samcp-steps"<?php echo $first_time ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Setup steps (about 5 minutes)', 'site-agent-mcp-lite' ); ?></summary>
					<ol>
						<?php
						self::step( __( 'In the same Google Cloud project, enable the PageSpeed Insights API.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com', __( 'Enable it', 'site-agent-mcp-lite' ) );
						self::step( __( 'Enable the Chrome UX Report API too.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/apis/library/chromeuxreport.googleapis.com', __( 'Enable it', 'site-agent-mcp-lite' ) );
						self::step( __( 'Under Credentials, choose Create credentials, then API key.', 'site-agent-mcp-lite' ), 'https://console.cloud.google.com/apis/credentials', __( 'Open Credentials', 'site-agent-mcp-lite' ) );
						self::step( __( 'Under API restrictions, choose Restrict key and select those two APIs. A stolen key then cannot be used for anything else.', 'site-agent-mcp-lite' ) );
						self::step( __( 'Paste the key here.', 'site-agent-mcp-lite' ) );
						?>
					</ol>
				</details>
				<?php if ( $google_const ) : ?>
					<p class="description"><?php esc_html_e( 'Set in wp-config.php with SAMCP_GOOGLE_API_KEY, which takes precedence over this screen.', 'site-agent-mcp-lite' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>">
						<?php wp_nonce_field( 'samcp_services' ); ?>
						<input type="hidden" name="action" value="samcp_services">
						<?php self::secret_field( 'google_api_key', __( 'Google API key', 'site-agent-mcp-lite' ), $google ); ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Google API key', 'site-agent-mcp-lite' ); ?></button></p>
					</form>
				<?php endif; ?>
				<?php if ( $google ) : ?>
					<div class="samcp-actions"><?php self::render_test_button(); ?></div>
				<?php endif; ?>
			</section>

			<section class="samcp-panel samcp-service" aria-labelledby="samcp-gtmetrix-title">
				<div class="samcp-service-head">
					<h2 id="samcp-gtmetrix-title"><?php esc_html_e( 'GTmetrix', 'site-agent-mcp-lite' ); ?></h2>
					<?php self::badge( $gtm_state[0], $gtm_state[1] ); ?>
				</div>
				<p class="samcp-service-purpose"><?php esc_html_e( 'A second speed test. Tests spend your GTmetrix credits, and tested URLs are sent to GTmetrix.', 'site-agent-mcp-lite' ); ?></p>
				<?php self::render_tests( $results['gtmetrix'] ); ?>
				<details class="samcp-steps"<?php echo $first_time ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Setup steps (about 5 minutes)', 'site-agent-mcp-lite' ); ?></summary>
					<ol>
						<?php
						self::step( __( 'Log in to GTmetrix or sign up, and validate your email address. GTmetrix refuses API calls until you do.', 'site-agent-mcp-lite' ), 'https://gtmetrix.com/', __( 'Open GTmetrix', 'site-agent-mcp-lite' ) );
						self::step( __( 'Generate your API key in your account settings. New keys come with 5 trial credits.', 'site-agent-mcp-lite' ), 'https://gtmetrix.com/dashboard/account#us-api', __( 'Open account settings', 'site-agent-mcp-lite' ) );
						self::step( __( 'Paste the key here.', 'site-agent-mcp-lite' ) );
						?>
					</ol>
				</details>
				<?php if ( $gtm_const ) : ?>
					<p class="description"><?php esc_html_e( 'Set in wp-config.php with SAMCP_GTMETRIX_API_KEY, which takes precedence over this screen.', 'site-agent-mcp-lite' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>">
						<?php wp_nonce_field( 'samcp_services' ); ?>
						<input type="hidden" name="action" value="samcp_services">
						<?php self::secret_field( 'gtmetrix_api_key', __( 'GTmetrix API key', 'site-agent-mcp-lite' ), $gtmetrix ); ?>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save GTmetrix API key', 'site-agent-mcp-lite' ); ?></button></p>
					</form>
				<?php endif; ?>
				<?php if ( $gtmetrix ) : ?>
					<div class="samcp-actions"><?php self::render_test_button(); ?></div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- activity */

	/** Activity: every kept tool call, newest first, 25 per page, with a filter on failures. */
	private static function render_activity() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filter and paging only.
		$failed_only = isset( $_GET['failed'] ) && '1' === sanitize_key( wp_unslash( $_GET['failed'] ) );
		$paged       = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$all_count  = Store::audit_count( false );
		$fail_count = Store::audit_count( true );
		$total      = $failed_only ? $fail_count : $all_count;
		$pages      = max( 1, (int) ceil( $total / self::ACTIVITY_PER_PAGE ) );
		$paged      = min( max( 1, $paged ), $pages );
		$rows       = Store::audit_page( $failed_only, self::ACTIVITY_PER_PAGE, ( $paged - 1 ) * self::ACTIVITY_PER_PAGE );
		$args       = $failed_only ? array( 'failed' => '1' ) : array();
		?>
		<section class="samcp-panel" aria-labelledby="samcp-activity-title">
			<h2 id="samcp-activity-title"><?php esc_html_e( 'Agent activity', 'site-agent-mcp-lite' ); ?></h2>
			<div class="samcp-activity-bar">
				<ul class="samcp-filter" aria-label="<?php esc_attr_e( 'Filter the activity', 'site-agent-mcp-lite' ); ?>">
					<li><a href="<?php echo esc_url( self::url( 'activity' ) ); ?>" class="button<?php echo $failed_only ? '' : ' button-primary'; ?>"<?php echo $failed_only ? '' : ' aria-current="true"'; ?>>
						<?php /* translators: %d: number of calls. */ ?>
						<?php echo esc_html( sprintf( __( 'All (%d)', 'site-agent-mcp-lite' ), $all_count ) ); ?>
					</a></li>
					<li><a href="<?php echo esc_url( self::url( 'activity', array( 'failed' => '1' ) ) ); ?>" class="button<?php echo $failed_only ? ' button-primary' : ''; ?>"<?php echo $failed_only ? ' aria-current="true"' : ''; ?>>
						<?php /* translators: %d: number of calls. */ ?>
						<?php echo esc_html( sprintf( __( 'Failed only (%d)', 'site-agent-mcp-lite' ), $fail_count ) ); ?>
					</a></li>
				</ul>
				<p class="samcp-muted samcp-activity-note"><?php esc_html_e( 'Tool calls by AI apps, newest first.', 'site-agent-mcp-lite' ); ?></p>
				<?php self::render_pagination( $paged, $pages, $total, $args ); ?>
			</div>
			<?php
			if ( $failed_only ) {
				self::render_audit( $rows, __( 'Failed tool calls by AI apps, newest first', 'site-agent-mcp-lite' ), __( 'No failed calls.', 'site-agent-mcp-lite' ) );
			} else {
				self::render_audit( $rows, __( 'Tool calls by AI apps, newest first', 'site-agent-mcp-lite' ), __( 'No agent activity yet. Calls appear here once an app uses the site.', 'site-agent-mcp-lite' ) );
			}
			if ( count( $rows ) > 10 ) {
				self::render_pagination( $paged, $pages, $total, $args );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Page links of the activity table, in the WordPress list-table markup.
	 *
	 * @param int   $paged Current page.
	 * @param int   $pages Number of pages.
	 * @param int   $total Number of rows.
	 * @param array $args  Query arguments to keep, such as the filter.
	 */
	private static function render_pagination( $paged, $pages, $total, array $args ) {
		if ( $pages < 2 ) {
			return;
		}
		$links = array(
			'first-page' => array( 1, __( 'First page', 'site-agent-mcp-lite' ), '&laquo;', 1 === $paged ),
			'prev-page'  => array( $paged - 1, __( 'Previous page', 'site-agent-mcp-lite' ), '&lsaquo;', 1 === $paged ),
			'next-page'  => array( $paged + 1, __( 'Next page', 'site-agent-mcp-lite' ), '&rsaquo;', $pages === $paged ),
			'last-page'  => array( $pages, __( 'Last page', 'site-agent-mcp-lite' ), '&raquo;', $pages === $paged ),
		);
		?>
		<nav class="tablenav samcp-tablenav" aria-label="<?php esc_attr_e( 'Activity pages', 'site-agent-mcp-lite' ); ?>">
			<div class="tablenav-pages">
				<?php /* translators: %s: number of tool calls. */ ?>
				<span class="displaying-num"><?php echo esc_html( sprintf( _n( '%s call', '%s calls', $total, 'site-agent-mcp-lite' ), number_format_i18n( $total ) ) ); ?></span>
				<span class="pagination-links">
					<?php foreach ( $links as $class => $link ) : ?>
						<?php if ( 'next-page' === $class ) : ?>
							<?php /* translators: 1: current page, 2: number of pages. */ ?>
							<span class="paging-input"><span class="tablenav-paging-text"><?php echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'site-agent-mcp-lite' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) ); ?></span></span>
						<?php endif; ?>
						<?php if ( $link[3] ) : ?>
							<span class="tablenav-pages-navspan button disabled" aria-hidden="true"><?php echo $link[2]; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entity. ?></span>
						<?php else : ?>
							<a class="<?php echo esc_attr( $class ); ?> button" href="<?php echo esc_url( self::url( 'activity', $link[0] > 1 ? $args + array( 'paged' => $link[0] ) : $args ) ); ?>"><span class="screen-reader-text"><?php echo esc_html( $link[1] ); ?></span><span aria-hidden="true"><?php echo $link[2]; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed entity. ?></span></a>
						<?php endif; ?>
					<?php endforeach; ?>
				</span>
			</div>
		</nav>
		<?php
	}

	/**
	 * Render audit rows.
	 *
	 * @param array[] $rows    Rows.
	 * @param string  $caption Table caption.
	 * @param string  $empty   Text when there is nothing.
	 * @param bool    $compact One line per row, details cut short: for the overview.
	 */
	private static function render_audit( array $rows, $caption, $empty, $compact = false ) {
		if ( ! $rows ) {
			echo '<p>' . esc_html( $empty ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped samcp-table samcp-table-dense<?php echo $compact ? ' samcp-table-compact' : ''; ?>">
			<caption class="screen-reader-text"><?php echo esc_html( $caption ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'App', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Account', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Tool', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'site-agent-mcp-lite' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'site-agent-mcp-lite' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $user = get_userdata( (int) $row['user_id'] ); ?>
				<tr>
					<th scope="row" class="samcp-nowrap"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['created_at'] ) ); ?></th>
					<td><?php echo esc_html( $row['client_name'] ); ?></td>
					<td><?php echo esc_html( $user ? $user->user_login : '#' . $row['user_id'] ); ?></td>
					<td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
					<?php if ( $compact ) : ?>
						<td><span class="samcp-truncate"><?php echo esc_html( $row['summary'] ); ?></span></td>
					<?php else : ?>
						<td class="samcp-break"><?php echo esc_html( $row['summary'] ); ?></td>
					<?php endif; ?>
					<td><?php self::badge( $row['is_error'] ? 'error' : 'success', $row['is_error'] ? __( 'Failed', 'site-agent-mcp-lite' ) : __( 'OK', 'site-agent-mcp-lite' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------- settings */

	/**
	 * Lite edition: what Site Agent MCP Full adds, once on the Settings tab, until the
	 * administrator dismisses it. Information only: nothing in this edition is locked.
	 */
	private static function render_full_info() {
		if ( get_user_meta( get_current_user_id(), self::FULL_INFO_DISMISSED, true ) ) {
			return;
		}
		?>
		<section class="samcp-panel samcp-full-info" id="samcp-full-info" aria-labelledby="samcp-full-info-title">
			<h2 id="samcp-full-info-title"><?php esc_html_e( 'Site Agent MCP Full', 'site-agent-mcp-lite' ); ?></h2>
			<p><?php esc_html_e( 'This is Site Agent MCP Lite, the edition published on wordpress.org. Site Agent MCP Full, distributed from its own site, also offers:', 'site-agent-mcp-lite' ); ?></p>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Server-level tools for agents: site files, SQL, PHP and WP-CLI, offered on the consent screen and off unless you tick them.', 'site-agent-mcp-lite' ); ?></li>
				<li><?php esc_html_e( 'Restoring a backup from the Backups tab.', 'site-agent-mcp-lite' ); ?></li>
				<li><?php esc_html_e( 'Installing plugins and themes from a ZIP address.', 'site-agent-mcp-lite' ); ?></li>
			</ul>
			<p><?php echo self::external_link( SAMCP_FULL_URL, __( 'About Site Agent MCP Full', 'site-agent-mcp-lite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'samcp_dismiss_full' ); ?>
				<input type="hidden" name="action" value="samcp_dismiss_full">
				<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'site-agent-mcp-lite' ); ?></button>
			</form>
		</section>
		<?php
	}

	/** Settings and about. */
	private static function render_settings() {
		if ( 'lite' === SAMCP_EDITION ) {
			self::render_full_info();
		}
		?>
		<section class="samcp-panel" aria-labelledby="samcp-settings-title">
			<h2 id="samcp-settings-title"><?php esc_html_e( 'Settings', 'site-agent-mcp-lite' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'samcp_settings' ); ?>
				<input type="hidden" name="action" value="samcp_settings">
				<?php if ( Header_Check::has_block() ) : ?>
					<p class="description" id="samcp-header-block"><?php esc_html_e( 'Site Agent MCP added a block at the top of .htaccess so the Authorization header reaches WordPress.', 'site-agent-mcp-lite' ); ?></p>
				<?php endif; ?>
				<details class="samcp-advanced samcp-settings-advanced">
					<summary><?php esc_html_e( 'Advanced', 'site-agent-mcp-lite' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="samcp-redirects"><?php esc_html_e( 'Other allowed return addresses', 'site-agent-mcp-lite' ); ?></label></th>
							<td>
								<textarea id="samcp-redirects" name="extra_redirects" class="large-text code" rows="3" aria-describedby="samcp-redirects-help"><?php echo esc_textarea( (string) get_option( 'samcp_extra_redirects', '' ) ); ?></textarea>
								<p class="description" id="samcp-redirects-help"><?php esc_html_e( 'One per line, for AI apps this plugin does not know. Exact addresses only.', 'site-agent-mcp-lite' ); ?></p>
							</td>
						</tr>
					</table>
				</details>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'site-agent-mcp-lite' ); ?></button></p>
			</form>
		</section>

		<?php if ( Header_Check::has_block() && ( ! is_multisite() || is_super_admin() ) ) : ?>
			<section class="samcp-panel" aria-labelledby="samcp-unfix-title">
				<h2 id="samcp-unfix-title"><?php esc_html_e( 'Authorization header', 'site-agent-mcp-lite' ); ?></h2>
				<p><?php esc_html_e( 'Remove the block that Site Agent MCP put at the top of .htaccess, and its backup copy. AI apps stop working if your server needs it.', 'site-agent-mcp-lite' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'samcp_unfix_header' ); ?>
					<input type="hidden" name="action" value="samcp_unfix_header">
					<p><button type="submit" class="button"><?php esc_html_e( 'Remove the block from .htaccess', 'site-agent-mcp-lite' ); ?></button></p>
				</form>
			</section>
		<?php endif; ?>

		<section class="samcp-panel samcp-about" aria-labelledby="samcp-about-title">
			<h2 id="samcp-about-title"><?php esc_html_e( 'About', 'site-agent-mcp-lite' ); ?></h2>
			<?php /* translators: %s: version number. */ ?>
			<p><?php echo esc_html( sprintf( __( 'Site Agent MCP, version %s.', 'site-agent-mcp-lite' ), SAMCP_VERSION ) ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: author name, as a link. */
					esc_html__( 'Site Agent MCP is created by %s, AI operations architect, OVEANET.', 'site-agent-mcp-lite' ),
					self::external_link( 'https://rochetta.fr/', 'Laurent Rochetta' ) // phpcs:ignore WordPress.Security.EscapeOutput
				);
				?>
				<?php echo self::external_link( 'https://www.linkedin.com/in/laurentrochetta/', 'LinkedIn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> ·
				<?php echo self::external_link( 'https://github.com/lrochetta', 'GitHub' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
		</section>
		<?php
	}
}
