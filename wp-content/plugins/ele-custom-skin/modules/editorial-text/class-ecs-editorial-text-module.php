<?php
/**
 * Module: Editorial Text
 *
 * Provides the DTE Editorial Text widget — WYSIWYG content with an optional
 * image that flows inline (none / before / after / float left / float right).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Editorial_Text_Module extends ECS_Module_Base {

	public function get_id(): string {
		return 'editorial_text';
	}

	public function get_title(): string {
		return __( 'Editorial Text', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'A widget for editorial-style content with inline image support (float left/right, before/after) and fine typographic controls.', 'ele-custom-skin' );
	}

	public function boot(): void {}

	public function register_widgets( $widgets_manager ): void {
		require_once $this->module_path() . 'widgets/class-ecs-editorial-text-widget.php';
		$widgets_manager->register( new ECS_Editorial_Text_Widget() );
	}

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'ecs-editorial-text',
			$this->module_asset_url( 'assets/css/ecs-editorial-text.css' ),
			[],
			ECS_VERSION
		);
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-editorial-text-editor',
			$this->module_asset_url( 'assets/css/ecs-editorial-text.css' ),
			[],
			ECS_VERSION
		);
	}
}
