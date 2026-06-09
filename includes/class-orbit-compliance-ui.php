<?php
/**
 * Compliance UI helpers.
 *
 * Owns the TCPA-compliance presentation primitives shared between the
 * rendered opt-in surfaces (subscribe + signup shortcodes) and the
 * server-side consent-ledger snapshot paths (REST handlers + CLI
 * commands). Extracted from Orbit_Shortcodes in todo 131 so REST
 * controllers and CLI commands don't have to reach into a shortcode
 * class to assemble cta_snapshot for the consent ledger.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Compliance_UI
 *
 * Static helpers — all four methods are pure functions over input
 * (plus the SMS-dormancy gate read through Orbit_Messaging_Copy).
 */
class Orbit_Compliance_UI {

	/**
	 * Canonical compliance-disclosure text shown adjacent to phone-capture
	 * fields on every opt-in surface.
	 *
	 * Used in TWO places that must agree byte-for-byte:
	 *
	 * 1. As the rendered HTML in the subscribe + signup forms (via
	 *    {@see self::render_compliance_block()}).
	 * 2. As the cta_snapshot string stored on every consent ledger row at
	 *    opt-in time (via {@see Orbit_REST_Subscription::handle_subscribe}
	 *    and {@see Orbit_REST_Signup::handle_signup} when they call
	 *    Orbit_Consent::record).
	 *
	 * Storing the exact phrasing the user agreed to is the TCPA evidence
	 * the consent ledger exists to preserve.
	 *
	 * When this text changes, bump ORBIT_VERSION so the orbit_policy_version
	 * post_meta on /privacy/ + /terms/ tracks the change. Every consent
	 * row captures the version it agreed to.
	 *
	 * @param string|null $privacy_label Optional label to interpolate for the
	 *                                   "Privacy Policy" placeholder. Pass plain
	 *                                   text (default) for the ledger snapshot;
	 *                                   pass anchor HTML for the rendered form.
	 * @param string|null $terms_label   Optional label to interpolate for the
	 *                                   "Terms" placeholder. Same semantics as
	 *                                   $privacy_label.
	 * @return string Plain text (default) safe to esc_html() into HTML or store
	 *                verbatim in the ledger; or HTML when callers pass anchor
	 *                strings.
	 */
	public static function compliance_disclosure_text( $privacy_label = null, $terms_label = null ) {
		if ( null === $privacy_label ) {
			$privacy_label = __( 'Privacy Policy', 'orbit' );
		}
		if ( null === $terms_label ) {
			$terms_label = __( 'Terms', 'orbit' );
		}

		$baseline = sprintf(
			/* translators: 1: Privacy Policy link or label, 2: Terms link or label. */
			__( 'Get notified when posters you follow share new activities. Email is required; phone is optional and used only for SMS notifications. Up to 10 msgs/week. Msg & data rates may apply. Reply STOP to opt out, HELP for help. See our %1$s and %2$s.', 'orbit' ),
			$privacy_label,
			$terms_label
		);

		// SMS dormancy clause comes from the central gate so the dashboard
		// banner, settings help note, this disclosure, and the ledger
		// snapshot all flip together on the SMS-launch day. The clause is
		// PREPENDED — keep this position stable across the rendered HTML
		// path and the ledger snapshot path or the cta_snapshot byte-match
		// invariant breaks. When SMS is live the helper returns an empty
		// string and the disclosure reads as the baseline alone.
		$sms_clause = Orbit_Messaging_Copy::sms_status_clause();
		if ( '' === $sms_clause ) {
			return $baseline;
		}

		return $sms_clause . ' ' . $baseline;
	}

