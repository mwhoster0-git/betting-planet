<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	/** The same definitions drive WordPress registration and the taxonomy library. */
	public static function definitions() {
		return array(
			'bpt_total_open_bets' => array( 'name' => __( 'Number of Open Bets', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected pending betting tips.', 'betting-planet-tips' ) ),
			'bpt_overall_total_tips' => array( 'name' => __( 'Overall Total Tips', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected tips: won + lost + Storno + pending.', 'betting-planet-tips' ) ),
			'bpt_open_bets' => array( 'name' => __( 'Open bets display', 'betting-planet-tips' ), 'description' => __( 'Swipeable card deck of published, valid pending tips. Use [bpt_open_bets] or [bpt_open_bets limit="10"]. Touch swipe, mouse drag, arrow keys and navigation buttons are supported.', 'betting-planet-tips' ) ),
			'bpt_performance_board' => array( 'name' => __( 'Performance Board', 'betting-planet-tips' ), 'description' => __( 'Responsive table of published settled tips. Use [bpt_performance_board] or [bpt_performance_board limit="10"]. Pending and incomplete tips are excluded.', 'betting-planet-tips' ) ),
			'bpt_total_tips' => array( 'name' => __( 'Total Number of Evaluated Tips', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected betting tips, excluding pending tips.', 'betting-planet-tips' ) ),
			'bpt_total_wins' => array( 'name' => __( 'Total Number of Won Bets', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected settled tips calculated as won.', 'betting-planet-tips' ) ),
			'bpt_total_losses' => array( 'name' => __( 'Total Number of Lost Bets', 'betting-planet-tips' ), 'description' => __( 'Number of published, non-password-protected settled tips calculated as lost.', 'betting-planet-tips' ) ),
			'bpt_yield' => array( 'name' => __( 'Yield Percentage', 'betting-planet-tips' ), 'description' => __( 'Overall yield: total profit divided by total stakes × 100. Uses won/lost public tips, excluding void stakes and refunds; never averages individual yields.', 'betting-planet-tips' ) ),
			'bpt_win_ratio' => array( 'name' => __( 'Win Ratio', 'betting-planet-tips' ), 'description' => __( 'Winning tips divided by won/lost tips × 100. Pending, incomplete and void tips are excluded.', 'betting-planet-tips' ) ),
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
		if ( 'bpt_performance_board' === $tag ) {
			return Performance_Board::render( $attributes );
		}
		if ( ! isset( self::definitions()[ $tag ] ) ) {
			return '';
		}
		$totals = Statistics::totals();
		switch ( $tag ) {
			case 'bpt_total_open_bets':
				$output = (string) $totals['pending'];
				break;
			case 'bpt_overall_total_tips':
				$output = (string) ( $totals['settled'] + $totals['void'] + $totals['pending'] );
				break;
			case 'bpt_total_tips':
				$output = (string) ( $totals['settled'] + $totals['void'] );
				break;
			case 'bpt_total_wins':
				$output = (string) $totals['wins'];
				break;
			case 'bpt_total_losses':
				$output = (string) ( $totals['settled'] - $totals['wins'] );
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
