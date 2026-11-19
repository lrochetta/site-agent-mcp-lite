<?php
/**
 * MCP tool registry and the tools available with the wp:full scope.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/** A failure to report to the agent as a tool error. */
class Tool_Error extends \Exception {}

/**
 * Tools never grant rights of their own: WordPress capability checks decide
 * everything for the connected user.
 *
 * Tool titles, descriptions, results and errors are read by agents and stay in
 * English on purpose, whatever the site language: no gettext here.
 */
class Tools {

	const OUTPUT_LIMIT = 60000;
	const SECRET_NAME  = '/(pass(word|wd|phrase)?|pwd|secret|token|api[_-]?key|private[_-]?key|(consumer|client|access|site|stripe|recaptcha|encryption|signing|license|licence)[_-]?key|salt|licen[sc]e|auth[_-]?key|credential|bearer|jwt|session[_-]?id|webhook[_-]?url)/i';

	/** Values that are secrets whatever their name: private keys, JWTs and well-known API key formats. */
	const SECRET_VALUE = '/(-----BEGIN [A-Z ]*PRIVATE KEY-----|\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.|\b(sk|rk)_(live|test)_[A-Za-z0-9]{10,}|\bAIza[0-9A-Za-z_-]{30,}|\bgh[pousr]_[A-Za-z0-9]{30,}|\bxox[abprs]-[A-Za-z0-9-]{10,}|\bAKIA[0-9A-Z]{16}\b|\bglpat-[A-Za-z0-9_-]{20})/';

	/** Most routes rest_routes returns in one call; offset reads the rest. */
	const ROUTES_LIMIT = 300;

	/** WP-CLI commands without subcommands: the word after them is already a value. */
	const CLI_SINGLE_COMMANDS = array( 'eval', 'eval-file', 'search-replace', 'shell', 'import', 'export', 'find', 'help' );

