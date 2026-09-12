<?php
/**
 * Abstract base class for all ECS modules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ECS_Module_Base {

	/** Unique snake_case identifier, e.g. 'color_scheme' */
	abstract public function get_id(): string;

	/** Human-readable title shown in the admin UI */
	abstract public function get_title(): string;

	/** Short description shown in the admin UI */
	public function get_description(): string {
		return '';
	}

	/** Whether this module is deprecated (legacy) */
	public function is_deprecated(): bool {
		return false;
	}

	/** Optional migration notice shown on deprecated module cards */
	public function get_deprecated_notice(): string {
		return '';
	}

	/** Whether this module requires ECS Pro licence */
	public function is_pro(): bool {
		return false;
	}

	/** Main entry point called by the modules manager when the module is active */
	abstract public function boot(): void;

	/** Register Elementor widgets — called on elementor/widgets/register */
	public function register_widgets( $widgets_manager ): void {}

	/** Register custom Elementor controls — called on elementor/controls/register */
	public function register_controls( $controls_manager ): void {}

	/** Enqueue frontend assets — called on wp_enqueue_scripts */
	public function enqueue_frontend_assets(): void {}

	/** Enqueue editor-only assets — called on elementor/editor/after_enqueue_scripts */
	public function enqueue_editor_assets(): void {}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function module_path(): string {
		return ECS_PATH . 'modules/' . str_replace( '_', '-', $this->get_id() ) . '/';
	}

	protected function module_url(): string {
		return ECS_URL . 'modules/' . str_replace( '_', '-', $this->get_id() ) . '/';
	}

	/** Enqueue URL for a .css/.js file under this module, minified when available. */
	protected function module_asset_url( string $rel_path ): string {
		return ecs_asset_url( $this->module_url() . $rel_path, $this->module_path() . $rel_path );
	}
}
