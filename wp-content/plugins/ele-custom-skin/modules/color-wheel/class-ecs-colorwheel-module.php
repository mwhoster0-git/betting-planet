<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Colorwheel_Module extends ECS_Module_Base {

	const OPTION_KEY  = 'ecs_colorwheel_palettes';
	const MAX_PALETTES = 50;

	public function get_id(): string {
		return 'color_wheel';
	}

	public function get_title(): string {
		return 'Color Wheel';
	}

	public function get_description(): string {
		return 'Generate color harmonies from any color picker. Save and reuse palettes across your site.';
	}

	public function boot(): void {
		add_action( 'wp_ajax_ecs_cw_get_palettes',   [ $this, 'ajax_get_palettes' ] );
		add_action( 'wp_ajax_ecs_cw_save_palette',   [ $this, 'ajax_save_palette' ] );
		add_action( 'wp_ajax_ecs_cw_delete_palette', [ $this, 'ajax_delete_palette' ] );
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-colorwheel-editor',
			$this->module_asset_url( 'assets/css/ecs-colorwheel-editor.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-colorwheel-editor',
			$this->module_asset_url( 'assets/js/ecs-colorwheel-editor.js' ),
			[ 'elementor-editor', 'jquery' ],
			ECS_VERSION,
			true
		);

		wp_localize_script( 'ecs-colorwheel-editor', 'ecsColorWheel', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ecs_colorwheel' ),
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_get_palettes(): void {
		check_ajax_referer( 'ecs_colorwheel', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$palettes = $this->get_palettes();
		wp_send_json_success( $palettes );
	}

	public function ajax_save_palette(): void {
		check_ajax_referer( 'ecs_colorwheel', 'nonce' );

		// Palettes are a single site-wide option shared by every user of the
		// color picker, so writing one is not scoped to a post the caller
		// owns — edit_posts (held by Contributors) is not enough here.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$colors = isset( $_POST['colors'] ) ? (array) $_POST['colors'] : [];

		if ( empty( $name ) ) {
			wp_send_json_error( 'Name required' );
		}

		$colors = array_values( array_filter(
			array_map( 'sanitize_hex_color', $colors )
		) );

		if ( empty( $colors ) ) {
			wp_send_json_error( 'No valid colors' );
		}

		$palettes = $this->get_palettes();

		if ( count( $palettes ) >= self::MAX_PALETTES ) {
			wp_send_json_error( 'Palette limit reached' );
		}

		// Overwrite if name exists.
		foreach ( $palettes as $i => $p ) {
			if ( $p['name'] === $name ) {
				$palettes[ $i ] = [ 'name' => $name, 'colors' => $colors ];
				update_option( self::OPTION_KEY, $palettes );
				wp_send_json_success( $palettes );
			}
		}

		$palettes[] = [ 'name' => $name, 'colors' => $colors ];
		update_option( self::OPTION_KEY, $palettes );
		wp_send_json_success( $palettes );
	}

	public function ajax_delete_palette(): void {
		check_ajax_referer( 'ecs_colorwheel', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$palettes = $this->get_palettes();
		$palettes = array_values( array_filter( $palettes, fn( $p ) => $p['name'] !== $name ) );

		update_option( self::OPTION_KEY, $palettes );
		wp_send_json_success( $palettes );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_palettes(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}
}
