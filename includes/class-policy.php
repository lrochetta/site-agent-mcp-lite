<?php
/**
 * Who may connect agents, how far each connection reaches, and the rules that
 * hold on every request made by or for an agent.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Access levels per role and per user, and one request-scoped agent context.
 *
 * The context is active in two places: during an MCP request (set once the token
 * is validated) and during the loopback requests the admin bridge makes for a tool
 * (carried by the bridge's own login session, so the agent cannot strip it). While
 * it is active, the rules below apply to WordPress itself, through capabilities,
 * option writes and the upgrader, so no admin screen, REST route or form spelling
 * can get around them.
 */
class Policy {

	const LEVELS = array(
		'none'    => 0,
		'read'    => 1,
		'content' => 2,
		'full'    => 3,
	);

	const SCOPE_READ    = 'wp:read';
	const SCOPE_CONTENT = 'wp:content';

	/** How long new connections stay open after a person opens them. */
	const PAIRING_TTL = 900;

	/**
	 * Prefixes of this plugin's own options and transients. Agents never change them,
	 * whatever their access: settings, connections, backups, export links, caches.
	 */
	const OWN_PREFIXES = array( 'samcp_', '_transient_samcp_', '_transient_timeout_samcp_', '_site_transient_samcp_', '_site_transient_timeout_samcp_' );

	/** Options that decide which code runs. Changed only through extension_manage, which runs WordPress's own checks. */
	const CODE_OPTIONS = array( 'active_plugins', 'active_sitewide_plugins', 'uninstall_plugins', 'template', 'stylesheet', 'template_root', 'stylesheet_root', 'upload_path', 'upload_url_path' );

	/** Options that decide who can sign in and with which rights. The role table is added with the site prefix. */
	const ACCOUNT_OPTIONS = array( 'default_role', 'users_can_register', 'admin_email', 'new_admin_email', 'siteurl', 'home' );

	/** Capabilities that write code: file editors, uploaded packages, unfiltered markup. */
	const CODE_CAPS = array( 'edit_plugins', 'edit_themes', 'edit_files', 'upload_plugins', 'upload_themes', 'unfiltered_upload', 'unfiltered_html' );

	/** Capabilities that create accounts or change who has which rights. */
	const ACCOUNT_CAPS = array( 'create_users', 'promote_users', 'edit_users', 'delete_users', 'remove_users' );

	/** Capabilities that add, change or remove code from wordpress.org, when the site does not allow it. */
	const EXTENSION_CAPS = array( 'install_plugins', 'install_themes', 'update_plugins', 'update_themes', 'update_core', 'delete_plugins', 'delete_themes', 'switch_themes' );

	/** Objects whose edit_/delete_ capabilities are never content. */
	const NOT_CONTENT = array( 'user', 'users', 'plugin', 'plugins', 'theme', 'themes', 'theme_options', 'file', 'files', 'dashboard', 'site', 'sites', 'network', 'networks', 'css', 'options' );

	/**
	 * Context of the MCP request.
	 *
	 * @var array|null
	 */
	private static $direct = null;

	/**
	 * Context carried by a bridge session: null until looked up, false when none.
	 *
	 * @var array|false|null
	 */
	private static $bridge = null;

	/**
	 * Depth of trusted internal work (extension_manage), which may write code options.
	 *
	 * @var int
	 */
	private static $internal = 0;

	/**
	 * Depth of this plugin's own bookkeeping (caches, last audit time, backup folder).
	 *
	 * @var int
	 */
	private static $own = 0;

	/**
	 * Depth of writes whose option names come from the agent (options_update).
	 *
	 * @var int
	 */
	private static $agent_write = 0;

	/**
	 * Surface of the next bridge session: admin (wp-admin screens) or public (front-end pages).
	 *
	 * @var string
	 */
	private static $next_surface = 'admin';

