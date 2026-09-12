<?php
/**
 * Plugin Name: Betting Tips
 * Description: Manually managed individual betting tips with automatic settlement.
 * Version: 1.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: betting-planet-tips
 *
 * @package BettingPlanetTips
 */

namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

define( 'BPT_VERSION', '1.4.0' );
define( 'BPT_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-fields.php';
require_once __DIR__ . '/includes/class-leagues.php';
require_once __DIR__ . '/includes/class-post-type.php';
require_once __DIR__ . '/includes/class-settlement.php';
require_once __DIR__ . '/includes/class-meta-boxes.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-statistics.php';
require_once __DIR__ . '/includes/class-shortcodes.php';
require_once __DIR__ . '/includes/class-open-bets.php';
require_once __DIR__ . '/includes/class-shortcode-catalog.php';
require_once __DIR__ . '/includes/class-import-export.php';

add_action( 'init', array( Post_Type::class, 'register' ) );
add_action( 'init', array( Leagues::class, 'register' ), 9 );
add_action( 'admin_init', array( Leagues::class, 'upgrade' ) );
add_action( 'init', array( Fields::class, 'register' ) );
add_action( 'init', array( Shortcodes::class, 'register' ) );
add_action( 'init', array( Shortcode_Catalog::class, 'register' ), 9 );
add_action( 'admin_init', array( Shortcode_Catalog::class, 'install' ) );
add_filter( 'manage_edit-bpt_shortcode_columns', array( Shortcode_Catalog::class, 'columns' ) );
add_filter( 'manage_bpt_shortcode_custom_column', array( Shortcode_Catalog::class, 'column' ), 10, 3 );
add_action( 'after-bpt_shortcode-table', array( Shortcode_Catalog::class, 'help' ) );
add_action( 'admin_menu', array( Import_Export::class, 'menu' ) );
add_action( 'admin_post_' . Import_Export::ACTION_EXPORT, array( Import_Export::class, 'handle_export' ) );
add_action( 'admin_post_' . Import_Export::ACTION_IMPORT, array( Import_Export::class, 'handle_import' ) );
add_action( 'clean_post_cache', array( Statistics::class, 'invalidate' ), 10, 0 );
add_action( 'added_post_meta', array( Statistics::class, 'meta_changed' ), 10, 3 );
add_action( 'updated_post_meta', array( Statistics::class, 'meta_changed' ), 10, 3 );
add_action( 'deleted_post_meta', array( Statistics::class, 'meta_changed' ), 10, 3 );
add_action( 'add_meta_boxes_betting_tip', array( Meta_Boxes::class, 'register' ) );
add_action( 'save_post_betting_tip', array( Meta_Boxes::class, 'save' ), 20, 2 );
add_action( 'admin_notices', array( Admin::class, 'notices' ) );

// Reject invalid owned input metadata even when written through the native meta API.
add_filter( 'add_post_metadata', array( Fields::class, 'guard' ), 10, 5 );
add_filter( 'update_post_metadata', array( Fields::class, 'guard' ), 10, 5 );

register_activation_hook( __FILE__, array( Post_Type::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Post_Type::class, 'deactivate' ) );
