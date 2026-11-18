<?php
/**
 * SEO, GEO, performance and design-verification tools.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Facts come from fetching the site or from official APIs. Nothing here invents a
 * score: E-E-A-T and GEO have no official metric, so results list evidence and say
 * what only a person can judge. Sources: docs/research/2026-11-03-seo-geo.md.
 */
class Seo_Tools {

	const GOOGLE_OPTION   = 'samcp_google_api_key';
	const GTMETRIX_OPTION = 'samcp_gtmetrix_api_key';
	const INDEXNOW_OPTION = 'samcp_indexnow_key';
	const CRAWL_MAX       = 25;
	const CRAWL_SECONDS   = 45;

	/** Rich result types and the properties Google requires. */
	const SCHEMA_REQUIRED = array(
		'BreadcrumbList' => array( 'itemListElement' ),
		'LocalBusiness'  => array( 'name', 'address' ),
		'Event'          => array( 'name', 'startDate', 'location' ),
		'Recipe'         => array( 'name', 'image' ),
		'VideoObject'    => array( 'name', 'thumbnailUrl', 'uploadDate' ),
		'Product'        => array( 'name' ),
		'Review'         => array( 'author', 'reviewRating' ),
	);

	/** Types Google no longer shows as rich results. */
	const SCHEMA_RETIRED = array( 'FAQPage', 'HowTo', 'SpecialAnnouncement', 'Course', 'ClaimReview', 'EstimatedSalary', 'LearningResource', 'VehicleListing' );

	/** AI and search crawlers, with what each one does. */
	const CRAWLERS = array(
		'Googlebot'          => 'Google Search, including AI Overviews and AI Mode',
		'Bingbot'            => 'Bing and Copilot search',
		'OAI-SearchBot'      => 'ChatGPT search results',
		'ChatGPT-User'       => 'fetches pages when a ChatGPT user asks; robots.txt may not apply',
		'GPTBot'             => 'OpenAI model training',
		'Claude-SearchBot'   => 'Claude search results',
		'Claude-User'        => 'fetches pages when a Claude user asks',
		'ClaudeBot'          => 'Anthropic model training',
		'PerplexityBot'      => 'Perplexity search results',
		'Perplexity-User'    => 'fetches pages when a Perplexity user asks; generally ignores robots.txt',
		'Google-Extended'    => 'control token for Gemini training and grounding; no effect on Google Search',
		'Applebot'           => 'Apple search features; may train',
		'Applebot-Extended'  => 'control token for Apple model training',
		'Meta-ExternalAgent' => 'Meta training and indexing',
		'Amazonbot'          => 'Amazon products; may train',
		'MistralAI-Index'    => 'Mistral search index',
		'MistralAI-Training' => 'Mistral model training',
		'CCBot'              => 'Common Crawl open dataset, often used for training',
		'Bytespider'         => 'ByteDance, reported as training; no official documentation found',
	);

