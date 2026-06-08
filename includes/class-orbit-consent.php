<?php
/**
 * Append-only consent ledger.
 *
 * Records per-channel opt-in / opt-out / re-opt-in events for TCPA defense
 * and Twilio A2P 10DLC audit. Every write is chained to the previous row
 * for that (user_id, channel) tuple via SHA-256, so tampering with any row
 * breaks the chain and is detectable by verify_chain().
 *
 * The table is network-scoped on multisite ($wpdb->base_prefix) because
 * users are network-wide on multisite — a user's consent must follow them
 * across sub-sites, not fragment per sub-site.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static facade for the consent ledger.
 */
class Orbit_Consent {

	/**
	 * Valid channels for consent events.
	 */
	const CHANNELS = array( 'email', 'sms' );

	/**
	 * Valid event types.
	 */
	const EVENTS = array( 'opt_in', 'opt_out', 're_opt_in' );

	/**
	 * Default program identifier for consent events.
	 *
	 * Distinguishes one consent stream from another in the unlikely case
	 * Perihelion ever runs multiple parallel messaging programs.
	 */
	const PROGRAM_DEFAULT = 'creator-notifications';

	/**
	 * Hash-chain version tag.
	 *
	 * Every row's row_hash payload is prefixed with this tag so we can
	 * evolve the hash inputs without invalidating older rows. v1.6.0 ships
	 * with v2 directly because no production rows exist yet; if a future
	 * change adds more fields we'll bump to v3 and add a compat codepath
	 * in verify_chain().
	 *
	 * @var string
	 */
	const CHAIN_VERSION = 'v2';

	/**
	 * Runtime flag complement to the ORBIT_CONSENT_MIGRATION constant.
	 *
	 * The constant is the production gate (defined for deliberate migration
	 * windows). This flag is the same gate for test code or one-shot CLI
	 * commands that need to bypass the query guard without polluting global
	 * state. Use Orbit_Consent::with_migration_mode() to flip it around a
	 * callable.
	 *
	 * Visibility is private (not protected) so subclasses and reflection
	 * cannot quietly flip the guard outside the sanctioned wrapper.
	 *
	 * @var bool
	 */
	private static $in_migration_mode = false;

	/**
	 * Returns the fully-prefixed table name.
	 *
	 * Always uses $wpdb->base_prefix so the ledger is network-scoped on
	 * multisite — consent follows the user, which is network-wide.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->base_prefix . ORBIT_TABLE_CONSENT_LEDGER;
	}

	/**
	 * Resolve the effective IP salt for hashing.
	 *
	 * Production should pin the salt to wp-config.php via the
	 * ORBIT_CONSENT_IP_SALT constant. When the constant is undefined we
	 * fall back to the `orbit_consent_ip_salt` option so a fresh install
	 * does not silently fail every consent write. The activator seeds the
	 * option with a generated value; admin notices encourage ops to move
	 * it to wp-config for key-rotation discipline.
	 *
	 * The `orbit_consent_ip_salt_resolved` filter runs last so tests and
	 * HSM-backed integrations can fully override the resolution.
	 *
	 * @return string Effective salt, or '' when neither source is set.
	 */
	protected static function resolve_ip_salt() {
		if ( defined( 'ORBIT_CONSENT_IP_SALT' ) && '' !== ORBIT_CONSENT_IP_SALT ) {
			$salt = (string) ORBIT_CONSENT_IP_SALT;
		} else {
			$salt = (string) get_option( 'orbit_consent_ip_salt', '' );
		}

		/**
		 * Filter the resolved consent IP salt.
		 *
		 * Last-mile override for HSM-backed deployments and tests that
		 * need to exercise the salt-missing branch. Returning '' triggers
		 * the orbit_consent_salt_missing WP_Error path in record().
		 *
		 * @param string $salt Resolved salt (from constant or option fallback).
		 */
		return (string) apply_filters( 'orbit_consent_ip_salt_resolved', $salt );
	}