	/** Options the Lite edition never sets directly: they decide which code runs. */
	const LITE_PROTECTED_OPTIONS = array( 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins', 'template', 'stylesheet', 'cron' );

	/**
	 * Tool definitions, built once.
	 *
	 * @var array|null
	 */
	private static $registry = null;

	/**
	 * Build a JSON Schema object.
	 *
	 * @param array    $properties Properties.
	 * @param string[] $required   Required names.
	 * @return array
	 */
	private static function schema( array $properties = array(), array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties ? $properties : new \stdClass(),
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Build a tool entry.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @param array  $schema      Input schema.
	 * @param string $mode        read, write, or destructive.
	 * @param string $handler     Callable.
	 * @param string $scope       Required scope.
	 * @param string $capability  Required capability.
	 * @param bool   $open_world  Whether the tool talks to a service outside this site.
	 * @return array
	 */
	private static function tool( $title, $description, array $schema, $mode, $handler, $scope = OAuth::SCOPE_FULL, $capability = 'read', $open_world = false ) {
		return array(
			'title'       => $title,
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => array(
				'title'           => $title,
				'readOnlyHint'    => 'read' === $mode,
				'destructiveHint' => 'destructive' === $mode,
				'idempotentHint'  => 'read' === $mode,
				'openWorldHint'   => (bool) $open_world,
			),
			'handler'     => $handler,
			'scope'       => $scope,
			'capability'  => $capability,
		);
	}

	/**
	 * All tools.
	 *
	 * @return array
	 */
	private static function registry() {
		if ( null !== self::$registry ) {
			return self::$registry;
		}
		$str  = array( 'type' => 'string' );
		$bool = array( 'type' => 'boolean' );
		$int  = array( 'type' => 'integer' );
		$obj  = array( 'type' => 'object' );
		$list = array(
			'type'  => 'array',
			'items' => $str,
		);
		$c    = __CLASS__ . '::';
		$full = 'full' === SAMCP_EDITION;

		// The Lite edition installs from wordpress.org only and has no server-level access.
		$manage_properties = array(
			'type'   => $str + array( 'enum' => array( 'plugin', 'theme', 'core' ) ),
			'action' => $str + array( 'enum' => array( 'install', 'update', 'activate', 'deactivate', 'delete' ) ),
			'slug'   => $str + array( 'description' => 'Plugin or theme slug. Not used for core.' ),
		);
		$manage_install    = 'Install takes a wordpress.org slug.';
		$manage_properties['force'] = $bool + array( 'description' => 'Required to deactivate or delete this MCP plugin, which ends this connection.' );
		$options_get_text           = $full
			? 'Read option values. Values under secret-looking names are hidden unless reveal_secrets is true, which needs server-level access.'
			: 'Read option values. Values under secret-looking names are always hidden in this edition.';
		$undo_text                  = $full
			? 'Restore the options and files of a journaled change. Plugin data kept in custom tables is not covered.'
			: 'Restore the options, SEO fields and site CSS of a journaled change. Plugin data kept in custom tables is not covered.';

		self::$registry = array(
			'site_overview'     => self::tool( 'Site overview', 'Start here. Site, WordPress and PHP versions, active theme and plugins, and the connected user with their roles and capabilities.', self::schema(), 'read', $c . 'site_overview' ),
			'extensions_list'   => self::tool( 'List plugins and themes', 'Installed plugins and themes with slug, version, active state and available update.', self::schema(), 'read', $c . 'extensions_list', OAuth::SCOPE_FULL, 'activate_plugins' ),
			'plugin_describe'   => self::tool( 'Describe a plugin', 'Everything known about how to use an installed plugin: REST routes, abilities, readme, AJAX actions, settings, admin screens, CLI commands and option names. Use it before working with an unfamiliar plugin.', self::schema( array( 'slug' => $str + array( 'description' => 'Plugin slug from extensions_list.' ), 'include_wordpress_org' => $bool + array( 'description' => 'Also read its public wordpress.org documentation.' ) ), array( 'slug' ) ), 'read', $c . 'plugin_describe', OAuth::SCOPE_FULL, 'activate_plugins' ),
			'extension_manage'  => self::tool( 'Manage plugins, themes and core', 'Install, update, activate, deactivate or delete a plugin or theme, or update WordPress core. ' . $manage_install, self::schema( $manage_properties, array( 'type', 'action' ) ), 'destructive', $c . 'extension_manage' ),

			'rest_routes'       => self::tool( 'Discover REST routes', 'REST routes of WordPress and every active plugin. Without filters, returns the namespaces. Filter by namespace or search text to get routes and methods, at most 300 per call: when truncated is true, call again with next_offset.', self::schema( array( 'namespace' => $str, 'search' => $str, 'include_args' => $bool + array( 'description' => 'Include each route\'s parameters.' ), 'offset' => $int + array( 'description' => 'Matching routes to skip, from next_offset.' ), 'limit' => $int + array( 'description' => 'Routes to return, 300 at most.' ) ) ), 'read', $c . 'rest_routes' ),
			'rest_call'         => self::tool( 'Call a REST route', 'Call any REST route, as this user, on WordPress or any plugin. Example: GET /wp/v2/posts. Templates and global styles are at /wp/v2/templates and /wp/v2/global-styles. To upload media, POST to /wp/v2/media with raw_body_base64, content_type and filename. A write to a post that someone else has open in the editor is refused unless force is true.', self::schema( array( 'method' => $str + array( 'enum' => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) ), 'route' => $str + array( 'description' => 'Route path such as /wp/v2/posts/12.' ), 'query' => $obj, 'body' => $obj + array( 'description' => 'JSON body.' ), 'raw_body_base64' => $str, 'content_type' => $str, 'filename' => $str, 'force' => $bool + array( 'description' => 'Write even though another person has the post open in the editor. Ask the user first.' ) ), array( 'method', 'route' ) ), 'destructive', $c . 'rest_call' ),

			'abilities_list'    => self::tool( 'List abilities', 'WordPress abilities registered by core and plugins, with their input schema. Abilities are the most reliable way to use a plugin when one exists.', self::schema( array( 'search' => $str ) ), 'read', $c . 'abilities_list' ),
			'ability_run'       => self::tool( 'Run an ability', 'Run a WordPress ability. Its own permission check applies.', self::schema( array( 'name' => $str, 'input' => $obj ), array( 'name' ) ), 'destructive', $c . 'ability_run' ),

			'options_search'    => self::tool( 'Search options', 'Find site options by name. Most plugins keep their settings in options.', self::schema( array( 'search' => $str, 'limit' => $int ), array( 'search' ) ), 'read', $c . 'options_search', OAuth::SCOPE_FULL, 'manage_options' ),
			'options_get'       => self::tool( 'Read options', $options_get_text, self::schema( array( 'names' => $list, 'reveal_secrets' => $bool ), array( 'names' ) ), 'read', $c . 'options_get', OAuth::SCOPE_FULL, 'manage_options' ),
			'options_update'    => self::tool( 'Change options', 'Set options, given as an object of name to value. The previous values are journaled for change_undo.', self::schema( array( 'changes' => $obj ), array( 'changes' ) ), 'destructive', $c . 'options_update', OAuth::SCOPE_FULL, 'manage_options' ),

			'admin_menu'        => self::tool( 'List admin screens', 'Admin menu entries visible to this user, including plugin screens.', self::schema(), 'read', $c . 'admin_menu' ),
			'admin_page_read'   => self::tool( 'Read an admin screen', 'Read an admin screen as structured data: forms with labels, help and values, notices, text, and the REST namespaces and AJAX actions it uses. Use it for plugins with no REST route, ability or documented option.', self::schema( array( 'page' => $str + array( 'description' => 'Admin path such as admin.php?page=wpseo_dashboard.' ) ), array( 'page' ) ), 'read', $c . 'admin_page_read' ),
			'admin_form_submit' => self::tool( 'Submit an admin form', 'Fill and send a form from an admin screen, starting from its current values and a fresh nonce. dry_run defaults to true and only shows the changes. Reports which options changed and returns a change_id for change_undo.', self::schema( array( 'page' => $str, 'form_index' => $int, 'values' => $obj + array( 'description' => 'Field name to new value. An array sets a multi-value field; null removes the field.' ), 'dry_run' => $bool, 'allow_unknown_fields' => $bool, 'submit' => $str + array( 'description' => 'Submit button to press, if several: its name, or name=value when buttons share a name.' ) ), array( 'page', 'form_index', 'values' ) ), 'destructive', $c . 'admin_form_submit' ),

			'page_fetch'        => self::tool( 'Inspect a public page', 'Fetch a page of this site and analyse it: status, response time, cache headers, title, meta tags, canonical, robots, Open Graph, hreflang, headings, JSON-LD, images without alt text and HTML. Use it for SEO, GEO, performance checks and to verify a change. It does not render JavaScript or produce screenshots.', self::schema( array( 'url' => $str + array( 'description' => 'URL or path on this site. Works for /robots.txt and /llms.txt.' ), 'as_user' => $bool + array( 'description' => 'Fetch logged in as this user. Default is anonymous, which is what visitors and caches see.' ), 'html_chars' => $int ), array( 'url' ) ), 'read', $c . 'page_fetch' ),
			'cache_flush'       => self::tool( 'Flush caches', 'Flush the object cache and the page cache of common cache plugins. Reports what was flushed.', self::schema(), 'write', $c . 'cache_flush', OAuth::SCOPE_FULL, 'manage_options' ),

			'change_log'        => self::tool( 'List recent changes', 'Changes journaled by these tools, with their change_id.', self::schema( array( 'limit' => $int ) ), 'read', $c . 'change_log', OAuth::SCOPE_FULL, 'manage_options' ),
			'change_undo'       => self::tool( 'Undo a change', $undo_text, self::schema( array( 'change_id' => $int ), array( 'change_id' ) ), 'destructive', $c . 'change_undo', OAuth::SCOPE_FULL, 'manage_options' ),
		);
		self::$registry += Seo_Tools::definitions( array( __CLASS__, 'seo_tool' ) );
		self::$registry += Search_Console::definitions( array( __CLASS__, 'seo_tool' ) );
		self::$registry += Security_Audit::definitions( array( __CLASS__, 'seo_tool' ) );
		self::$registry += Backup::definitions( array( __CLASS__, 'seo_tool' ) );
		return self::$registry;
	}

	/**
	 * Build an SEO tool entry.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @param array  $properties  Properties.
	 * @param array  $required    Required.
	 * @param string $mode        Mode.
	 * @param string $handler     Seo_Tools method.
	 * @param string $capability  Capability.
	 * @param bool   $open_world  Whether the tool talks to a service outside this site.
	 * @return array
	 */
	public static function seo_tool( $title, $description, array $properties, array $required, $mode, $handler, $capability, $open_world = false ) {
		$callable = false === strpos( $handler, '::' ) ? __NAMESPACE__ . '\\Seo_Tools::' . $handler : $handler;
		return self::tool( $title, $description, self::schema( $properties, $required ), $mode, $callable, OAuth::SCOPE_FULL, $capability, $open_world );
	}


	/**
	 * Whether a tool exists.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function exists( $name ) {
		return isset( self::registry()[ $name ] );
	}

	/**
	 * Whether a token and its user can use a tool right now.
	 *
	 * @param array $tool  Tool.
	 * @param array $token Token.
	 * @return bool
	 */
	private static function allowed( $name, array $tool, array $token ) {
		if ( OAuth::SCOPE_POWER === $tool['scope'] && ( ! OAuth::power_tools_enabled() || ! self::token_has_power( $token ) || 'full' !== Policy::token_level( $token ) ) ) {
			return false;
		}
		$level = Policy::token_level( $token );
		if ( 'none' === $level ) {
			return false;
		}
		// Read-only connections use read-only tools, plus REST GET and read-only abilities, checked when called.
		if ( 'read' === $level && empty( $tool['annotations']['readOnlyHint'] ) && ! in_array( $name, array( 'rest_call', 'ability_run' ), true ) ) {
			return false;
		}
		if ( 'extension_manage' === $name && ( ! Policy::extensions_allowed() || 'full' !== $level ) ) {
			return false;
		}
		return current_user_can( $tool['capability'] );
	}

	/**
	 * Whether a token includes the power scope.
	 *
	 * @param array $token Token.
	 * @return bool
	 */
	public static function token_has_power( array $token ) {
		return in_array( OAuth::SCOPE_POWER, explode( ' ', $token['scope'] ), true );
	}

	/**
	 * Whether a token has server-level access right now: the power scope, the site switch on,
	 * and the full level today. The scope alone is not enough, since the person or the
	 * settings may have lowered the connection since it was approved.
	 *
	 * @param array $token Token.
	 * @return bool
	 */
	public static function has_server_access( array $token ) {
		if ( ! $token || ! isset( $token['scope'], $token['user_id'] ) ) {
			return false;
		}
		$context = Policy::context_for( $token + array( 'family' => '' ) );
		return ! empty( $context['power'] );
	}

	/**
	 * Tools this token can use, without internal fields.
	 *
	 * @param array $token Token.
	 * @return array[]
	 */
	public static function definitions( array $token ) {
		$list = array();
		foreach ( self::registry() as $name => $tool ) {
			if ( self::allowed( $name, $tool, $token ) ) {
				$list[] = array(
					'name'        => $name,
					'title'       => $tool['title'],
					'description' => $tool['description'],
					'inputSchema' => $tool['inputSchema'],
					'annotations' => $tool['annotations'],
				);
			}
		}
		return $list;
	}

	/**
	 * Run a tool and shape its MCP result.
	 *
	 * @param string $name      Tool.
	 * @param array  $arguments Arguments.
	 * @param array  $token     Token.
	 * @return array
	 */
	public static function call( $name, array $arguments, array $token ) {
		$tool    = self::registry()[ $name ];
		$started = microtime( true );
		$failed  = false;

		// Plugins can echo notices during a call, which would corrupt the JSON response.
		// Server-level tools are written to the audit log before they run, so an exit or a fatal error still leaves a trace.
		$audit_id = OAuth::SCOPE_POWER === $tool['scope'] ? Store::audit( get_current_user_id(), $token['client_name'], $name, self::summarize( $arguments ) . ' [started]', true, 0 ) : 0;
		// No trace, no server-level tool.
		if ( OAuth::SCOPE_POWER === $tool['scope'] && ! $audit_id ) {
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => self::encode( array( 'error' => 'The activity log could not be written, so this server-level tool did not run.' ) ),
					),
				),
				'isError' => true,
			);
		}

		ob_start();
		try {
			if ( ! self::allowed( $name, $tool, $token ) ) {
				throw new Tool_Error( esc_html( self::denial( $name, $tool, $token ) ) );
			}
			// Every handler gets arguments of the shape its schema announces, whatever the client sent.
			self::validate_arguments( $tool['inputSchema'], $arguments );
			$output = call_user_func( $tool['handler'], $arguments, $token );
		} catch ( Tool_Error $e ) {
			$failed = true;
			$output = array( 'error' => $e->getMessage() );
		} catch ( \Throwable $e ) {
			$failed = true;
			$output = array( 'error' => 'The tool failed: ' . str_replace( array( ABSPATH, WP_CONTENT_DIR ), array( '', 'wp-content' ), $e->getMessage() ) );
		}
		ob_end_clean();

		if ( is_array( $output ) && ! empty( $output['_is_error'] ) ) {
			$failed = true;
		}
		if ( is_array( $output ) ) {
			unset( $output['_is_error'] );
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( $audit_id ) {
			Store::audit_finish( $audit_id, $failed, $duration, self::summarize( $arguments ) );
		} else {
			Store::audit( get_current_user_id(), $token['client_name'], $name, self::summarize( $arguments ), $failed, $duration );
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => self::encode( $output ),
				),
			),
			'isError' => $failed,
		);
	}

	/**
	 * Why a tool is refused, in words the agent can pass on.
	 *
	 * @param string $name  Tool.
	 * @param array  $tool  Tool.
	 * @param array  $token Token.
	 * @return string
	 */
	private static function denial( $name, array $tool, array $token ) {
		$level = Policy::token_level( $token );
		if ( OAuth::SCOPE_POWER === $tool['scope'] && ! self::token_has_power( $token ) ) {
			return 'This connection does not have server-level access. Reconnect and tick that option on the consent screen.';
		}
		if ( 'read' === $level ) {
			return 'This connection is read-only. Ask the user to reconnect with more access if the task needs changes.';
		}
		if ( 'extension_manage' === $name && ! Policy::extensions_allowed() ) {
			return 'This site does not let agents install, update, switch or remove plugins and themes. An administrator can allow it in Site Agent > Access.';
		}
		if ( 'content' === $level ) {
			return 'This connection works on content only. Ask the user to reconnect with more access if the task needs it.';
		}
		return 'Your WordPress account does not have the right to use this tool (' . $tool['capability'] . ').';
	}

	/**
	 * Check arguments against a tool's input schema before its handler runs: required
	 * properties, types, enum values and the items of arrays, nested objects included.
	 * Properties the schema does not list are left to the handler, as JSON Schema allows
	 * them. A null optional argument counts as omitted, since many clients send one.
	 *
	 * @param array $schema    Input schema.
	 * @param array $arguments Arguments.
	 * @throws Tool_Error Naming every mismatch, five at most.
	 */
	public static function validate_arguments( array $schema, array $arguments ) {
		$problems = self::schema_problems( $schema, $arguments, '' );
		if ( $problems ) {
			$more = count( $problems ) > 5 ? ' (and ' . ( count( $problems ) - 5 ) . ' more)' : '';
			// Plain words only (no quotes or brackets of HTML), so escaping changes nothing an agent reads.
			throw new Tool_Error( esc_html( 'Invalid arguments, nothing was done: ' . implode( '; ', array_slice( $problems, 0, 5 ) ) . $more . '. The expected arguments are in the inputSchema of this tool (tools/list).' ) );
		}
	}

	/**
	 * Mismatches between a value and a schema.
	 *
	 * @param array  $schema Schema.
	 * @param mixed  $value  Value.
	 * @param string $path   Where the value sits, empty for the arguments themselves.
	 * @return string[]
	 */
	private static function schema_problems( array $schema, $value, $path ) {
		$label = '' === $path ? 'the arguments' : $path;
		if ( isset( $schema['type'] ) && ! self::has_type( $value, $schema['type'] ) ) {
			return array( $label . ' must be ' . implode( ' or ', (array) $schema['type'] ) . ', not ' . self::type_of( $value ) );
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return array( $label . ' must be one of ' . implode( ', ', array_map( 'strval', $schema['enum'] ) ) );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$problems = array();
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) && self::is_list( $value ) ) {
			foreach ( $value as $index => $item ) {
				$problems = array_merge( $problems, self::schema_problems( $schema['items'], $item, $label . '[' . $index . ']' ) );
			}
		}
		if ( ( $value && self::is_list( $value ) ) || ! ( isset( $schema['properties'] ) || isset( $schema['required'] ) ) ) {
			return $problems;
		}
		$prefix = '' === $path ? '' : $path . '.';
		foreach ( isset( $schema['required'] ) ? (array) $schema['required'] : array() as $name ) {
			if ( ! isset( $value[ $name ] ) ) {
				$problems[] = $prefix . $name . ' is required';
			}
		}
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( $properties as $name => $property ) {
			if ( isset( $value[ $name ] ) && is_array( $property ) ) {
				$problems = array_merge( $problems, self::schema_problems( $property, $value[ $name ], $prefix . $name ) );
			}
		}
		return $problems;
	}

	/**
	 * Whether a decoded JSON value has one of the JSON Schema types. JSON objects and arrays
	 * both decode to PHP arrays: an object has keys, an array has none or 0, 1, 2...
	 *
	 * @param mixed           $value Value.
	 * @param string|string[] $types Type or types.
	 * @return bool
	 */
	private static function has_type( $value, $types ) {
		foreach ( (array) $types as $type ) {
			switch ( $type ) {
				case 'string':
					$ok = is_string( $value );
					break;
				case 'integer':
					// 5.0 is an integer in JSON Schema; 5.5 and "5" are not.
					$ok = is_int( $value ) || ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value );
					break;
				case 'number':
					$ok = is_int( $value ) || is_float( $value );
					break;
				case 'boolean':
					$ok = is_bool( $value );
					break;
				case 'object':
					$ok = is_array( $value ) && ( ! $value || ! self::is_list( $value ) );
					break;
				case 'array':
					$ok = is_array( $value ) && self::is_list( $value );
					break;
				case 'null':
					$ok = null === $value;
					break;
				default:
					$ok = true;
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * JSON Schema type name of a decoded value, for error messages.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function type_of( $value ) {
		if ( is_array( $value ) ) {
			return $value && ! self::is_list( $value ) ? 'object' : 'array';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		return null === $value ? 'null' : 'string';
	}

	/**
	 * Whether an array has the keys 0, 1, 2... in order (PHP 7.4 has no array_is_list).
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Encode a result, truncating to what clients accept.
	 *
	 * @param mixed $output Output.
	 * @return string
	 */
	private static function encode( $output ) {
		$text = self::json( $output );
		if ( strlen( $text ) <= self::OUTPUT_LIMIT ) {
			return $text;
		}
		// The result stays one valid JSON document: the bulky parts are cut before encoding, while
		// small fields such as status, change_id and options_changed are kept whole.
		$note = 'Truncated to fit ' . self::OUTPUT_LIMIT . ' bytes: part of this result is missing. Read it again asking for less: use filters, _fields, per_page, a smaller limit or length, or a smaller range.';
		if ( ! is_array( $output ) || ( $output && array_values( $output ) === $output ) ) {
			$output = array( 'result' => $output );
		}
		// A tool's own paging describes the whole answer, not the part kept here: following it would skip what was cut.
		unset( $output['next_offset'], $output['hint'] );
		// The encoder's flag comes first, so it wins over a tool's own "truncated": false.
		$flag   = array(
			'truncated'      => true,
			'truncated_note' => $note,
		);
		$fitted = self::fit( $output, self::OUTPUT_LIMIT - 600 );
		$fitted = is_array( $fitted ) ? $fitted : array();
		$text   = self::json( $flag + $fitted );
		if ( strlen( $text ) > self::OUTPUT_LIMIT ) {
			// Last resort: the scalar fields only.
			$text = self::json( $flag + array_filter( $output, 'is_scalar' ) );
		}
		return $text;
	}

	/**
	 * JSON text of a value, as sent to clients.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function json( $value ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/**
	 * Shrink a value until its JSON fits a byte budget: long strings are cut, lists lose their
	 * last items, and objects shrink their largest members first, so small fields survive.
	 *
	 * @param mixed $value Value.
	 * @param int   $room  Bytes of JSON the value may take.
	 * @return mixed
	 */
	private static function fit( $value, $room ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		$size = strlen( self::json( $value ) );
		if ( $size <= $room ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			// Escaping makes JSON longer than the text: scale the cut, the caller checks again.
			return Store::cut( $value, max( 0, (int) floor( strlen( $value ) * ( $room - 2 ) / $size ) ) );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( array_values( $value ) === $value ) {
			$kept = array();
			$used = 2;
			foreach ( $value as $item ) {
				$item_size = strlen( self::json( $item ) ) + 1;
				if ( $used + $item_size > $room ) {
					if ( ! $kept && $room - $used > 2 ) {
						$kept[] = self::fit( $item, $room - $used - 1 );
					}
					break;
				}
				$kept[] = $item;
				$used  += $item_size;
			}
			return $kept;
		}
		for ( $round = 0; $round < 50 && $size > $room; $round++ ) {
			$largest      = null;
			$largest_size = 0;
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $item ) && ! is_array( $item ) && ! is_object( $item ) ) {
					continue;
				}
				$item_size = strlen( self::json( $item ) );
				if ( $item_size > $largest_size ) {
					$largest      = $key;
					$largest_size = $item_size;
				}
			}
			if ( null === $largest || $largest_size <= 2 ) {
				break;
			}
			$value[ $largest ] = self::fit( $value[ $largest ], max( 0, $largest_size - ( $size - $room ) ) );
			$new_size          = strlen( self::json( $value ) );
			if ( $new_size >= $size ) {
				break;
			}
			$size = $new_size;
		}
		return $value;
	}

	/**
	 * Short audit text that leaves out content and secrets.
	 *
	 * @param array $arguments Arguments.
	 * @return string
	 */
	private static function summarize( array $arguments ) {
		$safe  = array( 'path', 'route', 'method', 'slug', 'page', 'type', 'action', 'name', 'url', 'form_index', 'dry_run', 'change_id', 'search', 'namespace', 'as_user', 'force', 'post_id', 'strategy', 'mode', 'topic', 'test_id' );
		$parts = array();
		foreach ( $arguments as $key => $value ) {
			if ( 'args' === $key && is_array( $value ) ) {
				$parts[] = 'args=' . Store::cut( self::summarize_cli( $value ), 200 );
			} elseif ( in_array( $key, array( 'sql', 'code', 'args' ), true ) ) {
				$text = is_string( $value ) ? $value : (string) wp_json_encode( $value );
				// Quoted literals can hold secrets: mask them and keep the statement's shape for the audit log.
				$text    = (string) preg_replace( '/(["\'])(?:\\\\.|(?!\1).)*\1/s', "'…'", $text );
				$parts[] = $key . '=' . Store::cut( $text, 200 );
			} elseif ( in_array( $key, $safe, true ) && is_scalar( $value ) ) {
				$text = (string) $value;
				if ( in_array( $key, array( 'path', 'url', 'route', 'page' ), true ) ) {
					$text = strtok( $text, '?#' );
				}
				$parts[] = $key . '=' . Store::cut( (string) $text, 120 );
			} else {
				$parts[] = $key . '=[omitted]';
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * WP-CLI arguments for the audit log: the command and the names of its options, never
	 * a value. Positional values (a login, an e-mail, a password) and option values are masked.
	 *
	 * @param array $words Arguments, without the wp prefix.
	 * @return string
	 */
	private static function summarize_cli( array $words ) {
		$out     = array();
		$command = 0;
		foreach ( array_values( $words ) as $word ) {
			$word = is_scalar( $word ) ? (string) $word : '';
			if ( 0 === strpos( $word, '-' ) ) {
				// An option: its name stays, its value does not.
				$equals = strpos( $word, '=' );
				$name   = false === $equals ? $word : substr( $word, 0, $equals );
				$out[]  = preg_match( '/^--?[A-Za-z0-9_-]+$/', $name ) ? $name . ( false === $equals ? '' : '=…' ) : '…';
			} elseif ( $command < 2 && preg_match( '/^[a-z][a-z0-9_-]*$/', $word ) ) {
				// The command and its subcommand, as in "user create" or "option get". A command
				// without subcommands takes values next ("search-replace old new"): they are masked.
				$out[]   = $word;
				$command = 0 === $command && in_array( $word, self::CLI_SINGLE_COMMANDS, true ) ? 2 : $command + 1;
			} else {
				$out[]   = '…';
				$command = 2;
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Whether a name looks like it holds a secret.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_secret_name( $name ) {
		return (bool) preg_match( self::SECRET_NAME, $name );
	}

	/**
	 * Hide values under secret-looking keys, at any depth.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_string( $value ) ) {
			// A private key hides the whole value; other secrets are cut out of the text around them.
			if ( false !== strpos( $value, 'PRIVATE KEY-----' ) ) {
				return '[hidden]';
			}
			return (string) preg_replace( self::SECRET_VALUE, '[hidden]', $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::is_secret_name( $key ) && ! is_bool( $item ) && null !== $item && '' !== $item && array() !== $item ) {
				$value[ $key ] = '[hidden]';
			} else {
				$value[ $key ] = self::redact( $item );
			}
		}
		return $value;
	}

	/**
	 * Site overview.
	 *
	 * @param array $args  Arguments.
	 * @param array $token Token.
	 * @return array
	 */
	public static function site_overview( array $args, array $token ) {
		global $wp_version;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$user   = wp_get_current_user();
		$theme  = wp_get_theme();
		$active = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $data['Name'] . ' ' . $data['Version'];
			}
		}
		$user_section = array(
			'id'           => $user->ID,
			'login'        => $user->user_login,
			'roles'        => array_values( $user->roles ),
			// Effective capabilities: map_meta_cap removes e.g. edit_files under DISALLOW_FILE_EDIT.
			'capabilities' => array_values( array_filter( array_keys( array_filter( $user->allcaps ) ), 'current_user_can' ) ),
		);
		// The same decision as tools/list: level, site switch and the edit_files right (DISALLOW_FILE_EDIT removes it).
		$registry     = self::registry();
		$connection   = array(
			'client'             => $token['client_name'],
			'scope'              => $token['scope'],
			'server_level_tools' => isset( $registry['php_run'] ) && self::allowed( 'php_run', $registry['php_run'], $token ),
			'access_level'       => Policy::token_level( $token ),
			// ok, missing or unknown: whether this server passes the Authorization header to WordPress.
			'authorization_header' => Header_Check::cached(),
		);
		// Versions help an attacker, and WordPress does not show them to low-privilege accounts either.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array(
				'site'       => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url(),
				),
				'user'       => $user_section,
				'connection' => $connection,
			);
		}
		return array(
			'site'       => array(
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url(),
				'locale'      => get_locale(),
				'multisite'   => is_multisite(),
				'environment' => wp_get_environment_type(),
				'permalinks'  => get_option( 'permalink_structure' ),
			),
			'runtime'    => array(
				'wordpress'            => $wp_version,
				'php'                  => PHP_VERSION,
				'server'               => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'object_cache'         => wp_using_ext_object_cache(),
				'file_edit_disallowed' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'file_mods_disallowed' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
				'html_restrictions'    => current_user_can( 'unfiltered_html' )
					? 'none: this account can save style and script tags in content'
					: 'WordPress removes style, script and similar tags from content this account saves. Use custom_css_update for CSS.',
			),
			'theme'      => array(
				'name'        => $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'parent'      => $theme->parent() ? $theme->get_template() : null,
				'block_theme' => $theme->is_block_theme(),
			),
			'plugins'    => $active,
			'user'       => $user_section,
			'connection' => $connection,
			'security'   => current_user_can( 'manage_options' ) ? Security_Audit::overview_hint() : null,
		);
	}

	/**
	 * List extensions.
	 *
	 * @return array
	 */
	public static function extensions_list() {
		return Plugin_Inspector::extensions();
	}

	/**
	 * Describe a plugin.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function plugin_describe( array $args ) {
		return Plugin_Inspector::describe( self::required( $args, 'slug' ), ! empty( $args['include_wordpress_org'] ) );
	}

	/**
	 * Read a required string argument.
	 *
	 * @param array  $args Arguments.
	 * @param string $key  Name.
	 * @return string
	 * @throws Tool_Error When missing.
	 */
	private static function required( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || ! is_string( $args[ $key ] ) || '' === $args[ $key ] ) {
			throw new Tool_Error( 'Argument "' . esc_html( $key ) . '" is required.' );
		}
		return $args[ $key ];
	}

	/**
	 * Require a capability.
	 *
	 * @param string $capability Capability.
	 * @throws Tool_Error When the user lacks it.
	 */
	private static function require_cap( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			throw new Tool_Error( 'Your WordPress account does not have the right to do this (' . esc_html( $capability ) . ').' );
		}
	}

	/**
	 * Install, update, activate, deactivate or delete an extension.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On refusal or failure.
	 */
	public static function extension_manage( array $args, array $token = array() ) {
		if ( ! Policy::extensions_allowed() ) {
			throw new Tool_Error( 'This site does not let agents install, update, switch or remove plugins and themes. An administrator can allow it in Site Agent > Access.' );
		}
		// WordPress's own installer, activation and theme switch run here; only this path may change which code runs.
		return Policy::internal(
			static function () use ( $args ) {
				return self::extension_manage_run( $args );
			}
		);
	}

	/**
	 * Run extension_manage once the checks passed.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On refusal or failure.
	 */
	private static function extension_manage_run( array $args ) {
		$type   = self::required( $args, 'type' );
		$action = self::required( $args, 'action' );
		$slug   = isset( $args['slug'] ) ? (string) $args['slug'] : '';

		foreach ( array( 'file', 'misc', 'plugin', 'theme', 'update', 'class-wp-upgrader', 'plugin-install', 'theme-install' ) as $include ) {
			require_once ABSPATH . 'wp-admin/includes/' . $include . '.php';
		}
		wp_cache_flush();

		if ( 'core' === $type ) {
			return self::update_core( $action );
		}

		$skin = new \WP_Ajax_Upgrader_Skin();
		if ( 'plugin' === $type && in_array( $action, array( 'deactivate', 'delete' ), true ) && self::plugin_file( $slug ) === plugin_basename( SAMCP_FILE ) && empty( $args['force'] ) ) {
			throw new Tool_Error( 'This would disconnect every agent from the site. Set force to true if that is really what you want.' );
		}
		// delete_theme() builds a path from its argument, so only an installed theme's own name may reach it.
		if ( 'theme' === $type && 'install' !== $action && ! array_key_exists( $slug, wp_get_themes() ) ) {
			throw new Tool_Error( 'No installed theme matches "' . esc_html( $slug ) . '". Use extensions_list to see the slugs.' );
		}
		if ( 'theme' === $type && 'delete' === $action && ( get_stylesheet() === $slug || get_template() === $slug ) ) {
			throw new Tool_Error( 'This theme is in use. Activate another theme first.' );
		}

		if ( 'plugin' === $type ) {
			$upgrader = new \Plugin_Upgrader( $skin );
			switch ( $action ) {
				case 'install':
					self::require_cap( 'install_plugins' );
					$package = self::package( $args, $slug, 'plugin' );
					$result  = $upgrader->install( $package );
					break;
				case 'update':
					self::require_cap( 'update_plugins' );
					wp_update_plugins();
					$result = $upgrader->upgrade( self::plugin_file( $slug ) );
					break;
				case 'activate':
					self::require_cap( 'activate_plugins' );
					$result = activate_plugin( self::plugin_file( $slug ) );
					$result = is_wp_error( $result ) ? $result : true;
					break;
				case 'deactivate':
					self::require_cap( 'activate_plugins' );
					deactivate_plugins( self::plugin_file( $slug ) );
					$result = true;
					break;
				case 'delete':
					self::require_cap( 'delete_plugins' );
					$result = delete_plugins( array( self::plugin_file( $slug ) ) );
					break;
				default:
					throw new Tool_Error( 'Unknown action.' );
			}
		} elseif ( 'theme' === $type ) {
			$upgrader = new \Theme_Upgrader( $skin );
			switch ( $action ) {
				case 'install':
					self::require_cap( 'install_themes' );
					$result = $upgrader->install( self::package( $args, $slug, 'theme' ) );
					break;
				case 'update':
					self::require_cap( 'update_themes' );
					wp_update_themes();
					$result = $upgrader->upgrade( $slug );
					break;
				case 'activate':
					self::require_cap( 'switch_themes' );
					switch_theme( $slug );
					$result = true;
					break;
				case 'delete':
					self::require_cap( 'delete_themes' );
					$result = delete_theme( $slug );
					break;
				default:
					throw new Tool_Error( 'Unknown action for a theme.' );
			}
		} else {
			throw new Tool_Error( 'Unknown type.' );
		}

		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( esc_html( $result->get_error_message() ) );
		}
		if ( is_wp_error( $skin->result ) ) {
			throw new Tool_Error( esc_html( $skin->result->get_error_message() ) );
		}
		if ( $skin->get_errors()->has_errors() ) {
			throw new Tool_Error( esc_html( $skin->get_error_messages() ) );
		}
		if ( false === $result || null === $result ) {
			throw new Tool_Error( 'The operation failed, often because WordPress cannot write to its files on this server.' );
		}
		wp_cache_flush();
		return array(
			'type'   => $type,
			'action' => $action,
			'slug'   => $slug,
			'done'   => true,
		);
	}

	/**
	 * Update WordPress core to the version offered by the update check.
	 *
	 * @param string $action Must be update.
	 * @return array
	 * @throws Tool_Error On refusal or failure.
	 */
	private static function update_core( $action ) {
		if ( 'update' !== $action ) {
			throw new Tool_Error( 'Core only supports the update action.' );
		}
		self::require_cap( 'update_core' );
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		wp_version_check( array(), true );
		$offer = get_preferred_from_update_core();
		if ( ! $offer || 'latest' === $offer->response ) {
			return array(
				'done'   => false,
				'detail' => 'WordPress is already up to date.',
			);
		}
		$upgrader = new \Core_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $offer );
		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( esc_html( $result->get_error_message() ) );
		}
		return array(
			'done'    => true,
			'version' => $result,
		);
	}

	/**
	 * Find a package to install.
	 *
	 * @param array  $args Arguments.
	 * @param string $slug Slug.
	 * @param string $type plugin or theme.
	 * @return string
	 * @throws Tool_Error When not found.
	 */
	private static function package( array $args, $slug, $type ) {
		if ( ! empty( $args['package_url'] ) ) {
			throw new Tool_Error( 'This edition installs plugins and themes from wordpress.org only, by slug.' );
		}
		if ( '' === $slug ) {
			throw new Tool_Error( 'full' === SAMCP_EDITION ? 'slug or package_url is required.' : 'slug is required.' );
		}
		$request = array(
			'slug'   => $slug,
			'fields' => array( 'sections' => false ),
		);
		$info    = 'plugin' === $type ? plugins_api( 'plugin_information', $request ) : themes_api( 'theme_information', $request );
		if ( is_wp_error( $info ) ) {
			throw new Tool_Error( 'wordpress.org has no ' . esc_html( $type ) . ' with slug "' . esc_html( $slug ) . '".' );
		}
		return $info->download_link;
	}

	/**
	 * Find a plugin file from a slug.
	 *
	 * @param string $slug Slug or plugin file.
	 * @return string
	 * @throws Tool_Error When not installed.
	 */
	private static function plugin_file( $slug ) {
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( $file === $slug || dirname( $file ) === $slug || basename( $file, '.php' ) === $slug ) {
				return $file;
			}
		}
		throw new Tool_Error( 'No installed plugin matches "' . esc_html( $slug ) . '".' );
	}

	/**
	 * Discover REST routes.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function rest_routes( array $args ) {
		$server    = rest_get_server();
		$namespace = isset( $args['namespace'] ) ? trim( (string) $args['namespace'], '/' ) : '';
		$search    = isset( $args['search'] ) ? strtolower( (string) $args['search'] ) : '';
		if ( '' === $namespace && '' === $search ) {
			return array(
				'namespaces' => $server->get_namespaces(),
				'hint'       => 'Call again with a namespace or search to see routes.',
			);
		}
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit   = isset( $args['limit'] ) ? min( self::ROUTES_LIMIT, max( 1, (int) $args['limit'] ) ) : self::ROUTES_LIMIT;
		$routes  = array();
		$matched = 0;
		// The list stops before the answer outgrows what clients accept, so next_offset
		// points at the first route really left out, never past routes cut on the way out.
		$budget = self::OUTPUT_LIMIT - 5000;
		$used   = 200;
		$full   = false;
		foreach ( $server->get_routes( $namespace ) as $route => $handlers ) {
			if ( '' !== $search && false === strpos( strtolower( $route ), $search ) ) {
				continue;
			}
			// Every match is counted, so the agent knows how many it has not seen yet.
			++$matched;
			if ( $matched <= $offset || $full || count( $routes ) >= $limit ) {
				continue;
			}
			$entry = array(
				'route'   => $route,
				'methods' => array(),
			);
			foreach ( (array) $handlers as $handler ) {
				$entry['methods'] = array_merge( $entry['methods'], array_keys( (array) $handler['methods'] ) );
				if ( ! empty( $args['include_args'] ) && ! empty( $handler['args'] ) ) {
					foreach ( $handler['args'] as $name => $definition ) {
						$entry['args'][ $name ] = array_intersect_key( (array) $definition, array_flip( array( 'type', 'required', 'description', 'enum', 'default' ) ) );
					}
				}
			}
			$entry['methods'] = array_values( array_unique( $entry['methods'] ) );
			$size             = strlen( self::json( $entry ) ) + 1;
			if ( $routes && $used + $size > $budget ) {
				$full = true;
				continue;
			}
			if ( ! $routes && $used + $size > $budget && isset( $entry['args'] ) ) {
				// A single route too large to send whole: its methods come without the parameters.
				unset( $entry['args'] );
				$entry['args_omitted'] = true;
				$size                  = strlen( self::json( $entry ) ) + 1;
			}
			$routes[] = $entry;
			$used    += $size;
		}
		$result = array(
			'routes'    => $routes,
			'total'     => $matched,
			'truncated' => $offset + count( $routes ) < $matched,
		);
		if ( $result['truncated'] ) {
			$result['next_offset'] = $offset + count( $routes );
			$result['hint']        = 'More routes match. Call again with offset ' . $result['next_offset'] . ', or narrow with namespace or search.';
		}
		return $result;
	}

	/**
	 * Call a REST route.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function rest_call( array $args, array $token = array() ) {
		$method = strtoupper( self::required( $args, 'method' ) );
		$route  = '/' . ltrim( self::required( $args, 'route' ), '/' );
		if ( 'GET' !== $method && $token && 'read' === Policy::token_level( $token ) ) {
			throw new Tool_Error( 'This connection is read-only: only GET requests are allowed.' );
		}
		if ( 'GET' !== $method ) {
			self::assert_route_not_locked( $route, $args );
		}

		$request = new \WP_REST_Request( $method, $route );
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$request->set_query_params( $args['query'] );
		}
		if ( ! empty( $args['raw_body_base64'] ) ) {
			$request->set_body( (string) base64_decode( (string) $args['raw_body_base64'], true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$request->set_header( 'content-type', isset( $args['content_type'] ) ? (string) $args['content_type'] : 'application/octet-stream' );
			if ( ! empty( $args['filename'] ) ) {
				$request->set_header( 'content-disposition', 'attachment; filename="' . sanitize_file_name( (string) $args['filename'] ) . '"' );
			}
		} elseif ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $args['body'] ) );
		}

		$snapshot = 'GET' === $method ? null : Journal::snapshot_options();
		$change   = array(
			'id'    => null,
			'names' => array(),
		);
		try {
			$response = rest_do_request( $request );
		} finally {
			if ( $snapshot ) {
				$change = Journal::record_option_diff( $snapshot, 'rest_call', $method . ' ' . strtok( $route, '?' ) );
			}
		}
		$server  = rest_get_server();
		$embed   = isset( $args['query']['_embed'] ) ? $args['query']['_embed'] : false;
		$data    = $server->response_to_data( $response, $embed );
		$status  = $response->get_status();
		$headers = array_intersect_key( $response->get_headers(), array_flip( array( 'X-WP-Total', 'X-WP-TotalPages', 'Location', 'Allow' ) ) );
		$result  = array(
			'_is_error'     => $status >= 400,
			'content_trust' => 'untrusted_site_content',
			'status'        => $status,
			'headers'       => $headers,
			'data'          => self::redact( $data ),
		);
		if ( $change['names'] ) {
			$result['options_changed'] = $change['names'];
			$result['change_id']       = $change['id'];
		}
		$warning  = self::removed_tags_warning( $args, $data );
		if ( '' !== $warning ) {
			$result['warning'] = $warning;
		}
		return $result;
	}

	/**
	 * Refuse a REST write to a post that another person has open in the editor, unless forced.
	 * Only routes of the form /wp/v2/<rest base>/<id> whose base is the post's own type count.
	 *
	 * @param string $route REST route.
	 * @param array  $args  Tool arguments, where force may be true.
	 * @throws Tool_Error When the post is locked by someone else.
	 */
	private static function assert_route_not_locked( $route, array $args ) {
		if ( ! preg_match( '#^/wp/v2/([a-z0-9_-]+)/(\d+)(?:/|$)#', (string) strtok( $route, '?' ), $match ) ) {
			return;
		}
		$post = get_post( (int) $match[2] );
		if ( ! $post ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		$base = $type && ! empty( $type->rest_base ) ? $type->rest_base : $post->post_type;
		if ( $base !== $match[1] ) {
			return;
		}
		Seo_Tools::assert_not_locked( (int) $post->ID, $args );
	}

	/**
	 * Say so when WordPress dropped tags from saved content: the save still answers 200.
	 *
	 * @param array $args Tool arguments.
	 * @param mixed $data REST response data.
	 * @return string
	 */
	private static function removed_tags_warning( array $args, $data ) {
		if ( ! isset( $args['body']['content'] ) || ! is_string( $args['body']['content'] ) || ! is_array( $data ) ) {
			return '';
		}
		$saved = '';
		if ( isset( $data['content']['raw'] ) ) {
			$saved = (string) $data['content']['raw'];
		} elseif ( isset( $data['content']['rendered'] ) ) {
			$saved = (string) $data['content']['rendered'];
		} else {
			return '';
		}
		$lost = array();
		foreach ( array( 'style', 'script', 'iframe', 'form', 'input', 'svg' ) as $tag ) {
			$pattern = '/<' . $tag . '\b/i';
			if ( preg_match_all( $pattern, $args['body']['content'] ) > preg_match_all( $pattern, $saved ) ) {
				$lost[] = $tag;
			}
		}
		if ( ! $lost ) {
			return '';
		}
		return 'The save succeeded but WordPress removed these tags from the content: ' . implode( ', ', $lost ) . '. Treat this as a failed save: their contents may remain as visible text. Put CSS in custom_css_update, remove the leftovers from the content, then run design_check. See seo_guide topic page_design.';
	}

	/**
	 * List abilities.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error When the API is missing.
	 */
	public static function abilities_list( array $args ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			throw new Tool_Error( 'This WordPress version has no Abilities API.' );
		}
		$search = isset( $args['search'] ) ? strtolower( (string) $args['search'] ) : '';
		$list   = array();
		foreach ( wp_get_abilities() as $ability ) {
			$text = strtolower( $ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description() );
			if ( '' !== $search && false === strpos( $text, $search ) ) {
				continue;
			}
			$list[] = array(
				'name'         => $ability->get_name(),
				'label'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'category'     => $ability->get_category(),
				'input_schema' => $ability->get_input_schema(),
				'annotations'  => $ability->get_meta_item( 'annotations', null ),
			);
		}
		return array( 'abilities' => $list );
	}

	/**
	 * Run an ability.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error When it is unknown or fails.
	 */
	public static function ability_run( array $args, array $token = array() ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			throw new Tool_Error( 'This WordPress version has no Abilities API.' );
		}
		$ability = wp_get_ability( self::required( $args, 'name' ) );
		if ( ! $ability ) {
			throw new Tool_Error( 'Unknown ability. Use abilities_list.' );
		}
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		$read_only   = ! empty( $annotations['readonly'] );
		if ( $token && 'read' === Policy::token_level( $token ) && ! $read_only ) {
			throw new Tool_Error( 'This connection is read-only: only abilities marked read-only can run.' );
		}
		$snapshot = $read_only ? null : Journal::snapshot_options();
		$change   = array(
			'id'    => null,
			'names' => array(),
		);
		try {
			$result = $ability->execute( isset( $args['input'] ) ? $args['input'] : null );
		} finally {
			if ( $snapshot ) {
				$change = Journal::record_option_diff( $snapshot, 'ability_run', $ability->get_name() );
			}
		}
		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( esc_html( $result->get_error_message() ) );
		}
		$output = array(
			'content_trust' => 'untrusted_site_content',
			'result'        => self::redact( $result ),
		);
		if ( $change['names'] ) {
			$output['options_changed'] = $change['names'];
			$output['change_id']       = $change['id'];
		}
		return $output;
	}

	/**
	 * Search options.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function options_search( array $args ) {
		global $wpdb;
		$limit = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- searching option names, no WP API exists
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d',
				$wpdb->options,
				'%' . $wpdb->esc_like( self::required( $args, 'search' ) ) . '%',
				$wpdb->esc_like( '_transient' ) . '%',
				$limit
			)
		);
		return array( 'names' => $names );
	}

	/**
	 * Read options.
	 *
	 * @param array $args  Arguments.
	 * @param array $token Token.
	 * @return array
	 * @throws Tool_Error When revealing is not allowed.
	 */
	public static function options_get( array $args, array $token ) {
		$reveal = ! empty( $args['reveal_secrets'] );
		if ( $reveal && 'full' !== SAMCP_EDITION ) {
			throw new Tool_Error( 'reveal_secrets is not available in this edition: secret values stay hidden.' );
		}
		// The access the connection has today, not the scope it was approved with: a lowered account loses it at once.
		if ( $reveal && ! self::has_server_access( $token ) ) {
			throw new Tool_Error( 'reveal_secrets needs server-level access with the full level. Ask the user to reconnect with full access and tick that option on the consent screen.' );
		}
		$values = array();
		foreach ( (array) ( isset( $args['names'] ) ? $args['names'] : array() ) as $name ) {
			$name  = (string) $name;
			$value = get_option( $name, null );
			// This plugin's own credentials stay out of agents' hands, whatever the connection allows.
			if ( Policy::is_own_option( $name ) ) {
				$values[ $name ] = null === $value ? null : '[hidden]';
				continue;
			}
			if ( ! $reveal ) {
				// A secret-looking name hides the whole value, lists and objects included: their parts inherit the name.
				$value = self::is_secret_name( $name ) && null !== $value && array() !== $value ? '[hidden]' : self::redact( $value );
			}
			$values[ $name ] = $value;
		}
		return array(
			'content_trust' => 'untrusted_site_content',
			'options'       => $values,
		);
	}

	/**
	 * Change options.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error When there is nothing to change.
	 */
	public static function options_update( array $args, array $token = array() ) {
		if ( empty( $args['changes'] ) || ! is_array( $args['changes'] ) ) {
			throw new Tool_Error( 'changes must be an object of option name to value.' );
		}
		// On a network, a site admin must not reach active_plugins or the role table, which core protects.
		if ( is_multisite() && ! is_super_admin() ) {
			throw new Tool_Error( 'On a multisite network, only super admins can change options directly. Use the plugin screens instead.' );
		}
		if ( 'lite' === SAMCP_EDITION ) {
			foreach ( $args['changes'] as $name => $value ) {
				self::lite_check_option( (string) $name, $value );
			}
		}
		// The schedule decides which code runs later, outside any rule: only WordPress itself writes it.
		foreach ( array_keys( $args['changes'] ) as $name ) {
			if ( 'cron' === Policy::normalise_option( (string) $name ) && 'full' !== SAMCP_EDITION ) {
				throw new Tool_Error( 'The option "cron" is the schedule of WordPress and cannot be set directly.' );
			}
		}
		foreach ( array_keys( $args['changes'] ) as $name ) {
			if ( Policy::is_own_option( (string) $name ) ) {
				throw new Tool_Error( 'This connection cannot change the option "' . esc_html( (string) $name ) . '": it belongs to Site Agent MCP, whose settings a person changes in wp-admin.' );
			}
		}
		$snapshot = Journal::snapshot_options();
		$results  = array();
		$changes  = $args['changes'];
		$label    = implode( ', ', array_keys( $changes ) );
		$change   = null;
		try {
			Policy::agent_write(
				static function () use ( $changes, &$results ) {
					foreach ( $changes as $name => $value ) {
						$results[ $name ] = update_option( (string) $name, $value );
					}
				}
			);
		} catch ( Tool_Error $e ) {
			// A refusal can stop the batch halfway: what was already written stays undoable, and the agent is told.
			$change = self::record_named_options( $snapshot, array_keys( $changes ), $label );
			if ( $change['names'] ) {
				throw new Tool_Error( esc_html( $e->getMessage() ) . ' Options already written before this refusal: ' . esc_html( implode( ', ', $change['names'] ) ) . ( $change['id'] ? '. Undo them with change_undo, change_id ' . (int) $change['id'] . '.' : '.' ) );
			}
			throw $e;
		} finally {
			// Any other failure still journals the writes made before it.
			if ( null === $change ) {
				$change = self::record_named_options( $snapshot, array_keys( $changes ), $label );
			}
		}
		return array(
			'updated'         => $results,
			'options_changed' => $change['names'],
			'change_id'       => $change['id'],
		);
	}

	/**
	 * Journal the options a direct write named, and only those: another option changed
	 * meanwhile, by a person, a scheduled task or a hook, is not the agent's change.
	 * Names compare as the database does (case, accents, trailing spaces).
	 *
	 * @param array    $before Snapshot taken before the write.
	 * @param string[] $names  Option names the agent asked to set.
	 * @param string   $label  Journal label.
	 * @return array id and names.
	 */
	private static function record_named_options( array $before, array $names, $label ) {
		$wanted = array();
		foreach ( $names as $name ) {
			$wanted[ Policy::normalise_option( trim( (string) $name ) ) ] = true;
		}
		$after = Journal::snapshot_options();
		$state = array();
		foreach ( array_keys( $before + $after ) as $name ) {
			if ( ! isset( $wanted[ Policy::normalise_option( trim( (string) $name ) ) ] ) ) {
				continue;
			}
			$had = array_key_exists( $name, $before );
			$has = array_key_exists( $name, $after );
			if ( ! $had ) {
				$state[ $name ] = array(
					'exists' => false,
					'now'    => $after[ $name ],
				);
			} elseif ( ! $has || $before[ $name ] !== $after[ $name ] ) {
				$state[ $name ] = array(
					'exists' => true,
					'raw'    => $before[ $name ],
					'now'    => $has ? $after[ $name ] : null,
				);
			}
		}
		if ( ! $state ) {
			return array(
				'id'    => null,
				'names' => array(),
			);
		}
		return array(
			'id'    => Journal::record( 'options_update', $label, array( 'options' => $state ) ),
			'names' => array_map( 'strval', array_keys( $state ) ),
		);
	}

	/**
	 * Lite edition: refuse an option change that would activate code or store code.
	 * Which plugins and theme run is changed with extension_manage, so WordPress runs
	 * their activation checks; PHP is never stored; script tags only by accounts that
	 * WordPress already lets save them (unfiltered_html).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value New value.
	 * @throws Tool_Error When the change is refused.
	 */
	private static function lite_check_option( $name, $value ) {
		if ( in_array( Policy::normalise_option( $name ), self::LITE_PROTECTED_OPTIONS, true ) ) {
			throw new Tool_Error( 'The option "' . esc_html( $name ) . '" decides which code runs on the site and cannot be set directly. Use extension_manage to activate or deactivate plugins and themes.' );
		}
		$texts = array();
		if ( is_array( $value ) ) {
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$texts ) {
					if ( is_string( $item ) ) {
						$texts[] = $item;
					}
				}
			);
		} elseif ( is_string( $value ) ) {
			$texts[] = $value;
		}
		foreach ( $texts as $text ) {
			if ( preg_match( '/<\?(php|=)/i', $text ) ) {
				throw new Tool_Error( 'The value of "' . esc_html( $name ) . '" holds PHP code. This edition never stores code in options.' );
			}
			if ( preg_match( '/<script\b/i', $text ) && ! current_user_can( 'unfiltered_html' ) ) {
				throw new Tool_Error( 'The value of "' . esc_html( $name ) . '" holds a script tag, which your WordPress account is not allowed to save (unfiltered_html).' );
			}
		}
	}

	/**
	 * Lite edition: refuse requests to WordPress's theme and plugin file editors, which
	 * save PHP files. The Lite edition never edits code on the site.
	 *
	 * @param string $url    Target URL.
	 * @param array  $fields Query and body fields sent.
	 * @throws Tool_Error When the target edits code.
	 */
	public static function lite_check_admin_target( $url, array $fields ) {
		if ( 'lite' !== SAMCP_EDITION ) {
			return;
		}
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$file    = strtolower( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$actions = array();
		foreach ( array( $fields, $query ) as $source ) {
			if ( isset( $source['action'] ) && is_string( $source['action'] ) ) {
				$actions[] = strtolower( trim( $source['action'] ) );
			}
		}
		if ( in_array( $file, array( 'theme-editor.php', 'plugin-editor.php' ), true ) || in_array( 'edit-theme-plugin-file', $actions, true ) ) {
			throw new Tool_Error( 'This edition never edits theme or plugin files.' );
		}
	}

	/**
	 * Admin menu.
	 *
	 * @return array
	 */
	public static function admin_menu() {
		$bridge = new Admin_Bridge();
		return array(
			'content_trust' => 'untrusted_site_content',
			'screens'       => $bridge->menu(),
		);
	}

	/**
	 * Read an admin screen.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function admin_page_read( array $args ) {
		$bridge = new Admin_Bridge();
		return $bridge->read_page( self::required( $args, 'page' ) );
	}

	/**
	 * Submit an admin form.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function admin_form_submit( array $args ) {
		$bridge = new Admin_Bridge();
		return $bridge->submit_form(
			self::required( $args, 'page' ),
			(int) ( isset( $args['form_index'] ) ? $args['form_index'] : 0 ),
			isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array(),
			! isset( $args['dry_run'] ) || (bool) $args['dry_run'],
			! empty( $args['allow_unknown_fields'] ),
			isset( $args['submit'] ) ? (string) $args['submit'] : ''
		);
	}


	/**
	 * Inspect a page.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function page_fetch( array $args ) {
		$target = self::required( $args, 'url' );
		$url    = preg_match( '#^https?://#i', $target ) ? $target : home_url( '/' . ltrim( $target, '/' ) );
		$limit  = isset( $args['html_chars'] ) ? min( 50000, max( 0, (int) $args['html_chars'] ) ) : 8000;
		if ( ! empty( $args['as_user'] ) ) {
			$path  = strtolower( rawurldecode( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
			$admin = strtolower( (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH ) );
			if ( 0 === strpos( $path, $admin ) || false !== strpos( $path, 'wp-login.php' ) || false !== strpos( $path, '/samcp/' ) ) {
				throw new Tool_Error( 'as_user is for public pages. Use admin_page_read for wp-admin screens.' );
			}
			// The session is tagged public: WordPress refuses it wp-admin, AJAX and REST, whatever redirect leads there.
			Policy::next_surface( 'public' );
			try {
				$bridge = new Admin_Bridge();
			} finally {
				Policy::next_surface( 'admin' );
			}
			$response         = $bridge->request( 'GET', $url );
			$response['body'] = Admin_Bridge::scrub_html( $response['body'] );
		} else {
			$response = Admin_Bridge::send( 'GET', $url );
		}
		$headers = array_change_key_case( $response['headers'], CASE_LOWER );
		$keep    = array( 'content-type', 'cache-control', 'expires', 'etag', 'last-modified', 'age', 'vary', 'content-encoding', 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-wp-super-cache', 'x-rocket', 'x-robots-tag', 'server', 'strict-transport-security', 'content-security-policy', 'link' );
		$result  = array(
			'content_trust' => 'untrusted_site_content',
			'status'        => $response['status'],
			'final_url'     => $response['final_url'],
			'duration_ms'   => $response['duration_ms'],
			'bytes'         => strlen( $response['body'] ),
			'headers'       => array_intersect_key( $headers, array_flip( $keep ) ),
		);
		$type    = isset( $headers['content-type'] ) ? implode( ',', (array) $headers['content-type'] ) : '';
		if ( false !== stripos( $type, 'html' ) ) {
			$result['analysis'] = self::analyse_html( $response['body'] );
		}
		$result['body']           = Store::cut( $response['body'], $limit );
		$result['body_truncated'] = strlen( $response['body'] ) > $limit;
		return $result;
	}

	/**
	 * SEO, GEO and markup facts of an HTML page.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function analyse_html( $html ) {
		$dom   = Admin_Bridge::dom( $html );
		$xpath = new \DOMXPath( $dom );
		$attr  = static function ( $query, $name ) use ( $xpath ) {
			$node = $xpath->query( $query )->item( 0 );
			return $node ? (string) $node->getAttribute( $name ) : null;
		};
		$headings = array();
		foreach ( $xpath->query( '//h1|//h2|//h3' ) as $node ) {
			if ( count( $headings ) < 60 ) {
				$headings[] = $node->nodeName . ': ' . substr( Admin_Bridge::text( $node ), 0, 120 );
			}
		}
		$open_graph = array();
		foreach ( $xpath->query( '//meta[starts-with(@property,"og:")]' ) as $node ) {
			$open_graph[ $node->getAttribute( 'property' ) ] = $node->getAttribute( 'content' );
		}
		$hreflang = array();
		foreach ( $xpath->query( '//link[@rel="alternate"][@hreflang]' ) as $node ) {
			$hreflang[ $node->getAttribute( 'hreflang' ) ] = $node->getAttribute( 'href' );
		}
		$json_ld = array();
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$decoded   = json_decode( $node->textContent, true );
			$json_ld[] = null === $decoded ? array( 'invalid_json' => substr( $node->textContent, 0, 200 ) ) : $decoded;
		}
		$images      = $xpath->query( '//img' );
		$without_alt = 0;
		foreach ( $images as $image ) {
			if ( ! $image->hasAttribute( 'alt' ) ) {
				++$without_alt;
			}
		}
		$body_text = Admin_Bridge::text( $xpath->query( '//body' )->item( 0 ) );
		return array(
			'title'              => Admin_Bridge::text( $xpath->query( '//title' )->item( 0 ) ),
			'meta_description'   => $attr( '//meta[@name="description"]', 'content' ),
			'meta_robots'        => $attr( '//meta[@name="robots"]', 'content' ),
			'canonical'          => $attr( '//link[@rel="canonical"]', 'href' ),
			'lang'               => $attr( '//html', 'lang' ),
			'viewport'           => $attr( '//meta[@name="viewport"]', 'content' ),
			'open_graph'         => $open_graph,
			'hreflang'           => $hreflang,
			'headings'           => $headings,
			'json_ld'            => $json_ld,
			'images'             => $images->length,
			'images_without_alt' => $without_alt,
			'approx_words'       => count( (array) preg_split( '/\s+/u', $body_text, -1, PREG_SPLIT_NO_EMPTY ) ),
			'scripts'            => $xpath->query( '//script[@src]' )->length,
			'stylesheets'        => $xpath->query( '//link[@rel="stylesheet"]' )->length,
			'render_blocking_js' => $xpath->query( '//head/script[@src][not(@async) and not(@defer) and not(@type="module")]' )->length,
		);
	}

	/**
	 * Flush caches.
	 *
	 * @return array
	 */
	public static function cache_flush() {
		$flushed = array();
		if ( wp_cache_flush() ) {
			$flushed[] = 'object cache';
		}
		$functions = array(
			'rocket_clean_domain'  => 'WP Rocket',
			'w3tc_flush_all'       => 'W3 Total Cache',
			'wp_cache_clear_cache' => 'WP Super Cache',
			'wpfc_clear_all_cache' => 'WP Fastest Cache',
		);
		foreach ( $functions as $function => $label ) {
			if ( function_exists( $function ) ) {
				$function();
				$flushed[] = $label;
			}
		}
		if ( class_exists( 'autoptimizeCache' ) ) {
			\autoptimizeCache::clearall();
			$flushed[] = 'Autoptimize';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$flushed[] = 'LiteSpeed';
		}
		do_action( 'samcp_cache_flush' );
		return array(
			'flushed' => $flushed,
			'note'    => 'A CDN or server cache outside WordPress is not flushed by this tool.',
		);
	}

	/**
	 * List changes.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function change_log( array $args ) {
		return array( 'changes' => Journal::recent( isset( $args['limit'] ) ? min( 100, max( 1, (int) $args['limit'] ) ) : 20 ) );
	}

	/**
	 * Undo a change.
	 *
	 * @param array $args  Arguments.
	 * @param array $token Token.
	 * @return array
	 */
	public static function change_undo( array $args, array $token ) {
		$has_power = self::has_server_access( $token );
		return array( 'restored' => Journal::undo( (int) ( isset( $args['change_id'] ) ? $args['change_id'] : 0 ), $has_power ) );
	}
}
