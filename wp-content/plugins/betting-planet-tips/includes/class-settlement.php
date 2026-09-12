<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Settlement {
	/** Format signed integer hundredths without floating-point arithmetic. */
	public static function decimal( $hundredths ) {
		$absolute = abs( $hundredths );
		return ( $hundredths < 0 ? '-' : '' ) . intdiv( $absolute, 100 ) . '.' . str_pad( (string) ( $absolute % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** Pure reusable calculation; pending/incomplete bets have no final amounts. */
	public static function calculate( array $input ) {
		$pending = array( 'bet_status' => 'pending', 'return' => null, 'profit' => null, 'yield' => null );
		$data    = array();
		foreach ( array( 'stake', 'odds', 'bet_selection', 'match_result' ) as $field ) {
			$data[ $field ] = Fields::validate( $field, $input[ $field ] ?? '' );
			if ( is_wp_error( $data[ $field ] ) || '' === $data[ $field ] ) {
				return $pending;
			}
		}
		if ( 'pending' === $data['match_result'] ) {
			return $pending;
		}
		$stake = (int) $data['stake'];
		if ( $data['bet_selection'] !== $data['match_result'] ) {
			return array( 'bet_status' => 'lost', 'return' => '0.00', 'profit' => self::decimal( -$stake * 100 ), 'yield' => '-100.00' );
		}
		$odds = (int) str_replace( '.', '', $data['odds'] );
		return array(
			'bet_status' => 'won',
			'return'     => self::decimal( $stake * $odds ),
			'profit'     => self::decimal( $stake * ( $odds - 100 ) ),
			// (Profit / Stake) * 100; cancellation avoids intermediate overflow.
			'yield'      => self::decimal( ( $odds - 100 ) * 100 ),
		);
	}

	public static function settle( $post_id ) {
		if ( 'betting_tip' !== get_post_type( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$data = array();
		foreach ( array( 'stake', 'odds', 'bet_selection', 'match_result' ) as $field ) {
			$data[ $field ] = get_post_meta( $post_id, '_bpt_' . $field, true );
		}
		foreach ( self::calculate( $data ) as $field => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, '_bpt_' . $field );
			} else {
				update_post_meta( $post_id, '_bpt_' . $field, $value );
			}
		}
		// No wp_update_post(): metadata writes cannot recurse into save_post.
	}
}
