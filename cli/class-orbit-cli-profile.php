<?php
/**
 * WP-CLI profile commands.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage poster profiles.
 *
 * @when after_wp_load
 */
class Orbit_CLI_Profile extends Orbit_CLI {

	/**
	 * Create a new profile.
	 *
	 * ## OPTIONS
	 *
	 * --user_id=<id>
	 * : WordPress user ID.
	 *
	 * --slug=<slug>
	 * : URL-safe slug.
	 *
	 * --display_name=<name>
	 * : Public display name.
	 *
	 * [--bio=<bio>]
	 * : Optional bio.
	 *
	 * [--require_approval]
	 * : Require subscription approval. Default: true.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp orbit profile create --user_id=1 --slug=sarah-k --display_name="Sarah K"
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function create( $args, $assoc_args ) {
		$profile_id = Orbit_Profile::create(
			array(
				'user_id'          => $assoc_args['user_id'],
				'slug'             => $assoc_args['slug'],
				'display_name'     => $assoc_args['display_name'],
				'bio'              => isset( $assoc_args['bio'] ) ? $assoc_args['bio'] : null,
				'require_approval' => ! isset( $assoc_args['require_approval'] ) || $assoc_args['require_approval'],
			)
		);

		if ( is_wp_error( $profile_id ) ) {
			WP_CLI::error( $profile_id->get_error_message() );
		}

		// Upgrade user to poster.
		Orbit_Roles::upgrade_to_poster( $assoc_args['user_id'] );

		$profile = Orbit_Profile::get( $profile_id );

		WP_CLI::success( "Profile created (ID: {$profile_id})." );
		self::output_item( $profile, $assoc_args );
	}

	/**
	 * Get a profile by ID or slug.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Profile ID.
	 *
	 * [--slug=<slug>]
	 * : Profile slug.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function get( $args, $assoc_args ) {
		if ( ! empty( $args[0] ) ) {
			$profile = Orbit_Profile::get( absint( $args[0] ) );
		} elseif ( isset( $assoc_args['slug'] ) ) {
			$profile = Orbit_Profile::get_by_slug( $assoc_args['slug'] );
		} else {
			WP_CLI::error( 'Provide a profile ID or --slug.' );
		}

		if ( ! $profile ) {
			WP_CLI::error( 'Profile not found.' );
		}

		self::output_item( $profile, $assoc_args );
	}

	/**
	 * Update a profile.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Profile ID.
	 *
	 * [--display_name=<name>]
	 * : New display name.
	 *
	 * [--slug=<slug>]
	 * : New slug.
	 *
	 * [--bio=<bio>]
	 * : New bio.
	 *
	 * [--require_approval=<bool>]
	 * : Whether to require approval.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function update( $args, $assoc_args ) {
		$id        = absint( $args[0] );
		$update_args = array();

		foreach ( array( 'display_name', 'slug', 'bio' ) as $field ) {
			if ( isset( $assoc_args[ $field ] ) ) {
				$update_args[ $field ] = $assoc_args[ $field ];
			}
		}

		if ( isset( $assoc_args['require_approval'] ) ) {
			$update_args['require_approval'] = filter_var( $assoc_args['require_approval'], FILTER_VALIDATE_BOOLEAN );
		}

		$result = Orbit_Profile::update( $id, $update_args );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Profile updated.' );
		self::output_item( Orbit_Profile::get( $id ), $assoc_args );
	}

	/**
	 * Delete a profile.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Profile ID.
	 *
	 * [--force]
	 * : Hard delete (remove record entirely).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function delete( $args, $assoc_args ) {
		$result = Orbit_Profile::delete( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Profile deleted.' );
	}

	/**
	 * List profiles.
	 *
	 * ## OPTIONS
	 *
	 * [--user_id=<id>]
	 * : Filter by user ID.
	 *
	 * [--search=<term>]
	 * : Search display_name and slug.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function list_( $args, $assoc_args ) {
		$profiles = Orbit_Profile::list(
			array(
				'user_id' => isset( $assoc_args['user_id'] ) ? $assoc_args['user_id'] : null,
				'search'  => isset( $assoc_args['search'] ) ? $assoc_args['search'] : null,
			)
		);

		self::output_items( $profiles, $assoc_args, array( 'id', 'user_id', 'slug', 'display_name', 'require_approval', 'created_at' ) );
	}

	/**
	 * Regenerate a profile's share token.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Profile ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default: json.
	 *
	 * @subcommand regenerate-token
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function regenerate_token( $args, $assoc_args ) {
		$result = Orbit_Profile::regenerate_token( absint( $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "New share token: {$result}" );
		self::output_item( Orbit_Profile::get( absint( $args[0] ) ), $assoc_args );
	}
}
