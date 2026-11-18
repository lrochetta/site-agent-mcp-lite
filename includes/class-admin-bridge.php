<?php
/**
 * Access to wp-admin screens as the connected user.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens only exist inside a real admin request, so this bridge makes a
 * loopback request with a short-lived session of the current user, then turns
 * the HTML into structured data. The session is destroyed right after use.
 */
class Admin_Bridge {

	const SESSION_TTL  = 120;
	const TEXT_LIMIT   = 6000;
	const MAX_REDIRECT = 3;
	/** Largest response body read from the site, so one huge page cannot exhaust PHP memory. */
	const MAX_BODY = 5242880;

	const SCRIPT_DATA_LIMIT = 2000;
	const SCRIPT_DATA_TOTAL = 12000;

	/**
	 * Current loopback session.
	 *
	 * @var array|null
	 */
	private $session = null;

	/** Open a session for the current user. */
	public function __construct() {
		$user_id = get_current_user_id();
		$expires = time() + self::SESSION_TTL;
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		// The session carries the agent context, so the rules of Policy hold in the loopback request too.
		add_filter( 'attach_session_information', array( Policy::class, 'tag_session' ), 999 );
		try {
			$token = $manager->create( $expires );
		} finally {
			remove_filter( 'attach_session_information', array( Policy::class, 'tag_session' ), 999 );
		}
		$secure  = is_ssl() || 0 === strpos( admin_url(), 'https://' );

		$this->session = array(
			'manager' => $manager,
			'token'   => $token,
			'cookies' => array(
				$secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE => wp_generate_auth_cookie( $user_id, $expires, $secure ? 'secure_auth' : 'auth', $token ),
				LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token ),
				TEST_COOKIE      => 'WP Cookie check',
			),
		);
	}

	/** Destroy the session as soon as the bridge goes away. */
	public function __destruct() {
		if ( $this->session ) {
			$this->session['manager']->destroy( $this->session['token'] );
		}
	}

	/**
	 * Create a nonce valid for the loopback session. Nonces are tied to the session token,
	 * so one made for the MCP request itself would fail in the admin screen.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	public function nonce( $action ) {
		$previous                    = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_COOKIE[ LOGGED_IN_COOKIE ] = $this->session['cookies'][ LOGGED_IN_COOKIE ];
		$nonce                       = wp_create_nonce( $action );
		if ( null === $previous ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $previous;
		}
		return $nonce;
	}

	/**
	 * Absolute URL of an admin path, refusing anything off this site.
	 *
	 * @param string $path Path such as options-general.php or admin.php?page=x.
	 * @return string
	 * @throws Tool_Error When the target is another host.
	 */
	public static function admin_target( $path ) {
		$path = (string) $path;
		$url  = preg_match( '#^https?://#i', $path ) ? $path : admin_url( ltrim( $path, '/' ) );
		self::assert_same_host( $url );
		// Decoded twice, so no spelling of the path can step out of wp-admin.
		$admin  = strtolower( (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH ) );
		$target = strtolower( rawurldecode( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		if ( false !== strpos( $target, '..' ) || false !== strpos( $target, '\\' ) || 0 !== strpos( $target, $admin ) ) {
			throw new Tool_Error( 'Only wp-admin screens are allowed here. Use page_fetch for public pages and rest_call for REST routes.' );
		}
		return $url;
	}

	/**
	 * Refuse URLs on another host: the session cookies must never leave the site.
	 *
	 * @param string $url URL.
	 * @throws Tool_Error When the host differs.
	 */
	public static function assert_same_host( $url ) {
		if ( ! self::is_site_url( $url ) ) {
			throw new Tool_Error( 'Only URLs on this site are allowed.' );
		}
	}

	/**
	 * Whether a URL has the scheme, host and port of this site. A host alone is not enough:
	 * another port could be a private service, and plain HTTP would expose the session cookies.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		$origin = static function ( $value ) {
			$parts  = wp_parse_url( $value );
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
			return $scheme . '://' . ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' ) . ':' . $port;
		};
		return in_array( $origin( $url ), array( $origin( home_url() ), $origin( site_url() ), $origin( admin_url() ) ), true );
	}

	/**
	 * Hide the value of password and secret-named inputs in raw admin HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function scrub_html( $html ) {
		$html = (string) preg_replace_callback(
			'/(<textarea\b[^>]*>)(.*?)(<\/textarea>)/is',
			static function ( $match ) {
				preg_match( '/\bname\s*=\s*["\']?([^"\'\s>]+)/i', $match[1], $name );
				return isset( $name[1] ) && Tools::is_secret_name( $name[1] ) ? $match[1] . '[hidden]' . $match[3] : $match[0];
			},
			(string) $html
		);
		return (string) preg_replace_callback(
			'/<input\b[^>]*>/i',
			static function ( $match ) {
				$tag = $match[0];
				preg_match( '/\bname\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $name );
				if ( preg_match( '/\btype\s*=\s*["\']?password/i', $tag ) || ( isset( $name[1] ) && Tools::is_secret_name( $name[1] ) ) ) {
					return (string) preg_replace( '/\bvalue\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', 'value="[hidden]"', $tag );
				}
				return $tag;
			},
			(string) $html
		);
	}

	/**
	 * Send a request as the current user.
	 *
	 * @param string       $method HTTP method.
	 * @param string       $url    Same-site URL.
	 * @param string|array $body   Body.
	 * @param array        $headers Headers.
	 * @return array status, headers, body, final_url, duration_ms.
	 * @throws Tool_Error On transport failure.
	 */
	public function request( $method, $url, $body = '', array $headers = array() ) {
		$response = self::send( $method, $url, $body, $headers, $this->session['cookies'] );
		if ( ! empty( $response['login_redirect'] ) ) {
			throw new Tool_Error( 'The admin screen refused the session and redirected to the login page. A security plugin may be blocking loopback sessions.' );
		}
		return $response;
	}

	/**
	 * Send a loopback HTTP request, following same-site redirects.
	 *
	 * @param string       $method  HTTP method.
	 * @param string       $url     Same-site URL.
	 * @param string|array $body    Body.
	 * @param array        $headers Headers.
	 * @param array        $cookies Cookies.
	 * @return array
	 * @throws Tool_Error On transport failure.
	 */
	public static function send( $method, $url, $body = '', array $headers = array(), array $cookies = array() ) {
		$started = microtime( true );
		$hops    = 0;
		do {
			self::assert_same_host( $url );
			$target = (string) apply_filters( 'samcp_loopback_url', $url );
			$args   = array(
				'method'              => $method,
				'timeout'             => 30,
				'redirection'         => 0,
				/**
				 * Whether the loopback request checks the site's TLS certificate. It carries an
				 * admin session, so it does by default. A site whose own address has a certificate
				 * the server does not trust can turn it off, or trust its authority through the
				 * sslcertificates argument of the http_request_args filter.
				 *
				 * @param bool   $verify Check the certificate.
				 * @param string $target Address requested.
				 */
				'sslverify'           => (bool) apply_filters( 'samcp_loopback_sslverify', true, $target ),
				'limit_response_size' => self::MAX_BODY,
				'cookies'             => $cookies,
				'headers'             => $headers,
				'body'                => 'GET' === $method ? null : $body,
			);
			$response = wp_remote_request( $target, $args );
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				$hint    = preg_match( '/certificate|ssl|tls/i', $message ) ? ' The certificate of the site\'s own address is not trusted by this server; the samcp_loopback_sslverify filter can turn the check off for a self-signed certificate.' : ' Some hosts block loopback requests; the samcp_loopback_url filter can point to an internal address.';
				throw new Tool_Error( 'The site could not reach itself: ' . esc_html( $message ) . '.' . $hint ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed text around an escaped message
			}
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				break;
			}
			$next = \WP_Http::make_absolute_url( $location, $url );
			if ( false !== strpos( $next, 'wp-login.php' ) && $cookies ) {
				return array(
					'status'         => $status,
					'login_redirect' => true,
				);
			}
			if ( ! self::is_site_url( $next ) ) {
				break;
			}
			$url = $next;
			// 307 and 308 repeat the same request at the new address; the others continue as a GET, as browsers do.
			if ( ! in_array( $status, array( 307, 308 ), true ) ) {
				$method = 'GET';
				$body   = '';
			}
		} while ( ++$hops <= self::MAX_REDIRECT );

		$response_headers = wp_remote_retrieve_headers( $response );
		$response_body    = (string) wp_remote_retrieve_body( $response );
		return array(
			'status'         => $status,
			'headers'        => is_object( $response_headers ) ? $response_headers->getAll() : (array) $response_headers,
			'body'           => $response_body,
			'body_truncated' => strlen( $response_body ) >= self::MAX_BODY,
			'final_url'      => $url,
			'duration_ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * Whether a response says no: an HTTP error status, or the JSON that WordPress AJAX
	 * handlers send on failure ({"success": false}) under HTTP 200.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $json   Decoded JSON body, or null.
	 * @return bool
	 */
	public static function is_refusal( $status, $json ) {
		return (int) $status >= 400 || ( is_array( $json ) && array_key_exists( 'success', $json ) && false === $json['success'] );
	}

	/**
	 * Load HTML into a DOM.
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument
	 */
	public static function dom( $html ) {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $dom;
	}

	/**
	 * Compact text of a node.
	 *
	 * @param \DOMNode|null $node Node.
	 * @return string
	 */
	public static function text( $node ) {
		return $node ? trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) ) : '';
	}

	/**
	 * Links of the admin menu visible to the current user.
	 *
	 * @return array[]
	 * @throws Tool_Error On transport failure.
	 */
	public function menu() {
		$page  = $this->request( 'GET', admin_url( 'index.php' ) );
		$xpath = new \DOMXPath( self::dom( $page['body'] ) );
		$items = array();
		// A freshly activated plugin can redirect the first admin visit to a wizard that has no menu.
		if ( 0 === $xpath->query( '//*[@id="adminmenu"]' )->length ) {
			$page  = $this->request( 'GET', admin_url( 'index.php' ) );
			$xpath = new \DOMXPath( self::dom( $page['body'] ) );
		}
		foreach ( $xpath->query( '//*[@id="adminmenu"]//a[@href]' ) as $link ) {
			$href = (string) $link->getAttribute( 'href' );
			$text = self::text( $link );
			if ( '' !== $text && '#' !== $href && ! isset( $items[ $href ] ) ) {
				$items[ $href ] = array(
					'label' => $text,
					'page'  => $href,
				);
			}
		}
		return array_values( $items );
	}

	/**
	 * Describe an admin screen.
	 *
	 * @param string $path Admin path.
	 * @return array
	 * @throws Tool_Error On transport failure.
	 */
	public function read_page( $path ) {
		$url      = self::admin_target( $path );
		$page     = $this->request( 'GET', $url );
		$dom      = self::dom( $page['body'] );
		$xpath    = new \DOMXPath( $dom );
		$root     = $xpath->query( '//*[@id="wpbody-content"]' )->item( 0 );
		$context  = $root ? $root : $dom->documentElement;
		$raw_text = '';
		if ( $context ) {
			$copy = $context->cloneNode( true );
			foreach ( array( 'script', 'style', 'noscript' ) as $tag ) {
				$nodes = array();
				foreach ( $copy->getElementsByTagName( $tag ) as $node ) {
					$nodes[] = $node;
				}
				foreach ( $nodes as $node ) {
					$node->parentNode->removeChild( $node );
				}
			}
			$raw_text = self::text( $copy );
		}
		$forms  = $this->parse_forms( $xpath, $context, $page['final_url'] );
		$public = array_map( array( __CLASS__, 'public_form' ), $forms );

		$notices = array();
		foreach ( $xpath->query( '//*[contains(concat(" ",normalize-space(@class)," ")," notice ") or contains(concat(" ",normalize-space(@class)," ")," updated ") or contains(concat(" ",normalize-space(@class)," ")," error ")]' ) as $notice ) {
			$text = self::text( $notice );
			if ( '' !== $text ) {
				$notices[] = substr( $text, 0, 400 );
			}
		}
		$title = $xpath->query( '//*[@id="wpbody-content"]//h1' )->item( 0 );

		preg_match_all( '#(?:wp-json|rest_route=)/?(?:%2F)?([a-z0-9-]+/v[0-9]+[a-z0-9-]*)#i', $page['body'], $rest );
		preg_match_all( '#["\']action["\']\s*[:,]\s*["\']([a-z0-9_-]{3,80})["\']#i', $page['body'], $ajax );

		$is_js_screen = ! $forms;
		return array(
			'content_trust'   => 'untrusted_site_content',
			'reliability'     => $is_js_screen ? 'partial_javascript_screen' : 'parsed_form',
			'status'          => $page['status'],
			'url'             => $page['final_url'],
			// Over 5 MiB the page is cut: forms and text past the cut are missing.
			'body_truncated'  => ! empty( $page['body_truncated'] ),
			'title'           => self::text( $title ),
			'notices'         => array_slice( array_unique( $notices ), 0, 10 ),
			'forms'           => $public,
			'loose_fields'    => $this->parse_loose_fields( $xpath, $context ),
			'text'            => substr( $raw_text, 0, self::TEXT_LIMIT ),
			'text_truncated'  => strlen( $raw_text ) > self::TEXT_LIMIT,
			'rest_namespaces' => array_values( array_unique( array_map( 'strtolower', $rest[1] ) ) ),
			'ajax_actions'    => array_slice( array_values( array_unique( $ajax[1] ) ), 0, 40 ),
			'script_data'     => self::script_data( $page['body'] ),
			'hint'            => $is_js_screen ? 'This screen has no HTML form: JavaScript saves its settings. Look at loose_fields and script_data, then use rest_call, admin_request or the plugin options. Nonces in script_data belong to a closed session, so ask admin_request to generate one through its nonces argument.' : '',
		);
	}

	/**
	 * Controls outside any form, which script-driven screens use for their settings.
	 *
	 * @param \DOMXPath $xpath   XPath.
	 * @param \DOMNode  $context Search root.
	 * @return array[]
	 */
	private function parse_loose_fields( \DOMXPath $xpath, $context ) {
		$fields = array();
		if ( ! $context ) {
			return $fields;
		}
		foreach ( $xpath->query( './/*[self::input or self::select or self::textarea][not(ancestor::form)]', $context ) as $node ) {
			$type = strtolower( (string) $node->getAttribute( 'type' ) );
			if ( in_array( $type, array( 'hidden', 'submit', 'button', 'image', 'reset', 'file' ), true ) ) {
				continue;
			}
			$name = (string) $node->getAttribute( 'name' );
			if ( '' === $name ) {
				$name = (string) $node->getAttribute( 'data-option' ) ?: (string) $node->getAttribute( 'id' );
			}
			if ( '' === $name ) {
				continue;
			}
			$field = $this->parse_field( $xpath, $node, strtolower( $node->nodeName ), $type, $name );
			if ( 'password' === $field['type'] || Tools::is_secret_name( $name ) ) {
				$field['value'] = '' === $field['value'] ? '' : '[hidden]';
			}
			$fields[] = $field;
			if ( count( $fields ) >= 100 ) {
				break;
			}
		}
		return $fields;
	}

	/**
	 * Data that the screen hands to its scripts through wp_localize_script.
	 *
	 * @param string $html HTML.
	 * @return array Object name to its redacted value, or to its keys when large.
	 */
	private static function script_data( $html ) {
		$objects = array();
		$budget  = self::SCRIPT_DATA_TOTAL;
		preg_match_all( '#(?:var|const|let)\s+([A-Za-z_][A-Za-z0-9_]{2,60})\s*=\s*(\{.*?\});?\s*(?:\n|</script>)#s', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			// WordPress's own script data says nothing about the plugin on this screen.
			if ( preg_match( '/^(_wp|wp[A-Z]|userSettings|heartbeatSettings|pluploadL10n|mejs|commonL10n|thickboxL10n|quicktagsL10n|authcheckL10n|bulkActionObserverIds)/', $match[1] ) ) {
				continue;
			}
			$decoded = json_decode( $match[2], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$clean = Tools::redact( $decoded );
			$size  = strlen( (string) wp_json_encode( $clean ) );
			if ( $size <= self::SCRIPT_DATA_LIMIT && $size <= $budget ) {
				$objects[ $match[1] ] = $clean;
				$budget              -= $size;
			} else {
				$objects[ $match[1] ] = array( 'keys_only' => array_slice( array_keys( $clean ), 0, 40 ) );
			}
			if ( count( $objects ) >= 30 ) {
				break;
			}
		}
		return $objects;
	}

	/**
	 * Remove form values that must not reach the agent.
	 *
	 * @param array $form Parsed form.
	 * @return array
	 */
	private static function public_form( array $form ) {
		foreach ( $form['fields'] as &$field ) {
			if ( 'password' === $field['type'] || Tools::is_secret_name( $field['name'] ) ) {
				$field['value'] = '' === $field['value'] ? '' : '[hidden]';
			}
		}
		unset( $field );
		$form['hidden_fields'] = array_keys( $form['hidden'] );
		unset( $form['hidden'] );
		return $form;
	}

	/**
	 * Extract forms with their labels, help text and values.
	 *
	 * @param \DOMXPath    $xpath   XPath.
	 * @param \DOMNode     $context Search root.
	 * @param string       $base    Page URL.
	 * @return array[]
	 */
	private function parse_forms( \DOMXPath $xpath, $context, $base ) {
		$forms = array();
		if ( ! $context ) {
			return $forms;
		}
		$index = 0;
		foreach ( $xpath->query( './/form', $context ) as $form ) {
			$action = (string) $form->getAttribute( 'action' );
			$data   = array(
				'index'   => $index++,
				'id'      => (string) $form->getAttribute( 'id' ),
				'method'  => strtoupper( $form->getAttribute( 'method' ) ?: 'GET' ),
				'action'  => \WP_Http::make_absolute_url( '' === $action ? $base : $action, $base ),
				'fields'  => array(),
				'hidden'  => array(),
				'submits' => array(),
			);
			foreach ( $xpath->query( './/input|.//select|.//textarea|.//button', $form ) as $node ) {
				$name = (string) $node->getAttribute( 'name' );
				$tag  = strtolower( $node->nodeName );
				$type = strtolower( (string) $node->getAttribute( 'type' ) );
				if ( 'button' === $tag && '' === $type ) {
					$type = 'submit';
				}
				// A browser never sends disabled controls.
				if ( ( '' === $name && 'submit' !== $type ) || $node->hasAttribute( 'disabled' ) ) {
					continue;
				}
				if ( 'hidden' === $type ) {
					$data['hidden'][ $name ][] = (string) $node->getAttribute( 'value' );
					continue;
				}
				if ( 'submit' === $type || 'image' === $type ) {
					$data['submits'][] = array(
						'name'  => $name,
						'value' => (string) $node->getAttribute( 'value' ),
						'label' => self::text( $node ) ?: (string) $node->getAttribute( 'value' ),
					);
					continue;
				}
				if ( 'button' === $tag || 'reset' === $type || 'file' === $type ) {
					continue;
				}
				$data['fields'][] = $this->parse_field( $xpath, $node, $tag, $type, $name );
			}
			$forms[] = $data;
		}
		return $forms;
	}

	/**
	 * Describe one field.
	 *
	 * @param \DOMXPath   $xpath XPath.
	 * @param \DOMElement $node  Field.
	 * @param string      $tag   Tag.
	 * @param string      $type  Type attribute.
	 * @param string      $name  Name.
	 * @return array
	 */
	private function parse_field( \DOMXPath $xpath, $node, $tag, $type, $name ) {
		$label = '';
		$id    = (string) $node->getAttribute( 'id' );
		if ( '' !== $id && false === strpos( $id, '"' ) ) {
			$label = self::text( $xpath->query( '//label[@for="' . $id . '"]' )->item( 0 ) );
		}
		if ( '' === $label ) {
			$label = self::text( $xpath->query( 'ancestor::label[1]', $node )->item( 0 ) );
		}
		if ( '' === $label ) {
			$label = self::text( $xpath->query( 'ancestor::tr[1]/th', $node )->item( 0 ) );
		}
		$help = self::text( $xpath->query( 'ancestor::td[1]//*[contains(@class,"description")]', $node )->item( 0 ) );

		$field = array(
			'name'  => $name,
			'type'  => 'select' === $tag ? 'select' : ( 'textarea' === $tag ? 'textarea' : ( $type ?: 'text' ) ),
			'label' => substr( $label, 0, 200 ),
			'help'  => substr( $help, 0, 400 ),
			'value' => '',
		);
		if ( 'select' === $tag ) {
			$field['options'] = array();
			$field['value']   = $node->hasAttribute( 'multiple' ) ? array() : '';
			foreach ( $xpath->query( './/option', $node ) as $index => $option ) {
				$value              = $option->hasAttribute( 'value' ) ? (string) $option->getAttribute( 'value' ) : self::text( $option );
				$field['options'][] = array(
					'value' => $value,
					'label' => substr( self::text( $option ), 0, 100 ),
				);
				if ( $option->hasAttribute( 'selected' ) ) {
					if ( is_array( $field['value'] ) ) {
						$field['value'][] = $value;
					} else {
						$field['value'] = $value;
					}
				} elseif ( 0 === $index && ! is_array( $field['value'] ) ) {
					$field['value'] = $value;
				}
			}
		} elseif ( 'textarea' === $tag ) {
			$field['value'] = $node->textContent;
		} elseif ( in_array( $type, array( 'checkbox', 'radio' ), true ) ) {
			$field['value']   = (string) $node->getAttribute( 'value' ) ?: 'on';
			$field['checked'] = $node->hasAttribute( 'checked' );
		} else {
			$field['value'] = (string) $node->getAttribute( 'value' );
		}
		return $field;
	}

	/**
	 * Build the body a browser would send, plus the requested changes.
	 *
	 * @param array $form   Parsed form.
	 * @param array $values Field name to new value; null removes the field.
	 * @param bool  $allow_unknown Send fields the form does not declare.
	 * @param string $submit Name of the submit button.
	 * @return array body pairs and changes.
	 * @throws Tool_Error On an unknown field.
	 */
	private static function build_payload( array $form, array $values, $allow_unknown, $submit ) {
		$current = array();
		$known   = array_keys( $form['hidden'] );
		$secret  = array();
		foreach ( $form['hidden'] as $name => $list ) {
			$current[ $name ] = '[]' === substr( $name, -2 ) ? $list : array( end( $list ) );
		}
		foreach ( $form['fields'] as $field ) {
			$known[] = $field['name'];
			// A password input stays confidential whatever its name, as it is when the page is read.
			if ( 'password' === $field['type'] ) {
				$secret[ $field['name'] ] = true;
			}
			if ( in_array( $field['type'], array( 'checkbox', 'radio' ), true ) && empty( $field['checked'] ) ) {
				continue;
			}
			$value = is_array( $field['value'] ) ? $field['value'] : array( $field['value'] );
			if ( isset( $current[ $field['name'] ] ) && '[]' === substr( $field['name'], -2 ) ) {
				$current[ $field['name'] ] = array_merge( $current[ $field['name'] ], $value );
			} else {
				$current[ $field['name'] ] = $value;
			}
		}

		$changes = array();
		foreach ( $values as $name => $value ) {
			if ( ! $allow_unknown && ! in_array( $name, $known, true ) ) {
				throw new Tool_Error( 'This form has no field named "' . esc_html( $name ) . '". Read the page again, or set allow_unknown_fields to true.' );
			}
			$before = isset( $current[ $name ] ) ? $current[ $name ] : null;
			if ( null === $value ) {
				unset( $current[ $name ] );
			} else {
				$current[ $name ] = is_array( $value ) ? array_map( 'strval', array_values( $value ) ) : array( (string) $value );
			}
			$after = isset( $current[ $name ] ) ? $current[ $name ] : null;
			if ( $before !== $after ) {
				$changes[ $name ] = isset( $secret[ $name ] ) || Tools::is_secret_name( $name ) ? array( 'changed' => true ) : array(
					'from' => $before,
					'to'   => $after,
				);
			}
		}

		$chosen = self::choose_submit( $form['submits'], $submit );
		if ( $chosen ) {
			$current[ $chosen['name'] ] = array( $chosen['value'] );
		}

		$pairs = array();
		foreach ( $current as $name => $list ) {
			foreach ( $list as $value ) {
				$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( (string) $value );
			}
		}
		return array(
			'body'         => implode( '&', $pairs ),
			'changes'      => $changes,
			'submit'       => $chosen ? $chosen['name'] : '',
			'submit_value' => $chosen ? $chosen['value'] : '',
			'submit_label' => $chosen ? $chosen['label'] : '',
		);
	}

	/**
	 * Pick the submit button a person would press. Buttons often share a name, such as
	 * "action", and differ by value, so "name=value" picks one of them; a bare name that
	 * matches buttons with different values is refused rather than guessed.
	 *
	 * @param array[] $buttons Submit buttons of the form.
	 * @param string  $submit  Name, or name=value; empty for the first named button.
	 * @return array|null Chosen button.
	 * @throws Tool_Error When no button matches, or several do.
	 */
	private static function choose_submit( array $buttons, $submit ) {
		$submit = (string) $submit;
		if ( '' === $submit ) {
			foreach ( $buttons as $button ) {
				if ( '' !== $button['name'] ) {
					return $button;
				}
			}
			return null;
		}
		$matches = array();
		foreach ( $buttons as $button ) {
			if ( '' !== $button['name'] && $button['name'] === $submit ) {
				$matches[ $button['value'] ] = $button;
			}
		}
		if ( ! $matches && false !== strpos( $submit, '=' ) ) {
			list( $name, $value ) = explode( '=', $submit, 2 );
			foreach ( $buttons as $button ) {
				if ( '' !== $button['name'] && $button['name'] === $name && $button['value'] === $value ) {
					$matches[ $button['value'] ] = $button;
				}
			}
		}
		if ( ! $matches ) {
			throw new Tool_Error( 'This form has no submit button "' . esc_html( $submit ) . '". Give its name, or name=value when several buttons share a name.' );
		}
		if ( count( $matches ) > 1 ) {
			$choices = array();
			foreach ( $matches as $button ) {
				$choices[] = $button['name'] . '=' . $button['value'] . ( '' !== $button['label'] ? ' (' . $button['label'] . ')' : '' );
			}
			throw new Tool_Error( 'Submit button "' . esc_html( $submit ) . '" is ambiguous: several buttons share this name. Choose one as name=value: ' . esc_html( implode( ', ', $choices ) ) . '.' );
		}
		return reset( $matches );
	}

	/**
	 * Fill and optionally send a form, always from a fresh copy of the screen.
	 *
	 * @param string   $path          Admin path.
	 * @param int      $index         Form index.
	 * @param array    $values        Changes.
	 * @param bool     $dry_run       Only show what would be sent.
	 * @param bool     $allow_unknown Send undeclared fields.
	 * @param string   $submit        Submit button.
	 * @return array
	 * @throws Tool_Error When the form is missing or the request fails.
	 */
	public function submit_form( $path, $index, array $values, $dry_run, $allow_unknown, $submit ) {
		$url = self::admin_target( $path );
		Tools::lite_check_admin_target( $url, array() );
		$page = $this->request( 'GET', $url );
		if ( ! empty( $page['body_truncated'] ) ) {
			// Fields after the cut would be left out of the POST, and most settings screens read a missing field as cleared.
			throw new Tool_Error( 'This admin screen is larger than 5 MiB, so its form cannot be read completely. Nothing was sent.' );
		}
		$dom   = self::dom( $page['body'] );
		$xpath = new \DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="wpbody-content"]' )->item( 0 );
		$forms = $this->parse_forms( $xpath, $root ? $root : $dom->documentElement, $page['final_url'] );
		if ( ! isset( $forms[ $index ] ) ) {
			throw new Tool_Error( 'This page has no form with index ' . (int) $index . '. It has ' . (int) count( $forms ) . '.' );
		}
		$form    = $forms[ $index ];
		$payload = self::build_payload( $form, $values, $allow_unknown, $submit );
		$sent    = array();
		parse_str( $payload['body'], $sent );
		Tools::lite_check_admin_target( $form['action'], $sent );

		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'action'  => $form['action'],
				'method'  => $form['method'],
				'changes' => $payload['changes'],
				'submit'  => $payload['submit'],
				'button'  => array(
					'value' => $payload['submit_value'],
					'label' => $payload['submit_label'],
				),
				'next'    => 'Nothing was sent. Call again with dry_run set to false to apply these changes.',
			);
		}

		$snapshot = Journal::snapshot_options();
		try {
			if ( 'GET' === $form['method'] ) {
				$target = $form['action'] . ( false === strpos( $form['action'], '?' ) ? '?' : '&' ) . $payload['body'];
				$result = $this->request( 'GET', $target );
			} else {
				$result = $this->request( 'POST', $form['action'], $payload['body'], array( 'Content-Type' => 'application/x-www-form-urlencoded' ) );
			}
		} finally {
			// A timeout can arrive after the server applied the change, which must still be journaled.
			$change = Journal::record_option_diff( $snapshot, 'admin_form_submit', $path );
		}

		$after   = new \DOMXPath( self::dom( $result['body'] ) );
		$notices = array();
		foreach ( $after->query( '//*[contains(@class,"notice") or contains(@class,"updated") or contains(@class,"error")]' ) as $notice ) {
			$text = self::text( $notice );
			if ( '' !== $text ) {
				$notices[] = substr( $text, 0, 300 );
			}
		}
		$refused = self::is_refusal( $result['status'], json_decode( $result['body'], true ) );
		return array(
			'_is_error'       => $refused,
			'content_trust'   => 'untrusted_site_content',
			'reliability'     => 'parsed_form',
			'refused'         => $refused,
			'status'          => $result['status'],
			'final_url'       => $result['final_url'],
			'requested'       => $payload['changes'],
			'options_changed' => $change['names'],
			'change_id'       => $change['id'],
			'notices'         => array_slice( array_unique( $notices ), 0, 8 ),
			'verification'    => $change['names']
				? ( $change['id'] ? 'Options changed in the database, listed above. change_undo can restore them.' : 'Options changed, listed above, but they were too large to journal: change_undo cannot restore them.' )
				: 'No option changed. The plugin may store this elsewhere, or may have rejected the values: read the page again to check.',
		);
	}
}
