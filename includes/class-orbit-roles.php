<?php
/**
 * Role and capability registration.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Roles
 */
class Orbit_Roles {

	/**
	 * Register Orbit roles and capabilities.
	 *
	 * Safe to call multiple times — WordPress ignores add_role if the role exists.
	 */
	public static function register() {
		add_role(
			'orbit_subscriber',
			__( 'Orbit Subscriber', 'orbit' ),
			array(
				'read'                       => true,
				'orbit_subscribe'            => true,
				'orbit_respond'              => true,
				'orbit_manage_preferences'   => true,
				'orbit_view_activities'      => true,
			)
		);

		add_role(
			'orbit_poster',
			__( 'Orbit Poster', 'orbit' ),
			array(
				'read'                       => true,
				'orbit_subscribe'            => true,
				'orbit_respond'              => true,
				'orbit_manage_preferences'   => true,
				'orbit_view_activities'      => true,
				'orbit_create_activity'      => true,
				'orbit_manage_activity'      => true,
				'orbit_manage_profile'       => true,
				'orbit_manage_subscribers'   => true,
			)
		);

		// Grant admin all Orbit capabilities.
		$admin_role = get_role( 'administrator' );

		if ( $admin_role ) {
			$admin_role->add_cap( 'orbit_subscribe' );
			$admin_role->add_cap( 'orbit_respond' );
			$admin_role->add_cap( 'orbit_manage_preferences' );
			$admin_role->add_cap( 'orbit_view_activities' );
			$admin_role->add_cap( 'orbit_create_activity' );
			$admin_role->add_cap( 'orbit_manage_activity' );
			$admin_role->add_cap( 'orbit_manage_profile' );
			$admin_role->add_cap( 'orbit_manage_subscribers' );
			$admin_role->add_cap( 'orbit_admin' );
		}
	}

	/**
	 * Upgrade a user from subscriber to poster.
	 *
	 * Adds the orbit_poster role without removing orbit_subscriber,
	 * since a user can hold both roles simultaneously.
	 *
	 * @param int $user_id The user ID.
	 */
	public static function upgrade_to_poster( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$user->add_role( 'orbit_poster' );
	}
}