	/** Register hooks. */
	public static function boot() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 999, 4 );
		add_filter( 'pre_update_option', array( __CLASS__, 'guard_option_update' ), 999, 3 );
		add_filter( 'pre_update_site_option_active_sitewide_plugins', array( __CLASS__, 'guard_sitewide_plugins' ), 999, 2 );
		add_action( 'add_option', array( __CLASS__, 'check_option' ), 0 );
		add_action( 'delete_option', array( __CLASS__, 'check_option' ), 0 );
		add_filter( 'upgrader_package_options', array( __CLASS__, 'guard_package' ), 999 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 999 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 0, 3 );
		add_action( 'delete_plugin', array( __CLASS__, 'guard_delete_plugin' ), 0 );
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'guard_user_data' ), 999, 4 );
		add_filter( 'rest_pre_insert_user', array( __CLASS__, 'guard_rest_user' ), 0, 2 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'guard_profile_form' ), 0, 3 );
		add_filter( 'send_password_change_email', array( __CLASS__, 'guard_password_mail' ), 0 );
		add_action( 'init', array( __CLASS__, 'guard_request' ), 0 );
	}

	// ------------------------------------------------------------------
	// Settings.
	// ------------------------------------------------------------------

	/**
	 * Whether agents can connect at all. The Lite edition starts switched off.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( 'samcp_enabled', 'full' === SAMCP_EDITION ? 1 : 0 );
	}

	/**
	 * Whether agents may install, update, switch or remove plugins, themes and core.
	 *
	 * @return bool
	 */
	public static function extensions_allowed() {
		return (bool) get_option( 'samcp_allow_extensions', 'full' === SAMCP_EDITION ? 1 : 0 );
	}

	/**
	 * Whether a mail goes out for each new connection.
	 *
	 * @return bool
	 */
	public static function notify_enabled() {
		return (bool) get_option( 'samcp_notify', 1 );
	}

	/**
	 * Access levels per role and per user.
	 *
	 * @return array{roles: array<string,string>, users: array<int,string>}
	 */
	public static function settings() {
		$stored = get_option( 'samcp_access' );
		$stored = is_array( $stored ) ? $stored : array();
		$roles  = isset( $stored['roles'] ) && is_array( $stored['roles'] ) ? $stored['roles'] : self::default_roles();
		$users  = isset( $stored['users'] ) && is_array( $stored['users'] ) ? $stored['users'] : array();
		return array(
			'roles' => array_filter( $roles, array( __CLASS__, 'is_level' ) ),
			'users' => array_filter( $users, array( __CLASS__, 'is_level' ) ),
		);
	}

	/**
	 * Default levels: administrators get everything they can do, editors their content, others nothing.
	 *
	 * @return array<string,string>
	 */
	public static function default_roles() {
		return array(
			'administrator' => 'full',
			'editor'        => 'content',
		);
	}

	/**
	 * Save access levels.
	 *
	 * @param array $roles Role => level.
	 * @param array $users User id => level.
	 */
	public static function save_settings( array $roles, array $users ) {
		$clean_roles = array();
		foreach ( $roles as $role => $level ) {
			if ( is_string( $role ) && self::is_level( $level ) && 'none' !== $level ) {
				$clean_roles[ sanitize_key( $role ) ] = $level;
			}
		}
		$clean_users = array();
		foreach ( $users as $user_id => $level ) {
			if ( (int) $user_id > 0 && self::is_level( $level ) && get_userdata( (int) $user_id ) ) {
				$clean_users[ (int) $user_id ] = $level;
			}
		}
		update_option(
			'samcp_access',
			array(
				'roles' => $clean_roles,
				'users' => $clean_users,
			),
			false
		);
	}

	/**
	 * Name of a level, for people.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function level_label( $level ) {
		$labels = array(
			'none'    => __( 'No access', 'site-agent-mcp-lite' ),
			'read'    => __( 'Read only', 'site-agent-mcp-lite' ),
			'content' => __( 'Content', 'site-agent-mcp-lite' ),
			'full'    => __( 'Everything the account can do', 'site-agent-mcp-lite' ),
		);
		return isset( $labels[ $level ] ) ? $labels[ $level ] : $labels['none'];
	}

	/**
	 * What a level allows, for people.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function level_help( $level ) {
		$help = array(
			'none'    => __( 'Cannot connect AI apps.', 'site-agent-mcp-lite' ),
			'read'    => __( 'Looks at content, settings and reports. Changes nothing.', 'site-agent-mcp-lite' ),
			'content' => __( 'Writes and edits posts, pages, media, categories, tags and comments, and their SEO fields. No settings, plugins, themes or accounts.', 'site-agent-mcp-lite' ),
			'full'    => __( 'Also settings, plugins and themes, within the account\'s rights. Never code editing, uploaded packages, or changes to accounts and roles.', 'site-agent-mcp-lite' ),
		);
		return isset( $help[ $level ] ) ? $help[ $level ] : $help['none'];
	}

	/**
	 * Whether a value names a level.
	 *
	 * @param mixed $level Value.
	 * @return bool
	 */
	public static function is_level( $level ) {
		return is_string( $level ) && isset( self::LEVELS[ $level ] );
	}

	/**
	 * Rank of a level, for comparisons.
	 *
	 * @param string $level Level.
	 * @return int
	 */
	public static function rank( $level ) {
		return isset( self::LEVELS[ $level ] ) ? self::LEVELS[ $level ] : 0;
	}

	/**
	 * Lower of two levels.
	 *
	 * @param string $a Level.
	 * @param string $b Level.
	 * @return string
	 */
	public static function lower( $a, $b ) {
		return self::rank( $a ) <= self::rank( $b ) ? $a : $b;
	}

	/**
	 * Highest level a user may give a connection: their own setting first, then their best role.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function user_max_level( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return 'none';
		}
		$settings = self::settings();
		if ( isset( $settings['users'][ $user->ID ] ) ) {
			$level = $settings['users'][ $user->ID ];
		} else {
			$level = 'none';
			foreach ( (array) $user->roles as $role ) {
				$candidate = isset( $settings['roles'][ $role ] ) ? $settings['roles'][ $role ] : 'none';
				if ( self::rank( $candidate ) > self::rank( $level ) ) {
					$level = $candidate;
				}
			}
			if ( is_multisite() && is_super_admin( $user->ID ) && isset( $settings['roles']['administrator'] ) ) {
				$level = $settings['roles']['administrator'];
			}
		}
		$level = apply_filters( 'samcp_user_max_level', $level, $user->ID );
		return self::is_level( $level ) ? $level : 'none';
	}

	/**
	 * Access level a scope string grants. Tokens from before levels existed carry wp:full.
	 *
	 * @param string $scope Space-separated scopes.
	 * @return string
	 */
	public static function scope_level( $scope ) {
		$scopes = explode( ' ', (string) $scope );
		if ( in_array( self::SCOPE_READ, $scopes, true ) ) {
			return 'read';
		}
		if ( in_array( self::SCOPE_CONTENT, $scopes, true ) ) {
			return 'content';
		}
		return in_array( OAuth::SCOPE_FULL, $scopes, true ) ? 'full' : 'none';
	}

	/**
	 * Scope string for a level.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function level_scope( $level ) {
		if ( 'read' === $level ) {
			return self::SCOPE_READ;
		}
		return 'content' === $level ? self::SCOPE_CONTENT : OAuth::SCOPE_FULL;
	}

	/**
	 * Level a token has right now: what the person granted, never above what the
	 * settings allow today, so a settings change reaches existing connections at once.
	 *
	 * @param array $token Token row.
	 * @return string
	 */
	public static function token_level( array $token ) {
		return self::lower( self::scope_level( $token['scope'] ), self::user_max_level( (int) $token['user_id'] ) );
	}

	/**
	 * Open new-connection windows: user id => end time. Id 0 is a site-wide window, which
	 * only WP-CLI or a test bench sets (the screens open one per person).
	 *
	 * @return array<int,int>
	 */
	private static function pairing_windows() {
		$windows = get_option( 'samcp_pairing', array() );
		$windows = is_array( $windows ) ? array_map( 'intval', $windows ) : array();
		$site    = (int) get_option( 'samcp_pairing_until', 0 );
		if ( $site ) {
			$windows[0] = max( $site, isset( $windows[0] ) ? $windows[0] : 0 );
		}
		return $windows;
	}

	/**
	 * Whether new connections can be made now: for this person, or for anyone when no
	 * person is known yet (dynamic registration is anonymous).
	 *
	 * @param int $user_id Person approving, or 0 for any.
	 * @return bool
	 */
	public static function pairing_open( $user_id = 0 ) {
		return self::pairing_left( $user_id ) > 0;
	}

	/**
	 * Seconds left before new connections close.
	 *
	 * @param int $user_id Person, or 0 for any.
	 * @return int
	 */
	public static function pairing_left( $user_id = 0 ) {
		$windows = self::pairing_windows();
		$until   = isset( $windows[0] ) ? $windows[0] : 0;
		if ( $user_id ) {
			$until = max( $until, isset( $windows[ $user_id ] ) ? $windows[ $user_id ] : 0 );
		} elseif ( $windows ) {
			$until = max( $windows );
		}
		return max( 0, $until - time() );
	}

	/**
	 * Open new connections for one person, for a while. Called only from that person's request in wp-admin.
	 *
	 * @param int $user_id Person.
	 */
	public static function open_pairing( $user_id ) {
		$windows = get_option( 'samcp_pairing', array() );
		$windows = is_array( $windows ) ? $windows : array();
		foreach ( $windows as $id => $until ) {
			if ( (int) $until <= time() ) {
				unset( $windows[ $id ] );
			}
		}
		$windows[ (int) $user_id ] = time() + self::PAIRING_TTL;
		self::own_write(
			static function () use ( $windows ) {
				update_option( 'samcp_pairing', $windows, false );
			}
		);
	}

	/**
	 * Close new connections for one person, and the site-wide window if any. Also called once a connection is approved.
	 *
	 * @param int $user_id Person.
	 */
	public static function close_pairing( $user_id, $site_wide = false ) {
		$windows = get_option( 'samcp_pairing', array() );
		$windows = is_array( $windows ) ? $windows : array();
		unset( $windows[ (int) $user_id ] );
		self::own_write(
			static function () use ( $windows, $site_wide ) {
				update_option( 'samcp_pairing', $windows, false );
				// "Close now" also closes a site-wide window set by WP-CLI; an approval only closes the person's own.
				if ( $site_wide ) {
					update_option( 'samcp_pairing_until', 0, false );
				}
			}
		);
	}

	// ------------------------------------------------------------------
	// Agent context.
	// ------------------------------------------------------------------

	/**
	 * Start the agent context for an MCP request.
	 *
	 * @param array $token Validated token row.
	 */
	public static function enter( array $token ) {
		self::$direct = self::context_for( $token );
	}

	/** End the agent context of an MCP request. */
	public static function leave() {
		self::$direct = null;
	}

	/**
	 * Context values for a token.
	 *
	 * @param array $token Token row.
	 * @return array
	 */
	public static function context_for( array $token ) {
		$power = in_array( OAuth::SCOPE_POWER, explode( ' ', (string) $token['scope'] ), true ) && OAuth::power_tools_enabled();
		$level = self::token_level( $token );
		return array(
			'user_id' => (int) $token['user_id'],
			'level'   => $level,
			// Server-level access only ever comes with the full level.
			'power'   => $power && 'full' === $level,
			'family'  => (string) $token['family'],
		);
	}

	/**
	 * The active agent context, if any.
	 *
	 * @return array|null
	 */
	public static function current() {
		if ( self::$direct ) {
			return self::$direct;
		}
		$bridge = self::bridge();
		return $bridge ? $bridge : null;
	}

	/**
	 * Whether this request comes from the admin bridge of an agent.
	 *
	 * @return bool
	 */
	public static function in_bridge() {
		return (bool) self::bridge();
	}

	/**
	 * Whether a context is held to the strict rules: always in Lite, and in Full
	 * unless the connection has server-level access.
	 *
	 * @param array $context Context.
	 * @return bool
	 */
	public static function strict( array $context ) {
		return 'full' !== SAMCP_EDITION || empty( $context['power'] );
	}

	/**
	 * Mark a session created by the admin bridge with the agent context.
	 *
	 * @param array $info Session information.
	 * @return array
	 */
	public static function tag_session( array $info ) {
		$context = self::current();
		$context = $context ? $context : array(
			'user_id' => get_current_user_id(),
			'level'   => 'read',
			'power'   => false,
			'family'  => '',
		);
		$context['surface']  = self::$next_surface;
		$info['samcp_agent'] = $context;
		return $info;
	}

	/**
	 * Set the surface of the next bridge session.
	 *
	 * @param string $surface admin or public.
	 */
	public static function next_surface( $surface ) {
		self::$next_surface = 'public' === $surface ? 'public' : 'admin';
	}

	/**
	 * Context carried by the login session of this request, when the admin bridge made it.
	 *
	 * @return array|false
	 */
	private static function bridge() {
		if ( null !== self::$bridge ) {
			return self::$bridge;
		}
		// Before pluggable functions load, nothing can be known yet: answer without caching.
		if ( ! function_exists( 'wp_parse_auth_cookie' ) || ! defined( 'LOGGED_IN_COOKIE' ) ) {
			return false;
		}
		self::$bridge = false;
		if ( empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return false;
		}
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );
		if ( ! $cookie || empty( $cookie['token'] ) || empty( $cookie['username'] ) ) {
			return false;
		}
		$user = get_user_by( 'login', $cookie['username'] );
		if ( ! $user ) {
			return false;
		}
		$session = \WP_Session_Tokens::get_instance( $user->ID )->get( $cookie['token'] );
		if ( is_array( $session ) && ! empty( $session['samcp_agent'] ) && is_array( $session['samcp_agent'] ) ) {
			self::$bridge = $session['samcp_agent'] + array(
				'user_id' => $user->ID,
				'level'   => 'read',
				'power'   => false,
				'family'  => '',
				'surface' => 'admin',
			);
		}
		return self::$bridge;
	}

	/**
	 * Run trusted internal work that may write code options (extension_manage).
	 *
	 * @param callable $work Work.
	 * @return mixed
	 */
	public static function internal( callable $work ) {
		++self::$internal;
		try {
			return $work();
		} finally {
			--self::$internal;
		}
	}

	/**
	 * Run this plugin's own bookkeeping, which may write its own options during an agent request.
	 *
	 * @param callable $work Work.
	 * @return mixed
	 */
	public static function agent_write( callable $work ) {
		++self::$agent_write;
		try {
			return $work();
		} finally {
			--self::$agent_write;
		}
	}

	/**
	 * Run this plugin's own bookkeeping, which may write its own options during an agent request.
	 *
	 * @param callable $work Work.
	 * @return mixed
	 */
	public static function own_write( callable $work ) {
		++self::$own;
		try {
			return $work();
		} finally {
			--self::$own;
		}
	}

	/**
	 * An option name as the database compares it: case, accents and trailing spaces do not count.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public static function normalise_option( $name ) {
		return strtolower( remove_accents( rtrim( (string) $name ) ) );
	}

	/**
	 * Whether an option or transient belongs to this plugin.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_own_option( $name ) {
		$name = self::normalise_option( $name );
		foreach ( self::OWN_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stop a refused action: a tool error during an MCP request, a 403 page in a bridge request.
	 *
	 * @param string $message English message, read by the agent.
	 * @throws Tool_Error During an MCP request.
	 */
	public static function refuse( $message ) {
		if ( self::$direct ) {
			throw new Tool_Error( esc_html( $message ) );
		}
		wp_die( esc_html( $message ), 'Refused', array( 'response' => 403 ) );
	}

	// ------------------------------------------------------------------
	// Rules.
	// ------------------------------------------------------------------

	/**
	 * Capability rules for the agent's user while the context is active.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability checked.
	 * @param int      $user_id User id.
	 * @param array    $args    Arguments.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$context = self::current();
		if ( ! $context || (int) $user_id !== (int) $context['user_id'] ) {
			return $caps;
		}
		// The agent never switches off the plugin that connects it, except through extension_manage with force.
		if ( 'deactivate_plugin' === $cap && isset( $args[0] ) && plugin_basename( SAMCP_FILE ) === $args[0] && ! self::$internal ) {
			return array( 'do_not_allow' );
		}
		$deny = array();
		if ( self::strict( $context ) ) {
			$deny = array_merge( self::CODE_CAPS, self::ACCOUNT_CAPS );
		}
		if ( ! self::extensions_allowed() ) {
			$deny = array_merge( $deny, self::EXTENSION_CAPS );
		}
		// Additional CSS maps to unfiltered_html in core, but CSS is not script: the CSS tool refuses markup itself.
		if ( 'edit_css' === $cap ) {
			$deny = array_diff( $deny, array( 'unfiltered_html' ) );
		}
		foreach ( array_merge( array( $cap ), (array) $caps ) as $needed ) {
			if ( in_array( $needed, $deny, true ) ) {
				return array( 'do_not_allow' );
			}
		}
		if ( 'content' === $context['level'] ) {
			foreach ( (array) $caps as $needed ) {
				if ( ! self::is_content_cap( $needed ) ) {
					return array( 'do_not_allow' );
				}
			}
		}
		return $caps;
	}

	/**
	 * Whether a primitive capability is about content: posts, pages, media, terms, comments.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function is_content_cap( $cap ) {
		if ( in_array( $cap, array( 'exist', 'read', 'upload_files', 'manage_categories', 'moderate_comments', 'edit_comment', 'list_users' ), true ) ) {
			return true;
		}
		if ( preg_match( '/^(assign|edit|manage|delete)_[a-z0-9_]*terms?$/', $cap ) ) {
			return true;
		}
		if ( preg_match( '/^(edit|delete|publish|read)_(others_|published_|private_)?([a-z0-9_]+)$/', $cap, $m ) ) {
			return ! in_array( $m[3], self::NOT_CONTENT, true );
		}
		return false;
	}

	/**
	 * Whether an option is out of the agent's reach in this context.
	 *
	 * @param string $name    Option name.
	 * @param array  $context Context.
	 * @return bool
	 */
	public static function is_protected_option( $name, array $context ) {
		global $wpdb;
		$name = self::normalise_option( $name );
		// Own options: refused when the name comes from the agent (options_update) or from a screen the
		// bridge opened (options.php can post any name); the plugin's own code writes its caches freely.
		if ( self::is_own_option( $name ) && ! self::$own && ( self::$agent_write || self::in_bridge() ) ) {
			return true;
		}
		if ( in_array( $name, self::CODE_OPTIONS, true ) ) {
			return true;
		}
		if ( self::strict( $context ) ) {
			return in_array( $name, self::ACCOUNT_OPTIONS, true ) || self::normalise_option( $wpdb->prefix . 'user_roles' ) === $name || self::normalise_option( $wpdb->base_prefix . 'user_roles' ) === $name;
		}
		return false;
	}

	/**
	 * Refuse a write to a protected option.
	 *
	 * @param string $option Option name.
	 */
	public static function check_option( $option ) {
		if ( self::$internal ) {
			return;
		}
		$context = self::current();
		if ( $context && self::is_protected_option( (string) $option, $context ) ) {
			self::refuse( 'This connection cannot change the option "' . $option . '". Plugins and themes are switched with extension_manage; accounts, roles, the site address and the settings of Site Agent MCP are changed by a person in wp-admin.' );
		}
	}

	/**
	 * Filter for update_option.
	 *
	 * @param mixed  $value  New value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function guard_option_update( $value, $option, $old_value = null ) {
		// Saving a form writes every field: an unchanged value is not a change.
		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			return $value;
		}
		// A protected setting on a screen an agent saves unchanged: an unticked box comes as null
		// over a stored "0", new_admin_email as the current address. Both keep their stored value
		// instead of being refused, since nothing changes.
		$context = self::$internal ? null : self::current();
		if ( $context && self::is_protected_option( (string) $option, $context ) && ( self::both_off( $value, $old_value ) || self::is_current_admin_email( $option, $value ) ) ) {
			return $old_value;
		}
		self::check_option( $option );
		return $value;
	}

	/**
	 * Whether two option values are both an empty or "off" scalar (null, false, "", "0", 0).
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Stored value.
	 * @return bool
	 */
	private static function both_off( $value, $old_value ) {
		foreach ( array( $value, $old_value ) as $one ) {
			if ( ! ( null === $one || is_scalar( $one ) ) || ! empty( $one ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a write is General Settings posting the current address as new_admin_email.
	 * Core keeps that field only as a pending change request and ignores it when it equals
	 * admin_email.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return bool
	 */
	private static function is_current_admin_email( $option, $value ) {
		return 'new_admin_email' === self::normalise_option( (string) $option ) && is_string( $value ) && get_option( 'admin_email' ) === $value;
	}

	/**
	 * Filter for the network's active plugins.
	 *
	 * @param mixed $value New value.
	 * @return mixed
	 */
	public static function guard_sitewide_plugins( $value ) {
		self::check_option( 'active_sitewide_plugins' );
		return $value;
	}

	/**
	 * Installs made for an agent come from wordpress.org, unless the connection has server-level access.
	 *
	 * @param array $options Upgrader options.
	 * @return array
	 */
	public static function guard_package( $options ) {
		$context = self::current();
		if ( ! $context || ! is_array( $options ) ) {
			return $options;
		}
		$action = isset( $options['hook_extra']['action'] ) ? $options['hook_extra']['action'] : '';
		if ( 'install' !== $action || ! self::strict( $context ) ) {
			return $options;
		}
		$package = isset( $options['package'] ) ? (string) $options['package'] : '';
		$scheme  = strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) );
		$host    = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
		if ( 'https' !== $scheme || 'downloads.wordpress.org' !== $host ) {
			self::refuse( 'This connection installs plugins and themes from wordpress.org only, by slug.' );
		}
		return $options;
	}

	/**
	 * No application passwords are created or used while an agent acts.
	 *
	 * @param bool $available Whether they are available.
	 * @return bool
	 */
	public static function application_passwords( $available ) {
		return self::current() ? false : $available;
	}

	/**
	 * REST requests made for an agent never reach this plugin's own routes.
	 *
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_rest( $result, $server, $request ) {
		if ( null !== $result || ! self::current() || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		$route = strtolower( rawurldecode( (string) $request->get_route() ) );
		// The MCP request itself is the one dispatch of its own route; any REST call a tool makes after it is refused.
		$own_request = '/' . MCP_Server::NAMESPACE . MCP_Server::ROUTE === untrailingslashit( $route ) && ! MCP_Server::is_dispatching();
		if ( 0 === strpos( $route, '/' . MCP_Server::NAMESPACE ) && ! $own_request ) {
			return new \WP_Error( 'samcp_refused', 'Site Agent MCP routes are not available to agents.', array( 'status' => 403 ) );
		}
		return $result;
	}

	/**
	 * The agent never deletes the plugin that connects it, except through extension_manage with force.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public static function guard_delete_plugin( $plugin_file ) {
		if ( ! self::$internal && self::current() && dirname( plugin_basename( SAMCP_FILE ) ) === dirname( (string) $plugin_file ) ) {
			self::refuse( 'This connection cannot delete Site Agent MCP.' );
		}
	}

	/**
	 * An agent never changes how an account signs in: its password, e-mail or login.
	 * WordPress lets everyone edit their own profile with no capability at all, so this
	 * is checked on the data, whatever the screen or route, and even with server-level access.
	 *
	 * @param array $data     User data about to be saved.
	 * @param bool  $update   Whether an existing user is updated.
	 * @param int   $user_id  User id.
	 * @param array $userdata Raw data passed to wp_insert_user.
	 * @return array
	 */
	public static function guard_user_data( $data, $update = false, $user_id = 0, $userdata = array() ) {
		unset( $userdata );
		if ( ! $update || ! self::current() ) {
			return $data;
		}
		$old = get_userdata( (int) $user_id );
		if ( ! $old ) {
			return $data;
		}
		$changed = ( isset( $data['user_pass'] ) && $data['user_pass'] !== $old->user_pass )
			|| ( isset( $data['user_email'] ) && strtolower( $data['user_email'] ) !== strtolower( $old->user_email ) )
			|| ( isset( $data['user_login'] ) && $data['user_login'] !== $old->user_login );
		if ( $changed ) {
			self::refuse( 'This connection cannot change the password, e-mail or login of an account. A person does that in WordPress.' );
		}
		return $data;
	}

	/**
	 * REST user updates made for an agent never carry a password, e-mail or login change.
	 *
	 * @param \stdClass        $prepared User data prepared by the REST controller.
	 * @param \WP_REST_Request $request  Request.
	 * @return \stdClass
	 */
	public static function guard_rest_user( $prepared, $request ) {
		if ( ! self::current() || ! $request instanceof \WP_REST_Request ) {
			return $prepared;
		}
		$old = isset( $prepared->ID ) ? get_userdata( (int) $prepared->ID ) : false;
		if ( ! $old ) {
			return $prepared;
		}
		$changed = null !== $request['password'] && '' !== $request['password']
			|| ( null !== $request['email'] && strtolower( (string) $request['email'] ) !== strtolower( $old->user_email ) )
			|| ( null !== $request['username'] && (string) $request['username'] !== $old->user_login );
		if ( $changed ) {
			self::refuse( 'This connection cannot change the password, e-mail or login of an account. A person does that in WordPress.' );
		}
		return $prepared;
	}

	/**
	 * The profile form, opened through the admin bridge, never changes a password or e-mail.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param bool      $update Whether a user is updated.
	 * @param \stdClass $user   User data from the form.
	 */
	public static function guard_profile_form( $errors, $update, $user ) {
		if ( ! $update || ! self::current() || ! isset( $user->ID ) ) {
			return;
		}
		$old = get_userdata( (int) $user->ID );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core checked the profile nonce before this action.
		$new_pass = ! empty( $_POST['pass1'] );
		if ( $old && ( $new_pass || ( isset( $user->user_email ) && strtolower( $user->user_email ) !== strtolower( $old->user_email ) ) ) ) {
			$errors->add( 'samcp_credentials', 'This connection cannot change the password or e-mail of an account. A person does that in WordPress.' );
		}
	}

	/**
	 * Last door: if a password change ever reached this point for an agent, stop before the mail and the save.
	 *
	 * @param bool $send Whether to send the mail.
	 * @return bool
	 */
	public static function guard_password_mail( $send ) {
		if ( self::current() ) {
			self::refuse( 'This connection cannot change the password of an account. A person does that in WordPress.' );
		}
		return $send;
	}

	/**
	 * Requests made by the admin bridge never reach this plugin's own pages, actions
	 * and OAuth endpoints: consent, settings and connections belong to people.
	 */
	public static function guard_request() {
		if ( ! self::in_bridge() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = strtolower( rawurldecode( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) ) );
		// phpcs:disable WordPress.Security.NonceVerification -- routing only, nothing is changed.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? strtolower( sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) ) : '';
		$page   = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) : '';
		$rest   = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? strtolower( rawurldecode( wp_unslash( $_GET['rest_route'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$page    = strtolower( rawurldecode( $page ) );
		$refused = false !== strpos( $path, '/samcp/' )
			|| false !== strpos( $path, '/samcp/v1' )
			|| 0 === strpos( $rest, '/samcp/' )
			|| 0 === strpos( $action, 'samcp_' )
			|| false !== strpos( $page, Admin_Page::SLUG )
			|| false !== strpos( $path, 'wp-login.php' );
		if ( $refused ) {
			wp_die( 'Site Agent MCP pages, actions and sign-in are for people, not agents.', 'Refused', array( 'response' => 403 ) );
		}
		// A session opened to read public pages never reaches wp-admin, AJAX, REST or XML-RPC, whatever redirect led there.
		$context = self::bridge();
		if ( isset( $context['surface'] ) && 'public' === $context['surface'] ) {
			$elsewhere = is_admin() || wp_doing_ajax() || '' !== $rest || false !== strpos( $path, '/' . rest_get_url_prefix() . '/' ) || false !== strpos( $path, 'xmlrpc.php' );
			if ( $elsewhere ) {
				wp_die( 'This connection reads public pages only here.', 'Refused', array( 'response' => 403 ) );
			}
		}
	}
}
