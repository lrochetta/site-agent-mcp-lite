<?php
/**
 * Google Search Console: the site's real search data.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * The site owner connects their own Google account once, with an OAuth client they
 * create. The token stays on this site. Agents use it through tools and never see it.
 */
class Search_Console {

	const ID_OPTION     = 'samcp_gsc_client_id';
	const SECRET_OPTION = 'samcp_gsc_client_secret';
	const TOKEN_OPTION  = 'samcp_gsc_refresh_token';
	const ACCESS_CACHE  = 'samcp_gsc_access_token';
	const API           = 'https://www.googleapis.com/webmasters/v3/';
	const SCOPE         = 'https://www.googleapis.com/auth/webmasters';

	/** Register hooks. */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'callback' ), 1 );
		add_action( 'admin_post_samcp_gsc_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_samcp_gsc_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	/**
	 * Tool definitions.
	 *
	 * @param callable $make Entry factory.
	 * @return array
	 */
	public static function definitions( callable $make ) {
		$str  = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$site = $str + array( 'description' => 'Search Console property. Defaults to the one matching this site.' );
		$ns   = __NAMESPACE__ . '\\Search_Console::';
		return array(
			'gsc_sites'       => $make( 'Search Console status', 'Whether Search Console is connected, and the properties this Google account can read.', array(), array(), 'read', $ns . 'gsc_sites', 'manage_options' ),
			'gsc_performance' => $make( 'Search performance', 'Real clicks, impressions, CTR and position from Google Search Console, by query, page, country, device or date. This is the only real ranking data available here: use it to choose what to work on, and again later to measure results.', array( 'days' => $int + array( 'description' => 'Period, default 28.' ), 'dimensions' => array( 'type' => 'array', 'items' => $str, 'description' => 'query, page, country, device, date. Default query.' ), 'page' => $str + array( 'description' => 'Only this exact page URL.' ), 'query' => $str + array( 'description' => 'Only queries containing this text.' ), 'type' => $str + array( 'enum' => array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' ) ), 'limit' => $int, 'site' => $site ), array(), 'read', $ns . 'gsc_performance', 'manage_options' ),
			'gsc_inspect_url' => $make( 'Inspect a URL in Google', 'What Google knows about one page: whether it is indexed, the canonical it chose, the last crawl, and any rich results found. Limited to 2,000 a day per site.', array( 'url' => $str, 'site' => $site ), array( 'url' ), 'read', $ns . 'gsc_inspect_url', 'manage_options' ),
			'gsc_sitemaps'    => $make( 'Search Console sitemaps', 'List the sitemaps Google knows, with errors and warnings, or submit one.', array( 'action' => $str + array( 'enum' => array( 'list', 'submit' ) ), 'sitemap_url' => $str, 'site' => $site ), array(), 'write', $ns . 'gsc_sitemaps', 'manage_options' ),
		);
	}

	/**
	 * Address Google sends the user back to.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return home_url( '/samcp/gsc-callback' );
	}

	/**
	 * Whether an account is connected.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== (string) get_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Return to the Services tab.
	 *
	 * @param string $notice Notice key.
	 */
	private static function back( $notice ) {
		wp_safe_redirect( Admin_Page::url( 'services', array( 'samcp_notice' => $notice ) ) );
		exit;
	}

	/** Send an administrator to Google's consent screen. */
	public static function connect() {
		check_admin_referer( 'samcp_gsc_connect' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$client_id = (string) get_option( self::ID_OPTION, '' );
		if ( '' === $client_id || '' === (string) get_option( self::SECRET_OPTION, '' ) ) {
			self::back( 'gsc_missing' );
		}
		$state = Store::random( 16 );
		set_transient( 'samcp_gsc_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );
		$query = http_build_query(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				// Without this, Google gives a refresh token only on the very first consent.
				'prompt'        => 'consent',
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		header( 'Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302 );
		exit;
	}

	/** Handle Google's return. */
	public static function callback() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( untrailingslashit( $path ) !== (string) wp_parse_url( self::redirect_uri(), PHP_URL_PATH ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sign in to WordPress as an administrator, then connect again.', 'site-agent-mcp-lite' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the state value protects this request.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$refused  = isset( $_GET['error'] );
		// phpcs:enable
		$key      = 'samcp_gsc_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			wp_die( esc_html__( 'This connection attempt has expired. Start again from Site Agent > Services.', 'site-agent-mcp-lite' ), 400 );
		}
		if ( $refused || '' === $code ) {
			self::back( 'gsc_refused' );
		}
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => (string) get_option( self::ID_OPTION, '' ),
					'client_secret' => (string) get_option( self::SECRET_OPTION, '' ),
					'redirect_uri'  => self::redirect_uri(),
				),
			)
		);
		$data = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['refresh_token'] ) ) {
			self::back( 'gsc_failed' );
		}
		update_option( self::TOKEN_OPTION, $data['refresh_token'], false );
		if ( ! empty( $data['access_token'] ) ) {
			set_transient( self::ACCESS_CACHE, $data['access_token'], max( 60, (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ) - 120 ) );
		}
		self::back( 'gsc_connected' );
	}

	/** Disconnect and revoke the access at Google. */
	public static function disconnect() {
		check_admin_referer( 'samcp_gsc_disconnect' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' !== $token ) {
			wp_remote_post(
				'https://oauth2.googleapis.com/revoke',
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $token ),
				)
			);
		}
		self::forget_tokens();
		self::back( 'gsc_disconnected' );
	}

	/** Remove stored Google tokens. */
	public static function forget_tokens() {
		delete_option( self::TOKEN_OPTION );
		delete_transient( self::ACCESS_CACHE );
		delete_transient( 'samcp_gsc_default_site' );
	}

	/**
	 * Current access token, refreshing it when needed.
	 *
	 * @return string
	 * @throws Tool_Error When not connected, or when Google refuses.
	 */
	private static function access_token() {
		$cached = get_transient( self::ACCESS_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$refresh = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' === $refresh ) {
			throw new Tool_Error( 'Search Console is not connected, so real search data is unavailable. Ask an administrator to connect it in Tools > Site Agent MCP, and say your analysis lacks this data until then.' );
		}
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => (string) get_option( self::ID_OPTION, '' ),
					'client_secret' => (string) get_option( self::SECRET_OPTION, '' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'Google did not answer. Try again later.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error'] ) && 'invalid_grant' === $data['error'] ) {
			self::forget_tokens();
			throw new Tool_Error( 'Google ended the Search Console connection. An administrator must connect it again. This happens every 7 days while the Google project is in Testing status, so publish it.' );
		}
		if ( empty( $data['access_token'] ) ) {
			throw new Tool_Error( 'Google refused to renew the access. Check the client ID and secret in Tools > Site Agent MCP.' );
		}
		Policy::own_write(
			static function () use ( $data ) {
				set_transient( self::ACCESS_CACHE, $data['access_token'], max( 60, (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ) - 120 ) );
			}
		);
		return $data['access_token'];
	}

	/**
	 * Call Google.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   JSON body.
	 * @return array
	 * @throws Tool_Error On failure.
	 */
	private static function call( $method, $url, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 40,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::access_token(),
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'Google did not answer. Try again later.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 400 ) {
			throw new Tool_Error( 'Search Console answered ' . (int) $status . ': ' . esc_html( isset( $data['error']['message'] ) ? $data['error']['message'] : 'no detail' ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Property to use: the one asked for, or the one matching this site.
	 *
	 * @param array $args Arguments.
	 * @return string
	 * @throws Tool_Error When none matches.
	 */
	private static function site( array $args ) {
		if ( ! empty( $args['site'] ) && is_string( $args['site'] ) ) {
			// One Google account can hold other people's sites: tools only ever read this one.
			if ( ! self::is_own_property( $args['site'] ) ) {
				throw new Tool_Error( 'Only the Search Console property of this site can be used.' );
			}
			return $args['site'];
		}
		$cached = get_transient( 'samcp_gsc_default_site' );
		if ( is_string( $cached ) && '' !== $cached && self::is_own_property( $cached ) ) {
			return $cached;
		}
		$entries   = self::call( 'GET', self::API . 'sites' );
		$available = array();
		foreach ( isset( $entries['siteEntry'] ) ? (array) $entries['siteEntry'] : array() as $entry ) {
			$available[] = $entry['siteUrl'];
		}
		$home = trailingslashit( home_url() );
		$host = (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( $home, PHP_URL_HOST ) );
		foreach ( array( $home, 'sc-domain:' . $host ) as $candidate ) {
			if ( in_array( $candidate, $available, true ) ) {
				Policy::own_write(
					static function () use ( $candidate ) {
						set_transient( 'samcp_gsc_default_site', $candidate, HOUR_IN_SECONDS );
					}
				);
				return $candidate;
			}
		}
		throw new Tool_Error( 'This Google account has no Search Console property for this site. It can read: ' . esc_html( $available ? implode( ', ', $available ) : 'none' ) . '. Add and verify the site at https://search.google.com/search-console, or pass site.' );
	}

	/**
	 * Whether a Search Console property is this site: its URL prefix or its domain property.
	 *
	 * @param string $property Property such as https://example.com/ or sc-domain:example.com.
	 * @return bool
	 */
	public static function is_own_property( $property ) {
		$own = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( 0 === stripos( $property, 'sc-domain:' ) ) {
			return strtolower( substr( $property, 10 ) ) === $own;
		}
		$host = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( $property, PHP_URL_HOST ) ) );
		$path = (string) wp_parse_url( $property, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return '' !== $host && $host === $own && 0 === strpos( trailingslashit( $base ), '' === $path ? '/' : trailingslashit( $path ) );
	}

	/**
	 * Connection status and properties.
	 *
	 * @return array
	 */
	public static function gsc_sites() {
		if ( ! self::is_connected() ) {
			return array(
				'connected' => false,
				'next'      => 'Ask an administrator to connect Search Console in Site Agent > Services. Until then, say that your SEO analysis has no real ranking data.',
			);
		}
		$entries = self::call( 'GET', self::API . 'sites' );
		$own     = array();
		$others  = 0;
		foreach ( isset( $entries['siteEntry'] ) ? (array) $entries['siteEntry'] : array() as $entry ) {
			if ( isset( $entry['siteUrl'] ) && self::is_own_property( (string) $entry['siteUrl'] ) ) {
				$own[] = $entry;
			} else {
				++$others;
			}
		}
		return array(
			'connected'          => true,
			'sites'              => $own,
			'other_sites_hidden' => $others,
		);
	}

	/**
	 * Read search performance.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function gsc_performance( array $args ) {
		$site = self::site( $args );
		$days = isset( $args['days'] ) ? min( 480, max( 1, (int) $args['days'] ) ) : 28;
		// Recent days are incomplete in Search Console, so the period ends a little earlier.
		$end  = time() - 3 * DAY_IN_SECONDS;
		$body = array(
			'startDate'  => gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS ),
			'endDate'    => gmdate( 'Y-m-d', $end ),
			'dimensions' => ! empty( $args['dimensions'] ) && is_array( $args['dimensions'] ) ? array_values( array_map( 'strval', $args['dimensions'] ) ) : array( 'query' ),
			'type'       => ! empty( $args['type'] ) ? (string) $args['type'] : 'web',
			'rowLimit'   => isset( $args['limit'] ) ? min( 1000, max( 1, (int) $args['limit'] ) ) : 100,
		);
		$filters = array();
		if ( ! empty( $args['page'] ) ) {
			$filters[] = array(
				'dimension'  => 'page',
				'operator'   => 'equals',
				'expression' => (string) $args['page'],
			);
		}
		if ( ! empty( $args['query'] ) ) {
			$filters[] = array(
				'dimension'  => 'query',
				'operator'   => 'contains',
				'expression' => (string) $args['query'],
			);
		}
		if ( $filters ) {
			$body['dimensionFilterGroups'] = array( array( 'filters' => $filters ) );
		}
		$data = self::call( 'POST', self::API . 'sites/' . rawurlencode( $site ) . '/searchAnalytics/query', $body );
		$rows = array();
		foreach ( isset( $data['rows'] ) ? (array) $data['rows'] : array() as $row ) {
			$rows[] = array(
				'keys'        => $row['keys'],
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'ctr'         => round( (float) $row['ctr'], 4 ),
				'position'    => round( (float) $row['position'], 1 ),
			);
		}
		$near = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return $row['position'] >= 4 && $row['position'] <= 20 && $row['impressions'] >= 10;
				}
			)
		);
		usort(
			$near,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		return array(
			'content_trust'  => 'untrusted_site_content',
			'site'           => $site,
			'period'         => array( $body['startDate'], $body['endDate'] ),
			'dimensions'     => $body['dimensions'],
			'rows'           => $rows,
			'near_page_one'  => array_slice( $near, 0, 20 ),
			'how_to_read'    => 'near_page_one is a heuristic of this plugin (position 4 to 20, 10 or more impressions), not a Google metric. Position is an average. Before and after comparisons need the same length of period, and search results move for many reasons, so never attribute a change to one edit with certainty or promise a ranking.',
		);
	}

	/**
	 * Inspect a URL.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error Without a URL.
	 */
	public static function gsc_inspect_url( array $args ) {
		if ( empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			throw new Tool_Error( 'url is required.' );
		}
		$data = self::call(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => $args['url'],
				'siteUrl'       => self::site( $args ),
			)
		);
		return array(
			'content_trust' => 'untrusted_site_content',
			'inspection'    => isset( $data['inspectionResult'] ) ? $data['inspectionResult'] : $data,
		);
	}

	/**
	 * List or submit sitemaps.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On invalid input.
	 */
	public static function gsc_sitemaps( array $args ) {
		$site = self::site( $args );
		$base = self::API . 'sites/' . rawurlencode( $site ) . '/sitemaps';
		if ( isset( $args['action'] ) && 'submit' === $args['action'] ) {
			if ( empty( $args['sitemap_url'] ) || ! is_string( $args['sitemap_url'] ) ) {
				throw new Tool_Error( 'sitemap_url is required to submit.' );
			}
			Admin_Bridge::assert_same_host( $args['sitemap_url'] );
			self::call( 'PUT', $base . '/' . rawurlencode( $args['sitemap_url'] ) );
			return array(
				'submitted' => $args['sitemap_url'],
				'site'      => $site,
			);
		}
		$data = self::call( 'GET', $base );
		return array(
			'site'     => $site,
			'sitemaps' => isset( $data['sitemap'] ) ? $data['sitemap'] : array(),
		);
	}
}
