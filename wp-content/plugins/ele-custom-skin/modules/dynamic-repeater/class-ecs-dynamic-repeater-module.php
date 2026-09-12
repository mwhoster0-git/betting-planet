<?php
/**
 * Module: Dynamic Repeater Builder
 *
 * Populates any Elementor repeater from Posts, Terms, ACF Repeater, or Raw JSON.
 * Supports one-time generation in the editor and runtime binding on the frontend.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Dynamic_Repeater_Module extends ECS_Module_Base {

	const OPTION_PRESETS       = 'ecs_drb_presets';
	const META_BINDINGS        = '_ecs_drb_bindings';
	const OPTION_BINDING_INDEX = 'ecs_drb_binding_index'; // element_id:control_key → doc_post_id
	const CACHE_PREFIX         = 'ecs_drb_src_';
	const CACHE_TTL            = 300;

	// ── Identity ──────────────────────────────────────────────────────────────

	public function get_id(): string {
		return 'dynamic_repeater';
	}

	public function get_title(): string {
		return __( 'Dynamic Repeater Builder', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Populate any Elementor repeater from Posts, Terms, ACF, or JSON. Supports one-time generation and runtime data binding.', 'ele-custom-skin' );
	}

	public function is_pro(): bool {
		return true;
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	public function boot(): void {
		$this->load_dependencies();

		$ajax_actions = [
			'ecs_drb_get_source_schema',
			'ecs_drb_get_available_fields',
			'ecs_drb_generate',
			'ecs_drb_get_presets',
			'ecs_drb_save_preset',
			'ecs_drb_delete_preset',
			'ecs_drb_save_binding',
			'ecs_drb_break_binding',
			'ecs_drb_get_bindings',
			'ecs_drb_get_terms',
			'ecs_drb_get_acf_repeaters',
		];

		foreach ( $ajax_actions as $action ) {
			add_action( "wp_ajax_{$action}", [ $this, 'handle_ajax' ] );
		}

		// Intercept _elementor_data reads to inject binding data before Elementor
		// parses the JSON into element objects — this is the earliest possible point.
		add_filter( 'get_post_metadata', [ $this, 'filter_elementor_data' ], 10, 4 );

		// Prevent Elementor from using its element-level HTML cache for documents
		// with bindings — each post must render fresh so our injected data applies.
		add_filter( 'get_post_metadata', [ $this, 'suppress_element_cache' ], 10, 4 );
	}

	private function load_dependencies(): void {
		$src = $this->module_path() . 'sources/';
		require_once $src . 'class-ecs-drb-source-base.php';
		require_once $src . 'class-ecs-drb-source-posts.php';
		require_once $src . 'class-ecs-drb-source-terms.php';
		require_once $src . 'class-ecs-drb-source-acf.php';
		require_once $src . 'class-ecs-drb-source-json.php';
		require_once $this->module_path() . 'class-ecs-drb-sources.php';
		require_once $this->module_path() . 'class-ecs-drb-mapper.php';

		$sources = ECS_DRB_Sources::instance();
		$sources->register( new ECS_DRB_Source_Posts() );
		$sources->register( new ECS_DRB_Source_Terms() );
		$sources->register( new ECS_DRB_Source_ACF() );
		$sources->register( new ECS_DRB_Source_JSON() );

		do_action( 'ecs_drb_register_sources', $sources );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_editor_assets(): void {
		wp_enqueue_style(
			'ecs-drb-editor',
			$this->module_asset_url( 'assets/css/ecs-dynamic-repeater-editor.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-drb-editor',
			$this->module_asset_url( 'assets/js/ecs-dynamic-repeater-editor.js' ),
			[ 'jquery', 'elementor-editor' ],
			ECS_VERSION,
			true
		);

		wp_localize_script( 'ecs-drb-editor', 'ecsDrb', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'ecs_drb' ),
			'sources'      => ECS_DRB_Sources::instance()->get_list_for_js(),
			'bindingIndex' => (object) get_option( self::OPTION_BINDING_INDEX, [] ),
			'l10n'         => [
				'title'          => __( 'Dynamic Repeater Builder', 'ele-custom-skin' ),
				'btnLabel'       => __( 'Dynamic Populate', 'ele-custom-skin' ),
				'tabSource'      => __( 'Source', 'ele-custom-skin' ),
				'tabMapping'     => __( 'Mapping', 'ele-custom-skin' ),
				'tabOptions'     => __( 'Options', 'ele-custom-skin' ),
				'tabPresets'     => __( 'Presets', 'ele-custom-skin' ),
				'tabBinding'     => __( 'Binding', 'ele-custom-skin' ),
				'generate'       => __( 'Generate', 'ele-custom-skin' ),
				'apply'          => __( 'Apply', 'ele-custom-skin' ),
				'preview'        => __( 'Preview', 'ele-custom-skin' ),
				'loading'        => __( 'Loading…', 'ele-custom-skin' ),
				'fetchFields'    => __( 'Fill from First Item', 'ele-custom-skin' ),
				'noFields'       => __( 'Configure source first, then fetch available fields.', 'ele-custom-skin' ),
				'savePreset'     => __( 'Save as Preset', 'ele-custom-skin' ),
				'loadPreset'     => __( 'Load', 'ele-custom-skin' ),
				'deletePreset'   => __( 'Delete', 'ele-custom-skin' ),
				'bindToSource'   => __( 'Bind to Source', 'ele-custom-skin' ),
				'breakSync'      => __( 'Break Sync', 'ele-custom-skin' ),
				'refreshNow'     => __( 'Refresh Now', 'ele-custom-skin' ),
				'bound'          => __( 'Bound — data is generated at runtime on every page load.', 'ele-custom-skin' ),
				'notBound'       => __( 'Not bound. Apply once or bind to enable runtime generation.', 'ele-custom-skin' ),
				'autoSuggest'    => __( 'Auto-suggest Mapping', 'ele-custom-skin' ),
				'previewRows'    => __( '%d rows generated', 'ele-custom-skin' ),
				'appliedRows'    => __( '%d rows applied to repeater', 'ele-custom-skin' ),
				'cached'         => __( '(cached)', 'ele-custom-skin' ),
			],
		] );
	}

	// ── AJAX dispatcher ───────────────────────────────────────────────────────

	public function handle_ajax(): void {
		$action = preg_replace( '/^wp_ajax_(nopriv_)?ecs_drb_/', '', current_action() );
		$method = 'ajax_' . $action;
		if ( method_exists( $this, $method ) ) {
			$this->$method();
		} else {
			wp_send_json_error( [ 'message' => 'Unknown action' ] );
		}
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * Every handler is reachable by anyone who can read the 'ecs_drb' nonce,
	 * which Elementor prints for any user who can open the editor (including
	 * Contributors). This enforces the actual capability required for the
	 * data the handler touches: base editing rights for read/preview
	 * operations, ownership of the target post for binding read/write, and
	 * a site-level capability for the presets option (which is not scoped
	 * to any post).
	 */
	private function check_capability( string $capability, int $object_id = 0 ): void {
		$allowed = $object_id ? current_user_can( $capability, $object_id ) : current_user_can( $capability );
		if ( ! $allowed ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}
	}

	/**
	 * Several source configs accept a 'post_id' to read data from a specific
	 * post (currently the ACF source), independent of any post ownership
	 * already checked elsewhere (e.g. the post a binding is saved on). The
	 * ACF source falls back to '_current_post_id' when 'post_id' is empty,
	 * and that key can arrive either already inside the decoded config or
	 * merged in afterwards from the request's own current_post_id — both are
	 * attacker-controlled, so this must be called on the FINAL config, after
	 * any such merging, not on the config as first decoded from the request.
	 */
	private function resolve_config_post_id( array $config ): int {
		if ( ! empty( $config['post_id'] ) ) {
			return intval( $config['post_id'] );
		}
		if ( ! empty( $config['_current_post_id'] ) ) {
			return intval( $config['_current_post_id'] );
		}
		return 0;
	}

	private function check_source_config_post_capability( array $config ): void {
		$post_id = $this->resolve_config_post_id( $config );
		if ( ! $post_id ) {
			return;
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}
	}

	/**
	 * Same resolution as check_source_config_post_capability(), but for the
	 * frontend runtime binding path: returns a bool instead of dying, and
	 * accepts a fallback post id (the post actually being viewed) for when
	 * the config supplies neither post_id nor _current_post_id.
	 */
	private function current_viewer_can_read_config_post( array $config, int $fallback_post_id = 0 ): bool {
		$post_id = $this->resolve_config_post_id( $config ) ?: $fallback_post_id;
		if ( ! $post_id ) {
			return true;
		}
		return current_user_can( 'read_post', $post_id );
	}

	private function ajax_get_source_schema(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );

		$source_id     = sanitize_key( $_POST['source_type'] ?? '' );
		$source_config = json_decode( stripslashes( $_POST['source_config'] ?? '{}' ), true ) ?: [];
		$this->check_source_config_post_capability( $source_config );

		$source = ECS_DRB_Sources::instance()->get( $source_id );
		if ( ! $source ) {
			wp_send_json_error( [ 'message' => 'Unknown source type: ' . $source_id ] );
		}

		wp_send_json_success( [
			'schema' => $source->get_config_schema(),
			'fields' => $source->get_available_fields( $source_config ),
		] );
	}

	private function ajax_get_available_fields(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );

		$source_id       = sanitize_key( $_POST['source_type'] ?? '' );
		$source_config   = json_decode( stripslashes( $_POST['source_config'] ?? '{}' ), true ) ?: [];
		$current_post_id = intval( $_POST['current_post_id'] ?? 0 );
		if ( $current_post_id && empty( $source_config['post_id'] ) ) {
			$source_config['_current_post_id'] = $current_post_id;
		}
		$this->check_source_config_post_capability( $source_config );

		$source = ECS_DRB_Sources::instance()->get( $source_id );
		if ( ! $source ) {
			wp_send_json_error( [ 'message' => 'Unknown source type' ] );
		}

		wp_send_json_success( [ 'fields' => $source->get_available_fields( $source_config ) ] );
	}

	private function ajax_generate(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );

		$source_id      = sanitize_key( $_POST['source_type'] ?? '' );
		$source_config  = json_decode( stripslashes( $_POST['source_config'] ?? '{}' ), true ) ?: [];
		$mapping        = json_decode( stripslashes( $_POST['mapping'] ?? '[]' ), true ) ?: [];
		$field_types    = json_decode( stripslashes( $_POST['field_types'] ?? '{}' ), true ) ?: [];
		$use_cache      = filter_var( $_POST['use_cache'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$cache_ttl      = intval( $_POST['cache_ttl'] ?? self::CACHE_TTL );
		$current_post_id = intval( $_POST['current_post_id'] ?? 0 );
		if ( $current_post_id && empty( $source_config['post_id'] ) ) {
			$source_config['_current_post_id'] = $current_post_id;
		}
		$this->check_source_config_post_capability( $source_config );

		$source = ECS_DRB_Sources::instance()->get( $source_id );
		if ( ! $source ) {
			wp_send_json_error( [ 'message' => 'Unknown source type' ] );
		}

		$cache_key   = self::CACHE_PREFIX . md5( $source_id . wp_json_encode( $source_config ) );
		$source_rows = false;

		if ( $use_cache ) {
			$source_rows = get_transient( $cache_key );
		}

		if ( false === $source_rows ) {
			$source_rows = $source->get_rows( $source_config );
			if ( $use_cache ) {
				set_transient( $cache_key, $source_rows, max( 60, $cache_ttl ) );
			}
		}

		$rows = ECS_DRB_Mapper::resolve( $source_rows, $mapping, $field_types );

		wp_send_json_success( [
			'rows'         => $rows,
			'source_count' => count( $source_rows ),
			'result_count' => count( $rows ),
			'cached'       => isset( $source_rows ) && get_transient( $cache_key ) !== false,
		] );
	}

	private function ajax_get_presets(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );
		wp_send_json_success( [ 'presets' => array_values( (array) get_option( self::OPTION_PRESETS, [] ) ) ] );
	}

	private function ajax_save_preset(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		// Presets are a site-wide option, not scoped to any post — ownership
		// checks don't apply, so this requires a site-level capability instead.
		$this->check_capability( 'edit_theme_options' );

		$name   = sanitize_text_field( $_POST['name'] ?? '' );
		$config = json_decode( stripslashes( $_POST['config'] ?? '{}' ), true ) ?: [];

		if ( empty( $name ) ) {
			wp_send_json_error( [ 'message' => 'Preset name is required' ] );
		}

		$presets = (array) get_option( self::OPTION_PRESETS, [] );
		$id = 'preset_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$presets[ $id ] = [
			'id'      => $id,
			'name'    => $name,
			'config'  => $config,
			'created' => time(),
		];
		update_option( self::OPTION_PRESETS, $presets );

		wp_send_json_success( [ 'id' => $id, 'presets' => array_values( $presets ) ] );
	}

	private function ajax_delete_preset(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_theme_options' );

		$id = sanitize_key( $_POST['id'] ?? '' );
		$presets = (array) get_option( self::OPTION_PRESETS, [] );
		unset( $presets[ $id ] );
		update_option( self::OPTION_PRESETS, $presets );

		wp_send_json_success( [ 'presets' => array_values( $presets ) ] );
	}

	private function ajax_save_binding(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );

		$post_id     = intval( $_POST['post_id'] ?? 0 );
		$element_id  = sanitize_text_field( $_POST['element_id'] ?? '' );
		$control_key = sanitize_text_field( $_POST['control_key'] ?? '' );
		$config      = json_decode( stripslashes( $_POST['config'] ?? '{}' ), true ) ?: [];

		$this->check_capability( 'edit_post', $post_id );

		if ( ! $post_id || ! $element_id || ! $control_key ) {
			wp_send_json_error( [ 'message' => 'Missing required parameters' ] );
		}

		$bindings = (array) get_post_meta( $post_id, self::META_BINDINGS, true );
		$key = $element_id . ':' . $control_key;
		$bindings[ $key ] = [
			'element_id'  => $element_id,
			'control_key' => $control_key,
			'config'      => $config,
			'updated'     => time(),
		];
		update_post_meta( $post_id, self::META_BINDINGS, $bindings );

		// Update global index: element_key → document post_id.
		$index         = (array) get_option( self::OPTION_BINDING_INDEX, [] );
		$index[ $key ] = $post_id;
		update_option( self::OPTION_BINDING_INDEX, $index, false );

		wp_send_json_success( [ 'binding_key' => $key ] );
	}

	private function ajax_break_binding(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );

		$post_id     = intval( $_POST['post_id'] ?? 0 );
		$element_id  = sanitize_text_field( $_POST['element_id'] ?? '' );
		$control_key = sanitize_text_field( $_POST['control_key'] ?? '' );

		$this->check_capability( 'edit_post', $post_id );

		$bindings = (array) get_post_meta( $post_id, self::META_BINDINGS, true );
		$key      = $element_id . ':' . $control_key;
		unset( $bindings[ $key ] );
		update_post_meta( $post_id, self::META_BINDINGS, $bindings );

		// Remove from global index.
		$index = (array) get_option( self::OPTION_BINDING_INDEX, [] );
		unset( $index[ $key ] );
		update_option( self::OPTION_BINDING_INDEX, $index, false );

		wp_send_json_success();
	}

	private function ajax_get_bindings(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );

		$post_id = intval( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_success( [ 'bindings' => [] ] );
			return;
		}

		$this->check_capability( 'edit_post', $post_id );

		$bindings = (array) get_post_meta( $post_id, self::META_BINDINGS, true );

		wp_send_json_success( [ 'bindings' => array_values( $bindings ) ] );
	}

	private function ajax_get_terms(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );

		$taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
		$search   = sanitize_text_field( $_POST['search'] ?? '' );

		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_success( [ 'terms' => [] ] );
			return;
		}

		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 30,
			'search'     => $search,
			'fields'     => 'all',
		] );

		if ( is_wp_error( $terms ) ) {
			wp_send_json_success( [ 'terms' => [] ] );
			return;
		}

		$result = array_map( fn( $t ) => [
			'slug'  => $t->slug,
			'name'  => $t->name,
			'count' => $t->count,
		], $terms );

		wp_send_json_success( [ 'terms' => $result ] );
	}

	private function ajax_get_acf_repeaters(): void {
		check_ajax_referer( 'ecs_drb', 'nonce' );
		$this->check_capability( 'edit_posts' );
		wp_send_json_success( [ 'fields' => ECS_DRB_Source_ACF::get_repeater_field_names() ] );
	}

	// ── Runtime binding ───────────────────────────────────────────────────────

	/**
	 * Return null for _elementor_element_cache reads on any document that contains
	 * bound elements. This prevents Elementor from serving a cached container HTML
	 * that was built for a different post's data.
	 */
	public function suppress_element_cache( $value, $object_id, $meta_key, $single = null ) {
		if ( $meta_key !== '_elementor_element_cache' ) {
			return $value;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return $value;
		}
		$index = (array) get_option( self::OPTION_BINDING_INDEX, [] );
		if ( empty( $index ) ) {
			return $value;
		}
		foreach ( $index as $doc_post_id ) {
			if ( (int) $doc_post_id === $object_id ) {
				// Return empty array — Elementor treats this as "no cache".
				return $single ? '' : [];
			}
		}
		return $value;
	}

	/**
	 * Filter _elementor_data postmeta to inject binding data before Elementor
	 * parses the JSON. This is earlier than any widget render hook and ensures
	 * the repeater settings are correct from the moment the element is built.
	 *
	 * Only runs on the frontend; skipped in editor and AJAX (except Elementor
	 * frontend-preview AJAX which also renders on the frontend).
	 */
	public function filter_elementor_data( $value, $object_id, $meta_key, $single = null ) {
		if ( $meta_key !== '_elementor_data' ) {
			return $value;
		}

		// Only intercept on regular frontend page loads.
		if ( is_admin() || wp_doing_ajax() ) {
			return $value;
		}
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return $value;
		}

		$index = (array) get_option( self::OPTION_BINDING_INDEX, [] );
		if ( empty( $index ) ) {
			return $value;
		}

		// Check if this post_id is a document that contains bound elements.
		$bound_in_doc = [];
		foreach ( $index as $index_key => $doc_post_id ) {
			if ( (int) $doc_post_id === $object_id ) {
				$bound_in_doc[ $index_key ] = true;
			}
		}
		if ( empty( $bound_in_doc ) ) {
			return $value;
		}

		// Load the real postmeta value (bypass this filter to avoid recursion).
		remove_filter( 'get_post_metadata', [ $this, 'filter_elementor_data' ], 10 );
		$raw = get_post_meta( $object_id, '_elementor_data', true );
		add_filter( 'get_post_metadata', [ $this, 'filter_elementor_data' ], 10, 4 );

		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return $value;
		}

		// Load all bindings for this document.
		$doc_bindings = (array) get_post_meta( $object_id, self::META_BINDINGS, true );
		if ( empty( $doc_bindings ) ) {
			return $value;
		}

		$current_post = get_queried_object_id() ?: get_the_ID();

		// Walk the element tree and inject rows where bound.
		$modified = $this->inject_binding_rows( $elements, $doc_bindings, $current_post );

		// Return as a single-element array (how WP returns $single=true metadata).
		if ( $single ) {
			return wp_json_encode( $modified );
		}
		return [ wp_json_encode( $modified ) ];
	}

	/**
	 * Sanitize bound row values recursively. Row values can be plain strings,
	 * or nested arrays for control types like icon/media. Non-string leaves
	 * (ids, booleans, numbers) pass through unchanged.
	 */
	private function sanitize_bound_row( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ $this, 'sanitize_bound_row' ], $value );
		}
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		return $value;
	}

	/**
	 * Recursively walk the Elementor element tree and replace repeater settings
	 * for any element that has a binding configured.
	 */
	private function inject_binding_rows( array $elements, array $doc_bindings, int $current_post ): array {
		foreach ( $elements as &$element ) {
			$element_id = $element['id'] ?? '';

			// Check if this element has any binding.
			foreach ( $doc_bindings as $binding ) {
				if ( ( $binding['element_id'] ?? '' ) !== $element_id ) {
					continue;
				}

				$cfg         = $binding['config'] ?? [];
				$source_id   = sanitize_key( $cfg['source_type'] ?? '' );
				$s_config    = $cfg['source_config'] ?? [];
				$mapping     = $cfg['mapping'] ?? [];
				$field_types = $cfg['field_types'] ?? [];
				$apply_mode  = $cfg['apply_mode'] ?? 'replace';
				$use_cache   = (bool) ( $cfg['use_cache'] ?? true );
				$cache_ttl   = intval( $cfg['cache_ttl'] ?? self::CACHE_TTL );
				$control_key = $binding['control_key'] ?? '';

				if ( ! $control_key || ! $source_id ) {
					continue;
				}

				$source = ECS_DRB_Sources::instance()->get( $source_id );
				if ( ! $source ) {
					continue;
				}

				// Whoever configured this binding chose post_id (or
				// _current_post_id) in source_config, and it can point at any
				// post, independent of the post this binding itself is saved
				// on. Resolved the same way the source itself resolves it
				// (post_id, then _current_post_id, then the post actually
				// being viewed) so this covers it regardless of which of
				// those ends up supplying the id. Since this filter renders
				// on the frontend for every visitor, skip the binding instead
				// of exposing data the current viewer — who may be anonymous
				// — is not allowed to read.
				if ( ! $this->current_viewer_can_read_config_post( $s_config, $current_post ) ) {
					continue;
				}

				$runtime_post_id = empty( $s_config['post_id'] ) ? $current_post : 0;
				$cache_key       = self::CACHE_PREFIX . md5( $source_id . wp_json_encode( $s_config ) . ':' . $runtime_post_id );
				$source_rows     = false;

				if ( $use_cache ) {
					$source_rows = get_transient( $cache_key );
				}

				if ( false === $source_rows ) {
					if ( empty( $s_config['post_id'] ) && $current_post ) {
						$s_config['_current_post_id'] = $current_post;
					}
					$source_rows = $source->get_rows( $s_config );
					if ( $use_cache ) {
						set_transient( $cache_key, $source_rows, max( 60, $cache_ttl ) );
					}
				}

				if ( empty( $source_rows ) ) {
					continue;
				}

				$new_rows = ECS_DRB_Mapper::resolve( $source_rows, $mapping, $field_types );

				// This runtime injection bypasses Elementor's own save-time
				// wp_kses on widget settings, since the values never go through
				// the normal editor save flow — sanitize them here instead, or a
				// bound JSON/Posts/Terms/ACF source could stored-XSS every
				// visitor who views the page.
				$new_rows = $this->sanitize_bound_row( $new_rows );

				if ( $apply_mode === 'append' ) {
					$existing = $element['settings'][ $control_key ] ?? [];
					$new_rows = array_merge( is_array( $existing ) ? $existing : [], $new_rows );
				}

				// Elementor expects each repeater row to have a unique _id.
				foreach ( $new_rows as &$row ) {
					if ( empty( $row['_id'] ) ) {
						$row['_id'] = substr( md5( uniqid( '', true ) ), 0, 7 );
					}
				}
				unset( $row );

				$element['settings'][ $control_key ] = $new_rows;
			}

			// Recurse into child elements.
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->inject_binding_rows( $element['elements'], $doc_bindings, $current_post );
			}
		}
		unset( $element );

		return $elements;
	}
}
