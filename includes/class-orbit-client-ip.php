<?php
/**
 * Client IP resolver.
 *
 * `$_SERVER['REMOTE_ADDR']` alone is wrong behind Cloudflare or any
 * reverse proxy — it returns the proxy IP, not the real client. When
 * the site is configured with a trusted forwarded header (via the
 * `orbit_client_ip_header` filter), we read the first IP from that
 * header instead. Default behavior is unchanged for sites that don't
 * configure a header.
 *
 * Usage:
 *   add_filter( 'orbit_client_ip_header', fn () => 'HTTP_CF_CONNECTING_IP' );
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Client_IP
 */
class Orbit_Client_IP {

	/**
	 * Resolve the client IP.
	 *
	 * @return string Client IP, or empty string if nothing resolves.
	 */
	public static function get() {
		$proxy_header = apply_filters( 'orbit_client_ip_header', '' );

		if ( ! empty( $proxy_header ) && ! empty( $_SERVER[ $proxy_header ] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER[ $proxy_header ] ) );
			$parts     = array_map( 'trim', explode( ',', $forwarded ) );
			$candidate = $parts[0] ?? '';
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '';
	}
}
