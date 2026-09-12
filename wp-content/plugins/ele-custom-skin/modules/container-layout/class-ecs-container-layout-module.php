<?php
/**
 * Module: Container Content Layout
 *
 * Provides:
 *  - Layout modes on Elementor Containers: inherit | slider | custom_layout
 *  - DTE Custom Layout template type (ecs_custom_layout)
 *  - DTE Container Placeholder widget
 *  - Child injection: container's rendered children are captured via output
 *    buffering, extracted from .e-con-inner, and passed to the placeholder
 *    widget inside the selected DTE Custom Layout template.
 *  - Recursion guard via ECS_Container_Placeholder_Widget::is_rendering()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Container_Layout_Module extends ECS_Module_Base {

	public function get_id(): string {
		return 'container_layout';
	}

	public function get_title(): string {
		return __( 'Container Content Layout', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Two extra layout modes for containers: Slider (CSS-only) and Custom Layout (distribute children into a template via placeholder widgets).', 'ele-custom-skin' );
	}

	public function boot(): void {
		// Normalize template_type slug before Elementor's new-post handler validates it.
		// Elementor Pro's "Add New Template" modal sends the display title ("DTE Custom Layout")
		// instead of the registered slug ("ecs_custom_layout") for custom document types.
		add_action( 'admin_init', [ $this, 'fix_new_post_template_type' ], 1 );

		// Register the custom document type (template type)
		add_action( 'elementor/documents/register', [ $this, 'register_document_types' ] );

		// Add layout controls to every Container element.
		// We use TWO hooks:
		//  - after_section_start (priority 5) → fallback simple ecs_container_type control,
		//    active ONLY when the Responsive Container Layout module is not enabled.
		//    When that module IS active, it registers the responsive version at priority 10
		//    and this callback does nothing.
		//  - before_section_end  → inject DTE slider/custom-specific controls LAST
		add_action( 'elementor/element/container/section_layout_container/after_section_start',  [ $this, 'maybe_add_fallback_type_control' ], 5, 2 );
		add_action( 'elementor/element/container/section_layout_container/before_section_end', [ $this, 'add_container_controls' ], 10, 2 );

		// Slider style controls (arrows + dots) in the Style tab
		add_action( 'elementor/element/container/section_border/after_section_end',
			[ $this, 'add_slider_style_controls' ], 10, 2 );

		// Intercept container render for layout injection.
		// No should_render override — third-party conditional display (EA, etc.) must work for all containers.
		add_action( 'elementor/frontend/container/before_render', [ $this, 'before_container_render' ] );
		add_action( 'elementor/frontend/container/after_render',  [ $this, 'after_container_render' ] );

		// When a DTE Custom Layout template is saved, bust the element cache for
		// all pages — their cached HTML may contain the old template output.
		add_action( 'elementor/document/after_save', [ $this, 'on_custom_layout_saved' ] );

		// AJAX endpoint: render template with injected children for the editor preview.
		add_action( 'wp_ajax_ecs_preview_layout', [ $this, 'ajax_preview_layout' ] );
	}

	public function register_widgets( $widgets_manager ): void {
		require_once $this->module_path() . 'widgets/class-ecs-container-placeholder-widget.php';
		$widgets_manager->register( new ECS_Container_Placeholder_Widget() );
	}

	/**
	 * When a DTE Custom Layout template is saved, delete the Elementor element
	 * cache for all posts so the next frontend request rebuilds with the new template.
	 *
	 * We use delete_post_meta_by_key() because we have no reverse-index of which
	 * pages use which template — a global bust is safe and inexpensive (cache
	 * rebuilds on next load, just like after a regular Elementor publish).
	 */
	public function on_custom_layout_saved( $document ): void {
		if ( ECS_Custom_Layout_Document::get_type() !== $document->get_type() ) {
			return;
		}
		delete_post_meta_by_key( \Elementor\Core\Base\Document::CACHE_META_KEY );
	}

	/**
	 * Normalize the template_type request parameter for the elementor_new_post action.
	 *
	 * Elementor Pro's "Add New Template" modal passes the document's display title
	 * ("DTE Custom Layout") as template_type instead of the registered slug
	 * ("ecs_custom_layout"). We catch this at priority 1 — before Elementor's own
	 * admin_init handler — and rewrite all three superglobals so validation passes.
	 */
	public function fix_new_post_template_type(): void {
		if ( empty( $_REQUEST['action'] ) || 'elementor_new_post' !== $_REQUEST['action'] ) {
			return;
		}
		if ( empty( $_REQUEST['template_type'] ) ) {
			return;
		}

		$raw        = sanitize_text_field( wp_unslash( $_REQUEST['template_type'] ) );
		$normalized = strtolower( preg_replace( '/[\s-]+/', '_', $raw ) );

		if ( 'ecs_custom_layout' === $normalized && 'ecs_custom_layout' !== $raw ) {
			$_REQUEST['template_type'] = 'ecs_custom_layout';
			$_GET['template_type']     = 'ecs_custom_layout';
			$_POST['template_type']    = 'ecs_custom_layout';
		}
	}

	/**
	 * Register the DTE Custom Layout document type.
	 */
	public function register_document_types( $documents_manager ): void {
		require_once $this->module_path() . 'class-ecs-custom-layout-document.php';
		$documents_manager->register_document_type( 'ecs_custom_layout', ECS_Custom_Layout_Document::class );
	}

	/**
	 * Fallback container type control — used only when the Responsive Container Layout
	 * module is NOT active.  Adds a simple (non-responsive) ecs_container_type SELECT
	 * so Slider and Custom Layout modes can still be selected at the desktop level.
	 *
	 * When the Responsive Container Layout module IS active it registers the full
	 * responsive version of this control at priority 10 (after this priority-5 hook).
	 * In that case this method exits early so the control is not registered twice.
	 */
	public function maybe_add_fallback_type_control( $element, $args ): void {
		if ( ECS_Core::instance()->modules()->is_active( 'container_responsive' ) ) {
			return; // Responsive module handles ecs_container_type.
		}

		$element->update_control( 'container_type', [ 'classes' => 'ecs-hidden-control' ] );

		$element->add_control(
			'ecs_container_type',
			[
				'label'              => esc_html__( 'Container Layout', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SELECT,
				'default'            => 'flex',
				'options'            => [
					'flex'   => esc_html__( 'Flexbox', 'ele-custom-skin' ),
					'grid'   => esc_html__( 'Grid', 'ele-custom-skin' ),
					'slider' => esc_html__( 'Slider', 'ele-custom-skin' ),
					'custom' => esc_html__( 'Custom Layout', 'ele-custom-skin' ),
				],
				'prefix_class'       => 'e-ecs-',
				'frontend_available' => true,
			]
		);
	}

	public function add_container_type_control( $element, $args ): void {
		// Hide the built-in Container Layout control — replaced by our responsive ecs_container_type.
		// prefix_class uses sprintf() format: desktop → 'e-ecs-', tablet → 'e-ecs-tablet-', mobile → 'e-ecs-mobile-'
		// This generates the same CSS classes the stylesheet already uses.
		$element->update_control( 'container_type', [ 'classes' => 'ecs-hidden-control' ] );

		$element->add_responsive_control(
			'ecs_container_type',
			[
				'label'              => esc_html__( 'Container Layout', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SELECT,
				'default'            => 'flex',
				'tablet_default'     => '',
				'mobile_default'     => '',
				'options'            => [
					''       => esc_html__( '— Inherit —', 'ele-custom-skin' ),
					'flex'   => esc_html__( 'Flexbox', 'ele-custom-skin' ),
					'grid'   => esc_html__( 'Grid', 'ele-custom-skin' ),
					'slider' => esc_html__( 'Slider', 'ele-custom-skin' ),
					'custom' => esc_html__( 'Custom Layout', 'ele-custom-skin' ),
				],
				'prefix_class'       => 'e-ecs%s-',
				'frontend_available' => true,
			]
		);
	}

	public function add_container_controls( $element, $args ): void {

		// ecs_active_type is a virtual key set by JS (ecs-editor-preview.js) to reflect
		// the effective container type for the currently active device mode.
		// All dependent controls condition on this key so Elementor re-evaluates them
		// automatically when JS calls container.settings.set('ecs_active_type', type).

		// ── Slider settings ───────────────────────────────────────────────────
		$element->add_responsive_control(
			'ecs_slider_columns',
			[
				'label'              => esc_html__( 'Slides Visible', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SELECT,
				'default'            => '1',
				'tablet_default'     => '',
				'mobile_default'     => '',
				'options'            => [
					''  => esc_html__( '— Inherit —', 'ele-custom-skin' ),
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				],
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'selectors'          => [
					'{{WRAPPER}}' => '--ecs-slider-columns: {{VALUE}};',
				],
				'separator'          => 'before',
				'frontend_available' => true,
			]
		);

		// ── Slider behavior ───────────────────────────────────────────────────
		$element->add_control(
			'ecs_loop',
			[
				'label'              => esc_html__( 'Infinite Loop', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SWITCHER,
				'default'            => 'yes',
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_autoplay',
			[
				'label'              => esc_html__( 'Autoplay', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SWITCHER,
				'default'            => '',
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_autoplay_speed',
			[
				'label'              => esc_html__( 'Autoplay Speed (ms)', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::NUMBER,
				'default'            => 3000,
				'condition'          => [ 'ecs_active_type' => 'slider', 'ecs_autoplay' => 'yes' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_pause_on_hover',
			[
				'label'              => esc_html__( 'Pause on Hover', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SWITCHER,
				'default'            => 'yes',
				'condition'          => [ 'ecs_active_type' => 'slider', 'ecs_autoplay' => 'yes' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_speed',
			[
				'label'              => esc_html__( 'Transition Speed (ms)', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::NUMBER,
				'default'            => 500,
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_space_between',
			[
				'label'              => esc_html__( 'Space Between (px)', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::NUMBER,
				'default'            => 0,
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'frontend_available' => true,
			]
		);

		$element->add_control(
			'ecs_navigation',
			[
				'label'              => esc_html__( 'Navigation', 'ele-custom-skin' ),
				'type'               => \Elementor\Controls_Manager::SELECT,
				'default'            => 'arrows',
				'options'            => [
					'none'   => esc_html__( 'None', 'ele-custom-skin' ),
					'arrows' => esc_html__( 'Arrows', 'ele-custom-skin' ),
					'dots'   => esc_html__( 'Dots', 'ele-custom-skin' ),
					'both'   => esc_html__( 'Arrows & Dots', 'ele-custom-skin' ),
				],
				'condition'          => [ 'ecs_active_type' => 'slider' ],
				'frontend_available' => true,
			]
		);

		// ── Custom Layout settings ────────────────────────────────────────────
		$element->add_control(
			'ecs_custom_layout_id',
			[
				'label'     => esc_html__( 'Custom Layout Template', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::SELECT2,
				'options'   => $this->get_custom_layout_templates(),
				'condition' => [ 'ecs_active_type' => 'custom' ],
				'separator' => 'before',
			]
		);

		$element->add_control(
			'ecs_hide_empty_slots',
			[
				'label'        => esc_html__( 'Hide empty slots', 'ele-custom-skin' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'hide-empty-slots',
				'default'      => '',
				'prefix_class' => 'e-ecs-',
				'condition'    => [ 'ecs_active_type' => 'custom' ],
			]
		);

		$element->add_control(
			'ecs_direction',
			[
				'label'   => esc_html__( 'Direction', 'ele-custom-skin' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => [
					'row'            => [ 'title' => esc_html__( 'Row', 'ele-custom-skin' ),              'icon' => 'eicon-arrow-right'  ],
					'column'         => [ 'title' => esc_html__( 'Column', 'ele-custom-skin' ),           'icon' => 'eicon-arrow-down'   ],
					'row-reverse'    => [ 'title' => esc_html__( 'Row - Reverse', 'ele-custom-skin' ),    'icon' => 'eicon-arrow-left'   ],
					'column-reverse' => [ 'title' => esc_html__( 'Column - Reverse', 'ele-custom-skin' ), 'icon' => 'eicon-arrow-up'  ],
				],
				'default'   => 'row',
				'condition' => [ 'ecs_active_type' => 'custom' ],
				'selectors' => [ '{{WRAPPER}}' => 'flex-direction: {{VALUE}};' ],
			]
		);
	}

	/**
	 * Add "Slider Navigation" style controls (arrows + dots) in the Style tab.
	 * Hooked after 'section_border' so it appears near the bottom of Style tab.
	 */
	public function add_slider_style_controls( $element, $args ): void {
		$nav_arrows = [ 'relation' => 'or', 'terms' => [
			[ 'name' => 'ecs_navigation', 'operator' => '==', 'value' => 'arrows' ],
			[ 'name' => 'ecs_navigation', 'operator' => '==', 'value' => 'both' ],
		] ];
		$nav_dots = [ 'relation' => 'or', 'terms' => [
			[ 'name' => 'ecs_navigation', 'operator' => '==', 'value' => 'dots' ],
			[ 'name' => 'ecs_navigation', 'operator' => '==', 'value' => 'both' ],
		] ];

		$element->start_controls_section(
			'section_ecs_slider_navigation',
			[
				'label'     => esc_html__( 'Slider Navigation', 'ele-custom-skin' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => [ 'ecs_active_type' => 'slider' ],
			]
		);

		// ── Arrows ────────────────────────────────────────────────────────────
		$element->add_control(
			'ecs_arrows_heading',
			[
				'label'     => esc_html__( 'Arrows', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'arrows', 'both' ] ],
			]
		);

		$element->add_control(
			'ecs_arrows_position',
			[
				'label'        => esc_html__( 'Position', 'ele-custom-skin' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => 'inside',
				'options'      => [
					'inside'  => esc_html__( 'Inside', 'ele-custom-skin' ),
					'outside' => esc_html__( 'Outside', 'ele-custom-skin' ),
				],
				'prefix_class' => 'elementor-arrows-position-',
				'condition'    => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'arrows', 'both' ] ],
			]
		);

		$element->add_responsive_control(
			'ecs_arrows_size',
			[
				'label'      => esc_html__( 'Size', 'ele-custom-skin' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 10, 'max' => 100 ] ],
				'selectors'  => [
					'{{WRAPPER}} .elementor-swiper-button' => 'font-size: {{SIZE}}{{UNIT}};',
				],
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'arrows', 'both' ] ],
			]
		);

		$element->start_controls_tabs(
			'ecs_arrows_colors',
			[
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'arrows', 'both' ] ],
			]
		);

		$element->start_controls_tab(
			'ecs_arrows_normal',
			[ 'label' => esc_html__( 'Normal', 'ele-custom-skin' ) ]
		);

		$element->add_control(
			'ecs_arrows_color',
			[
				'label'     => esc_html__( 'Color', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .elementor-swiper-button' => 'color: {{VALUE}};',
				],
			]
		);

		$element->end_controls_tab();

		$element->start_controls_tab(
			'ecs_arrows_hover',
			[ 'label' => esc_html__( 'Hover', 'ele-custom-skin' ) ]
		);

		$element->add_control(
			'ecs_arrows_hover_color',
			[
				'label'     => esc_html__( 'Color', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .elementor-swiper-button:hover' => 'color: {{VALUE}};',
				],
			]
		);

		$element->end_controls_tab();
		$element->end_controls_tabs();

		// ── Dots ──────────────────────────────────────────────────────────────
		$element->add_control(
			'ecs_dots_heading',
			[
				'label'     => esc_html__( 'Dots', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->add_control(
			'ecs_dots_position',
			[
				'label'        => esc_html__( 'Position', 'ele-custom-skin' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => 'inside',
				'options'      => [
					'inside'  => esc_html__( 'Inside', 'ele-custom-skin' ),
					'outside' => esc_html__( 'Outside', 'ele-custom-skin' ),
				],
				'prefix_class' => 'elementor-pagination-position-',
				'condition'    => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->add_responsive_control(
			'ecs_dots_size',
			[
				'label'      => esc_html__( 'Size', 'ele-custom-skin' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 4, 'max' => 30 ] ],
				'selectors'  => [
					'{{WRAPPER}} .swiper-pagination-bullet' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				],
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->add_responsive_control(
			'ecs_dots_gap',
			[
				'label'      => esc_html__( 'Gap', 'ele-custom-skin' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 20 ] ],
				'selectors'  => [
					'{{WRAPPER}} .swiper-pagination-bullet' => 'margin: 0 {{SIZE}}{{UNIT}};',
				],
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->add_control(
			'ecs_dots_color',
			[
				'label'     => esc_html__( 'Color', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .swiper-pagination-bullet' => 'background: {{VALUE}};',
				],
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->add_control(
			'ecs_dots_active_color',
			[
				'label'     => esc_html__( 'Active Color', 'ele-custom-skin' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .swiper-pagination-bullet-active' => 'background: {{VALUE}};',
				],
				'condition' => [ 'ecs_active_type' => 'slider', 'ecs_navigation' => [ 'dots', 'both' ] ],
			]
		);

		$element->end_controls_section();
	}

	/**
	 * Return an id → title map of all published DTE Custom Layout templates.
	 */
	private function get_custom_layout_templates(): array {
		$posts = get_posts( [
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => '_elementor_template_type',
					'value' => 'ecs_custom_layout',
				],
			],
		] );

		$options = [ '' => esc_html__( '— Select Template —', 'ele-custom-skin' ) ];
		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}
		return $options;
	}

	// ── Render stack ──────────────────────────────────────────────────────────

	/** @var array<int, array{mode:string, layout_id:int, id:string, buffering:bool}> */
	private array $render_stack = [];

	/**
	 * Before a container renders: start output buffering for custom_layout mode.
	 *
	 * We buffer the entire container HTML (wrapper + children) so we can
	 * extract children and inject them into the selected template.
	 */
	public function before_container_render( $element ): void {
		// Read DTE layout type; fall back to mapping from legacy container_type for backwards compat.
		$ecs_type = $element->get_settings( 'ecs_container_type' ) ?: '';
		if ( ! $ecs_type || 'flex' === $ecs_type ) {
			$old_type = $element->get_settings( 'container_type' ) ?: 'flex';
			if ( 'ecs-slider' === $old_type ) {
				$ecs_type = 'slider';
			} elseif ( 'ecs-custom' === $old_type ) {
				$ecs_type = 'custom';
			} else {
				$ecs_type = $ecs_type ?: 'flex';
			}
		}
		$mode = $ecs_type;
		$layout_id = absint( $element->get_settings( 'ecs_custom_layout_id' ) );

		$frame = [
			'mode'           => $mode,
			'layout_id'      => $layout_id,
			'id'             => $element->get_id(),
			'buffering'      => false,
			'filter_removed' => false,
		];

		if ( 'slider' === $mode ) {
			// Skip buffering in the editor — JS handles preview there (like ecs-custom).
			$is_edit = \Elementor\Plugin::$instance->editor->is_edit_mode();
			if ( ! $is_edit ) {
				$frame['filter_removed'] = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				if ( $frame['filter_removed'] ) {
					remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				}
				ob_start();
				$frame['buffering'] = true;
			}
		} elseif ( 'custom' === $mode ) {
			// Skip buffering in the editor — the JS preview handles it there.
			$is_edit = \Elementor\Plugin::$instance->editor->is_edit_mode();

			$will_buffer = ! $is_edit
				&& $layout_id > 0
				&& ! ECS_Container_Placeholder_Widget::is_rendering( $element->get_id() );

			if ( $will_buffer ) {
				// Remove the shortcode-rendering filter so ALL children render as HTML
				// into the buffer. Without this, widgets with is_dynamic_content()=true
				// (e.g. Text Editor) emit [elementor-element] shortcodes during
				// Elementor's cache-build pass, and split_children() misses them.
				$frame['filter_removed'] = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				if ( $frame['filter_removed'] ) {
					remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				}
				ob_start();
				$frame['buffering'] = true;
			}
		} elseif ( in_array( $mode, [ 'flex', 'grid' ], true ) ) {
			// Desktop is flex/grid but tablet or mobile may need custom layout / slider.
			// Buffer the container so we can append the responsive alternative versions.
			$tab_type = $element->get_settings( 'ecs_container_type_tablet' ) ?: '';
			$mob_type = $element->get_settings( 'ecs_container_type_mobile' ) ?: '';
			if ( ( in_array( 'custom', [ $tab_type, $mob_type ], true )
				|| in_array( 'slider', [ $tab_type, $mob_type ], true ) )
				&& ! \Elementor\Plugin::$instance->editor->is_edit_mode()
				&& ! ECS_Container_Placeholder_Widget::is_rendering( $element->get_id() )
			) {
				$frame['filter_removed'] = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				if ( $frame['filter_removed'] ) {
					remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
				}
				ob_start();
				$frame['buffering'] = true;
				$frame['mode']      = 'flex_responsive';
				$frame['tab_type']  = $tab_type;
				$frame['mob_type']  = $mob_type;
			}
		}

		$this->render_stack[] = $frame;
	}

	/**
	 * After a container renders: replace buffered output with template + children.
	 *
	 * Flow:
	 *  1. ob_get_clean() captures the full container HTML
	 *  2. Extract children from .e-con-inner / Full Width wrapper and split into elements
	 *  3. Pass ordered batch to ECS_Container_Placeholder_Widget via static context
	 *  4. Render the selected DTE Custom Layout template (repeat for each overflow batch)
	 *     – each placeholder consumes the next child in sequence
	 *     – if children_count < placeholders_count → extra placeholders stay empty
	 *     – if children_count > placeholders_count → template re-renders with overflow children
	 *  5. If no placeholder was found in the template, show fallback error
	 */
	public function after_container_render( $element ): void {
		$frame = array_pop( $this->render_stack );

		if ( empty( $frame['buffering'] ) ) {
			return;
		}

		$container_html = ob_get_clean();

		if ( 'slider' === $frame['mode'] ) {
			$this->render_slider( $element, $container_html, $frame );
			return;
		}

		if ( 'flex_responsive' === $frame['mode'] ) {
			$this->render_flex_with_responsive( $element, $container_html, $frame );
			return;
		}

		$layout_id    = $frame['layout_id'];
		$container_id = $frame['id'];

		// Safety: verify the template post exists and is published.
		if ( ! $layout_id || 'publish' !== get_post_status( $layout_id ) ) {
			echo $container_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// Preserve outer container opening tag — keeps all Elementor + ECS responsive classes.
		preg_match( '/<div\b[^>]*>/i', $container_html, $outer_m );
		$outer_tag = $outer_m[0] ?? '<div>';
		echo $outer_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Split children HTML into individual top-level elements.
		$inner_html    = $this->extract_container_children( $container_html );
		$children_list = $this->split_children( $inner_html );

		// Responsive overrides — used to decide whether to emit a second HTML version.
		$tablet_type    = $element->get_settings( 'ecs_container_type_tablet' ) ?: '';
		$mobile_type    = $element->get_settings( 'ecs_container_type_mobile' ) ?: '';
		$has_responsive = $tablet_type || $mobile_type;

		// ── Desktop version: template with children injected into slots ───────────

		ECS_Container_Placeholder_Widget::mark_rendering( $container_id );

		// Inline template CSS before first render.
		$css_path = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $layout_id . '.css';
		if ( file_exists( $css_path ) ) {
			echo '<style id="elementor-post-' . esc_attr( $layout_id ) . '-css">' . file_get_contents( $css_path ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$hide_empty = ( 'hide-empty-slots' === $element->get_settings( 'ecs_hide_empty_slots' ) );
		$direction  = $element->get_settings( 'ecs_direction' ) ?: 'row';
		$batch      = $children_list;
		$first_pass = true;

		$filter_was_active = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		if ( $filter_was_active ) {
			remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		// When responsive overrides exist, wrap the desktop version so CSS can hide it.
		if ( $has_responsive ) {
			echo '<div class="ecs-custom-version">';
		}

		$wrapper_classes = 'ecs-custom-layout-wrap' . ( $hide_empty ? ' e-ecs-hide-empty-slots' : '' );
		echo '<div class="' . esc_attr( $wrapper_classes ) . '" style="display:flex;flex-direction:' . esc_attr( $direction ) . ';">';

		do {
			ECS_Container_Placeholder_Widget::set_pending_children( $batch );
			$output = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $layout_id, true );
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $first_pass && ! ECS_Container_Placeholder_Widget::any_consumed() && ! empty( $children_list ) ) {
				ECS_Container_Placeholder_Widget::reset_pending_children();
				echo '<div class="ecs-missing-placeholder">' . implode( '', $children_list ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			}

			$batch      = ECS_Container_Placeholder_Widget::get_overflow_children();
			$first_pass = false;
			ECS_Container_Placeholder_Widget::reset_pending_children();
		} while ( ! empty( $batch ) );

		echo '</div>'; // .ecs-custom-layout-wrap

		if ( $has_responsive ) {
			echo '</div>'; // .ecs-custom-version
		}

		if ( $filter_was_active ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		ECS_Container_Placeholder_Widget::unmark_rendering( $container_id );

		// ── Responsive version: children directly as grid or slider ───────────────

		if ( $has_responsive && ! empty( $children_list ) ) {
			$needs_slider = in_array( 'slider', [ $tablet_type, $mobile_type ], true );

			echo '<div class="ecs-responsive-version">';

			if ( $needs_slider ) {
				// Build Swiper — breakpoints enable/disable slider per device.
				$cols_desktop = (int) ( $element->get_settings( 'ecs_slider_columns' ) ?: 1 );
				$cols_tablet  = (int) $element->get_settings( 'ecs_slider_columns_tablet' ) ?: $cols_desktop;
				$cols_mobile  = (int) $element->get_settings( 'ecs_slider_columns_mobile' ) ?: $cols_tablet;
				$navigation   = $element->get_settings( 'ecs_navigation' ) ?: 'arrows';
				$show_arrows  = in_array( $navigation, [ 'arrows', 'both' ], true );
				$show_dots    = in_array( $navigation, [ 'dots',   'both' ], true );

				$swiper_cfg = [
					'slidesPerView' => $cols_desktop,
					'loop'          => 'yes' === $element->get_settings( 'ecs_loop' ),
					'speed'         => (int) ( $element->get_settings( 'ecs_speed' ) ?: 500 ),
					'spaceBetween'  => (int) $element->get_settings( 'ecs_space_between' ),
					'breakpoints'   => [
						0    => [
							'slidesPerView' => $cols_mobile,
							'enabled'       => 'slider' === $mobile_type,
						],
						768  => [
							'slidesPerView' => $cols_tablet,
							'enabled'       => 'slider' === $tablet_type,
						],
						1025 => [ 'enabled' => false ], // desktop uses custom-version
					],
				];

				if ( $show_arrows ) { $swiper_cfg['navigation'] = true; }
				if ( $show_dots )   { $swiper_cfg['pagination'] = [ 'clickable' => true ]; }

				if ( 'yes' === $element->get_settings( 'ecs_autoplay' ) ) {
					$swiper_cfg['autoplay'] = [
						'delay'                => (int) ( $element->get_settings( 'ecs_autoplay_speed' ) ?: 3000 ),
						'pauseOnMouseEnter'    => 'yes' === $element->get_settings( 'ecs_pause_on_hover' ),
						'disableOnInteraction' => false,
					];
				}

				echo '<div class="swiper ecs-swiper" data-ecs-slider-settings="' . esc_attr( wp_json_encode( $swiper_cfg ) ) . '">';
				echo '<div class="swiper-wrapper">';
				foreach ( $children_list as $child ) {
					echo '<div class="swiper-slide">' . $child . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>'; // .swiper-wrapper

				if ( $show_arrows ) {
					echo '<div class="elementor-swiper-button elementor-swiper-button-prev" role="button" tabindex="0">'
						. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M15.5 5 8.5 12.5 15.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
						. '</div>';
					echo '<div class="elementor-swiper-button elementor-swiper-button-next" role="button" tabindex="0">'
						. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M9.5 5 16.5 12.5 9.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
						. '</div>';
				}
				if ( $show_dots ) {
					echo '<div class="swiper-pagination"></div>';
				}

				echo '</div>'; // .ecs-swiper

			} else {
				// Grid or flex — plain children container; CSS applies layout.
				echo '<div class="ecs-resp-children">';
				foreach ( $children_list as $child ) {
					echo $child; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>'; // .ecs-resp-children
			}

			echo '</div>'; // .ecs-responsive-version
		}

		echo '</div>'; // outer .e-con

		// Restore the filter removed at buffer start.
		if ( ! empty( $frame['filter_removed'] ) ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}
	}

	/**
	 * Rebuild buffered container HTML as a Swiper slider.
	 *
	 * Preserves the outer container div (with all Elementor classes), wraps
	 * each direct child in a .swiper-slide, and appends navigation elements.
	 * Swiper is initialised by ecs-slider.js on the frontend.
	 */
	private function render_slider( $element, string $container_html, array $frame ): void {
		// Restore filter removed during buffering.
		if ( ! empty( $frame['filter_removed'] ) ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		// 1. Preserve outer opening tag (with all Elementor classes/attributes).
		preg_match( '/<div\b[^>]*>/i', $container_html, $outer_m );
		$outer_tag = $outer_m[0] ?? '<div>';

		// 2. Extract and split children.
		$inner_html    = $this->extract_container_children( $container_html );
		$children_list = $this->split_children( $inner_html );

		// 3. Build Swiper settings from element controls.
		$navigation  = $element->get_settings( 'ecs_navigation' ) ?: 'arrows';
		$show_arrows = in_array( $navigation, [ 'arrows', 'both' ], true );
		$show_dots   = in_array( $navigation, [ 'dots', 'both' ], true );
		$autoplay_on = 'yes' === $element->get_settings( 'ecs_autoplay' );

		// Responsive columns ('' = inherit from larger breakpoint).
		$cols_desktop = (int) ( $element->get_settings( 'ecs_slider_columns' ) ?: 1 );
		$cols_tablet  = (int) $element->get_settings( 'ecs_slider_columns_tablet' );
		$cols_mobile  = (int) $element->get_settings( 'ecs_slider_columns_mobile' );
		$cols_tablet  = $cols_tablet ?: $cols_desktop;
		$cols_mobile  = $cols_mobile ?: $cols_tablet;

		// Layout overrides ('' = inherit; 'flex'|'grid' = disable Swiper at that breakpoint).
		$layout_tablet = $element->get_settings( 'ecs_container_type_tablet' ) ?: '';
		$layout_mobile = $element->get_settings( 'ecs_container_type_mobile' ) ?: '';

		$swiper_settings = [
			'slidesPerView' => $cols_desktop,
			'loop'          => 'yes' === $element->get_settings( 'ecs_loop' ),
			'speed'         => (int) ( $element->get_settings( 'ecs_speed' ) ?: 500 ),
			'spaceBetween'  => (int) $element->get_settings( 'ecs_space_between' ),
			'breakpoints'   => [
				0    => [
					'slidesPerView' => $cols_mobile,
					'enabled'       => ! in_array( $layout_mobile, [ 'flex', 'grid' ], true ),
				],
				768  => [
					'slidesPerView' => $cols_tablet,
					'enabled'       => ! in_array( $layout_tablet, [ 'flex', 'grid' ], true ),
				],
				1025 => [
					'slidesPerView' => $cols_desktop,
					'enabled'       => true,
				],
			],
		];

		if ( $autoplay_on ) {
			$swiper_settings['autoplay'] = [
				'delay'                => (int) ( $element->get_settings( 'ecs_autoplay_speed' ) ?: 3000 ),
				'pauseOnMouseEnter'    => 'yes' === $element->get_settings( 'ecs_pause_on_hover' ),
				'disableOnInteraction' => false,
			];
		}

		if ( $show_arrows ) {
			$swiper_settings['navigation'] = true;
		}

		if ( $show_dots ) {
			$swiper_settings['pagination'] = [ 'clickable' => true ];
		}

		// 4. Output HTML.
		echo $outer_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="swiper ecs-swiper" data-ecs-slider-settings="' . esc_attr( wp_json_encode( $swiper_settings ) ) . '">';
		echo '<div class="swiper-wrapper">';

		foreach ( $children_list as $child ) {
			echo '<div class="swiper-slide">' . $child . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>'; // .swiper-wrapper

		if ( $show_arrows ) {
			echo '<div class="elementor-swiper-button elementor-swiper-button-prev" role="button" tabindex="0">'
				. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M15.5 5 8.5 12.5 15.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</div>';
			echo '<div class="elementor-swiper-button elementor-swiper-button-next" role="button" tabindex="0">'
				. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M9.5 5 16.5 12.5 9.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</div>';
		}

		if ( $show_dots ) {
			echo '<div class="swiper-pagination"></div>';
		}

		echo '</div>'; // .swiper
		echo '</div>'; // outer container
	}

	/**
	 * Desktop=flex/grid with tablet/mobile=custom|slider.
	 * Re-emits the buffered container as-is for desktop, then appends one or two
	 * responsive-version blocks that CSS shows at the appropriate breakpoints.
	 */
	private function render_flex_with_responsive( $element, string $container_html, array $frame ): void {
		if ( ! empty( $frame['filter_removed'] ) ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		$tab_type     = $frame['tab_type'] ?? '';
		$mob_type     = $frame['mob_type'] ?? '';
		$layout_id    = absint( $element->get_settings( 'ecs_custom_layout_id' ) );
		$container_id = $frame['id'];

		// Outer opening tag.
		preg_match( '/<div\b[^>]*>/i', $container_html, $outer_m, PREG_OFFSET_CAPTURE );
		$outer_tag       = $outer_m[0][0] ?? '<div>';
		$after_outer_pos = (int) $outer_m[0][1] + strlen( $outer_tag );

		// Desktop inner: everything between the outer opening tag and the last </div>.
		$last_close_pos = strrpos( $container_html, '</div>' );
		$desktop_inner  = ( false !== $last_close_pos )
			? substr( $container_html, $after_outer_pos, $last_close_pos - $after_outer_pos )
			: substr( $container_html, $after_outer_pos );

		// Full Width containers have no .e-con-inner — wrap in a hide-able div.
		if ( ! preg_match( '/<div\b[^>]*\be-con-inner\b/', $desktop_inner ) ) {
			$desktop_inner = '<div class="ecs-desktop-inner">' . $desktop_inner . '</div>';
		}

		$inner_html    = $this->extract_container_children( $container_html );
		$children_list = $this->split_children( $inner_html );

		echo $outer_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $desktop_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// When tablet and mobile share the same type (or mobile inherits), one block suffices.
		$mob_resolved = $mob_type ?: $tab_type;
		$same_type    = ( '' === $mob_type || $mob_type === $tab_type );

		if ( $same_type ) {
			echo '<div class="ecs-responsive-version">';
			if ( 'custom' === $tab_type ) {
				$this->echo_custom_layout_content( $element, $children_list, $layout_id, $container_id );
			} elseif ( 'slider' === $tab_type ) {
				$this->echo_swiper_html( $element, $children_list, $tab_type, $mob_resolved );
			}
			echo '</div>';
		} else {
			// Different types for tablet vs mobile — separate blocks.
			if ( 'custom' === $tab_type || 'slider' === $tab_type ) {
				echo '<div class="ecs-tablet-version">';
				if ( 'custom' === $tab_type ) {
					$this->echo_custom_layout_content( $element, $children_list, $layout_id, $container_id );
				} else {
					$this->echo_swiper_html( $element, $children_list, 'slider', '' );
				}
				echo '</div>';
			}
			if ( 'custom' === $mob_type || 'slider' === $mob_type ) {
				echo '<div class="ecs-mobile-version">';
				if ( 'custom' === $mob_type ) {
					$this->echo_custom_layout_content( $element, $children_list, $layout_id, $container_id );
				} else {
					$this->echo_swiper_html( $element, $children_list, '', 'slider' );
				}
				echo '</div>';
			}
		}

		echo '</div>'; // close outer .e-con
	}

	/**
	 * Render a custom layout template with children injected into placeholder slots.
	 * Used by both after_container_render (desktop=custom) and render_flex_with_responsive.
	 *
	 * @param array<string> $children_list Rendered HTML of each child element.
	 */
	private function echo_custom_layout_content( $element, array $children_list, int $layout_id, string $container_id ): void {
		if ( ! $layout_id || 'publish' !== get_post_status( $layout_id ) ) {
			foreach ( $children_list as $child ) {
				echo $child; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}

		if ( ! class_exists( 'ECS_Container_Placeholder_Widget', false ) ) {
			require_once $this->module_path() . 'widgets/class-ecs-container-placeholder-widget.php';
		}

		$hide_empty = ( 'hide-empty-slots' === $element->get_settings( 'ecs_hide_empty_slots' ) );
		$direction  = $element->get_settings( 'ecs_direction' ) ?: 'row';

		$css_path = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $layout_id . '.css';
		if ( file_exists( $css_path ) ) {
			echo '<style id="elementor-post-' . esc_attr( $layout_id ) . '-css">'
				. file_get_contents( $css_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.Security.EscapeOutput.OutputNotEscaped
				. '</style>';
		}

		ECS_Container_Placeholder_Widget::mark_rendering( $container_id );

		$filter_was_active = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		if ( $filter_was_active ) {
			remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		$wrapper_classes = 'ecs-custom-layout-wrap' . ( $hide_empty ? ' e-ecs-hide-empty-slots' : '' );
		echo '<div class="' . esc_attr( $wrapper_classes ) . '" style="display:flex;flex-direction:' . esc_attr( $direction ) . ';">';

		$batch      = $children_list;
		$first_pass = true;
		do {
			ECS_Container_Placeholder_Widget::set_pending_children( $batch );
			$output = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $layout_id, true );
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $first_pass && ! ECS_Container_Placeholder_Widget::any_consumed() && ! empty( $children_list ) ) {
				ECS_Container_Placeholder_Widget::reset_pending_children();
				echo '<div class="ecs-missing-placeholder">' . implode( '', $children_list ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			}
			$batch      = ECS_Container_Placeholder_Widget::get_overflow_children();
			$first_pass = false;
			ECS_Container_Placeholder_Widget::reset_pending_children();
		} while ( ! empty( $batch ) );

		echo '</div>'; // .ecs-custom-layout-wrap

		if ( $filter_was_active ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		ECS_Container_Placeholder_Widget::unmark_rendering( $container_id );
	}

	/**
	 * Output the Swiper HTML for responsive slider versions.
	 * Breakpoints control enabled/disabled per device; CSS hides the wrapper block entirely.
	 *
	 * @param string $tab_type 'slider'|'' — type resolved for tablet
	 * @param string $mob_type 'slider'|'' — type resolved for mobile ('' = inherit tab_type)
	 */
	private function echo_swiper_html( $element, array $children_list, string $tab_type, string $mob_type ): void {
		$mob_resolved = $mob_type ?: $tab_type;
		$cols_desktop = (int) ( $element->get_settings( 'ecs_slider_columns' ) ?: 1 );
		$cols_tablet  = (int) $element->get_settings( 'ecs_slider_columns_tablet' ) ?: $cols_desktop;
		$cols_mobile  = (int) $element->get_settings( 'ecs_slider_columns_mobile' ) ?: $cols_tablet;
		$navigation   = $element->get_settings( 'ecs_navigation' ) ?: 'arrows';
		$show_arrows  = in_array( $navigation, [ 'arrows', 'both' ], true );
		$show_dots    = in_array( $navigation, [ 'dots', 'both' ], true );

		$swiper_cfg = [
			'slidesPerView' => $cols_desktop,
			'loop'          => 'yes' === $element->get_settings( 'ecs_loop' ),
			'speed'         => (int) ( $element->get_settings( 'ecs_speed' ) ?: 500 ),
			'spaceBetween'  => (int) $element->get_settings( 'ecs_space_between' ),
			'breakpoints'   => [
				0    => [
					'slidesPerView' => $cols_mobile,
					'enabled'       => 'slider' === $mob_resolved,
				],
				768  => [
					'slidesPerView' => $cols_tablet,
					'enabled'       => 'slider' === $tab_type,
				],
				1025 => [ 'enabled' => false ],
			],
		];

		if ( 'yes' === $element->get_settings( 'ecs_autoplay' ) ) {
			$swiper_cfg['autoplay'] = [
				'delay'                => (int) ( $element->get_settings( 'ecs_autoplay_speed' ) ?: 3000 ),
				'pauseOnMouseEnter'    => 'yes' === $element->get_settings( 'ecs_pause_on_hover' ),
				'disableOnInteraction' => false,
			];
		}
		if ( $show_arrows ) { $swiper_cfg['navigation'] = true; }
		if ( $show_dots )   { $swiper_cfg['pagination'] = [ 'clickable' => true ]; }

		echo '<div class="swiper ecs-swiper" data-ecs-slider-settings="' . esc_attr( wp_json_encode( $swiper_cfg ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="swiper-wrapper">';
		foreach ( $children_list as $child ) {
			echo '<div class="swiper-slide">' . $child . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>'; // .swiper-wrapper

		if ( $show_arrows ) {
			echo '<div class="elementor-swiper-button elementor-swiper-button-prev" role="button" tabindex="0">'
				. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M15.5 5 8.5 12.5 15.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</div>';
			echo '<div class="elementor-swiper-button elementor-swiper-button-next" role="button" tabindex="0">'
				. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25"><path d="M9.5 5 16.5 12.5 9.5 20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</div>';
		}
		if ( $show_dots ) {
			echo '<div class="swiper-pagination"></div>';
		}
		echo '</div>'; // .ecs-swiper
	}

	/**
	 * Extract the direct-children HTML from a rendered container.
	 *
	 * Handles two Elementor layout modes:
	 *  - Boxed (Content Width = Boxed):  children are inside .e-con-inner
	 *  - Full Width (Content Width = Full Width): children are direct children of .e-con
	 *
	 * @param string $html Full container HTML from output buffer.
	 * @return string HTML fragment containing the direct child elements.
	 */
	private function extract_container_children( string $html ): string {
		// Boxed mode: children are inside .e-con-inner.
		if ( preg_match(
			'/<div\b[^>]*\bclass=["\'][^"\']*\be-con-inner\b[^"\']*["\'][^>]*>/i',
			$html,
			$match,
			PREG_OFFSET_CAPTURE
		) ) {
			return $this->extract_e_con_inner( $html );
		}

		// Full Width mode: no .e-con-inner — children are direct children of the
		// outermost <div> in the buffer. Skip the outer wrapper and return its content.
		if ( ! preg_match( '/<div\b[^>]*>/i', $html, $outer_m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$after_open = (int) $outer_m[0][1] + strlen( $outer_m[0][0] );
		$inner      = substr( $html, $after_open );

		preg_match_all( '/<div\b|<\/div>/i', $inner, $tokens, PREG_OFFSET_CAPTURE );

		$depth = 1;
		foreach ( $tokens[0] as [ $token, $pos ] ) {
			if ( 0 === stripos( $token, '<div' ) ) {
				$depth++;
			} else {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $inner, 0, $pos );
				}
			}
		}

		return $inner;
	}

	/**
	 * Extract the inner HTML of the first .e-con-inner element.
	 *
	 * Uses a stack-based approach (count opening/closing <div> tags) to
	 * reliably find the matching closing tag regardless of nesting depth.
	 * Avoids DOMDocument which modifies encoding and adds DOCTYPE wrappers.
	 *
	 * @param string $html Full container HTML from output buffer.
	 * @return string Inner HTML of .e-con-inner, or full $html as fallback.
	 */
	private function extract_e_con_inner( string $html ): string {
		// Find the opening <div ... class="... e-con-inner ...">
		if ( ! preg_match(
			'/<div\b[^>]*\bclass=["\'][^"\']*\be-con-inner\b[^"\']*["\'][^>]*>/i',
			$html,
			$match,
			PREG_OFFSET_CAPTURE
		) ) {
			return $html; // No e-con-inner found — return full HTML as fallback
		}

		$after_open = (int) $match[0][1] + strlen( $match[0][0] );
		$inner      = substr( $html, $after_open );

		// Walk all <div and </div> tokens using \b to avoid matching <divider> etc.
		preg_match_all( '/<div\b|<\/div>/i', $inner, $tokens, PREG_OFFSET_CAPTURE );

		$depth = 1;
		foreach ( $tokens[0] as [ $token, $pos ] ) {
			if ( 0 === stripos( $token, '<div' ) ) {
				$depth++;
			} else {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $inner, 0, $pos );
				}
			}
		}

		return $inner; // Unmatched tags — return inner content as fallback
	}

	/**
	 * Split a block of HTML into individual top-level <div> elements.
	 *
	 * Each Elementor child element is a <div>. We find each one at depth-0
	 * using the same stack-based approach as extract_e_con_inner(), returning
	 * an ordered array: [child_0_html, child_1_html, ...].
	 *
	 * @param string $html Inner HTML from .e-con-inner.
	 * @return list<string> Ordered array of individual child HTML strings.
	 */
	private function split_children( string $html ): array {
		$elements  = [];
		$remaining = ltrim( $html );

		while ( '' !== $remaining ) {
			// Find the next opening <div tag.
			if ( ! preg_match( '/<div\b[^>]*>/i', $remaining, $open_m, PREG_OFFSET_CAPTURE ) ) {
				break;
			}

			$open_start = (int) $open_m[0][1];
			$open_len   = strlen( $open_m[0][0] );

			// Walk <div and </div> tokens after the opening tag to find the matching close.
			$after_open = substr( $remaining, $open_start + $open_len );
			preg_match_all( '/<div\b|<\/div>/i', $after_open, $tokens, PREG_OFFSET_CAPTURE );

			$depth     = 1;
			$close_pos = null;
			foreach ( $tokens[0] as [ $token, $pos ] ) {
				if ( 0 === stripos( $token, '<div' ) ) {
					$depth++;
				} else {
					$depth--;
					if ( 0 === $depth ) {
						$close_pos = (int) $pos;
						break;
					}
				}
			}

			if ( null === $close_pos ) {
				// Unmatched — treat everything remaining as one element.
				$elements[] = trim( substr( $remaining, $open_start ) );
				break;
			}

			$close_tag_len = 6; // strlen( '</div>' )
			$element_end   = $open_start + $open_len + $close_pos + $close_tag_len;
			$elements[]    = substr( $remaining, $open_start, $element_end - $open_start );
			$remaining     = ltrim( substr( $remaining, $element_end ) );
		}

		return array_values( array_filter( $elements, fn( $e ) => '' !== trim( $e ) ) );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler: render a DTE Custom Layout template with caller-supplied
	 * children HTML strings so the Elementor editor canvas can display a live
	 * preview of the injection result.
	 *
	 * Security: nonce + capability check. Only logged-in users with edit_posts
	 * can reach this handler (wp_ajax_ prefix requires authentication).
	 */
	public function ajax_preview_layout(): void {
		check_ajax_referer( 'ecs-preview-layout', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
			return;
		}

		$layout_id = absint( $_POST['layout_id'] ?? 0 );
		$children  = isset( $_POST['children'] ) && is_array( $_POST['children'] )
			? array_values( wp_unslash( $_POST['children'] ) )
			: [];

		if ( ! $layout_id || 'publish' !== get_post_status( $layout_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid template' ] );
			return;
		}

		// Same filter guard as in after_container_render: prevent shortcode
		// re-emission when the element cache builder filter is active.
		$filter_was_active = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		if ( $filter_was_active ) {
			remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		// The widget class is loaded lazily via elementor/widgets/register, which
		// fires inside get_builder_content_for_display(). Since we need static
		// methods before that call, ensure the file is loaded explicitly here.
		if ( ! class_exists( 'ECS_Container_Placeholder_Widget', false ) ) {
			require_once $this->module_path() . 'widgets/class-ecs-container-placeholder-widget.php';
		}

		// Cycle through children like after_container_render: each pass fills one
		// template instance; overflow children feed the next pass.
		$batch      = $children;
		$first_pass = true;
		$output     = '';

		do {
			ECS_Container_Placeholder_Widget::set_pending_children( $batch );

			$batch_html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $layout_id, false );

			if ( $first_pass && ! ECS_Container_Placeholder_Widget::any_consumed() && ! empty( $children ) ) {
				// Template has no DTE Placeholder widget — send error fallback HTML.
				ECS_Container_Placeholder_Widget::reset_pending_children();
				$output = '<div class="ecs-missing-placeholder">' . implode( '', $children ) . '</div>';
				break;
			}

			$output    .= $batch_html;
			$batch      = ECS_Container_Placeholder_Widget::get_overflow_children();
			$first_pass = false;
			ECS_Container_Placeholder_Widget::reset_pending_children();

		} while ( ! empty( $batch ) );

		if ( $filter_was_active ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		wp_send_json_success( [ 'html' => $output ] );
	}

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'ecs-container-layout',
			$this->module_asset_url( 'assets/css/ecs-container-layout.css' ),
			[],
			ECS_VERSION
		);

		// Swiper — bundled by Elementor, just enqueue the registered handles.
		wp_enqueue_script( 'swiper' );
		wp_enqueue_style( 'swiper' );
		wp_enqueue_style( 'e-swiper' );

		wp_enqueue_script(
			'ecs-slider',
			$this->module_asset_url( 'assets/js/ecs-slider.js' ),
			[ 'swiper', 'elementor-frontend' ],
			ECS_VERSION,
			[ 'in_footer' => true ]
		);
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-container-layout-editor',
			$this->module_asset_url( 'assets/css/ecs-container-layout.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-editor-preview',
			$this->module_asset_url( 'assets/js/ecs-editor-preview.js' ),
			[ 'elementor-editor' ],
			ECS_VERSION,
			true
		);

		wp_localize_script(
			'ecs-editor-preview',
			'ecsEditorPreview',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ecs-preview-layout' ),
			]
		);

		// When the Responsive Container Layout module is NOT active this module
		// registers a simple (non-responsive) ecs_container_type control in
		// maybe_add_fallback_type_control().  We need to hide the native control and
		// wire a simple grid-sync here as well.
		if ( ! ECS_Core::instance()->modules()->is_active( 'container_responsive' ) ) {
			wp_add_inline_style(
				'ecs-container-layout-editor',
				'.elementor-control-container_type { display: none !important; }'
			);

			// Minimal sync: keeps native container_type in step with ecs_container_type
			// so Elementor shows its grid controls when the user picks Grid.
			wp_add_inline_script( 'ecs-editor-preview', '
( function () {
	"use strict";
	elementor.hooks.addAction( "panel/open_editor/container", function ( mgr, model ) {
		var settings = model && model.get && model.get( "settings" );
		if ( ! settings ) { return; }
		function syncNative() {
			var dteCt = settings.get( "ecs_container_type" ) || "flex";
			var nat   = ( "grid" === dteCt ) ? "grid" : "flex";
			if ( settings.get( "container_type" ) !== nat ) { settings.set( "container_type", nat ); }
		}
		if ( settings.get( "ecs_container_type" ) ) {
			syncNative();
		} else {
			settings.set( "ecs_container_type", settings.get( "container_type" ) || "flex", { silent: true } );
		}
		settings.on( "change:ecs_container_type", syncNative );
	} );
} )();
' );
		}
	}
}
