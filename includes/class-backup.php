<?php
/**
 * Full backups in resumable slices, and their verification.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * A backup is a folder holding database.sql (one statement per line), files.tar
 * (ustar with GNU long names), files.index (path, size, md5) and manifest.json.
 * Work happens in slices of a few seconds, so shared hosting time limits do not
 * matter: agents, the admin screen and WP-Cron all advance the same job. State
 * lives in job.json next to the archive, never in the database being saved.
 * Design: docs/backup-deploy-design.md.
 */
class Backup {

	const FORMAT     = 'samcp-backup-1';
	const DIR_OPTION = 'samcp_backup_dir';
	const CRON_HOOK  = 'samcp_backup_tick';
	const ROWS       = 500;
	const ROW_BYTES  = 1048576;
	const CHUNK      = 1048576;
	const HASH_LIMIT = 20971520;
	/** Folders never archived: caches and other backup plugins' archives. */
	const EXCLUDED = array( 'wp-content/cache', 'wp-content/upgrade', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-lite', 'wp-content/backups-dup-pro', 'wp-content/wpvivid_backup', 'wp-content/backup-db', 'wp-content/uploads/backwpup', 'wp-content/et-cache' );

	/** Register hooks. */
	public static function boot() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
		add_action( 'wp_ajax_samcp_backup_step', array( __CLASS__, 'ajax_step' ) );
		foreach ( array( 'create', 'verify', 'delete', 'download' ) as $action ) {
			add_action( 'admin_post_samcp_backup_' . $action, array( __CLASS__, 'admin_' . $action ) );
		}
	}

	/* ---------------------------------------------------------------- admin */

	/**
	 * Whether the current user may handle backups. On multisite a backup holds every
	 * site of the network and its users, so only network administrators may.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return is_multisite() ? is_super_admin() : current_user_can( 'manage_options' );
	}

	/**
	 * Refuse backup tools to an agent whose user may not handle backups.
	 *
	 * @throws Tool_Error When the user may not.
	 */
	private static function tool_guard() {
		if ( ! self::can_manage() ) {
			throw new Tool_Error( 'On a multisite network only network administrators can use backups, because a backup holds every site of the network.' );
		}
	}

