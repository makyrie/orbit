<?php
/**
 * Branded HTML email template builder.
 *
 * A tiny, dependency-free static builder that renders Perihelion's branded
 * HTML email shell and the content-piece fragments that go inside it. It is
 * the HTML counterpart to the warm plaintext bodies in Orbit_Emails /
 * Orbit_Notifier: callers assemble an inner-HTML string from the
 * `paragraph()` / `button()` / `heading()` helpers, then hand it to `wrap()`
 * to get a full, email-client-safe HTML document.
 *
 * Design constraints (email, not web):
 *  - ALL CSS is inline. Email clients routinely strip `<style>` blocks, so
 *    every visual rule lives on a `style=""` attribute.
 *  - Layout is table-based. Flexbox/grid are unreliable across Outlook and
 *    the older webmail renderers.
 *  - The palette + type are the approved Perihelion brand tokens (see the
 *    CONST block): Paper background, Sienna wordmark/button, Ink body copy,
 *    Slate sign-offs, a muted footer.
 *
 * The wordmark is intentionally isolated in `wordmark()` as ONE swappable
 * unit: #62 will replace the serif text lockup with a hosted logo image, and
 * that change should touch exactly one method here.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Orbit_Email_Template
 */
class Orbit_Email_Template {

	/**
	 * Brand tokens (approved palette + type). Kept as constants so the whole
	 * template reads from one source and #62's logo swap / any future re-skin
	 * is a localized edit.
	 */
	const COLOR_PAPER     = '#F0EEE6'; // newsprint canvas
	const COLOR_CARD      = '#ffffff';
	const COLOR_SIENNA    = '#D8176E'; // brand accent — deep pink (name kept; used for links/buttons/wordmark)
	const COLOR_INK       = '#191A1D';
	const COLOR_SLATE     = '#54555C';
	const COLOR_FOOTER    = '#8A8A82';
	const COLOR_CARD_EDGE = '#191A1D'; // ink hairline

	/*
	 * Both faces are now sans — the Community Press redesign dropped serifs.
	 * Webfonts (Jost / Work Sans) don't load reliably in email, so these are
	 * email-safe stacks: a geometric display face (FONT_SERIF, name kept) that
	 * evokes Jost, and a neutral body face (FONT_SANS).
	 */
	const FONT_SERIF = "'Century Gothic','Futura','Trebuchet MS',Helvetica,Arial,sans-serif";
	const FONT_SANS  = "'Helvetica Neue',Helvetica,Arial,sans-serif";

	/**
	 * Wrap assembled inner HTML in the full branded email document.
	 *
	 * Returns a complete `<!DOCTYPE html>` document: a hidden preheader (the
	 * inbox preview snippet), the Paper-background canvas, the wordmark, a
	 * white card holding `$inner_html`, and the muted footer.
	 *
	 * @param string $inner_html Pre-escaped HTML fragments (built from the
	 *                           helpers below) to place inside the card.
	 * @param string $preheader  Optional inbox preview text. Plain string;
	 *                           escaped internally.
	 * @return string Full HTML document.
	 */
	public static function wrap( $inner_html, $preheader = '' ) {
		$preheader_block = '';
		if ( '' !== (string) $preheader ) {
			$preheader_block = sprintf(
				'<div style="display:none; max-height:0; overflow:hidden; opacity:0;">%s</div>',
				esc_html( $preheader )
			);
		}

		/**
		 * Filter the footer HTML shown beneath the card.
		 *
		 * The default explains why the reader received the mail and links back
		 * to the site. Individual emails may prepend context (e.g. an
		 * unsubscribe link) to the inner HTML; this is the shared footer.
		 *
		 * @param string $footer_html The rendered footer HTML.
		 */
		$footer_html = apply_filters(
			'orbit_email_footer_html',
			sprintf(
				'%1$s<br><a href="%2$s" style="color:%3$s; text-decoration:none;">%4$s</a>',
				esc_html__( "You're receiving this because you have a Perihelion account.", 'orbit' ),
				esc_url( home_url( '/' ) ),
				esc_attr( self::COLOR_SIENNA ),
				esc_html( self::footer_domain() )
			)
		);

		return '<!DOCTYPE html>'
			. '<html lang="en"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="color-scheme" content="light">'
			. '</head>'
			. '<body style="margin:0; padding:0; background-color:' . esc_attr( self::COLOR_PAPER ) . '; -webkit-text-size-adjust:100%;">'
			. $preheader_block
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . esc_attr( self::COLOR_PAPER ) . ';">'
			. '<tr><td align="center" style="padding:32px 16px 48px;">'
			. '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; width:100%;">'
			// Wordmark.
			. '<tr><td style="padding:4px 8px 22px;">' . self::wordmark() . '</td></tr>'
			// Card.
			. '<tr><td style="background-color:' . esc_attr( self::COLOR_CARD ) . '; border:2px solid ' . esc_attr( self::COLOR_CARD_EDGE ) . '; border-radius:8px; padding:40px 40px 36px;">'
			. $inner_html
			. '</td></tr>'
			// Footer.
			. '<tr><td style="padding:22px 40px 0; font-family:' . esc_attr( self::FONT_SANS ) . '; font-size:13px; line-height:1.55; color:' . esc_attr( self::COLOR_FOOTER ) . ';">'
			. $footer_html
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body></html>';
	}

