<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	public static function register() {
		register_post_type(
			'betting_tip',
			array(
				'labels'       => array(
					'name'          => __( 'Betting Tips', 'betting-planet-tips' ),
					'singular_name' => __( 'Betting Tip', 'betting-planet-tips' ),
					'add_new_item'  => __( 'Add New Betting Tip', 'betting-planet-tips' ),
					'edit_item'     => __( 'Edit Betting Tip', 'betting-planet-tips' ),
					'new_item'      => __( 'New Betting Tip', 'betting-planet-tips' ),
					'view_item'     => __( 'View Betting Tip', 'betting-planet-tips' ),
					'search_items'  => __( 'Search Betting Tips', 'betting-planet-tips' ),
					'not_found'     => __( 'No betting tips found.', 'betting-planet-tips' ),
				),
				'public'       => true,
				'show_in_rest' => false,
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'betting-tips', 'with_front' => false ),
				'menu_icon'    => 'dashicons-chart-line',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'author', 'revisions' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function activate() {
		self::register();
		Leagues::register();
		Leagues::upgrade();
		Shortcode_Catalog::register();
		Shortcode_Catalog::install();
		flush_rewrite_rules( false );
	}

	public static function deactivate() {
		unregister_post_type( 'betting_tip' );
		flush_rewrite_rules( false );
	}
}
