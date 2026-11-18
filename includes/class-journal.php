<?php
/**
 * Change journal with undo for options and files.
 *
 * @package SiteAgentMCP
 */

namespace SiteAgentMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the previous value of what agents change. Plugins that store data in
 * their own tables are not covered, and results say so.
 */
class Journal {

	const MAX_STATE_BYTES = 4194304;

	/**
	 * Read options straight from the database, bypassing caches.
	 *
	 * @return array Name to raw value.
	 */
	public static function snapshot_options() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT LIKE 'samcp\\_%' AND option_name NOT IN ('cron')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw option rows on purpose: the journal must bypass the option cache
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['option_name'] ] = $row['option_value'];
		}
		return $map;
	}

	/**
	 * Compare options with a snapshot and journal what changed.
	 *
	 * @param array  $before Snapshot.
	 * @param string $tool   Tool.
	 * @param string $label  Description.
	 * @return array id and names.
	 */
	public static function record_option_diff( array $before, $tool, $label ) {
		$after = self::snapshot_options();
		$state = array();
		foreach ( $after as $name => $value ) {
			if ( ! array_key_exists( $name, $before ) ) {
				$state[ $name ] = array(
					'exists' => false,
					'now'    => $value,
				);
			} elseif ( $before[ $name ] !== $value ) {
				$state[ $name ] = array(
					'exists' => true,
					'raw'    => $before[ $name ],
					'now'    => $value,
				);
			}
		}
		foreach ( $before as $name => $value ) {
			if ( ! array_key_exists( $name, $after ) ) {
				$state[ $name ] = array(
					'exists' => true,
					'raw'    => $value,
					'now'    => null,
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
			'id'    => self::record( $tool, $label, array( 'options' => $state ) ),
			'names' => array_keys( $state ),
		);
	}

	/**
	 * Store a change.
	 *
	 * @param string $tool  Tool.
	 * @param string $label Description.
	 * @param array  $state Previous state.
	 * @return int|null Id, or null when the state is too large to keep.
	 */
	public static function record( $tool, $label, array $state ) {
		global $wpdb;
		$json = wp_json_encode( $state, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json || strlen( $json ) > self::MAX_STATE_BYTES ) {
			return null;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
			Store::table( 'changes' ),
			array(
				'created_at'   => time(),
				'user_id'      => get_current_user_id(),
				'tool'         => Store::cut( $tool, 64 ),
				'label'        => Store::cut( $label, 191 ),
				'before_state' => $json,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent changes.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function recent( $limit ) {
		global $wpdb;
		$table = Store::table( 'changes' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, user_id, tool, label, undone_at, before_state FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		foreach ( $rows as &$row ) {
			$state           = json_decode( $row['before_state'], true );
			$row['options']  = isset( $state['options'] ) ? array_keys( $state['options'] ) : array();
			$row['files']    = isset( $state['files'] ) ? array_keys( $state['files'] ) : array();
			$row['undoable'] = empty( $row['undone_at'] );
			unset( $row['before_state'] );
		}
		return $rows;
	}

	/**
	 * Restore a change.
	 *
	 * @param int  $id        Change id.
	 * @param bool $has_power Connection has server-level access.
	 * @return array Restored items.
	 * @throws Tool_Error When it cannot be undone.
	 */
	public static function undo( $id, $has_power ) {
		global $wpdb;
		$table = Store::table( 'changes' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		if ( ! $row ) {
			throw new Tool_Error( 'Unknown change id.' );
		}
		if ( $row['undone_at'] ) {
			throw new Tool_Error( 'This change was already undone.' );
		}
		$state = json_decode( $row['before_state'], true );
		// File changes come from the server-level tools, which only the Full edition has.
		if ( ! empty( $state['files'] ) && 'full' !== SAMCP_EDITION ) {
			throw new Tool_Error( 'This change also edited files, which this edition cannot undo.' );
		}

		$restored = array();
		if ( ! empty( $state['options'] ) ) {
			if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) {
				throw new Tool_Error( 'Your account cannot restore these options.' );
			}
			foreach ( $state['options'] as $name => $option ) {
				// Raw writes skip update_option, so the agent rules are checked here: an undo never reaches what the connection could not change.
				Policy::check_option( (string) $name );
				// A value changed again since this change is left alone: undoing it would erase someone else's work.
				if ( array_key_exists( 'now', $option ) ) {
					$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw option rows on purpose, see below
					if ( $current !== $option['now'] ) {
						$restored[] = $name . ' (skipped: changed again since)';
						continue;
					}
				}
				// Raw writes: saving through update_option would run sanitizers, and encrypt an encrypted value twice.
				if ( empty( $option['exists'] ) ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw option rows on purpose, see above
				} elseif ( ! $wpdb->update( $wpdb->options, array( 'option_value' => $option['raw'] ), array( 'option_name' => $name ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw option rows on purpose, see above
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- raw option rows on purpose, see above
						$wpdb->options,
						array(
							'option_name'  => $name,
							'option_value' => $option['raw'],
							'autoload'     => 'auto',
						)
					);
				}
				$restored[] = $name;
			}
		}
		if ( ! empty( $state['post_meta'] ) ) {
			foreach ( $state['post_meta'] as $post_id => $keys ) {
				if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
					throw new Tool_Error( 'Your account cannot edit this content.' );
				}
				foreach ( $keys as $key => $meta ) {
					if ( empty( $meta['exists'] ) ) {
						delete_post_meta( (int) $post_id, $key );
					} else {
						update_post_meta( (int) $post_id, $key, wp_slash( $meta['value'] ) );
					}
					$restored[] = 'post ' . $post_id . ': ' . $key;
				}
			}
		}
		if ( isset( $state['custom_css'] ) ) {
			if ( ! current_user_can( 'edit_css' ) ) {
				throw new Tool_Error( 'Your account cannot edit the site CSS.' );
			}
			Seo_Tools::write_custom_css( (string) $state['custom_css']['css'] );
			$restored[] = 'site CSS';
		}
		$wpdb->update( $table, array( 'undone_at' => time() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables / backup engine, no WP API exists
		wp_cache_flush();
		return $restored;
	}
}
