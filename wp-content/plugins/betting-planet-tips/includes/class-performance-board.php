<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Performance_Board {
	public static function rows( $limit ) {
		$rows = array();
		$page = 1;
		do {
			$query = new \WP_Query( array(
				'post_type' => 'betting_tip', 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => 50, 'paged' => $page, 'no_found_rows' => true,
				'meta_key' => '_bpt_match_datetime', 'orderby' => array( 'meta_value' => 'DESC', 'ID' => 'DESC' ),
				'meta_query' => array(
					array( 'key' => '_bpt_match_result', 'value' => array( '1', '0', '2' ), 'compare' => 'IN' ),
					array( 'key' => '_bpt_bet_status', 'value' => array( 'won', 'lost' ), 'compare' => 'IN' ),
				),
			) );
			foreach ( $query->posts as $post ) {
				$record = array();
				foreach ( array( 'home_team', 'away_team', 'stake', 'odds', 'bet_selection', 'match_result', 'match_datetime' ) as $field ) {
					$record[ $field ] = Fields::validate( $field, get_post_meta( $post->ID, '_bpt_' . $field, true ) );
					if ( is_wp_error( $record[ $field ] ) || '' === $record[ $field ] ) {
						continue 2;
					}
				}
				$record['bet_status'] = get_post_meta( $post->ID, '_bpt_bet_status', true );
				$settled = Settlement::calculate( $record );
				if ( 'pending' === $settled['bet_status'] || $record['bet_status'] !== $settled['bet_status'] ) {
					continue;
				}
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $record['match_datetime'], wp_timezone() );
				if ( ! $date ) {
					continue;
				}
				$rows[] = array(
					'date' => wp_date( 'd.m.', $date->getTimestamp(), wp_timezone() ),
					'match' => $record['home_team'] . ' - ' . $record['away_team'],
					'selection' => $record['bet_selection'],
					'stake' => $record['stake'],
					'odds' => $record['odds'],
					'status' => $settled['bet_status'],
					'profit' => $settled['profit'],
					'yield' => $settled['yield'],
				);
				if ( count( $rows ) >= $limit ) {
					return $rows;
				}
			}
			++$page;
		} while ( 50 === count( $query->posts ) );
		return $rows;
	}

	public static function render( $attributes ) {
		$attributes = shortcode_atts( array( 'limit' => '20' ), $attributes, 'bpt_performance_board' );
		$limit = is_scalar( $attributes['limit'] ) && preg_match( '/\A[1-9][0-9]{0,2}\z/', (string) $attributes['limit'] ) ? min( 100, (int) $attributes['limit'] ) : 20;
		$rows = self::rows( $limit );
		wp_enqueue_style( 'bpt-performance-board', BPT_URL . 'assets/performance-board.css', array(), BPT_VERSION );
		ob_start();
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'bpt-performance-board', 'done' ) ) {
			wp_print_styles( 'bpt-performance-board' );
		}
		include dirname( __DIR__ ) . '/templates/performance-board.php';
		return ob_get_clean();
	}

	public static function signed_amount( $value, $suffix ) {
		$prefix = '-' !== substr( $value, 0, 1 ) && '0.00' !== $value ? '+' : '';
		return $prefix . $value . $suffix;
	}

	public static function compact_percent( $value ) {
		$display = preg_replace( '/\.00\z/', '', $value );
		return self::signed_amount( $display, '%' );
	}
}