	/**
	 * Render the compliance-disclosure block.
	 *
	 * Visually distinct from surrounding form fields so it reads as a
	 * disclosure, not body copy. Twilio reviewer guidance: the
	 * disclosures must be adjacent to the phone field, not buried in
	 * the form footer. Includes inline links to /privacy/ and /terms/.
	 *
	 * @return string Block-level HTML.
	 */
	public static function render_compliance_block() {
		$privacy_url = esc_url( home_url( '/privacy/' ) );
		$terms_url   = esc_url( home_url( '/terms/' ) );

		// Build the anchor labels locally so the sprintf template only ever
		// interpolates trusted strings. Each label escapes its own translated
		// text; the rest of the sentence is locale-controlled but contains
		// no untrusted input. This replaces an earlier str_replace approach
		// that broke i18n because translators don't necessarily render the
		// substring "Privacy Policy" / "Terms" identically in two independent
		// translation strings — see todo 111.
		$privacy_label = '<a href="' . $privacy_url . '">' . esc_html__( 'Privacy Policy', 'orbit' ) . '</a>';
		$terms_label   = '<a href="' . $terms_url . '">' . esc_html__( 'Terms', 'orbit' ) . '</a>';

		// The sentence template is interpolated with anchor HTML, so we
		// can't esc_html() the whole result. Run the rendered string through
		// wp_kses() with a tight allowlist (only the <a> tags we inject) so
		// any HTML-special chars in the brand name (interpolated inside
		// compliance_disclosure_text() and not pre-escaped, to preserve the
		// byte-match invariant with the ledger snapshot) are neutralized.
		$rendered = self::compliance_disclosure_text( $privacy_label, $terms_label );
		$rendered = wp_kses(
			$rendered,
			array(
				'a' => array(
					'href' => array(),
				),
			)
		);

		return '<div class="orbit-compliance-block" role="note">' . $rendered . '</div>';
	}

	/**
	 * Render the optional phone field. Visually grouped with the
	 * compliance block.
	 *
	 * @param string $id_prefix Form ID prefix so multiple forms on a
	 *                          single page don't collide (e.g. 'orbit-subscribe').
	 * @return string Block-level HTML.
	 */
	public static function render_phone_field( $id_prefix ) {
		$id = $id_prefix . '-phone';

		ob_start();
		?>
		<div class="orbit-form-group orbit-phone-group">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Phone (optional)', 'orbit' ); ?></label>
			<input
				type="tel"
				id="<?php echo esc_attr( $id ); ?>"
				name="phone"
				autocomplete="tel"
				inputmode="tel"
				placeholder="+1 555 555 0123"
				data-orbit-phone-input
			>
			<p class="orbit-help"><?php esc_html_e( 'Include country code (e.g. +1 for US/Canada).', 'orbit' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the consent checkboxes.
	 *
	 * Two checkboxes per Twilio's "explicit consent per channel" pattern:
	 * - Email consent is required (the form can't submit without it).
	 * - SMS consent is optional and only meaningful when the phone field
	 *   has a value. JS toggles its `disabled` attribute based on phone
	 *   input; server-side, if `consent_sms=1` arrives without a phone
	 *   the handler returns a validation error.
	 *
	 * @param string $id_prefix Form ID prefix.
	 * @return string Block-level HTML.
	 */
	public static function render_consent_checkboxes( $id_prefix ) {
		$email_id = $id_prefix . '-consent-email';
		$sms_id   = $id_prefix . '-consent-sms';

		ob_start();
		?>
		<div class="orbit-form-group orbit-consent-group">
			<label class="orbit-checkbox-label" for="<?php echo esc_attr( $email_id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $email_id ); ?>" name="consent_email" value="1" required>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: brand name */
						__( 'I agree to receive activity notifications from %s at the email address above.', 'orbit' ),
						defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' )
					)
				);
				?>
				<span class="orbit-required-mark" aria-hidden="true">*</span>
			</label>
			<label class="orbit-checkbox-label" for="<?php echo esc_attr( $sms_id ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $sms_id ); ?>"
					name="consent_sms"
					value="1"
					disabled
					data-orbit-sms-consent
				>
				<?php esc_html_e( "Also send me SMS notifications at the phone above (once SMS is live).", 'orbit' ); ?>
				<span class="orbit-help"><?php esc_html_e( '(Optional — only available when you provide a phone number.)', 'orbit' ); ?></span>
			</label>
		</div>
		<?php
		return ob_get_clean();
	}
}
