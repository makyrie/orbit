<?php
/**
 * Activity CRUD operations.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Activity
 */
class Orbit_Activity {

	/**
	 * Valid tier values.
	 *
	 * @var array
	 */
	const VALID_TIERS = array( 1, 2, 3 );

	/**
	 * Valid status values.
	 *
	 * @var array
	 */
	const VALID_STATUSES = array( 'active', 'cancelled', 'past' );

	/**
	 * Valid show_attendees values.
	 *
	 * @var array
	 */
	const VALID_SHOW_ATTENDEES = array( 'none', 'count', 'names' );

	/**
	 * Create a new activity.
	 *
	 * @param array $args {
	 *     @type int    $profile_id       Required. Poster's profile ID.
	 *     @type int    $tier             Required. 1, 2, or 3.
	 *     @type string $title            Required. Max 300 chars.
	 *     @type string $description      Optional.
	 *     @type string $audience         Optional. Free-text "who's this for" hint.
	 *     @type string $location_name    Optional. Max 300 chars.
	 *     @type string $location_address Optional.
	 *     @type string $url              Optional. External URL.
	 *     @type string $date_time        Optional. UTC datetime string.
	 *     @type bool   $date_flexible    Optional. Default false.
	 *     @type string $show_attendees   Optional. Default 'count'.
	 * }
	 * @return int|WP_Error Activity ID on success, WP_Error on failure.
	 */
	public static function create( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'description'      => null,
				'audience'         => null,
				'location_name'    => null,
				'location_address' => null,
				'url'              => null,
				'date_time'        => null,
				'date_flexible'    => false,
				'show_attendees'   => 'count',
			)
		);

		if ( empty( $args['profile_id'] ) || empty( $args['tier'] ) || empty( $args['title'] ) ) {
			return new WP_Error( 'missing_required', __( 'profile_id, tier, and title are required.', 'orbit' ) );
		}

		$tier = absint( $args['tier'] );
		if ( ! in_array( $tier, self::VALID_TIERS, true ) ) {
			return new WP_Error( 'invalid_tier', __( 'Tier must be 1, 2, or 3.', 'orbit' ) );
		}

		$title = sanitize_text_field( $args['title'] );
		if ( mb_strlen( $title ) > 300 ) {
			return new WP_Error( 'title_too_long', __( 'Title must be 300 characters or fewer.', 'orbit' ) );
		}

		$show_attendees = $args['show_attendees'];
		if ( ! in_array( $show_attendees, self::VALID_SHOW_ATTENDEES, true ) ) {
			return new WP_Error( 'invalid_show_attendees', __( 'show_attendees must be none, count, or names.', 'orbit' ) );
		}

		$location_name = $args['location_name'] ? sanitize_text_field( $args['location_name'] ) : null;
		if ( $location_name && mb_strlen( $location_name ) > 300 ) {
			return new WP_Error( 'location_name_too_long', __( 'Location name must be 300 characters or fewer.', 'orbit' ) );
		}

		$table = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$now   = current_time( 'mysql', true );

		$result = $wpdb->insert(
			$table,
			array(
				'profile_id'       => absint( $args['profile_id'] ),
				'tier'             => $tier,
				'title'            => $title,
				'description'      => $args['description'] ? sanitize_textarea_field( $args['description'] ) : null,
				'audience'         => $args['audience'] ? sanitize_textarea_field( $args['audience'] ) : null,
				'location_name'    => $location_name,
				'location_address' => $args['location_address'] ? sanitize_textarea_field( $args['location_address'] ) : null,
				'url'              => $args['url'] ? esc_url_raw( $args['url'] ) : null,
				'date_time'        => $args['date_time'] ? sanitize_text_field( $args['date_time'] ) : null,
				'date_flexible'    => $args['date_flexible'] ? 1 : 0,
				'show_attendees'   => $show_attendees,
				'status'           => 'active',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create activity.', 'orbit' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get an activity by ID.
	 *
	 * @param int $id Activity ID.
	 * @return object|null Activity row or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * Update an activity.
	 *
	 * @param int   $id   Activity ID.
	 * @param array $args Fields to update.
	 * @return bool|WP_Error True on success.
	 */
	public static function update( $id, $args ) {
		global $wpdb;

		$table   = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$data    = array();
		$formats = array();

		if ( isset( $args['title'] ) ) {
			$title = sanitize_text_field( $args['title'] );
			if ( mb_strlen( $title ) > 300 ) {
				return new WP_Error( 'title_too_long', __( 'Title must be 300 characters or fewer.', 'orbit' ) );
			}
			$data['title'] = $title;
			$formats[]     = '%s';
		}

		if ( isset( $args['tier'] ) ) {
			$tier = absint( $args['tier'] );
			if ( ! in_array( $tier, self::VALID_TIERS, true ) ) {
				return new WP_Error( 'invalid_tier', __( 'Tier must be 1, 2, or 3.', 'orbit' ) );
			}
			$data['tier'] = $tier;
			$formats[]    = '%d';
		}

		if ( array_key_exists( 'description', $args ) ) {
			$data['description'] = $args['description'] ? sanitize_textarea_field( $args['description'] ) : null;
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'audience', $args ) ) {
			$data['audience'] = $args['audience'] ? sanitize_textarea_field( $args['audience'] ) : null;
			$formats[]        = '%s';
		}

		if ( array_key_exists( 'location_name', $args ) ) {
			$location_name = $args['location_name'] ? sanitize_text_field( $args['location_name'] ) : null;
			if ( $location_name && mb_strlen( $location_name ) > 300 ) {
				return new WP_Error( 'location_name_too_long', __( 'Location name must be 300 characters or fewer.', 'orbit' ) );
			}
			$data['location_name'] = $location_name;
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'location_address', $args ) ) {
			$data['location_address'] = $args['location_address'] ? sanitize_textarea_field( $args['location_address'] ) : null;
			$formats[]                = '%s';
		}

		if ( array_key_exists( 'url', $args ) ) {
			$data['url'] = $args['url'] ? esc_url_raw( $args['url'] ) : null;
			$formats[]   = '%s';
		}

		if ( array_key_exists( 'date_time', $args ) ) {
			$data['date_time'] = $args['date_time'] ? sanitize_text_field( $args['date_time'] ) : null;
			$formats[]         = '%s';
		}

		if ( isset( $args['date_flexible'] ) ) {
			$data['date_flexible'] = $args['date_flexible'] ? 1 : 0;
			$formats[]             = '%d';
		}

		if ( isset( $args['show_attendees'] ) ) {
			if ( ! in_array( $args['show_attendees'], self::VALID_SHOW_ATTENDEES, true ) ) {
				return new WP_Error( 'invalid_show_attendees', __( 'show_attendees must be none, count, or names.', 'orbit' ) );
			}
			$data['show_attendees'] = $args['show_attendees'];
			$formats[]              = '%s';
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'nothing_to_update', __( 'No valid fields to update.', 'orbit' ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$result = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to update activity.', 'orbit' ) );
		}

		return true;
	}

	/**
	 * Cancel an activity.
	 *
	 * @param int $id Activity ID.
	 * @return bool|WP_Error True on success.
	 */
	public static function cancel( $id ) {
		global $wpdb;

		$table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$result = $wpdb->update(
			$table,
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to cancel activity.', 'orbit' ) );
		}

		return true;
	}

	/**
	 * List activities with filters.
	 *
	 * @param array $args {
	 *     @type int    $profile_id Filter by profile.
	 *     @type string $status     Filter by status.
	 *     @type int    $tier       Filter by tier.
	 *     @type string $after      Activities after this UTC datetime.
	 *     @type string $before     Activities before this UTC datetime.
	 *     @type int    $per_page   Results per page. Default 20.
	 *     @type int    $page       Page number. Default 1.
	 *     @type string $orderby    Column to order by. Default 'created_at'.
	 *     @type string $order      ASC or DESC. Default 'DESC'.
	 * }
	 * @return array Array of activity rows.
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'profile_id' => null,
				'status'     => null,
				'tier'       => null,
				'after'      => null,
				'before'     => null,
				'per_page'   => 20,
				'page'       => 1,
				'orderby'    => 'created_at',
				'order'      => 'DESC',
			)
		);

		$table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$where  = array( '1=1' );
		$values = array();

		if ( $args['profile_id'] ) {
			$where[]  = 'profile_id = %d';
			$values[] = absint( $args['profile_id'] );
		}

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['tier'] && in_array( absint( $args['tier'] ), self::VALID_TIERS, true ) ) {
			$where[]  = 'tier = %d';
			$values[] = absint( $args['tier'] );
		}

		if ( $args['after'] ) {
			$where[]  = 'date_time >= %s';
			$values[] = sanitize_text_field( $args['after'] );
		}

		if ( $args['before'] ) {
			$where[]  = 'date_time <= %s';
			$values[] = sanitize_text_field( $args['before'] );
		}

		$allowed_orderby = array( 'created_at', 'date_time', 'tier', 'title', 'id' );
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
	 * List activities for multiple profile IDs in a single query.
	 *
	 * @param array $profile_ids Array of profile IDs.
	 * @param array $args        Optional. Same filters as list() except profile_id.
	 * @return array Array of activity rows.
	 */
	public static function list_by_profile_ids( $profile_ids, $args = array() ) {
		global $wpdb;

		if ( empty( $profile_ids ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status'   => null,
				'tier'     => null,
				'per_page' => 100,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$where  = array();
		$values = array();

		$placeholders = implode( ',', array_fill( 0, count( $profile_ids ), '%d' ) );
		$where[]      = "profile_id IN ({$placeholders})";
		$values       = array_map( 'absint', $profile_ids );

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['tier'] && in_array( absint( $args['tier'] ), self::VALID_TIERS, true ) ) {
			$where[]  = 'tier = %d';
			$values[] = absint( $args['tier'] );
		}

		$allowed_orderby = array( 'created_at', 'date_time', 'tier', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$offset          = max( 0, ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] ) );

		$where_clause = implode( ' AND ', $where );

		// When sorting by date_time, push undated activities (date_time IS NULL,
		// e.g. tier-1 "just an idea" entries) to the end rather than letting
		// MySQL's default null-first ASC ordering crowd the top of the list.
		$order_clause = 'date_time' === $orderby
			? "date_time IS NULL, date_time {$order}"
			: "{$orderby} {$order}";

		$sql      = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$order_clause} LIMIT %d OFFSET %d";
		$values[] = absint( $args['per_page'] );
		$values[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
	}

	/**
	 * Canonical tier metadata — single source of truth for both the
	 * dropdown label and its supporting description. Adding a new tier
	 * requires one edit here, which guarantees the label and description
	 * keys can never drift apart.
	 *
	 * @return array Associative array of tier number => array{label: string, description: string}.
	 */
	private static function get_tier_data() {
		return array(
			1 => array(
				// First-person 'label' is the poster's compose/marketing voice;
				// 'label_badge' is the neutral, poster-independent tag shown on
				// every display surface (cards, activity page, emails).
				'label'       => __( 'Just an idea', 'orbit' ),
				'label_badge' => __( 'Musing', 'orbit' ),
				'description' => __( 'An open thought. Subscribers see it on their dashboard but get no notification.', 'orbit' ),
			),
			2 => array(
				'label'       => __( "I'll go if you will", 'orbit' ),
				'label_badge' => __( 'Tempted', 'orbit' ),
				'description' => __( "You're interested, but want company before committing. Subscribers get a low-priority alert.", 'orbit' ),
			),
			3 => array(
				'label'       => __( "I'm going — join me", 'orbit' ),
				'label_badge' => __( 'Planned', 'orbit' ),
				'description' => __( "You're going for sure. Subscribers who opted in for this tier get a real-time alert.", 'orbit' ),
			),
		);
	}

	/**
	 * Get human-readable tier labels.
	 *
	 * @return array Associative array of tier number => label string.
	 */
	public static function get_tier_labels() {
		return wp_list_pluck( self::get_tier_data(), 'label' );
	}

	/**
	 * Get one-line descriptions for each tier — used to clarify the
	 * commitment level dropdown on the create activity form.
	 *
	 * @return array Associative array of tier number => description string.
	 */
	public static function get_tier_descriptions() {
		return wp_list_pluck( self::get_tier_data(), 'description' );
	}

	/**
	 * Get the neutral, poster-independent badge label for a tier
	 * (Musing / Tempted / Planned).
	 *
	 * This is the tag shown on every display surface — dashboard and profile
	 * cards, the activity page, and the notification emails. It deliberately
	 * says nothing in the first person and names no one, so it can't be
	 * misread as the viewer's own RSVP and its length never depends on a
	 * poster's name. The poster's first-person voice ("I'm going — join me")
	 * lives only on the compose forms (get_tier_labels()) and the marketing
	 * copy.
	 *
	 * @param int $tier Tier number (1-3).
	 * @return string The badge label, or '' for an unknown tier.
	 */
	public static function get_tier_label( $tier ) {
		$data = self::get_tier_data();
		$tier = (int) $tier;

		return isset( $data[ $tier ] ) ? $data[ $tier ]['label_badge'] : '';
	}

	/**
	 * Format a stored activity datetime for display.
	 *
	 * Activity datetimes are stored naively — the local clock time the poster
	 * typed, with no timezone at save — so they are parsed as UTC purely so
	 * PHP's DateTime can read them, then formatted without timezone shifting.
	 * This is the canonical formatter used by the notification emails; the
	 * front-end shortcodes keep their own twin for now.
	 *
	 * @param string $datetime Stored datetime string (Y-m-d H:i:s).
	 * @param string $format   PHP date format. Default: full readable.
	 * @return string Formatted date string, or '' when empty.
	 */
	public static function format_datetime( $datetime, $format = '' ) {
		if ( empty( $datetime ) ) {
			return '';
		}

		if ( ! $format ) {
			$format = 'l, F j \a\t g:i A';
		}

		try {
			$dt = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );

			return $dt->format( $format );
		} catch ( Exception $e ) {
			return $datetime;
		}
	}

	/**
	 * Get human-readable activity status labels. The 'active' status
	 * intentionally returns an empty string so the manage table can omit
	 * the badge for normal activities — only cancelled and past need a
	 * visible status indicator.
	 *
	 * @return array Associative array of status key => translated label.
	 */
	public static function get_status_labels() {
		return array(
			'active'    => '',
			'cancelled' => __( 'Cancelled', 'orbit' ),
			'past'      => __( 'Past', 'orbit' ),
		);
	}

	/**
	 * Batch update past activities.
	 *
	 * Sets status to 'past' for all active activities with date_time in the past.
	 *
	 * @return int Number of rows affected.
	 */
	public static function mark_past() {
		global $wpdb;

		$table = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
		$now   = current_time( 'mysql', true );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'past', updated_at = %s WHERE status = 'active' AND date_time IS NOT NULL AND date_time < %s",
				$now,
				$now
			)
		);
	}
}
