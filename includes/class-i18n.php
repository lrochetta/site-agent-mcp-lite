<?php
/**
 * Translations of the screens people see, in the WordPress language.
 *
 * The plugin ships WordPress 6.5+ PHP translation files,
 * languages/site-agent-mcp-<locale>.l10n.php. Format, as read by WordPress core:
 * - the file returns an array of headers (lowercase keys, among them 'plural-forms')
 *   plus 'messages', a map of original => translation;
 *   https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/l10n/class-wp-translation-file-php.php
 * - a message with a context is keyed "context\4original"; a plural message is keyed
 *   "singular\0plural" and its forms are joined with "\0";
 *   https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/l10n/class-wp-translation-controller.php
 * - load_textdomain() given a .mo path reads the .l10n.php beside it first.
 *   https://make.wordpress.org/core/2024/02/27/i18n-improvements-6-5-performant-translations/
 * The files are built by scripts/i18n/compile.py; see i18n/README.md.
 *
 * What AI agents read over MCP (tool descriptions, tool results, server
 * instructions, agent_rules, OAuth error descriptions) stays in English on purpose:
 * agents work best with one stable wording. Never wrap it in gettext.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the translation for the language WordPress picked: the user's language in
 * the admin, the site language elsewhere (determine_locale()). When the exact
 * locale has no file, another locale of the same language is used.
 */
class I18n {

	const DOMAIN = 'site-agent-mcp-lite';

	/** Locale shipped for each language, used when the exact locale has no file. */
	const FALLBACK = array(
		'ar' => 'ar',
		'de' => 'de_DE',
		'es' => 'es_ES',
		'fr' => 'fr_FR',
		'he' => 'he_IL',
		'hi' => 'hi_IN',
		'it' => 'it_IT',
		'nl' => 'nl_NL',
		'pt' => 'pt_BR',
		'th' => 'th',
		'zh' => 'zh_CN',
	);

	/** Chinese written in traditional characters: never shown the simplified file. */
	const TRADITIONAL = array( 'zh_TW', 'zh_HK', 'zh_MO' );

	/** Languages written right to left. */
	const RTL = array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'haz', 'he', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * Locale of the file loaded, null when none.
	 *
	 * @var string|null
	 */
	private static $loaded = null;

	/** Register hooks. */
	public static function boot() {
		// Priority 0: before the OAuth and Search Console routes, which answer on init at priority 1.
		add_action( 'init', array( __CLASS__, 'load' ), 0 );
		add_action( 'change_locale', array( __CLASS__, 'load' ) );
	}

	/**
	 * Load the translation for a locale.
	 *
	 * @param string $locale Locale, or empty for the one WordPress determined.
	 */
	public static function load( $locale = '' ) {
		$locale = is_string( $locale ) && '' !== $locale ? $locale : determine_locale();
		if ( is_textdomain_loaded( self::DOMAIN ) ) {
			unload_textdomain( self::DOMAIN );
		}
		self::$loaded = null;
		foreach ( self::files( $locale ) as $file ) {
			list( $file_locale, $mofile ) = $file;
			$php = substr( $mofile, 0, -3 ) . '.l10n.php';
			// The locale passed is the one WordPress uses, so a fallback file serves it.
			if ( ( is_readable( $php ) || is_readable( $mofile ) ) && load_textdomain( self::DOMAIN, $mofile, $locale ) ) {
				self::$loaded = $file_locale;
				return;
			}
		}
	}

	/**
	 * Files to try, best first. A language pack from translate.wordpress.org wins,
	 * as in WordPress; then the plugin's own files.
	 *
	 * @param string $locale Locale.
	 * @return array<int,array{0:string,1:string}> Locale held and .mo path; WordPress reads the .l10n.php beside it first.
	 */
	private static function files( $locale ) {
		$files = array( array( $locale, WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale . '.mo' ) );
		foreach ( self::candidates( $locale ) as $candidate ) {
			$files[] = array( $candidate, SAMCP_DIR . 'languages/' . self::DOMAIN . '-' . $candidate . '.mo' );
		}
		return $files;
	}

	/**
	 * Locales whose file can serve a locale: itself, then the shipped locale of its
	 * language. fr_CA gets fr_FR, de_DE_formal gets de_DE, pt_PT gets pt_BR, zh_SG
	 * gets zh_CN, but zh_TW and zh_HK get nothing.
	 *
	 * @param string $locale Locale.
	 * @return string[]
	 */
	public static function candidates( $locale ) {
		$list = array( $locale );
		$lang = explode( '_', $locale )[0];
		if ( isset( self::FALLBACK[ $lang ] ) && ! in_array( substr( $locale, 0, 5 ), self::TRADITIONAL, true ) ) {
			$list[] = self::FALLBACK[ $lang ];
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Whether the plugin's text shows translated.
	 *
	 * @return bool
	 */
	public static function translated() {
		return null !== self::$loaded || is_textdomain_loaded( self::DOMAIN );
	}

	/**
	 * Whether the translation loaded is written right to left.
	 *
	 * @return bool
	 */
	public static function translation_is_rtl() {
		return null !== self::$loaded && in_array( explode( '_', self::$loaded )[0], self::RTL, true );
	}

	/**
	 * Attributes for an element holding the plugin's text inside a WordPress page:
	 * lang="en" when the text stays English on a site set to another language, so
	 * screen readers pronounce it right; dir="rtl" when the translation reads right
	 * to left but WordPress does not, because its own language pack is missing.
	 *
	 * @return string Attributes with a leading space, or empty.
	 */
	public static function container_attributes() {
		if ( ! self::translated() ) {
			return 0 === strpos( determine_locale(), 'en' ) ? '' : ' lang="en" dir="ltr"';
		}
		return self::translation_is_rtl() && ! is_rtl() ? ' dir="rtl"' : '';
	}

	/**
	 * Attributes of the <html> element of a page the plugin renders alone, such as
	 * the consent screen.
	 *
	 * @return string
	 */
	public static function document_attributes() {
		if ( ! self::translated() && 0 !== strpos( determine_locale(), 'en' ) ) {
			return 'lang="en" dir="ltr"';
		}
		$attributes = get_language_attributes();
		return self::translation_is_rtl() && ! is_rtl() ? 'dir="rtl" ' . $attributes : $attributes;
	}

	/**
	 * Language and direction for a page served without WordPress, such as the restorer.
	 *
	 * @return array{lang:string,dir:string}
	 */
	public static function standalone() {
		if ( ! self::translated() ) {
			return array(
				'lang' => 'en',
				'dir'  => 'ltr',
			);
		}
		return array(
			'lang' => str_replace( '_', '-', determine_locale() ),
			'dir'  => is_rtl() || self::translation_is_rtl() ? 'rtl' : 'ltr',
		);
	}
}