	/**
	 * Record a consent event.
	 *
	 * @param int    $user_id User ID.
	 * @param string $channel One of self::CHANNELS.
	 * @param string $event   One of self::EVENTS.
	 * @param array  $args    {
	 *     Optional context.
	 *
	 *     @type string $cta_snapshot     The exact CTA copy the user saw at opt-in.
	 *     @type string $source           Free-form source label (subscribe|signup|settings|sms_stop|sms_start|email_unsubscribe|admin|...).
	 *     @type string $program          Defaults to self::PROGRAM_DEFAULT.
	 *     @type string $privacy_version  Privacy policy version at consent time. Auto-resolved if omitted.
	 *     @type string $terms_version    Terms version at consent time. Auto-resolved if omitted.
	 *     @type string $user_agent       Override the $_SERVER detection (for CLI/admin paths).
	 *     @type string $ip               Override the Orbit_Client_IP detection.
	 * }
	 * @return int|WP_Error Inserted row ID, or WP_Error on validation failure, salt missing, chain conflict, or DB error.
	 */
	public static function record( $user_id, $channel, $event, array $args = array() ) {
		$salt = self::resolve_ip_salt();
		if ( '' === $salt ) {
			return new WP_Error(
				'orbit_consent_salt_missing',
				__( 'ORBIT_CONSENT_IP_SALT is not defined in wp-config.php and the orbit_consent_ip_salt fallback option is empty. Consent rows cannot be recorded.', 'orbit' )
			);
		}

		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return new WP_Error( 'orbit_consent_invalid_user', __( 'Invalid user ID.', 'orbit' ) );
		}

		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return new WP_Error( 'orbit_consent_invalid_channel', __( 'Invalid channel.', 'orbit' ) );
		}

		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return new WP_Error( 'orbit_consent_invalid_event', __( 'Invalid event type.', 'orbit' ) );
		}

		global $wpdb;
		$table = self::table_name();

		$cta_snapshot = isset( $args['cta_snapshot'] ) ? (string) $args['cta_snapshot'] : '';

		// Defend against callers who accidentally pass the entire rendered
		// HTML page (or megabytes of irrelevant context) instead of the CTA
		// text. Silent truncation would invalidate future hash verification,
		// so reject loudly instead.
		if ( strlen( $cta_snapshot ) > 16000 ) {
			return new WP_Error(
				'orbit_consent_cta_too_long',
				__( 'cta_snapshot exceeds 16,000 character limit.', 'orbit' )
			);
		}
		$source       = isset( $args['source'] ) ? sanitize_text_field( $args['source'] ) : '';
		$program      = isset( $args['program'] ) ? sanitize_text_field( $args['program'] ) : self::PROGRAM_DEFAULT;

		$privacy_version = isset( $args['privacy_version'] ) ? sanitize_text_field( $args['privacy_version'] ) : self::current_policy_version( 'privacy' );
		$terms_version   = isset( $args['terms_version'] ) ? sanitize_text_field( $args['terms_version'] ) : self::current_policy_version( 'terms' );

		$ip         = isset( $args['ip'] ) ? (string) $args['ip'] : Orbit_Client_IP::get();
		$ip_hash    = '' === $ip ? '' : self::hash_ip( $ip, $salt );
		$user_agent = isset( $args['user_agent'] ) ? (string) $args['user_agent'] : self::detect_user_agent();
		$user_agent = mb_substr( $user_agent, 0, 255 );

		$now = isset( $args['created_at_utc'] ) ? $args['created_at_utc'] : gmdate( 'Y-m-d H:i:s' );

		$prev_hash = self::latest_row_hash( $user_id, $channel );
		$row_hash  = self::compute_row_hash(
			$user_id,
			$channel,
			$event,
			$program,
			$privacy_version,
			$terms_version,
			$cta_snapshot,
			$source,
			$ip_hash,
			$user_agent,
			$now,
			$prev_hash
		);

		// Allow the append-only query guard to permit this single insert.
		// The guard only blocks UPDATE / DELETE, but we set the marker so
		// nothing else hooked on `query` mistakes us for a write outside
		// the legitimate path.
		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'                => $user_id,
				'channel'                => $channel,
				'event'                  => $event,
				'program'                => $program,
				'cta_snapshot'           => $cta_snapshot,
				'source'                 => $source,
				'ip_hash'                => $ip_hash,
				'user_agent'             => $user_agent,
				'privacy_policy_version' => $privacy_version,
				'terms_version'          => $terms_version,
				'created_at_utc'         => $now,
				'row_hash'               => $row_hash,
				'prev_hash'              => $prev_hash,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// Distinguish chain conflict (retry-able) from other DB errors
			// (real failures) so callers can decide whether to retry.
			$last_error = (string) $wpdb->last_error;

			if ( '' !== $last_error && (
				false !== stripos( $last_error, 'Duplicate entry' )
				|| false !== strpos( $last_error, '1062' )
			) ) {
				return new WP_Error(
					'orbit_consent_chain_conflict',
					__( 'Consent chain conflict — another write was committed concurrently. Retry with a refreshed prev_hash.', 'orbit' )
				);
			}

			return new WP_Error(
				'orbit_consent_insert_failed',
				'' === $last_error ? __( 'Consent ledger insert failed with no DB error message.', 'orbit' ) : $last_error
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return the most recent event string for a (user_id, channel) tuple.
	 *
	 * @param int    $user_id User ID.
	 * @param string $channel Channel.
	 * @return string|null Event name ('opt_in'|'opt_out'|'re_opt_in') or null if no events.
	 */
	public static function latest_state( $user_id, $channel ) {
		global $wpdb;

		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$event = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT event FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY created_at_utc DESC, id DESC LIMIT 1",
				(int) $user_id,
				$channel
			)
		);
		// phpcs:enable

		return null === $event ? null : (string) $event;
	}

	/**
	 * Verify the hash chain for a (user_id, channel) tuple.
	 *
	 * Walks the chain in insertion order and recomputes each row's
	 * row_hash. The first row at which the stored hash diverges from the
	 * recomputed hash is reported. An intact chain returns array().
	 *
	 * The recompute uses the row's stored user_id (not the URL-supplied
	 * parameter) so a tamper attempt that rewrites user_id is detectable.
	 *
	 * @param int    $user_id User ID.
	 * @param string $channel Channel.
	 * @return array Array of broken row IDs, or empty array if chain is intact.
	 */
	public static function verify_chain( $user_id, $channel ) {
		global $wpdb;

		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return array();
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, channel, event, program, cta_snapshot, source, ip_hash, user_agent, privacy_policy_version, terms_version, created_at_utc, row_hash, prev_hash
				 FROM {$table}
				 WHERE user_id = %d AND channel = %s
				 ORDER BY created_at_utc ASC, id ASC",
				(int) $user_id,
				$channel
			)
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return array();
		}

		$broken        = array();
		$expected_prev = '';
		foreach ( $rows as $row ) {
			if ( $row->prev_hash !== $expected_prev ) {
				$broken[] = (int) $row->id;
				continue;
			}

			$recomputed = self::compute_row_hash(
				(int) $row->user_id,
				$row->channel,
				$row->event,
				$row->program,
				$row->privacy_policy_version,
				$row->terms_version,
				$row->cta_snapshot,
				$row->source,
				$row->ip_hash,
				$row->user_agent,
				$row->created_at_utc,
				$row->prev_hash
			);

			if ( ! hash_equals( $recomputed, $row->row_hash ) ) {
				$broken[] = (int) $row->id;
			}

			$expected_prev = $row->row_hash;
		}

		return $broken;
	}

	/**
	 * Hash an IP for storage using the resolved consent salt.
	 *
	 * HMAC-SHA256 over the IP with a per-install salt. Storing the hash
	 * lets us prove "we recorded an IP at consent time" without retaining
	 * raw personal data after the TCPA retention horizon.
	 *
	 * @param string $ip   IP address.
	 * @param string $salt Optional explicit salt; resolves via resolve_ip_salt() when omitted.
	 * @return string 64-char hex digest, or '' when no salt is available.
	 */
	public static function hash_ip( $ip, $salt = null ) {
		if ( null === $salt ) {
			$salt = self::resolve_ip_salt();
		}

		if ( '' === $salt ) {
			return '';
		}

		return hash_hmac( 'sha256', (string) $ip, (string) $salt );
	}

	/**
	 * Compute the row_hash for a row.
	 *
	 * Pure function over every TCPA-load-bearing field plus prev_hash, with
	 * a leading chain-version tag (see self::CHAIN_VERSION). Used during
	 * both insertion (Orbit_Consent::record()) and verification
	 * (Orbit_Consent::verify_chain()) so the hash computation is
	 * symmetrical.
	 *
	 * The version tag lets us evolve the payload later without breaking
	 * pre-existing rows. v1.6.0 ships as v2 because the v1 payload omitted
	 * program / privacy_policy_version / terms_version (a tampering hole)
	 * and we have no production rows to retain compatibility with.
	 *
	 * @param int    $user_id                User ID.
	 * @param string $channel                Channel.
	 * @param string $event                  Event.
	 * @param string $program                Program identifier.
	 * @param string $privacy_policy_version Privacy policy version at consent time.
	 * @param string $terms_version          Terms version at consent time.
	 * @param string $cta_snapshot           CTA copy shown to the user.
	 * @param string $source                 Free-form source label.
	 * @param string $ip_hash                HMAC-SHA256 of the client IP.
	 * @param string $user_agent             User agent string.
	 * @param string $created_at_utc         Row creation timestamp (UTC).
	 * @param string $prev_hash              Previous row hash.
	 * @return string 64-char hex digest.
	 */
	public static function compute_row_hash( $user_id, $channel, $event, $program, $privacy_policy_version, $terms_version, $cta_snapshot, $source, $ip_hash, $user_agent, $created_at_utc, $prev_hash ) {
		$payload = implode(
			'|',
			array(
				self::CHAIN_VERSION,
				(int) $user_id,
				(string) $channel,
				(string) $event,
				(string) $program,
				(string) $privacy_policy_version,
				(string) $terms_version,
				(string) $cta_snapshot,
				(string) $source,
				(string) $ip_hash,
				(string) $user_agent,
				(string) $created_at_utc,
				(string) $prev_hash,
			)
		);

		return hash( 'sha256', $payload );
	}

	/**
	 * Look up the latest row_hash for a (user_id, channel) tuple.
	 *
	 * Returns empty string when the chain is uninitialized (first event).
	 *
	 * @param int    $user_id User ID.
	 * @param string $channel Channel.
	 * @return string
	 */
	protected static function latest_row_hash( $user_id, $channel ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT row_hash FROM {$table} WHERE user_id = %d AND channel = %s ORDER BY created_at_utc DESC, id DESC LIMIT 1",
				(int) $user_id,
				$channel
			)
		);
		// phpcs:enable

		return null === $hash ? '' : (string) $hash;
	}

	/**
	 * Detect the current request's User-Agent string.
	 *
	 * @return string
	 */
	protected static function detect_user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}

	/**
	 * Look up the currently published version string for a policy page.
	 *
	 * Reads post_meta `orbit_policy_version` from the relevant page. When
	 * the page or meta doesn't exist (early in Phase 1 deployment), falls
	 * back to ORBIT_VERSION so the row is still self-describing.
	 *
	 * @param string $kind 'privacy' or 'terms'.
	 * @return string
	 */
	protected static function current_policy_version( $kind ) {
		$slug = 'privacy' === $kind ? 'privacy' : 'terms';
		$page = get_page_by_path( $slug );

		if ( $page ) {
			$version = get_post_meta( $page->ID, 'orbit_policy_version', true );
			if ( ! empty( $version ) ) {
				return (string) $version;
			}
		}

		return defined( 'ORBIT_VERSION' ) ? ORBIT_VERSION : '';
	}

	/**
	 * Register the append-only query guard.
	 *
	 * Hooks the `query` filter so any non-INSERT statement against the
	 * consent ledger table outside an ORBIT_CONSENT_MIGRATION window is
	 * replaced with a no-op SELECT. Append-only is an audit / legal-defense
	 * invariant — a stray UPDATE / REPLACE / DELETE from any plugin, theme,
	 * or `wp db query` would silently invalidate TCPA records. The guard is
	 * loud about what it blocks (E_USER_WARNING) so the offending code is
	 * findable in error logs.
	 *
	 * Must be called once at file-load time (from orbit.php).
	 */
	public static function register_query_guard() {
		add_filter( 'query', array( __CLASS__, 'filter_query' ) );
	}

	/**
	 * Query filter callback: refuse any non-INSERT write against the ledger.
	 *
	 * Implements an allow-list: the only permitted operation is a bare
	 * INSERT (without ON DUPLICATE KEY UPDATE, which would turn into a
	 * functional UPDATE via the unique key on chain_pos). Leading SQL
	 * comments are stripped before classification so trace-prepended
	 * statements from Query Monitor / NewRelic etc. cannot smuggle a
	 * write past the guard.
	 *
	 * @param string $query SQL query.
	 * @return string Possibly substituted no-op query.
	 */
	public static function filter_query( $query ) {
		// Allow during deliberate migration (only legitimate UPDATE: the
		// scheduled retention-redaction job that NULLs ip_hash + user_agent
		// after the TCPA retention horizon). Either the constant (set in
		// wp-config.php for production migration windows) or the runtime
		// flag (set via with_migration_mode() for tests + CLI) bypasses
		// the guard.
		if ( self::$in_migration_mode ) {
			return $query;
		}

		if ( defined( 'ORBIT_CONSENT_MIGRATION' ) && ORBIT_CONSENT_MIGRATION ) {
			return $query;
		}

		if ( ! self::is_consent_ledger_query( $query ) ) {
			return $query;
		}

		// Strip leading SQL comments so a comment-prefixed write cannot
		// slip past the first-word classification.
		$stripped = preg_replace( '#^\s*(/\*.*?\*/|--[^\n]*\n|\#[^\n]*\n|\s)+#s', '', (string) $query );
		if ( null === $stripped ) {
			$stripped = (string) $query;
		}

		$first_token = strtok( $stripped, " \t\n(" );
		$first_word  = false === $first_token ? '' : strtoupper( $first_token );

		// Deny-list: refuse any verb that could mutate EXISTING ledger
		// rows. Reads (SELECT), introspection (SHOW, DESCRIBE, EXPLAIN),
		// schema (CREATE, ALTER, DROP — needed during activation +
		// migration), and bare INSERT all pass through.
		$destructive = array( 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE', 'RENAME' );

		$is_destructive = in_array( $first_word, $destructive, true );

		// INSERT ... ON DUPLICATE KEY UPDATE is a functional UPDATE via
		// the chain_pos UNIQUE — block it even though the leading verb
		// is INSERT.
		if ( 'INSERT' === $first_word
			&& false !== stripos( $stripped, 'ON DUPLICATE KEY UPDATE' )
		) {
			$is_destructive = true;
		}

		if ( ! $is_destructive ) {
			return $query;
		}

		// Don't use wp_die — that would crash the request. Just no-op the
		// write and emit a warning so the source is findable in logs.
		trigger_error(
			'Orbit_Consent: refused destructive write against append-only ledger. Query: ' . esc_html( substr( (string) $query, 0, 200 ) ),
			E_USER_WARNING
		);

		return 'SELECT 1 WHERE 1 = 0';
	}

	/**
	 * Run a callable with the query guard temporarily bypassed.
	 *
	 * Production migration windows should prefer the ORBIT_CONSENT_MIGRATION
	 * constant. This runtime wrapper exists for test fixtures + CLI
	 * commands that need to perform legitimate write maintenance (cleanup,
	 * redaction, reconciliation) without polluting global PHP constant
	 * state.
	 *
	 * WARNING: `exit()`, `die()`, and `wp_die()` inside the callback BYPASS
	 * the try/finally — PHP's shutdown sequence does not unwind pending
	 * finally blocks. To defend against that we also register a shutdown
	 * function that restores the prior state. The finally block still runs
	 * for normal returns and thrown exceptions; the shutdown callback is
	 * idempotent (restores prior over prior) when both fire.
	 *
	 * @param callable $callback Work to run with the guard relaxed.
	 * @return mixed Whatever the callback returns.
	 */
	public static function with_migration_mode( callable $callback ) {
		$prior                   = self::$in_migration_mode;
		self::$in_migration_mode = true;

		// Safety net for exit() / die() / wp_die() inside the callback —
		// finally would not run in that case, but shutdown functions do.
		register_shutdown_function(
			static function () use ( $prior ) {
				self::$in_migration_mode = $prior;
			}
		);

		try {
			return $callback();
		} finally {
			self::$in_migration_mode = $prior;
		}
	}

	/**
	 * Cheap regex check to decide whether a query touches the ledger
	 * table. Uses word boundaries so an audit-log INSERT that merely
	 * mentions the table name as a string literal does not false-positive.
	 *
	 * @param string $query SQL query.
	 * @return bool
	 */
	protected static function is_consent_ledger_query( $query ) {
		// Memoize self::table_name() so the wpdb global isn't re-read for
		// every query the filter sees — this runs on the `query` filter, so
		// "every query" is literally every query for the request.
		static $table = null;
		if ( null === $table ) {
			$table = self::table_name();
		}

		$pattern = '/\b' . preg_quote( $table, '/' ) . '\b/i';
		return 1 === preg_match( $pattern, (string) $query );
	}
}
