<?php
/** Included by integration.php inside its rollback transaction. */
defined( 'ABSPATH' ) && function_exists( 'bpt_test_assert' ) || exit;

use BettingPlanetTips\Shortcodes;
use BettingPlanetTips\Shortcode_Catalog;
use BettingPlanetTips\Performance_Board;
use BettingPlanetTips\Statistics;
use BettingPlanetTips\Settlement;

Shortcodes::register();
Shortcode_Catalog::register();
Shortcode_Catalog::install();
$sc_terms = get_terms( array( 'taxonomy' => Shortcode_Catalog::TAXONOMY, 'hide_empty' => false ) );
bpt_test_assert( ! is_wp_error( $sc_terms ) && 8 === count( $sc_terms ), 'Eight shortcode catalog terms required.' );
foreach ( $sc_terms as $sc_term ) {
	$sc_code = get_term_meta( $sc_term->term_id, '_bpt_shortcode', true );
	bpt_test_assert( shortcode_exists( trim( $sc_code, '[]' ) ), 'Catalog code is not registered.' );
}
Shortcode_Catalog::install();
bpt_test_assert( 8 === count( get_terms( array( 'taxonomy' => Shortcode_Catalog::TAXONOMY, 'hide_empty' => false ) ) ), 'Catalog initialization duplicated terms.' );
$sc_tax = get_taxonomy( Shortcode_Catalog::TAXONOMY );
bpt_test_assert( ! $sc_tax->public && ! $sc_tax->show_in_rest && false === $sc_tax->meta_box_cb, 'Catalog exposure.' );
bpt_test_assert( current_user_can( $sc_tax->cap->manage_terms ) && ! current_user_can( $sc_tax->cap->edit_terms ), 'Catalog management permissions.' );
$sc_column = Shortcode_Catalog::column( '', 'bpt_code', $sc_terms[0]->term_id );
bpt_test_assert( false !== strpos( $sc_column, 'readonly' ) && false !== strpos( $sc_column, '[bpt_' ), 'Catalog must display copyable code.' );

$sc_win = array( 'stake' => '1', 'odds' => '3.00', 'bet_selection' => '1', 'match_result' => '1', 'bet_status' => 'won' );
$sc_loss = array( 'stake' => '9', 'odds' => '2.00', 'bet_selection' => '1', 'match_result' => '0', 'bet_status' => 'lost' );
$sc_pending = array_merge( $sc_win, array( 'match_result' => 'pending', 'bet_status' => 'pending' ) );
$sc_summary = Statistics::summarize( array( $sc_win, $sc_loss, $sc_pending ) );
bpt_test_assert( array( 'tips' => 3, 'settled' => 2, 'wins' => 1, 'stakes' => 1000, 'returns' => 300, 'profit' => -700 ) === $sc_summary, 'Integer totals/settled-only accounting.' );
bpt_test_assert( '-70.00%' === Statistics::percentage( $sc_summary['profit'], $sc_summary['stakes'] ), 'Yield must be weighted by stakes, not average individual yields (50%).' );
bpt_test_assert( '50.00%' === Statistics::percentage( $sc_summary['wins'], $sc_summary['settled'] ), 'Win ratio must exclude pending tips.' );
bpt_test_assert( '8.00%' === Statistics::percentage( 8000, 100000 ), '1000 staked, 1080 returned must yield 8%.' );
bpt_test_assert( '0.00%' === Statistics::percentage( 0, 0 ), 'Empty denominator display.' );
bpt_test_assert( 0 === Statistics::summarize( array() )['tips'], 'Empty collection.' );
bpt_test_assert( 0 === Statistics::summarize( array( array_merge( $sc_win, array( 'stake' => '11' ) ) ) )['settled'], 'Invalid input counted as settled.' );
bpt_test_assert( 0 === Statistics::summarize( array( array_merge( $sc_win, array( 'bet_status' => 'pending' ) ) ) )['settled'], 'Unsettled status counted as won.' );

