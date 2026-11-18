<?php
/**
 * Plugin documentation and capability discovery.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a description of an installed plugin from its headers, readme,
 * declared interfaces and a static scan of its source.
 */
class Plugin_Inspector {

	const SCAN_MAX_FILES = 800;
	const SCAN_MAX_BYTES = 1048576;
	const README_LIMIT   = 8000;

	/**
	 * Installed plugins and themes.
	 *
	 * @return array
	 */
	public static function extensions() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$updates = get_site_transient( 'update_plugins' );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'plugin'           => $file,
				'slug'             => self::slug( $file ),
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
			);
		}
		$theme_updates = get_site_transient( 'update_themes' );
		$active        = get_stylesheet();
		$themes        = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'slug'             => $stylesheet,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'active'           => $stylesheet === $active,
				'parent'           => $theme->parent() ? $theme->get_template() : null,
				'block_theme'      => $theme->is_block_theme(),
				'update_available' => isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ? $theme_updates->response[ $stylesheet ]['new_version'] : null,
			);
		}
		return array(
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Slug of a plugin file.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return string
	 */
	private static function slug( $file ) {
		return false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
	}

	/**
	 * Describe one plugin.
	 *
	 * @param string $slug        Slug or plugin file.
	 * @param bool   $include_org Also read its wordpress.org page.
	 * @return array
	 * @throws Tool_Error When it is not installed.
	 */
	public static function describe( $slug, $include_org ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = null;
		foreach ( array_keys( get_plugins() ) as $candidate ) {
			if ( $candidate === $slug || self::slug( $candidate ) === $slug ) {
				$file = $candidate;
				break;
			}
		}
		if ( ! $file ) {
			throw new Tool_Error( 'No installed plugin matches "' . esc_html( $slug ) . '". Use extensions_list to see the slugs.' );
		}
		$headers = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		$dir     = false === strpos( $file, '/' ) ? null : WP_PLUGIN_DIR . '/' . dirname( $file );

		$description = array(
			'content_trust'  => 'untrusted_site_content',
			'plugin'         => $file,
			'slug'           => self::slug( $file ),
			'name'           => $headers['Name'],
			'version'        => $headers['Version'],
			'active'         => is_plugin_active( $file ),
			'plugin_uri'     => $headers['PluginURI'],
			'summary'        => wp_strip_all_tags( $headers['Description'] ),
			'interfaces'     => array(
				'rest_routes' => $dir ? self::rest_routes_of( $dir ) : array(),
				'abilities'   => $dir ? self::abilities_of( $dir ) : array(),
			),
			'readme'         => $dir ? self::readme( $dir ) : '',
			'source_scan'    => $dir ? self::scan( $dir, (string) $headers['Version'] ) : array(),
			'option_names'   => self::option_names( self::slug( $file ) ),
			'reliability'    => array(
				'interfaces'   => 'declared by the plugin, the most reliable route',
				'option_names' => 'matched by name, may include unrelated options',
				'source_scan'  => 'found in source code, may be disabled or conditional',
				'readme'       => 'written by the plugin author, may be out of date',
			),
		);
		if ( $include_org ) {
			$description['wordpress_org'] = self::wordpress_org( self::slug( $file ) );
		}
		return $description;
	}

	/**
	 * Find which plugin directory a callback lives in.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_file( $callback ) {
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				return (string) ( new \ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
			}
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				return (string) ( new \ReflectionMethod( $class, $method ) )->getFileName();
			}
			if ( is_object( $callback ) && ! ( $callback instanceof \Closure ) ) {
				return (string) ( new \ReflectionMethod( $callback, '__invoke' ) )->getFileName();
			}
			if ( is_string( $callback ) || $callback instanceof \Closure ) {
				return (string) ( new \ReflectionFunction( $callback ) )->getFileName();
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		return '';
	}

	/**
	 * REST routes whose callbacks live in a directory.
	 *
	 * @param string $dir Plugin directory.
	 * @return array[]
	 */
	private static function rest_routes_of( $dir ) {
		$prefix = wp_normalize_path( $dir ) . '/';
		$routes = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['callback'] ) ) {
					continue;
				}
				if ( 0 === strpos( wp_normalize_path( self::callback_file( $handler['callback'] ) ), $prefix ) ) {
					if ( ! isset( $routes[ $route ] ) ) {
						$routes[ $route ] = array();
					}
					$routes[ $route ] = array_values( array_unique( array_merge( $routes[ $route ], array_keys( (array) $handler['methods'] ) ) ) );
				}
			}
		}
		$list = array();
		foreach ( $routes as $route => $methods ) {
			$list[] = array(
				'route'   => $route,
				'methods' => $methods,
			);
		}
		return array_slice( $list, 0, 150 );
	}

	/**
	 * Abilities whose callbacks live in a directory.
	 *
	 * @param string $dir Plugin directory.
	 * @return string[]
	 */
	private static function abilities_of( $dir ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$prefix = wp_normalize_path( $dir ) . '/';
		$names  = array();
		foreach ( wp_get_abilities() as $ability ) {
			$class = new \ReflectionClass( $ability );
			if ( $class->hasProperty( 'execute_callback' ) ) {
				$property = $class->getProperty( 'execute_callback' );
				$property->setAccessible( true );
				if ( 0 === strpos( wp_normalize_path( self::callback_file( $property->getValue( $ability ) ) ), $prefix ) ) {
					$names[] = $ability->get_name();
				}
			}
		}
		return $names;
	}

	/**
	 * Useful sections of readme.txt.
	 *
	 * @param string $dir Plugin directory.
	 * @return string
	 */
	private static function readme( $dir ) {
		foreach ( array( 'readme.txt', 'README.txt', 'readme.md', 'README.md' ) as $name ) {
			$path = $dir . '/' . $name;
			if ( is_readable( $path ) ) {
				$text = (string) file_get_contents( $path, false, null, 0, self::SCAN_MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$cut  = preg_split( '/^==\s*Changelog\s*==/mi', $text );
				return substr( $cut[0], 0, self::README_LIMIT );
			}
		}
		return '';
	}

	/**
	 * Static scan for AJAX actions, settings, screens and CLI commands. Cached per version.
	 *
	 * @param string $dir     Plugin directory.
	 * @param string $version Plugin version.
	 * @return array
	 */
	private static function scan( $dir, $version ) {
		$key    = 'samcp_scan_' . md5( $dir . '|' . $version );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found    = array(
			'ajax_actions'      => array(),
			'ajax_prefixes'     => array(),
			'nonce_actions'     => array(),
			'settings'          => array(),
			'options_in_source' => array(),
			'cli_commands'      => array(),
			'files_scanned'     => 0,
			'complete'          => true,
		);
		$patterns = array(
			'ajax_actions'      => '/[\'"]wp_ajax_(?:nopriv_)?([a-zA-Z0-9_-]+)[\'"]/',
			// Actions built at runtime, such as 'wp_ajax_wordfence_' . $name.
			'ajax_prefixes'     => '/[\'"]wp_ajax_(?:nopriv_)?([a-zA-Z0-9_-]+)[\'"]\s*\./',
			'nonce_actions'     => '/(?:wp_verify_nonce|check_ajax_referer|check_admin_referer|wp_create_nonce)\s*\(\s*(?:[^,()]+,\s*)?[\'"]([a-zA-Z0-9_ -]{2,80})[\'"]/',
			'settings'          => '/register_setting\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([^\'"]+)/',
			'options_in_source' => '/(?:get|update|add|delete)_option\s*\(\s*[\'"]([a-zA-Z0-9_-]{3,100})[\'"]/',
			'cli_commands'      => '/WP_CLI::add_command\s*\(\s*[\'"]([^\'"]+)/',
		);
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) || preg_match( '#[/\\\\](vendor|node_modules|tests?)[/\\\\]#', $file->getPathname() ) ) {
				continue;
			}
			if ( $found['files_scanned'] >= self::SCAN_MAX_FILES ) {
				$found['complete'] = false;
				break;
			}
			++$found['files_scanned'];
			$code = (string) file_get_contents( $file->getPathname(), false, null, 0, self::SCAN_MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( $patterns as $group => $pattern ) {
				if ( preg_match_all( $pattern, $code, $matches ) ) {
					$found[ $group ] = array_merge( $found[ $group ], $matches[1] );
				}
			}
		}
		foreach ( array_keys( $patterns ) as $group ) {
			$found[ $group ] = array_slice( array_values( array_unique( $found[ $group ] ) ), 0, 100 );
		}
		set_transient( $key, $found, WEEK_IN_SECONDS );
		return $found;
	}

	/**
	 * Option names that look like they belong to a plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return string[]
	 */
	private static function option_names( $slug ) {
		global $wpdb;
		$like = array( $slug, str_replace( '-', '_', $slug ) );
		$head = explode( '-', $slug );
		if ( strlen( $head[0] ) >= 4 ) {
			$like[] = $head[0];
		}
		$clauses = array();
		foreach ( array_unique( $like ) as $term ) {
			$clauses[] = $wpdb->prepare( 'option_name LIKE %s', $wpdb->esc_like( $term ) . '%' );
		}
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE (" . implode( ' OR ', $clauses ) . ") AND option_name NOT LIKE '\\_%' ORDER BY option_name LIMIT 100" ); // phpcs:ignore WordPress.DB -- each clause is prepared above and their number varies; no WP API lists option names
	}

	/**
	 * Public documentation from wordpress.org.
	 *
	 * @param string $slug Plugin slug.
	 * @return array
	 */
	private static function wordpress_org( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'     => true,
					'short_description' => true,
					'versions'     => false,
					'reviews'      => false,
					'banners'      => false,
					'icons'        => false,
					'screenshots'  => false,
					'contributors' => false,
				),
			)
		);
		if ( is_wp_error( $info ) ) {
			return array( 'found' => false );
		}
		$sections = array();
		foreach ( array( 'description', 'faq', 'installation' ) as $name ) {
			if ( ! empty( $info->sections[ $name ] ) ) {
				$sections[ $name ] = substr( wp_strip_all_tags( $info->sections[ $name ] ), 0, 4000 );
			}
		}
		return array(
			'found'          => true,
			'latest_version' => $info->version,
			'tested_up_to'   => isset( $info->tested ) ? $info->tested : null,
			'homepage'       => isset( $info->homepage ) ? $info->homepage : null,
			'sections'       => $sections,
		);
	}
}
