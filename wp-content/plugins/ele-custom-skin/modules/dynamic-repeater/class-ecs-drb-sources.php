<?php
/**
 * Dynamic Repeater Builder — Source Registry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Sources {

	private static ?self $instance = null;

	/** @var ECS_DRB_Source_Base[] */
	private array $sources = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register( ECS_DRB_Source_Base $source ): void {
		$this->sources[ $source->get_id() ] = $source;
	}

	public function get( string $id ): ?ECS_DRB_Source_Base {
		return $this->sources[ $id ] ?? null;
	}

	/** @return ECS_DRB_Source_Base[] */
	public function get_all(): array {
		return $this->sources;
	}

	/** Compact list for wp_localize_script. */
	public function get_list_for_js(): array {
		return array_values( array_map( fn( $s ) => [
			'id'    => $s->get_id(),
			'label' => $s->get_label(),
		], $this->sources ) );
	}
}
