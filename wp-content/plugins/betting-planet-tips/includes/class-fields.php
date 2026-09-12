<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Fields {
	/** League choices come exclusively from the native taxonomy. */
	public static function leagues() {
		return Leagues::choices();
	}

	public static function selections() {
		return array( '1' => __( 'Home Win', 'betting-planet-tips' ), '0' => __( 'Draw', 'betting-planet-tips' ), '2' => __( 'Away Win', 'betting-planet-tips' ) );
	}

	public static function schema() {
		return array(
			'league'         => array( 'label' => __( 'League', 'betting-planet-tips' ), 'group' => 'match', 'type' => 'select', 'choices' => self::leagues() ),
			'season'         => array( 'label' => __( 'Season', 'betting-planet-tips' ), 'group' => 'match', 'type' => 'text' ),
			'match_datetime' => array( 'label' => __( 'Match Date & Time', 'betting-planet-tips' ), 'group' => 'match', 'type' => 'datetime-local' ),
			'home_team'      => array( 'label' => __( 'Home Team', 'betting-planet-tips' ), 'group' => 'match', 'type' => 'text' ),
			'away_team'      => array( 'label' => __( 'Away Team', 'betting-planet-tips' ), 'group' => 'match', 'type' => 'text' ),
			'bet_selection'  => array( 'label' => __( 'Bet Selection', 'betting-planet-tips' ), 'group' => 'bet', 'type' => 'select', 'choices' => self::selections() ),
			'stake'          => array( 'label' => __( 'Stake', 'betting-planet-tips' ), 'group' => 'bet', 'type' => 'select', 'choices' => array_combine( range( 1, 10 ), range( 1, 10 ) ) ),
			'odds'           => array( 'label' => __( 'Odds', 'betting-planet-tips' ), 'group' => 'bet', 'type' => 'number' ),
			'match_result'   => array( 'label' => __( 'Match Result', 'betting-planet-tips' ), 'group' => 'result', 'type' => 'select', 'choices' => array( 'pending' => __( 'Pending', 'betting-planet-tips' ) ) + self::selections() ),
		);
	}

	public static function register() {
		foreach ( array_merge( array_keys( self::schema() ), array( 'bet_status', 'return', 'profit', 'yield' ) ) as $field ) {
			if ( 'league' === $field ) {
				continue;
			}
			register_post_meta( 'betting_tip', '_bpt_' . $field, array(
				'type' => 'string', 'single' => true, 'show_in_rest' => false,
				'auth_callback' => static function ( $allowed, $key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			) );
		}
	}

	/** Returns canonical text, or WP_Error; blank inputs mean delete metadata. */
	public static function validate( $field, $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return new \WP_Error( 'bpt_invalid', __( 'Invalid field value.', 'betting-planet-tips' ) );
		}
		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return 'match_result' === $field ? 'pending' : '';
		}
		switch ( $field ) {
			case 'stake':
				if ( ! preg_match( '/\A(?:[1-9]|10)\z/', $value ) ) {
					return new \WP_Error( 'bpt_stake', __( 'Stake must be a whole number from 1 to 10 Units.', 'betting-planet-tips' ) );
				}
				return $value;
			case 'odds':
				// Strict decimal notation; bounded to keep arithmetic safe on 32-bit PHP too.
				if ( ! preg_match( '/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/', $value ) ) {
					return new \WP_Error( 'bpt_odds', __( 'Use decimal odds with at most two decimal places (for example 2.50).', 'betting-planet-tips' ) );
				}
				$parts = explode( '.', $value );
				$cents = (int) $parts[0] * 100 + (int) str_pad( $parts[1] ?? '', 2, '0' );
				if ( $cents <= 100 || $cents > 10000000 ) {
					return new \WP_Error( 'bpt_odds_range', __( 'Odds must be greater than 1.00 and at most 100000.00.', 'betting-planet-tips' ) );
				}
				return Settlement::decimal( $cents );
			case 'bet_selection':
			case 'match_result':
				$allowed = 'match_result' === $field ? array( 'pending', '1', '0', '2' ) : array( '1', '0', '2' );
				if ( ! in_array( $value, $allowed, true ) ) {
					return new \WP_Error( 'bpt_selection', __( 'Choose a valid match outcome.', 'betting-planet-tips' ) );
				}
				return $value;
			case 'league':
				return array_key_exists( $value, self::leagues() ) ? $value : new \WP_Error( 'bpt_league', __( 'Choose a listed league.', 'betting-planet-tips' ) );
			case 'match_datetime':
				$value = str_replace( 'T', ' ', $value );
				if ( 16 === strlen( $value ) ) {
					$value .= ':00';
				}
				if ( ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value ) ) {
					return new \WP_Error( 'bpt_date', __( 'Enter a valid match date and time.', 'betting-planet-tips' ) );
				}
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, wp_timezone() );
				if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
					return new \WP_Error( 'bpt_date', __( 'Enter a valid match date and time.', 'betting-planet-tips' ) );
				}
				return $value;
			default:
				return sanitize_text_field( $value );
		}
	}

	/** Native API guard: reject invalid or noncanonical nonempty input values. */
	public static function guard( $check, $post_id, $key, $value, $unused ) {
		if ( null !== $check || 'betting_tip' !== get_post_type( $post_id ) || 0 !== strpos( $key, '_bpt_' ) ) {
			return $check;
		}
		$field = substr( $key, 5 );
		if ( 'league' === $field ) {
			return false; // League relationships now belong to the taxonomy, not post meta.
		}
		if ( ! isset( self::schema()[ $field ] ) ) {
			return $check;
		}
		$valid = self::validate( $field, $value );
		return is_wp_error( $valid ) || '' === $valid || $valid !== (string) $value ? false : $check;
	}
}
