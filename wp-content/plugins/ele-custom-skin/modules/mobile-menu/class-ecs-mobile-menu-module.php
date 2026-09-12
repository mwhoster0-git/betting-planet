<?php
/**
 * Module: WordPress Menu Responsive Controls
 *
 * Extends the built-in Elementor Pro "WordPress Menu" (nav-menu) widget
 * by upgrading Layout, Alignment, Pointer, and Animation controls to
 * responsive (Desktop / Tablet / Mobile) via Elementor's control API.
 *
 * No separate DTE widget — the module only hooks into the existing widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;

class ECS_Mobile_Menu_Module extends ECS_Module_Base {

	public function get_id(): string {
		return 'mobile_menu';
	}

	public function get_title(): string {
		return __( 'WordPress Menu Responsive', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Makes layout, alignment, and animation controls on the Nav Menu widget responsive — set different values for desktop, tablet, and mobile.', 'ele-custom-skin' );
	}

	public function boot(): void {
		add_action(
			'elementor/element/nav-menu/section_layout/before_section_end',
			[ $this, 'upgrade_nav_menu_controls' ],
			10,
			2
		);

		add_action(
			'elementor/element/nav-menu/section_style_main-menu/before_section_end',
			[ $this, 'upgrade_nav_menu_style_controls' ],
			10,
			2
		);
	}

	/**
	 * Upgrade Layout, Alignment, Pointer, and Animation controls
	 * of the nav-menu widget to responsive variants.
	 *
	 * Call order matters: each upgrade uses the previous control's ID
	 * as its position anchor so controls stay in their original place.
	 *
	 * @param \Elementor\Element_Base $element
	 * @param array                   $args
	 */
	public function upgrade_nav_menu_controls( $element, $args ): void {
		$this->upgrade_layout( $element );
		$this->upgrade_alignment( $element );
		$this->upgrade_pointer( $element );
		$this->upgrade_animations( $element );
		$this->fix_mobile_dropdown_conditions( $element );
	}

	// ── Layout ────────────────────────────────────────────────────────────────

	private function upgrade_layout( $element ): void {
		$element->remove_control( 'layout' );

		$element->add_responsive_control(
			'layout',
			[
				'label'              => esc_html__( 'Layout', 'elementor-pro' ),
				'type'               => Controls_Manager::SELECT,
				'default'            => 'horizontal',
				'options'            => [
					'horizontal' => esc_html__( 'Horizontal', 'elementor-pro' ),
					'vertical'   => esc_html__( 'Vertical',   'elementor-pro' ),
					'dropdown'   => esc_html__( 'Dropdown',   'elementor-pro' ),
				],
				// prefix_class marks this widget as "DTE-controlled" so the CSS
				// variable rules in ecs-mobile-menu.css apply only to it.
				// NOTE: Elementor PHP does NOT add breakpoint prefixes (tablet-/mobile-)
				// to prefix_class values, so ALL breakpoint values are added as
				// ecs-nav-layout-{value} without prefix. The actual responsive
				// behaviour is handled via CSS custom properties (selectors below).
				'prefix_class'       => 'ecs-nav-layout-',
				'render_type'        => 'template',
				'frontend_available' => true,
				// CSS custom properties — Elementor generates proper @media rules
				// AND body.elementor-device-* rules (for editor preview) from these.
				'selectors_dictionary' => [
					'horizontal' => '--ecs-nav-main-display:flex;--ecs-nav-main-dir:row;--ecs-nav-toggle-display:none',
					'vertical'   => '--ecs-nav-main-display:flex;--ecs-nav-main-dir:column;--ecs-nav-toggle-display:none',
					'dropdown'   => '--ecs-nav-main-display:none;--ecs-nav-main-dir:row;--ecs-nav-toggle-display:flex',
				],
				'selectors'          => [
					'{{WRAPPER}}' => '{{VALUE}}',
				],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'menu' ] ]
		);
	}

	// ── Alignment ─────────────────────────────────────────────────────────────

	private function upgrade_alignment( $element ): void {
		$element->remove_control( 'align_items' );

		$element->add_responsive_control(
			'align_items',
			[
				'label'                => esc_html__( 'Alignment', 'elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => [
					'flex-start'    => [
						'title' => esc_html__( 'Start', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center'        => [
						'title' => esc_html__( 'Center', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-center',
					],
					'flex-end'      => [
						'title' => esc_html__( 'End', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-right',
					],
					'space-between' => [
						'title' => esc_html__( 'Stretch', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-justify',
					],
				],
				// Direct selectors — Elementor generates scoped per-element CSS with
				// proper @media breakpoints. When no value is set for a breakpoint,
				// Elementor emits no rule, so native Elementor alignment is preserved.
				// (Previous CSS-variable approach used a flex-start fallback that
				// overwrote the user's existing alignment when the CSS cache was stale.)
				//
				// space-between also sets --ecs-nav-li-grow:1 so <li> items expand to
				// fill equal widths (equivalent to Elementor native flex-grow:1 on >li).
				// All values must be mapped; unmapped values would output the raw key
				// as CSS (e.g. "flex-start;" alone), which is invalid.
				'selectors_dictionary' => [
					'flex-start'    => 'justify-content:flex-start',
					'center'        => 'justify-content:center',
					'flex-end'      => 'justify-content:flex-end',
					'space-between' => 'justify-content:space-between;--ecs-nav-li-grow:1',
				],
				// Also targets .elementor-nav-menu--dropdown .elementor-nav-menu so
				// alignment still applies when the native Elementor Breakpoint (not
				// the ECS Layout control) switches this horizontal/vertical menu into
				// its dropdown/burger state. Without this, Elementor Pro's own
				// .elementor-nav-menu__align-{value} CSS would have covered both
				// variants, but that class is no longer added since this control
				// replaces the native one.
				'selectors' => [
					'{{WRAPPER}} .elementor-nav-menu--main,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-nav-menu,
					 {{WRAPPER}} .elementor-nav-menu--dropdown .elementor-nav-menu' => '{{VALUE}};',
				],
				'condition'            => [ 'layout!' => 'dropdown' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'layout' ] ]
		);
	}

	// ── Pointer ───────────────────────────────────────────────────────────────

	private function upgrade_pointer( $element ): void {
		$element->remove_control( 'pointer' );

		// Re-add pointer as non-responsive to preserve prefix_class:'e--pointer-'.
		// With a responsive control, Elementor adds ALL device values as classes
		// simultaneously (e.g. e--pointer-underline + e--pointer-none), causing the
		// 'none' class to cancel out the active pointer type on desktop.
		$element->add_control(
			'pointer',
			[
				'label'          => esc_html__( 'Pointer', 'elementor-pro' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => 'underline',
				'options'        => [
					'none'        => esc_html__( 'None',        'elementor-pro' ),
					'underline'   => esc_html__( 'Underline',   'elementor-pro' ),
					'overline'    => esc_html__( 'Overline',    'elementor-pro' ),
					'double-line' => esc_html__( 'Double Line', 'elementor-pro' ),
					'framed'      => esc_html__( 'Framed',      'elementor-pro' ),
					'background'  => esc_html__( 'Background',  'elementor-pro' ),
					'text'        => esc_html__( 'Text',        'elementor-pro' ),
				],
				'prefix_class'   => 'e--pointer-',
				'style_transfer' => true,
				'render_type'    => 'template',
				'condition'      => [ 'layout!' => 'dropdown' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'align_items' ] ]
		);

		// Separate responsive control: hide pointer on specific devices.
		// --ecs-nav-ptr-vis (default: visible) is read by ecs-mobile-menu.css.
		$element->add_responsive_control(
			'ecs_pointer_hide',
			[
				'label'                => esc_html__( 'Hide Pointer', 'ele-custom-skin' ),
				'type'                 => Controls_Manager::SWITCHER,
				'label_on'             => esc_html__( 'Hidden',  'ele-custom-skin' ),
				'label_off'            => esc_html__( 'Visible', 'ele-custom-skin' ),
				'selectors_dictionary' => [ 'yes' => '--ecs-nav-ptr-vis:hidden' ],
				'selectors'            => [ '{{WRAPPER}}' => '{{VALUE}}' ],
				'condition'            => [
					'layout!'  => 'dropdown',
					'pointer!' => 'none',
				],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'pointer' ] ]
		);
	}

	// ── Animation controls ─────────────────────────────────────────────────────

	private function upgrade_animations( $element ): void {
		$target = '{{WRAPPER}} .elementor-nav-menu--main .elementor-item::before,
		            {{WRAPPER}} .elementor-nav-menu--main .elementor-item::after,
		            {{WRAPPER}} .elementor-nav-menu--main .elementor-item';

		$none_css = [ 'none' => 'transition-duration:0s!important;animation:none!important;' ];

		// Line pointer animations (underline / overline / double-line)
		$element->remove_control( 'animation_line' );
		$element->add_responsive_control(
			'animation_line',
			[
				'label'                => esc_html__( 'Animation', 'elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'fade',
				'options'              => [
					'fade'     => 'Fade',
					'slide'    => 'Slide',
					'grow'     => 'Grow',
					'drop-in'  => 'Drop In',
					'drop-out' => 'Drop Out',
					'none'     => 'None',
				],
				// prefix_class activates Elementor Pro's animation CSS (e--animation-fade etc.)
				'prefix_class'         => 'e--animation-',
				'render_type'          => 'template',
				'condition'            => [
					'layout!'  => 'dropdown',
					'pointer'  => [ 'underline', 'overline', 'double-line' ],
				],
				'selectors_dictionary' => $none_css,
				'selectors'            => [ $target => '{{VALUE}}' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'pointer' ] ]
		);

		// Framed pointer animation
		$element->remove_control( 'animation_framed' );
		$element->add_responsive_control(
			'animation_framed',
			[
				'label'                => esc_html__( 'Animation', 'elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'fade',
				'options'              => [
					'fade'    => 'Fade',
					'grow'    => 'Grow',
					'shrink'  => 'Shrink',
					'draw'    => 'Draw',
					'corners' => 'Corners',
					'none'    => 'None',
				],
				'prefix_class'         => 'e--animation-',
				'render_type'          => 'template',
				'condition'            => [
					'layout!'  => 'dropdown',
					'pointer'  => 'framed',
				],
				'selectors_dictionary' => $none_css,
				'selectors'            => [ $target => '{{VALUE}}' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'animation_line' ] ]
		);

		// Background pointer animation
		$element->remove_control( 'animation_background' );
		$element->add_responsive_control(
			'animation_background',
			[
				'label'                => esc_html__( 'Animation', 'elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'fade',
				'options'              => [
					'fade'                   => 'Fade',
					'grow'                   => 'Grow',
					'shrink'                 => 'Shrink',
					'sweep-left'             => 'Sweep Left',
					'sweep-right'            => 'Sweep Right',
					'sweep-up'               => 'Sweep Up',
					'sweep-down'             => 'Sweep Down',
					'shutter-in-vertical'    => 'Shutter In Vertical',
					'shutter-out-vertical'   => 'Shutter Out Vertical',
					'shutter-in-horizontal'  => 'Shutter In Horizontal',
					'shutter-out-horizontal' => 'Shutter Out Horizontal',
					'none'                   => 'None',
				],
				'prefix_class'         => 'e--animation-',
				'render_type'          => 'template',
				'condition'            => [
					'layout!'  => 'dropdown',
					'pointer'  => 'background',
				],
				'selectors_dictionary' => $none_css,
				'selectors'            => [ $target => '{{VALUE}}' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'animation_framed' ] ]
		);

		// Text pointer animation
		$element->remove_control( 'animation_text' );
		$element->add_responsive_control(
			'animation_text',
			[
				'label'                => esc_html__( 'Animation', 'elementor-pro' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => 'grow',
				'options'              => [
					'grow'   => 'Grow',
					'shrink' => 'Shrink',
					'sink'   => 'Sink',
					'float'  => 'Float',
					'skew'   => 'Skew',
					'rotate' => 'Rotate',
					'none'   => 'None',
				],
				'prefix_class'         => 'e--animation-',
				'render_type'          => 'template',
				'condition'            => [
					'layout!'  => 'dropdown',
					'pointer'  => 'text',
				],
				'selectors_dictionary' => $none_css,
				'selectors'            => [ $target => '{{VALUE}}' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'animation_background' ] ]
		);
	}

	// ── Mobile Dropdown conditions fix ────────────────────────────────────────

	/**
	 * Remove the 'dropdown!' => 'none' condition from full_width, text_align,
	 * toggle, and toggle_align so they remain visible when Breakpoint = None.
	 * This allows using DTE's Layout=Dropdown per-device without losing access
	 * to the toggle button and dropdown settings.
	 */
	private function fix_mobile_dropdown_conditions( $element ): void {

		// full_width
		$element->remove_control( 'full_width' );
		$element->add_control(
			'full_width',
			[
				'label'              => esc_html__( 'Full Width', 'elementor-pro' ),
				'type'               => Controls_Manager::SWITCHER,
				'description'        => esc_html__( 'Stretch the dropdown of the menu to full width.', 'elementor-pro' ),
				'prefix_class'       => 'elementor-nav-menu--',
				'return_value'       => 'stretch',
				'frontend_available' => true,
				// no 'dropdown!' => 'none' condition
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'dropdown' ] ]
		);

		// ecs_dropdown_width — visible only when Full Width is OFF
		$element->add_control(
			'ecs_dropdown_width',
			[
				'label'      => esc_html__( 'Dropdown Width', 'ele-custom-skin' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'vw' ],
				'range'      => [
					'px'  => [ 'min' => 50,  'max' => 1000, 'step' => 1  ],
					'%'   => [ 'min' => 10,  'max' => 100,  'step' => 1  ],
					'vw'  => [ 'min' => 10,  'max' => 100,  'step' => 1  ],
				],
				'condition'  => [ 'full_width!' => 'stretch' ],
				// !important needed to override width:auto !important from ecs-mobile-menu.css
				'selectors'  => [
					'{{WRAPPER}} .elementor-nav-menu--dropdown.elementor-nav-menu__container' => 'width: {{SIZE}}{{UNIT}} !important; min-width: 0 !important;',
				],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'full_width' ] ]
		);

		// force_breakpoint — force dropdown at Elementor's native Breakpoint,
		// regardless of the DTE Layout setting for tablet/mobile.
		$element->add_control(
			'ecs_force_breakpoint',
			[
				'label'              => esc_html__( 'Force Breakpoint', 'ele-custom-skin' ),
				'type'               => Controls_Manager::SWITCHER,
				'description'        => esc_html__( 'Force dropdown mode at the selected Breakpoint, regardless of the Layout setting.', 'ele-custom-skin' ),
				'prefix_class'       => 'ecs-',
				'return_value'       => 'force-breakpoint',
				'frontend_available' => true,
				'condition'          => [ 'dropdown!' => 'none' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'ecs_dropdown_width' ] ]
		);

		// text_align — replaced with Left/Center/Right CHOOSE control
		$element->remove_control( 'text_align' );
		$element->add_control(
			'text_align',
			[
				'label'     => esc_html__( 'Text Align', 'elementor-pro' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'left',
				'options'   => [
					'left'   => [
						'title' => esc_html__( 'Left', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'elementor-pro' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				// .elementor-item is display:flex → text-align has no effect.
				// justify-content controls horizontal alignment of flex children.
				'selectors_dictionary' => [
					'left'   => 'flex-start',
					'center' => 'center',
					'right'  => 'flex-end',
				],
				'selectors' => [
					'{{WRAPPER}} .elementor-nav-menu--dropdown .elementor-item,
					 {{WRAPPER}} .elementor-nav-menu--dropdown .elementor-sub-item' => 'justify-content: {{VALUE}}',
				],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'ecs_force_breakpoint' ] ]
		);

		// toggle
		$element->remove_control( 'toggle' );
		$element->add_control(
			'toggle',
			[
				'label'              => esc_html__( 'Toggle Button', 'elementor-pro' ),
				'type'               => Controls_Manager::SELECT,
				'default'            => 'burger',
				'options'            => [
					''       => esc_html__( 'None', 'elementor-pro' ),
					'burger' => esc_html__( 'Hamburger', 'elementor-pro' ),
				],
				'prefix_class'       => 'elementor-nav-menu--toggle elementor-nav-menu--',
				'render_type'        => 'template',
				'frontend_available' => true,
				// no 'dropdown!' => 'none' condition
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'text_align' ] ]
		);

		// toggle_align — keep 'toggle!' condition, remove 'dropdown!' condition.
		// prefix_class 'ecs-toggle-align-' adds ecs-toggle-align-{left|center|right}
		// to the wrapper so CSS can align the dropdown panel to match.
		$element->remove_control( 'toggle_align' );
		$element->add_control(
			'toggle_align',
			[
				'label'                => esc_html__( 'Toggle Align', 'elementor-pro' ),
				'type'                 => Controls_Manager::CHOOSE,
				'default'              => 'center',
				'options'              => [
					'left'   => [
						'title' => esc_html__( 'Left', 'elementor-pro' ),
						'icon'  => 'eicon-h-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'elementor-pro' ),
						'icon'  => 'eicon-h-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'elementor-pro' ),
						'icon'  => 'eicon-h-align-right',
					],
				],
				'prefix_class'         => 'ecs-toggle-align-',
				'selectors_dictionary' => [
					'left'   => 'margin-right: auto',
					'center' => 'margin: 0 auto',
					'right'  => 'margin-left: auto',
				],
				'selectors'            => [
					'{{WRAPPER}} .elementor-menu-toggle' => '{{VALUE}}',
				],
				'condition'            => [ 'toggle!' => '' ], // removed 'dropdown!' => 'none'
				'separator'            => 'before',
			]
			// no position → goes at end of section, after the nav_icon_options tabs
		);
	}

	// ── Style tab upgrades ────────────────────────────────────────────────────

	public function upgrade_nav_menu_style_controls( $element, $args ): void {
		$this->upgrade_colors( $element );
	}

	private function upgrade_colors( $element ): void {
		$tabs = 'tabs_menu_item_style';

		// Normal tab
		$element->remove_control( 'color_menu_item' );
		$element->add_responsive_control(
			'color_menu_item',
			[
				'label'        => esc_html__( 'Text Color', 'elementor-pro' ),
				'type'         => Controls_Manager::COLOR,
				'default'      => '',
				'render_type'  => 'ui',
				'tabs_wrapper' => $tabs,
				'inner_tab'    => 'tab_menu_item_normal',
				'selectors'    => [
					'{{WRAPPER}} .elementor-nav-menu--main .elementor-item' => 'color: {{VALUE}}; fill: {{VALUE}};',
				],
			],
			);
		// No position arg — inner_tab places it in the correct tab; all other Normal tab
		// controls are removed by ECS so order within the tab doesn't matter.

		// Hover tab
		$element->remove_control( 'color_menu_item_hover' );
		$element->add_responsive_control(
			'color_menu_item_hover',
			[
				'label'        => esc_html__( 'Text Color', 'elementor-pro' ),
				'type'         => Controls_Manager::COLOR,
				'render_type'  => 'ui',
				'tabs_wrapper' => $tabs,
				'inner_tab'    => 'tab_menu_item_hover',
				'selectors'    => [
					'{{WRAPPER}} .elementor-nav-menu--main .elementor-item:hover,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item.elementor-item-active,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item.highlighted,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item:focus' => 'color: {{VALUE}}; fill: {{VALUE}};',
				],
				'condition'    => [ 'pointer!' => 'background' ],
			],
			// pointer_color_menu_item_hover has both tabs_wrapper and inner_tab set,
			// so it's safe as a position anchor (avoids the undefined 'inner_tab' warning
			// that tab-type controls trigger in Elementor's get_position_info()).
			[ 'position' => [ 'type' => 'control', 'at' => 'before', 'of' => 'pointer_color_menu_item_hover' ] ]
		);

		$element->remove_control( 'color_menu_item_hover_pointer_bg' );
		$element->add_responsive_control(
			'color_menu_item_hover_pointer_bg',
			[
				'label'        => esc_html__( 'Text Color', 'elementor-pro' ),
				'type'         => Controls_Manager::COLOR,
				'default'      => '#fff',
				'render_type'  => 'ui',
				'tabs_wrapper' => $tabs,
				'inner_tab'    => 'tab_menu_item_hover',
				'selectors'    => [
					'{{WRAPPER}} .elementor-nav-menu--main .elementor-item:hover,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item.elementor-item-active,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item.highlighted,
					 {{WRAPPER}} .elementor-nav-menu--main .elementor-item:focus' => 'color: {{VALUE}}',
				],
				'condition'    => [ 'pointer' => 'background' ],
			],
			[ 'position' => [ 'type' => 'control', 'at' => 'after', 'of' => 'color_menu_item_hover' ] ]
		);

		// Active tab
		$element->remove_control( 'color_menu_item_active' );
		$element->add_responsive_control(
			'color_menu_item_active',
			[
				'label'        => esc_html__( 'Text Color', 'elementor-pro' ),
				'type'         => Controls_Manager::COLOR,
				'default'      => '',
				'render_type'  => 'ui',
				'tabs_wrapper' => $tabs,
				'inner_tab'    => 'tab_menu_item_active',
				'selectors'    => [
					'{{WRAPPER}} .elementor-nav-menu--main .elementor-item.elementor-item-active' => 'color: {{VALUE}}',
				],
			],
			// pointer_color_menu_item_active has both tabs_wrapper and inner_tab — safe anchor.
			[ 'position' => [ 'type' => 'control', 'at' => 'before', 'of' => 'pointer_color_menu_item_active' ] ]
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'ecs-mobile-menu',
			$this->module_asset_url( 'assets/css/ecs-mobile-menu.css' ),
			[],
			ECS_VERSION
		);

		// Loaded in <head> (no in_footer) so our DOMContentLoaded listener is
		// registered before Elementor Pro's scripts (which load in the footer).
		// The script reads the computed ECS CSS variables to determine the active
		// layout per viewport and applies the correct elementor-nav-menu--layout-*
		// class so pointer animations and dropdown positioning both work correctly.
		wp_enqueue_script(
			'ecs-mobile-menu',
			$this->module_asset_url( 'assets/js/ecs-mobile-menu.js' ),
			[],
			ECS_VERSION,
			false
		);
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-mobile-menu',
			$this->module_asset_url( 'assets/css/ecs-mobile-menu.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-mobile-menu-editor',
			$this->module_asset_url( 'assets/js/ecs-mobile-menu-editor.js' ),
			[],
			ECS_VERSION,
			true
		);
	}
}