	/**
	 * The Perihelion wordmark — ONE swappable unit.
	 *
	 * #62 replaces this serif text lockup with a hosted logo `<img>`; keep the
	 * swap contained to this method so the rest of the template is untouched.
	 *
	 * @return string Wordmark HTML.
	 */
	public static function wordmark() {
		return '<span style="font-family:' . esc_attr( self::FONT_SERIF ) . '; font-size:22px; font-weight:700; color:' . esc_attr( self::COLOR_INK ) . '; letter-spacing:1px; text-transform:uppercase;">'
			. esc_html__( 'Perihelion', 'orbit' )
			. '</span>';
	}

	/**
	 * A body paragraph in Ink.
	 *
	 * Escapes `$text` and converts `\n` to `<br>` so two-line sign-offs and
	 * short multi-line notes render with their intended breaks.
	 *
	 * @param string $text Plain text (may contain "\n").
	 * @return string Paragraph HTML.
	 */
	public static function paragraph( $text ) {
		return self::paragraph_in_color( $text, self::COLOR_INK );
	}

	/**
	 * A muted paragraph in Slate — used for sign-offs.
	 *
	 * @param string $text Plain text (may contain "\n").
	 * @return string Paragraph HTML.
	 */
	public static function paragraph_muted( $text ) {
		return self::paragraph_in_color( $text, self::COLOR_SLATE );
	}

	/**
	 * Shared paragraph renderer.
	 *
	 * @param string $text  Plain text (may contain "\n").
	 * @param string $color Hex/CSS color for the text.
	 * @return string Paragraph HTML.
	 */
	private static function paragraph_in_color( $text, $color ) {
		return '<p style="margin:0 0 18px; font-family:' . esc_attr( self::FONT_SANS ) . '; font-size:16px; line-height:1.6; color:' . esc_attr( $color ) . ';">'
			. self::text_with_breaks( $text )
			. '</p>';
	}

	/**
	 * A heading — used for the activity title in notification/digest cards.
	 *
	 * @param string $text Plain text.
	 * @return string Heading HTML.
	 */
	public static function heading( $text ) {
		return '<p style="margin:0 0 10px; font-family:' . esc_attr( self::FONT_SERIF ) . '; font-size:20px; font-weight:700; line-height:1.3; color:' . esc_attr( self::COLOR_INK ) . ';">'
			. esc_html( $text )
			. '</p>';
	}

