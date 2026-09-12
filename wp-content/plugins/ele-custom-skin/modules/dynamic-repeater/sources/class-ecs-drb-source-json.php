<?php
/**
 * Dynamic Repeater Builder — Raw JSON Source
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Source_JSON extends ECS_DRB_Source_Base {

	public function get_id(): string {
		return 'json';
	}

	public function get_label(): string {
		return __( 'Raw JSON', 'ele-custom-skin' );
	}

	public function get_config_schema(): array {
		return [
			[
				'type' => 'info',
				'text' => __( 'Paste any JSON array here — each object becomes a repeater row. Useful for static data (menus, pricing, team members) or data copied from an external API. Go to Mapping to connect JSON keys to repeater fields, then Apply.', 'ele-custom-skin' ),
			],
			[
				'key'         => 'json',
				'label'       => __( 'JSON Array', 'ele-custom-skin' ),
				'type'        => 'textarea',
				'default'     => '[]',
				'placeholder' => '[{"field":"value"},{"field":"value2"}]',
			],
		];
	}

	public function get_available_fields( array $config ): array {
		$rows = $this->get_rows( $config );
		if ( empty( $rows ) ) {
			return [];
		}
		$keys = array_keys( $rows[0] );
		return array_map( fn( $k ) => [ 'key' => $k, 'label' => $k ], $keys );
	}

	public function get_rows( array $config ): array {
		$json = $config['json'] ?? '[]';
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		$rows = array_values( array_filter( $data, 'is_array' ) );

		// Flatten scalar values.
		return array_map( function ( $row ) {
			$clean = [];
			foreach ( $row as $key => $val ) {
				$clean[ sanitize_key( $key ) ] = is_array( $val ) ? wp_json_encode( $val ) : (string) $val;
			}
			return $clean;
		}, $rows );
	}
}
