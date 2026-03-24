<?php
/**
 * Profile CRUD operations.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Profile
 */
class Orbit_Profile {

	/**
	 * Reserved slugs that cannot be used as profile slugs.
	 *
	 * @var array
	 */
	const RESERVED_SLUGS = array(
		'dashboard',
		'manage',
		'activity',
		'unsubscribe',
		'api',
		'wp-admin',
		'wp-login',
		'wp-content',
		'settings',
		'subscribers',
		'new-activity',
		'edit-activity',
		'edit-profile',
	);

	/**
	 * Create a new profile.
	 *
	 * @param array $args {
	 *     @type int    $user_id         Required. WordPress user ID.
	 *     @type string $slug            Required. URL-safe identifier.
	 *     @type string $display_name    Required. Public name.
	 *     @type string $bio             Optional. Short description.
	 *     @type bool   $require_approval Optional. Default true.
	 * }
	 * @return int|WP_Error Profile ID on success, WP_Error on failure.
	 */
	public static function create( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'bio'              => null,
				'require_approval' => true,
			)
		);

		if ( empty( $args['user_id'] ) || empty( $args['slug'] ) || empty( $args['display_name'] ) ) {
			return new WP_Error( 'missing_required', __( 'user_id, slug, and display_name are required.', 'orbit' ) );
		}

		// Validate and sanitize slug.
		$slug = sanitize_title( $args['slug'] );

		$slug_error = self::validate_slug( $slug );
		if ( is_wp_error( $slug_error ) ) {
			return $slug_error;
		}

		// Check user doesn't already have a profile.
		$existing = self::get_by_user_id( $args['user_id'] );
		if ( $existing ) {
			return new WP_Error( 'profile_exists', __( 'User already has a profile.', 'orbit' ) );
		}

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$now   = current_time( 'mysql', true );

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'          => absint( $args['user_id'] ),
				'slug'             => $slug,
				'display_name'     => sanitize_text_field( $args['display_name'] ),
				'bio'              => $args['bio'] ? sanitize_textarea_field( $args['bio'] ) : null,
				'share_token'      => Orbit_Token::generate_random(),
				'require_approval' => $args['require_approval'] ? 1 : 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create profile.', 'orbit' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a profile by ID.
	 *
	 * @param int $id Profile ID.
	 * @return object|null Profile row or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Get a profile by slug.
	 *
	 * @param string $slug The profile slug.
	 * @return object|null Profile row or null.
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug )
		);
	}

	/**
	 * Get a profile by user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|null Profile row or null.
	 */
	public static function get_by_user_id( $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id )
		);
	}

	/**
	 * Get a profile by share token.
	 *
	 * @param string $token The share token.
	 * @return object|null Profile row or null.
	 */
	public static function get_by_share_token( $token ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE share_token = %s", $token )
		);
	}

	/**
	 * Update a profile.
	 *
	 * @param int   $id   Profile ID.
	 * @param array $args Fields to update. Supports: display_name, bio, slug, require_approval.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update( $id, $args ) {
		global $wpdb;

		$table   = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$data    = array();
		$formats = array();

		if ( isset( $args['display_name'] ) ) {
			$data['display_name'] = sanitize_text_field( $args['display_name'] );
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'bio', $args ) ) {
			$data['bio'] = $args['bio'] ? sanitize_textarea_field( $args['bio'] ) : null;
			$formats[]   = '%s';
		}

		if ( isset( $args['slug'] ) ) {
			$slug       = sanitize_title( $args['slug'] );
			$slug_error = self::validate_slug( $slug, $id );
			if ( is_wp_error( $slug_error ) ) {
				return $slug_error;
			}
			$data['slug'] = $slug;
			$formats[]    = '%s';
		}

		if ( isset( $args['require_approval'] ) ) {
			$data['require_approval'] = $args['require_approval'] ? 1 : 0;
			$formats[]                = '%d';
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'nothing_to_update', __( 'No valid fields to update.', 'orbit' ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$result = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to update profile.', 'orbit' ) );
		}

		return true;
	}

	/**
	 * Delete (soft-delete) a profile by setting it inactive.
	 *
	 * For v1, this removes the profile record entirely.
	 *
	 * @param int $id Profile ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to delete profile.', 'orbit' ) );
		}

		return true;
	}

	/**
	 * List profiles with optional filters.
	 *
	 * @param array $args {
	 *     @type int    $user_id  Filter by user ID.
	 *     @type string $search   Search in display_name and slug.
	 *     @type int    $per_page Number of results. Default 20.
	 *     @type int    $page     Page number. Default 1.
	 *     @type string $orderby  Column to order by. Default 'created_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 * }
	 * @return array Array of profile rows.
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_id'  => null,
				'search'   => null,
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$table  = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$where  = array( '1=1' );
		$values = array();

		if ( $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$values[] = absint( $args['user_id'] );
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(display_name LIKE %s OR slug LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$allowed_orderby = array( 'created_at', 'display_name', 'slug', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$offset   = max( 0, ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] ) );
		$per_page = absint( $args['per_page'] );

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$values[] = $per_page;
		$values[] = $offset;

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * Regenerate a profile's share token.
	 *
	 * @param int $id Profile ID.
	 * @return string|WP_Error New share token on success.
	 */
	public static function regenerate_token( $id ) {
		global $wpdb;

		$table     = $wpdb->prefix . ORBIT_TABLE_PROFILES;
		$new_token = Orbit_Token::generate_random();

		$result = $wpdb->update(
			$table,
			array(
				'share_token' => $new_token,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to regenerate token.', 'orbit' ) );
		}

		return $new_token;
	}

	/**
	 * Validate a profile slug.
	 *
	 * @param string   $slug       The slug to validate.
	 * @param int|null $exclude_id Profile ID to exclude from uniqueness check (for updates).
	 * @return true|WP_Error True if valid.
	 */
	private static function validate_slug( $slug, $exclude_id = null ) {
		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', __( 'Slug cannot be empty.', 'orbit' ) );
		}

		if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			return new WP_Error( 'reserved_slug', __( 'This slug is reserved and cannot be used.', 'orbit' ) );
		}

		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		if ( $exclude_id ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND id != %d",
					$slug,
					$exclude_id
				)
			);
		} else {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s",
					$slug
				)
			);
		}

		if ( $existing ) {
			return new WP_Error( 'slug_exists', __( 'This slug is already in use.', 'orbit' ) );
		}

		return true;
	}
}