	/**
	 * An uppercase eyebrow used to head a section — e.g. a poster's name above
	 * their activities in the digest. Paired with an ink hairline above it so
	 * each poster's block reads as a distinct group.
	 *
	 * @param string $text Plain text. Escaped internally.
	 * @return string Section-label HTML.
	 */
	public static function section_label( $text ) {
		return '<p style="margin:26px 0 12px; padding-top:20px; border-top:2px solid ' . esc_attr( self::COLOR_CARD_EDGE ) . '; font-family:' . esc_attr( self::FONT_SERIF ) . '; font-size:13px; font-weight:700; line-height:1.3; letter-spacing:1px; text-transform:uppercase; color:' . esc_attr( self::COLOR_INK ) . ';">'
			. esc_html( $text )
			. '</p>';
	}

	/**
	 * A bold, tappable activity title — the whole line is the link, so the
	 * digest gives one clear tap per activity straight to its page.
	 *
	 * @param string $text Title. Escaped internally.
	 * @param string $url  Destination URL. Escaped internally.
	 * @return string Title-link HTML.
	 */
	public static function title_link( $text, $url ) {
		return '<p style="margin:0 0 3px; font-family:' . esc_attr( self::FONT_SERIF ) . '; font-size:18px; font-weight:700; line-height:1.3;">'
			. '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( self::COLOR_INK ) . '; text-decoration:none;">'
			. esc_html( $text )
			. '</a>'
			. '</p>';
	}

	/**
	 * A compact muted meta line under a title (e.g. "Musing · Sat · Cedar Park").
	 *
	 * @param string $text Plain text (may contain "\n").
	 * @return string Meta-line HTML.
	 */
	public static function meta_line( $text ) {
		return '<p style="margin:0 0 18px; font-family:' . esc_attr( self::FONT_SANS ) . '; font-size:14px; line-height:1.5; color:' . esc_attr( self::COLOR_SLATE ) . ';">'
			. self::text_with_breaks( $text )
			. '</p>';
	}

	/**
	 * A Sienna inline text link on its own line.
	 *
	 * The lightweight counterpart to `button()` — used where a full CTA button
	 * would be too heavy (e.g. one "Respond" link per activity in a digest).
	 *
	 * @param string $text Link label. Plain string; escaped internally.
	 * @param string $url  Destination URL. Escaped internally.
	 * @return string Link-paragraph HTML.
	 */
	public static function link_paragraph( $text, $url ) {
		return '<p style="margin:0 0 20px; font-family:' . esc_attr( self::FONT_SANS ) . '; font-size:16px; line-height:1.6;">'
			. '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( self::COLOR_SIENNA ) . '; font-weight:600; text-decoration:none;">'
			. esc_html( $text )
			. '</a>'
			. '</p>';
	}

	/**
	 * A bulletproof Sienna call-to-action button.
	 *
	 * Table-cell + bgcolor + rounded corners + a padded white anchor so it
	 * renders as a solid button across Gmail, Apple Mail, and Outlook.
	 *
	 * @param string $text Button label. Plain string; escaped internally.
	 * @param string $url  Destination URL. Escaped internally.
	 * @return string Button HTML.
	 */
	public static function button( $text, $url ) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 26px;">'
			. '<tr><td align="center" bgcolor="' . esc_attr( self::COLOR_SIENNA ) . '" style="border-radius:9px;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block; padding:14px 30px; font-family:' . esc_attr( self::FONT_SANS ) . '; font-size:16px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none;">'
			. esc_html( $text )
			. '</a>'
			. '</td></tr>'
			. '</table>';
	}

	/**
	 * Escape text and convert newlines to `<br>`.
	 *
	 * @param string $text Plain text (may contain "\n").
	 * @return string Escaped HTML with `<br>` line breaks.
	 */
	private static function text_with_breaks( $text ) {
		return str_replace( "\n", '<br>', esc_html( $text ) );
	}

	/**
	 * The bare host shown as the footer link label (e.g. "perihelion.social").
	 *
	 * @return string Host string, or the full home URL if the host can't be parsed.
	 */
	private static function footer_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? $host : home_url( '/' );
	}
}
