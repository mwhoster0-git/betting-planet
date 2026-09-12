<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Shortcode_Catalog {
	const TAXONOMY = 'bpt_shortcode';

	public static function register() {
		register_taxonomy( self::TAXONOMY, array( 'betting_tip' ), array(
			'labels' => array(
				'name' => __( 'Shortcodes', 'betting-planet-tips' ),
				'singular_name' => __( 'Shortcode', 'betting-planet-tips' ),
				'menu_name' => __( 'Shortcodes', 'betting-planet-tips' ),
				'search_items' => __( 'Search Shortcodes', 'betting-planet-tips' ),
				'not_found' => __( 'No shortcodes found.', 'betting-planet-tips' ),
			),
			'public' => false, 'show_ui' => true, 'show_in_menu' => true,
			'show_in_rest' => false, 'show_in_quick_edit' => false,
			'meta_box_cb' => false, 'rewrite' => false, 'query_var' => false,
			// A built-in shortcode library: terms store reference data, never executable code.
			'capabilities' => array(
				'manage_terms' => 'manage_categories', 'edit_terms' => 'do_not_allow',
				'delete_terms' => 'do_not_allow', 'assign_terms' => 'do_not_allow',
			),
		) );
		register_term_meta( self::TAXONOMY, '_bpt_shortcode', array(
			'type' => 'string', 'single' => true, 'show_in_rest' => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback' => '__return_false',
		) );
	}

	public static function install() {
		if ( '5' === get_option( 'bpt_shortcode_catalog_version' ) ) {
			return;
		}
		// Remove the retired built-in entries when upgrading an existing installation.
		foreach ( array( 'bpt-team-a', 'bpt-team-b' ) as $slug ) {
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( $term ) {
				$deleted = wp_delete_term( $term->term_id, self::TAXONOMY );
				if ( is_wp_error( $deleted ) || ! $deleted ) {
					return;
				}
			}
		}
		foreach ( Shortcodes::definitions() as $tag => $definition ) {
			$slug = str_replace( '_', '-', $tag );
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( ! $term ) {
				$created = wp_insert_term( $definition['name'], self::TAXONOMY, array( 'slug' => $slug, 'description' => $definition['description'] ) );
				if ( is_wp_error( $created ) ) {
					return;
				}
				$term_id = $created['term_id'];
			} else {
				$term_id = $term->term_id;
			}
			update_term_meta( $term_id, '_bpt_shortcode', '[' . $tag . ']' );
			if ( '[' . $tag . ']' !== get_term_meta( $term_id, '_bpt_shortcode', true ) ) {
				return;
			}
		}
		update_option( 'bpt_shortcode_catalog_version', '5', false );
	}

	public static function columns( $columns ) {
		return array(
			'name' => __( 'Name', 'betting-planet-tips' ),
			'bpt_code' => __( 'Shortcode — select and copy', 'betting-planet-tips' ),
			'description' => __( 'Usage', 'betting-planet-tips' ),
		);
	}

	public static function column( $output, $column, $term_id ) {
		if ( 'bpt_code' !== $column ) {
			return $output;
		}
		$code = get_term_meta( $term_id, '_bpt_shortcode', true );
		return '<input type="text" class="regular-text code" readonly aria-label="' . esc_attr__( 'Shortcode to copy', 'betting-planet-tips' ) . '" value="' . esc_attr( $code ) . '" />';
	}

	public static function help() {
		echo '<p>' . esc_html__( 'Copy a shortcode into a WordPress Shortcode block or an Elementor Shortcode widget. The calculation codes return text; Open bets display renders the swipeable card deck.', 'betting-planet-tips' ) . '</p>';
		echo '<p>' . esc_html__( 'Statistics use published, non-password-protected tips. Performance excludes pending/incomplete tips. Percentages display 0.00% when there are no settled tips.', 'betting-planet-tips' ) . '</p>';
	}
}
