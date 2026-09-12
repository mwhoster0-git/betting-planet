<?php
/**
 * DTE Dark Mode Colours — Elementor Kit Tab
 *
 * Appears in Site Settings → GLOBAL alongside "Default Colours".
 *
 * Structure:
 *  ├─ System Colours  — 4 fixed pickers that override --e-global-color-primary/secondary/text/accent
 *  └─ Custom Colours  — dynamically mirrors every entry from Default Colours' Custom Colors repeater,
 *                       each with the exact same _id → overrides --e-global-color-{id} for dark mode
 *
 * When <html data-ecs-scheme="alt"> is active, all --e-global-color-* variables are overridden,
 * so every Elementor widget that references a global color switches to its dark variant automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Core\Kits\Documents\Tabs\Tab_Base;
use Elementor\Core\Kits\Controls\Repeater as Global_Style_Repeater;
use Elementor\Repeater;

class ECS_Dark_Mode_Kit_Tab extends Tab_Base {

	public function get_id(): string {
		return 'ecs-dark-mode-colors';
	}

	public function get_title(): string {
		return esc_html__( 'Dark Mode Colours', 'ele-custom-skin' );
	}

	public function get_group(): string {
		return 'global';
	}

	public function get_icon(): string {
		return 'eicon-adjust';
	}

	protected function register_tab_controls(): void {
		$this->register_system_colours_section();
		$this->register_custom_colours_section();
	}

	// ── System Colours ────────────────────────────────────────────────────────

	private function register_system_colours_section(): void {
		$this->start_controls_section(
			'ecs_dark_system_colours',
			[
				'label' => esc_html__( 'System Colours', 'ele-custom-skin' ),
				'tab'   => $this->get_id(),
			]
		);

		$this->add_control(
			'ecs_dark_system_notice',
			[
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => '<p style="font-size:12px;line-height:1.6;color:#555;margin:0 0 4px;">'
					. esc_html__( 'These override the Default System Colours when Dark Mode is active.', 'ele-custom-skin' )
					. '</p>',
				'content_classes' => 'elementor-descriptor',
			]
		);

		$system_colours = [
			'ecs_dark_primary'   => [
				'label' => esc_html__( 'Primary', 'ele-custom-skin' ),
				'var'   => '--e-global-color-primary',
				'default' => '#3a9ad4',
			],
			'ecs_dark_secondary' => [
				'label' => esc_html__( 'Secondary', 'ele-custom-skin' ),
				'var'   => '--e-global-color-secondary',
				'default' => '#1e6fa0',
			],
			'ecs_dark_text'      => [
				'label' => esc_html__( 'Text', 'ele-custom-skin' ),
				'var'   => '--e-global-color-text',
				'default' => '#f0f0f0',
			],
			'ecs_dark_accent'    => [
				'label' => esc_html__( 'Accent', 'ele-custom-skin' ),
				'var'   => '--e-global-color-accent',
				'default' => '#ff6b6b',
			],
		];

		foreach ( $system_colours as $key => $config ) {
			$this->add_control(
				$key,
				[
					'label'   => $config['label'],
					'type'    => Controls_Manager::COLOR,
					'default' => $config['default'],
					'selectors' => [
						'[data-ecs-scheme="alt"]' => $config['var'] . ': {{VALUE}};',
					],
					'global' => [ 'active' => false ],
				]
			);
		}

		$this->end_controls_section();
	}

	// ── Custom Colours ────────────────────────────────────────────────────────

	private function register_custom_colours_section(): void {
		// Read the custom colours defined in Default Colours (the kit's custom_colors repeater).
		$kit_settings  = $this->parent->get_settings();
		$custom_colours = $kit_settings['custom_colors'] ?? [];

		$this->start_controls_section(
			'ecs_dark_custom_colours',
			[
				'label' => esc_html__( 'Custom Colours', 'ele-custom-skin' ),
				'tab'   => $this->get_id(),
			]
		);

		if ( empty( $custom_colours ) ) {
			$this->add_control(
				'ecs_dark_custom_empty_notice',
				[
					'type' => Controls_Manager::RAW_HTML,
					'raw'  => '<p style="font-size:12px;line-height:1.6;color:#888;margin:0;">'
						. esc_html__( 'No custom colours defined yet. Add colours in the Default Colours tab first.', 'ele-custom-skin' )
						. '</p>',
					'content_classes' => 'elementor-descriptor',
				]
			);
			$this->end_controls_section();
			return;
		}

		$this->add_control(
			'ecs_dark_custom_notice',
			[
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => '<p style="font-size:12px;line-height:1.6;color:#555;margin:0 0 4px;">'
					. esc_html__( 'Dark mode variants for each custom colour. Changes apply automatically when Dark Mode is active.', 'ele-custom-skin' )
					. '</p>',
				'content_classes' => 'elementor-descriptor',
			]
		);

		// Generate one colour picker per custom colour — using the SAME _id
		// so the CSS selector overrides the exact same --e-global-color-{id} variable.
		foreach ( $custom_colours as $colour ) {
			if ( empty( $colour['_id'] ) ) {
				continue;
			}

			$colour_id    = sanitize_key( $colour['_id'] );
			$colour_label = ! empty( $colour['title'] ) ? $colour['title'] : $colour_id;
			$css_var      = '--e-global-color-' . $colour_id;

			$this->add_control(
				'ecs_dark_custom_' . $colour_id,
				[
					'label'   => esc_html( $colour_label ),
					'type'    => Controls_Manager::COLOR,
					'default' => '',
					'selectors' => [
						'[data-ecs-scheme="alt"]' => $css_var . ': {{VALUE}};',
					],
					'global' => [ 'active' => false ],
				]
			);
		}

		$this->end_controls_section();
	}
}
