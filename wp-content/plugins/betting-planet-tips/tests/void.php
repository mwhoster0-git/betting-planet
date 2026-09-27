<?php
/** Included within the integration suite's rollback transaction. */
defined( 'ABSPATH' ) && function_exists( 'bpt_test_assert' ) || exit;

use BettingPlanetTips\Fields;
use BettingPlanetTips\Settlement;
use BettingPlanetTips\Statistics;
use BettingPlanetTips\Import_Export;

bpt_test_assert( 'void' === Fields::validate( 'match_result', 'void' ) && is_wp_error( Fields::validate( 'bet_selection', 'void' ) ), 'Void is only a match result.' );
$void_record = array_merge( $sc_win, array( 'match_result' => 'void', 'bet_status' => 'void' ) );
bpt_test_assert( array( 'bet_status' => 'void', 'return' => '1.00', 'profit' => '0.00', 'yield' => '0.00' ) === Settlement::calculate( $void_record ), 'Void must refund exactly the stake.' );
$void_totals = Statistics::summarize( array( $sc_win, $sc_loss, $sc_pending, $void_record ) );
bpt_test_assert( 1 === $void_totals['void'] && 2 === $void_totals['settled'] && 1 === $void_totals['wins'] && 1000 === $void_totals['stakes'] && 300 === $void_totals['returns'] && -700 === $void_totals['profit'], 'Void must leave all performance totals unchanged.' );

$void_id = $sc_create( $void_record );
update_post_meta( $void_id, Import_Export::UID_META, 'bpt-void-test-' . $void_id );
$void_only = static function ( $query ) use ( $void_id ) {
	if ( 'betting_tip' === $query->get( 'post_type' ) ) {
		$query->set( 'post__in', array( $void_id ) );
	}
};
add_action( 'pre_get_posts', $void_only );
Statistics::invalidate();
bpt_test_assert( '1' === do_shortcode( '[bpt_total_tips]' ) && '0' === do_shortcode( '[bpt_total_wins]' ) && '0' === do_shortcode( '[bpt_total_losses]' ), 'Void counts as a completed tip, never a win or loss.' );
bpt_test_assert( '0.00%' === do_shortcode( '[bpt_yield]' ) && '0.00%' === do_shortcode( '[bpt_win_ratio]' ) && '0.00 Units' === do_shortcode( '[bpt_profit]' ), 'Only-void history must have neutral performance.' );
$void_cards = do_shortcode( '[bpt_open_bets]' );
bpt_test_assert( false !== strpos( $void_cards, '>Void<' ) && false !== strpos( $void_cards, 'bp-settled-badge' ) && false !== strpos( $void_cards, 'stake returned' ), 'Void card must display as cancelled and settled.' );
$void_board = do_shortcode( '[bpt_performance_board]' );
bpt_test_assert( false !== strpos( $void_board, '>VOID<' ) && false !== strpos( $void_board, 'num neutral' ) && false !== strpos( $void_board, '>0%</td>' ), 'Void performance row must be neutral with unsigned zero yield.' );
$void_export = Import_Export::export_data();
$void_import = Import_Export::import_data( $void_export );
bpt_test_assert( ! is_wp_error( $void_import ) && 1 === $void_import['updated'] && 'void' === get_post_meta( $void_id, '_bpt_bet_status', true ) && '1.00' === get_post_meta( $void_id, '_bpt_return', true ), 'Void import/export must preserve cancellation and refund.' );
update_post_meta( $void_id, '_bpt_match_result', '1' );
Settlement::settle( $void_id );
bpt_test_assert( 'won' === get_post_meta( $void_id, '_bpt_bet_status', true ) && '2.00' === get_post_meta( $void_id, '_bpt_profit', true ) && '1' === do_shortcode( '[bpt_total_wins]' ), 'Correcting void must recalculate and invalidate statistics.' );
remove_action( 'pre_get_posts', $void_only );
wp_delete_post( $void_id, true );
Statistics::invalidate();
