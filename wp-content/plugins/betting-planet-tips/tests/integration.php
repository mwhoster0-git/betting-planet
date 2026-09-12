<?php
/** Run explicitly with PHP CLI against a disposable/local WordPress database. */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once dirname( __DIR__ ) . '/betting-planet-tips.php';

use BettingPlanetTips\Admin;
use BettingPlanetTips\Fields;
use BettingPlanetTips\Leagues;
use BettingPlanetTips\Meta_Boxes;
use BettingPlanetTips\Post_Type;
use BettingPlanetTips\Settlement;

$checks = 0;
function bpt_test_assert( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

set_error_handler( static function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
	return false;
} );

global $wpdb;
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
	throw new RuntimeException( 'A local administrator account is required.' );
}

// All database mutations, including activation options and rewrite rules, roll back.
foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta ) as $table ) {
	$info = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
	if ( ! $info || 'InnoDB' !== $info->Engine ) {
		throw new RuntimeException( 'Tests require transactional InnoDB tables.' );
	}
}
$wpdb->query( 'START TRANSACTION' );
try {
	wp_set_current_user( $admins[0]->ID );
	$result = activate_plugin( 'betting-planet-tips/betting-planet-tips.php' );
	bpt_test_assert( ! is_wp_error( $result ), 'Plugin activation failed.' );
	Post_Type::register();
	Leagues::register();
	Leagues::upgrade();
	Fields::register();
	$type = get_post_type_object( 'betting_tip' );
	bpt_test_assert( 'betting-tips' === $type->rewrite['slug'] && ! $type->show_in_rest, 'CPT configuration.' );
	foreach ( array( 'title', 'editor', 'thumbnail', 'author', 'revisions' ) as $support ) {
		bpt_test_assert( post_type_supports( 'betting_tip', $support ), 'Missing support: ' . $support );
	}
	bpt_test_assert( current_theme_supports( 'post-thumbnails' ), 'Active theme must support featured images.' );
	foreach ( array( '-1', '0', '11', '1.5', '1e1', '01', array( 5 ) ) as $invalid ) {
		bpt_test_assert( is_wp_error( Fields::validate( 'stake', $invalid ) ), 'Invalid stake accepted.' );
	}
	foreach ( range( 1, 10 ) as $stake ) {
		bpt_test_assert( (string) $stake === Fields::validate( 'stake', (string) $stake ), 'Valid stake rejected.' );
	}
	foreach ( array( '-2', '0', '1', '1.00', 'NaN', 'INF', '2e2', '2,50', '2.500', '2.5junk', '100000.01', array( '2' ) ) as $invalid ) {
		bpt_test_assert( is_wp_error( Fields::validate( 'odds', $invalid ) ), 'Invalid odds accepted.' );
	}
	bpt_test_assert( '2.50' === Fields::validate( 'odds', '2.5' ), 'Odds normalization.' );
	bpt_test_assert( '2026-09-10 14:30:00' === Fields::validate( 'match_datetime', '2026-09-10T14:30' ), 'Date normalization.' );
	bpt_test_assert( is_wp_error( Fields::validate( 'match_datetime', '2026-02-30T14:30' ) ), 'Invalid calendar date accepted.' );
	bpt_test_assert( is_wp_error( Fields::validate( 'match_datetime', "2026-09-10T14:\00030" ) ), 'Malformed date must fail safely.' );
	foreach ( array( 'bet_selection', 'match_result' ) as $field ) {
		bpt_test_assert( is_wp_error( Fields::validate( $field, '3' ) ), 'Invalid outcome accepted.' );
		bpt_test_assert( '0' === Fields::validate( $field, '0' ), 'Draw lost through empty-value handling.' );
	}
	foreach ( array( '1', '0', '2' ) as $selection ) {
		foreach ( array( '1', '0', '2' ) as $result ) {
			$actual = Settlement::calculate( array( 'stake' => '5', 'odds' => '2.50', 'bet_selection' => $selection, 'match_result' => $result ) );
			$expected = $selection === $result
				? array( 'bet_status' => 'won', 'return' => '12.50', 'profit' => '7.50', 'yield' => '150.00' )
				: array( 'bet_status' => 'lost', 'return' => '0.00', 'profit' => '-5.00', 'yield' => '-100.00' );
			bpt_test_assert( $expected === $actual, 'Settlement outcome matrix.' );
		}
	}
	$max = Settlement::calculate( array( 'stake' => '10', 'odds' => '100000.00', 'bet_selection' => '1', 'match_result' => '1' ) );
	bpt_test_assert( '1000000.00' === $max['return'] && '9999900.00' === $max['yield'], 'Maximum arithmetic bounds.' );
	$post_id = wp_insert_post( array( 'post_type' => 'betting_tip', 'post_title' => 'BPT temporary integration test', 'post_status' => 'draft' ), true );
	bpt_test_assert( ! is_wp_error( $post_id ), 'Test post creation.' );
	$input = array( 'league' => 'premier-league', 'season' => '2026/27', 'match_datetime' => '2026-09-10T14:30', 'home_team' => '<b>Arsenal</b>', 'away_team' => "Chelsea's XI", 'bet_selection' => '0', 'stake' => '5', 'odds' => '2.5', 'match_result' => '0' );
	$_POST = array( 'bpt_nonce' => wp_create_nonce( 'bpt_save_' . $post_id ), 'bpt' => wp_slash( $input ) );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'BPT verified save' ) );
	bpt_test_assert( 'won' === get_post_meta( $post_id, '_bpt_bet_status', true ), 'Real save_post settlement.' );
	bpt_test_assert( '7.50' === get_post_meta( $post_id, '_bpt_profit', true ), 'Stored exact profit.' );
	bpt_test_assert( '0' === get_post_meta( $post_id, '_bpt_bet_selection', true ), 'Stored draw code.' );
	bpt_test_assert( 'Arsenal' === get_post_meta( $post_id, '_bpt_home_team', true ), 'Text sanitization.' );
	bpt_test_assert( 'premier-league' === Leagues::selected( $post_id ) && ! metadata_exists( 'post', $post_id, '_bpt_league' ), 'League must use taxonomy relationships.' );
	bpt_test_assert( "Chelsea's XI" === get_post_meta( $post_id, '_bpt_away_team', true ), 'Text slashing.' );
	$_POST['bpt']['stake'] = '11';
	$_POST['bpt']['match_result'] = '2';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( '5' === get_post_meta( $post_id, '_bpt_stake', true ) && '0' === get_post_meta( $post_id, '_bpt_match_result', true ), 'Invalid form must preserve every betting field.' );
	bpt_test_assert( is_array( get_transient( Admin::notice_key( $post_id ) ) ), 'Validation notice missing.' );
	foreach ( array( '-1', '0', '11', '1.5' ) as $stake ) {
		bpt_test_assert( false === update_post_meta( $post_id, '_bpt_stake', $stake ), 'Metadata guard accepted invalid stake.' );
	}
	bpt_test_assert( false === add_post_meta( $post_id, '_bpt_odds', 'NaN' ), 'Metadata guard accepted invalid odds.' );
	$_POST['bpt']['stake'] = '5';
	$_POST['bpt_nonce'] = 'invalid';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( '0' === get_post_meta( $post_id, '_bpt_match_result', true ), 'Invalid nonce altered fields.' );
	$_POST['bpt_nonce'] = wp_create_nonce( 'bpt_save_' . $post_id );
	wp_set_current_user( 0 );
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( 'won' === get_post_meta( $post_id, '_bpt_bet_status', true ), 'Unauthorized save altered settlement.' );
	wp_set_current_user( $admins[0]->ID );
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( 'lost' === get_post_meta( $post_id, '_bpt_bet_status', true ), 'Result correction failed.' );
	$_POST['bpt']['match_result'] = 'pending';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( 'pending' === get_post_meta( $post_id, '_bpt_bet_status', true ), 'Reopening a result failed.' );
	foreach ( array( 'return', 'profit', 'yield' ) as $field ) {
		bpt_test_assert( ! metadata_exists( 'post', $post_id, '_bpt_' . $field ), 'Pending retained final performance.' );
	}
	$_POST['bpt']['match_result'] = '0';
	$_POST['bpt']['stake'] = '';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( ! metadata_exists( 'post', $post_id, '_bpt_stake' ) && 'pending' === get_post_meta( $post_id, '_bpt_bet_status', true ), 'Incomplete tip must stay pending.' );
	$_POST['bpt']['stake'] = '5';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	ob_start();
	Admin::performance( get_post( $post_id ) );
	$html = ob_get_clean();
	bpt_test_assert( false === strpos( $html, '<input' ) && false !== strpos( $html, '+7.50 Units' ) && false !== strpos( $html, '+150.00%' ), 'Read-only performance formatting.' );
	ob_start();
	foreach ( array( 'match', 'bet', 'result' ) as $group ) {
		Meta_Boxes::render( get_post( $post_id ), array( 'args' => array( 'group' => $group ) ) );
	}
	$html = ob_get_clean();
	bpt_test_assert( false !== strpos( $html, 'bpt_nonce' ) && false !== strpos( $html, 'Chelsea&#039;s XI' ) && preg_match( '/value="0"\s+selected=/', $html ), 'Admin field rendering/escaping.' );
	$_POST = array();
	update_post_meta( $post_id, '_bpt_odds', '1.65' );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'BPT authorized resave' ) );
	bpt_test_assert( '3.25' === get_post_meta( $post_id, '_bpt_profit', true ), 'Resave must use current betting values.' );
	$revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id ) );
	Meta_Boxes::save( $revision, get_post( $revision ) );
	bpt_test_assert( ! metadata_exists( 'post', $revision, '_bpt_bet_status' ), 'Revision was settled.' );
	$ordinary = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'BPT unrelated test' ) );
	Settlement::settle( $ordinary );
	bpt_test_assert( ! metadata_exists( 'post', $ordinary, '_bpt_bet_status' ), 'Unrelated post was settled.' );
	$term = wp_insert_term( 'BPT Test League', Leagues::TAXONOMY, array( 'slug' => 'bpt-test-league' ) );
	bpt_test_assert( ! is_wp_error( $term ) && isset( Fields::leagues()['bpt-test-league'] ), 'New league absent from dropdown.' );
	$_POST = array( 'bpt_nonce' => wp_create_nonce( 'bpt_save_' . $post_id ), 'bpt' => wp_slash( array_merge( $input, array( 'league' => 'bpt-test-league' ) ) ) );
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( 'bpt-test-league' === Leagues::selected( $post_id ), 'Dynamic league save failed.' );
	wp_update_term( $term['term_id'], Leagues::TAXONOMY, array( 'name' => 'Renamed Test League', 'slug' => 'renamed-test-league' ) );
	bpt_test_assert( 'renamed-test-league' === Leagues::selected( $post_id ) && 'Renamed Test League' === Fields::leagues()['renamed-test-league'], 'Renaming broke league selection.' );
	$_POST['bpt']['league'] = 'does-not-exist';
	$_POST['bpt']['stake'] = '9';
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	bpt_test_assert( 'renamed-test-league' === Leagues::selected( $post_id ) && '5' === get_post_meta( $post_id, '_bpt_stake', true ), 'Unknown league did not reject whole form.' );
	wp_set_current_user( 0 );
	bpt_test_assert( is_wp_error( Leagues::assign( $post_id, '' ) ), 'Unauthorized league assignment.' );
	wp_set_current_user( $admins[0]->ID );
	wp_delete_term( $term['term_id'], Leagues::TAXONOMY );
	bpt_test_assert( '' === Leagues::selected( $post_id ) && ! isset( Fields::leagues()['renamed-test-league'] ), 'Deleted league still selected/available.' );
	$other = get_term_by( 'slug', 'other', Leagues::TAXONOMY );
	if ( $other ) {
		wp_delete_term( $other->term_id, Leagues::TAXONOMY );
		Leagues::upgrade();
		bpt_test_assert( ! get_term_by( 'slug', 'other', Leagues::TAXONOMY ), 'Deleted defaults were recreated.' );
	}
	// Simulate an old installation's metadata to exercise upgrade independently of real data.
	remove_filter( 'add_post_metadata', array( Fields::class, 'guard' ), 10 );
	add_post_meta( $post_id, '_bpt_league', 'la-liga' );
	add_filter( 'add_post_metadata', array( Fields::class, 'guard' ), 10, 5 );
	delete_option( 'bpt_league_migration_complete' );
	Leagues::upgrade();
	bpt_test_assert( 'la-liga' === Leagues::selected( $post_id ) && ! metadata_exists( 'post', $post_id, '_bpt_league' ), 'Legacy migration failed.' );
	bpt_test_assert( false === update_post_meta( $post_id, '_bpt_league', 'premier-league' ), 'Legacy metadata writes should be rejected.' );
	Leagues::assign( $post_id, '' );
	bpt_test_assert( '' === Leagues::selected( $post_id ), 'Blank league failed to clear relationship.' );
	$_POST = array();
	update_post_meta( $post_id, '_bpt_odds', '1.65' );
	Settlement::settle( $post_id );
	require __DIR__ . '/shortcodes.php';
	define( 'DOING_AUTOSAVE', true );
	update_post_meta( $post_id, '_bpt_odds', '2.50' );
	Meta_Boxes::save( $post_id, get_post( $post_id ) );
	Settlement::settle( $post_id );
	bpt_test_assert( '3.25' === get_post_meta( $post_id, '_bpt_profit', true ), 'Autosave settled the tip.' );
	echo 'PASS: ' . $checks . " checks; test database changes rolled back.\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_set_current_user( $original_user );
	$_POST = array();
	restore_error_handler();
}
