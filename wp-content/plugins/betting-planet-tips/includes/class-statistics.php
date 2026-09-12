<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Statistics {
	private static $cached = null;

	public static function invalidate() {
		self::$cached = null;
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( 0 === strpos( $key, '_bpt_' ) ) {
			self::invalidate();
		}
	}

	/** One shared, request-local snapshot for all aggregate shortcodes. */
	public static function totals() {
		if ( null === self::$cached ) {
			self::$cached = self::summarize( self::records() );
		}
		return self::$cached;
	}

	/** Bounded queries avoid loading an entire tip history into memory at once. */
	private static function records() {
		$page = 1;
		do {
			$query = new \WP_Query( array(
				'post_type' => 'betting_tip', 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => 200, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC',
				'no_found_rows' => true, 'ignore_sticky_posts' => true,
				'update_post_term_cache' => false,
			) );
			foreach ( $query->posts as $post ) {
				$record = array();
				foreach ( array( 'stake', 'odds', 'bet_selection', 'match_result', 'bet_status' ) as $key ) {
					$record[ $key ] = get_post_meta( $post->ID, '_bpt_' . $key, true );
				}
				yield $record;
			}
			++$page;
		} while ( 200 === count( $query->posts ) );
	}

	/** Sum integer hundredths; percentages are calculated only after summation. */
	public static function summarize( iterable $records ) {
		$totals = array( 'tips' => 0, 'settled' => 0, 'wins' => 0, 'stakes' => 0, 'returns' => 0, 'profit' => 0 );
		foreach ( $records as $record ) {
			++$totals['tips'];
			$settled = Settlement::calculate( $record );
			// Exclude incomplete/pending inputs and inconsistent/unsettled stored statuses.
			if ( 'pending' === $settled['bet_status'] || ( $record['bet_status'] ?? '' ) !== $settled['bet_status'] ) {
				continue;
			}
			++$totals['settled'];
			$totals['wins'] += 'won' === $settled['bet_status'] ? 1 : 0;
			$totals['stakes'] += (int) $record['stake'] * 100;
			$totals['returns'] += (int) str_replace( '.', '', $settled['return'] );
		}
		$totals['profit'] = $totals['returns'] - $totals['stakes'];
		return $totals;
	}

	public static function percentage( $numerator, $denominator ) {
		// Zero is a display convention when no settled denominator exists.
		return number_format( $denominator > 0 ? ( $numerator / $denominator ) * 100 : 0, 2, '.', '' ) . '%';
	}
}
