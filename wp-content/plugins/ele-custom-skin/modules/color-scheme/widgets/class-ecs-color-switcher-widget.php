<?php
/**
 * Elementor Widget: DTE Dark Mode Switcher
 *
 * Three display modes:
 *  - Toggle   : single button cycling Light ↔ Dark
 *  - Dual     : two side-by-side buttons, active one highlighted
 *  - Dropdown : native <select>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Icons_Manager;

class ECS_Color_Switcher_Widget extends Widget_Base {

	public function get_name(): string {
		return 'ecs_color_switcher';
	}

	public function get_title(): string {
		return esc_html__( 'ECS Dark Mode Switcher', 'ele-custom-skin' );
	}

	public function get_icon(): string {
		return 'eicon-adjust';
	}

	public function get_categories(): array {
		return [ 'ele-custom-skin' ];
	}

	public function get_keywords(): array {
		return [ 'ecs', 'dark', 'mode', 'switcher', 'toggle', 'theme', 'color', 'scheme' ];
	}

	public function get_style_depends(): array {
		return [ 'elementor-icons' ];
	}

	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_button_controls();
		$this->register_style_dropdown_controls();
	}

	// ── Content ──────────────────────────────────────────────────────────────

	private function register_content_controls(): void {

		$this->start_controls_section( 'section_content', [
			'label' => esc_html__( 'Content', 'ele-custom-skin' ),
		] );

		$this->add_control( 'display_type', [
			'label'   => esc_html__( 'Display', 'ele-custom-skin' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'toggle',
			'options' => [
				'toggle'   => esc_html__( 'Toggle Button', 'ele-custom-skin' ),
				'dual'     => esc_html__( 'Dual Buttons', 'ele-custom-skin' ),
				'dropdown' => esc_html__( 'Dropdown', 'ele-custom-skin' ),
			],
		] );

		// ── Light mode ───────────────────────────────────────────────────────

		$this->add_control( 'heading_light', [
			'label'     => esc_html__( 'Light Mode', 'ele-custom-skin' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
		] );

		$this->add_control( 'label_light', [
			'label'   => esc_html__( 'Label', 'ele-custom-skin' ),
			'type'    => Controls_Manager::TEXT,
			'default' => esc_html__( 'Light', 'ele-custom-skin' ),
		] );

		$this->add_control( 'icon_light', [
			'label'       => esc_html__( 'Icon', 'ele-custom-skin' ),
			'type'        => Controls_Manager::ICONS,
			'default'     => [ 'value' => 'eicon-sun-o', 'library' => 'eicons' ],
			'skin'        => 'inline',
			'label_block' => false,
			'condition'   => [ 'display_type!' => 'dropdown' ],
		] );

		// ── Dark mode ────────────────────────────────────────────────────────

		$this->add_control( 'heading_dark', [
			'label'     => esc_html__( 'Dark Mode', 'ele-custom-skin' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
		] );

		$this->add_control( 'label_dark', [
			'label'   => esc_html__( 'Label', 'ele-custom-skin' ),
			'type'    => Controls_Manager::TEXT,
			'default' => esc_html__( 'Dark', 'ele-custom-skin' ),
		] );

		$this->add_control( 'icon_dark', [
			'label'       => esc_html__( 'Icon', 'ele-custom-skin' ),
			'type'        => Controls_Manager::ICONS,
			'default'     => [ 'value' => 'eicon-moon-o', 'library' => 'eicons' ],
			'skin'        => 'inline',
			'label_block' => false,
			'condition'   => [ 'display_type!' => 'dropdown' ],
		] );

		// ── Layout ───────────────────────────────────────────────────────────

		$this->add_control( 'icon_position', [
			'label'     => esc_html__( 'Icon Position', 'ele-custom-skin' ),
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'before' => [ 'title' => esc_html__( 'Before', 'ele-custom-skin' ), 'icon' => 'eicon-h-align-left' ],
				'after'  => [ 'title' => esc_html__( 'After', 'ele-custom-skin' ),  'icon' => 'eicon-h-align-right' ],
			],
			'default'   => 'before',
			'condition' => [ 'display_type!' => 'dropdown' ],
		] );

		$this->end_controls_section();
	}

	// ── Style: Button (toggle + dual) ────────────────────────────────────────

	private function register_style_button_controls(): void {

		$this->start_controls_section( 'section_style_btn', [
			'label'     => esc_html__( 'Button', 'ele-custom-skin' ),
			'tab'       => Controls_Manager::TAB_STYLE,
			'condition' => [ 'display_type!' => 'dropdown' ],
		] );

		$this->add_responsive_control( 'btn_align', [
			'label'     => esc_html__( 'Alignment', 'ele-custom-skin' ),
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'flex-start' => [ 'title' => esc_html__( 'Left', 'ele-custom-skin' ),   'icon' => 'eicon-text-align-left' ],
				'center'     => [ 'title' => esc_html__( 'Center', 'ele-custom-skin' ), 'icon' => 'eicon-text-align-center' ],
				'flex-end'   => [ 'title' => esc_html__( 'Right', 'ele-custom-skin' ),  'icon' => 'eicon-text-align-right' ],
			],
			'default'   => 'flex-start',
			'selectors' => [ '{{WRAPPER}} .ecs-dms-wrap' => 'justify-content: {{VALUE}};' ],
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'btn_typography',
			'selector' => '{{WRAPPER}} .ecs-dms-btn',
		] );

		$this->add_control( 'btn_icon_size', [
			'label'      => esc_html__( 'Icon Size', 'ele-custom-skin' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px', 'em' ],
			'range'      => [ 'px' => [ 'min' => 8, 'max' => 64 ] ],
			'default'    => [ 'size' => 16, 'unit' => 'px' ],
			'selectors'  => [
				'{{WRAPPER}} .ecs-dms-icon i'   => 'font-size: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .ecs-dms-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->add_control( 'btn_icon_gap', [
			'label'      => esc_html__( 'Icon Gap', 'ele-custom-skin' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 30 ] ],
			'selectors'  => [ '{{WRAPPER}} .ecs-dms-btn' => 'gap: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_responsive_control( 'btn_padding', [
			'label'      => esc_html__( 'Padding', 'ele-custom-skin' ),
			'type'       => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', '%' ],
			'selectors'  => [
				'{{WRAPPER}} .ecs-dms-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );

		$this->add_responsive_control( 'btn_border_radius', [
			'label'      => esc_html__( 'Border Radius', 'ele-custom-skin' ),
			'type'       => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%', 'em' ],
			'selectors'  => [
				'{{WRAPPER}} .ecs-dms-btn'  => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .ecs-dms-wrap' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );

		$this->add_group_control( Group_Control_Border::get_type(), [
			'name'     => 'btn_border',
			'selector' => '{{WRAPPER}} .ecs-dms-btn',
		] );

		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [
			'name'     => 'btn_box_shadow',
			'selector' => '{{WRAPPER}} .ecs-dms-btn',
		] );

		// ── Color tabs ───────────────────────────────────────────────────────

		$this->start_controls_tabs( 'btn_colors' );

		// Normal / Inactive
		$this->start_controls_tab( 'btn_colors_normal', [
			'label' => esc_html__( 'Normal', 'ele-custom-skin' ),
		] );

		$this->add_control( 'btn_color_text_normal', [
			'label'     => esc_html__( 'Text / Icon', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [
				'{{WRAPPER}} .ecs-dms-btn'            => 'color: {{VALUE}};',
				'{{WRAPPER}} .ecs-dms-btn svg'        => 'fill: {{VALUE}};',
			],
		] );

		$this->add_control( 'btn_color_bg_normal', [
			'label'     => esc_html__( 'Background', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn' => 'background-color: {{VALUE}};' ],
		] );

		$this->add_control( 'btn_color_border_normal', [
			'label'     => esc_html__( 'Border', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn' => 'border-color: {{VALUE}};' ],
		] );

		$this->add_control( 'btn_color_bg_hover', [
			'label'     => esc_html__( 'Background Hover', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn:hover:not(.is-active)' => 'background-color: {{VALUE}};' ],
		] );

		$this->end_controls_tab();

		// Active (Dark Mode)
		$this->start_controls_tab( 'btn_colors_active', [
			'label' => esc_html__( 'Active', 'ele-custom-skin' ),
		] );

		$this->add_control( 'btn_color_text_active', [
			'label'     => esc_html__( 'Text / Icon', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [
				'{{WRAPPER}} .ecs-dms-btn.is-active'     => 'color: {{VALUE}};',
				'{{WRAPPER}} .ecs-dms-btn.is-active svg' => 'fill: {{VALUE}};',
			],
		] );

		$this->add_control( 'btn_color_bg_active', [
			'label'     => esc_html__( 'Background', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn.is-active' => 'background-color: {{VALUE}};' ],
		] );

		$this->add_control( 'btn_color_border_active', [
			'label'     => esc_html__( 'Border', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn.is-active' => 'border-color: {{VALUE}};' ],
		] );

		$this->add_control( 'btn_color_bg_active_hover', [
			'label'     => esc_html__( 'Background Hover', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-btn.is-active:hover' => 'background-color: {{VALUE}};' ],
		] );

		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	// ── Style: Dropdown ──────────────────────────────────────────────────────

	private function register_style_dropdown_controls(): void {

		$this->start_controls_section( 'section_style_dd', [
			'label'     => esc_html__( 'Dropdown', 'ele-custom-skin' ),
			'tab'       => Controls_Manager::TAB_STYLE,
			'condition' => [ 'display_type' => 'dropdown' ],
		] );

		$this->add_responsive_control( 'dd_align', [
			'label'     => esc_html__( 'Alignment', 'ele-custom-skin' ),
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'flex-start' => [ 'title' => esc_html__( 'Left', 'ele-custom-skin' ),   'icon' => 'eicon-text-align-left' ],
				'center'     => [ 'title' => esc_html__( 'Center', 'ele-custom-skin' ), 'icon' => 'eicon-text-align-center' ],
				'flex-end'   => [ 'title' => esc_html__( 'Right', 'ele-custom-skin' ),  'icon' => 'eicon-text-align-right' ],
			],
			'default'   => 'flex-start',
			'selectors' => [ '{{WRAPPER}} .ecs-dms-wrap' => 'justify-content: {{VALUE}};' ],
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'dd_typography',
			'selector' => '{{WRAPPER}} .ecs-dms-select',
		] );

		$this->add_control( 'dd_color_text', [
			'label'     => esc_html__( 'Text Color', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-select' => 'color: {{VALUE}};' ],
		] );

		$this->add_control( 'dd_color_bg', [
			'label'     => esc_html__( 'Background', 'ele-custom-skin' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ecs-dms-select' => 'background-color: {{VALUE}};' ],
		] );

		$this->add_responsive_control( 'dd_padding', [
			'label'      => esc_html__( 'Padding', 'ele-custom-skin' ),
			'type'       => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em' ],
			'selectors'  => [
				'{{WRAPPER}} .ecs-dms-select' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );

		$this->add_responsive_control( 'dd_border_radius', [
			'label'      => esc_html__( 'Border Radius', 'ele-custom-skin' ),
			'type'       => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%' ],
			'selectors'  => [
				'{{WRAPPER}} .ecs-dms-select' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );

		$this->add_group_control( Group_Control_Border::get_type(), [
			'name'     => 'dd_border',
			'selector' => '{{WRAPPER}} .ecs-dms-select',
		] );

		$this->end_controls_section();
	}

	// ── Render ────────────────────────────────────────────────────────────────

	protected function render(): void {
		$s       = $this->get_settings_for_display();
		$display = $s['display_type'];
		$is_dark = ! empty( $_COOKIE['ecs_color_scheme'] ) && $_COOKIE['ecs_color_scheme'] === 'alt';

		$wrap_cls = 'ecs-dms-wrap ecs-dms-type-' . esc_attr( $display );
		if ( $is_dark ) {
			$wrap_cls .= ' is-alt';
		}
		?>
		<div class="<?php echo esc_attr( $wrap_cls ); ?>" data-display="<?php echo esc_attr( $display ); ?>">
			<?php
			if ( 'dropdown' === $display ) {
				$this->render_dropdown( $s, $is_dark );
			} elseif ( 'dual' === $display ) {
				$this->render_dual( $s, $is_dark );
			} else {
				$this->render_toggle( $s, $is_dark );
			}
			?>
		</div>
		<?php
	}

	private function render_toggle( array $s, bool $is_dark ): void {
		?>
		<button class="ecs-dms-btn<?php echo $is_dark ? ' is-active' : ''; ?>"
		        type="button"
		        aria-label="<?php esc_attr_e( 'Toggle colour scheme', 'ele-custom-skin' ); ?>">
			<span class="ecs-dms-state ecs-dms-state-light">
				<?php $this->render_btn_content( $s, 'light' ); ?>
			</span>
			<span class="ecs-dms-state ecs-dms-state-dark">
				<?php $this->render_btn_content( $s, 'dark' ); ?>
			</span>
		</button>
		<?php
	}

	private function render_dual( array $s, bool $is_dark ): void {
		?>
		<button class="ecs-dms-btn ecs-dms-btn-light<?php echo ! $is_dark ? ' is-active' : ''; ?>"
		        type="button" data-scheme="default"
		        aria-pressed="<?php echo ! $is_dark ? 'true' : 'false'; ?>">
			<?php $this->render_btn_content( $s, 'light' ); ?>
		</button>
		<button class="ecs-dms-btn ecs-dms-btn-dark<?php echo $is_dark ? ' is-active' : ''; ?>"
		        type="button" data-scheme="alt"
		        aria-pressed="<?php echo $is_dark ? 'true' : 'false'; ?>">
			<?php $this->render_btn_content( $s, 'dark' ); ?>
		</button>
		<?php
	}

	private function render_dropdown( array $s, bool $is_dark ): void {
		?>
		<select class="ecs-dms-select" aria-label="<?php esc_attr_e( 'Colour scheme', 'ele-custom-skin' ); ?>">
			<option value="default"<?php selected( ! $is_dark ); ?>>
				<?php echo esc_html( $s['label_light'] ); ?>
			</option>
			<option value="alt"<?php selected( $is_dark ); ?>>
				<?php echo esc_html( $s['label_dark'] ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Render icon + label for one state (light or dark), respecting icon_position.
	 */
	private function render_btn_content( array $s, string $state ): void {
		$icon_pos  = $s['icon_position'] ?? 'before';
		$label     = esc_html( $s[ 'label_' . $state ] ?? '' );
		$icon      = $s[ 'icon_' . $state ] ?? [];
		$has_icon  = ! empty( $icon['value'] );
		$has_label = $label !== '';

		$icon_html = '';
		if ( $has_icon ) {
			ob_start();
			echo '<span class="ecs-dms-icon">';
			Icons_Manager::render_icon( $icon, [ 'aria-hidden' => 'true' ] );
			echo '</span>';
			$icon_html = ob_get_clean();
		}

		if ( 'after' === $icon_pos ) {
			if ( $has_label ) {
				echo '<span class="ecs-dms-label">' . $label . '</span>'; // phpcs:ignore
			}
			echo $icon_html; // phpcs:ignore
		} else {
			echo $icon_html; // phpcs:ignore
			if ( $has_label ) {
				echo '<span class="ecs-dms-label">' . $label . '</span>'; // phpcs:ignore
			}
		}
	}
}
