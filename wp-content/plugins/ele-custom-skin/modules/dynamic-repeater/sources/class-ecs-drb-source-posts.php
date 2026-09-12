<?php
/**
 * Dynamic Repeater Builder — Posts Source
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Source_Posts extends ECS_DRB_Source_Base {

	public function get_id(): string {
		return 'posts';
	}

	public function get_label(): string {
		return __( 'Posts', 'ele-custom-skin' );
	}

	public function get_config_schema(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$pt_options = array_values( array_map( fn( $pt ) => [ 'value' => $pt->name, 'label' => $pt->label ], $post_types ) );

		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
		$tax_options = [ [ 'value' => '', 'label' => __( '— none —', 'ele-custom-skin' ) ] ];
		foreach ( $taxonomies as $tax ) {
			$tax_options[] = [ 'value' => $tax->name, 'label' => $tax->label ];
		}

		return [
			[
				'key'     => 'post_type',
				'label'   => __( 'Post Type', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => $pt_options,
				'default' => 'post',
			],
			[
				'key'     => 'posts_per_page',
				'label'   => __( 'Number of Posts', 'ele-custom-skin' ),
				'type'    => 'number',
				'default' => 10,
				'min'     => 1,
				'max'     => 200,
			],
			[
				'key'     => 'orderby',
				'label'   => __( 'Order By', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => [
					[ 'value' => 'date',       'label' => 'Date' ],
					[ 'value' => 'title',      'label' => 'Title' ],
					[ 'value' => 'menu_order', 'label' => 'Menu Order' ],
					[ 'value' => 'modified',   'label' => 'Modified' ],
					[ 'value' => 'rand',       'label' => 'Random' ],
				],
				'default' => 'date',
			],
			[
				'key'     => 'order',
				'label'   => __( 'Order', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => [
					[ 'value' => 'DESC', 'label' => 'Descending' ],
					[ 'value' => 'ASC',  'label' => 'Ascending' ],
				],
				'default' => 'DESC',
			],
			[
				'key'     => 'taxonomy',
				'label'   => __( 'Filter by Taxonomy', 'ele-custom-skin' ),
				'type'    => 'select',
				'options' => $tax_options,
				'default' => '',
			],
			[
				'key'          => 'tax_terms',
				'label'        => __( 'Term Slugs (comma-separated)', 'ele-custom-skin' ),
				'type'         => 'text',
				'default'      => '',
				'placeholder'  => 'e.g. news, events',
				'autocomplete' => 'taxonomy_terms',
			],
			[
				'key'         => 'search',
				'label'       => __( 'Search', 'ele-custom-skin' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '',
			],
			[
				'key'         => 'date_format',
				'label'       => __( 'Date Format', 'ele-custom-skin' ),
				'type'        => 'text',
				'default'     => get_option( 'date_format' ),
				'placeholder' => 'Y-m-d',
			],
		];
	}

	public function get_available_fields( array $config ): array {
		$fields = [
			[ 'key' => 'post_id',        'label' => __( 'Post ID', 'ele-custom-skin' ) ],
			[ 'key' => 'post_title',     'label' => __( 'Post Title', 'ele-custom-skin' ) ],
			[ 'key' => 'post_excerpt',   'label' => __( 'Post Excerpt', 'ele-custom-skin' ) ],
			[ 'key' => 'post_content',   'label' => __( 'Post Content (full)', 'ele-custom-skin' ) ],
			[ 'key' => 'post_date',      'label' => __( 'Post Date', 'ele-custom-skin' ) ],
			[ 'key' => 'post_modified',  'label' => __( 'Post Modified', 'ele-custom-skin' ) ],
			[ 'key' => 'post_url',       'label' => __( 'Post URL', 'ele-custom-skin' ) ],
			[ 'key' => 'post_slug',      'label' => __( 'Post Slug', 'ele-custom-skin' ) ],
			[ 'key' => 'post_author',    'label' => __( 'Post Author Name', 'ele-custom-skin' ) ],
			[ 'key' => 'thumbnail_url',  'label' => __( 'Thumbnail URL', 'ele-custom-skin' ) ],
			[ 'key' => 'thumbnail_id',   'label' => __( 'Thumbnail ID', 'ele-custom-skin' ) ],
			[ 'key' => 'category_names', 'label' => __( 'Categories (comma list)', 'ele-custom-skin' ) ],
		];

		if ( function_exists( 'acf_get_field_groups' ) ) {
			$post_type = $config['post_type'] ?? 'post';
			$groups = acf_get_field_groups( [ 'post_type' => $post_type ] );
			foreach ( $groups as $group ) {
				$group_fields = acf_get_fields( $group['key'] );
				if ( $group_fields ) {
					foreach ( $group_fields as $f ) {
						$fields[] = [
							'key'   => 'acf_' . $f['name'],
							'label' => 'ACF: ' . $f['label'],
						];
					}
				}
			}
		}

		return $fields;
	}

	public function get_rows( array $config ): array {
		$args = [
			'post_type'      => sanitize_key( $config['post_type'] ?? 'post' ),
			'posts_per_page' => intval( $config['posts_per_page'] ?? 10 ),
			'orderby'        => sanitize_key( $config['orderby'] ?? 'date' ),
			'order'          => in_array( $config['order'] ?? 'DESC', [ 'ASC', 'DESC' ], true ) ? $config['order'] : 'DESC',
			'post_status'    => 'publish',
		];

		if ( ! empty( $config['taxonomy'] ) && ! empty( $config['tax_terms'] ) ) {
			$term_slugs = array_map( 'trim', explode( ',', $config['tax_terms'] ) );
			$args['tax_query'] = [ [
				'taxonomy' => sanitize_key( $config['taxonomy'] ),
				'field'    => 'slug',
				'terms'    => $term_slugs,
			] ];
		}

		if ( ! empty( $config['search'] ) ) {
			$args['s'] = sanitize_text_field( $config['search'] );
		}

		$posts = get_posts( $args );
		$date_format = sanitize_text_field( $config['date_format'] ?? get_option( 'date_format' ) );
		$rows = [];

		$available_acf_keys = [];
		if ( function_exists( 'get_field' ) ) {
			foreach ( $this->get_available_fields( $config ) as $f ) {
				if ( str_starts_with( $f['key'], 'acf_' ) ) {
					$available_acf_keys[] = $f['key'];
				}
			}
		}

		foreach ( $posts as $post ) {
			$thumbnail_id  = get_post_thumbnail_id( $post->ID );
			$thumbnail_url = $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'full' ) : '';

			$row = [
				'post_id'        => (string) $post->ID,
				'post_title'     => $post->post_title,
				'post_excerpt'   => get_the_excerpt( $post ),
				'post_content'   => $post->post_content,
				'post_date'      => get_the_date( $date_format, $post ),
				'post_modified'  => get_the_modified_date( $date_format, $post ),
				'post_url'       => get_permalink( $post->ID ),
				'post_slug'      => $post->post_name,
				'post_author'    => get_the_author_meta( 'display_name', $post->post_author ),
				'thumbnail_url'  => $thumbnail_url,
				'thumbnail_id'   => (string) $thumbnail_id,
				'category_names' => implode( ', ', wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] ) ),
			];

			foreach ( $available_acf_keys as $fkey ) {
				$acf_name = substr( $fkey, 4 );
				$val = get_field( $acf_name, $post->ID );
				$row[ $fkey ] = is_array( $val ) ? ( $val['url'] ?? $val['ID'] ?? implode( ', ', array_filter( $val, 'is_scalar' ) ) ) : (string) $val;
			}

			$rows[] = $row;
		}

		return $rows;
	}
}
