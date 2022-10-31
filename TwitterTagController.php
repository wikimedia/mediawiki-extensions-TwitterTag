<?php

class TwitterTagController {

	private const PARSER_TAG_NAME = 'twitter';
	private const TWITTER_NAME = 'Twitter';
	private const TWITTER_BASE_URL = 'https://twitter.com/';
	private const TWITTER_USER_TIMELINE = '/^https:\/\/twitter\.com\/@?[a-z0-9_]{1,15}$/i';
	private const TWITTER_LIST_TIMELINE = '/^https:\/\/twitter\.com\/@?[a-z0-9_]{1,15}\/lists\/[^0-9]+.{0,24}$/i';
	private const TWITTER_LIKES_TIMELINE = '/^https:\/\/twitter\.com\/@?[a-z0-9_]{1,15}\/likes/i';
	private const TWITTER_TWEET = '/^https:\/\/twitter\.com\/@?[a-z0-9_]{1,15}\/status\/[0-9]*/i';

	private const DEFAULT_HEIGHT = '500';

	private const REGEX_DIGITS = '/^[0-9]*$/';
	private const REGEX_HEX_COLOR = '/#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i';
	private const REGEX_TWITTER_SCREEN_NAME = '/^@?[a-z0-9_]{1,15}$/i';
	private const REGEX_TWITTER_LIST_SLUG = '/^[^0-9]+.{0,24}$/';

	private const TAG_PERMITTED_ATTRIBUTES = [
		'widget-id' => self::REGEX_DIGITS,
		'chrome' => '/^((noheader|nofooter|noborders|noscrollbar|transparent) ?){0,5}$/i',
		'tweet-limit' => self::REGEX_DIGITS,
		'aria-polite' => '/^(off|polite|assertive)$/i',
		'related' => '/.*/',
		'lang' => '/^[a-z\-]{2,5}$/i',
		'theme' => '/^(light|dark)$/i',
		'link-color' => self::REGEX_HEX_COLOR,
		'border-color' => self::REGEX_HEX_COLOR,
		'width' => self::REGEX_DIGITS,
		'height' => self::REGEX_DIGITS,
		'show-replies' => '/^(true|false)$/i',
		'dnt' => '/^(true|false)$/i',
		'cards' => '/^(hidden)$/i',
		'conversation' => '/^(none)$/i',
		'align' => '/^(left|center|right)$/i',
		// Parameters below if used properly, may overwrite the timeline type to:
		// User and list timelines, embedded tweet
		'screen-name' => self::REGEX_TWITTER_SCREEN_NAME,
		'user-id' => self::REGEX_DIGITS,
		// List timeline
		'list-slug' => self::REGEX_TWITTER_LIST_SLUG,
		'list-id' => self::REGEX_DIGITS,
		// Embedded tweet
		'tweet-id' => self::REGEX_DIGITS,
		// Likes timeline (deprecated)
		'likes-screen-name' => self::REGEX_TWITTER_SCREEN_NAME,
	];

	/**
	 * Hooks to ParserFirstCallInit
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( self::PARSER_TAG_NAME, [ new static(), 'parseTag' ] );
	}

	/**
	 * Parses the twitter tag. Checks to ensure the required attributes are there.
	 * Then constructs the HTML after seeing which attributes are in use.
	 *
	 * @param string $input
	 * @param array $args Attributes of <twitter> tag
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public function parseTag( $input, array $args, Parser $parser, PPFrame $frame ) {
		if ( !empty( $args['href'] ) ) {
			if ( preg_match( self::TWITTER_USER_TIMELINE, $args['href'] ) || preg_match( self::TWITTER_LIST_TIMELINE, $args['href'] ) ) {
				$type = 'timeline';
				$href = $args['href'];
			} elseif ( preg_match( self::TWITTER_LIKES_TIMELINE, $args['href'] ) ) {
				$type = 'timeline';
				$href = $args['href'];
				// add tracking category for likes timelines
				$parser->addTrackingCategory( 'twitter-tag-likes-category' );
			} elseif ( preg_match( self::TWITTER_TWEET, $args['href'] ) ) {
				$type = 'tweet';
				$href = $args['href'];
			} else {
				// if href doesn't match supported types
				return '<strong class="error">' . wfMessage( 'twitter-tag-href' )->parse() . '</strong>';
			}
		} elseif ( !empty( $args['screen-name'] ) && preg_match( self::REGEX_TWITTER_SCREEN_NAME, $args['screen-name'] ) ) {
			if ( !empty( $args['tweet-id'] ) && preg_match( self::REGEX_DIGITS, $args['tweet-id'] ) ) {
				// embedded tweet
				$type = 'tweet';
				$href = self::TWITTER_BASE_URL . $args['screen-name'] . '/status/' . $args['tweet-id'];
			} elseif ( !empty( $args['list-slug'] ) && preg_match( self::REGEX_TWITTER_LIST_SLUG, $args['list-slug'] ) ) {
				// list timeline
				$type = 'timeline';
				$href = self::TWITTER_BASE_URL . $args['screen-name'] . '/lists/' . $args['list-slug'];
			} else {
				// user timeline
				$type = 'timeline';
				$href = self::TWITTER_BASE_URL . $args['screen-name'];
			}
		} elseif ( !empty( $args['likes-screen-name'] ) && preg_match( self::REGEX_TWITTER_SCREEN_NAME, $args['likes-screen-name'] ) ) {
			// likes timeline
			$type = 'timeline';
			$href = self::TWITTER_BASE_URL . $args['likes-screen-name'] . '/likes';
			// add tracking category
			$parser->addTrackingCategory( 'twitter-tag-likes-category' );
		} else {
			// if no href to user timeline check for id
			if ( empty( $args['widget-id'] ) ) {
				return '<strong class="error">' . wfMessage( 'twitter-tag-widget-id' )->parse() . '</strong>';
			}
			$href = self::TWITTER_BASE_URL;
		}

		$attributes = $this->prepareAttributes( $args, self::TAG_PERMITTED_ATTRIBUTES );

		// Twitter script is searching for twitter-timeline class
		if ( $type == 'tweet' ) {
			$attributes['class'] = 'twitter-tweet';
			$html = Html::element( 'a', [ 'href' => $href ], self::TWITTER_NAME );
			$html = Html::rawElement( 'blockquote', $attributes, $html );
		} else {
			$attributes['class'] = 'twitter-timeline';
			$attributes['href'] = $href;
			$html = Html::element( 'a', $attributes, self::TWITTER_NAME );
		}
		// Wrapper used for easily selecting the widget in Selenium tests
		$html = Html::rawElement( 'span', [ 'class' => 'widget-twitter' ], $html );

		$parser->getOutput()->addModules( [ 'ext.TwitterTag' ] );

		return $html;
	}

	/**
	 * Validates, prefixes and sanitizes the provided attributes.
	 *
	 * @param array $attributes attributes to validate
	 * @param array $permittedAttributes key-value pairs of permitted parameters and regexes which these parameters'
	 *     values have to match.
	 *
	 * @return array
	 */
	private function prepareAttributes( array $attributes, array $permittedAttributes ) {
		$validatedAttributes = [];

		// setting default values
		$validatedAttributes['data-height'] = self::DEFAULT_HEIGHT;

		foreach ( $attributes as $attributeName => $attributeValue ) {
			if (
				array_key_exists( $attributeName, $permittedAttributes ) &&
				preg_match( $permittedAttributes[$attributeName], $attributeValue )
			) {
				$validatedAttributes['data-' . $attributeName] = $attributeValue;
			}
		}

		return $validatedAttributes;
	}

}
