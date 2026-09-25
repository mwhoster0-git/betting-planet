<?php
/** Included by the integration suite within its rollback transaction. */
defined( 'ABSPATH' ) && function_exists( 'bpt_test_assert' ) || exit;

use BettingPlanetTips\Open_Bets;
use BettingPlanetTips\Settlement;

$ob_baseline = count( Open_Bets::tips( 100 ) );
$ob_data = array( 'home_team' => 'Test & United', 'away_team' => 'Visitors', 'stake' => '5', 'odds' => '2.50', 'bet_selection' => '0', 'match_result' => 'pending', 'match_datetime' => '2000-01-01 12:00:00', 'season' => '2000/01' );
$ob_id = $sc_create( $ob_data );
wp_update_post( array( 'ID' => $ob_id, 'post_title' => 'Open fixture <script>alert(1)</script>', 'post_content' => 'Analysis [bpt_total_tips] with content.' ) );
\BettingPlanetTips\Leagues::assign( $ob_id, 'la-liga' );
$ob_tips = Open_Bets::tips( 1 );
bpt_test_assert( 1 === count( $ob_tips ) && 'Test & United' === $ob_tips[0]['home_team'], 'Open tips chronological ordering and limit.' );
bpt_test_assert( 'Draw' === $ob_tips[0]['selection_label'] && 'La Liga' === $ob_tips[0]['league'], 'Dynamic draw and league values.' );
bpt_test_assert( false === strpos( $ob_tips[0]['analysis'], '[bpt_' ), 'Content shortcodes must not execute inside analysis.' );
$ob_html = do_shortcode( '[bpt_open_bets limit="1"]' );
bpt_test_assert( 1 === substr_count( $ob_html, 'class="bp-hand-card"' ) && false !== strpos( $ob_html, 'Test &amp; United' ) && false === strpos( $ob_html, '<script>' ), 'Open card escaping and limit.' );
bpt_test_assert( false !== strpos( $ob_html, '0 · Draw' ) && false !== strpos( $ob_html, '5 Units' ) && false !== strpos( $ob_html, '2.50' ), 'Open card betting values.' );
bpt_test_assert( false === strpos( $ob_html, 'bp-settled-badge' ), 'Open tips must take priority over settled tips.' );
$ob_second = do_shortcode( '[bpt_open_bets limit="1"]' );
preg_match( '/id="(bpt-open-bets-[^"]+)"/', $ob_html, $ob_first_id );
preg_match( '/id="(bpt-open-bets-[^"]+)"/', $ob_second, $ob_second_id );
bpt_test_assert( $ob_first_id[1] !== $ob_second_id[1], 'Deck IDs must be unique per instance.' );
bpt_test_assert( count( Open_Bets::tips( 100 ) ) === $ob_baseline + 1, 'New pending tip missing.' );
update_post_meta( $ob_id, '_bpt_match_result', '0' );
Settlement::settle( $ob_id );
bpt_test_assert( count( Open_Bets::tips( 100 ) ) === $ob_baseline, 'Settled tip still displayed.' );
update_post_meta( $ob_id, '_bpt_match_result', 'pending' );
Settlement::settle( $ob_id );
wp_update_post( array( 'ID' => $ob_id, 'post_password' => 'test' ) );
bpt_test_assert( count( Open_Bets::tips( 100 ) ) === $ob_baseline, 'Password-protected tip exposed.' );
wp_update_post( array( 'ID' => $ob_id, 'post_password' => '', 'post_status' => 'draft' ) );
bpt_test_assert( count( Open_Bets::tips( 100 ) ) === $ob_baseline, 'Draft tip exposed.' );
wp_update_post( array( 'ID' => $ob_id, 'post_status' => 'publish' ) );
delete_post_meta( $ob_id, '_bpt_stake' );
bpt_test_assert( count( Open_Bets::tips( 100 ) ) === $ob_baseline, 'Incomplete betting data displayed.' );
$ob_empty = static function ( $query ) {
	if ( 'betting_tip' === $query->get( 'post_type' ) && '_bpt_match_datetime' === $query->get( 'meta_key' ) ) {
		$query->set( 'post__in', array( -1 ) );
	}
};
add_action( 'pre_get_posts', $ob_empty );
$ob_empty_html = do_shortcode( '[bpt_open_bets]' );
remove_action( 'pre_get_posts', $ob_empty );
bpt_test_assert( '' === $ob_empty_html, 'No message when neither open nor settled tips exist.' );

$ob_settled_ids = array();
foreach ( range( 1, 5 ) as $ob_day ) {
	$ob_record = array_merge( $ob_data, array( 'match_datetime' => '2090-01-0' . $ob_day . ' 12:00:00', 'match_result' => $ob_day % 2 ? '0' : '1' ) );
	$ob_settled_ids[] = $sc_create( $ob_record );
}
$ob_only_settled = static function ( $query ) use ( $ob_settled_ids ) {
	if ( 'betting_tip' === $query->get( 'post_type' ) && '_bpt_match_datetime' === $query->get( 'meta_key' ) ) {
		$query->set( 'post__in', $ob_settled_ids );
	}
};
add_action( 'pre_get_posts', $ob_only_settled );
$ob_fallback = do_shortcode( '[bpt_open_bets limit="1"]' );
$ob_recent = Open_Bets::tips( 4, true );
remove_action( 'pre_get_posts', $ob_only_settled );
bpt_test_assert( 4 === substr_count( $ob_fallback, 'class="bp-hand-card"' ) && 4 === substr_count( $ob_fallback, 'bp-settled-badge' ), 'Fallback must show four settled cards regardless of open limit.' );
bpt_test_assert( '2090-01-05 12:00:00' === $ob_recent[0]['match_datetime'] && '2090-01-02 12:00:00' === $ob_recent[3]['match_datetime'], 'Settled fallback newest match first.' );
bpt_test_assert( false !== strpos( $ob_fallback, '+7.50 Units' ) && false !== strpos( $ob_fallback, '-5.00 Units' ) && false !== strpos( $ob_fallback, '+150.00%' ) && false !== strpos( $ob_fallback, '-100.00%' ), 'Won and lost fallback performance.' );
foreach ( $ob_settled_ids as $ob_cleanup_id ) {
	wp_delete_post( $ob_cleanup_id, true );
}
