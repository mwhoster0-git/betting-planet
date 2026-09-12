<?php
/**
 * Dynamic Repeater Builder — Taxonomy Terms Source
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Source_Terms extends ECS_DRB_Source_Base {

	public function get_id(): string {
		return 'terms';
	}

	public function get_label(): string {
		return __( 'Taxonomy Terms', 'ele-custom-skin' );
	}

	public function get_config_schema(): array {
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
		$tax_options = [];
		foreach ( $taxonomies as $tax ) {
			$tax_options[] = [ 'value' => $tax->name, 'label' => $tax->label ];
		}

		return [
			[
				'key'     => 'taxonomy',
				'label'   => __( 'Taxonomy', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => $tax_options,
				'default' => 'category',
			],
			[
				'key'     => 'number',
				'label'   => __( 'Number of Terms', 'ele-custom-skin' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 500,
			],
			[
				'key'     => 'orderby',
				'label'   => __( 'Order By', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => [
					[ 'value' => 'name',    'label' => 'Name' ],
					[ 'value' => 'slug',    'label' => 'Slug' ],
					[ 'value' => 'count',   'label' => 'Post Count' ],
					[ 'value' => 'term_id', 'label' => 'Term ID' ],
				],
				'default' => 'name',
			],
			[
				'key'     => 'order',
				'label'   => __( 'Order', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => [
					[ 'value' => 'ASC',  'label' => 'Ascending' ],
					[ 'value' => 'DESC', 'label' => 'Descending' ],
				],
				'default' => 'ASC',
			],
			[
				'key'     => 'hide_empty',
				'label'   => __( 'Hide Empty Terms', 'ele-custom-skin' ),
				'type'    => 'toggle',
				'default' => true,
			],
			[
				'key'         => 'parent',
				'label'       => __( 'Parent Term ID (0 = top level only)', 'ele-custom-skin' ),
				'type'        => 'number',
				'default'     => '',
				'placeholder' => __( 'Leave empty for all levels', 'ele-custom-skin' ),
			],
			[
				'key'         => 'search',
				'label'       => __( 'Search', 'ele-custom-skin' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '',
			],
		];
	}

	public function get_available_fields( array $config ): array {
		return [
			[ 'key' => 'term_id',     'label' => __( 'Term ID', 'ele-custom-skin' ) ],
			[ 'key' => 'name',        'label' => __( 'Term Name', 'ele-custom-skin' ) ],
			[ 'key' => 'slug',        'label' => __( 'Term Slug', 'ele-custom-skin' ) ],
			[ 'key' => 'description', 'label' => __( 'Description', 'ele-custom-skin' ) ],
			[ 'key' => 'count',       'label' => __( 'Post Count', 'ele-custom-skin' ) ],
			[ 'key' => 'link',        'label' => __( 'Term URL', 'ele-custom-skin' ) ],
			[ 'key' => 'parent_id',   'label' => __( 'Parent Term ID', 'ele-custom-skin' ) ],
			[ 'key' => 'taxonomy',    'label' => __( 'Taxonomy', 'ele-custom-skin' ) ],
		];
	}

	public function get_rows( array $config ): array {
		$args = [
			'taxonomy'   => sanitize_key( $config['taxonomy'] ?? 'category' ),
			'number'     => intval( $config['number'] ?? 10 ),
			'orderby'    => sanitize_key( $config['orderby'] ?? 'name' ),
			'order'      => in_array( $config['order'] ?? 'ASC', [ 'ASC', 'DESC' ], true ) ? $config['order'] : 'ASC',
			'hide_empty' => (bool) ( $config['hide_empty'] ?? true ),
		];

		if ( isset( $config['parent'] ) && $config['parent'] !== '' ) {
			$args['parent'] = intval( $config['parent'] );
		}
		if ( ! empty( $config['search'] ) ) {
			$args['search'] = sanitize_text_field( $config['search'] );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$rows = [];
		foreach ( $terms as $term ) {
			$rows[] = [
				'term_id'     => (string) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => (string) $term->count,
				'link'        => get_term_link( $term ),
				'parent_id'   => (string) $term->parent,
				'taxonomy'    => $term->taxonomy,
			];
		}

		return $rows;
	}
}
