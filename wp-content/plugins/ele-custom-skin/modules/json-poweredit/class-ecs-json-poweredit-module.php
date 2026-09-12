<?php
/**
 * Module: JSON PowerEdit
 *
 * Adds an "Edit JSON" button to Elementor repeater controls in the editor panel.
 * Allows viewing, editing and replacing the repeater's data as raw JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_JSON_PowerEdit_Module extends ECS_Module_Base {

	// ── Identity ──────────────────────────────────────────────────────────────

	public function get_id(): string {
		return 'json_poweredit';
	}

	public function get_title(): string {
		return __( 'JSON PowerEdit', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Adds an "Edit JSON" button to every Elementor repeater control. View, edit and bulk-replace repeater data as raw JSON directly in the editor.', 'ele-custom-skin' );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	public function boot(): void {
		// Nothing to hook server-side; all functionality is JS-only (editor).
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-json-poweredit-editor',
			$this->module_asset_url( 'assets/css/ecs-json-poweredit-editor.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-json-poweredit-editor',
			$this->module_asset_url( 'assets/js/ecs-json-poweredit-editor.js' ),
			[ 'jquery', 'elementor-editor' ],
			ECS_VERSION,
			true
		);
	}
}
