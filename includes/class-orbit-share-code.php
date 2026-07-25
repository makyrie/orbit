<?php
/**
 * Memorable share codes — the human-friendly invite identifier.
 *
 * A profile's invite link is /hi/<code> where <code> is an adjective-color-noun
 * triple, e.g. "wiggly-orange-otter": easy to say out loud, write down, or type.
 *
 * Security note: the code is a capability, but a soft one. It only reaches the
 * *request* form (never a person's whereabouts), the /hi/ lookup is
 * rate-limited to defeat enumeration, and the poster's approval is the real
 * gate for anything sensitive. The word lists are curated to be positive and
 * unambiguous so no combination reads as unkind; codes are rerollable if one
 * ever feels off. Entropy is ~adjectives x colors x nouns; a fourth word can
 * be added later by extending generate() if more headroom is wanted.
 *
 * @package Orbit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates and validates memorable share codes.
 */
class Orbit_Share_Code {

	/**
	 * Curated "cute" descriptors — gentle, positive, unambiguous.
	 *
	 * @var string[]
	 */
	private static $adjectives = array(
		'wiggly', 'sunny', 'brave', 'fuzzy', 'merry', 'snug', 'bouncy', 'gentle',
		'cheery', 'plucky', 'dapper', 'jolly', 'cozy', 'nimble', 'chirpy', 'breezy',
		'peppy', 'mellow', 'spry', 'giddy', 'perky', 'chipper', 'zippy', 'dandy',
		'bubbly', 'sprightly', 'jaunty', 'lively', 'chummy', 'comfy', 'twirly', 'swirly',
		'humble', 'kindly', 'sleepy', 'dreamy', 'starry', 'misty', 'frosty', 'toasty',
		'velvet', 'pillowy', 'downy', 'wispy', 'silky', 'glowy', 'shiny', 'twinkly',
		'rosy', 'peachy', 'minty', 'lucky', 'jazzy', 'snappy', 'zesty', 'nifty',
		'quirky', 'wobbly', 'squishy', 'noodly', 'floaty', 'drifty', 'roamy', 'wandery',
		'curly', 'puffy', 'fluffy', 'tufty', 'scruffy', 'shaggy', 'stubby', 'chubby',
		'teeny', 'titchy', 'mini', 'wee', 'grand', 'lofty', 'sturdy', 'trusty',
		'valiant', 'noble', 'bonny', 'winsome', 'plush', 'cuddly', 'huggy', 'snuggly',
		'polite', 'chatty', 'giggly', 'smiley', 'winky', 'nodding', 'humming', 'skippy',
	);

	/**
	 * Friendly colors.
	 *
	 * @var string[]
	 */
	private static $colors = array(
		'orange', 'coral', 'peach', 'apricot', 'amber', 'honey', 'lemon', 'butter',
		'lime', 'mint', 'sage', 'olive', 'teal', 'aqua', 'sky', 'azure',
		'cobalt', 'indigo', 'violet', 'plum', 'grape', 'orchid', 'rose', 'pink',
		'ruby', 'cherry', 'cocoa', 'hazel', 'sandy', 'cream', 'pearl', 'silver',
	);

	/**
	 * Cute nouns — mostly animals and gentle objects.
	 *
	 * @var string[]
	 */
	private static $nouns = array(
		'otter', 'unicorn', 'penguin', 'panda', 'koala', 'bunny', 'kitten', 'puppy',
		'duckling', 'gosling', 'cygnet', 'fawn', 'piglet', 'lamb', 'hedgehog', 'chipmunk',
		'squirrel', 'dormouse', 'hamster', 'gerbil', 'ferret', 'sloth', 'lemur', 'meerkat',
		'quokka', 'wombat', 'platypus', 'axolotl', 'narwhal', 'dolphin', 'seal', 'walrus',
		'pufferfish', 'seahorse', 'starfish', 'jellyfish', 'octopus', 'cuttlefish', 'newt', 'toadlet',
		'ladybug', 'firefly', 'bumblebee', 'butterfly', 'dragonfly', 'cricket', 'snail', 'caterpillar',
		'robin', 'sparrow', 'wren', 'finch', 'chickadee', 'puffin', 'toucan', 'kingfisher',
		'owlet', 'ducky', 'goose', 'swan', 'heron', 'flamingo', 'peacock', 'hummingbird',
		'acorn', 'pinecone', 'clover', 'daisy', 'tulip', 'poppy', 'pebble', 'seashell',
		'muffin', 'crumpet', 'biscuit', 'dumpling', 'noodle', 'pretzel', 'waffle', 'pancake',
		'teapot', 'mitten', 'button', 'ribbon', 'lantern', 'balloon', 'kazoo', 'ukulele',
		'comet', 'planet', 'stardust', 'moonbeam', 'raindrop', 'snowflake', 'sunbeam', 'cloudlet',
	);

	/**
	 * Generate a unique memorable code, retrying on the (rare) collision.
	 *
	 * @param callable|null $exists Optional predicate ( string $code ): bool that
	 *                              returns true when the code is already taken.
	 *                              Defaults to a profiles-table lookup.
	 * @return string The code, e.g. "wiggly-orange-otter".
	 */
	public static function generate( $exists = null ) {
		if ( null === $exists ) {
			$exists = array( __CLASS__, 'code_exists' );
		}

		// Bounded retries; the keyspace is far larger than the row count, so a
		// handful of tries is plenty. As a last resort, append a short random
		// suffix to guarantee termination.
		for ( $i = 0; $i < 20; $i++ ) {
			$code = self::pick( self::$adjectives ) . '-'
				. self::pick( self::$colors ) . '-'
				. self::pick( self::$nouns );

			if ( ! call_user_func( $exists, $code ) ) {
				return $code;
			}
		}

		return $code . '-' . substr( Orbit_Token::generate_random(), 0, 4 );
	}

	/**
	 * Whether a share code is already used by a profile.
	 *
	 * @param string $code Candidate code.
	 * @return bool
	 */
	public static function code_exists( $code ) {
		global $wpdb;
		$table = $wpdb->prefix . ORBIT_TABLE_PROFILES;

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE share_code = %s", $code )
		);
	}

	/**
	 * Pick a random element without relying on mt_rand seeding quirks.
	 *
	 * @param string[] $list Non-empty list.
	 * @return string
	 */
	private static function pick( $list ) {
		return $list[ wp_rand( 0, count( $list ) - 1 ) ];
	}
}
