<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Leagues {
	const TAXONOMY = 'bpt_league';

	public static function register() {
		register_taxonomy( self::TAXONOMY, array( 'betting_tip' ), array(
			'labels' => array(
				'name' => __( 'Leagues', 'betting-planet-tips' ),
				'singular_name' => __( 'League', 'betting-planet-tips' ),
				'menu_name' => __( 'Leagues', 'betting-planet-tips' ),
				'search_items' => __( 'Search Leagues', 'betting-planet-tips' ),
				'all_items' => __( 'All Leagues', 'betting-planet-tips' ),
				'edit_item' => __( 'Edit League', 'betting-planet-tips' ),
				'update_item' => __( 'Update League', 'betting-planet-tips' ),
				'add_new_item' => __( 'Add New League', 'betting-planet-tips' ),
				'new_item_name' => __( 'New League Name', 'betting-planet-tips' ),
			),
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'show_in_rest' => false,
			'show_admin_column' => true,
			'show_in_quick_edit' => false,
			'hierarchical' => false,
			'meta_box_cb' => false,
			'rewrite' => false,
			'query_var' => false,
			'capabilities' => array(
				'manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories',
				'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_posts',
			),
		) );
	}

	public static function choices() {
		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name' ) );
		$choices = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$choices[ $term->slug ] = $term->name;
			}
		}
		return $choices;
	}

	public static function selected( $post_id ) {
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY );
		return ! is_wp_error( $terms ) && $terms ? $terms[0]->slug : '';
	}

	/** Replace the single league using an existing term ID, never create terms on save. */
	public static function assign( $post_id, $slug ) {
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( get_taxonomy( self::TAXONOMY )->cap->assign_terms ) ) {
			return new \WP_Error( 'bpt_league_permission', __( 'You cannot assign leagues.', 'betting-planet-tips' ) );
		}
		return self::set_term( $post_id, $slug );
	}

	private static function set_term( $post_id, $slug ) {
		$term = '' !== $slug ? get_term_by( 'slug', $slug, self::TAXONOMY ) : null;
		if ( '' !== $slug && ! $term ) {
			return new \WP_Error( 'bpt_league_missing', __( 'The league no longer exists. Reload and select a league.', 'betting-planet-tips' ) );
		}
		return wp_set_object_terms( $post_id, $term ? array( (int) $term->term_id ) : array(), self::TAXONOMY, false );
	}

	/** One-time defaults and resumable legacy migration, limited to 100 tips per request. */
	public static function upgrade() {
		if ( get_option( 'bpt_league_migration_complete' ) ) {
			return;
		}
		if ( ! get_option( 'bpt_league_defaults_created' ) ) {
			$defaults = array(
				'premier-league' => 'Premier League', 'la-liga' => 'La Liga',
				'serie-a' => 'Serie A', 'bundesliga' => 'Bundesliga', 'ligue-1' => 'Ligue 1',
				'champions-league' => 'Champions League', 'other' => 'Other',
			);
			foreach ( $defaults as $slug => $name ) {
				if ( ! get_term_by( 'slug', $slug, self::TAXONOMY ) ) {
					$created = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
					if ( is_wp_error( $created ) ) {
						return;
					}
				}
			}
			update_option( 'bpt_league_defaults_created', 1, false );
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s ORDER BY p.ID LIMIT 100",
			'betting_tip', '_bpt_league'
		) );
		foreach ( $ids as $post_id ) {
			$slug = get_post_meta( $post_id, '_bpt_league', true );
			$existing = wp_get_object_terms( $post_id, self::TAXONOMY );
			if ( is_wp_error( $existing ) || ! is_string( $slug ) ) {
				return;
			}
			if ( ! $existing ) {
				if ( '' !== $slug && ! get_term_by( 'slug', $slug, self::TAXONOMY ) ) {
					$created = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), self::TAXONOMY, array( 'slug' => $slug ) );
					if ( is_wp_error( $created ) ) {
						return;
					}
				}
				if ( is_wp_error( self::set_term( $post_id, $slug ) ) ) {
					return;
				}
			}
			// Only remove the old value after successful assignment; never overwrite existing terms.
			delete_post_meta( $post_id, '_bpt_league' );
		}
		if ( count( $ids ) < 100 ) {
			update_option( 'bpt_league_migration_complete', 1, false );
		}
	}
}
