<?php
/**
 * Dynamic Repeater Builder — Mapper
 *
 * Converts source rows into Elementor-compatible repeater rows
 * using a field mapping configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_DRB_Mapper {

	/**
	 * Resolve source rows into Elementor-compatible repeater rows.
	 *
	 * @param array $source_rows  Raw rows from a source.
	 * @param array $mapping      Array of mapping rules.
	 * @param array $field_types  Map of repeater_field_key => Elementor control type.
	 *                            e.g. [ 'link' => 'url', 'image' => 'media' ]
	 * @return array
	 */
	public static function resolve( array $source_rows, array $mapping, array $field_types = [] ): array {
		$active_mapping = array_filter( $mapping, fn( $r ) => ! empty( $r['repeater_field'] ) && ! empty( $r['source_field'] ) );

		$result = [];
		foreach ( $source_rows as $source_row ) {
			$row = [];
			foreach ( $active_mapping as $rule ) {
				$resolved = self::resolve_field( $source_row, $rule );
				if ( null !== $resolved ) {
					$field_key  = $rule['repeater_field'];
					$base_key   = preg_replace( '/\[(id|url)\]$/', '', $field_key );
					// Sub-key rows (image[id], image[url]) must stay as raw strings —
					// their wrapping into ['id'=>N,'url'=>'...'] happens at the merge step.
					$field_type = ( $field_key !== $base_key )
						? 'text'
						: ( $field_types[ $field_key ] ?? 'text' );
					$row[ $field_key ] = self::wrap_value( $resolved, $field_type );
				}
			}

			// Merge image sub-keys: 'img[id]' + 'img[url]' → 'img' => ['id' => N, 'url' => '...']
			$merged = [];
			foreach ( $row as $key => $val ) {
				if ( preg_match( '/^(.+)\[(id|url)\]$/', $key, $m ) ) {
					$base = $m[1];
					$sub  = $m[2];
					if ( ! isset( $merged[ $base ] ) ) {
						$merged[ $base ] = [ 'id' => 0, 'url' => '' ];
					}
					$merged[ $base ][ $sub ] = $sub === 'id' ? intval( $val ) : (string) $val;
				} else {
					$merged[ $key ] = $val;
				}
			}

			if ( ! empty( $merged ) ) {
				$result[] = $merged;
			}
		}

		return $result;
	}

	/**
	 * Wrap a resolved string value into the format Elementor expects
	 * for the given control type.
	 *
	 * @param string $value      Resolved string value.
	 * @param string $field_type Elementor control type (url, media, image, text, …).
	 * @return string|array
	 */
	private static function wrap_value( string $value, string $field_type ) {
		switch ( $field_type ) {
			case 'url':
				return [
					'url'               => $value,
					'is_external'       => '',
					'nofollow'          => '',
					'custom_attributes' => '',
				];

			case 'media':
			case 'image':
				// Numeric value → treat as attachment ID and resolve the URL.
				if ( is_numeric( $value ) && intval( $value ) > 0 ) {
					$id  = intval( $value );
					$url = wp_get_attachment_url( $id ) ?: '';
					return [ 'id' => $id, 'url' => $url ];
				}
				// URL string → use directly with id = 0.
				return [ 'id' => 0, 'url' => $value ];

			default:
				return $value;
		}
	}

	/**
	 * Resolve a single field from a source row.
	 * Returns null when the value is empty and skip_if_empty is true,
	 * signalling that the field should be omitted from the row entirely.
	 */
	private static function resolve_field( array $source_row, array $rule ): ?string {
		$source_field  = $rule['source_field'] ?? '';
		$before        = $rule['before']        ?? '';
		$after         = $rule['after']         ?? '';
		$fallback      = $rule['fallback']       ?? '';
		$skip_if_empty = (bool) ( $rule['skip_if_empty'] ?? false );

		// Template mode: "{post_title} by {post_author}" — replace each {key}.
		if ( strpos( $source_field, '{' ) !== false ) {
			$value = preg_replace_callback(
				'/\{([a-zA-Z0-9_]+)\}/',
				function ( $m ) use ( $source_row ) {
					$raw = $source_row[ $m[1] ] ?? '';
					if ( is_array( $raw ) ) {
						$raw = $raw['url'] ?? $raw['ID'] ?? implode( ', ', array_filter( $raw, 'is_scalar' ) );
					}
					return (string) $raw;
				},
				$source_field
			);
			$value = trim( $value );
		} else {
			// Simple field key.
			$value = $source_row[ $source_field ] ?? '';
			if ( is_array( $value ) ) {
				$value = $value['url'] ?? $value['ID'] ?? implode( ', ', array_filter( $value, 'is_scalar' ) );
			}
			$value = trim( (string) $value );
		}

		if ( $value === '' ) {
			if ( $fallback !== '' ) {
				// Use fallback, but do NOT apply before/after around it —
				// fallback is meant as a literal replacement, not a formatted value.
				return $fallback;
			}
			if ( $skip_if_empty ) {
				return null;
			}
			return '';
		}

		return $before . $value . $after;
	}
}
