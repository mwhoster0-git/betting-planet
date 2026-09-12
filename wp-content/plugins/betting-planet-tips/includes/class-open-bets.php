<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Open_Bets {
	public static function tips( $limit ) {
		$tips = array();
		$page = 1;
		do {
			$query = new \WP_Query( array(
				'post_type' => 'betting_tip', 'post_status' => 'publish', 'has_password' => false,
				'posts_per_page' => 50, 'paged' => $page, 'no_found_rows' => true,
				'meta_key' => '_bpt_match_datetime', 'orderby' => array( 'meta_value' => 'ASC', 'ID' => 'ASC' ),
				'meta_query' => array(
					array( 'relation' => 'OR', array( 'key' => '_bpt_match_result', 'value' => 'pending' ), array( 'key' => '_bpt_match_result', 'compare' => 'NOT EXISTS' ) ),
					array( 'relation' => 'OR', array( 'key' => '_bpt_bet_status', 'value' => 'pending' ), array( 'key' => '_bpt_bet_status', 'compare' => 'NOT EXISTS' ) ),
				),
			) );
			foreach ( $query->posts as $post ) {
				$data = array();
				foreach ( array( 'home_team', 'away_team', 'stake', 'odds', 'bet_selection', 'match_datetime', 'season' ) as $field ) {
					$data[ $field ] = Fields::validate( $field, get_post_meta( $post->ID, '_bpt_' . $field, true ) );
					if ( is_wp_error( $data[ $field ] ) || ( 'season' !== $field && '' === $data[ $field ] ) ) {
						continue 2;
					}
				}
				$leagues = wp_get_object_terms( $post->ID, Leagues::TAXONOMY, array( 'fields' => 'names' ) );
				$data['league'] = ! is_wp_error( $leagues ) ? implode( ', ', $leagues ) : '';
				$data['title'] = get_the_title( $post );
				$data['analysis'] = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55, '…' );
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $data['match_datetime'], wp_timezone() );
				$data['date_label'] = wp_date( 'D. d.m. · H:i', $date->getTimestamp(), wp_timezone() );
				$data['date_iso'] = $date->format( 'c' );
				$data['selection_label'] = '0' === $data['bet_selection'] ? __( 'Draw', 'betting-planet-tips' ) : $data[ '1' === $data['bet_selection'] ? 'home_team' : 'away_team' ];
				$tips[] = $data;
				if ( count( $tips ) >= $limit ) {
					return $tips;
				}
			}
			++$page;
		} while ( 50 === count( $query->posts ) );
		return $tips;
	}

	public static function initials( $team ) {
		$words = preg_split( '/[\s-]+/u', $team, -1, PREG_SPLIT_NO_EMPTY );
		$text = count( $words ) > 1 ? implode( '', array_map( static function ( $word ) { return wp_html_excerpt( $word, 1 ); }, array_slice( $words, 0, 3 ) ) ) : wp_html_excerpt( $team, 3 );
		return strtoupper( $text );
	}

	public static function render( $attributes ) {
		$attributes = shortcode_atts( array( 'limit' => '20' ), $attributes, 'bpt_open_bets' );
		$limit = is_scalar( $attributes['limit'] ) && preg_match( '/\A[1-9][0-9]{0,2}\z/', (string) $attributes['limit'] ) ? min( 100, (int) $attributes['limit'] ) : 20;
		$tips = self::tips( $limit );
		wp_enqueue_style( 'bpt-open-bets', BPT_URL . 'assets/open-bets.css', array(), BPT_VERSION );
		wp_enqueue_script( 'bpt-open-bets', BPT_URL . 'assets/open-bets.js', array(), BPT_VERSION, true );
		$id = wp_unique_id( 'bpt-open-bets-' );
		ob_start();
		// Shortcodes often render after wp_head, including inside page builders.
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'bpt-open-bets', 'done' ) ) {
			wp_print_styles( 'bpt-open-bets' );
		}
		include dirname( __DIR__ ) . '/templates/open-bets.php';
		return ob_get_clean();
	}
}
