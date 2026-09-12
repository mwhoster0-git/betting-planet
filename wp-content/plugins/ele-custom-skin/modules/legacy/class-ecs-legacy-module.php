<?php
/**
 * ECS Legacy Module
 *
 * Wraps the original Ele Custom Skin 3.x functionality (custom loop skin,
 * loop item widget, Ajax pagination, dynamic style, admin bar menu).
 *
 * This module is active by default only when updating from ECS 3.x.
 * New installs start with it disabled. It is marked as deprecated and
 * will be removed in a future major version.
 *
 * @deprecated since ECS 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Legacy_Module extends ECS_Module_Base {

	public function get_id(): string {
		return 'legacy';
	}

	public function get_title(): string {
		return __( 'ECS Legacy (Loop Skin)', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Original ECS custom skin for Posts/Archive widgets, Loop Item widget, and Ajax pagination.', 'ele-custom-skin' );
	}

	public function is_deprecated(): bool {
		return true;
	}

	public function get_deprecated_notice(): string {
		return __( 'Features from this module are being moved to dedicated modules in ECS Free. Keep it active to preserve your existing site, and switch to the new modules when ready.', 'ele-custom-skin' );
	}

	public function boot(): void {
		// Always load: enqueue styles, pro-features notices, dynamic style fix.
		require_once ELECS_DIR . 'includes/enqueue-styles.php';
		require_once ELECS_DIR . 'includes/pro-features.php';
		require_once ELECS_DIR . 'includes/dynamic-style.php';
		require_once ELECS_DIR . 'includes/ajax-pagination.php';

		// Elementor Pro-dependent features (skin + theme builder + loop item).
		if ( ecs_dependencies() ) {
			add_action( 'elementor_pro/init', [ $this, 'boot_pro_features' ] );
			add_action( 'init', 'ecs_check_for_notification' );
		}
	}

	public function boot_pro_features(): void {
		require_once ELECS_DIR . 'includes/admin-bar-menu.php';
		require_once ELECS_DIR . 'theme-builder/init.php';
		require_once ELECS_DIR . 'modules/loop-item/module.php';
	}

	public function register_widgets( $widgets_manager ): void {
		// The skin registers itself via elementor/widgets/register.
		// We hook it here when the legacy module is active.
		if ( ecs_dependencies() ) {
			require_once ELECS_DIR . 'skins/skin-custom.php';
		}
	}
}
