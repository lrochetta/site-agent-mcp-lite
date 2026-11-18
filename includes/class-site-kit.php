<?php
/**
 * Use Site Kit by Google when it already connects this site to Google.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Site Kit by Google keeps its own Google connection for Search Console and
 * PageSpeed Insights. When it is set up, the agent's tools read through Site Kit's
 * REST API, as the connected account, so nothing has to be configured twice.
 * Site Kit's credentials are never read: only its public REST routes are called.
 */
class Site_Kit {

	const NAMESPACE = '/google-site-kit/v1/';

	/**
	 * Whether Site Kit is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return defined( 'GOOGLESITEKIT_VERSION' );
	}

	/**
	 * Whether a Site Kit module is active.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	private static function module_active( $slug ) {
		// Search Console is always on in Site Kit; other modules are listed.
		if ( 'search-console' === $slug ) {
			return true;
		}
		$modules = get_option( 'googlesitekit_active_modules', array() );
		return is_array( $modules ) && in_array( $slug, $modules, true );
	}

	/**
	 * Whether Site Kit gives access to this site's Search Console data.
	 *
	 * @return bool
	 */
	public static function search_console_ready() {
		if ( ! self::active() ) {
			return false;
		}
		$settings = get_option( 'googlesitekit_search-console_settings', array() );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) && Search_Console::is_own_property( (string) $settings['propertyID'] );
	}

	/**
	 * The Search Console property Site Kit uses.
	 *
	 * @return string
	 */
	public static function property() {
		$settings = get_option( 'googlesitekit_search-console_settings', array() );
		return is_array( $settings ) && isset( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
	}

	/**
	 * Whether Site Kit's PageSpeed Insights module is on.
	 *
	 * @return bool
	 */
	public static function pagespeed_ready() {
		return self::active() && self::module_active( 'pagespeed-insights' );
	}

	/**
	 * GET a Site Kit REST route as the current user.
	 *
	 * @param string $route Route under /google-site-kit/v1/.
	 * @param array  $query Query parameters.
	 * @return mixed
	 * @throws Tool_Error When Site Kit refuses or fails.
	 */
	private static function get( $route, array $query ) {
		$request = new \WP_REST_Request( 'GET', self::NAMESPACE . ltrim( $route, '/' ) );
		$request->set_query_params( $query );
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		if ( $response->get_status() >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'no detail';
			throw new Tool_Error( 'Site Kit by Google answered ' . (int) $response->get_status() . ': ' . esc_html( Store::cut( $message, 300 ) ) . '. The connected account may need to sign in to Site Kit once in WordPress.' );
		}
		return $data;
	}

	/**
	 * Search analytics through Site Kit, in the shape of gsc_performance rows.
	 *
	 * @param string $start      Start date, Y-m-d.
	 * @param string $end        End date, Y-m-d.
	 * @param array  $dimensions Dimensions.
	 * @param int    $limit      Row limit.
	 * @param string $page       Page URL filter, optional.
	 * @return array[]
	 */
	public static function search_analytics( $start, $end, array $dimensions, $limit, $page = '' ) {
		$query = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => implode( ',', $dimensions ),
			'limit'      => (int) $limit,
		);
		if ( '' !== $page ) {
			$query['url'] = $page;
		}
		$data = self::get( 'modules/search-console/data/searchanalytics', $query );
		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : ( is_array( $data ) ? $data : array() );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['clicks'] ) ) {
				continue;
			}
			$out[] = array(
				'keys'        => isset( $row['keys'] ) ? (array) $row['keys'] : array(),
				'clicks'      => (int) $row['clicks'],
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? round( (float) $row['ctr'], 4 ) : 0,
				'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 1 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * A PageSpeed Insights report through Site Kit: the same JSON as the Google API.
	 *
	 * @param string $url      Page URL.
	 * @param string $strategy mobile or desktop.
	 * @return array
	 */
	public static function pagespeed( $url, $strategy ) {
		$data = self::get(
			'modules/pagespeed-insights/data/pagespeed',
			array(
				'url'      => $url,
				'strategy' => 'desktop' === strtolower( $strategy ) ? 'desktop' : 'mobile',
			)
		);
		return is_array( $data ) ? $data : array();
	}
}
