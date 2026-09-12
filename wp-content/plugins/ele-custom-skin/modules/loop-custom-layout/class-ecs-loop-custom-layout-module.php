<?php
/**
 * Module: Loop Custom Layout
 *
 * Replaces the default Loop Grid widget output with an ECS Custom Layout
 * template. Each queried loop item is rendered in post context, then
 * distributed into ECS Container Placeholder widgets inside the template —
 * cycling when items > placeholders, leaving placeholders empty when
 * items < placeholders.
 *
 * Requirements:
 *  - Elementor Pro ≥ 3.8 (Loop Builder module — provides the Loop Grid widget)
 *  - ECS Container Layout module (registers the ecs_custom_layout document type
 *    and the ECS Container Placeholder widget)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Loop_Custom_Layout_Module extends ECS_Module_Base {

	/**
	 * Collected loop-grid widgets (widget_id => template_id) that have
	 * ecs_use_custom_layout enabled. Populated before each document renders,
	 * consumed in the elementor/frontend/the_content filter.
	 *
	 * @var array<string, int>
	 */
	private array $pending_widgets = [];

	// ── Identity ──────────────────────────────────────────────────────────────

	public function get_id(): string {
		return 'loop_custom_layout';
	}

	public function get_title(): string {
		return __( 'Loop Custom Layout', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Arrange Loop Grid items inside an ECS Custom Layout template via placeholder widgets — the same injection system as Container Content Layout but powered by the Loop Grid query. Requires Elementor Pro and the Container Layout module.', 'ele-custom-skin' );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	public function boot(): void {
		// Requires Elementor Pro Loop Builder (Loop Grid widget).
		if ( ! class_exists( '\ElementorPro\Modules\LoopBuilder\Module' ) ) {
			return;
		}

		// Inject the toggle + template selector at the bottom of the Layout section.
		add_action(
			'elementor/element/loop-grid/section_layout/before_section_end',
			[ $this, 'add_loop_grid_controls' ],
			10, 2
		);

		// Before each document renders: collect loop-grid widgets with our setting.
		// (edit-mode check is deferred to inside the callbacks to avoid calling
		//  is_edit_mode() here — doing so would trigger documents->get(), firing
		//  elementor/documents/register before EPro registers its loop-item type.)
		add_action( 'elementor/frontend/before_get_builder_content', [ $this, 'collect_widgets' ], 10, 1 );
		add_filter( 'elementor/frontend/the_content', [ $this, 'inject_custom_layouts' ], 10, 1 );

		// AJAX endpoint: render template with loop items for the editor preview.
		add_action( 'wp_ajax_ecs_loop_custom_layout_preview', [ $this, 'ajax_loop_custom_layout_preview' ] );
	}

	// ── Controls ──────────────────────────────────────────────────────────────

	/**
	 * Inject a switcher + template selector at the bottom of the Layout section.
	 */
	public function add_loop_grid_controls( $element, $args ): void {
		$element->add_control( 'ecs_use_custom_layout', [
			'label'        => esc_html__( 'Use Custom Layout', 'ele-custom-skin' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => '',
			'separator'    => 'before',
		] );

		$element->add_control( 'ecs_loop_layout_id', [
			'label'       => esc_html__( 'Custom Layout Template', 'ele-custom-skin' ),
			'type'        => \Elementor\Controls_Manager::SELECT2,
			'options'     => $this->get_custom_layout_templates(),
			'label_block' => true,
			'description' => esc_html__( 'Placeholder widgets inside the template receive loop items in order, cycling when items exceed placeholders.', 'ele-custom-skin' ),
			'condition'   => [ 'ecs_use_custom_layout' => 'yes' ],
		] );
	}

	// ── Document-level interception ───────────────────────────────────────────

	/**
	 * Fired before Elementor renders a document.
	 * Walks the document element tree and registers any loop-grid widgets that
	 * have ecs_use_custom_layout enabled.
	 *
	 * @param \Elementor\Core\Base\Document $document
	 */
	public function collect_widgets( $document ): void {
		// Skip in the editor panel but NOT in the editor's preview iframe —
		// is_edit_mode()=true covers both; is_preview_mode()=true only for the iframe.
		if ( \Elementor\Plugin::$instance->editor->is_edit_mode()
			&& ! \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return;
		}

		$elements_data = $document->get_elements_data();
		if ( empty( $elements_data ) ) {
			return;
		}

		$this->walk_elements( $elements_data );
	}

	/**
	 * Fired after Elementor renders a document (receives its full HTML).
	 * For each collected loop-grid widget whose ID class appears in the HTML,
	 * extract the rendered loop items and replace the widget output with the
	 * ECS Custom Layout template.
	 */
	public function inject_custom_layouts( string $content ): string {
		if ( empty( $this->pending_widgets ) ) {
			return $content;
		}

		foreach ( $this->pending_widgets as $widget_id => $template_id ) {
			// Quick check before the expensive extraction.
			if ( false === strpos( $content, 'elementor-element-' . $widget_id ) ) {
				continue;
			}

			$widget_html = $this->extract_element_by_id( $content, $widget_id );
			if ( ! $widget_html ) {
				continue;
			}

			$items = $this->extract_loop_items( $widget_html );
			if ( empty( $items ) ) {
				continue; // No loop items rendered — leave original output.
			}

			ob_start();
			$this->render_with_custom_layout( $template_id, $items );
			$replacement = ob_get_clean();

			$pos = strpos( $content, $widget_html );
			if ( false !== $pos ) {
				$content = substr( $content, 0, $pos )
					. $replacement
					. substr( $content, $pos + strlen( $widget_html ) );
			}

			unset( $this->pending_widgets[ $widget_id ] );
		}

		return $content;
	}

	// ── Injection ─────────────────────────────────────────────────────────────

	/**
	 * Render the ECS Custom Layout template with loop items distributed into
	 * placeholder widgets.  Mirrors the logic in ECS_Container_Layout_Module.
	 *
	 * @param int           $template_id Published ECS Custom Layout post ID.
	 * @param list<string>  $items       Rendered loop item HTML strings.
	 */
	private function render_with_custom_layout( int $template_id, array $items ): void {
		if ( ! class_exists( 'ECS_Container_Placeholder_Widget' ) ) {
			// Container Layout module inactive — output items without a template.
			echo '<div class="elementor-widget-container">' . implode( '', $items ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// Inline template CSS — same technique as Container Content Layout.
		$css_path = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $template_id . '.css';
		if ( file_exists( $css_path ) ) {
			echo '<style id="elementor-post-' . esc_attr( $template_id ) . '-css">'
				. file_get_contents( $css_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				. '</style>';
		}

		// The shortcode-rendering filter breaks placeholder output during
		// Elementor's element-cache build pass — temporarily remove it.
		$filter_was_active = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		if ( $filter_was_active ) {
			remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		echo '<div class="elementor-widget-container"><div class="ecs-loop-custom-layout-wrap">';

		$batch      = $items;
		$first_pass = true;

		do {
			ECS_Container_Placeholder_Widget::set_pending_children( $batch );

			$output = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, true );
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $first_pass && ! ECS_Container_Placeholder_Widget::any_consumed() && ! empty( $items ) ) {
				// Template has no placeholder widgets — show fallback.
				ECS_Container_Placeholder_Widget::reset_pending_children();
				echo '<div class="ecs-missing-placeholder">';
				foreach ( $items as $item ) {
					echo $item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>';
				break;
			}

			$batch      = ECS_Container_Placeholder_Widget::get_overflow_children();
			$first_pass = false;
			ECS_Container_Placeholder_Widget::reset_pending_children();

		} while ( ! empty( $batch ) );

		echo '</div></div>'; // .ecs-loop-custom-layout-wrap + .elementor-widget-container

		if ( $filter_was_active ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Recursively walk the element tree and add qualifying loop-grid widgets
	 * to $this->pending_widgets.
	 */
	private function walk_elements( array $elements ): void {
		foreach ( $elements as $element ) {
			if (
				( $element['elType'] ?? '' ) === 'widget'
				&& ( $element['widgetType'] ?? '' ) === 'loop-grid'
				&& ( $element['settings']['ecs_use_custom_layout'] ?? '' ) === 'yes'
			) {
				$template_id = absint( $element['settings']['ecs_loop_layout_id'] ?? 0 );
				if ( $template_id && 'publish' === get_post_status( $template_id ) ) {
					$this->pending_widgets[ $element['id'] ] = $template_id;
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				$this->walk_elements( $element['elements'] );
			}
		}
	}

	/**
	 * Find and return the outermost element HTML that carries the class
	 * elementor-element-{$element_id}.  Uses tag-depth tracking to find the
	 * matching closing tag, handling arbitrary nesting.
	 */
	private function extract_element_by_id( string $html, string $element_id ): string {
		if ( ! preg_match(
			'/<([\w-]+)(\s[^>]*?\bclass="[^"]*\belementor-element-' . preg_quote( $element_id, '/' ) . '\b[^"]*"[^>]*)>/i',
			$html,
			$m,
			PREG_OFFSET_CAPTURE
		) ) {
			return '';
		}

		$tag        = $m[1][0];
		$start      = $m[0][1];
		$after_open = $start + strlen( $m[0][0] );
		$len        = strlen( $html );
		$depth      = 1;
		$cursor     = $after_open;
		$end        = $after_open;

		while ( $depth > 0 && $cursor < $len ) {
			$next_open  = strpos( $html, '<' . $tag, $cursor );
			$next_close = strpos( $html, '</' . $tag . '>', $cursor );

			if ( false === $next_close ) {
				break;
			}
			if ( false === $next_open ) {
				$next_open = $len;
			}

			if ( $next_open < $next_close ) {
				$depth++;
				$cursor = $next_open + 1;
			} else {
				$depth--;
				$cursor = $next_close + 1;
				if ( 0 === $depth ) {
					$end = $next_close + strlen( '</' . $tag . '>' );
				}
			}
		}

		return ( 0 === $depth ) ? substr( $html, $start, $end - $start ) : '';
	}

	/**
	 * Extract individual loop item HTML strings from the buffered Loop Grid output.
	 *
	 * Elementor Pro marks each rendered loop item with class `e-loop-item` on
	 * its outermost element.  We use tag-depth tracking to find the matching
	 * closing tag for each item, handling arbitrary nesting correctly.
	 *
	 * @return list<string>
	 */
	private function extract_loop_items( string $html ): array {
		$items = [];
		$pos   = 0;
		$len   = strlen( $html );

		while ( $pos < $len ) {
			// Find the next opening tag that carries the e-loop-item class.
			if ( ! preg_match(
				'/<(\w[\w-]*)(\s[^>]*?\bclass="[^"]*\be-loop-item\b[^"]*"[^>]*|[^>]*\bclass=\'[^\']*\be-loop-item\b[^\']*\'[^>]*)>/i',
				$html,
				$m,
				PREG_OFFSET_CAPTURE,
				$pos
			) ) {
				break;
			}

			$tag        = $m[1][0];
			$start      = $m[0][1];
			$after_open = $start + strlen( $m[0][0] );

			// Walk forward tracking open/close depth to find the matching close tag.
			$depth  = 1;
			$cursor = $after_open;
			$end    = $after_open;

			while ( $depth > 0 && $cursor < $len ) {
				$next_open  = strpos( $html, '<' . $tag, $cursor );
				$next_close = strpos( $html, '</' . $tag . '>', $cursor );

				if ( false === $next_close ) {
					break; // Malformed HTML — stop.
				}
				if ( false === $next_open ) {
					$next_open = $len;
				}

				if ( $next_open < $next_close ) {
					$depth++;
					$cursor = $next_open + 1;
				} else {
					$depth--;
					$cursor = $next_close + 1;
					if ( 0 === $depth ) {
						$end = $next_close + strlen( '</' . $tag . '>' );
					}
				}
			}

			if ( 0 === $depth ) {
				$items[] = substr( $html, $start, $end - $start );
				$pos = $end;
			} else {
				break; // Could not close the tag — stop parsing.
			}
		}

		return $items;
	}

	// ── Editor assets ─────────────────────────────────────────────────────────

	/**
	 * Enqueue the editor preview script for the Loop Custom Layout feature.
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'ecs-loop-custom-layout-editor',
			$this->module_asset_url( 'assets/js/ecs-loop-custom-layout-editor.js' ),
			[ 'elementor-editor' ],
			ECS_VERSION,
			true
		);

		wp_localize_script(
			'ecs-loop-custom-layout-editor',
			'ecsLoopPreview',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ecs-loop-custom-layout-preview' ),
			]
		);
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler: render a custom layout template with caller-supplied loop
	 * item HTML strings so the Elementor editor canvas can preview the result.
	 *
	 * Security: nonce + capability check (wp_ajax_ requires authentication).
	 */
	public function ajax_loop_custom_layout_preview(): void {
		check_ajax_referer( 'ecs-loop-custom-layout-preview', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ] );
			return;
		}

		$template_id = absint( $_POST['template_id'] ?? 0 );
		$items       = isset( $_POST['items'] ) && is_array( $_POST['items'] )
			? array_values( wp_unslash( $_POST['items'] ) )
			: [];

		if ( ! $template_id || 'publish' !== get_post_status( $template_id ) ) {
			wp_send_json_error( [ 'message' => 'Invalid template' ] );
			return;
		}

		// Ensure the placeholder widget class is loaded before static calls.
		if ( ! class_exists( 'ECS_Container_Placeholder_Widget', false ) ) {
			$widget_file = ECS_PATH . 'modules/container-layout/widgets/class-ecs-container-placeholder-widget.php';
			if ( file_exists( $widget_file ) ) {
				require_once $widget_file;
			}
		}

		if ( ! class_exists( 'ECS_Container_Placeholder_Widget', false ) ) {
			wp_send_json_error( [ 'message' => 'Container Layout module not active' ] );
			return;
		}

		ob_start();
		// Render only what goes inside .elementor-widget-container —
		// i.e. the ecs-loop-custom-layout-wrap div and its template instances.
		$this->render_loop_items_with_template( $template_id, $items );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Like render_with_custom_layout() but emits only the inner content —
	 * the ecs-loop-custom-layout-wrap div — without the outer
	 * .elementor-widget-container wrapper (the editor injects into that wrapper).
	 *
	 * @param int          $template_id Published ECS Custom Layout post ID.
	 * @param list<string> $items       Rendered loop item HTML strings.
	 */
	private function render_loop_items_with_template( int $template_id, array $items ): void {
		$filter_was_active = has_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		if ( $filter_was_active ) {
			remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}

		echo '<div class="ecs-loop-custom-layout-wrap">';

		$batch      = $items;
		$first_pass = true;

		do {
			ECS_Container_Placeholder_Widget::set_pending_children( $batch );

			$output = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id, true );
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( $first_pass && ! ECS_Container_Placeholder_Widget::any_consumed() && ! empty( $items ) ) {
				ECS_Container_Placeholder_Widget::reset_pending_children();
				echo '<div class="ecs-missing-placeholder">';
				foreach ( $items as $item ) {
					echo $item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>';
				break;
			}

			$batch      = ECS_Container_Placeholder_Widget::get_overflow_children();
			$first_pass = false;
			ECS_Container_Placeholder_Widget::reset_pending_children();

		} while ( ! empty( $batch ) );

		echo '</div>'; // .ecs-loop-custom-layout-wrap

		if ( $filter_was_active ) {
			add_filter( 'elementor/element/should_render_shortcode', '__return_true' );
		}
	}

	/**
	 * Return an id → title map of all published ECS Custom Layout templates.
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
}