	/**
	 * Check the nonce and right of an admin action.
	 *
	 * @param string $action Nonce action.
	 * @return string Backup id from the request, may be empty.
	 */
	private static function admin_guard( $action ) {
		check_admin_referer( $action );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'site-agent-mcp-lite' ), 403 );
		}
		return isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Back to the screen with a notice.
	 *
	 * Errors from the backup engine (start, verify, delete) are also tool results
	 * for agents, so they stay in English; the notice before them is translated.
	 *
	 * @param string $notice Notice key.
	 * @param string $detail Optional detail, kept for one page load.
	 */
	private static function admin_back( $notice, $detail = '' ) {
		if ( '' !== $detail ) {
			set_transient( 'samcp_backup_detail_' . get_current_user_id(), $detail, 300 );
		}
		$args = array( 'samcp_notice' => $notice );
		wp_safe_redirect( Admin_Page::url( 'backups', $args ) );
		exit;
	}

	/** Admin: start a backup. */
	public static function admin_create() {
		self::admin_guard( 'samcp_backup_create' );
		try {
			self::step( self::start() );
		} catch ( Tool_Error $e ) {
			self::admin_back( 'backup_failed', $e->getMessage() );
		}
		self::admin_back( 'backup_started' );
	}

	/** Admin: verify a backup. */
	public static function admin_verify() {
		$id = self::admin_guard( 'samcp_backup_verify' );
		try {
			$report = self::verify( $id );
		} catch ( Tool_Error $e ) {
			self::admin_back( 'backup_failed', $e->getMessage() );
		}
		$detail = implode( ' ', $report['problems'] );
		if ( $report['ok'] ) {
			$detail = sprintf(
				/* translators: Result of a backup check. 1: number of files, such as "3,400 files", 2: number of tables, such as "12 tables". */
				__( '%1$s, %2$s.', 'site-agent-mcp-lite' ),
				/* translators: %s: number of files. */
				sprintf( _n( '%s file', '%s files', (int) $report['files'], 'site-agent-mcp-lite' ), number_format_i18n( (int) $report['files'] ) ),
				/* translators: %s: number of database tables. */
				sprintf( _n( '%s table', '%s tables', (int) $report['tables'], 'site-agent-mcp-lite' ), number_format_i18n( (int) $report['tables'] ) )
			);
		}
		self::admin_back( $report['ok'] ? 'backup_verified' : 'backup_failed', $detail );
	}

	/** Admin: delete a backup. */
	public static function admin_delete() {
		$id = self::admin_guard( 'samcp_backup_delete' );
		try {
			self::delete( $id );
		} catch ( Tool_Error $e ) {
			self::admin_back( 'backup_failed', $e->getMessage() );
		}
		self::admin_back( 'backup_deleted' );
	}

	/** Admin: download one part of a backup. */
	public static function admin_download() {
		$id   = self::admin_guard( 'samcp_backup_download' );
		$part = isset( $_GET['part'] ) ? sanitize_file_name( wp_unslash( $_GET['part'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $part, array( 'manifest.json', 'database.sql', 'files.tar', 'files.index' ), true ) ) {
			wp_die( esc_html__( 'Unknown file.', 'site-agent-mcp-lite' ), 404 );
		}
		$path = self::folder( $id ) . '/' . $part;
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Unknown file.', 'site-agent-mcp-lite' ), 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $id . '-' . $part . '"' );
		header( 'Content-Length: ' . self::size( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}


	/** Backups section of the settings screen. */
	public static function render_section() {
		$backups = self::all();
		$running = null;
		foreach ( $backups as $backup ) {
			if ( ! in_array( $backup['state'], array( 'complete', 'failed' ), true ) ) {
				$running = $backup;
			}
		}
		try {
			$web = self::web_path( self::storage() );
		} catch ( Tool_Error $e ) {
			$web = null;
		}
		$post = admin_url( 'admin-post.php' );
		?>
		<section class="samcp-panel" aria-labelledby="samcp-backups-title">
			<h2 id="samcp-backups-title"><?php esc_html_e( 'Backups', 'site-agent-mcp-lite' ); ?></h2>
			<p class="samcp-lead"><?php esc_html_e( 'A backup holds every database table of this WordPress and every file of the installation. It runs in short steps, so it works on shared hosting. Agents can create and verify backups, never restore them.', 'site-agent-mcp-lite' ); ?></p>
			<?php if ( 'lite' === SAMCP_EDITION ) : ?>
				<p class="description" id="samcp-restore-full">
					<?php esc_html_e( 'Restoring a backup from this screen is available in Site Agent MCP Full. With this edition, download the parts of a backup and restore them with your host\'s tools.', 'site-agent-mcp-lite' ); ?>
					<a href="<?php echo esc_url( SAMCP_FULL_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'About Site Agent MCP Full', 'site-agent-mcp-lite' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'site-agent-mcp-lite' ); ?></span></a>
				</p>
			<?php endif; ?>
			<?php if ( null !== $web ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: folder. */ __( 'Backups are kept in /%s, which Apache, LiteSpeed and IIS refuse to serve. On Nginx, block that folder or download each backup and delete it here.', 'site-agent-mcp-lite' ), $web ) ); ?></p>
			<?php endif; ?>
			<?php if ( $running ) : ?>
				<div id="samcp-backup-progress" class="samcp-progress">
					<p class="samcp-progress-text"><span class="dashicons dashicons-update samcp-spin" aria-hidden="true"></span> <span id="samcp-progress-text" data-samcp-progress-text><?php esc_html_e( 'Backup in progress', 'site-agent-mcp-lite' ); ?></span></p>
					<progress max="100" aria-labelledby="samcp-progress-text"></progress>
					<p class="description"><?php esc_html_e( 'It goes on if you leave this page, and goes faster while the page is open.', 'site-agent-mcp-lite' ); ?></p>
					<p class="screen-reader-text" role="status" data-samcp-progress-status></p>
					<div class="notice notice-error inline" data-samcp-progress-error hidden>
						<p><?php esc_html_e( 'Lost contact with the site after 3 attempts. The backup goes on in the background.', 'site-agent-mcp-lite' ); ?> <button type="button" class="button button-small" data-samcp-retry><?php esc_html_e( 'Try again', 'site-agent-mcp-lite' ); ?></button></p>
					</div>
					<noscript><p><?php esc_html_e( 'Reload this page to see the progress.', 'site-agent-mcp-lite' ); ?></p></noscript>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<?php wp_nonce_field( 'samcp_backup_create' ); ?>
					<input type="hidden" name="action" value="samcp_backup_create">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create a backup', 'site-agent-mcp-lite' ); ?></button>
				</form>
			<?php endif; ?>
		</section>

		<section class="samcp-panel" aria-labelledby="samcp-backup-list-title">
			<h2 id="samcp-backup-list-title"><?php esc_html_e( 'Backups on this server', 'site-agent-mcp-lite' ); ?></h2>
			<?php if ( ! $backups ) : ?>
				<p><?php esc_html_e( 'No backup yet. Create one before letting an agent change the site.', 'site-agent-mcp-lite' ); ?></p>
			<?php else : ?>
				<table class="widefat striped samcp-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Backups on this server, newest first', 'site-agent-mcp-lite' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Backup', 'site-agent-mcp-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'site-agent-mcp-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'site-agent-mcp-lite' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'site-agent-mcp-lite' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $backups as $backup ) : ?>
						<?php
						$when   = wp_date( 'Y-m-d H:i', strtotime( $backup['created'] ) );
						/* translators: %s: size in megabytes, such as 12.5. */
						$size   = sprintf( __( '%s MB', 'site-agent-mcp-lite' ), number_format_i18n( (float) $backup['size_mb'], 1 ) );
						$reason = '';
						if ( 'failed' === $backup['state'] ) {
							try {
								$job    = self::read_json( self::storage() . '/' . $backup['id'] . '/job.json' );
								$reason = $job && ! empty( $job['error'] ) ? (string) $job['error'] : '';
							} catch ( Tool_Error $e ) {
								$reason = '';
							}
						}
						/* translators: %s: backup date. */
						$of = sprintf( __( 'backup of %s', 'site-agent-mcp-lite' ), $when );
						?>
						<tr>
							<th scope="row"><strong class="samcp-nowrap"><?php echo esc_html( $when ); ?></strong><br><code><?php echo esc_html( $backup['id'] ); ?></code></th>
							<td>
								<?php
								if ( 'complete' === $backup['state'] ) {
									Admin_Page::badge( 'success', __( 'Complete', 'site-agent-mcp-lite' ) );
								} elseif ( 'failed' === $backup['state'] ) {
									Admin_Page::badge( 'error', __( 'Failed', 'site-agent-mcp-lite' ) );
								} else {
									Admin_Page::badge( 'info', __( 'In progress', 'site-agent-mcp-lite' ) );
								}
								?>
								<?php if ( $backup['warnings'] ) : ?>
									<br><span class="samcp-muted"><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d warning', '%d warnings', $backup['warnings'], 'site-agent-mcp-lite' ), $backup['warnings'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $reason ) : ?>
									<br><span class="samcp-break"><?php echo esc_html( $reason ); ?></span>
								<?php endif; ?>
							</td>
							<td class="samcp-nowrap"><?php echo esc_html( $size ); ?></td>
							<td>
								<div class="samcp-row-actions">
									<?php if ( 'complete' === $backup['state'] ) : ?>
										<?php foreach ( array( 'manifest.json', 'database.sql', 'files.tar' ) as $part ) : ?>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'samcp_backup_download', 'id' => $backup['id'], 'part' => $part ), $post ), 'samcp_backup_download' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php echo esc_html( $part ); ?><span class="screen-reader-text"> <?php /* translators: %s: "backup of" and its date. */ echo esc_html( sprintf( __( '(download, %s)', 'site-agent-mcp-lite' ), $of ) ); ?></span></a>
										<?php endforeach; ?>
										<form method="post" action="<?php echo esc_url( $post ); ?>">
											<?php wp_nonce_field( 'samcp_backup_verify' ); ?>
											<input type="hidden" name="action" value="samcp_backup_verify">
											<input type="hidden" name="id" value="<?php echo esc_attr( $backup['id'] ); ?>">
											<button type="submit" class="button button-small"><?php esc_html_e( 'Verify', 'site-agent-mcp-lite' ); ?><span class="screen-reader-text"> <?php echo esc_html( $of ); ?></span></button>
										</form>
										<?php do_action( 'samcp_backup_row_actions', $backup, $post ); ?>
									<?php endif; ?>
									<?php if ( ! $running || $running['id'] !== $backup['id'] ) : ?>
										<?php
										/* translators: %s: backup date. */
										$question = sprintf( __( 'Delete the backup of %s?', 'site-agent-mcp-lite' ), $when );
										/* translators: %s: size. */
										$consequence = sprintf( __( '%s are removed from this server. This cannot be undone.', 'site-agent-mcp-lite' ), $size );
										?>
										<form method="post" action="<?php echo esc_url( $post ); ?>"<?php echo Admin_Page::confirm_attributes( $question, $consequence, __( 'Delete backup', 'site-agent-mcp-lite' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
											<?php wp_nonce_field( 'samcp_backup_delete' ); ?>
											<input type="hidden" name="action" value="samcp_backup_delete">
											<input type="hidden" name="id" value="<?php echo esc_attr( $backup['id'] ); ?>">
											<?php Admin_Page::confirm_fallback( 'samcp-confirm-delete-' . $backup['id'], __( 'Confirm', 'site-agent-mcp-lite' ) ); ?>
											<button type="submit" class="button button-small samcp-button-danger"><?php esc_html_e( 'Delete', 'site-agent-mcp-lite' ); ?><span class="screen-reader-text"> <?php echo esc_html( $of ); ?></span></button>
										</form>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Tool definitions.
	 *
	 * @param callable $make Entry factory.
	 * @return array
	 */
	public static function definitions( callable $make ) {
		$str = array( 'type' => 'string' );
		$c   = __NAMESPACE__ . '\\Backup::';
		// On multisite a backup holds the whole network: network administrators only.
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		$how = 'full' === SAMCP_EDITION
			? 'Restoring is done by a person in Site Agent > Backups, never by an agent.'
			: 'Restoring is never done by an agent: the person downloads the backup parts from Site Agent > Backups.';
		return array(
			'backup_create' => $make( 'Create a full backup', 'Back up the whole site: every database table of this WordPress and every file of the installation, with a manifest of the environment. Runs in slices: call backup_status until the state is complete. ' . $how, array(), array(), 'write', $c . 'tool_create', $cap ),
			'backup_status' => $make( 'Backup progress and list', 'Advance a running backup by one slice and report its progress, and list the backups on this server.', array(), array(), 'write', $c . 'tool_status', $cap ),
			'backup_verify' => $make( 'Verify a backup', 'Read a finished backup back and compare it with its manifest: every file entry, sizes, checksums and database statements. Changes nothing.', array( 'id' => $str ), array( 'id' ), 'read', $c . 'tool_verify', $cap ),
		);
	}

	/* ---------------------------------------------------------------- storage */

	/**
	 * Folder holding the backups, created on first use. Outside the web root when
	 * the server allows it, otherwise a folder with an unguessable name and deny rules.
	 *
	 * @return string
	 * @throws Tool_Error When nothing is writable.
	 */
	public static function storage() {
		$above = wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) . '/samcp-backups' );
		$saved = wp_normalize_path( (string) get_option( self::DIR_OPTION, '' ) );
		// The saved folder is used only if it has one of the two shapes this plugin creates:
		// never trust a path just because it is stored, and put the deny rules back every time.
		if ( '' !== $saved && self::is_storage_shape( $saved, $above ) && is_dir( $saved ) && wp_is_writable( $saved ) && ! is_link( $saved ) ) {
			self::protect( $saved );
			return $saved;
		}
		$candidates = array();
		// With WordPress in a subfolder of the document root, the parent folder is still served;
		// an unknown document root counts as served.
		if ( self::reachable( dirname( $above ) ) && wp_is_writable( dirname( $above ) ) && null !== self::document_root() && ! self::in_document_root( dirname( $above ) ) ) {
			$candidates[] = $above;
		}
		$candidates[] = wp_normalize_path( WP_CONTENT_DIR ) . '/samcp-backups-' . bin2hex( random_bytes( 8 ) );
		foreach ( $candidates as $dir ) {
			if ( ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) && wp_is_writable( $dir ) ) {
				self::protect( $dir );
				// Policy refuses agents every write to this plugin's options; this one is the plugin's own bookkeeping.
				Policy::own_write(
					static function () use ( $dir ) {
						update_option( self::DIR_OPTION, $dir, false );
					}
				);
				return $dir;
			}
		}
		throw new Tool_Error( 'No folder is writable for backups on this server.' );
	}

	/**
	 * Whether a folder has one of the shapes this plugin creates for backups: the fixed
	 * folder next to WordPress, or a randomly named folder directly in wp-content.
	 *
	 * @param string $dir   Normalised folder.
	 * @param string $above Normalised folder next to WordPress.
	 * @return bool
	 */
	private static function is_storage_shape( $dir, $above ) {
		if ( $dir === $above ) {
			return true;
		}
		return wp_normalize_path( WP_CONTENT_DIR ) === dirname( $dir ) && 1 === preg_match( '/^samcp-backups-[a-f0-9]{16}$/', basename( $dir ) );
	}

	/**
	 * Whether open_basedir lets PHP use a folder.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function reachable( $dir ) {
		$limit = (string) ini_get( 'open_basedir' );
		if ( '' === $limit ) {
			return true;
		}
		$dir = trailingslashit( wp_normalize_path( $dir ) );
		foreach ( explode( PATH_SEPARATOR, $limit ) as $allowed ) {
			if ( '' !== $allowed && 0 === strpos( $dir, trailingslashit( wp_normalize_path( $allowed ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deny web access for Apache, LiteSpeed and IIS. Nginx ignores these files,
	 * hence the random name and the exposure check in the status.
	 *
	 * @param string $dir Folder.
	 */
	private static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence.\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			// Written again when missing or changed, so a deleted or edited rule comes back.
			if ( ! file_exists( $dir . '/' . $name ) || (string) file_get_contents( $dir . '/' . $name ) !== $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Whether a folder sits inside the web server's document root.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function in_document_root( $dir ) {
		$docroot = self::document_root();
		if ( null === $docroot ) {
			return false;
		}
		$real = realpath( $dir );
		$dir  = trailingslashit( wp_normalize_path( false === $real ? $dir : $real ) );
		return 0 === strpos( $dir, $docroot );
	}

	/**
	 * The document root, normalised with a trailing slash, or null when unknown.
	 *
	 * @return string|null
	 */
	private static function document_root() {
		$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $docroot ) {
			return null;
		}
		$real = realpath( $docroot );
		return trailingslashit( wp_normalize_path( false === $real ? $docroot : $real ) );
	}

	/**
	 * Whether the storage folder sits inside the web root.
	 *
	 * @param string $dir Folder.
	 * @return string|null Path relative to the site root, or null when outside.
	 */
	public static function web_path( $dir ) {
		$dir     = wp_normalize_path( $dir );
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		// wp-content is served at its own URL even when it lives outside WordPress.
		if ( 0 === strpos( $dir, $content ) ) {
			$url_path = trim( (string) wp_parse_url( content_url(), PHP_URL_PATH ), '/' );
			$site     = trim( (string) wp_parse_url( site_url(), PHP_URL_PATH ), '/' );
			if ( '' !== $site && 0 === strpos( $url_path, $site . '/' ) ) {
				$url_path = substr( $url_path, strlen( $site ) + 1 );
			}
			return ltrim( $url_path . '/' . substr( $dir, strlen( $content ) ), '/' );
		}
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $dir, $root ) ) {
			return substr( $dir, strlen( $root ) );
		}
		// Above WordPress but still under the document root: served too.
		$docroot = self::document_root();
		$real    = realpath( $dir );
		$real    = trailingslashit( wp_normalize_path( false === $real ? $dir : $real ) );
		return null !== $docroot && 0 === strpos( $real, $docroot ) ? untrailingslashit( substr( $real, strlen( $docroot ) ) ) : null;
	}

	/**
	 * Folder of one backup.
	 *
	 * @param string $id Backup id.
	 * @return string
	 * @throws Tool_Error When the id is invalid or unknown.
	 */
	public static function folder( $id ) {
		// Ids end with 16 random hex digits since 0.8.0, 6 before.
		if ( ! preg_match( '/^\d{8}-\d{6}-(?:[a-f0-9]{6}|[a-f0-9]{16})$/', (string) $id ) ) {
			throw new Tool_Error( 'Unknown backup id. Use backup_status to list them.' );
		}
		$dir = self::storage() . '/' . $id;
		if ( ! is_dir( $dir ) ) {
			throw new Tool_Error( 'Unknown backup id. Use backup_status to list them.' );
		}
		return $dir;
	}

	/**
	 * Read a JSON file.
	 *
	 * @param string $path Path.
	 * @return array|null
	 */
	private static function read_json( $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write a JSON file atomically.
	 *
	 * @param string $path Path.
	 * @param array  $data Data.
	 */
	private static function write_json( $path, array $data ) {
		file_put_contents( $path . '.tmp', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rename( $path . '.tmp', $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Backups on this server, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		try {
			$root = self::storage();
		} catch ( Tool_Error $e ) {
			return array();
		}
		$list = array();
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$id  = basename( $dir );
			$job = self::read_json( $dir . '/job.json' );
			if ( ! $job ) {
				continue;
			}
			$manifest = self::read_json( $dir . '/manifest.json' );
			$list[]   = array(
				'id'       => $id,
				'created'  => $job['created'],
				'state'    => $job['state'],
				'size_mb'  => round( ( self::size( $dir . '/database.sql' ) + self::size( $dir . '/files.tar' ) ) / 1048576, 1 ),
				'files'    => $manifest ? $manifest['files']['count'] : $job['file_index'],
				'tables'   => $manifest ? count( $manifest['database']['tables'] ) : null,
				'warnings' => count( $job['warnings'] ),
			);
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( $b['id'], $a['id'] );
			}
		);
		return $list;
	}

	/**
	 * File size, 0 when missing.
	 *
	 * @param string $path Path.
	 * @return int
	 */
	private static function size( $path ) {
		clearstatcache( true, $path );
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * The unfinished backup, if any.
	 *
	 * @return string|null Folder.
	 */
	private static function running() {
		foreach ( self::all() as $backup ) {
			if ( 'complete' !== $backup['state'] && 'failed' !== $backup['state'] ) {
				return self::storage() . '/' . $backup['id'];
			}
		}
		return null;
	}

	/**
	 * Delete a backup.
	 *
	 * @param string $id Backup id.
	 */
	public static function delete( $id ) {
		$dir = self::folder( $id );
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------- job */

	/**
	 * Start a backup, or return the running one.
	 *
	 * @return string Folder.
	 * @throws Tool_Error When it cannot start.
	 */
	public static function start() {
		$running = self::running();
		if ( $running ) {
			return $running;
		}
		$root = self::storage();
		$id   = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) );
		$dir  = $root . '/' . $id;
		wp_mkdir_p( $dir );
		self::write_json(
			$dir . '/job.json',
			array(
				'format'      => self::FORMAT,
				'id'          => $id,
				'created'     => gmdate( 'c' ),
				'state'       => 'listing',
				'tables'      => array(),
				'table_index' => 0,
				'row_offset'  => 0,
				'file_index'  => 0,
				'file_offset' => 0,
				'list_pos'    => 0,
				'entry_name'  => null,
				'entry_size'  => 0,
				'entry_md5'   => '',
				'tar_size'    => 0,
				'index_size'  => 0,
				'sql_size'    => 0,
				'file_count'  => 0,
				'file_bytes'  => 0,
				'db_rows'     => array(),
				'warnings'    => array(),
				'error'       => null,
				'updated'     => time(),
			)
		);
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
		return $dir;
	}

	/** WP-Cron keeps a backup moving when nobody watches it. */
	public static function cron_tick() {
		$dir = self::running();
		if ( ! $dir ) {
			return;
		}
		self::step( $dir );
		if ( self::running() ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/** The admin screen advances a backup while it is open. */
	public static function ajax_step() {
		check_ajax_referer( 'samcp_backup_step' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		$dir = self::running();
		if ( ! $dir ) {
			wp_send_json_success( array( 'state' => 'complete' ) );
		}
		$state = self::step( $dir );
		// The progress above is English, for agents. The screen gets its count in the person's language.
		$job = self::read_json( $dir . '/job.json' );
		if ( $job ) {
			$state += self::progress_detail( $job );
		}
		wp_send_json_success( $state );
	}

	/**
	 * Count of the current phase, for the admin screen.
	 *
	 * @param array $job Job.
	 * @return array{detail?:string,ratio?:float}
	 */
	private static function progress_detail( array $job ) {
		if ( 'database' === $job['state'] && ! empty( $job['tables'] ) ) {
			$total = count( $job['tables'] );
			$done  = min( (int) $job['table_index'], $total );
			/* translators: 1: tables saved so far, 2: number of tables. */
			$text = _n( '%1$s of %2$s table', '%1$s of %2$s tables', $total, 'site-agent-mcp-lite' );
		} elseif ( 'files' === $job['state'] && ! empty( $job['file_count'] ) ) {
			$total = (int) $job['file_count'];
			$done  = min( (int) $job['file_index'], $total );
			/* translators: 1: files archived so far, 2: number of files. */
			$text = _n( '%1$s of %2$s file', '%1$s of %2$s files', $total, 'site-agent-mcp-lite' );
		} else {
			return array();
		}
		return array(
			'detail' => sprintf( $text, number_format_i18n( $done ), number_format_i18n( $total ) ),
			'ratio'  => $done / $total,
		);
	}

	/**
	 * Run one slice of work.
	 *
	 * @param string $dir Backup folder.
	 * @return array Progress.
	 */
	public static function step( $dir ) {
		$lock = fopen( $dir . '/.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			$job = self::read_json( $dir . '/job.json' );
			return self::progress( $job, true );
		}
		$job      = self::read_json( $dir . '/job.json' );
		$deadline = microtime( true ) + self::budget();
		ignore_user_abort( true );
		try {
			while ( microtime( true ) < $deadline && ! in_array( $job['state'], array( 'complete', 'failed' ), true ) ) {
				switch ( $job['state'] ) {
					case 'listing':
						$job = self::list_files( $dir, $job );
						break;
					case 'database':
						$job = self::dump_database( $dir, $job, $deadline );
						break;
					case 'files':
						$job = self::archive_files( $dir, $job, $deadline );
						break;
					case 'manifest':
						$job = self::write_manifest( $dir, $job );
						break;
				}
				$job['updated'] = time();
				self::write_json( $dir . '/job.json', $job );
			}
		} catch ( \Throwable $e ) {
			$job['state'] = 'failed';
			$job['error'] = $e->getMessage();
			self::write_json( $dir . '/job.json', $job );
		}
		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return self::progress( $job, false );
	}

	/**
	 * Seconds of work per slice, well under the host's limit.
	 *
	 * @return float
	 */
	private static function budget() {
		$limit = (int) ini_get( 'max_execution_time' );
		return (float) ( $limit > 0 ? max( 3, min( 20, $limit / 3 ) ) : 20 );
	}

	/**
	 * Progress for people and agents.
	 *
	 * @param array $job  Job.
	 * @param bool  $busy Another request holds the lock.
	 * @return array
	 */
	private static function progress( array $job, $busy ) {
		$done = 'complete' === $job['state'];
		return array_filter(
			array(
				'id'        => $job['id'],
				'state'     => $job['state'],
				'busy'      => $busy ? 'Another request is working on this backup right now.' : null,
				'database'  => $job['tables'] ? min( $job['table_index'], count( $job['tables'] ) ) . ' of ' . count( $job['tables'] ) . ' tables' : null,
				'files'     => $job['file_count'] ? $job['file_index'] . ' of ' . $job['file_count'] . ' files' : null,
				'warnings'  => $job['warnings'] ? array_slice( $job['warnings'], 0, 20 ) : null,
				'error'     => $job['error'],
				'next'      => $done
					? 'Run backup_verify with this id. Restoring is done by a person in Tools > Site Agent MCP.'
					: ( 'failed' === $job['state'] ? 'Report the error to the user.' : 'Call backup_status again to continue.' ),
			),
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Folders archived, keyed by the prefix their entries get in the archive. When
	 * wp-content lives outside WordPress (Cloudron, Bedrock), its entries still start
	 * with wp-content/, so a restore elsewhere can put them back in the usual place.
	 *
	 * @return array<string,string>
	 */
	public static function roots() {
		$root    = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$roots   = array( '' => $root );
		if ( 0 !== strpos( $content . '/', $root . '/' ) ) {
			$roots['wp-content/'] = $content;
		}
		return $roots;
	}

	/**
	 * Path on this server of an archive entry.
	 *
	 * @param string $relative Entry name.
	 * @return string
	 */
	public static function absolute( $relative ) {
		$roots = self::roots();
		if ( '../wp-config.php' === $relative ) {
			return dirname( $roots[''] ) . '/wp-config.php';
		}
		if ( isset( $roots['wp-content/'] ) && 0 === strpos( $relative, 'wp-content/' ) ) {
			return $roots['wp-content/'] . '/' . substr( $relative, 11 );
		}
		return $roots[''] . '/' . $relative;
	}

	/* ---------------------------------------------------------------- phases */

	/**
	 * List files to archive into files.list and check free space.
	 *
	 * @param string $dir Folder.
	 * @param array  $job Job.
	 * @return array
	 * @throws Tool_Error When space is short.
	 */
	private static function list_files( $dir, array $job ) {
		global $wpdb;
		$roots    = self::roots();
		$excluded = array_map( array( __CLASS__, 'absolute' ), self::EXCLUDED );
		$excluded[] = wp_normalize_path( self::storage() );
		if ( isset( $roots['wp-content/'] ) ) {
			// The folder WordPress would use by default is unused, or a link to the real one.
			$excluded[] = $roots[''] . '/wp-content';
		}
		$list  = fopen( $dir . '/files.list', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$count = 0;
		$bytes = 0;

		foreach ( $roots as $prefix => $base ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS ),
					static function ( $file ) use ( $excluded ) {
						$path = wp_normalize_path( $file->getPathname() );
						// Backups, restorers, and a site import's staging, previous-site and first-boot files.
						if ( 0 === strpos( $file->getFilename(), 'samcp-' ) ) {
							return false;
						}
						foreach ( $excluded as $skip ) {
							if ( $path === $skip || 0 === strpos( $path, $skip . '/' ) ) {
								return false;
							}
						}
						return ! $file->isLink() || $file->isFile();
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$relative = $prefix . substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) + 1 );
				if ( ! $file->isReadable() ) {
					$job['warnings'][] = 'Not readable, skipped: ' . $relative;
					continue;
				}
				fwrite( $list, $relative . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				++$count;
				$bytes += (int) $file->getSize();
			}
		}
		// wp-config.php may sit one folder above WordPress.
		$root = $roots[''];
		if ( ! is_file( $root . '/wp-config.php' ) ) {
			if ( is_file( dirname( $root ) . '/wp-config.php' ) && is_readable( dirname( $root ) . '/wp-config.php' ) ) {
				fwrite( $list, "../wp-config.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				++$count;
			} else {
				$job['warnings'][] = 'wp-config.php is not next to WordPress or one folder above, so it is not in this backup. Hosts such as Cloudron manage it themselves.';
			}
		}
		fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$db_bytes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$needed   = (int) ( ( $bytes + $db_bytes * 1.5 ) * 1.05 );
		if ( false !== $free && $free < $needed ) {
			throw new Tool_Error( sprintf( 'Not enough disk space: the backup needs about %d MB and %d MB are free.', (int) ( $needed / 1048576 ), (int) ( $free / 1048576 ) ) );
		}

		$job['tables']     = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		$job['file_count'] = $count;
		$job['file_bytes'] = $bytes;
		$job['state']      = 'database';
		file_put_contents( $dir . '/database.sql', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/files.tar', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/files.index', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $job;
	}

	/**
	 * Dump tables, one statement per line, in batches of rows.
	 *
	 * @param string $dir      Folder.
	 * @param array  $job      Job.
	 * @param float  $deadline Stop time.
	 * @return array
	 */
	private static function dump_database( $dir, array $job, $deadline ) {
		global $wpdb;
		$out = fopen( $dir . '/database.sql', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		ftruncate( $out, $job['sql_size'] );
		fseek( $out, $job['sql_size'] );
		if ( 0 === $job['table_index'] && 0 === $job['row_offset'] && 0 === $job['sql_size'] ) {
			fwrite( $out, '/*!40101 SET NAMES ' . $wpdb->charset . " */;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		while ( $job['table_index'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
			$table  = $job['tables'][ $job['table_index'] ];
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			if ( 0 === $job['row_offset'] ) {
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
				fwrite( $out, 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . preg_replace( '/\s*\n\s*/', ' ', $create[1] ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$job['db_rows'][ $table ] = 0;
			}
			$keys  = $wpdb->get_col( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), 4 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			$order = $keys ? ' ORDER BY ' . implode( ', ', array_map( static function ( $k ) { return '`' . str_replace( '`', '``', $k ) . '`'; }, $keys ) ) : ''; // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie
			// $order holds the primary key columns, each quoted as an identifier just above; their number varies, so %i cannot list them.
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $order . ' LIMIT %d, %d', $table, $job['row_offset'], self::ROWS ), ARRAY_N ); // phpcs:ignore WordPress.DB -- backup engine, no WP API exists; $order is quoted above
			if ( $wpdb->last_error ) {
				throw new Tool_Error( 'Reading ' . esc_html( $table ) . ' failed: ' . esc_html( $wpdb->last_error ) );
			}
			$values = array();
			$size   = 0;
			foreach ( $rows as $row ) {
				$cells = array();
				foreach ( $row as $cell ) {
					$cells[] = null === $cell ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $cell ) ) . "'";
				}
				$line     = '(' . implode( ',', $cells ) . ')';
				$values[] = $line;
				$size    += strlen( $line );
				if ( $size > self::ROW_BYTES ) {
					fwrite( $out, 'INSERT INTO ' . $quoted . ' VALUES ' . implode( ',', $values ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$values = array();
					$size   = 0;
				}
			}
			if ( $values ) {
				fwrite( $out, 'INSERT INTO ' . $quoted . ' VALUES ' . implode( ',', $values ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$job['db_rows'][ $table ] += count( $rows );
			if ( count( $rows ) < self::ROWS ) {
				++$job['table_index'];
				$job['row_offset'] = 0;
			} else {
				$job['row_offset'] += self::ROWS;
			}
		}
		$job['sql_size'] = ftell( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $job['table_index'] >= count( $job['tables'] ) ) {
			$job['state'] = 'files';
		}
		return $job;
	}

	/**
	 * Append files to files.tar, resuming inside large files.
	 *
	 * @param string $dir      Folder.
	 * @param array  $job      Job.
	 * @param float  $deadline Stop time.
	 * @return array
	 */
	private static function archive_files( $dir, array $job, $deadline ) {
		$root  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$list  = fopen( $dir . '/files.list', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$tar   = fopen( $dir . '/files.tar', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$index = fopen( $dir . '/files.index', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// A slice that died mid-write leaves bytes the job never recorded: drop them.
		ftruncate( $tar, $job['tar_size'] );
		ftruncate( $index, $job['index_size'] );
		fseek( $tar, $job['tar_size'] );
		fseek( $index, $job['index_size'] );
		fseek( $list, $job['list_pos'] );
		$finished = false;

		while ( microtime( true ) < $deadline ) {
			if ( null === $job['entry_name'] ) {
				$line = fgets( $list );
				if ( false === $line ) {
					$finished = true;
					break;
				}
				$job['list_pos'] = ftell( $list );
				$relative        = rtrim( $line, "\n" );
				if ( '' === $relative ) {
					continue;
				}
				$path = self::absolute( $relative );
				clearstatcache( true, $path );
				if ( ! is_file( $path ) || ! is_readable( $path ) ) {
					$job['warnings'][] = 'Gone or unreadable during the backup, skipped: ' . $relative;
					++$job['file_index'];
					continue;
				}
				$size               = (int) filesize( $path );
				$job['entry_name']  = $relative;
				$job['entry_size']  = $size;
				$job['entry_md5']   = $size <= self::HASH_LIMIT ? (string) md5_file( $path ) : '';
				$job['file_offset'] = 0;
				fwrite( $tar, self::tar_header( $relative, $size, (int) filemtime( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$relative = $job['entry_name'];
			// Write the size announced in the header, even if the file changed since.
			$source = fopen( self::absolute( $relative ), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $source ) {
				fseek( $source, $job['file_offset'] );
			}
			while ( $job['file_offset'] < $job['entry_size'] ) {
				$want  = (int) min( self::CHUNK, $job['entry_size'] - $job['file_offset'] );
				$chunk = $source ? (string) fread( $source, $want ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( strlen( $chunk ) < $want ) {
					$chunk            .= str_repeat( "\0", $want - strlen( $chunk ) );
					$job['entry_md5']  = '';
					$job['warnings'][] = 'Changed during the backup, saved incomplete: ' . $relative;
				}
				fwrite( $tar, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$job['file_offset'] += $want;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
			if ( $source ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( $job['file_offset'] < $job['entry_size'] ) {
				break;
			}
			$pad = ( 512 - $job['entry_size'] % 512 ) % 512;
			if ( $pad ) {
				fwrite( $tar, str_repeat( "\0", $pad ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			fwrite( $index, $relative . "\t" . $job['entry_size'] . "\t" . $job['entry_md5'] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$job['entry_name']  = null;
			$job['file_offset'] = 0;
			++$job['file_index'];
		}
		if ( $finished ) {
			fwrite( $tar, str_repeat( "\0", 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$job['state'] = 'manifest';
		}
		$job['tar_size']   = ftell( $tar );
		$job['index_size'] = ftell( $index );
		fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $tar ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $job;
	}

	/**
	 * Tar header, with a GNU long-name entry before it when needed.
	 *
	 * @param string $name  Relative path.
	 * @param int    $size  Bytes.
	 * @param int    $mtime Modification time.
	 * @param string $type  Type flag.
	 * @return string
	 */
	public static function tar_header( $name, $size, $mtime, $type = '0' ) {
		$prefix = '';
		if ( strlen( $name ) > 99 ) {
			$long   = $name . "\0";
			$prefix = self::tar_header( '././@LongLink', strlen( $long ), 0, 'L' ) . $long . str_repeat( "\0", ( 512 - strlen( $long ) % 512 ) % 512 );
			$name   = substr( $name, 0, 99 );
		}
		$header = pack(
			'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
			$name,
			sprintf( '%07o', 0644 ),
			sprintf( '%07o', 0 ),
			sprintf( '%07o', 0 ),
			sprintf( '%011o', $size ),
			sprintf( '%011o', max( 0, $mtime ) ),
			'        ',
			$type,
			'',
			'ustar',
			'00',
			'',
			'',
			'',
			'',
			'',
			''
		);
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $header[ $i ] );
		}
		return $prefix . substr_replace( $header, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/**
	 * Describe the environment, so a restore elsewhere knows what it came from.
	 *
	 * @param string $dir Folder.
	 * @param array  $job Job.
	 * @return array
	 */
	private static function write_manifest( $dir, array $job ) {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		$constants = array();
		foreach ( array( 'WP_CACHE', 'WP_DEBUG', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN', 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'UPLOADS', 'WP_MEMORY_LIMIT', 'WP_ENVIRONMENT_TYPE', 'MULTISITE', 'WPCACHEHOME', 'DB_CHARSET', 'DB_COLLATE' ) as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = constant( $name );
			}
		}
		$posts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT post_type, post_status, COUNT(*) AS n FROM %i GROUP BY post_type, post_status', $wpdb->posts ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			$posts[ $row['post_type'] . ':' . $row['post_status'] ] = (int) $row['n'];
		}
		$uploads  = wp_upload_dir( null, false );
		$theme    = wp_get_theme();
		$manifest = array(
			'format'   => self::FORMAT,
			'id'       => $job['id'],
			'created'  => $job['created'],
			'finished' => gmdate( 'c' ),
			'plugin'   => SAMCP_VERSION,
			'site'     => array(
				'home'         => home_url(),
				'siteurl'      => site_url(),
				'abspath'      => wp_normalize_path( ABSPATH ),
				'content_dir'  => wp_normalize_path( WP_CONTENT_DIR ),
				'content_outside' => isset( self::roots()['wp-content/'] ),
				'uploads_dir'  => wp_normalize_path( $uploads['basedir'] ),
				'table_prefix' => $wpdb->base_prefix,
				'multisite'    => is_multisite(),
			),
			'runtime'  => array(
				'wordpress'  => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'extensions' => array_values( array_map( 'strtolower', get_loaded_extensions() ) ),
				'database'   => $wpdb->db_server_info(),
				'charset'    => $wpdb->charset,
				'collate'    => $wpdb->collate,
				'server'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'os'         => PHP_OS_FAMILY,
				'constants'  => $constants,
			),
			'theme'    => array(
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
				'version'    => $theme->get( 'Version' ),
			),
			'plugins'  => $plugins,
			// Administrator logins: an importing site lists the accounts that will sign in there.
			'admins'   => is_multisite() ? array() : array_values( array_map( 'strval', (array) get_users( array( 'role' => 'administrator', 'fields' => 'user_login', 'number' => 50 ) ) ) ),
			'mu_plugins' => array_keys( get_mu_plugins() ),
			'dropins'  => array_keys( get_dropins() ),
			'counts'   => array(
				'posts'    => $posts,
				'users'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
				'comments' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->comments ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			),
			'database' => array(
				'file'       => 'database.sql',
				'bytes'      => self::size( $dir . '/database.sql' ),
				'statements' => self::count_lines( $dir . '/database.sql' ),
				'tables'     => $job['db_rows'],
			),
			'files'    => array(
				'file'  => 'files.tar',
				'bytes' => self::size( $dir . '/files.tar' ),
				'count' => self::count_lines( $dir . '/files.index' ),
				'index' => 'files.index',
			),
			'warnings' => $job['warnings'],
		);
		self::write_json( $dir . '/manifest.json', $manifest );
		@unlink( $dir . '/files.list' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$job['state'] = 'complete';
		return $job;
	}

	/**
	 * Count lines without loading the file.
	 *
	 * @param string $path Path.
	 * @return int
	 */
	private static function count_lines( $path ) {
		$count  = 0;
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( $handle && ! feof( $handle ) ) {
			$count += substr_count( (string) fread( $handle, self::CHUNK ), "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $count;
	}

	/* ---------------------------------------------------------------- verify */

	/**
	 * Read a backup back and compare it with its manifest and index.
	 *
	 * @param string $id Backup id.
	 * @return array
	 */
	public static function verify( $id ) {
		$dir      = self::folder( $id );
		$manifest = self::read_json( $dir . '/manifest.json' );
		if ( ! $manifest ) {
			return array( 'ok' => false, 'problems' => array( 'No manifest: the backup is unfinished or damaged.' ) );
		}
		$problems = array();
		$index    = array();
		foreach ( file( $dir . '/files.index', FILE_IGNORE_NEW_LINES ) as $line ) {
			$parts                = explode( "\t", $line );
			$index[ $parts[0] ] = array( (int) $parts[1], $parts[2] );
		}
		$seen   = 0;
		$tar    = fopen( $dir . '/files.tar', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$long   = null;
		while ( $tar && ! feof( $tar ) ) {
			$header = fread( $tar, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( strlen( $header ) < 512 || "\0" === $header[0] ) {
				break;
			}
			$entry = self::parse_header( $header );
			if ( ! $entry ) {
				$problems[] = 'Damaged archive header after ' . $seen . ' files.';
				break;
			}
			if ( 'L' === $entry['type'] ) {
				$long = rtrim( (string) fread( $tar, $entry['size'] ), "\0" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fseek( $tar, ( 512 - $entry['size'] % 512 ) % 512, SEEK_CUR );
				continue;
			}
			$name = null !== $long ? $long : $entry['name'];
			$long = null;
			++$seen;
			$expected = isset( $index[ $name ] ) ? $index[ $name ] : null;
			if ( ! $expected ) {
				$problems[] = 'In the archive but not in the index: ' . $name;
			} elseif ( $expected[0] !== $entry['size'] ) {
				$problems[] = 'Size differs for ' . $name;
			}
			if ( $expected && '' !== $expected[1] ) {
				$context = hash_init( 'md5' );
				$left    = $entry['size'];
				while ( $left > 0 ) {
					$chunk = (string) fread( $tar, (int) min( self::CHUNK, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( '' === $chunk ) {
						break;
					}
					hash_update( $context, $chunk );
					$left -= strlen( $chunk );
				}
				if ( hash_final( $context ) !== $expected[1] ) {
					$problems[] = 'Checksum differs for ' . $name;
				}
				fseek( $tar, ( 512 - $entry['size'] % 512 ) % 512, SEEK_CUR );
			} else {
				fseek( $tar, $entry['size'] + ( 512 - $entry['size'] % 512 ) % 512, SEEK_CUR );
			}
		}
		if ( $tar ) {
			fclose( $tar ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( $seen !== count( $index ) ) {
			$problems[] = sprintf( 'The archive holds %d files and the index %d.', $seen, count( $index ) );
		}

		$statements = 0;
		$creates    = 0;
		$sql        = fopen( $dir . '/database.sql', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( $sql && false !== ( $line = fgets( $sql ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition, WordPress.CodeAnalysis.AssignmentInCondition
			++$statements;
			if ( ";\n" !== substr( $line, -2 ) ) {
				$problems[] = 'Database statement ' . $statements . ' is cut short.';
				break;
			}
			if ( 0 === strpos( $line, 'CREATE TABLE' ) ) {
				++$creates;
			}
		}
		if ( $sql ) {
			fclose( $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( $statements !== $manifest['database']['statements'] ) {
			$problems[] = sprintf( 'The database file holds %d statements and the manifest %d.', $statements, $manifest['database']['statements'] );
		}
		if ( count( $manifest['database']['tables'] ) !== $creates ) {
			$problems[] = sprintf( '%d tables created, %d expected.', $creates, count( $manifest['database']['tables'] ) );
		}
		return array(
			'ok'       => ! $problems,
			'id'       => $id,
			'files'    => $seen,
			'tables'   => $creates,
			'problems' => array_slice( $problems, 0, 50 ),
			'limits'   => 'Files over 20 MB are checked by size only. This proves the backup reads back, not that it restores on another server.',
		);
	}

	/**
	 * Parse a tar header.
	 *
	 * @param string $header 512 bytes.
	 * @return array|null
	 */
	public static function parse_header( $header ) {
		$data = unpack( 'a100name/a8mode/a8uid/a8gid/a12size/a12mtime/a8checksum/a1type', $header );
		$sum  = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $header[ $i ] );
		}
		if ( octdec( trim( $data['checksum'], " \0" ) ) !== $sum ) {
			return null;
		}
		return array(
			'name' => rtrim( $data['name'], "\0" ),
			'size' => (int) octdec( trim( $data['size'], " \0" ) ),
			'type' => '' === trim( $data['type'], "\0" ) ? '0' : $data['type'],
		);
	}

	/* ---------------------------------------------------------------- tools */

	/**
	 * Tool: start a backup.
	 *
	 * @return array
	 */
	public static function tool_create() {
		self::tool_guard();
		$dir = self::start();
		return self::step( $dir ) + self::location();
	}

	/**
	 * Tool: progress and list.
	 *
	 * @return array
	 */
	public static function tool_status() {
		self::tool_guard();
		$dir    = self::running();
		$result = $dir ? array( 'running' => self::step( $dir ) ) : array( 'running' => null );
		return $result + array( 'backups' => self::all() ) + self::location();
	}

	/**
	 * Tool: verify.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function tool_verify( array $args ) {
		self::tool_guard();
		$report = self::verify( isset( $args['id'] ) ? (string) $args['id'] : '' );
		if ( ! $report['ok'] ) {
			$report['_is_error'] = true;
		}
		return $report;
	}

	/**
	 * Where backups are kept, without exposing the full path.
	 *
	 * @return array
	 */
	private static function location() {
		$web = self::web_path( self::storage() );
		// Agents learn whether the folder is served, never where it is: the path stays on the admin screen.
		return array(
			'storage'               => null === $web
				? 'Outside the web root, so visitors cannot reach it.'
				: 'Inside the web root, with deny rules for Apache, LiteSpeed and IIS. On Nginx, ask the user to check the folder shown in Site Agent > Backups, or to download and remove backups.',
			'storage_in_web_root'   => null !== $web,
		);
	}
}
