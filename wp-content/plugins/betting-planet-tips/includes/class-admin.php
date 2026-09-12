<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function notice_key( $post_id ) {
		return 'bpt_errors_' . get_current_user_id() . '_' . $post_id;
	}

	public static function remember_errors( $post_id, array $errors ) {
		set_transient( self::notice_key( $post_id ), $errors, 5 * MINUTE_IN_SECONDS );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || 'betting_tip' !== $screen->post_type ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$errors = get_transient( self::notice_key( $post_id ) );
		if ( ! is_array( $errors ) ) {
			return;
		}
		delete_transient( self::notice_key( $post_id ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Betting fields were not saved. Previous betting values were retained; WordPress title/content may still have saved.', 'betting-planet-tips' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	public static function performance( $post ) {
		$status = get_post_meta( $post->ID, '_bpt_bet_status', true );
		$labels = array( 'pending' => __( 'Pending', 'betting-planet-tips' ), 'won' => __( 'Won', 'betting-planet-tips' ), 'lost' => __( 'Lost', 'betting-planet-tips' ) );
		if ( ! isset( $labels[ $status ] ) ) {
			$status = 'pending';
		}
		echo '<p>' . esc_html__( 'Bet Status:', 'betting-planet-tips' ) . ' <strong>' . esc_html( $labels[ $status ] ) . '</strong></p><dl>';
		foreach ( array( 'return' => __( 'Return', 'betting-planet-tips' ), 'profit' => __( 'Profit', 'betting-planet-tips' ), 'yield' => __( 'Yield', 'betting-planet-tips' ) ) as $field => $label ) {
			$value   = get_post_meta( $post->ID, '_bpt_' . $field, true );
			$display = '—';
			if ( 'pending' !== $status && preg_match( '/\A-?[0-9]+\.[0-9]{2}\z/', $value ) ) {
				$prefix  = 'return' !== $field && '-' !== substr( $value, 0, 1 ) && '0.00' !== $value ? '+' : '';
				$display = $prefix . $value . ( 'yield' === $field ? '%' : ' ' . __( 'Units', 'betting-planet-tips' ) );
			}
			echo '<dt>' . esc_html( $label ) . '</dt><dd><strong>' . esc_html( $display ) . '</strong></dd>';
		}
		echo '</dl><p class="description">' . esc_html__( 'Calculated when saved. Pending or incomplete tips have no final performance. Settlement needs a selection, stake, odds, and a final match result.', 'betting-planet-tips' ) . '</p>';
	}
}