$sc_base = Statistics::totals();
$sc_create = static function ( array $record, $status = 'publish', $password = '' ) {
	$meta = array( '_bpt_home_team' => 'Home & United', '_bpt_away_team' => 'Away Town', '_bpt_match_datetime' => '2030-09-12 20:00:00' );
	foreach ( $record as $field => $value ) {
		$meta[ '_bpt_' . $field ] = $value;
	}
	$id = wp_insert_post( array( 'post_type' => 'betting_tip', 'post_status' => $status, 'post_title' => 'BPT shortcode integration fixture', 'post_password' => $password, 'meta_input' => $meta ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return $id;
};
$sc_win_id = $sc_create( $sc_win );
$sc_loss_id = $sc_create( $sc_loss );
$sc_pending_id = $sc_create( $sc_pending );
$sc_draft_id = $sc_create( $sc_win, 'draft' );
$sc_private_id = $sc_create( $sc_win, 'private' );
$sc_password_id = $sc_create( $sc_win, 'publish', 'testing-password' );
$sc_totals = Statistics::totals();
bpt_test_assert( $sc_base['tips'] + 3 === $sc_totals['tips'] && $sc_base['settled'] + 2 === $sc_totals['settled'], 'Public query included hidden tips or dropped pending tips.' );
bpt_test_assert( $sc_base['profit'] - 700 === $sc_totals['profit'] && $sc_base['stakes'] + 1000 === $sc_totals['stakes'], 'Published query totals.' );
bpt_test_assert( (string) $sc_totals['tips'] === do_shortcode( '[bpt_total_tips]' ), 'Total shortcode.' );
bpt_test_assert( (string) $sc_totals['wins'] === do_shortcode( '[bpt_total_wins]' ), 'Total wins shortcode.' );
bpt_test_assert( (string) ( $sc_totals['settled'] - $sc_totals['wins'] ) === do_shortcode( '[bpt_total_losses]' ), 'Total losses shortcode.' );
bpt_test_assert( Statistics::percentage( $sc_totals['profit'], $sc_totals['stakes'] ) === do_shortcode( '[bpt_yield]' ), 'Yield shortcode.' );
bpt_test_assert( Statistics::percentage( $sc_totals['wins'], $sc_totals['settled'] ) === do_shortcode( '[bpt_win_ratio]' ), 'Win ratio shortcode.' );
bpt_test_assert( ( $sc_totals['profit'] > 0 ? '+' : '' ) . Settlement::decimal( $sc_totals['profit'] ) . ' Units' === do_shortcode( '[bpt_profit]' ), 'Profit shortcode formatting.' );
bpt_test_assert( ! shortcode_exists( 'bpt_team_a' ) && ! shortcode_exists( 'bpt_team_b' ), 'Team shortcodes must be removed.' );
$board_rows = Performance_Board::rows( 10 );
bpt_test_assert( count( $board_rows ) >= 2, 'Performance board rows missing settled tips.' );
$board_html = do_shortcode( '[bpt_performance_board limit="1"]' );
bpt_test_assert( false !== strpos( $board_html, 'class="perf-board"' ) && false !== strpos( $board_html, 'class="perf-board-scroll"' ) && ( false !== strpos( $board_html, 'GEWONNEN' ) || false !== strpos( $board_html, 'VERLOREN' ) ), 'Performance board shortcode rendering.' );
bpt_test_assert( 1 === substr_count( $board_html, '<tr>' ) - 1, 'Performance board limit failed.' );
bpt_test_assert( false !== strpos( $board_html, 'Home &amp; United - Away Town' ), 'Performance board escaping failed.' );
$sc_query_count = $wpdb->num_queries;
do_shortcode( '[bpt_total_tips] [bpt_total_wins] [bpt_total_losses] [bpt_profit] [bpt_yield] [bpt_win_ratio]' );
bpt_test_assert( $sc_query_count === $wpdb->num_queries, 'Aggregate shortcodes should reuse one request snapshot.' );
update_post_meta( $sc_loss_id, '_bpt_match_result', '1' );
Settlement::settle( $sc_loss_id );
bpt_test_assert( $sc_totals['profit'] + 1800 === Statistics::totals()['profit'], 'Result change must invalidate statistics.' );
wp_update_post( array( 'ID' => $sc_pending_id, 'post_status' => 'draft' ) );
bpt_test_assert( $sc_totals['tips'] - 1 === Statistics::totals()['tips'], 'Unpublishing must invalidate statistics.' );
delete_post_meta( $sc_win_id, '_bpt_stake' );
bpt_test_assert( $sc_base['settled'] + 1 === Statistics::totals()['settled'], 'Removed stake must exclude incomplete tip.' );
// Leave fixtures private to this transaction and restore a clean request snapshot.
Statistics::invalidate();
require __DIR__ . '/open-bets.php';
