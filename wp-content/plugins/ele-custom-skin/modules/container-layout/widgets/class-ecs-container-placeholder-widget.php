<?php
/**
 * Elementor Widget: DTE Container Placeholder
 *
 * Acts as a marker inside a "DTE Custom Layout" template.
 * Each placeholder in the template receives one child element from the
 * ecs-custom container, in document order.
 *
 * Distribution rules:
 *  - children_count < placeholders_count → extra placeholders stay empty (no cycling of children)
 *  - children_count > placeholders_count → extra children cycle back to the first placeholder
 *
 * Recursion guard: a static set tracks container IDs currently being
 * processed so a template that contains the same container type
 * cannot cause infinite recursion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class ECS_Container_Placeholder_Widget extends Widget_Base {

	/** @var array<string, true> Container IDs currently being rendered */
	private static array $rendering = [];

	/**
	 * Ordered list of individual child HTML strings.
	 *
	 * @var list<string>
	 */
	private static array $children = [];

	/**
	 * How many times get_next_child() has been called in the current injection.
	 * Used to detect "no placeholder found" (consumed === 0 after template render).
	 */
	private static int $consumed = 0;

	// ── Widget identity ───────────────────────────────────────────────────────

	public function get_name(): string {
		return 'ecs_container_placeholder';
	}

	public function get_title(): string {
		return esc_html__( 'ECS Container Placeholder', 'ele-custom-skin' );
	}

	public function get_icon(): string {
		return 'eicon-inner-section';
	}

	public function get_categories(): array {
		return [ 'ele-custom-skin' ];
	}

	public function get_keywords(): array {
		return [ 'ecs', 'placeholder', 'container', 'layout', 'children', 'inject' ];
	}

	// ── Controls ──────────────────────────────────────────────────────────────

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_info',
			[
				'label' => esc_html__( 'Placeholder', 'ele-custom-skin' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'placeholder_notice',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => '<p style="font-size:13px;line-height:1.5;">'
					. esc_html__( 'Place this widget inside a "DTE Custom Layout" template to mark where the container\'s children will be injected.', 'ele-custom-skin' )
					. '</p>',
				'content_classes' => 'elementor-descriptor',
			]
		);

		$this->end_controls_section();
	}

	// ── Render ────────────────────────────────────────────────────────────────

	protected function render(): void {
		$child = self::get_next_child();
		if ( $child !== null ) {
			echo $child; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<span class="ecs-slot-empty"></span>';
		}
	}

	// ── Static context: child injection ──────────────────────────────────────

	/**
	 * Set the ordered list of child HTML strings to distribute.
	 *
	 * @param list<string> $children Individual child HTML strings, index 0..n-1.
	 */
	public static function set_pending_children( array $children ): void {
		self::$children = array_values( $children );
		self::$consumed = 0;
	}

	/**
	 * Return the next child for this placeholder and advance the index.
	 *
	 * Behaviour:
	 *  - children_count === 0             → null (empty placeholder)
	 *  - consumed < children_count        → children[consumed] (sequential)
	 *  - consumed >= children_count       → null (more placeholders than children, stay empty)
	 *
	 * For the "more children than placeholders" case, extra children that
	 * exceed the placeholder count cycle back via the after_container_render
	 * fallback path (they are appended wrapped in .ecs-overflow-children).
	 */
	public static function get_next_child(): ?string {
		$count = count( self::$children );
		if ( 0 === $count || self::$consumed >= $count ) {
			return null;
		}
		return self::$children[ self::$consumed++ ];
	}

	/**
	 * Whether any children have been set (regardless of how many consumed).
	 */
	public static function has_pending_children(): bool {
		return count( self::$children ) > 0;
	}

	/**
	 * Whether at least one placeholder has consumed a child in this injection.
	 * Used to detect "template has no DTE Placeholder widget at all".
	 */
	public static function any_consumed(): bool {
		return self::$consumed > 0;
	}

	/**
	 * Return all children that were not consumed by any placeholder.
	 * These are the overflow children (children_count > placeholders_count).
	 */
	public static function get_overflow_children(): array {
		return array_slice( self::$children, self::$consumed );
	}

	/**
	 * Reset all injection state. Call after each container injection.
	 */
	public static function reset_pending_children(): void {
		self::$children = [];
		self::$consumed = 0;
	}

	// ── Recursion guard ───────────────────────────────────────────────────────

	public static function mark_rendering( string $container_id ): void {
		self::$rendering[ $container_id ] = true;
	}

	public static function unmark_rendering( string $container_id ): void {
		unset( self::$rendering[ $container_id ] );
	}

	public static function is_rendering( string $container_id ): bool {
		return isset( self::$rendering[ $container_id ] );
	}
}
