<?php
/**
 * ECS Modules Manager
 *
 * Registers all available modules, reads the active-modules option,
 * boots active modules, and handles first-run migration logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Modules_Manager {

	/** @var ECS_Module_Base[] All registered module instances, keyed by module ID */
	private array $modules = [];

	/** @var string[] IDs of currently active modules */
	private array $active_ids = [];

	const OPTION_KEY = 'ecs_active_modules';

	public function __construct() {
		// 1. Register built-in (free) modules first.
		$this->register_core_modules();

		// 2. Set defaults for free modules before external plugins can modify the option.
		$this->maybe_set_defaults();

		// 3. Let external plugins (e.g. ECS Pro) register their modules.
		//    Pro's auto-activation merges with the already-set free defaults.
		do_action( 'ecs_register_modules', $this );

		// 4. Read the final active list and boot.
		$this->active_ids = (array) get_option( self::OPTION_KEY, [] );
		$this->boot_active_modules();
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	private function register_core_modules(): void {
		$module_files = [
			'legacy'             => ECS_PATH . 'modules/legacy/class-ecs-legacy-module.php',
			'color-scheme'       => ECS_PATH . 'modules/color-scheme/class-ecs-color-scheme-module.php',
			'container-layout'   => ECS_PATH . 'modules/container-layout/class-ecs-container-layout-module.php',
			'loop-custom-layout' => ECS_PATH . 'modules/loop-custom-layout/class-ecs-loop-custom-layout-module.php',
			'mobile-menu'        => ECS_PATH . 'modules/mobile-menu/class-ecs-mobile-menu-module.php',
			'editorial-text'     => ECS_PATH . 'modules/editorial-text/class-ecs-editorial-text-module.php',
			'style-templates'    => ECS_PATH . 'modules/style-templates/class-ecs-style-templates-module.php',
			'json-poweredit'       => ECS_PATH . 'modules/json-poweredit/class-ecs-json-poweredit-module.php',
			'dynamic-repeater'     => ECS_PATH . 'modules/dynamic-repeater/class-ecs-dynamic-repeater-module.php',
			'color-wheel'          => ECS_PATH . 'modules/color-wheel/class-ecs-colorwheel-module.php',
		];

		foreach ( $module_files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		$module_classes = [
			'ECS_Legacy_Module',
			'ECS_Color_Scheme_Module',
			'ECS_Container_Layout_Module',
			'ECS_Loop_Custom_Layout_Module',
			'ECS_Mobile_Menu_Module',
			'ECS_Editorial_Text_Module',
			'ECS_Style_Templates_Module',
			'ECS_JSON_PowerEdit_Module',
			'ECS_Dynamic_Repeater_Module',
			'ECS_Colorwheel_Module',
		];

		foreach ( $module_classes as $class ) {
			if ( class_exists( $class ) ) {
				$module = new $class();
				$this->modules[ $module->get_id() ] = $module;
			}
		}
	}

	/**
	 * Register a single module instance from an external plugin.
	 *
	 * @param ECS_Module_Base $module
	 */
	public function register_module( ECS_Module_Base $module ): void {
		$this->modules[ $module->get_id() ] = $module;
	}

	// -------------------------------------------------------------------------
	// Migration / first-run defaults
	// -------------------------------------------------------------------------

	/**
	 * On first run of ECS 4.0, decide which modules are active by default.
	 *
	 * - Fresh install              → all active EXCEPT legacy
	 * - Update from ECS 3.x        → all active INCLUDING legacy
	 *   (detected by presence of loop templates created with the old ECS)
	 */
	private function maybe_set_defaults(): void {
		if ( false !== get_option( self::OPTION_KEY ) ) {
			return;
		}

		$all_ids = array_keys( $this->modules );

		// Detect old ECS usage: loop templates in the library.
		$has_loop_templates = (bool) get_posts( [
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'meta_key'       => '_elementor_template_type',
			'meta_value'     => 'loop',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );

		if ( $has_loop_templates ) {
			// Update from ECS 3.x — activate everything including legacy.
			$defaults = $all_ids;
		} else {
			// Fresh install — activate everything except legacy.
			$defaults = array_values( array_filter( $all_ids, fn( $id ) => $id !== 'legacy' ) );
		}

		update_option( self::OPTION_KEY, $defaults );
	}

	// -------------------------------------------------------------------------
	// Booting
	// -------------------------------------------------------------------------

	private function boot_active_modules(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->is_active( $id ) ) {
				continue;
			}

			$module->boot();

			add_action( 'elementor/widgets/register',          [ $module, 'register_widgets' ] );
			add_action( 'elementor/controls/register',         [ $module, 'register_controls' ] );
			add_action( 'wp_enqueue_scripts',                       [ $module, 'enqueue_frontend_assets' ] );
			add_action( 'elementor/preview/enqueue_styles',        [ $module, 'enqueue_frontend_assets' ] );
			add_action( 'elementor/editor/after_enqueue_scripts',  [ $module, 'enqueue_editor_assets' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/** @return ECS_Module_Base[] */
	public function get_all(): array {
		return $this->modules;
	}

	public function get( string $id ): ?ECS_Module_Base {
		return $this->modules[ $id ] ?? null;
	}

	public function is_active( string $id ): bool {
		return in_array( $id, $this->active_ids, true );
	}

	/** @param string[] $ids */
	public function set_active( array $ids ): void {
		$valid            = array_keys( $this->modules );
		$ids              = array_values( array_intersect( $ids, $valid ) );
		$this->active_ids = $ids;
		update_option( self::OPTION_KEY, $ids );
	}
}
