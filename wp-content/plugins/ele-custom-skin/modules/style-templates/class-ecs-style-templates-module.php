<?php
/**
 * Module: Style Templates
 *
 * Lets users save / apply / link Style presets per Elementor widget type.
 *
 * Storage:  wp_options key `ecs_style_templates_v1`
 * Scope:    Style tab controls only (no Content / Advanced).
 * Link mode: linked widgets merge preset styles at render-time without
 *            permanently rewriting _elementor_data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

class ECS_Style_Templates_Module extends ECS_Module_Base {

	const OPTION_KEY = 'ecs_style_templates_v1';

	/** Widget types that already received our section — prevents double-injection. */
	private static array $registered_types = [];

	// ── Identity ──────────────────────────────────────────────────────────────

	public function get_id(): string {
		return 'style_templates';
	}

	public function get_title(): string {
		return __( 'Widget Style Templates', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Save and reuse style presets across widgets of the same type. Link widgets to a preset so style changes apply everywhere at once.', 'ele-custom-skin' );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	public function boot(): void {
		// Unified trigger: before_section_start correctly receives the original 'tab' arg.
		// For Style sections → registers a targeted per-section after_section_end hook.
		// For Advanced sections → injects immediately as fallback (widgets with no Style sections).
		add_action( 'elementor/element/before_section_start', [ $this, 'on_before_section_start' ], 10, 3 );

		// Merge preset styles for linked widgets at render-time.
		add_action( 'elementor/frontend/widget/before_render', [ $this, 'maybe_merge_preset' ] );

		// AJAX endpoints (editor only — logged-in users).
		add_action( 'wp_ajax_ecs_style_templates_list',      [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_ecs_style_templates_save_new',  [ $this, 'ajax_save_new' ] );
		add_action( 'wp_ajax_ecs_style_templates_overwrite', [ $this, 'ajax_overwrite' ] );
		add_action( 'wp_ajax_ecs_style_templates_get',       [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_ecs_style_templates_delete',    [ $this, 'ajax_delete' ] );
	}

	// ── Controls injection ────────────────────────────────────────────────────

	/**
	 * Guards: skip internal Elementor base widgets and already-registered types.
	 * Returns true if the element should be skipped.
	 */
	private function should_skip( $element, string &$widget_type ): bool {
		if ( ! $element instanceof Widget_Base ) {
			return true;
		}
		$widget_type = $element->get_name();
		if ( str_starts_with( $widget_type, 'common' ) || in_array( $widget_type, [ 'global-widget' ], true ) ) {
			return true;
		}
		if ( in_array( $widget_type, self::$registered_types, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Fires before each section starts.
	 *
	 * - Style section → registers a targeted `{widget_type}/{section_id}/after_section_end`
	 *   hook so we inject DTE right after that section closes.  We already know it's a
	 *   style section because before_section_start receives the ORIGINAL args (before they
	 *   are processed into the control stack, where the tab value changes).
	 *
	 * - Advanced section (fallback) → injects immediately, for widgets that have no Style
	 *   sections of their own (Advanced controls come from common-optimized, which we skip).
	 *
	 * @param \Elementor\Element_Base $element
	 * @param string                  $section_id
	 * @param array                   $args
	 */
	public function on_before_section_start( $element, string $section_id, array $args ): void {
		$widget_type = '';
		if ( $this->should_skip( $element, $widget_type ) ) {
			return;
		}

		$tab = $args['tab'] ?? '';

		if ( Controls_Manager::TAB_STYLE === $tab ) {
			// Register a specific after_section_end hook for THIS style section.
			// When it fires we know for certain we just closed a style section.
			add_action(
				"elementor/element/{$widget_type}/{$section_id}/after_section_end",
				[ $this, 'on_after_style_section_end' ],
				10, 2
			);
			return;
		}

		if ( Controls_Manager::TAB_ADVANCED === $tab ) {
			// Fallback: no style sections seen yet for this widget type → inject here.
			$this->do_inject_section( $element, $widget_type );
		}
	}

	/**
	 * Fires after a Style-tab section ends (registered dynamically per section above).
	 * Injects the DTE section once per widget type.
	 *
	 * @param \Elementor\Element_Base $element
	 * @param array                   $args
	 */
	public function on_after_style_section_end( $element, array $args ): void {
		$widget_type = '';
		if ( $this->should_skip( $element, $widget_type ) ) {
			return;
		}
		$this->do_inject_section( $element, $widget_type );
	}

	/**
	 * Actually injects the DTE Style Templates section into the element's stack.
	 */
	private function do_inject_section( $element, string $widget_type ): void {
		self::$registered_types[] = $widget_type;

		$element->start_controls_section( 'ecs_style_templates', [
			'label' => __( 'ECS Style Templates', 'ele-custom-skin' ),
			'tab'   => Controls_Manager::TAB_STYLE,
		] );

		// Hidden metadata stored in widget's _elementor_data.
		$element->add_control( 'ecs_style_template_mode', [
			'type'    => Controls_Manager::HIDDEN,
			'default' => 'none',
		] );

		$element->add_control( 'ecs_style_template_name', [
			'type'    => Controls_Manager::HIDDEN,
			'default' => '',
		] );

		// The actual UI is rendered by JS into this placeholder.
		$element->add_control( 'ecs_style_templates_ui', [
			'type'            => Controls_Manager::RAW_HTML,
			'raw'             => $this->render_panel_html( $widget_type ),
			'content_classes' => 'ecs-st-raw',
		] );

		$element->end_controls_section();
	}

	/**
	 * Build the static HTML shell for the Style Templates panel.
	 * JavaScript populates the <select> and wires button events.
	 */
	private function render_panel_html( string $widget_type ): string {
		$select_label  = esc_html__( '— Select template —', 'ele-custom-skin' );
		$apply_label   = esc_html__( 'Apply', 'ele-custom-skin' );
		$ow_label      = esc_html__( 'Overwrite', 'ele-custom-skin' );
		$del_title     = esc_attr__( 'Delete template', 'ele-custom-skin' );
		$link_label    = esc_html__( 'Link to template', 'ele-custom-skin' );
		$ph_label      = esc_attr__( 'Template name…', 'ele-custom-skin' );
		$save_label    = esc_html__( 'Save current style', 'ele-custom-skin' );
		$widget        = esc_attr( $widget_type );

		return <<<HTML
<div class="ecs-st-panel" data-widget-type="{$widget}">

	<!-- Template select -->
	<div class="elementor-control-input-wrapper ecs-st-select-wrap">
		<select class="ecs-st-select">
			<option value="">{$select_label}</option>
		</select>
	</div>

	<!-- Quick actions -->
	<div class="ecs-st-actions">
		<button class="ecs-st-btn ecs-st-btn-apply" data-action="apply">
			<i class="eicon-check"></i><span>{$apply_label}</span>
		</button>
		<button class="ecs-st-btn ecs-st-btn-overwrite" data-action="overwrite">
			<i class="eicon-save"></i><span>{$ow_label}</span>
		</button>
		<button class="ecs-st-btn ecs-st-btn-danger" data-action="delete_tpl" title="{$del_title}">
			<i class="eicon-trash-o"></i>
		</button>
	</div>

	<!-- Link switch -->
	<div class="ecs-st-link-row">
		<span class="ecs-st-link-label">
			<i class="eicon-link"></i>{$link_label}
		</span>
		<label class="elementor-switch elementor-control-unit-2 ecs-st-link-switch">
			<input type="checkbox" class="elementor-switch-input ecs-st-link-toggle">
			<span class="elementor-switch-label" data-on="Yes" data-off="No"></span>
			<span class="elementor-switch-handle"></span>
		</label>
	</div>

	<!-- Status -->
	<div class="ecs-st-status"></div>

	<!-- Save form -->
	<div class="ecs-st-save-form">
		<div class="elementor-control-input-wrapper">
			<input type="text" class="ecs-st-name-input" placeholder="{$ph_label}" />
		</div>
		<button class="ecs-st-btn ecs-st-btn-save" data-action="save_new">
			<i class="eicon-plus-circle-o"></i><span>{$save_label}</span>
		</button>
	</div>

</div>
HTML;
	}

	// ── Frontend merge ────────────────────────────────────────────────────────

	/**
	 * For widgets in "linked" mode, overlay stored preset style settings
	 * onto the widget instance settings before Elementor renders them.
	 *
	 * Linked widgets store only link metadata in _elementor_data;
	 * preset values are never permanently written to the post.
	 *
	 * Also outputs an inline <style> block so CSS-driven properties
	 * (colors, borders, typography) are applied even though Elementor's
	 * page CSS was already generated before render-time.
	 *
	 * @param \Elementor\Widget_Base $widget
	 */
	public function maybe_merge_preset( $widget ): void {
		$settings = $widget->get_settings();

		if ( ( $settings['ecs_style_template_mode'] ?? 'none' ) !== 'linked' ) {
			return;
		}

		$name = $settings['ecs_style_template_name'] ?? '';
		if ( empty( $name ) ) {
			return;
		}

		$all    = get_option( self::OPTION_KEY, [] );
		$preset = $all[ $widget->get_name() ][ $name ]['style_settings'] ?? null;

		if ( ! is_array( $preset ) ) {
			return;
		}

		foreach ( $preset as $key => $value ) {
			$widget->set_settings( $key, $value );
		}

		$this->output_preset_css( $widget, $preset );
	}

	/**
	 * Output an inline <style> block that overrides pre-generated Elementor CSS
	 * for the linked preset. Iterates over controls with `selectors` config and
	 * substitutes preset values (including resolved global color references).
	 *
	 * @param \Elementor\Widget_Base $widget
	 * @param array                  $preset  Raw style_settings from storage.
	 */
	private function output_preset_css( $widget, array $preset ): void {
		$widget_id = $widget->get_id();

		// Elementor 4.x separates style tab controls into a `style_controls` sub-array
		// in the stack. On the frontend `get_controls()` only returns the content/advanced
		// controls; style controls (title_color, typography_*) live in `style_controls`.
		// Merge both so we can find selectors for all preset keys.
		$stack    = $widget->get_stack();
		$controls = array_merge(
			$stack['controls'] ?? [],
			$stack['style_controls'] ?? []
		);

		$wrapper   = '.elementor-element.elementor-element-' . $widget_id;

		$globals = is_array( $preset['__globals__'] ?? null ) ? (array) $preset['__globals__'] : [];

		// Collect all keys that might have CSS selectors.
		$keys = array_unique( array_merge(
			array_diff( array_keys( $preset ), [ '__globals__' ] ),
			array_keys( $globals )
		) );

		$lines = [];

		foreach ( $keys as $key ) {
			$control = $controls[ $key ] ?? null;
			if ( ! $control || empty( $control['selectors'] ) ) {
				continue;
			}
			// Skip DTE internal controls.
			if ( str_starts_with( (string) $key, 'ecs_' ) ) {
				continue;
			}
			// Skip responsive variants — they need @media wrappers (not implemented here).
			if ( preg_match( '/_(tablet|mobile|widescreen|laptop)$/', (string) $key ) ) {
				continue;
			}

			// Determine CSS value: global ref takes priority over direct value.
			if ( isset( $globals[ $key ] ) ) {
				$css_value = $this->resolve_global_ref( (string) $globals[ $key ] );
				$raw_value = null;
			} else {
				$raw_value = $preset[ $key ];
				$css_value = is_string( $raw_value ) ? $raw_value : null;
				// For COLOR controls with no value set, output 'inherit' to reset
				// any custom color the linked widget might have saved.
				if ( '' === $css_value && ( $control['type'] ?? '' ) === Controls_Manager::COLOR ) {
					$css_value = 'inherit';
					$raw_value = null;
				}
			}

			foreach ( $control['selectors'] as $raw_selector => $css_template ) {
				$css = $this->apply_css_template( $css_template, $css_value, $raw_value );
				if ( null === $css ) {
					continue;
				}

				$selector = str_replace( '{{WRAPPER}}', $wrapper, $raw_selector );
				// Skip selectors with unresolved tokens.
				if ( str_contains( $selector, '{{' ) ) {
					continue;
				}

				// Add !important to each declaration.
				$css = preg_replace( '/(\w[^;{]*)(;)/', '$1 !important$2', trim( $css ) );
				if ( ! str_ends_with( $css, ';' ) && ! str_ends_with( $css, '}' ) ) {
					$css .= ' !important';
				}

				$lines[] = $selector . ' { ' . $css . ' }';
			}
		}

		if ( ! empty( $lines ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<style id="ecs-st-' . esc_attr( $widget_id ) . '">' . implode( ' ', $lines ) . '</style>';
		}
	}

	/**
	 * Resolve an Elementor global reference string to a CSS value.
	 * Currently handles global colors: "globals/colors?id=XXXX" → "var(--e-global-color-XXXX)".
	 *
	 * @param string $ref  e.g. "globals/colors?id=b17eaf1"
	 * @return string|null CSS value, or null if not resolvable.
	 */
	private function resolve_global_ref( string $ref ): ?string {
		if ( preg_match( '#globals/colors\?id=([a-zA-Z0-9_-]+)#', $ref, $m ) ) {
			return 'var(--e-global-color-' . $m[1] . ')';
		}
		return null;
	}

	/**
	 * Apply a preset value to a CSS template string (mirrors Elementor's CSS generator).
	 *
	 * Handles:
	 *  - {{VALUE}}  — plain string or resolved CSS value
	 *  - {{SIZE}} + {{UNIT}}  — slider/dimensions value
	 *  - {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}} + {{UNIT}}  — dimensions
	 *  - {{URL}}  — media control (image URL)
	 *
	 * @param string      $template  CSS template from control['selectors'] value.
	 * @param string|null $css_value Pre-computed CSS value (e.g. resolved global ref).
	 * @param mixed       $raw_value Raw preset value for composite controls.
	 * @return string|null Processed CSS string, or null if unresolvable.
	 */
	private function apply_css_template( string $template, ?string $css_value, $raw_value ): ?string {
		$css = $template;

		// Substitute {{VALUE}} with direct CSS value.
		if ( null !== $css_value && '' !== $css_value ) {
			$css = str_replace( '{{VALUE}}', $css_value, $css );
		} elseif ( null === $raw_value || '' === $raw_value ) {
			return null;
		}

		// Handle composite array values (slider, dimensions, media).
		if ( is_array( $raw_value ) ) {
			$unit = (string) ( $raw_value['unit'] ?? '' );

			// Slider: { size: 14, unit: 'px' }.
			if ( array_key_exists( 'size', $raw_value ) ) {
				$size = (string) $raw_value['size'];
				if ( '' === $size ) {
					return null;
				}
				$css = str_replace( [ '{{SIZE}}', '{{UNIT}}' ], [ $size, $unit ], $css );
			}

			// Dimensions: { top, right, bottom, left, unit }.
			foreach ( [ 'TOP', 'RIGHT', 'BOTTOM', 'LEFT' ] as $side ) {
				$side_key = strtolower( $side );
				if ( array_key_exists( $side_key, $raw_value ) ) {
					$side_val = (string) $raw_value[ $side_key ];
					if ( '' === $side_val ) {
						return null;
					}
					$css = str_replace( "{{{$side}}}", $side_val, $css );
				}
			}

			// Media (image): { url: '...', id: ... }.
			if ( array_key_exists( 'url', $raw_value ) ) {
				$css = str_replace( '{{URL}}', esc_url( $raw_value['url'] ), $css );
			}

			// Remaining {{UNIT}} from dimensions.
			$css = str_replace( '{{UNIT}}', $unit, $css );
		}

		// Bail if unresolved tokens remain.
		if ( str_contains( $css, '{{' ) ) {
			return null;
		}
		// Bail if value part is empty (e.g. "property: ;").
		if ( preg_match( '/:\s*;/', $css ) ) {
			return null;
		}

		return $css;
	}

	// ── AJAX ─────────────────────────────────────────────────────────────────

	private function verify_ajax(): void {
		check_ajax_referer( 'ecs_style_templates', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}

	/**
	 * Templates are stored in a single site-wide option and applied to any
	 * linked widget on any post, so writing one is not scoped to a post the
	 * caller owns — edit_posts (held by Contributors) is not enough here.
	 */
	private function verify_ajax_write(): void {
		$this->verify_ajax();
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}

	/** List all templates for a widget type. */
	public function ajax_list(): void {
		$this->verify_ajax();

		$widget_type = sanitize_key( $_POST['widget_type'] ?? '' );
		$all         = get_option( self::OPTION_KEY, [] );
		$templates   = $all[ $widget_type ] ?? [];

		$list = [];
		foreach ( $templates as $name => $data ) {
			$list[] = [
				'name'       => $name,
				'updated_at' => $data['updated_at'] ?? 0,
			];
		}

		wp_send_json_success( $list );
	}

	/** Save a new template. Fails if name already exists. */
	public function ajax_save_new(): void {
		$this->verify_ajax_write();

		$widget_type   = sanitize_key( $_POST['widget_type'] ?? '' );
		$template_name = sanitize_text_field( substr( $_POST['template_name'] ?? '', 0, 100 ) );
		$raw           = json_decode( stripslashes( $_POST['style_settings'] ?? '{}' ), true );
		$style         = $this->sanitize_style_settings( is_array( $raw ) ? $raw : [] );

		if ( empty( $widget_type ) || empty( $template_name ) ) {
			wp_send_json_error( 'Missing required fields' );
		}

		$all = get_option( self::OPTION_KEY, [] );
		if ( isset( $all[ $widget_type ][ $template_name ] ) ) {
			wp_send_json_error( 'template_exists' );
		}

		$all[ $widget_type ][ $template_name ] = [
			'updated_at'     => time(),
			'style_settings' => $style,
			'meta'           => [
				'widget_type'       => $widget_type,
				'elementor_version' => ELEMENTOR_VERSION,
				'ecs_version'       => ECS_VERSION,
			],
		];

		update_option( self::OPTION_KEY, $all, false );
		wp_send_json_success( [ 'name' => $template_name ] );
	}

	/** Overwrite an existing template's style_settings. */
	public function ajax_overwrite(): void {
		$this->verify_ajax_write();

		$widget_type   = sanitize_key( $_POST['widget_type'] ?? '' );
		$template_name = sanitize_text_field( substr( $_POST['template_name'] ?? '', 0, 100 ) );
		$raw           = json_decode( stripslashes( $_POST['style_settings'] ?? '{}' ), true );
		$style         = $this->sanitize_style_settings( is_array( $raw ) ? $raw : [] );

		if ( empty( $widget_type ) || empty( $template_name ) ) {
			wp_send_json_error( 'Missing required fields' );
		}

		$all = get_option( self::OPTION_KEY, [] );
		if ( ! isset( $all[ $widget_type ][ $template_name ] ) ) {
			wp_send_json_error( 'Template not found' );
		}

		$all[ $widget_type ][ $template_name ]['updated_at']     = time();
		$all[ $widget_type ][ $template_name ]['style_settings'] = $style;

		update_option( self::OPTION_KEY, $all, false );
		wp_send_json_success( [ 'name' => $template_name ] );
	}

	/** Get a template's style_settings. */
	public function ajax_get(): void {
		$this->verify_ajax();

		$widget_type   = sanitize_key( $_POST['widget_type'] ?? '' );
		$template_name = sanitize_text_field( $_POST['template_name'] ?? '' );

		$all    = get_option( self::OPTION_KEY, [] );
		$preset = $all[ $widget_type ][ $template_name ] ?? null;

		if ( null === $preset ) {
			wp_send_json_error( 'Template not found' );
		}

		wp_send_json_success( $preset['style_settings'] ?? [] );
	}

	/** Delete a template. */
	public function ajax_delete(): void {
		$this->verify_ajax_write();

		$widget_type   = sanitize_key( $_POST['widget_type'] ?? '' );
		$template_name = sanitize_text_field( $_POST['template_name'] ?? '' );

		$all = get_option( self::OPTION_KEY, [] );
		if ( ! isset( $all[ $widget_type ][ $template_name ] ) ) {
			wp_send_json_error( 'Template not found' );
		}

		unset( $all[ $widget_type ][ $template_name ] );
		update_option( self::OPTION_KEY, $all, false );
		wp_send_json_success( [] );
	}

	/**
	 * Recursively sanitize the style_settings payload received from JS.
	 * Accepts strings, numbers, booleans and nested arrays (max depth 6).
	 */
	private function sanitize_style_settings( array $settings, int $depth = 0 ): array {
		if ( $depth > 6 ) {
			return [];
		}

		$out = [];
		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( empty( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = $this->sanitize_style_settings( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				// Allow CSS values (colors, units) — sanitize_text_field is safe here.
				$out[ $key ] = sanitize_text_field( $value );
			} elseif ( is_numeric( $value ) ) {
				$out[ $key ] = $value + 0;
			} elseif ( is_bool( $value ) ) {
				$out[ $key ] = $value;
			}
			// Silently discard other types (null, object, resource).
		}

		return $out;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_frontend_assets(): void {
		// No frontend assets — preset merge is PHP-only.
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'ecs-style-templates-editor',
			$this->module_asset_url( 'assets/js/ecs-style-templates-editor.js' ),
			[ 'elementor-editor', 'jquery' ],
			ECS_VERSION,
			[ 'in_footer' => true ]
		);

		wp_localize_script( 'ecs-style-templates-editor', 'ecsStyleTemplates', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ecs_style_templates' ),
			'i18n'    => [
				'enterName'       => __( 'Please enter a template name.', 'ele-custom-skin' ),
				'selectTemplate'  => __( 'Please select a template.', 'ele-custom-skin' ),
				'confirmOverwrite' => __( 'Overwrite this template with the current style settings?', 'ele-custom-skin' ),
				'confirmDelete'   => __( 'Delete this template? This cannot be undone.', 'ele-custom-skin' ),
				'noStyle'         => __( 'No style controls found for this widget.', 'ele-custom-skin' ),
				'alreadyExists'   => __( 'A template with this name already exists. Use "Overwrite" to replace it.', 'ele-custom-skin' ),
				'linkedTo'        => __( 'Linked to: ', 'ele-custom-skin' ),
				'applied'         => __( 'Template applied (not linked)', 'ele-custom-skin' ),
				'notLinked'       => __( 'Not linked', 'ele-custom-skin' ),
				'saving'          => __( 'Saving…', 'ele-custom-skin' ),
				'saved'           => __( 'Saved!', 'ele-custom-skin' ),
			],
		] );

		wp_enqueue_style(
			'ecs-style-templates-editor',
			$this->module_asset_url( 'assets/css/ecs-style-templates-editor.css' ),
			[],
			ECS_VERSION
		);
	}
}
