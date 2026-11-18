<?php
/**
 * OAuth 2.1 authorization server and discovery documents.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Serves RFC 9728 and RFC 8414 metadata, authorization with PKCE, tokens,
 * client registration (CIMD and RFC 7591) and revocation (RFC 7009).
 */
class OAuth {

	const SCOPE_FULL  = 'wp:full';
	const SCOPE_POWER = 'wp:power';

	/** Known client callbacks. See docs/research/2026-11-03-foundations.md. */
	const REDIRECT_PATTERNS = array(
		'#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/[^?\#]*)?$#',
		'#^https://claude\.(ai|com)/api/mcp/auth_callback$#',
		'#^https://chatgpt\.com/connector_platform_oauth_redirect$#',
		'#^https://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]+$#',
		'#^https://antigravity\.google/oauth-callback$#',
		'#^https://vertexaisearch\.cloud\.google\.com/oauth-redirect$#',
		'#^https://oauth-redirect(-sandbox|-test)?\.googleusercontent\.com/(r|a)/.+$#',
	);

	/** Register hooks. */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'route' ), 1 );
	}

	/**
	 * URL of the MCP endpoint, which is also the token audience.
	 *
	 * @return string
	 */
	public static function resource() {
		return rest_url( MCP_Server::NAMESPACE . MCP_Server::ROUTE );
	}

	/**
	 * Authorization server identifier.
	 *
	 * @return string
	 */
	public static function issuer() {
		return (string) apply_filters( 'samcp_issuer', untrailingslashit( home_url() ) );
	}

	/**
	 * URL of an OAuth endpoint.
	 *
	 * @param string $name authorize, token, register or revoke.
	 * @return string
	 */
	public static function endpoint( $name ) {
		return home_url( '/samcp/' . $name );
	}

	/**
	 * URL of the protected resource metadata.
	 *
	 * @return string
	 */
	public static function resource_metadata_url() {
		return self::issuer() . '/.well-known/oauth-protected-resource' . (string) wp_parse_url( self::resource(), PHP_URL_PATH );
	}

	/** Answer OAuth and discovery requests before WordPress builds a page. */
	public static function route() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '/' . ltrim( $path, '/' );

		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::cors();
			self::json( self::protected_resource_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $path, '/.well-known/openid-configuration' ) ) {
			self::cors();
			self::json( self::server_metadata() );
		}

		$routes = array(
			'/samcp/authorize' => 'authorize',
			'/samcp/token'     => 'token',
			'/samcp/register'  => 'register',
			'/samcp/revoke'    => 'revoke',
		);
		$key    = untrailingslashit( $path );
		if ( ! isset( $routes[ $key ] ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		$handler = $routes[ $key ];
		if ( 'authorize' !== $handler ) {
			self::cors();
		}
		if ( ! self::transport_is_secure() ) {
			self::oauth_error( 'invalid_request', 'HTTPS is required.', 400 );
		}
		self::$handler();
	}

	/**
	 * Whether the current request meets the HTTPS requirement.
	 *
	 * @return bool
	 */
	public static function transport_is_secure() {
		return is_ssl() || in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/** Allow browser-based clients. These endpoints never read cookies cross-origin with credentials. */
	private static function cors() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}
	}

	/**
	 * Send JSON and stop.
	 *
	 * @param array $data   Body.
	 * @param int   $status HTTP status.
	 */
	private static function json( array $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Send an OAuth error and stop.
	 *
	 * @param string $code        Error code.
	 * @param string $description Description.
	 * @param int    $status      HTTP status.
	 */
	private static function oauth_error( $code, $description, $status = 400 ) {
		self::json(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status
		);
	}

	/**
	 * Scopes this edition can grant. The Lite edition has no server-level scope.
	 *
	 * @return string[]
	 */
	public static function scopes_supported() {
		$scopes = array( Policy::SCOPE_READ, Policy::SCOPE_CONTENT, self::SCOPE_FULL );
		return 'full' === SAMCP_EDITION ? array_merge( $scopes, array( self::SCOPE_POWER ) ) : $scopes;
	}

	/**
	 * RFC 9728 document.
	 *
	 * @return array
	 */
	public static function protected_resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::scopes_supported(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	/**
	 * RFC 8414 document.
	 *
	 * @return array
	 */
	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'registration_endpoint'                 => self::endpoint( 'register' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array_merge( self::scopes_supported(), array( 'offline_access' ) ),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	/**
	 * Whether a redirect URI is acceptable for automatic registration.
	 *
	 * @param string $uri Redirect URI.
	 * @return bool
	 */
	public static function redirect_is_known( $uri ) {
		foreach ( self::REDIRECT_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $uri ) ) {
				return true;
			}
		}
		$extra = array_filter( array_map( 'trim', explode( "\n", (string) get_option( 'samcp_extra_redirects', '' ) ) ) );
		return (bool) apply_filters( 'samcp_redirect_is_known', in_array( $uri, $extra, true ), $uri );
	}

	/**
	 * Whether a URI is on the loopback interface.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	private static function is_loopback( $uri ) {
		return (bool) preg_match( '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#', $uri );
	}

	/**
	 * Compare a requested redirect with a client's list. Only loopback ignores the port (RFC 8252).
	 *
	 * @param string   $uri        Requested.
	 * @param string[] $registered Registered.
	 * @return bool
	 */
	private static function redirect_matches( $uri, array $registered ) {
		if ( in_array( $uri, $registered, true ) ) {
			return true;
		}
		if ( ! self::is_loopback( $uri ) ) {
			return false;
		}
		$strip = static function ( $value ) {
			return preg_replace( '#^(http://[^/:]+|http://\[::1\]):\d+#', '$1', $value );
		};
		foreach ( $registered as $candidate ) {
			if ( self::is_loopback( $candidate ) && $strip( $candidate ) === $strip( $uri ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a client from its id: a metadata document URL or a registered id.
	 *
	 * @param string $client_id Client id.
	 * @return array|null name, redirect_uris, secret_hash, source.
	 */
	private static function resolve_client( $client_id ) {
		if ( '' === $client_id || strlen( $client_id ) > 191 ) {
			return null;
		}
		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::fetch_metadata_document( $client_id );
		}
		$row = Store::get_client( $client_id );
		if ( ! $row ) {
			return null;
		}
		return array(
			'name'          => $row['client_name'],
			'redirect_uris' => $row['redirect_uris'],
			'secret_hash'   => $row['client_secret_hash'],
			'source'        => $row['source'],
		);
	}

	/**
	 * Fetch and validate a Client ID Metadata Document.
	 *
	 * @param string $url Document URL.
	 * @return array|null
	 */
	private static function fetch_metadata_document( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['fragment'] ) || isset( $parts['user'] ) || false !== strpos( $parts['path'], '/.' ) ) {
			return null;
		}
		$key    = 'samcp_cimd_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// wp_safe_remote_get refuses private and loopback addresses, which closes the SSRF path.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 10240,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || ! isset( $doc['client_id'], $doc['client_name'], $doc['redirect_uris'] ) || $doc['client_id'] !== $url || ! is_array( $doc['redirect_uris'] ) ) {
			return null;
		}
		$client = array(
			'name'          => Store::cut( sanitize_text_field( (string) $doc['client_name'] ), 191 ),
			'redirect_uris' => array_values( array_filter( $doc['redirect_uris'], 'is_string' ) ),
			'secret_hash'   => null,
			'source'        => 'cimd',
		);
		set_transient( $key, $client, HOUR_IN_SECONDS );
		return $client;
	}

	/** RFC 7591 registration. Open, but limited to known callbacks and public clients. */
	private static function register() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::oauth_error( 'invalid_request', 'POST required.', 405 );
		}
		if ( ! Policy::enabled() ) {
			self::oauth_error( 'access_denied', 'AI agents are switched off on this site. A person can switch them on in WordPress, under Site Agent > Access.', 403 );
		}
		// New applications are accepted only while a person has opened new connections in wp-admin.
		if ( ! Policy::pairing_open() ) {
			self::oauth_error( 'access_denied', 'New connections are closed on this site. In WordPress, open Site Agent > Connect (or Profile > AI connections) and click "Allow a new connection", then try again within 15 minutes.', 403 );
		}
		if ( Store::over_limit( 'register:' . self::client_ip(), 60, HOUR_IN_SECONDS ) ) {
			self::oauth_error( 'temporarily_unavailable', 'Too many registrations.', 429 );
		}
		$input = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $input ) || empty( $input['redirect_uris'] ) || ! is_array( $input['redirect_uris'] ) ) {
			self::oauth_error( 'invalid_client_metadata', 'redirect_uris is required.' );
		}
		$uris = array_values( array_filter( $input['redirect_uris'], 'is_string' ) );
		if ( count( $uris ) > 5 ) {
			self::oauth_error( 'invalid_client_metadata', 'At most 5 redirect URIs.' );
		}
		foreach ( $uris as $uri ) {
			if ( strlen( $uri ) > 512 || ! self::redirect_is_known( $uri ) ) {
				self::oauth_error( 'invalid_redirect_uri', 'Redirect URI is not allowed on this site: ' . Store::cut( $uri, 200 ) . '. An administrator can add it in Site Agent > Settings.' );
			}
		}
		if ( ! $uris ) {
			self::oauth_error( 'invalid_redirect_uri', 'No usable redirect URI.' );
		}
		$name   = isset( $input['client_name'] ) && is_string( $input['client_name'] ) ? sanitize_text_field( $input['client_name'] ) : 'Unnamed client';
		$client = Store::create_client( $name, $uris, 'dcr' );
		self::json(
			array(
				'client_id'                  => $client['client_id'],
				'client_id_issued_at'        => time(),
				'client_name'                => $name,
				'redirect_uris'              => $uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201
		);
	}

	/** Authorization endpoint: login, then a consent screen, then a redirect with the code. */
	private static function authorize() {
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::authorize_decision();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth request, protected by PKCE and the consent nonce.
		$params = array();
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' ) as $name ) {
			$params[ $name ] = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		// phpcs:enable

		if ( ! Policy::enabled() ) {
			self::error_page( __( 'AI agents are switched off on this site. An administrator can switch them on in Site Agent > Access.', 'site-agent-mcp-lite' ) );
		}

		// Sign-in first: nothing, not even fetching a client's metadata document, happens for anonymous visitors.
		if ( ! self::has_browser_session() ) {
			$scheme  = is_ssl() ? 'https://' : 'http://';
			$current = $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		// Never redirect until the client and its callback are proven.
		$client = self::resolve_client( $params['client_id'] );
		if ( ! $client ) {
			self::error_page( __( 'This application could not be identified.', 'site-agent-mcp-lite' ) );
		}
		if ( ! self::redirect_matches( $params['redirect_uri'], $client['redirect_uris'] ) ) {
			self::error_page( __( 'The return address does not match this application.', 'site-agent-mcp-lite' ) );
		}
		if ( 'manual' !== $client['source'] && ! self::redirect_is_known( $params['redirect_uri'] ) ) {
			self::error_page(
				sprintf(
					/* translators: %s: redirect URI */
					__( 'This return address is not allowed on this site: %s. An administrator can add it in Site Agent > Settings.', 'site-agent-mcp-lite' ),
					$params['redirect_uri']
				)
			);
		}

		if ( 'code' !== $params['response_type'] ) {
			self::redirect_error( $params, 'unsupported_response_type', 'Only the code flow is supported.' );
		}
		if ( 'S256' !== $params['code_challenge_method'] || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $params['code_challenge'] ) ) {
			self::redirect_error( $params, 'invalid_request', 'PKCE with S256 is required.' );
		}
		if ( '' !== $params['resource'] && untrailingslashit( $params['resource'] ) !== untrailingslashit( self::resource() ) ) {
			self::redirect_error( $params, 'invalid_target', 'This server only issues tokens for its MCP endpoint.' );
		}

		$user_id = get_current_user_id();
		if ( ! self::user_can_connect( $user_id ) ) {
			self::error_page( __( 'Your account is not allowed to connect AI agents to this site.', 'site-agent-mcp-lite' ) );
		}
		// A link alone never connects an app: this person opens new connections in wp-admin first.
		if ( ! Policy::pairing_open( $user_id ) ) {
			self::closed_page();
		}

		$request_id = Store::random( 16 );
		set_transient(
			'samcp_az_' . $request_id,
			array(
				'user_id' => $user_id,
				'params'  => $params,
				'client'  => $client,
			),
			10 * MINUTE_IN_SECONDS
		);
		self::consent_page( $request_id, $client, $params );
	}

	/**
	 * Whether a person is signed in through the browser. Consent must never be given by an API credential.
	 *
	 * @return bool
	 */
	private static function has_browser_session() {
		// Sessions opened by the admin bridge belong to an agent, never to a person.
		return is_user_logged_in() && wp_validate_auth_cookie( '', 'logged_in' ) === get_current_user_id() && ! Policy::in_bridge();
	}

	/**
	 * Whether a user may connect agents to the site: their access level is above none.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_can_connect( $user_id ) {
		return (bool) apply_filters( 'samcp_user_can_connect', 'none' !== Policy::user_max_level( $user_id ), $user_id );
	}

	/**
	 * Tell the account, and the site administrator, that an app was connected.
	 *
	 * @param int    $user_id  Account.
	 * @param string $app      App name, as the app gave it.
	 * @param string $redirect Return address.
	 * @param string $level    Access level.
	 * @param bool   $power    Server-level tools granted.
	 */
	private static function notify( $user_id, $app, $redirect, $level, $power ) {
		if ( ! Policy::notify_enabled() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] An AI app was connected', 'site-agent-mcp-lite' ), $site );
		$lines   = array(
			/* translators: %s: account login */
			sprintf( __( 'An AI app was connected to this site with the account %s.', 'site-agent-mcp-lite' ), $user->user_login ),
			'',
			/* translators: %s: app name */
			sprintf( __( 'App: %s (name given by the app itself)', 'site-agent-mcp-lite' ), $app ),
			/* translators: %s: URL */
			sprintf( __( 'Returns to: %s', 'site-agent-mcp-lite' ), $redirect ),
			/* translators: %s: access level name */
			sprintf( __( 'Access: %s', 'site-agent-mcp-lite' ), Policy::level_label( $level ) . ( $power ? ' + ' . __( 'server-level tools', 'site-agent-mcp-lite' ) : '' ) ),
			/* translators: %s: date and time */
			sprintf( __( 'Date: %s', 'site-agent-mcp-lite' ), wp_date( 'Y-m-d H:i' ) ),
			'',
			__( 'If you did not do this, disconnect the app now and change the account password:', 'site-agent-mcp-lite' ),
			user_can( $user, 'manage_options' ) ? admin_url( 'admin.php?page=site-agent-mcp&tab=connect' ) : admin_url( 'profile.php?page=site-agent-mcp' ),
		);
		$to = array( $user->user_email );
		$admin = (string) get_option( 'admin_email' );
		if ( is_email( $admin ) && strtolower( $admin ) !== strtolower( $user->user_email ) ) {
			$to[] = $admin;
		}
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/** Explain that new connections are closed, with the way to open them, and stop. */
	private static function closed_page() {
		$where = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=site-agent-mcp&tab=connect' ) : admin_url( 'profile.php?page=site-agent-mcp' );
		wp_die(
			'<p>' . esc_html__( 'New connections are closed on this site, so a link alone can never connect an app.', 'site-agent-mcp-lite' ) . '</p>'
			. '<p>' . esc_html__( 'If you started this connection yourself, open new connections in WordPress, then start again from your AI app within 15 minutes.', 'site-agent-mcp-lite' ) . '</p>'
			. '<p><a href="' . esc_url( $where ) . '">' . esc_html__( 'Open new connections', 'site-agent-mcp-lite' ) . '</a></p>',
			esc_html__( 'Connection refused', 'site-agent-mcp-lite' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Whether the power tools are available on this site.
	 *
	 * @return bool
	 */
	public static function power_tools_enabled() {
		// The Lite edition ships no server-level tools at all.
		if ( 'full' !== SAMCP_EDITION ) {
			return false;
		}
		if ( defined( 'SAMCP_DISABLE_POWER_TOOLS' ) && SAMCP_DISABLE_POWER_TOOLS ) {
			return false;
		}
		return (bool) get_option( 'samcp_power_enabled', 1 );
	}

	/** Apply the user's decision from the consent screen. */
	private static function authorize_decision() {
		$request_id = isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
		if ( ! self::has_browser_session() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'samcp_consent_' . $request_id ) ) {
			self::error_page( __( 'This page has expired. Start the connection again from your AI application.', 'site-agent-mcp-lite' ) );
		}
		$pending = get_transient( 'samcp_az_' . $request_id );
		if ( ! is_array( $pending ) || (int) $pending['user_id'] !== get_current_user_id() ) {
			self::error_page( __( 'This page has expired. Start the connection again from your AI application.', 'site-agent-mcp-lite' ) );
		}
		delete_transient( 'samcp_az_' . $request_id );

		$params = $pending['params'];
		if ( ! isset( $_POST['approve'] ) ) {
			self::redirect_error( $params, 'access_denied', 'The user refused the connection.' );
		}
		if ( ! Policy::enabled() || ! Policy::pairing_open( (int) $pending['user_id'] ) || ! self::user_can_connect( (int) $pending['user_id'] ) ) {
			self::redirect_error( $params, 'access_denied', 'New connections are closed on this site.' );
		}

		// The person picks the level; it can never exceed what the settings allow their account.
		$max    = Policy::user_max_level( (int) $pending['user_id'] );
		$asked  = isset( $_POST['access_level'] ) ? sanitize_key( wp_unslash( $_POST['access_level'] ) ) : 'read';
		$level  = Policy::is_level( $asked ) && 'none' !== $asked ? Policy::lower( $asked, $max ) : 'read';
		$scopes = array( Policy::level_scope( $level ) );
		if ( 'full' === $level && isset( $_POST['grant_power'] ) && self::power_tools_enabled() && user_can( $pending['user_id'], 'manage_options' ) ) {
			$scopes[] = self::SCOPE_POWER;
		}
		self::notify( (int) $pending['user_id'], $pending['client']['name'], $params['redirect_uri'], $level, in_array( self::SCOPE_POWER, $scopes, true ) );

		$code = Store::issue(
			'code',
			array(
				'family'         => Store::random( 16 ),
				'client_id'      => $params['client_id'],
				'client_name'    => $pending['client']['name'],
				'user_id'        => $pending['user_id'],
				'scope'          => implode( ' ', $scopes ),
				'resource'       => self::resource(),
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'granted_at'     => time(),
			),
			Store::CODE_TTL
		);
		// One approval per opening: the next app needs a new click in wp-admin.
		Policy::close_pairing( (int) $pending['user_id'] );
		self::redirect_to_client( $params, array( 'code' => $code ) );
	}

	/**
	 * Redirect to the client with response parameters, state and issuer.
	 *
	 * @param array $params Original request.
	 * @param array $extra  Response parameters.
	 */
	private static function redirect_to_client( array $params, array $extra ) {
		$extra['iss'] = self::issuer();
		if ( '' !== $params['state'] ) {
			$extra['state'] = $params['state'];
		}
		$separator = false === strpos( $params['redirect_uri'], '?' ) ? '?' : '&';
		// Not wp_safe_redirect: the destination is the client's validated callback, on another host.
		header( 'Location: ' . $params['redirect_uri'] . $separator . http_build_query( $extra, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
		exit;
	}

	/**
	 * Redirect an error to a validated client callback. The description is read by
	 * the client app, not shown by this site, so it stays in English.
	 *
	 * @param array  $params      Original request.
	 * @param string $error       Error code.
	 * @param string $description Description.
	 */
	private static function redirect_error( array $params, $error, $description ) {
		self::redirect_to_client(
			$params,
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
	}

	/**
	 * Show an error without redirecting and stop.
	 *
	 * @param string $message Message.
	 */
	private static function error_page( $message ) {
		wp_die( esc_html( $message ), esc_html__( 'Connection refused', 'site-agent-mcp-lite' ), array( 'response' => 400 ) );
	}

	/**
	 * Render the consent screen and stop.
	 *
	 * @param string $request_id Pending request.
	 * @param array  $client     Client.
	 * @param array  $params     Request.
	 */
	private static function consent_page( $request_id, array $client, array $params ) {
		$user       = wp_get_current_user();
		$host       = (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST );
		$identifier = 'cimd' === $client['source'] ? (string) wp_parse_url( $params['client_id'], PHP_URL_HOST ) : '';
		$max        = Policy::user_max_level( $user->ID );
		$levels     = array();
		foreach ( array( 'read', 'content', 'full' ) as $candidate ) {
			if ( Policy::rank( $candidate ) <= Policy::rank( $max ) ) {
				$levels[] = $candidate;
			}
		}
		// The Lite edition proposes the smallest access; the person can choose more.
		$preselect  = 'full' === SAMCP_EDITION ? $max : 'read';
		$can_power  = 'full' === $max && self::power_tools_enabled() && user_can( $user, 'manage_options' );
		$can_code   = 'full' === $max && Policy::extensions_allowed() && user_can( $user, 'install_plugins' );
		// The return address is where the access goes: show its scheme, host and port.
		$scheme = strtolower( (string) wp_parse_url( $params['redirect_uri'], PHP_URL_SCHEME ) );
		$port   = wp_parse_url( $params['redirect_uri'], PHP_URL_PORT );
		$origin = $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $site ) ) {
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$icon  = get_site_icon_url( 64 );
		$roles = array();
		foreach ( (array) $user->roles as $role ) {
			$roles[] = isset( wp_roles()->roles[ $role ] ) ? translate_user_role( wp_roles()->roles[ $role ]['name'] ) : $role;
		}
		// Signing out comes back here, so the right account can approve.
		$here    = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		header( 'Content-Type: text/html; charset=utf-8' );
		// Not an admin page: WordPress picks the site language here (determine_locale()).
		?>
<!doctype html>
<html <?php echo I18n::document_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>
		<?php
		/* translators: %s: site name */
		echo esc_html( sprintf( __( 'Connect an AI app to %s', 'site-agent-mcp-lite' ), $site ) );
		?>
</title>
<style>
:root{--accent:#2271b1;--text:#1d2327;--muted:#50575e;--border:#c3c4c7;--bg:#f0f0f1;--error:#d63638}
body{font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:24px 16px}
main{max-width:520px;margin:4vh auto;background:#fff;border:1px solid var(--border);border-radius:4px;padding:24px}
.site{display:flex;gap:12px;align-items:center;margin:0 0 16px;padding-bottom:16px;border-bottom:1px solid #dcdcde}
.site img{width:32px;height:32px;border-radius:4px}
.site strong{display:block;font-size:18px}.site span{display:block;color:var(--muted);font-size:14px}
h1{font-size:22px;line-height:1.3;margin:0 0 16px}
h2{font-size:16px;margin:24px 0 8px}
dl{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;margin:16px 0}dt{color:var(--muted)}dd{margin:0;overflow-wrap:anywhere}
.origin{font-size:18px;font-weight:600}
.tag{display:inline-block;font-size:13px;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:0 8px;margin-top:4px}
.hint{display:block;color:var(--muted);font-size:14px}
.warn{background:#fcf0f1;border-inline-start:4px solid var(--error);padding:8px 12px;margin:16px 0}
.note{background:#f6f7f7;border-inline-start:4px solid #8c8f94;padding:8px 12px;margin:16px 0}
.origin,.ltr{direction:ltr;unicode-bidi:isolate}
.levels{border:0;padding:0;margin:24px 0 8px}.levels legend{font-weight:600;font-size:16px;margin-bottom:8px;padding:0}
.levels label{display:flex;gap:12px;align-items:flex-start;padding:8px 0;cursor:pointer}
input[type=radio]{margin-top:4px;width:20px;height:20px;flex:none}
.power{margin:16px 0}
.power label{display:flex;gap:12px;align-items:flex-start}
.power .warn{margin:8px 0 0}
input[type=checkbox]{margin-top:4px;width:20px;height:20px;flex:none}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
button{font:inherit;min-height:44px;min-width:140px;padding:0 20px;border-radius:4px;border:1px solid var(--accent);cursor:pointer}
.primary{background:var(--accent);color:#fff}.secondary{background:#fff;color:var(--accent)}
a{color:var(--accent)}
button:focus,input:focus,a:focus{outline:2px solid transparent;box-shadow:0 0 0 2px #fff,0 0 0 4px var(--accent)}
.footer{margin:24px 0 0;color:var(--muted);font-size:14px}
.screen-reader-text{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>
</head>
<body>
<main>
<div class="site">
		<?php if ( $icon ) : ?>
<img src="<?php echo esc_url( $icon ); ?>" alt="" width="32" height="32">
		<?php endif; ?>
<div><strong><?php echo esc_html( $site ); ?></strong><span>Site Agent MCP</span></div>
</div>
<h1>
		<?php
		/* translators: %s: site name */
		echo esc_html( sprintf( __( 'An app asks to act on %s', 'site-agent-mcp-lite' ), $site ) );
		?>
</h1>
<dl>
<dt><?php esc_html_e( 'App', 'site-agent-mcp-lite' ); ?></dt>
<dd><?php echo esc_html( $client['name'] ); ?><br><span class="tag"><?php esc_html_e( 'Name given by the app itself, not verified', 'site-agent-mcp-lite' ); ?></span></dd>
<dt><?php esc_html_e( 'Returns to', 'site-agent-mcp-lite' ); ?></dt>
<dd><span class="origin"><?php echo esc_html( $origin ); ?></span><span class="hint ltr"><?php echo esc_html( $params['redirect_uri'] ); ?></span><span class="hint"><?php esc_html_e( 'The access is sent to this address. Check that it belongs to the app you are connecting.', 'site-agent-mcp-lite' ); ?></span></dd>
		<?php if ( $identifier ) : ?>
<dt><?php esc_html_e( 'Declared by', 'site-agent-mcp-lite' ); ?></dt><dd><?php echo esc_html( $identifier ); ?></dd>
		<?php endif; ?>
<dt><?php esc_html_e( 'Site', 'site-agent-mcp-lite' ); ?></dt><dd class="ltr"><?php echo esc_html( home_url() ); ?></dd>
<dt><?php esc_html_e( 'Signed in as', 'site-agent-mcp-lite' ); ?></dt>
<dd><?php echo esc_html( $user->user_login ); ?><?php echo $roles ? ' (' . esc_html( implode( ', ', $roles ) ) . ')' : ''; ?> · <a href="<?php echo esc_url( wp_logout_url( $here ) ); ?>"><?php esc_html_e( 'Not you?', 'site-agent-mcp-lite' ); ?></a></dd>
</dl>
		<?php if ( preg_match( '#^http://#', $params['redirect_uri'] ) ) : ?>
<p class="note"><?php esc_html_e( 'This connection returns to an app running on your own computer. Continue only if you just started it yourself.', 'site-agent-mcp-lite' ); ?></p>
		<?php endif; ?>
<form method="post" action="<?php echo esc_url( self::endpoint( 'authorize' ) ); ?>">
		<?php wp_nonce_field( 'samcp_consent_' . $request_id ); ?>
<input type="hidden" name="request_id" value="<?php echo esc_attr( $request_id ); ?>">
<fieldset class="levels">
<legend><?php esc_html_e( 'What the app can do', 'site-agent-mcp-lite' ); ?></legend>
		<?php foreach ( $levels as $level ) : ?>
<label for="samcp-level-<?php echo esc_attr( $level ); ?>">
<input type="radio" id="samcp-level-<?php echo esc_attr( $level ); ?>" name="access_level" value="<?php echo esc_attr( $level ); ?>"<?php checked( $level, $preselect ); ?>>
<span><strong><?php echo esc_html( Policy::level_label( $level ) ); ?></strong><span class="hint"><?php echo esc_html( Policy::level_help( $level ) ); ?></span></span>
</label>
		<?php endforeach; ?>
		<?php if ( count( $levels ) < 3 ) : ?>
<p class="hint"><?php esc_html_e( 'The site settings limit what your account can give an app.', 'site-agent-mcp-lite' ); ?></p>
		<?php endif; ?>
</fieldset>
		<?php if ( $can_code ) : ?>
<p class="note"><?php esc_html_e( 'With "Everything the account can do", the app can also install plugins from wordpress.org, which gives it wide control of the site.', 'site-agent-mcp-lite' ); ?></p>
		<?php endif; ?>
<p class="warn"><?php esc_html_e( 'Only continue if you started this connection and trust this app.', 'site-agent-mcp-lite' ); ?></p>
<div class="actions">
<button class="primary" type="submit" name="approve" value="1"><?php esc_html_e( 'Allow access', 'site-agent-mcp-lite' ); ?></button>
<button class="secondary" type="submit" name="deny" value="1"><?php esc_html_e( 'Cancel', 'site-agent-mcp-lite' ); ?></button>
</div>
</form>
<p class="footer"><?php esc_html_e( 'You can disconnect this app at any time in your WordPress admin, under Site Agent for administrators or Profile > AI connections for other accounts.', 'site-agent-mcp-lite' ); ?></p>
</main>
</body>
</html>
		<?php
		exit;
	}

	/** Token endpoint. */
	private static function token() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::oauth_error( 'invalid_request', 'POST required.', 405 );
		}
		if ( Store::over_limit( 'token:' . self::client_ip(), 300, 5 * MINUTE_IN_SECONDS ) ) {
			self::oauth_error( 'temporarily_unavailable', 'Too many requests.', 429 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- OAuth endpoint, authenticated by code, verifier and client.
		$grant = isset( $_POST['grant_type'] ) ? (string) wp_unslash( $_POST['grant_type'] ) : '';
		if ( ! Policy::enabled() ) {
			self::oauth_error( 'access_denied', 'AI agents are switched off on this site.', 403 );
		}
		list( $client_id, $secret ) = self::client_credentials();
		// The code or refresh token must exist for this client before anything else, so an anonymous
		// request never makes this site fetch a client metadata document.
		$presented = '';
		if ( 'authorization_code' === $grant ) {
			$presented = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		} elseif ( 'refresh_token' === $grant ) {
			$presented = isset( $_POST['refresh_token'] ) ? (string) wp_unslash( $_POST['refresh_token'] ) : '';
		}
		$known = '' !== $presented ? Store::find( $presented, 'authorization_code' === $grant ? 'code' : 'refresh' ) : null;
		if ( in_array( $grant, array( 'authorization_code', 'refresh_token' ), true ) && ( ! $known || ! hash_equals( (string) $known['client_id'], (string) $client_id ) ) ) {
			self::oauth_error( 'invalid_grant', 'Invalid code or refresh token.' );
		}
		$client = self::resolve_client( $client_id );
		if ( ! $client ) {
			self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}
		if ( ! empty( $client['secret_hash'] ) && ( null === $secret || ! hash_equals( $client['secret_hash'], Store::hash( $secret ) ) ) ) {
			self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}
		$resource = isset( $_POST['resource'] ) ? (string) wp_unslash( $_POST['resource'] ) : '';
		if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( self::resource() ) ) {
			self::oauth_error( 'invalid_target', 'This server only issues tokens for its MCP endpoint.' );
		}

		if ( 'authorization_code' === $grant ) {
			$code     = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
			$verifier = isset( $_POST['code_verifier'] ) ? (string) wp_unslash( $_POST['code_verifier'] ) : '';
			$redirect = isset( $_POST['redirect_uri'] ) ? (string) wp_unslash( $_POST['redirect_uri'] ) : '';
			self::grant_code( $client_id, $client, $code, $verifier, $redirect );
		}
		if ( 'refresh_token' === $grant ) {
			$refresh = isset( $_POST['refresh_token'] ) ? (string) wp_unslash( $_POST['refresh_token'] ) : '';
			self::grant_refresh( $client_id, $client, $refresh );
		}
		// phpcs:enable
		self::oauth_error( 'unsupported_grant_type', 'Unsupported grant type.' );
	}

	/**
	 * Read client credentials from Basic auth or the form.
	 *
	 * @return array Client id and secret or null.
	 */
	private static function client_credentials() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$header = MCP_Server::authorization_header();
		if ( 0 === stripos( $header, 'Basic ' ) ) {
			$decoded = base64_decode( substr( $header, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $id, $secret ) = explode( ':', $decoded, 2 );
				return array( rawurldecode( $id ), rawurldecode( $secret ) );
			}
		}
		$id     = isset( $_POST['client_id'] ) ? (string) wp_unslash( $_POST['client_id'] ) : '';
		$secret = isset( $_POST['client_secret'] ) ? (string) wp_unslash( $_POST['client_secret'] ) : null;
		// phpcs:enable
		return array( $id, $secret );
	}

	/**
	 * Exchange an authorization code.
	 *
	 * @param string $client_id Client id.
	 * @param array  $client    Client.
	 * @param string $code      Code.
	 * @param string $verifier  PKCE verifier.
	 * @param string $redirect  Redirect URI.
	 */
	private static function grant_code( $client_id, array $client, $code, $verifier, $redirect ) {
		$row = Store::find( $code, 'code' );
		if ( ! $row || ! hash_equals( $row['client_id'], $client_id ) || $row['revoked_at'] ) {
			self::oauth_error( 'invalid_grant', 'Invalid code.' );
		}
		if ( $row['used_at'] ) {
			// A replayed code means it leaked: cancel what it produced.
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'This code was already used.' );
		}
		if ( (int) $row['expires_at'] < time() || $row['redirect_uri'] !== $redirect ) {
			self::oauth_error( 'invalid_grant', 'Expired code or wrong redirect_uri.' );
		}
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || ! hash_equals( $row['code_challenge'], $computed ) ) {
			self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}
		if ( ! Store::consume( $row['id'] ) ) {
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'This code was already used.' );
		}
		self::send_tokens( $row, true );
	}

	/**
	 * Exchange a refresh token. Public clients rotate; confidential clients keep theirs.
	 *
	 * @param string $client_id Client id.
	 * @param array  $client    Client.
	 * @param string $refresh   Refresh token.
	 */
	private static function grant_refresh( $client_id, array $client, $refresh ) {
		$row = Store::find( $refresh, 'refresh' );
		if ( ! $row || ! hash_equals( $row['client_id'], $client_id ) || $row['revoked_at'] || (int) $row['expires_at'] < time() ) {
			self::oauth_error( 'invalid_grant', 'Invalid refresh token.' );
		}
		if ( ! Policy::enabled() ) {
			self::oauth_error( 'access_denied', 'AI agents are switched off on this site.', 403 );
		}
		if ( ! get_userdata( (int) $row['user_id'] ) ) {
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'This account no longer exists.' );
		}
		// Not revoked: the settings may give this account its access back.
		if ( ! self::user_can_connect( (int) $row['user_id'] ) || 'none' === Policy::token_level( $row ) ) {
			self::oauth_error( 'invalid_grant', 'This account is not allowed to connect AI agents right now. An administrator can change that in Site Agent > Access.' );
		}
		// Tokens issued again and again but never seen by the endpoint: the server drops the header.
		// Stop the client's refresh loop instead of minting pairs forever.
		if ( Store::unused_burst( $row['family'] ) ) {
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'Tokens are never received by the server: its Authorization header does not reach WordPress. See Site Agent > Overview in WordPress.' );
		}
		// However often it is refreshed, a connection ends some months after the person approved it.
		if ( Store::granted_at( $row ) < time() - Store::GRANT_MAX_AGE ) {
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'This connection is too old. Connect the app again.' );
		}
		$confidential = ! empty( $client['secret_hash'] );
		if ( $confidential ) {
			self::send_tokens( $row, false, $refresh );
		}
		if ( ! Store::consume( $row['id'] ) ) {
			$fresh = Store::find( $refresh, 'refresh' );
			if ( ! $fresh || (int) $fresh['used_at'] < time() - Store::REFRESH_GRACE ) {
				// Replay of a rotated token outside the race window: treat as theft.
				Store::revoke_family( $row['family'] );
				self::oauth_error( 'invalid_grant', 'This refresh token was already used.' );
			}
		}
		if ( $row['used_at'] && (int) $row['used_at'] < time() - Store::REFRESH_GRACE ) {
			Store::revoke_family( $row['family'] );
			self::oauth_error( 'invalid_grant', 'This refresh token was already used.' );
		}
		self::send_tokens( $row, true );
	}

	/**
	 * Issue tokens from a code or refresh row and stop.
	 *
	 * @param array       $row              Source row.
	 * @param bool        $issue_refresh    Issue a new refresh token.
	 * @param string|null $existing_refresh Refresh token to return unchanged.
	 */
	private static function send_tokens( array $row, $issue_refresh, $existing_refresh = null ) {
		$fields = array_intersect_key( $row, array_flip( array( 'family', 'client_id', 'client_name', 'user_id', 'scope', 'resource', 'granted_at' ) ) );
		$body   = array(
			'access_token' => Store::issue( 'access', $fields, Store::ACCESS_TTL ),
			'token_type'   => 'Bearer',
			'expires_in'   => Store::ACCESS_TTL,
			'scope'        => $row['scope'],
		);
		if ( $issue_refresh ) {
			$body['refresh_token'] = Store::issue( 'refresh', $fields, Store::REFRESH_TTL );
		} elseif ( null !== $existing_refresh ) {
			$body['refresh_token'] = $existing_refresh;
		}
		self::json( $body );
	}

	/** RFC 7009 revocation. Always answers 200. */
	private static function revoke() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::oauth_error( 'invalid_request', 'POST required.', 405 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- possession of the token proves the right to revoke it.
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
		$row   = Store::find( $token, 'access' );
		if ( ! $row ) {
			$row = Store::find( $token, 'refresh' );
		}
		if ( $row ) {
			Store::revoke_family( $row['family'] );
		}
		status_header( 200 );
		exit;
	}

	/**
	 * Address used for rate limiting. Forwarded headers can be forged, so they are ignored by default.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return (string) apply_filters( 'samcp_client_ip', $ip );
	}
}
