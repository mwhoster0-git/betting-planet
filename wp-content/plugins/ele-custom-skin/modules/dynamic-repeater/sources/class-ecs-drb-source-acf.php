<?php
/**
 * Dynamic Repeater Builder — ACF Repeater Source
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Source_ACF extends ECS_DRB_Source_Base {

	public function get_id(): string {
		return 'acf';
	}

	public function get_label(): string {
		return __( 'ACF Repeater', 'ele-custom-skin' );
	}

	public function get_config_schema(): array {
		if ( ! $this->is_acf_pro_active() ) {
			return [
				[
					'type' => 'error',
					'text' => __( 'ACF Pro is required for this source. Please install and activate Advanced Custom Fields Pro.', 'ele-custom-skin' ),
				],
			];
		}

		return [
			[
				'key'          => 'field_name',
				'label'        => __( 'Repeater Field Name', 'ele-custom-skin' ),
				'type'         => 'text',
				'default'      => '',
				'placeholder'  => 'e.g. my_repeater_field',
				'autocomplete' => 'acf_repeaters',
			],
			[
				'key'         => 'post_id',
				'label'       => __( 'Post ID', 'ele-custom-skin' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'Leave empty for current post', 'ele-custom-skin' ),
			],
		];
	}

	private function is_acf_pro_active(): bool {
		return defined( 'ACF_PRO' ) || class_exists( 'acf_field_repeater' );
	}

	/**
	 * Returns all ACF repeater field names available in the site.
	 */
	public static function get_repeater_field_names(): array {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return [];
		}

		$results = [];
		foreach ( acf_get_field_groups() as $group ) {
			$fields = acf_get_fields( $group['key'] ) ?: [];
			foreach ( $fields as $field ) {
				if ( $field['type'] === 'repeater' ) {
					$results[] = [
						'name'  => $field['name'],
						'label' => $field['label'],
						'group' => $group['title'],
					];
				}
			}
		}

		return $results;
	}

	public function get_available_fields( array $config ): array {
		if ( ! function_exists( 'acf_get_field' ) || empty( $config['field_name'] ) ) {
			return [];
		}

		$field = acf_get_field( sanitize_text_field( $config['field_name'] ) );
		if ( ! $field || $field['type'] !== 'repeater' ) {
			return [];
		}

		$fields = [];
		foreach ( $field['sub_fields'] ?? [] as $sub ) {
			$fields[] = [
				'key'   => $sub['name'],
				'label' => $sub['label'],
			];
		}

		return $fields;
	}

	public function get_rows( array $config ): array {
		if ( ! function_exists( 'get_field' ) || empty( $config['field_name'] ) ) {
			return [];
		}

		// Without this, get_field() falls back to reading raw postmeta for any
		// field name with no registered ACF definition, which would let this
		// source read arbitrary postmeta keys, not just ACF repeater fields.
		$field_name = sanitize_text_field( $config['field_name'] );
		$field      = function_exists( 'acf_get_field' ) ? acf_get_field( $field_name ) : null;
		if ( ! $field || $field['type'] !== 'repeater' ) {
			return [];
		}

		$post_id = ! empty( $config['post_id'] )
			? intval( $config['post_id'] )
			: ( intval( $config['_current_post_id'] ?? 0 ) ?: get_the_ID() );
		$rows    = get_field( $field_name, $post_id );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( function ( $row ) {
			$clean = [];
			foreach ( $row as $key => $val ) {
				if ( is_array( $val ) ) {
					// ACF image/file fields return arrays; normalise to URL or ID.
					if ( isset( $val['url'] ) ) {
						$clean[ $key ] = $val['url'];
					} elseif ( isset( $val['ID'] ) ) {
						$clean[ $key ] = (string) $val['ID'];
					} else {
						$clean[ $key ] = implode( ', ', array_filter( $val, 'is_scalar' ) );
					}
				} else {
					$clean[ $key ] = (string) $val;
				}
			}
			return $clean;
		}, $rows );
	}
}
