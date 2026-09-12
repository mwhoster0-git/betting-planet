<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	/** The same definitions drive WordPress registration and the taxonomy library. */
	public static function definitions() {
		return array(
			'bpt_open_bets' => array( 'name' => __( 'Open bets display', 'betting-planet-tips' ), 'description' => __( 'Swipeable card deck of published, valid pending tips. Use [bpt_open_bets] or [bpt_open_bets limit="10"]. Touch swipe, mouse drag, arrow keys and navigation buttons are supported.', 'betting-planet-tips' ) ),
			'bpt_total_tips' => array( 'name' => __( 'Total Number of Tips', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected betting tips, including pending tips.', 'betting-planet-tips' ) ),
			'bpt_yield' => array( 'name' => __( 'Yield Percentage', 'betting-planet-tips' ), 'description' => __( 'Overall yield: total profit divided by total stakes × 100. Uses settled public tips; never averages individual yields.', 'betting-planet-tips' ) ),
			'bpt_win_ratio' => array( 'name' => __( 'Win Ratio', 'betting-planet-tips' ), 'description' => __( 'Winning tips divided by settled tips × 100. Pending and incomplete tips are excluded.', 'betting-planet-tips' ) ),
			'bpt_profit' => array( 'name' => __( 'Profit', 'betting-planet-tips' ), 'description' => __( 'Total returns minus total stakes for settled public tips, displayed in Units.', 'betting-planet-tips' ) ),
		);
	}

	public static function register() {
		foreach ( self::definitions() as $tag => $definition ) {
			add_shortcode( $tag, array( self::class, 'render' ) );
		}
	}

	public static function render( $attributes, $content = null, $tag = '' ) {
		if ( 'bpt_open_bets' === $tag ) {
			return Open_Bets::render( $attributes );
		}
		if ( ! isset( self::definitions()[ $tag ] ) ) {
			return '';
		}
		$totals = Statistics::totals();
		switch ( $tag ) {
			case 'bpt_total_tips':
				$output = (string) $totals['tips'];
				break;
			case 'bpt_yield':
				$output = Statistics::percentage( $totals['profit'], $totals['stakes'] );
				break;
			case 'bpt_win_ratio':
				$output = Statistics::percentage( $totals['wins'], $totals['settled'] );
				break;
			case 'bpt_profit':
				$output = ( $totals['profit'] > 0 ? '+' : '' ) . Settlement::decimal( $totals['profit'] ) . ' ' . __( 'Units', 'betting-planet-tips' );
				break;
			default:
				return '';
		}
		return esc_html( $output );
	}

}
