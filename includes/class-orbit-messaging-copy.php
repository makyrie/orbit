<?php
/**
 * Central source of truth for SMS dormancy / launch copy.
 *
 * While the A2P 10DLC messaging service is awaiting carrier approval,
 * several user-facing surfaces tell visitors that "SMS goes live once
 * our messaging service is approved" and that we'll email everything
 * in the meantime. The exact phrasing is part of the consent ledger's
 * cta_snapshot (TCPA legal-defense evidence), so any drift between
 * surfaces silently mis-records what each user agreed to.
 *
 * This class is the single gate that decides what the dormancy/launch
 * copy says. Every surface that mentions SMS-coming-soon — the
 * dashboard onboarding banner, the settings phone-help note, and the
 * compliance disclosure on subscribe + signup forms — composes its
 * text through these helpers instead of hardcoding the sentence.
 *
 * Phase 5 (SMS go-live) is therefore a one-flag flip: when
 * {@see Orbit_Features::sms_enabled()} starts returning true, every
 * helper here switches to its live-state copy without any further
 * code edit at the call sites.
 *
 * NOTE: When Phase 5 ships, the post-launch copy below becomes the
 * permanent baseline. Update or retire the gettext string IDs on the
 * same commit so translators can resync — leaving stale "coming soon"
 * IDs hanging would let an old translation re-surface if a translation
 * file falls out of date.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central SMS-dormancy / launch copy gate.
 *
 * All four user-visible "SMS coming soon" surfaces compose their text
 * through this class. See class docblock above for the launch protocol.
 */
class Orbit_Messaging_Copy {

	/**
	 * Brand name used in user-facing dormancy copy.
	 *
	 * Same fallback pattern as the compliance disclosure: prefer the
	 * pinned ORBIT_MESSAGING_BRAND constant (cannot drift via Settings →
	 * General), fall back to the WP site name when the constant isn't
	 * defined.
	 *
	 * @return string Brand name to interpolate into copy.
	 */
	private static function brand() {
		return defined( 'ORBIT_MESSAGING_BRAND' ) ? ORBIT_MESSAGING_BRAND : get_bloginfo( 'name' );
	}

	/**
	 * SMS-dormancy status clause appended to the compliance disclosure.
	 *
	 * Returns the localized "Initially we deliver everything by email —
	 * SMS goes live once X's messaging service is approved." sentence
	 * while {@see Orbit_Features::sms_enabled()} is false. Returns the
	 * empty string when SMS is live so the disclosure baseline (which
	 * already describes the active SMS program) reads correctly.
	 *
	 * Callers that compose this into the disclosure MUST place it at
	 * the same position both in the rendered form HTML and in the
	 * ledger snapshot path — the two must byte-match per the existing
	 * cta_snapshot invariant.
	 *
	 * @return string Localized clause, or empty string when SMS is live.
	 */
	public static function sms_status_clause() {
		if ( Orbit_Features::sms_enabled() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: brand name (e.g. "Perihelion"). */
			__( "Initially we deliver everything by email — SMS goes live once %s's messaging service is approved.", 'orbit' ),
			self::brand()
		);
	}

	/**
	 * Dashboard onboarding banner body shown to users who haven't yet
	 * verified a phone.
	 *
	 * Dormant state: the current "verify your phone in Settings so we
	 * can text you as soon as our SMS program launches" wording.
	 *
	 * Live state: a phone-verification CTA that's still valid after
	 * launch. Note that the dashboard caller wraps the banner so it
	 * doesn't render at all once SMS is live (per todo 128, the
	 * verify-your-phone CTA can land elsewhere later) — but the helper
	 * still returns a launch-appropriate string so that any future
	 * caller doesn't have to special-case the dormant variant.
	 *
	 * Both variants embed a Settings link via an `{settings_link}`
	 * placeholder that the caller substitutes with the appropriate
	 * anchor (the helper stays HTML-free so callers escape as needed).
	 *
	 * @return string Localized banner body (plain text with anchor placeholder).
	 */
	public static function dashboard_onboarding_banner_copy() {
		if ( Orbit_Features::sms_enabled() ) {
			return __( 'Verify your phone to enable SMS notifications. {settings_link}', 'orbit' );
		}

		return __( 'Set up SMS notifications: {settings_link} to receive activity alerts as soon as our SMS program launches.', 'orbit' );
	}

	/**
	 * Settings phone-help note shown beneath the phone-number input on
	 * the settings page (currently {@see Orbit_Shortcodes::render_phone_verification()}).
	 *
	 * Dormant state: tell the user that the number they enter now will
	 * be used to enable SMS as soon as the program launches.
	 *
	 * Live state: matter-of-fact "verify to enable SMS for the tiers
	 * you opt into" — no more "as soon as it launches" promise.
	 *
	 * @return string Localized help note.
	 */
	public static function settings_phone_help_note() {
		if ( Orbit_Features::sms_enabled() ) {
			return __( "We have this number on file from your sign-up but it's not verified yet. Verify it now to enable SMS notifications.", 'orbit' );
		}

		return __( "We have this number on file from your sign-up but it's not verified yet. Verify it now to enable SMS notifications when SMS goes live.", 'orbit' );
	}
}
