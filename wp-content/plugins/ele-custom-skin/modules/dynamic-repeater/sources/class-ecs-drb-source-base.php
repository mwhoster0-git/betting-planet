<?php
/**
 * Dynamic Repeater Builder — Abstract Source Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ECS_DRB_Source_Base {

	abstract public function get_id(): string;

	abstract public function get_label(): string;

	/**
	 * Returns the configuration schema for the editor UI.
	 * Each item: [ key, label, type (text|number|select|toggle|textarea), options, default, placeholder, min, max ]
	 */
	abstract public function get_config_schema(): array;

	/**
	 * Returns available data fields for mapping, given the current config.
	 * Each item: [ key, label ]
	 */
	abstract public function get_available_fields( array $config ): array;

	/**
	 * Fetches raw data rows from this source.
	 * Each row: associative array of field_key => value (always strings or scalar).
	 */
	abstract public function get_rows( array $config ): array;
}
