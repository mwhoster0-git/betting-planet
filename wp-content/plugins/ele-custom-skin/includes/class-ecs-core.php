<?php
/**
 * ECS Core — main plugin singleton.
 *
 * Bootstraps the modules manager and the admin interface.
 * Access anywhere with ECS_Core::instance().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ECS_Core {

	private static ?ECS_Core $instance = null;

	private ECS_Modules_Manager $modules_manager;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->modules_manager = new ECS_Modules_Manager();

		if ( is_admin() ) {
			require_once ECS_PATH . 'admin/class-ecs-admin.php';
			new ECS_Admin( $this->modules_manager );
		}

		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'elementor/elements/categories_registered', [ $this, 'register_widget_category' ] );
	}

	public function register_widget_category( $elements_manager ): void {
		$elements_manager->add_category(
			'ele-custom-skin',
			[
				'title' => __( 'ECS Toolkit', 'ele-custom-skin' ),
				'icon'  => 'fa fa-plug',
			]
		);
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ele-custom-skin',
			false,
			dirname( plugin_basename( ECS_FILE ) ) . '/languages'
		);
	}

	public function modules(): ECS_Modules_Manager {
		return $this->modules_manager;
	}

	private function __clone() {}
	public function __wakeup() {
		throw new \Exception( 'ECS_Core cannot be unserialized.' );
	}
}
