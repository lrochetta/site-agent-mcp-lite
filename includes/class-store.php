<?php
/**
 * Database tables, OAuth clients and tokens.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for clients, authorization codes, tokens and the audit log.
 * Only SHA-256 hashes of codes, tokens and client secrets are stored.
 */
class Store {

	const DB_VERSION     = '2';
	const CODE_TTL       = 60;
	const ACCESS_TTL     = 3600;
	const REFRESH_TTL    = 7776000; // 90 days.
	const REFRESH_GRACE  = 10; // Parallel refreshes from one client must not revoke its grant.
	const GRANT_MAX_AGE  = 15552000; // 180 days: past this, the person connects the app again.
	const RETENTION_DAYS = 30;

	/** Register hooks. */
	public static function boot() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'samcp_daily_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'deleted_user', array( __CLASS__, 'revoke_user' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'revoke_user_object' ) );
		add_action( 'profile_update', array( __CLASS__, 'revoke_on_password_change' ), 10, 2 );
		add_action( 'wp_set_password', array( __CLASS__, 'revoke_on_set_password' ), 10, 2 );
	}

	/**
	 * Table name with the site prefix.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'samcp_' . $name;
	}

	/** Create or upgrade tables and schedule the cleanup. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$clients = self::table( 'clients' );
		$tokens  = self::table( 'tokens' );
		$audit   = self::table( 'audit' );
		$changes = self::table( 'changes' );

		dbDelta(
			"CREATE TABLE {$clients} (
				client_id varchar(191) NOT NULL,
				client_secret_hash char(64) DEFAULT NULL,
				client_name varchar(191) NOT NULL,
				redirect_uris longtext NOT NULL,
				source varchar(20) NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				last_used_at bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (client_id)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				family char(32) NOT NULL,
				kind varchar(10) NOT NULL,
				token_hash char(64) NOT NULL,
				client_id varchar(191) NOT NULL,
				client_name varchar(191) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				scope varchar(191) NOT NULL,
				resource varchar(255) NOT NULL,
				redirect_uri text NULL,
				code_challenge varchar(128) DEFAULT NULL,
				created_at bigint(20) unsigned NOT NULL,
				granted_at bigint(20) unsigned DEFAULT NULL,
				expires_at bigint(20) unsigned NOT NULL,
				used_at bigint(20) unsigned DEFAULT NULL,
				revoked_at bigint(20) unsigned DEFAULT NULL,
				last_used_at bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY family (family),
				KEY user_id (user_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				client_name varchar(191) NOT NULL,
				tool varchar(64) NOT NULL,
				summary text NULL,
				is_error tinyint(1) NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$changes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				tool varchar(64) NOT NULL,
				label varchar(191) NOT NULL,
				before_state longtext NOT NULL,
				undone_at bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'samcp_db_version', self::DB_VERSION, false );
		// The next administrator screen probes the Authorization header again.
		delete_option( 'samcp_header_check' );
		if ( ! wp_next_scheduled( 'samcp_daily_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'samcp_daily_cleanup' );
		}
	}

	/**
	 * Stop scheduled work and end every connection: switching the plugin off must cut the agents. Data stays until uninstall.
	 * A network deactivation does it on every site of the network, since each site has its own tables.
	 *
	 * @param bool $network_wide Deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ) {
		$sites = is_multisite() && $network_wide ? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) : array( 0 );
		foreach ( $sites as $site_id ) {
			if ( $site_id ) {
				switch_to_blog( (int) $site_id );
			}
			self::deactivate_site();
			if ( $site_id ) {
				restore_current_blog();
			}
		}
	}

	/** Deactivation work for the current site. */
	private static function deactivate_site() {
		global $wpdb;
		wp_clear_scheduled_hook( 'samcp_daily_cleanup' );
		wp_clear_scheduled_hook( 'samcp_backup_tick' );
		$table = self::table( 'tokens' );
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %d WHERE revoked_at IS NULL', $table, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		}
	}

	/** Upgrade the schema after a plugin update. */
	public static function maybe_upgrade() {
		$from = get_option( 'samcp_db_version' );
		if ( self::DB_VERSION === $from ) {
			return;
		}
		// This can run inside any request, an agent's included: these writes are the plugin's own.
		Policy::own_write(
			static function () use ( $from ) {
				self::install();
				if ( '1' === $from ) {
					self::upgrade_from_1();
				}
			}
		);
	}

	/**
	 * From 0.7.x and 0.8.0: date every grant, and keep the access of accounts already connected.
	 * Before 0.8.0 every account could connect with everything it could do; 0.8.0's defaults
	 * would cut authors, shop managers and other roles without a word.
	 */
	private static function upgrade_from_1() {
		global $wpdb;
		$table = self::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i t JOIN (SELECT family, MIN(created_at) AS started FROM %i GROUP BY family) f ON t.family = f.family SET t.granted_at = f.started WHERE t.granted_at IS NULL', $table, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists

		$connections = self::connections( null );
		if ( false === get_option( 'samcp_access' ) ) {
			$kept  = array();
			$roles = Policy::default_roles();
			foreach ( $connections as $row ) {
				$user_id = (int) $row['user_id'];
				$user    = get_userdata( $user_id );
				if ( ! $user || isset( $kept[ $user_id ] ) ) {
					continue;
				}
				$best = 'none';
				foreach ( (array) $user->roles as $role ) {
					$level = isset( $roles[ $role ] ) ? $roles[ $role ] : 'none';
					$best  = Policy::rank( $level ) > Policy::rank( $best ) ? $level : $best;
				}
				if ( 'full' !== $best ) {
					$kept[ $user_id ] = 'full';
				}
			}
			Policy::save_settings( $roles, $kept );
			if ( $kept ) {
				update_option( 'samcp_upgrade_kept', count( $kept ), false );
			}
		}
		// A Lite site that already had connections stays switched on.
		if ( 'lite' === SAMCP_EDITION && false === get_option( 'samcp_enabled' ) && $connections ) {
			update_option( 'samcp_enabled', 1, false );
		}
	}

	/**
	 * Generate an opaque secret.
	 *
	 * @param int $bytes Random bytes.
	 * @return string
	 */
	public static function random( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Hash a secret for storage.
	 *
	 * @param string $value Secret.
	 * @return string
	 */
	public static function hash( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * Shorten text without splitting a character. WordPress silently drops a row holding invalid UTF-8.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Maximum bytes.
	 * @return string
	 */
	public static function cut( $text, $bytes ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $text, 0, $bytes, 'UTF-8' );
		}
		return (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $text, 0, $bytes ) );
	}

	/**
	 * Insert a client.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @param string   $source        dcr or manual.
	 * @param bool     $confidential  Generate a secret.
	 * @return array Client with its id and, once only, its secret.
	 */
	public static function create_client( $name, array $redirect_uris, $source, $confidential = false ) {
		global $wpdb;
		$client_id = 'samcp_' . self::random( 16 );
		$secret    = $confidential ? self::random( 32 ) : null;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			self::table( 'clients' ),
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => $secret ? self::hash( $secret ) : null,
				'client_name'        => self::cut( $name, 191 ),
				'redirect_uris'      => wp_json_encode( array_values( $redirect_uris ) ),
				'source'             => $source,
				'created_at'         => time(),
			)
		);
		return array(
			'client_id'     => $client_id,
			'client_secret' => $secret,
			'client_name'   => $name,
			'redirect_uris' => array_values( $redirect_uris ),
		);
	}

	/**
	 * Read a registered client.
	 *
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	public static function get_client( $client_id ) {
		global $wpdb;
		$table = self::table( 'clients' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', $table, $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		if ( ! $row ) {
			return null;
		}
		$row['redirect_uris'] = (array) json_decode( $row['redirect_uris'], true );
		return $row;
	}

	/**
	 * List clients created by an administrator.
	 *
	 * @return array[]
	 */
	public static function manual_clients() {
		global $wpdb;
		$table = self::table( 'clients' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT client_id, client_name, redirect_uris, created_at FROM %i WHERE source = 'manual' ORDER BY created_at DESC", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Delete an administrator-created client and everything it was granted.
	 *
	 * @param string $client_id Client id.
	 */
	public static function delete_client( $client_id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'clients' ), array( 'client_id' => $client_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$wpdb->update( self::table( 'tokens' ), array( 'revoked_at' => time() ), array( 'client_id' => $client_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Store a code or token and return the plain value.
	 *
	 * @param string $kind   code, access or refresh.
	 * @param array  $fields family, client_id, client_name, user_id, scope, resource, optional redirect_uri and code_challenge.
	 * @param int    $ttl    Lifetime in seconds.
	 * @return string Plain value, or an empty string when the row could not be stored.
	 */
	public static function issue( $kind, array $fields, $ttl ) {
		global $wpdb;
		$value = self::random( 32 );
		$now   = time();
		$saved = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			self::table( 'tokens' ),
			array(
				'family'         => $fields['family'],
				'kind'           => $kind,
				'token_hash'     => self::hash( $value ),
				'client_id'      => $fields['client_id'],
				'client_name'    => $fields['client_name'],
				'user_id'        => $fields['user_id'],
				'scope'          => $fields['scope'],
				'resource'       => $fields['resource'],
				'redirect_uri'   => isset( $fields['redirect_uri'] ) ? $fields['redirect_uri'] : null,
				'code_challenge' => isset( $fields['code_challenge'] ) ? $fields['code_challenge'] : null,
				'created_at'     => $now,
				'granted_at'     => ! empty( $fields['granted_at'] ) ? (int) $fields['granted_at'] : $now,
				'expires_at'     => $now + $ttl,
			)
		);
		// A value that is not stored would be announced to the client and then refused everywhere.
		return 1 === $saved ? $value : '';
	}

	/**
	 * Delete a code or token that was stored but must not be handed out.
	 *
	 * @param string|null $value Plain value; empty values are ignored.
	 */
	public static function discard( $value ) {
		global $wpdb;
		if ( null === $value || '' === $value ) {
			return;
		}
		$wpdb->delete( self::table( 'tokens' ), array( 'token_hash' => self::hash( $value ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Undo consume() when nothing was handed out, so the client can try the same code or token again.
	 *
	 * @param int $id Row id.
	 */
	public static function release( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = NULL WHERE id = %d AND revoked_at IS NULL', self::table( 'tokens' ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Whether an authorization was withdrawn. Revocation always marks whole families, so one
	 * revoked row is a lasting mark: rows a concurrent refresh inserts afterwards stay refused.
	 *
	 * @param string $family Family id.
	 * @return bool
	 */
	public static function family_revoked( $family ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE family = %s AND revoked_at IS NOT NULL LIMIT 1', $table, $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Whether the authorization a token descends from is past its maximum age.
	 *
	 * @param array $row Token row.
	 * @return bool
	 */
	public static function grant_expired( array $row ) {
		return self::granted_at( $row ) < time() - self::GRANT_MAX_AGE;
	}

	/**
	 * Find a code or token by plain value, whatever its state.
	 *
	 * @param string $value Plain value.
	 * @param string $kind  Expected kind.
	 * @return array|null
	 */
	public static function find( $value, $kind ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND kind = %s', $table, self::hash( $value ), $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Mark a row used. Only one concurrent caller wins.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function consume( $id ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %d WHERE id = %d AND used_at IS NULL', $table, time(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Revoke everything descended from one authorization.
	 *
	 * @param string $family Family id.
	 */
	public static function revoke_family( $family ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %d WHERE family = %s AND revoked_at IS NULL', $table, time(), $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * When the person approved the authorization a family descends from.
	 *
	 * @param string $family Family id.
	 * @return int
	 */
	public static function family_started( $family ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE family = %s', $table, $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Whether a family got 5 access tokens in the last minute and none was ever used.
	 *
	 * @param string $family Family id.
	 * @return bool
	 */
	public static function unused_burst( $family ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		// Only recent tokens count: a connection that worked once and then stopped receiving its header loops too.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(kind = 'access' AND created_at > %d) AS recent, SUM(created_at > %d AND last_used_at IS NOT NULL) AS used FROM %i WHERE family = %s", time() - MINUTE_IN_SECONDS, time() - 10 * MINUTE_IN_SECONDS, $table, $family ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		return $row && (int) $row['recent'] >= 5 && 0 === (int) $row['used'];
	}

	/**
	 * When the person approved the authorization a token descends from. Rows from before
	 * granted_at existed fall back to the oldest row of the family.
	 *
	 * @param array $row Token row.
	 * @return int
	 */
	public static function granted_at( array $row ) {
		return ! empty( $row['granted_at'] ) ? (int) $row['granted_at'] : self::family_started( (string) $row['family'] );
	}

	/**
	 * Revoke all of a user's connections.
	 *
	 * @param int $user_id User id.
	 */
	public static function revoke_user( $user_id ) {
		global $wpdb;
		// Accounts are network-wide while tokens are per site, so a password change must reach every site.
		$sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
		foreach ( $sites as $site_id ) {
			if ( $site_id ) {
				switch_to_blog( $site_id );
			}
			$table = self::table( 'tokens' );
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %d WHERE user_id = %d AND revoked_at IS NULL', $table, time(), $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			}
			if ( $site_id ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Revoke connections after a password reset.
	 *
	 * @param \WP_User $user User.
	 */
	public static function revoke_user_object( $user ) {
		self::revoke_user( $user->ID );
	}

	/**
	 * Revoke connections when a profile update changes the password.
	 *
	 * @param int      $user_id  User id.
	 * @param \WP_User $old_user User before the update.
	 */
	public static function revoke_on_password_change( $user_id, $old_user ) {
		$user = get_userdata( $user_id );
		if ( $user && $old_user && $user->user_pass !== $old_user->user_pass ) {
			self::revoke_user( $user_id );
		}
	}

	/**
	 * Revoke connections when a password is set directly (wp_set_password, WordPress 6.2+).
	 *
	 * @param string $password Password, unused.
	 * @param int    $user_id  User id.
	 */
	public static function revoke_on_set_password( $password, $user_id ) {
		unset( $password );
		self::revoke_user( (int) $user_id );
	}

	/**
	 * Query of the active connections, one row per authorization, not yet ordered.
	 *
	 * @param int|null $user_id Limit to a user.
	 * @return string Prepared SQL.
	 */
	private static function connections_query( $user_id ) {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT family, client_name, user_id, scope, MIN(created_at) AS created_at, MAX(last_used_at) AS last_used_at FROM %i WHERE kind IN ('access','refresh') AND revoked_at IS NULL AND expires_at > %d AND (kind = 'access' OR used_at IS NULL)", self::table( 'tokens' ), time() );
		if ( null !== $user_id ) {
			$sql .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		return $sql . ' GROUP BY family, client_name, user_id, scope';
	}

	/**
	 * Active connections, one row per authorization, newest first in a stable order.
	 *
	 * @param int|null $user_id Limit to a user.
	 * @param int      $limit   Rows per page, 0 for every row.
	 * @param int      $offset  Rows to skip.
	 * @return array[]
	 */
	public static function connections( $user_id = null, $limit = 0, $offset = 0 ) {
		global $wpdb;
		// The family breaks ties, so two connections made the same second never swap pages.
		$sql = self::connections_query( $user_id ) . ' ORDER BY created_at DESC, family ASC';
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, max( 0, (int) $offset ) );
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own tables, built from prepared parts above.
	}

	/**
	 * Totals of the active connections, counted apart from any page of the list.
	 *
	 * @param int|null $user_id Limit to a user.
	 * @return array{total:int,power:int,last_used:int} Connections, those with server tools, latest use.
	 */
	public static function connection_stats( $user_id = null ) {
		global $wpdb;
		$power = esc_sql( OAuth::SCOPE_POWER );
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(INSTR(scope, '{$power}') > 0), 0) AS power, COALESCE(MAX(last_used_at), 0) AS last_used FROM (" . self::connections_query( $user_id ) . ') AS c', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, built from prepared parts and a constant.
		return array(
			'total'     => isset( $row['total'] ) ? (int) $row['total'] : 0,
			'power'     => isset( $row['power'] ) ? (int) $row['power'] : 0,
			'last_used' => isset( $row['last_used'] ) ? (int) $row['last_used'] : 0,
		);
	}

	/**
	 * Owner of an authorization family.
	 *
	 * @param string $family Family id.
	 * @return int
	 */
	public static function family_owner( $family ) {
		global $wpdb;
		$table = self::table( 'tokens' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE family = %s LIMIT 1', $table, $family ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Record a tool call.
	 *
	 * @param int    $user_id     User.
	 * @param string $client_name Client.
	 * @param string $tool        Tool.
	 * @param string $summary     Redacted summary of the arguments.
	 * @param bool   $is_error    Failed.
	 * @param int    $duration_ms Duration.
	 * @return int Row id.
	 */
	public static function audit( $user_id, $client_name, $tool, $summary, $is_error, $duration_ms ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			self::table( 'audit' ),
			array(
				'created_at'  => time(),
				'user_id'     => $user_id,
				'client_name' => self::cut( $client_name, 191 ),
				'tool'        => self::cut( $tool, 64 ),
				'summary'     => self::cut( $summary, 500 ),
				'is_error'    => $is_error ? 1 : 0,
				'duration_ms' => $duration_ms,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Complete an audit row written before a tool ran.
	 *
	 * @param int  $id          Row id.
	 * @param bool $is_error    Failed.
	 * @param int  $duration_ms Duration.
	 */
	public static function audit_finish( $id, $is_error, $duration_ms, $summary = null ) {
		global $wpdb;
		$fields = array(
			'is_error'    => $is_error ? 1 : 0,
			'duration_ms' => $duration_ms,
		);
		if ( null !== $summary ) {
			$fields['summary'] = self::cut( $summary, 500 );
		}
		$wpdb->update( self::table( 'audit' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Latest audit rows.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function recent_audit( $limit = 50 ) {
		global $wpdb;
		$table = self::table( 'audit' );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * One page of audit rows, newest first.
	 *
	 * @param bool $failed_only Failed calls only.
	 * @param int  $limit       Rows.
	 * @param int  $offset      Rows skipped.
	 * @return array[]
	 */
	public static function audit_page( $failed_only, $limit, $offset ) {
		global $wpdb;
		$table = self::table( 'audit' );
		// is_error is 0 or 1, so a minimum of 0 keeps every row and 1 keeps failed calls only.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE is_error >= %d ORDER BY id DESC LIMIT %d OFFSET %d', $table, $failed_only ? 1 : 0, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Number of audit rows kept.
	 *
	 * @param bool $failed_only Failed calls only.
	 * @return int
	 */
	public static function audit_count( $failed_only ) {
		global $wpdb;
		$table = self::table( 'audit' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_error >= %d', $table, $failed_only ? 1 : 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
	}

	/**
	 * Count recent events for one key and report whether the limit is passed.
	 *
	 * @param string $bucket Key.
	 * @param int    $limit  Maximum events.
	 * @param int    $window Window in seconds.
	 * @return bool True when the caller is over the limit.
	 */
	public static function over_limit( $bucket, $limit, $window ) {
		$key   = 'samcp_rl_' . md5( $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Count one event in a fixed time window and say how long to wait when the limit is reached.
	 * Unlike over_limit(), steady use never extends the window.
	 *
	 * @param string $bucket Key.
	 * @param int    $limit  Maximum events per window; 0 or less means no limit.
	 * @param int    $window Window in seconds.
	 * @return int Seconds to wait, or 0 when the event is allowed.
	 */
	public static function throttle( $bucket, $limit, $window ) {
		if ( $limit <= 0 ) {
			return 0;
		}
		$slot  = (int) floor( time() / $window );
		$key   = 'samcp_tl_' . md5( $bucket . '|' . $slot );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return max( 1, ( $slot + 1 ) * $window - time() );
		}
		set_transient( $key, $count + 1, $window );
		return 0;
	}

	/** Remove expired tokens, unused registrations and old history. */
	public static function cleanup() {
		global $wpdb;
		$tokens  = self::table( 'tokens' );
		$clients = self::table( 'clients' );
		$cutoff  = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
		// 30 days after expiry or revocation, whichever comes first: a revoked 90-day refresh token must not stay 120 days.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d OR revoked_at < %d', $tokens, $cutoff, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', self::table( 'audit' ), $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', self::table( 'changes' ), $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$wpdb->query( $wpdb->prepare( "DELETE c FROM %i c LEFT JOIN %i t ON t.client_id = c.client_id WHERE c.source = 'dcr' AND c.created_at < %d AND t.id IS NULL", $clients, $tokens, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		// A host can start or stop dropping the Authorization header at any time: check again every day.
		Header_Check::run();
	}
}
