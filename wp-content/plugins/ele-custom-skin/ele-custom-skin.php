<?php
/*
 * Plugin Name: ECS - Ele Custom Skin for Elementor
 * Version: 4.3.11
 * Description: Modular toolkit extending Elementor with custom loop skins, color schemes, container layouts, and more.
 * Plugin URI: https://dudaster.com
 * Author: Dudaster.com
 * Author URI: https://dudaster.com
 * Text Domain: ele-custom-skin
 * Domain Path: /languages
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 * Elementor tested up to: 3.35.0
 * Elementor Pro tested up to: 3.35.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'ECS_VERSION', '4.3.11' );
define( 'ECS_FILE',    __FILE__ );
define( 'ECS_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ECS_URL',     plugin_dir_url( __FILE__ ) );

// Backward-compatibility aliases used by legacy ECS code.
define( 'ELECS_DIR',  ECS_PATH );
define( 'ELECS_NAME', plugin_basename( __FILE__ ) );
define( 'ELECS_URL',  ECS_URL );
define( 'ELECS_VER',  ECS_VERSION );

// ── Always-on includes ────────────────────────────────────────────────────────
// These are loaded unconditionally because they're needed before Elementor init.

require_once ECS_PATH . 'includes/ecs-notices.php';
require_once ECS_PATH . 'includes/ecs-dependencies.php';
require_once ECS_PATH . 'includes/ecs-assets.php';

// ── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * Initialise the module system once Elementor is ready.
 * Individual modules handle their own Elementor Pro dependency internally.
 */
function ecs_init(): void {
	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'ECS - Ele Custom Skin requires Elementor to be installed and activated.', 'ele-custom-skin' );
			echo '</p></div>';
		} );
		return;
	}

	require_once ECS_PATH . 'includes/class-ecs-module-base.php';
	require_once ECS_PATH . 'includes/class-ecs-modules-manager.php';
	require_once ECS_PATH . 'includes/class-ecs-core.php';

	ECS_Core::instance();
}
add_action( 'elementor/init', 'ecs_init' );
