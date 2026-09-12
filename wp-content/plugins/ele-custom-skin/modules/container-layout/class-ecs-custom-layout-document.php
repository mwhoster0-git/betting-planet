<?php
/**
 * ECS Custom Layout — Elementor Document Type
 *
 * Appears in:
 *  - Elementor Pro Theme Builder (when Pro is active) via Theme_Document base
 *  - Elementor Template Library (free-only) via Post base
 *
 * No display conditions — the template is selected manually per-container
 * in the Container Layout panel, so conditions are not needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;

/**
 * Dynamic parent class selection.
 *
 * PHP does not allow `extends $variable`, so we declare an abstract
 * intermediate class conditionally before the concrete class is defined.
 * This is the standard PHP pattern for optional-dependency inheritance.
 */
if ( ! class_exists( 'ECS_Custom_Layout_Document_Base', false ) ) {
	if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Documents\Theme_Document' ) ) {
		// Elementor Pro is active → appear in Theme Builder section.
		abstract class ECS_Custom_Layout_Document_Base
			extends \ElementorPro\Modules\ThemeBuilder\Documents\Theme_Document {}
	} else {
		// Free Elementor only → appear in Template Library.
		abstract class ECS_Custom_Layout_Document_Base
			extends \Elementor\Core\DocumentTypes\Post {}
	}
}

class ECS_Custom_Layout_Document extends ECS_Custom_Layout_Document_Base {

	/**
	 * Unique type string — stored as _elementor_template_type post meta.
	 */
	public static function get_type(): string {
		return 'ecs_custom_layout';
	}

	public static function get_properties(): array {
		$properties = parent::get_properties();

		$properties['support_wp_page_templates'] = false;
		$properties['show_in_finder']             = true;
		$properties['show_on_admin_bar']          = true;

		// Pro Theme Builder: group under the "theme" tab, no conditions needed.
		$properties['admin_tab_group']   = 'theme';
		$properties['support_conditions'] = false;
		$properties['support_site_editor'] = true;

		return $properties;
	}

	/**
	 * Static title used for the tab label in Theme Builder / Template Library.
	 */
	public static function get_title(): string {
		return esc_html__( 'ECS Custom Layout', 'ele-custom-skin' );
	}

	/**
	 * Required by Theme_Document (Pro) for the library UI.
	 */
	public static function get_plural_title(): string {
		return esc_html__( 'ECS Custom Layouts', 'ele-custom-skin' );
	}

	public function get_css_wrapper_selector(): string {
		return 'body.elementor-template-type-ecs_custom_layout';
	}

	/**
	 * Add a "Recommended" category at the top of the widget panel,
	 * positioned right after "Favorites" (same pattern as Elementor Pro Loop Item).
	 */
	protected static function get_editor_panel_categories(): array {
		$new_categories = [
			'recommended' => [
				'title' => esc_html__( 'Recommended', 'ele-custom-skin' ),
			],
		];

		$parent_categories = parent::get_editor_panel_categories();
		$favorites_index   = array_search( 'favorites', array_keys( $parent_categories ), true );

		if ( false !== $favorites_index ) {
			return array_slice( $parent_categories, 0, $favorites_index + 1, true )
				+ $new_categories
				+ array_slice( $parent_categories, $favorites_index + 1, null, true );
		}

		return $new_categories + $parent_categories;
	}

	/**
	 * Put DTE Container Placeholder in the "Recommended" category so it
	 * appears first in the widget panel when editing a ECS Custom Layout.
	 */
	public function get_initial_config(): array {
		$config = parent::get_initial_config();

		$config['panel']['widgets_settings']['ecs_container_placeholder'] = [
			'categories'    => [ 'recommended' ],
			'show_in_panel' => true,
		];

		return $config;
	}

	protected function register_controls(): void {
		parent::register_controls();

		$this->start_controls_section(
			'ecs_layout_settings',
			[
				'label' => esc_html__( 'ECS Layout Settings', 'ele-custom-skin' ),
				'tab'   => Controls_Manager::TAB_SETTINGS,
			]
		);

		$this->add_control(
			'ecs_child_wrapper_class',
			[
				'label'       => esc_html__( 'Child Wrapper Class', 'ele-custom-skin' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'ecs-layout-item',
				'placeholder' => 'ecs-layout-item',
				'description' => esc_html__( 'CSS class applied to the <div> wrapping each injected child.', 'ele-custom-skin' ),
			]
		);

		$this->end_controls_section();
	}
}
