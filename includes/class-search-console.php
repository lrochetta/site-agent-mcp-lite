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
	const DIMENSIONS    = array( 'query', 'page', 'country', 'device', 'date' );
	const TYPES         = array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' );

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
			'gsc_sites'       => $make( 'Search Console status', 'Whether Search Console is connected, and the properties this Google account can read.', array(), array(), 'read', $ns . 'gsc_sites', 'manage_options', true ),
			'gsc_performance' => $make( 'Search performance', 'Real clicks, impressions, CTR and position from Google Search Console, by query, page, country, device or date. This is the only real ranking data available here: use it to choose what to work on, and again later to measure results.', array( 'days' => $int + array( 'description' => 'Period, default 28.' ), 'dimensions' => array( 'type' => 'array', 'items' => $str, 'description' => 'query, page, country, device, date. Default query.' ), 'page' => $str + array( 'description' => 'Only this exact page URL.' ), 'query' => $str + array( 'description' => 'Only queries containing this text.' ), 'type' => $str + array( 'enum' => array( 'web', 'image', 'video', 'news', 'discover', 'googleNews' ) ), 'limit' => $int, 'site' => $site ), array(), 'read', $ns . 'gsc_performance', 'manage_options', true ),
			'gsc_inspect_url' => $make( 'Inspect a URL in Google', 'What Google knows about one page: whether it is indexed, the canonical it chose, the last crawl, and any rich results found. Limited to 2,000 a day per site.', array( 'url' => $str, 'site' => $site ), array( 'url' ), 'read', $ns . 'gsc_inspect_url', 'manage_options', true ),
			'gsc_sitemaps'    => $make( 'Search Console sitemaps', 'Submit a sitemap of this site to Google with action submit, or list the sitemaps Google knows. To only read them, use gsc_sitemaps_list.', array( 'action' => $str + array( 'enum' => array( 'list', 'submit' ) ), 'sitemap_url' => $str, 'site' => $site ), array(), 'write', $ns . 'gsc_sitemaps', 'manage_options', true ),
			'gsc_sitemaps_list' => $make( 'List Search Console sitemaps', 'The sitemaps of this site that Google knows, with errors and warnings. Changes nothing.', array( 'site' => $site ), array(), 'read', $ns . 'gsc_sitemaps_list', 'manage_options', true ),
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
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			// A stale or forged return is refused without cancelling the attempt still in progress.
			wp_die( esc_html__( 'This connection attempt has expired. Start again from Site Agent > Services.', 'site-agent-mcp-lite' ), 400 );
		}
		// Consumed only once it matched: the same return cannot be used twice.
		delete_transient( $key );
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
		$token   = (string) get_option( self::TOKEN_OPTION, '' );
		$revoked = '' === $token || self::revoke( $token );
		// The local tokens go whatever Google answers.
		self::forget_tokens();
		self::back( $revoked ? 'gsc_disconnected' : 'gsc_unrevoked' );
	}

	/**
	 * Ask Google to revoke a token.
	 *
	 * @param string $token Refresh token.
	 * @return bool Whether Google confirmed that the token no longer works.
	 */
	private static function revoke( $token ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/revoke',
			array(
				'timeout' => 10,
				'body'    => array( 'token' => $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return true;
		}
		// A token Google no longer knows gives no access either.
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return 400 === $status && is_array( $data ) && isset( $data['error'] ) && 'invalid_token' === $data['error'];
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
			throw new Tool_Error( 'Search Console is not connected, so real search data is unavailable. Ask an administrator to connect it in Site Agent > Services, and say your analysis lacks this data until then.' );
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
			throw new Tool_Error( 'Google refused to renew the access. Check the client ID and secret in Site Agent > Services.' );
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
	 * @param bool       $json   Whether a JSON object must come back. A sitemap submission succeeds with no body.
	 * @return array
	 * @throws Tool_Error On failure, or when the answer is not the JSON object expected.
	 */
	private static function call( $method, $url, $body = null, $json = true ) {
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
		if ( ! $json ) {
			return is_array( $data ) ? $data : array();
		}
		// An unreadable answer must not look like "no data".
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			throw new Tool_Error( 'Search Console answered ' . (int) $status . ' without readable data. Try again later.' );
		}
		return $data;
	}

	/**
	 * Whether a URL is under this site's address, on its host with or without www, any scheme.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function in_scope( $url ) {
		return 1 === preg_match( '#' . self::scope_regex() . '#i', (string) $url );
	}

	/**
	 * Regular expression, valid in PCRE and in Google's RE2, for the pages of this site.
	 *
	 * @return string
	 */
	private static function scope_regex() {
		$quote = static function ( $text ) {
			return (string) preg_replace( '/[^A-Za-z0-9\/_]/', '\\\\$0', $text );
		};
		$host  = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$path  = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		return '^https?://(www\.)?' . $quote( $host ) . '(:[0-9]+)?' . $quote( $path );
	}

	/**
	 * Whether a property covers this site and nothing else: the URL prefix of the site itself.
	 * A domain property or a parent folder also holds other sites' data.
	 *
	 * @param string $property Property.
	 * @return bool
	 */
	private static function is_exact_property( $property ) {
		if ( 0 === stripos( $property, 'sc-domain:' ) ) {
			return false;
		}
		return trailingslashit( (string) wp_parse_url( $property, PHP_URL_PATH ) ) === trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
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
			if ( is_array( $entry ) && isset( $entry['siteUrl'] ) && is_string( $entry['siteUrl'] ) ) {
				$available[] = $entry['siteUrl'];
			}
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
		// The account may hold other people's sites: their names stay out of the message.
		throw new Tool_Error( 'This Google account has no Search Console property for this site, among the ' . count( $available ) . ' it can read. Add and verify the site at https://search.google.com/search-console, or pass site.' );
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
		if ( ! self::is_connected() && Site_Kit::search_console_ready() ) {
			return array(
				'connected' => true,
				'source'    => 'site_kit',
				'sites'     => array( array( 'siteUrl' => Site_Kit::property() ) ),
				'note'      => 'Search data comes through Site Kit by Google. gsc_performance works; gsc_inspect_url and gsc_sitemaps need the connection in Site Agent > Services.',
			);
		}
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
		// Site Kit by Google already connected to this site's property: read through it.
		$via_site_kit = ! self::is_connected() && Site_Kit::search_console_ready();
		$dimensions   = array( 'query' );
		if ( ! empty( $args['dimensions'] ) ) {
			$dimensions = is_array( $args['dimensions'] ) ? array_values( array_unique( array_map( 'strval', $args['dimensions'] ) ) ) : array( '' );
			$unknown    = array_diff( $dimensions, self::DIMENSIONS );
			if ( $unknown || count( $dimensions ) > 3 ) {
				throw new Tool_Error( 'dimensions takes up to three of: ' . esc_html( implode( ', ', self::DIMENSIONS ) ) . '.' );
			}
		}
		$type = isset( $args['type'] ) && '' !== $args['type'] ? $args['type'] : 'web';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new Tool_Error( 'type must be one of: ' . esc_html( implode( ', ', self::TYPES ) ) . '.' );
		}
		$page = isset( $args['page'] ) && '' !== $args['page'] ? $args['page'] : '';
		if ( '' !== $page && ( ! is_string( $page ) || ! self::in_scope( $page ) ) ) {
			throw new Tool_Error( 'page must be the full URL of a page of this site.' );
		}
		$query = isset( $args['query'] ) && '' !== $args['query'] ? $args['query'] : '';
		if ( ! is_string( $query ) ) {
			throw new Tool_Error( 'query must be text.' );
		}
		$site = $via_site_kit ? Site_Kit::property() : self::site( $args );
		$days = isset( $args['days'] ) ? min( 480, max( 1, (int) $args['days'] ) ) : 28;
		// Recent days are incomplete in Search Console, so the period ends a little earlier.
		$end  = time() - 3 * DAY_IN_SECONDS;
		$body = array(
			'startDate'  => gmdate( 'Y-m-d', $end - ( $days - 1 ) * DAY_IN_SECONDS ),
			'endDate'    => gmdate( 'Y-m-d', $end ),
			'dimensions' => $dimensions,
			'type'       => $type,
			'rowLimit'   => isset( $args['limit'] ) ? min( 1000, max( 1, (int) $args['limit'] ) ) : 100,
		);
		// A domain property or a parent folder also holds other sites: keep to this one.
		$scoped  = ! self::is_exact_property( $site );
		$filters = array();
		if ( $scoped && '' === $page ) {
			$filters[] = array(
				'dimension'  => 'page',
				'operator'   => 'includingRegex',
				'expression' => self::scope_regex(),
			);
		}
		if ( '' !== $page ) {
			$filters[] = array(
				'dimension'  => 'page',
				'operator'   => 'equals',
				'expression' => $page,
			);
		}
		if ( '' !== $query ) {
			$filters[] = array(
				'dimension'  => 'query',
				'operator'   => 'contains',
				'expression' => $query,
			);
		}
		if ( $filters ) {
			$body['dimensionFilterGroups'] = array( array( 'filters' => $filters ) );
		}
		$notes = array();
		if ( $via_site_kit ) {
			$rows = self::site_kit_rows( $body, $page, $query, $scoped, $notes );
		} else {
			$data = self::call( 'POST', self::API . 'sites/' . rawurlencode( $site ) . '/searchAnalytics/query', $body );
			if ( ! isset( $data['rows'] ) && ! isset( $data['responseAggregationType'] ) ) {
				throw new Tool_Error( 'Search Console answered without search data. Try again later.' );
			}
			$rows = array();
			foreach ( isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['keys'], $row['clicks'], $row['impressions'] ) ) {
					continue;
				}
				$rows[] = array(
					'keys'        => (array) $row['keys'],
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'ctr'         => isset( $row['ctr'] ) ? round( (float) $row['ctr'], 4 ) : 0,
					'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 1 ) : 0,
				);
			}
		}
		if ( $scoped ) {
			$notes[] = 'The property ' . $site . ' covers more than this site, so only pages under ' . home_url( '/' ) . ' are counted.';
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
			'source'         => $via_site_kit ? 'site_kit' : 'site_agent_mcp',
			'period'         => array( $body['startDate'], $body['endDate'] ),
			'dimensions'     => $body['dimensions'],
			'rows'           => $rows,
			'near_page_one'  => array_slice( $near, 0, 20 ),
			'notes'          => $notes,
			'how_to_read'    => 'near_page_one is a heuristic of this plugin (position 4 to 20, 10 or more impressions), not a Google metric. Position is an average. Before and after comparisons need the same length of period, and search results move for many reasons, so never attribute a change to one edit with certainty or promise a ranking.',
		);
	}

	/**
	 * Search analytics rows through Site Kit, which takes no search type or query filter.
	 * What it cannot honour is refused rather than answered with other data.
	 *
	 * @param array  $body   Request as built for Google.
	 * @param string $page   Exact page filter.
	 * @param string $query  Query filter.
	 * @param bool   $scoped Whether the property covers more than this site.
	 * @param array  $notes  Notes for the reader, filled here.
	 * @return array[]
	 * @throws Tool_Error For a request Site Kit cannot answer faithfully.
	 */
	private static function site_kit_rows( array $body, $page, $query, $scoped, array &$notes ) {
		$advice = ' Connect Search Console in Site Agent > Services for this request.';
		if ( 'web' !== $body['type'] ) {
			throw new Tool_Error( 'Through Site Kit by Google, only web search data can be read, not ' . esc_html( $body['type'] ) . '.' . esc_html( $advice ) );
		}
		$query_at = array_search( 'query', $body['dimensions'], true );
		if ( '' !== $query && false === $query_at ) {
			throw new Tool_Error( 'Through Site Kit by Google, the query filter needs query among the dimensions.' . esc_html( $advice ) );
		}
		$page_at = array_search( 'page', $body['dimensions'], true );
		if ( $scoped && '' === $page && false === $page_at ) {
			throw new Tool_Error( 'Site Kit by Google uses a property that covers more than this site, so totals would include other sites. Add page to the dimensions, or pass page.' . esc_html( $advice ) );
		}
		// Filters run here, after Site Kit's limit: ask for the most rows, then cut.
		$filtered = '' !== $query || ( $scoped && '' === $page );
		$rows     = Site_Kit::search_analytics( $body['startDate'], $body['endDate'], $body['dimensions'], $filtered ? 1000 : $body['rowLimit'], $page );
		if ( '' !== $query ) {
			$needle = strtolower( $query );
			$rows   = array_filter(
				$rows,
				static function ( $row ) use ( $needle, $query_at ) {
					return isset( $row['keys'][ $query_at ] ) && false !== strpos( strtolower( (string) $row['keys'][ $query_at ] ), $needle );
				}
			);
		}
		if ( $scoped && '' === $page ) {
			$rows = array_filter(
				$rows,
				static function ( $row ) use ( $page_at ) {
					return isset( $row['keys'][ $page_at ] ) && self::in_scope( (string) $row['keys'][ $page_at ] );
				}
			);
		}
		if ( $filtered ) {
			$notes[] = 'Through Site Kit, filters apply to the top 1,000 rows only.';
		}
		return array_slice( array_values( $rows ), 0, $body['rowLimit'] );
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
		// A domain property also covers other sites on the domain: inspect this site's pages only.
		if ( ! self::in_scope( $args['url'] ) ) {
			throw new Tool_Error( 'url must be the full URL of a page of this site.' );
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
		$action = isset( $args['action'] ) ? $args['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'submit' ), true ) ) {
			throw new Tool_Error( 'action must be list or submit.' );
		}
		if ( 'list' === $action ) {
			return self::gsc_sitemaps_list( $args );
		}
		if ( empty( $args['sitemap_url'] ) || ! is_string( $args['sitemap_url'] ) ) {
			throw new Tool_Error( 'sitemap_url is required to submit.' );
		}
		Admin_Bridge::assert_same_host( $args['sitemap_url'] );
		if ( ! self::in_scope( $args['sitemap_url'] ) ) {
			throw new Tool_Error( 'sitemap_url must be under this site\'s address.' );
		}
		$site = self::site( $args );
		self::call( 'PUT', self::API . 'sites/' . rawurlencode( $site ) . '/sitemaps/' . rawurlencode( $args['sitemap_url'] ), null, false );
		return array(
			'submitted' => $args['sitemap_url'],
			'site'      => $site,
		);
	}

	/**
	 * List this site's sitemaps known to Google.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function gsc_sitemaps_list( array $args ) {
		$site     = self::site( $args );
		$data     = self::call( 'GET', self::API . 'sites/' . rawurlencode( $site ) . '/sitemaps' );
		$sitemaps = array();
		$others   = 0;
		foreach ( isset( $data['sitemap'] ) && is_array( $data['sitemap'] ) ? $data['sitemap'] : array() as $entry ) {
			// A domain property also lists the sitemaps of other sites on the domain.
			if ( is_array( $entry ) && isset( $entry['path'] ) && is_string( $entry['path'] ) && self::in_scope( $entry['path'] ) ) {
				$sitemaps[] = $entry;
			} else {
				++$others;
			}
		}
		return array(
			'site'                  => $site,
			'sitemaps'              => $sitemaps,
			'other_sitemaps_hidden' => $others,
		);
	}
}