	/** SEO plugin storage. Noindex needs per-plugin handling. */
	const META_KEYS = array(
		'yoast'    => array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'canonical'     => '_yoast_wpseo_canonical',
			'focus_keyword' => '_yoast_wpseo_focuskw',
			'noindex'       => '_yoast_wpseo_meta-robots-noindex',
		),
		'rank_math' => array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'canonical'     => 'rank_math_canonical_url',
			'focus_keyword' => 'rank_math_focus_keyword',
			'noindex'       => 'rank_math_robots',
		),
		'seopress' => array(
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'canonical'     => '_seopress_robots_canonical',
			'focus_keyword' => '_seopress_analysis_target_kw',
			'noindex'       => '_seopress_robots_index',
		),
		// The SEO Framework has no focus keyword in its free version.
		'tsf'       => array(
			'title'       => '_genesis_title',
			'description' => '_genesis_description',
			'canonical'   => '_genesis_canonical_uri',
			'noindex'     => '_genesis_noindex',
		),
	);

	/** Rules attached to SEO results, so agents meet them where they act. */
	const AGENT_RULES = array(
		'Do not give an E-E-A-T score: none exists. Report the evidence found and missing, and ask the user about the rest.',
		'Do not promise rankings, citations or percentage gains. Do not quote a GEO figure unless you can name its source.',
		'Never invent authors, credentials, experience, reviews, clients, figures or sources. Ask the user for the real ones.',
		'Base priorities on gsc_performance when Search Console is connected. If it is not, say your analysis has no real ranking data.',
	);

	/** Register hooks. */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'serve_indexnow_key' ), 1 );
	}

	/**
	 * Guided workflows, offered as MCP prompts to clients that show them.
	 *
	 * @return array
	 */
	public static function prompts() {
		$url = array(
			array(
				'name'        => 'url',
				'description' => 'Page to review. Defaults to the home page.',
				'required'    => false,
			),
		);
		return array(
			'security_hardening' => array(
				'title'       => 'Audit and harden this site',
				'description' => 'Audit in read-only mode, report, apply only safe reversible fixes, verify, and list what a person must do.',
				'arguments'   => array(),
			),
			'seo_priorities'  => array(
				'title'       => 'Decide what to improve for search',
				'description' => 'Use real Search Console data to choose the pages worth working on, then measure.',
				'arguments'   => array(),
			),
			'eeat_review'     => array(
				'title'       => 'E-E-A-T review of a page',
				'description' => 'Evidence found and missing, what only a person can judge, and fixes based on real facts.',
				'arguments'   => $url,
			),
			'geo_review'      => array(
				'title'       => 'AI search (GEO) review',
				'description' => 'Crawler access, indexability and content, with honest limits on what can be promised.',
				'arguments'   => $url,
			),
		);
	}

	/**
	 * Text of a guided workflow.
	 *
	 * @param string $name Prompt name.
	 * @param array  $args Prompt arguments.
	 * @return string|null Null when unknown.
	 */
	public static function prompt_text( $name, array $args ) {
		if ( ! isset( self::prompts()[ $name ] ) ) {
			return null;
		}
		$topic = array(
			'security_hardening' => 'security',
			'seo_priorities' => 'search_console',
			'eeat_review'    => 'eeat',
			'geo_review'     => 'geo',
		);
		$guide = self::seo_guide( array( 'topic' => $topic[ $name ] ) );
		$url   = isset( $args['url'] ) && is_string( $args['url'] ) && '' !== $args['url'] ? $args['url'] : home_url( '/' );
		$text  = self::prompts()[ $name ]['title'] . ' for ' . $url . ".\n\nFollow these steps in order:\n";
		foreach ( $guide['workflow'] as $index => $step ) {
			$text .= ( $index + 1 ) . '. ' . $step . "\n";
		}
		$rules = isset( $guide['rules'] ) ? $guide['rules'] : self::AGENT_RULES;
		$text .= "\nRules:\n- " . implode( "\n- ", $rules ) . "\n\nFacts to rely on:\n- " . implode( "\n- ", $guide['facts'] );
		return isset( $guide['rules'] ) ? $text : $text . "\n\nDo not change the live site until the user has approved the proposed changes.";
	}

	/**
	 * Tool definitions.
	 *
	 * @param callable $make Entry factory.
	 * @return array
	 */
	public static function definitions( callable $make ) {
		$str  = array( 'type' => 'string' );
		$bool = array( 'type' => 'boolean' );
		$int  = array( 'type' => 'integer' );
		$list = array(
			'type'  => 'array',
			'items' => $str,
		);
		return array(
			'seo_guide'         => $make( 'Site work guide', 'Read this first for any security, SEO, GEO, E-E-A-T or page design work. Gives the steps to follow, the rules to respect and the known traps, with sources.', array( 'topic' => $str + array( 'enum' => array( 'security', 'search_console', 'eeat', 'geo', 'structured_data', 'performance', 'page_design' ) ) ), array( 'topic' ), 'read', 'seo_guide', 'read' ),
			'seo_audit'         => $make( 'Audit a page', 'SEO audit of one page as visitors see it: indexability, title, description, canonical, headings, images, security headers, JSON-LD with the properties Google requires, and E-E-A-T evidence. Each finding says whether the rule comes from Google or is only a convention.', array( 'url' => $str ), array( 'url' ), 'read', 'seo_audit', 'read' ),
			'site_seo_crawl'    => $make( 'Crawl the site', 'Check pages from the sitemap, 25 at most per call: status, title, description, H1, canonical, noindex, and duplicates in the batch. Call again with the returned next_offset.', array( 'offset' => $int, 'limit' => $int ), array(), 'read', 'site_seo_crawl', 'edit_posts' ),
			'geo_check'         => $make( 'Check AI search access', 'Which search and AI crawlers robots.txt allows, with what each one does, plus snippet restrictions, sitemap and llms.txt.', array(), array(), 'read', 'geo_check', 'read' ),
			'performance_test'  => $make( 'Run PageSpeed Insights', 'Google PageSpeed Insights on a public URL, which can be a competitor: Lighthouse scores, main problems, and real-user data when Google has it. Takes up to a minute. The URL is sent to Google.', array( 'url' => $str, 'strategy' => $str + array( 'enum' => array( 'mobile', 'desktop' ) ), 'categories' => $list + array( 'description' => 'performance, accessibility, best-practices, seo. Defaults to all.' ) ), array( 'url' ), 'read', 'performance_test', 'edit_posts' ),
			'crux_query'        => $make( 'Read real-user metrics', 'Chrome UX Report: LCP, INP and CLS measured on real visitors, with Google\'s rating. Set history to true for the weekly trend. Needs a Google API key.', array( 'url' => $str + array( 'description' => 'A page URL, or an origin such as https://example.com for the whole site.' ), 'form_factor' => $str + array( 'enum' => array( 'PHONE', 'DESKTOP', 'TABLET' ) ), 'history' => $bool ), array( 'url' ), 'read', 'crux_query', 'edit_posts' ),
			'gtmetrix_test'     => $make( 'Run GTmetrix', 'Start a GTmetrix test on a page of this site, or read its result with test_id. A test takes a while: start it, then call again with test_id. Spends the site owner\'s GTmetrix credits, and the URL is sent to GTmetrix.', array( 'url' => $str, 'test_id' => $str ), array(), 'read', 'gtmetrix_test', 'manage_options' ),
			'indexnow_submit'   => $make( 'Notify search engines', 'Tell IndexNow engines that pages changed: Bing, Yandex, Naver, Seznam, Yep and Amazon. Google does not take part. Use only for real changes.', array( 'urls' => $list ), array( 'urls' ), 'write', 'indexnow_submit', 'publish_posts' ),
			'seo_meta_get'      => $make( 'Read a page\'s SEO fields', 'SEO title, description, canonical, focus keyword and noindex of a post or page, from Yoast, Rank Math or SEOPress.', array( 'post_id' => $int ), array( 'post_id' ), 'read', 'seo_meta_get', 'edit_posts' ),
			'seo_meta_set'      => $make( 'Change a page\'s SEO fields', 'Set SEO title, description, canonical, focus keyword or noindex in the active SEO plugin. Only the fields given change. Journaled for change_undo.', array( 'post_id' => $int, 'title' => $str, 'description' => $str, 'canonical' => $str, 'focus_keyword' => $str, 'noindex' => $bool ), array( 'post_id' ), 'destructive', 'seo_meta_set', 'edit_posts' ),
			'custom_css_update' => $make( 'Edit the site CSS', 'Read, append to or replace the active theme\'s Additional CSS. Use it for page styling: WordPress can remove style tags from page content. Limit rules to one page with .page-id-N. Journaled for change_undo.', array( 'mode' => $str + array( 'enum' => array( 'read', 'append', 'replace' ) ), 'css' => $str ), array( 'mode' ), 'destructive', 'custom_css_update', 'edit_css' ),
			'design_check'      => $make( 'Check a page\'s styling', 'After publishing, check what visitors really get: page classes with no matching CSS, the theme\'s content width, and whether the theme header and footer surround the page. It cannot see the visual result: ask the user or use a browser for that.', array( 'url' => $str ), array( 'url' ), 'read', 'design_check', 'read' ),
		);
	}

	/**
	 * A stored key, with a constant taking priority.
	 *
	 * @param string $option   Option name.
	 * @param string $constant Constant name.
	 * @return string
	 */
	public static function api_key( $option, $constant ) {
		return defined( $constant ) ? (string) constant( $constant ) : (string) get_option( $option, '' );
	}

	/**
	 * Keep an API key out of error messages.
	 *
	 * @param string $message Message.
	 * @param string $key     Key.
	 * @return string
	 */
	private static function clean( $message, $key ) {
		return '' === $key ? (string) $message : str_replace( $key, '[key]', (string) $message );
	}

	/**
	 * URL on this site from a path or absolute URL.
	 *
	 * @param array  $args Arguments.
	 * @param string $name Argument.
	 * @return string
	 * @throws Tool_Error When missing.
	 */
	private static function site_url_arg( array $args, $name = 'url' ) {
		if ( empty( $args[ $name ] ) || ! is_string( $args[ $name ] ) ) {
			throw new Tool_Error( 'Argument "' . esc_html( $name ) . '" is required.' );
		}
		$url = preg_match( '#^https?://#i', $args[ $name ] ) ? $args[ $name ] : home_url( '/' . ltrim( $args[ $name ], '/' ) );
		Admin_Bridge::assert_same_host( $url );
		return $url;
	}

	/**
	 * Guidance from primary sources.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On an unknown topic.
	 */
	public static function seo_guide( array $args ) {
		$guides = array(
			'security'        => array(
				'workflow' => array(
					'Inventory: run site_overview and security_audit. Stay read-only.',
					'Report: give the user a table of check, result, proposed action and who acts. Include what you could not test.',
					'Clean up: delete inactive plugins and unused themes, keeping one default theme, plus sample content and automatic drafts. Never touch the MCP plugin that connects you.',
					'Harden: close comments and pings by default, keep registration closed and the default role at subscriber, hide user enumeration and block XML-RPC with a maintained wordpress.org plugin if no server rule is possible, change a display name equal to the login, and replace a fake administrator address with one the user gives.',
					'Credentials: report predictable logins. Propose a rename or a new administrator, and act only after the user confirms.',
					'Verify: run security_audit again, check that public pages answer 200 and that this connection still works, then flush caches.',
					'Final report: a table of check, before, action and after, then what you could not do and what needs a decision: two-factor authentication, backups, firewall, server rules and anti-spam on forms.',
				),
				'facts'    => array(
					'An agent has only the connected user\'s rights. It cannot configure the web server, turn on two-factor authentication or check the host\'s backups.',
					'A 200 answer does not prove a save. A Contact Form 7 form updated through REST returned the new values without storing them. Read back the public result or the database after every write.',
					'WordPress can remove style and script tags from saved content even for an administrator: see html_restrictions in site_overview, and use custom_css_update for CSS.',
					'Direct database writes have no undo, and PHP serialized values need exact byte lengths. Keep them for cases with no other route, with confirmation, on precise rows.',
					'options_update returns false for WPLANG until the language pack is installed.',
					'page_fetch cuts the body at 50,000 characters, so styles late in a page do not show. Check titles, meta tags and the content from REST instead.',
					'An SEO plugin can replace the native sitemap: the address becomes /sitemap.xml and the old one answers 404.',
					'A form whose sender does not match the SMTP account can be refused by the mail server. A public form email field that triggers an automatic reply can be used to spam third parties, so it needs anti-spam protection.',
					'An agent sees no rendered page, so it cannot approve a design by itself.',
				),
				'rules'    => Security_Audit::RULES,
				'sources'  => array( 'https://claude.ai/artifact/PVnRjsX4zsHJCw9nDu3aca' ),
			),
			'search_console'  => array(
				'workflow' => array(
					'Call gsc_sites. If it is not connected, tell the user that real ranking data is missing and how to connect it, then continue with seo_audit and say your priorities are unmeasured.',
					'Call gsc_performance by query, then by page, over 28 days. Note the period and figures as your baseline.',
					'Choose pages from the data: first near_page_one with high impressions, then pages with many impressions and a low CTR for their position.',
					'For each chosen page, run gsc_inspect_url to confirm it is indexed with the expected canonical, then seo_audit.',
					'Propose changes page by page, with the data behind each one, and wait for approval.',
					'After applying, record the date. Compare equal periods with gsc_performance weeks later and report the change as an observation, not as proof.',
				),
				'facts'    => array(
					'Search Console reports clicks, impressions, CTR and average position from Google Search. It is the only real ranking source here: page_fetch and seo_audit cannot see rankings.',
					'The URL Inspection API allows 2,000 calls a day per site.',
				),
				'sources'  => array( 'https://developers.google.com/webmaster-tools/v1/searchanalytics/query', 'https://developers.google.com/webmaster-tools/limits' ),
			),
			'eeat'            => array(
				'workflow' => array(
					'Run seo_audit on the page and read its eeat section.',
					'Report what was found and what is missing as a list, with no score or grade.',
					'Ask the user every question in needs_human, and for real facts: who wrote it, their qualifications, first-hand experience, sources.',
					'Tell the user if the topic concerns health, money, law or safety, where Google gives trust more weight.',
					'Propose changes using only the facts supplied: byline, author page, About and contact links, sources, Person or Organization markup.',
					'After approval, apply them and run seo_audit again to confirm.',
				),
				'facts'    => array(
					'Google says E-E-A-T itself is not a specific ranking factor, that trust matters most, and that YMYL topics get more weight. Rater data is not used directly in rankings. Never present a number as an E-E-A-T score.',
					'What Google asks of pages: accurate bylines, a link to an author or About page, disclosure of how content was made where readers would ask, value beyond its sources, and no date changes just to look fresh.',
					'AI-written content is not banned. Fact-check it manually, including titles, descriptions, structured data and alt text.',
					'Article markup: author, author.url, datePublished and dateModified are recommended.',
				),
				'act'      => array(
					'Use seo_audit for the evidence that can be checked by machine.',
					'Never invent credentials, experience, reviews, clients or figures. Ask the user for the real ones.',
				),
				'sources'  => array( 'https://developers.google.com/search/docs/fundamentals/creating-helpful-content', 'https://developers.google.com/search/docs/fundamentals/using-gen-ai-content' ),
			),
			'geo'             => array(
				'workflow' => array(
					'Run geo_check and report which crawlers are blocked. Ask the user before changing that: allowing training crawlers is their decision.',
					'Run seo_audit on the page. Google needs it indexed and eligible for a snippet, so fix noindex, nosnippet or missing content in the HTML first.',
					'Use gsc_performance if connected, to find what the page is already found for.',
					'Propose content changes that the evidence supports: clearer answers, real sources, quotations and figures supplied by the user.',
					'Add llms.txt only if the user wants it for other systems, and say Google does not use it for Search.',
					'State plainly that no technique has a proven stable effect across AI engines, and do not promise gains.',
				),
				'facts'    => array(
					'Google says AI Overviews and AI Mode have no additional requirements: a page must be indexed and eligible for a snippet. No special files, markup or schema are needed.',
					'nosnippet, max-snippet and data-nosnippet limit use in Google\'s AI features. Google-Extended affects Gemini training and grounding, not Search. Blocking GPTBot or ClaudeBot stops training, not their search crawlers.',
					'llms.txt is a proposal. On 2026-06-15 Google said Search does not need these files and they do not affect rankings. No primary statement was found from other engines, and the studies found are vendor studies showing no measurable effect. It is harmless but do not promise results.',
					'The GEO paper (KDD 2024) measured gains from adding quotations, statistics, fluent writing and cited sources, and a loss from keyword stuffing, on a simulated engine. A 2026 survey (single-author preprint) found no stable cross-platform causal effect on discoverability.',
				),
				'act'      => array(
					'Start with geo_check, then normal SEO: indexable pages and content in the server HTML.',
					'Add real sources, quotations and figures supplied by the user. Do not invent them.',
					'Do not claim a passage length, a citation rate or a percentage gain unless you can name its source.',
					'Choosing which AI crawlers to allow is the site owner\'s decision: ask.',
				),
				'sources'  => array( 'https://developers.google.com/search/docs/appearance/ai-features', 'https://developers.google.com/search/docs/fundamentals/ai-optimization-guide', 'https://developers.google.com/search/updates', 'https://arxiv.org/abs/2311.09735', 'https://arxiv.org/abs/2607.14035' ),
			),
			'structured_data' => array(
				'facts'    => array(
					'Markup must describe content visible on the page.',
					'FAQ rich results stopped appearing on 2026-05-07, and HowTo is no longer in Google\'s gallery. Do not sell them as a rich result gain.',
					'Businesses that control reviews about themselves are not eligible for stars on their LocalBusiness or Organization pages.',
					'Neither the Rich Results Test nor the Schema Markup Validator has a public API that we could find. seo_audit checks required properties only: send the user to the Rich Results Test for a final check.',
				),
				'act'      => array( 'Use the SEO plugin\'s schema when one is active, to avoid duplicates.' ),
				'sources'  => array( 'https://developers.google.com/search/docs/appearance/structured-data/search-gallery', 'https://search.google.com/test/rich-results' ),
			),
			'performance'     => array(
				'facts'    => array(
					'Good at the 75th percentile of real visits: LCP 2.5 s or less, INP 200 ms or less, CLS 0.1 or less. Poor: LCP above 4 s, INP above 500 ms, CLS above 0.25.',
					'Google says Core Web Vitals are used by its ranking systems, with no guarantee of top rankings.',
					'Lighthouse and GTmetrix are lab tests from one location. crux_query shows real visitors, over a rolling 28 days, so fixes take weeks to show there.',
					'Google plans to remove real-user data from PageSpeed Insights, with no date given. Prefer crux_query for it.',
				),
				'act'      => array(
					'Measure before and after each change, on mobile, and repeat: lab results vary.',
					'Test anonymously with page_fetch: a logged-in view often bypasses the page cache.',
					'Check the page still looks right after enabling minification, deferred scripts or critical CSS.',
				),
				'sources'  => array( 'https://web.dev/articles/lcp', 'https://web.dev/articles/inp', 'https://web.dev/articles/cls', 'https://developers.google.com/search/docs/appearance/page-experience', 'https://developers.google.com/speed/docs/insights/v5/get-started' ),
			),
			'page_design'     => array(
				'facts'    => array(
					'A preview outside WordPress proves nothing. Themes constrain content to a narrow column and add their own header and footer.',
					'WordPress removes style and script tags from content when the user lacks unfiltered_html, even for admins on hardened hosts such as Cloudron. The CSS text can stay behind as visible text. site_overview reports this as html_restrictions.',
					'rest_call warns when a save removed tags. Treat that warning as a failed save.',
				),
				'act'      => array(
					'Put CSS in custom_css_update, limited to the page with .page-id-N, and keep class names specific.',
					'In a block theme, use a template without a content width, or alignfull blocks, for full-width pages. On one page, the theme\'s parts can be hidden with .page-id-N header.wp-block-template-part.',
					'Do not work around a removal with db_query, and read back anything you write.',
					'After publishing, run design_check, then have the real page looked at in a browser, as an anonymous visitor, on desktop and mobile. Do not call a page finished before that.',
				),
				'sources'  => array(),
			),
		);
		$topic  = isset( $args['topic'] ) ? (string) $args['topic'] : '';
		if ( ! isset( $guides[ $topic ] ) ) {
			throw new Tool_Error( 'Unknown topic. Choose: ' . esc_html( implode( ', ', array_keys( $guides ) ) ) . '.' );
		}
		return $guides[ $topic ];
	}

	/**
	 * All JSON-LD nodes of a page, flattened.
	 *
	 * @param array $blocks Decoded blocks.
	 * @return array[]
	 */
	private static function schema_nodes( array $blocks ) {
		$nodes = array();
		$walk  = static function ( $value ) use ( &$walk, &$nodes ) {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['@type'] ) ) {
				$nodes[] = $value;
			}
			foreach ( $value as $item ) {
				$walk( $item );
			}
		};
		$walk( $blocks );
		return $nodes;
	}

	/**
	 * Audit one page.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function seo_audit( array $args ) {
		$url      = self::site_url_arg( $args );
		$response = Admin_Bridge::send( 'GET', $url );
		$headers  = array_change_key_case( $response['headers'], CASE_LOWER );
		$html     = $response['body'];
		$page     = Tools::analyse_html( $html );
		$findings = array();
		$add      = static function ( $severity, $id, $message, $basis ) use ( &$findings ) {
			$findings[] = compact( 'severity', 'id', 'message', 'basis' );
		};

		if ( 200 !== (int) $response['status'] ) {
			$add( 'high', 'status', 'The page answers ' . $response['status'] . '.', 'google' );
		}
		$robots = strtolower( (string) $page['meta_robots'] . ' ' . ( isset( $headers['x-robots-tag'] ) ? implode( ' ', (array) $headers['x-robots-tag'] ) : '' ) );
		if ( false !== strpos( $robots, 'noindex' ) ) {
			$add( 'high', 'noindex', 'The page asks not to be indexed, so it cannot appear in Search or AI features.', 'google' );
		}
		if ( false !== strpos( $robots, 'nosnippet' ) ) {
			$add( 'medium', 'nosnippet', 'nosnippet stops use in snippets and Google\'s AI features.', 'google' );
		}
		if ( empty( $page['canonical'] ) ) {
			$add( 'medium', 'canonical_missing', 'No canonical link.', 'google' );
		} elseif ( untrailingslashit( $page['canonical'] ) !== untrailingslashit( $response['final_url'] ) ) {
			$add( 'info', 'canonical_other', 'The canonical points to another URL: ' . $page['canonical'], 'google' );
		}
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $page['title'] ) : strlen( $page['title'] );
		if ( '' === $page['title'] ) {
			$add( 'high', 'title_missing', 'No title.', 'google' );
		} elseif ( $title_length < 15 || $title_length > 65 ) {
			$add( 'low', 'title_length', 'The title has ' . $title_length . ' characters. Results usually show about 50 to 60, but Google sets no limit.', 'convention' );
		}
		if ( empty( $page['meta_description'] ) ) {
			$add( 'low', 'description_missing', 'No meta description. Google may build a snippet from the content instead.', 'google' );
		}
		$h1 = count( preg_grep( '/^h1:/', $page['headings'] ) );
		if ( 1 !== $h1 ) {
			$add( 'low', 'h1_count', 'The page has ' . $h1 . ' H1 headings. One is the convention; Google does not require it.', 'convention' );
		}
		if ( empty( $page['lang'] ) ) {
			$add( 'medium', 'lang_missing', 'The html element has no lang attribute.', 'accessibility' );
		}
		if ( empty( $page['viewport'] ) ) {
			$add( 'high', 'viewport_missing', 'No viewport meta tag, so the page is not mobile-friendly.', 'google' );
		}
		if ( $page['images_without_alt'] > 0 ) {
			$add( 'medium', 'images_alt', $page['images_without_alt'] . ' of ' . $page['images'] . ' images have no alt attribute.', 'google' );
		}
		if ( 0 !== strpos( $response['final_url'], 'https://' ) ) {
			$add( 'high', 'https', 'The page is not served over HTTPS.', 'google' );
		} elseif ( ! isset( $headers['strict-transport-security'] ) ) {
			$add( 'low', 'hsts', 'No Strict-Transport-Security header.', 'security' );
		}
		if ( $page['render_blocking_js'] > 0 ) {
			$add( 'low', 'blocking_js', $page['render_blocking_js'] . ' scripts in head block rendering.', 'lighthouse' );
		}

		$nodes = self::schema_nodes( $page['json_ld'] );
		$types = array();
		foreach ( $nodes as $node ) {
			foreach ( (array) $node['@type'] as $type ) {
				$types[] = (string) $type;
				if ( in_array( $type, self::SCHEMA_RETIRED, true ) ) {
					$add( 'info', 'schema_retired', $type . ' no longer gives a rich result in Google. It stays valid schema.org, so remove it only if it has no other use.', 'google' );
				}
				if ( isset( self::SCHEMA_REQUIRED[ $type ] ) ) {
					$missing = array_diff( self::SCHEMA_REQUIRED[ $type ], array_keys( $node ) );
					if ( $missing ) {
						$add( 'medium', 'schema_required', $type . ' lacks required properties: ' . implode( ', ', $missing ) . '.', 'google' );
					}
				}
				if ( 'Product' === $type && ! array_intersect( array( 'review', 'aggregateRating', 'offers' ), array_keys( $node ) ) ) {
					$add( 'medium', 'schema_required', 'Product needs one of review, aggregateRating or offers.', 'google' );
				}
			}
		}
		foreach ( $page['json_ld'] as $block ) {
			if ( isset( $block['invalid_json'] ) ) {
				$add( 'high', 'schema_invalid', 'A JSON-LD block is not valid JSON, so it is ignored.', 'google' );
			}
		}

		return array(
			'content_trust'     => 'untrusted_site_content',
			'url'               => $response['final_url'],
			'status'            => $response['status'],
			'duration_ms'       => $response['duration_ms'],
			'findings'          => $findings,
			'summary'           => array_intersect_key( $page, array_flip( array( 'title', 'meta_description', 'canonical', 'lang', 'approx_words', 'images', 'scripts', 'stylesheets' ) ) ),
			'headings'          => $page['headings'],
			'schema_types'      => array_values( array_unique( $types ) ),
			'eeat'              => self::eeat_evidence( $html, $nodes, $response['final_url'], isset( $headers['strict-transport-security'] ) ),
			'not_checked'       => 'Rendered layout, real speed (use performance_test and crux_query) and backlinks.',
			'agent_rules'       => self::AGENT_RULES,
			'next_steps'        => array(
				Search_Console::is_connected() ? 'Run gsc_inspect_url and gsc_performance on this page: this audit cannot see rankings.' : 'Search Console is not connected, so this audit says nothing about rankings. Tell the user, and suggest connecting it in Tools > Site Agent MCP.',
				'Put the eeat.needs_human questions to the user. Do not answer them yourself.',
				'Propose fixes and wait for approval before changing the live site.',
			),
		);
	}

	/**
	 * Evidence for E-E-A-T. Deliberately not a score.
	 *
	 * @param string $html  HTML.
	 * @param array  $nodes JSON-LD nodes.
	 * @param string $url   Page URL.
	 * @param bool   $hsts  HSTS present.
	 * @return array
	 */
	private static function eeat_evidence( $html, array $nodes, $url, $hsts ) {
		$xpath = new \DOMXPath( Admin_Bridge::dom( $html ) );
		$links = array();
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$out   = array();
		foreach ( $xpath->query( '//a[@href]' ) as $link ) {
			$href    = (string) $link->getAttribute( 'href' );
			$links[] = strtolower( $href . ' ' . Admin_Bridge::text( $link ) );
			$target  = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' !== $target && $target !== $host && 0 === strpos( $href, 'http' ) ) {
				$out[ $target ] = true;
			}
		}
		$has_link = static function ( $pattern ) use ( $links ) {
			return (bool) preg_grep( $pattern, $links );
		};
		$schema   = static function ( $property ) use ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( ! empty( $node[ $property ] ) ) {
					return true;
				}
			}
			return false;
		};
		$types    = array();
		foreach ( $nodes as $node ) {
			$types = array_merge( $types, array_map( 'strval', (array) $node['@type'] ) );
		}
		return array(
			'note'        => 'Evidence only. Google says E-E-A-T is not itself a ranking factor, and no official score exists, so do not compute one.',
			'found'       => array(
				'byline'             => $xpath->query( '//meta[@name="author"]|//a[@rel="author"]|//*[contains(@class,"author") or contains(@class,"byline")]' )->length > 0 || $schema( 'author' ),
				'author_in_schema'   => $schema( 'author' ),
				'date_published'     => $schema( 'datePublished' ) || $xpath->query( '//meta[@property="article:published_time"]|//time' )->length > 0,
				'date_modified'      => $schema( 'dateModified' ) || $xpath->query( '//meta[@property="article:modified_time"]' )->length > 0,
				'about_link'         => $has_link( '#about|a-propos|à-propos|qui-sommes|equipe|team#' ),
				'contact_link'       => $has_link( '#contact#' ),
				'privacy_link'       => $has_link( '#privacy|confidentialit|mentions-legales|mentions légales#' ),
				'external_links'     => count( $out ),
				'organization_schema'=> (bool) array_intersect( array( 'Organization', 'LocalBusiness', 'Corporation' ), $types ),
				'same_as_profiles'   => $schema( 'sameAs' ),
				'https_with_hsts'    => 0 === strpos( $url, 'https://' ) && $hsts,
			),
			'needs_human' => array(
				'Is the experience first-hand: original photos, data, tests?',
				'Is the author truly qualified for this topic, above all for health, money, legal or safety pages?',
				'Are the claims accurate, and do the links support them?',
				'Does the page add something beyond its sources?',
				'Is AI involvement disclosed where readers would expect it?',
			),
		);
	}

	/**
	 * URLs listed in the site's sitemaps.
	 *
	 * @return string[]
	 */
	private static function sitemap_urls() {
		$robots    = Admin_Bridge::send( 'GET', home_url( '/robots.txt' ) );
		$sitemaps  = array();
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/mi', $robots['body'], $found ) ) {
			$sitemaps = $found[1];
		}
		if ( ! $sitemaps ) {
			$sitemaps = array( home_url( '/wp-sitemap.xml' ) );
		}
		$urls = array();
		$seen = 0;
		while ( $sitemaps && $seen < 20 && count( $urls ) < 2000 ) {
			$sitemap = array_shift( $sitemaps );
			++$seen;
			try {
				$body = Admin_Bridge::send( 'GET', $sitemap )['body'];
			} catch ( Tool_Error $e ) {
				continue;
			}
			preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $body, $locs );
			if ( false !== stripos( $body, '<sitemapindex' ) ) {
				$sitemaps = array_merge( $sitemaps, $locs[1] );
			} else {
				$urls = array_merge( $urls, $locs[1] );
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Crawl part of the site.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function site_seo_crawl( array $args ) {
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit   = isset( $args['limit'] ) ? min( self::CRAWL_MAX, max( 1, (int) $args['limit'] ) ) : self::CRAWL_MAX;
		$urls    = self::sitemap_urls();
		$started = time();
		$pages   = array();
		$titles  = array();
		$descs   = array();
		$index   = $offset;
		foreach ( array_slice( $urls, $offset, $limit ) as $url ) {
			if ( time() - $started >= self::CRAWL_SECONDS ) {
				break;
			}
			++$index;
			try {
				$response = Admin_Bridge::send( 'GET', $url );
			} catch ( Tool_Error $e ) {
				$pages[] = array(
					'url'   => $url,
					'error' => $e->getMessage(),
				);
				continue;
			}
			$page = Tools::analyse_html( $response['body'] );
			$row  = array(
				'url'         => $url,
				'status'      => $response['status'],
				'ms'          => $response['duration_ms'],
				'title'       => $page['title'],
				'description' => $page['meta_description'],
				'h1_count'    => count( preg_grep( '/^h1:/', $page['headings'] ) ),
				'canonical'   => $page['canonical'],
				'noindex'     => false !== stripos( (string) $page['meta_robots'], 'noindex' ),
				'words'       => $page['approx_words'],
				'images_without_alt' => $page['images_without_alt'],
			);
			$pages[]                = $row;
			$titles[ $row['title'] ][] = $url;
			if ( ! empty( $row['description'] ) ) {
				$descs[ $row['description'] ][] = $url;
			}
		}
		$repeated = static function ( array $map ) {
			return array_filter(
				$map,
				static function ( $list ) {
					return count( $list ) > 1;
				}
			);
		};
		return array(
			'content_trust'      => 'untrusted_site_content',
			'total_urls'         => count( $urls ),
			'checked'            => count( $pages ),
			'next_offset'        => $index < count( $urls ) ? $index : null,
			'pages'              => $pages,
			'duplicate_titles'   => $repeated( $titles ),
			'duplicate_descriptions' => $repeated( $descs ),
			'scope'              => 'Duplicates are within this batch only. Pages missing from the sitemap are not seen.',
		);
	}

	/**
	 * Parse robots.txt into groups.
	 *
	 * @param string $text robots.txt.
	 * @return array Lowercase agent to rules.
	 */
	private static function robots_groups( $text ) {
		$groups  = array();
		$current = array();
		$in_rules = false;
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', $line ) );
			if ( ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $match ) ) {
				continue;
			}
			$field = strtolower( $match[1] );
			if ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$current  = array();
					$in_rules = false;
				}
				$current[] = strtolower( $match[2] );
			} elseif ( in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				$in_rules = true;
				foreach ( $current as $agent ) {
					$groups[ $agent ][] = array( $field, $match[2] );
				}
			}
		}
		return $groups;
	}

	/**
	 * Whether robots rules let an agent read the home page. Longest matching rule wins.
	 *
	 * @param array  $groups Groups.
	 * @param string $agent  Agent.
	 * @return array allowed and matched group.
	 */
	private static function robots_allows( array $groups, $agent ) {
		$key   = strtolower( $agent );
		$group = isset( $groups[ $key ] ) ? $key : ( isset( $groups['*'] ) ? '*' : null );
		if ( null === $group ) {
			return array(
				'allowed' => true,
				'group'   => null,
			);
		}
		$allowed = true;
		$length  = -1;
		foreach ( $groups[ $group ] as $rule ) {
			list( $type, $path ) = $rule;
			if ( '' === $path || ! in_array( $path, array( '/', '/*', '*' ), true ) ) {
				continue;
			}
			if ( strlen( $path ) > $length || ( strlen( $path ) === $length && 'allow' === $type ) ) {
				$allowed = 'allow' === $type;
				$length  = strlen( $path );
			}
		}
		return array(
			'allowed' => $allowed,
			'group'   => $group,
		);
	}

	/**
	 * Check AI and search crawler access.
	 *
	 * @return array
	 */
	public static function geo_check() {
		$robots = Admin_Bridge::send( 'GET', home_url( '/robots.txt' ) );
		$groups = 200 === (int) $robots['status'] ? self::robots_groups( $robots['body'] ) : array();
		$list   = array();
		foreach ( self::CRAWLERS as $agent => $purpose ) {
			$rule   = self::robots_allows( $groups, $agent );
			$list[] = array(
				'agent'         => $agent,
				'purpose'       => $purpose,
				'home_allowed'  => $rule['allowed'],
				'matched_group' => $rule['group'],
			);
		}
		$llms = Admin_Bridge::send( 'GET', home_url( '/llms.txt' ) );
		$home = Admin_Bridge::send( 'GET', home_url( '/' ) );
		$page = Tools::analyse_html( $home['body'] );
		return array(
			'content_trust'    => 'untrusted_site_content',
			'robots_txt'       => array(
				'status' => $robots['status'],
				'body'   => Store::cut( $robots['body'], 4000 ),
			),
			'crawlers'         => $list,
			'scope'            => 'Rules are evaluated for the home page only. A CDN or firewall can also block crawlers, which robots.txt does not show.',
			'home_meta_robots' => $page['meta_robots'],
			'home_words_in_html' => $page['approx_words'],
			'sitemap_declared' => (bool) preg_match( '/^\s*sitemap:/mi', $robots['body'] ),
			'llms_txt'         => array(
				'present' => 200 === (int) $home['status'] && 200 === (int) $llms['status'],
				'note'    => 'Optional. Google says it has no effect on Search. See seo_guide topic geo.',
			),
			'decision'         => 'Whether to allow training crawlers is the site owner\'s choice. Blocking them does not remove the site from search crawlers.',
			'agent_rules'      => self::AGENT_RULES,
			'next_steps'       => array(
				'Read seo_guide with topic geo before advising.',
				'Run seo_audit on the pages that matter: Google needs them indexed and eligible for a snippet.',
			),
		);
	}

	/**
	 * Run PageSpeed Insights.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On invalid input or API failure.
	 */
	public static function performance_test( array $args ) {
		$url = isset( $args['url'] ) && is_string( $args['url'] ) ? $args['url'] : '';
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		$key      = self::api_key( self::GOOGLE_OPTION, 'SAMCP_GOOGLE_API_KEY' );
		$strategy = isset( $args['strategy'] ) && 'desktop' === $args['strategy'] ? 'DESKTOP' : 'MOBILE';
		$chosen   = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array( 'performance', 'accessibility', 'best-practices', 'seo' );
		$query    = 'url=' . rawurlencode( $url ) . '&strategy=' . $strategy;
		foreach ( $chosen as $category ) {
			$query .= '&category=' . rawurlencode( strtoupper( str_replace( '-', '_', (string) $category ) ) );
		}
		if ( '' !== $key ) {
			$query .= '&key=' . rawurlencode( $key );
		}
		$response = wp_remote_get( 'https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query, array( 'timeout' => 100 ) );
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'PageSpeed Insights did not answer: ' . esc_html( self::clean( $response->get_error_message(), $key ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 429 === $status && '' === $key ) {
			throw new Tool_Error( 'Google refused the request without an API key. An administrator can add a free key in Tools > Site Agent MCP.' );
		}
		if ( 200 !== $status || ! isset( $data['lighthouseResult'] ) ) {
			throw new Tool_Error( 'PageSpeed Insights answered ' . (int) $status . ': ' . esc_html( self::clean( isset( $data['error']['message'] ) ? $data['error']['message'] : 'no result', $key ) ) );
		}
		$lighthouse = $data['lighthouseResult'];
		$scores     = array();
		foreach ( (array) $lighthouse['categories'] as $id => $category ) {
			$scores[ $id ] = isset( $category['score'] ) ? (int) round( $category['score'] * 100 ) : null;
		}
		$metrics = array();
		foreach ( array( 'largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'first-contentful-paint', 'speed-index', 'server-response-time' ) as $id ) {
			if ( isset( $lighthouse['audits'][ $id ]['displayValue'] ) ) {
				$metrics[ $id ] = $lighthouse['audits'][ $id ]['displayValue'];
			}
		}
		$problems = array();
		foreach ( (array) $lighthouse['audits'] as $id => $audit ) {
			if ( isset( $audit['score'] ) && is_numeric( $audit['score'] ) && $audit['score'] < 0.9 ) {
				$problems[] = array(
					'id'     => $id,
					'title'  => isset( $audit['title'] ) ? $audit['title'] : $id,
					'value'  => isset( $audit['displayValue'] ) ? $audit['displayValue'] : null,
					'score'  => $audit['score'],
				);
			}
		}
		usort(
			$problems,
			static function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		$field = static function ( $block ) {
			if ( empty( $block['metrics'] ) ) {
				return null;
			}
			$out = array( 'overall' => isset( $block['overall_category'] ) ? $block['overall_category'] : null );
			foreach ( $block['metrics'] as $name => $metric ) {
				$out[ $name ] = array(
					'p75'    => isset( $metric['percentile'] ) ? $metric['percentile'] : null,
					'rating' => isset( $metric['category'] ) ? $metric['category'] : null,
				);
			}
			return $out;
		};
		return array(
			'url'           => isset( $lighthouse['finalDisplayedUrl'] ) ? $lighthouse['finalDisplayedUrl'] : $url,
			'strategy'      => strtolower( $strategy ),
			'scores'        => $scores,
			'lab_metrics'   => $metrics,
			'problems'      => array_slice( $problems, 0, 15 ),
			'field_page'    => $field( isset( $data['loadingExperience'] ) ? $data['loadingExperience'] : array() ),
			'field_origin'  => $field( isset( $data['originLoadingExperience'] ) ? $data['originLoadingExperience'] : array() ),
			'note'          => 'Lab scores vary between runs. Empty field data means Chrome has too few real visits for this page.',
		);
	}

	/**
	 * Read the Chrome UX Report.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On missing key or API failure.
	 */
	public static function crux_query( array $args ) {
		$key = self::api_key( self::GOOGLE_OPTION, 'SAMCP_GOOGLE_API_KEY' );
		if ( '' === $key ) {
			throw new Tool_Error( 'This needs a Google API key, which an administrator can add in Tools > Site Agent MCP.' );
		}
		$url = isset( $args['url'] ) ? (string) $args['url'] : '';
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			throw new Tool_Error( 'url must be a full URL or an origin.' );
		}
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body    = array( 'metrics' => array( 'largest_contentful_paint', 'interaction_to_next_paint', 'cumulative_layout_shift', 'experimental_time_to_first_byte' ) );
		$scope   = '' === $path || '/' === $path ? 'origin' : 'url';
		$body[ $scope ] = 'origin' === $scope ? untrailingslashit( $url ) : $url;
		if ( ! empty( $args['form_factor'] ) ) {
			$body['formFactor'] = (string) $args['form_factor'];
		}
		$history  = ! empty( $args['history'] );
		$endpoint = 'https://chromeuxreport.googleapis.com/v1/records:' . ( $history ? 'queryHistoryRecord' : 'queryRecord' ) . '?key=' . rawurlencode( $key );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'The Chrome UX Report did not answer: ' . esc_html( self::clean( $response->get_error_message(), $key ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 404 === $status ) {
			return array(
				'found' => false,
				'scope' => $scope,
				'note'  => 'Chrome has too few real visits for this ' . $scope . '. Try the origin, or use performance_test.',
			);
		}
		if ( 200 !== $status ) {
			throw new Tool_Error( 'The Chrome UX Report answered ' . (int) $status . ': ' . esc_html( self::clean( isset( $data['error']['message'] ) ? $data['error']['message'] : 'no result', $key ) ) );
		}
		$limits  = array(
			'largest_contentful_paint'  => array( 2500, 4000, 'ms' ),
			'interaction_to_next_paint' => array( 200, 500, 'ms' ),
			'cumulative_layout_shift'   => array( 0.1, 0.25, '' ),
		);
		$metrics = array();
		foreach ( (array) $data['record']['metrics'] as $name => $metric ) {
			if ( $history ) {
				$metrics[ $name ] = isset( $metric['percentilesTimeseries']['p75s'] ) ? array_slice( $metric['percentilesTimeseries']['p75s'], -12 ) : null;
				continue;
			}
			$p75    = isset( $metric['percentiles']['p75'] ) ? $metric['percentiles']['p75'] : null;
			$rating = null;
			if ( null !== $p75 && isset( $limits[ $name ] ) ) {
				$value  = (float) $p75;
				$rating = $value <= $limits[ $name ][0] ? 'good' : ( $value <= $limits[ $name ][1] ? 'needs_improvement' : 'poor' );
			}
			$metrics[ $name ] = array(
				'p75'    => $p75,
				'rating' => $rating,
			);
		}
		return array(
			'found'   => true,
			'scope'   => $scope,
			'history' => $history,
			'metrics' => $metrics,
			'period'  => $history ? null : ( isset( $data['record']['collectionPeriod'] ) ? $data['record']['collectionPeriod'] : null ),
			'note'    => 'Real Chrome visitors, rolling 28 days, so a fix takes weeks to show.',
		);
	}

	/**
	 * Start a GTmetrix test or read its result.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On missing key or API failure.
	 */
	public static function gtmetrix_test( array $args ) {
		$key = self::api_key( self::GTMETRIX_OPTION, 'SAMCP_GTMETRIX_API_KEY' );
		if ( '' === $key ) {
			throw new Tool_Error( 'This needs a GTmetrix API key, which an administrator can add in Tools > Site Agent MCP.' );
		}
		$base    = 'https://gtmetrix.com/api/2.0/';
		$request = array(
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'Content-Type'  => 'application/vnd.api+json',
			),
		);

		if ( empty( $args['test_id'] ) ) {
			$url             = self::site_url_arg( $args );
			$request['body'] = wp_json_encode(
				array(
					'data' => array(
						'type'       => 'test',
						'attributes' => array( 'url' => $url ),
					),
				)
			);
			$response        = wp_remote_post( $base . 'tests', $request );
			if ( is_wp_error( $response ) ) {
				throw new Tool_Error( 'GTmetrix did not answer: ' . esc_html( self::clean( $response->get_error_message(), $key ) ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 202 !== $status ) {
				throw new Tool_Error( 'GTmetrix answered ' . (int) $status . ': ' . esc_html( self::clean( isset( $data['errors'][0]['detail'] ) ? $data['errors'][0]['detail'] : 'test not started', $key ) ) );
			}
			return array(
				'state'        => 'started',
				'test_id'      => $data['data']['id'],
				'credits_left' => isset( $data['meta']['credits_left'] ) ? $data['meta']['credits_left'] : null,
				'next'         => 'Call again with this test_id in a little while.',
			);
		}

		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $args['test_id'] );
		$response = wp_remote_get( $base . 'tests/' . $id, $request );
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'GTmetrix did not answer: ' . esc_html( self::clean( $response->get_error_message(), $key ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status ) {
			$state = isset( $data['data']['attributes']['state'] ) ? $data['data']['attributes']['state'] : 'unknown';
			if ( 'error' === $state ) {
				throw new Tool_Error( 'The GTmetrix test failed: ' . esc_html( isset( $data['data']['attributes']['error'] ) ? $data['data']['attributes']['error'] : 'no detail' ) );
			}
			if ( 'completed' !== $state ) {
				return array(
					'state'   => $state,
					'test_id' => $id,
					'next'    => 'Still running. Call again in a little while.',
				);
			}
		} elseif ( 303 !== $status ) {
			throw new Tool_Error( 'GTmetrix answered ' . (int) $status . ': ' . esc_html( self::clean( isset( $data['errors'][0]['detail'] ) ? $data['errors'][0]['detail'] : 'no result', $key ) ) );
		}

		$report_id = 303 === $status ? basename( (string) wp_remote_retrieve_header( $response, 'location' ) ) : ( isset( $data['data']['attributes']['report'] ) ? $data['data']['attributes']['report'] : '' );
		$report    = wp_remote_get( $base . 'reports/' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $report_id ), $request );
		if ( is_wp_error( $report ) || 200 !== (int) wp_remote_retrieve_response_code( $report ) ) {
			throw new Tool_Error( 'The GTmetrix report could not be read.' );
		}
		$body       = json_decode( wp_remote_retrieve_body( $report ), true );
		$attributes = isset( $body['data']['attributes'] ) ? $body['data']['attributes'] : array();
		return array(
			'state'   => 'completed',
			'test_id' => $id,
			'report'  => array_intersect_key( $attributes, array_flip( array( 'gtmetrix_grade', 'gtmetrix_score', 'performance_score', 'structure_score', 'largest_contentful_paint', 'total_blocking_time', 'cumulative_layout_shift', 'time_to_first_byte', 'fully_loaded_time', 'page_bytes', 'page_requests' ) ) ),
			'report_url' => isset( $body['data']['links']['report_url'] ) ? $body['data']['links']['report_url'] : null,
			'note'    => 'Times are in milliseconds and sizes in bytes. A lab test from one location.',
		);
	}

	/**
	 * Check the GTmetrix key without spending a credit.
	 *
	 * @return string
	 * @throws Tool_Error When the key is refused.
	 */
	public static function gtmetrix_status() {
		$key      = self::api_key( self::GTMETRIX_OPTION, 'SAMCP_GTMETRIX_API_KEY' );
		$response = wp_remote_get(
			'https://gtmetrix.com/api/2.0/status',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $key . ':' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'GTmetrix did not answer: ' . esc_html( self::clean( $response->get_error_message(), $key ) ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new Tool_Error( esc_html( self::clean( isset( $data['errors'][0]['detail'] ) ? $data['errors'][0]['detail'] : 'The key was refused.', $key ) ) );
		}
		// Shown on the Services screen only, so it follows the person's language.
		$credits = '?';
		if ( isset( $data['data']['attributes']['api_credits'] ) ) {
			$credits = (float) $data['data']['attributes']['api_credits'];
			$credits = number_format_i18n( $credits, floor( $credits ) === $credits ? 0 : 1 );
		}
		/* translators: %s: GTmetrix API credits left. */
		return sprintf( __( '%s credits left', 'site-agent-mcp-lite' ), $credits );
	}

	/** Serve the IndexNow proof file, which engines fetch to verify the site owner. */
	public static function serve_indexnow_key() {
		$key = (string) get_option( self::INDEXNOW_OPTION, '' );
		if ( '' === $key ) {
			return;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ) . '/' . $key . '.txt' === $path ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $key );
			exit;
		}
	}

	/**
	 * Submit URLs to IndexNow.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On invalid input or API failure.
	 */
	public static function indexnow_submit( array $args ) {
		$urls = isset( $args['urls'] ) && is_array( $args['urls'] ) ? array_values( array_filter( $args['urls'], 'is_string' ) ) : array();
		if ( ! $urls || count( $urls ) > 10000 ) {
			throw new Tool_Error( 'urls must list between 1 and 10,000 pages.' );
		}
		foreach ( $urls as $url ) {
			Admin_Bridge::assert_same_host( $url );
		}
		$key = (string) get_option( self::INDEXNOW_OPTION, '' );
		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 16 ) );
			Policy::own_write(
				static function () use ( $key ) {
					update_option( self::INDEXNOW_OPTION, $key, false );
				}
			);
		}
		$response = wp_remote_post(
			'https://api.indexnow.org/indexnow',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => $urls,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Tool_Error( 'IndexNow did not answer: ' . esc_html( $response->get_error_message() ) );
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$meanings = array(
			200 => 'Received.',
			202 => 'Received. The key is still being validated.',
			400 => 'Bad request format.',
			403 => 'Key refused. Check that the key file is reachable.',
			422 => 'URLs do not belong to this host, or the key does not match.',
			429 => 'Too many requests.',
		);
		return array(
			'_is_error' => $status >= 400,
			'status'    => $status,
			'meaning'   => isset( $meanings[ $status ] ) ? $meanings[ $status ] : 'Unexpected answer.',
			'submitted' => count( $urls ),
			'note'      => 'Google does not take part in IndexNow. Being notified does not guarantee indexing.',
		);
	}

	/**
	 * Active SEO plugins with known post meta.
	 *
	 * @return string[]
	 * @throws Tool_Error When only an unsupported one is active.
	 */
	private static function seo_plugins() {
		$active = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$active[] = 'rank_math';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$active[] = 'seopress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$active[] = 'tsf';
		}
		if ( ! $active ) {
			throw new Tool_Error( defined( 'AIOSEO_VERSION' ) ? 'All in One SEO keeps these fields in its own aioseo_posts table, which this tool does not support yet.' : 'No supported SEO plugin is active: Yoast, Rank Math, SEOPress or The SEO Framework.' );
		}
		return $active;
	}

	/**
	 * Require the right to edit a post.
	 *
	 * @param array $args Arguments.
	 * @return int Post id.
	 * @throws Tool_Error When refused.
	 */
	private static function editable_post( array $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			throw new Tool_Error( 'Unknown post_id.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Tool_Error( 'Your WordPress account cannot edit this content.' );
		}
		return $post_id;
	}

	/**
	 * Whether a plugin's stored value means noindex.
	 *
	 * @param string $plugin Plugin.
	 * @param mixed  $value  Stored value.
	 * @return bool
	 */
	private static function is_noindex( $plugin, $value ) {
		if ( 'yoast' === $plugin ) {
			return '1' === (string) $value;
		}
		if ( 'rank_math' === $plugin ) {
			return in_array( 'noindex', (array) $value, true );
		}
		if ( 'tsf' === $plugin ) {
			return '1' === (string) $value;
		}
		return 'yes' === $value;
	}

	/**
	 * Read SEO fields.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function seo_meta_get( array $args ) {
		$post_id = self::editable_post( $args );
		$out     = array();
		foreach ( self::seo_plugins() as $plugin ) {
			$fields = array();
			foreach ( self::META_KEYS[ $plugin ] as $field => $key ) {
				$value            = get_post_meta( $post_id, $key, true );
				$fields[ $field ] = 'noindex' === $field ? self::is_noindex( $plugin, $value ) : $value;
			}
			$out[ $plugin ] = $fields;
		}
		return array(
			'content_trust' => 'untrusted_site_content',
			'post_id'       => $post_id,
			'plugins'       => $out,
			'note'          => 'An empty field means the plugin uses its template. Variables such as %%title%% are expanded on the page; check the result with seo_audit.',
		);
	}

	/**
	 * Change SEO fields.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error When there is nothing to change.
	 */
	public static function seo_meta_set( array $args ) {
		$post_id = self::editable_post( $args );
		$fields  = array_intersect_key( $args, array_flip( array( 'title', 'description', 'canonical', 'focus_keyword', 'noindex' ) ) );
		if ( ! $fields ) {
			throw new Tool_Error( 'Give at least one field to change.' );
		}
		$before = array();
		foreach ( self::seo_plugins() as $plugin ) {
			foreach ( $fields as $field => $value ) {
				if ( ! isset( self::META_KEYS[ $plugin ][ $field ] ) ) {
					continue; // This plugin has no such field, e.g. focus keyword in The SEO Framework.
				}
				$key = self::META_KEYS[ $plugin ][ $field ];
				if ( ! isset( $before[ $key ] ) ) {
					$before[ $key ] = array(
						'exists' => metadata_exists( 'post', $post_id, $key ),
						'value'  => get_post_meta( $post_id, $key, true ),
					);
				}
				if ( 'noindex' === $field ) {
					self::write_noindex( $plugin, $post_id, $key, (bool) $value );
				} elseif ( '' === $value ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, wp_slash( sanitize_text_field( (string) $value ) ) );
				}
			}
		}
		$change = Journal::record( 'seo_meta_set', 'post ' . $post_id, array( 'post_meta' => array( $post_id => $before ) ) );
		return array(
			'post_id'   => $post_id,
			'updated'   => array_keys( $fields ),
			'change_id' => $change,
			'next'      => 'A page cache can delay the change. Check the public page with seo_audit.',
		);
	}

	/**
	 * Store noindex in a plugin's format.
	 *
	 * @param string $plugin  Plugin.
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @param bool   $noindex New state.
	 */
	private static function write_noindex( $plugin, $post_id, $key, $noindex ) {
		if ( 'yoast' === $plugin ) {
			update_post_meta( $post_id, $key, $noindex ? '1' : '2' );
		} elseif ( 'rank_math' === $plugin ) {
			$robots = array_values( array_diff( (array) get_post_meta( $post_id, $key, true ), array( 'index', 'noindex', '' ) ) );
			array_unshift( $robots, $noindex ? 'noindex' : 'index' );
			update_post_meta( $post_id, $key, $robots );
		} elseif ( 'tsf' === $plugin ) {
			// The SEO Framework: 1 forces noindex, an absent value follows the site default.
			if ( $noindex ) {
				update_post_meta( $post_id, $key, 1 );
			} else {
				delete_post_meta( $post_id, $key );
			}
		} elseif ( $noindex ) {
			update_post_meta( $post_id, $key, 'yes' );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * Write the theme's Additional CSS, past the filter that would encode ">" in selectors.
	 *
	 * @param string $css CSS.
	 * @throws Tool_Error On failure.
	 */
	public static function write_custom_css( $css ) {
		kses_remove_filters();
		$result = wp_update_custom_css_post( $css, array( 'stylesheet' => get_stylesheet() ) );
		kses_init();
		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( 'The CSS was refused: ' . esc_html( $result->get_error_message() ) );
		}
	}

	/**
	 * Read or change the site CSS.
	 *
	 * @param array $args Arguments.
	 * @return array
	 * @throws Tool_Error On invalid CSS.
	 */
	public static function custom_css_update( array $args ) {
		if ( is_multisite() && ! is_super_admin() ) {
			throw new Tool_Error( 'On a multisite network, only super admins can edit the site CSS here.' );
		}
		$current = wp_get_custom_css();
		$mode    = isset( $args['mode'] ) ? (string) $args['mode'] : 'read';
		if ( 'read' === $mode ) {
			return array( 'css' => $current );
		}
		$css = isset( $args['css'] ) ? (string) $args['css'] : '';
		// CSS has no use for "<". Refusing it keeps markup out, so this cannot become a script injection.
		if ( false !== strpos( $css, '<' ) ) {
			throw new Tool_Error( 'CSS must not contain "<". Send only the rules, without a style tag.' );
		}
		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			throw new Tool_Error( 'The braces in this CSS do not balance. Nothing was written.' );
		}
		$new    = 'append' === $mode ? rtrim( $current ) . "\n" . $css : $css;
		$change = Journal::record( 'custom_css_update', get_stylesheet(), array( 'custom_css' => array( 'css' => $current ) ) );
		self::write_custom_css( $new );
		return array(
			'bytes'     => strlen( wp_get_custom_css() ),
			'change_id' => $change,
			'next'      => 'Run design_check, then look at the page in a browser.',
		);
	}

	/**
	 * Check what styling visitors really receive.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function design_check( array $args ) {
		$response = Admin_Bridge::send( 'GET', self::site_url_arg( $args ) );
		$xpath    = new \DOMXPath( Admin_Bridge::dom( $response['body'] ) );

		$css = '';
		foreach ( $xpath->query( '//style' ) as $style ) {
			$css .= $style->textContent;
		}
		$sheets = 0;
		foreach ( $xpath->query( '//link[@rel="stylesheet"]/@href' ) as $href ) {
			if ( $sheets >= 12 ) {
				break;
			}
			$sheet = \WP_Http::make_absolute_url( (string) $href->nodeValue, $response['final_url'] );
			try {
				$file = Admin_Bridge::send( 'GET', $sheet );
				$css .= $file['body'];
				++$sheets;
			} catch ( Tool_Error $e ) {
				continue;
			}
		}

		$classes = array();
		foreach ( $xpath->query( '//*[contains(@class,"wp-block-post-content") or contains(@class,"entry-content")]//*[@class]' ) as $node ) {
			foreach ( preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ) as $class ) {
				if ( '' !== $class && ! preg_match( '/^(wp-|has-|is-|align|size-|attachment-)/', $class ) ) {
					$classes[ $class ] = true;
				}
			}
		}
		$unstyled = array();
		foreach ( array_keys( $classes ) as $class ) {
			if ( ! preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $css ) ) {
				$unstyled[] = $class;
			}
		}

		$warnings = array();
		if ( count( $classes ) >= 4 && count( $unstyled ) * 2 > count( $classes ) ) {
			$warnings[] = 'Most of the classes in this page have no CSS, so visitors will see it unstyled. The CSS was probably removed on save: use custom_css_update.';
		}
		$body_text = '';
		foreach ( $xpath->query( '//*[contains(@class,"wp-block-post-content") or contains(@class,"entry-content")]' ) as $node ) {
			$body_text .= $node->textContent;
		}
		if ( preg_match( '/[.#][\w-]+\s*\{[^}]*:[^}]*\}/', $body_text ) ) {
			$warnings[] = 'CSS rules appear as text in the page content: a style tag was removed and its contents stayed behind.';
		}

		$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'layout' ) ) : array();
		return array(
			'content_trust'    => 'untrusted_site_content',
			'status'           => $response['status'],
			'warnings'         => $warnings,
			'custom_classes'   => count( $classes ),
			'classes_without_css' => array_slice( $unstyled, 0, 40 ),
			'stylesheets_read' => $sheets,
			'theme'            => array(
				'content_width'    => isset( $settings['contentSize'] ) ? $settings['contentSize'] : null,
				'wide_width'       => isset( $settings['wideSize'] ) ? $settings['wideSize'] : null,
				'theme_header'     => $xpath->query( '//header[contains(@class,"wp-block-template-part")]' )->length > 0,
				'theme_footer'     => $xpath->query( '//footer[contains(@class,"wp-block-template-part")]' )->length > 0,
				'content_constrained' => $xpath->query( '//*[contains(@class,"wp-block-post-content") and contains(@class,"is-layout-constrained")]' )->length > 0,
			),
			'limit'            => 'This reads HTML and CSS only and cannot see the visual result. A hidden theme header still counts as present. Check desktop and mobile in a browser.',
		);
	}
}
