<?php
/**
 * ECS Admin
 *
 * Registers the "ECS → Modules" page in WP Admin and handles
 * saving the module toggle settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Admin {

	private ECS_Modules_Manager $manager;

	public function __construct( ECS_Modules_Manager $manager ) {
		$this->manager = $manager;

		add_action( 'admin_menu',                [ $this, 'register_menu' ] );
		add_action( 'admin_post_ecs_save_modules', [ $this, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts',     [ $this, 'enqueue_assets' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'ECS - Ele Custom Skin', 'ele-custom-skin' ),
			'ECS',
			'manage_options',
			'ecs-modules',
			[ $this, 'render_modules_page' ],
			'data:image/svg+xml;base64,PHN2ZyB2aWV3Qm94PSIwIDAgMzQ1IDM5OCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBzdHlsZT0iZmlsbC1ydWxlOmV2ZW5vZGQ7Y2xpcC1ydWxlOmV2ZW5vZGQ7Ij4KICAgIDxnIHRyYW5zZm9ybT0ibWF0cml4KDAuNjA1OTM2LC0wLjM1NjI2OSwwLjQzMzI2NiwwLjczNjg5MSwtMTI3LjIyNjQ5Nyw0My45OTY4NzYpIj4KICAgICAgICA8cGF0aCBkPSJNNDUuNDk5LDQzMi45MzdDNDAuMDU5LDQzMy4wMzIgMzUuMDA1LDQzMC42MzMgMzIuMzgzLDQyNi43MTJMLTE1Ljc4NCwzNTQuNjc5Qy0yNC4wMjEsMzQzLjc2MyAtMjUuMDM3LDMyOS42NDggLTE3LjE0NywzMTcuNjcyQy0xNi43NjYsMzE3LjA4OCAxMDMuOTQyLDE0OC4yNzkgMTAzLjk0MiwxNDguMjc5QzEwOC4yOTEsMTQyLjE5NyAxMTQuNDQsMTM3LjUxOSAxMjEuNTA2LDEzNC40MzJDMTI3Ljg0NywxMzEuNjA2IDEzNS4xMDgsMTMwIDE0Mi44MjEsMTMwTDM4MC4xNzksMTMwQzQwNC45MTYsMTMwIDQyNSwxNDYuNTE1IDQyNSwxNjYuODU2TDQyNSwxNzIuMTQ0QzQyNSwxOTIuNDg1IDQwNC45MTYsMjA5IDM4MC4xNzksMjA5TDE4MC4zMjIsMjA5QzE3NS4xNSwyMDkgMTcwLjM2NCwyMTEuMjUxIDE2Ny43NDMsMjE0LjkxOEwxNjMuNjU4LDIyMC42M0MxNjEuMDA2LDIyNC4zNCAxNjAuOTcyLDIyOC45MzQgMTYzLjU3MSwyMzIuNjY5QzE2Ni4xNywyMzYuNDA1IDE3MS4wMDMsMjM4LjcxIDE3Ni4yMzcsMjM4LjcxTDI0OS4yNSwyMzguNzFDMjU0LjQ4LDIzOC43MSAyNTkuMzEsMjQxLjAxMyAyNjEuOTA4LDI0NC43NDdDMjY0LjUwNSwyNDguNDggMjY0LjQ3MiwyNTMuMDcxIDI2MS44MjEsMjU2Ljc3OUwyMjIuNDc4LDMxMS44MDJDMjE5Ljg2LDMxNS40NjIgMjE1LjA4MiwzMTcuNzEgMjA5LjkxOCwzMTcuNzFMMTAyLjYwMywzMTcuNzFDOTcuNDIzLDMxNy43MSA5Mi42MywzMTkuOTY1IDkwLjAwNSwzMjMuNjM2TDg1LjQzOSwzMzAuMDIyQzgyLjg0OSwzMzMuNjQ0IDgyLjc0OCwzMzguMTE1IDg1LjE3MiwzNDEuODEzTDEzMi40MjQsNDEzLjkyMkMxMzQuODM4LDQxNy42MDYgMTM0Ljc0Nyw0MjIuMDU4IDEzMi4xODUsNDI1LjY3NEMxMjkuNjIyLDQyOS4yODkgMTI0Ljk1Niw0MzEuNTQ5IDExOS44NjgsNDMxLjYzN0w0NS40OTksNDMyLjkzN1pNMzEyLjg4NywyNDQuNjM2QzMxNS41MTMsMjQwLjk2NSAzMjAuMzA2LDIzOC43MSAzMjUuNDg2LDIzOC43MUw0NDAuNjI4LDIzOC43MUM0NTcuNzQ2LDIzOC43MSA0NzIuNjM2LDI0Ni42MTggNDgwLjE4NCwyNTguMjNDNDgwLjg5OCwyNTkuMTA4IDUyMi4wMDksMzIwLjQ5NCA1MjIuMDA5LDMyMC40OTRDNTI4Ljc5MywzMzAuNjQgNTI5LjE0OSwzNDIuMjc2IDUyNC4yLDM1Mi4yMjdDNTIzLjU0NiwzNTQuMjk4IDUwNC42MzgsMzgxLjU1MiA0OTEuMTY1LDQwMC44MjhDNDg4LjY3OCw0MDQuNzY0IDQ4My43NTIsNDA3LjI0OCA0NzguMzU5LDQwNy4yODZDNDcyLjk2Niw0MDcuMzI0IDQ2Ny45ODgsNDA0LjkxIDQ2NS40Miw0MDEuMDFDNDQ5Ljk4OCwzNzcuNjUzIDQyNC41NzMsMzM5LjA2NCA0MTQuNjU0LDMyNC4wMDRDNDEyLjEsMzIwLjEyNiA0MDcuMTY3LDMxNy43MSA0MDEuODAzLDMxNy43MUwyODYuMTg0LDMxNy43MUMyODAuOTQyLDMxNy43MSAyNzYuMTAxLDMxNS40MDEgMjczLjQ5OCwzMTEuNjZDMjcwLjg5NSwzMDcuOTE4IDI3MC45MjksMzAzLjMxNyAyNzMuNTg1LDI5OS42MDFMMzEyLjg4NywyNDQuNjM2Wk00NTIuMjQyLDQ0NC4yMDFDNDU0LjY2OSw0NDcuOTAxIDQ1NC41NjksNDUyLjM3NCA0NTEuOTc4LDQ1NS45OThMNDA4LjgyOCw1MTYuMzQ1QzQwMS45OTcsNTI5LjUzIDM4Ni4wODgsNTM4Ljc4MyAzNjcuNTcxLDUzOC43ODNDMzY3LjU3MSw1MzguNzgzIDEyOS4xMjksNTM4LjM1MyAxMjUuNDQzLDUzNy41NDVDMTEyLjYwOCw1MzUuNjM0IDEwMC44ODcsNTI5LjE1OSA5NC4xMTksNTE5LjAzN0w2OC4xMzQsNDgwLjE3OEM2NS42NzIsNDc2LjQ5NSA2NS43MjgsNDcyLjAyMiA2OC4yODMsNDY4LjM4MUM3MC44MzgsNDY0Ljc0MSA3NS41Miw0NjIuNDYzIDgwLjYzLDQ2Mi4zNzNMMTk0LjIzLDQ2MC4zODlMMTk0LjIxNCw0NTkuNzgzTDMyOS40NjIsNDU5Ljc4M0MzMzQuNjQxLDQ1OS43ODMgMzM5LjQzNCw0NTcuNTI4IDM0Mi4wNiw0NTMuODU2TDM0NS45ODIsNDQ4LjM3MUMzNDguNjM5LDQ0NC42NTUgMzQ4LjY3Myw0NDAuMDU0IDM0Ni4wNyw0MzYuMzEyQzM0My40NjcsNDMyLjU3MSAzMzguNjI2LDQzMC4yNjIgMzMzLjM4NCw0MzAuMjYyTDMwNy4yMTgsNDMwLjI2MkMzMDEuOTc2LDQzMC4yNjIgMjk3LjEzNSw0MjcuOTU0IDI5NC41MzIsNDI0LjIxMkMyOTEuOTI5LDQyMC40NzEgMjkxLjk2Myw0MTUuODY5IDI5NC42Miw0MTIuMTU0TDMzMy45MjIsMzU3LjE4OUMzMzYuNTQ3LDM1My41MTcgMzQxLjM0LDM1MS4yNjIgMzQ2LjUyLDM1MS4yNjJMMzgyLjU0MiwzNTEuMjYyQzM4Ny45MTQsMzUxLjI2MiAzOTIuODUzLDM1My42ODYgMzk1LjQwNCwzNTcuNTczTDQ1Mi4yNDIsNDQ0LjIwMVpNMTQ0LjQ0MSwzNjguOTg2QzE0MS45OTcsMzY1LjI2MiAxNDIuMTE2LDM2MC43NTUgMTQ0Ljc1MywzNTcuMTIxQzE0Ny4zOSwzNTMuNDg4IDE1Mi4xNTcsMzUxLjI2MiAxNTcuMzAzLDM1MS4yNjJMMjcwLjIyOSwzNTEuMjYyQzI3NS40NzEsMzUxLjI2MiAyODAuMzExLDM1My41NzEgMjgyLjkxNCwzNTcuMzEyQzI4NS41MTcsMzYxLjA1NCAyODUuNDg0LDM2NS42NTUgMjgyLjgyNywzNjkuMzcxTDI0My41MjUsNDI0LjMzNkMyNDAuODk5LDQyOC4wMDcgMjM2LjEwNiw0MzAuMjYyIDIzMC45MjcsNDMwLjI2MkwxOTMuMzY2LDQzMC4yNjJDMTg3Ljk5NCw0MzAuMjYyIDE4My4wNTUsNDI3LjgzOSAxODAuNTA0LDQyMy45NTFMMTQ0LjQ0MSwzNjguOTg2WiIgZmlsbD0iI2E3YWFhZCIvPgogICAgPC9nPgo8L3N2Zz4=',
			58
		);

		add_submenu_page(
			'ecs-modules',
			__( 'Modules', 'ele-custom-skin' ),
			__( 'Modules', 'ele-custom-skin' ),
			'manage_options',
			'ecs-modules',
			[ $this, 'render_modules_page' ]
		);

		add_submenu_page(
			'ecs-modules',
			__( 'License', 'ele-custom-skin' ),
			__( 'License', 'ele-custom-skin' ),
			'manage_options',
			'ecs-license',
			[ $this, 'render_license_page' ]
		);
	}

	public function render_modules_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'ele-custom-skin' ) );
		}
		require_once ECS_PATH . 'admin/views/modules-page.php';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ele-custom-skin' ) );
		}

		check_admin_referer( 'ecs_save_modules' );

		$posted = isset( $_POST['ecs_modules'] ) ? (array) $_POST['ecs_modules'] : [];
		$ids    = array_map( 'sanitize_key', $posted );

		$this->manager->set_active( $ids );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'ecs-modules', 'updated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_license_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'ele-custom-skin' ) );
		}
		require_once ECS_PATH . 'admin/views/license-page.php';
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'toplevel_page_ecs-modules', 'ecs_page_ecs-license' ], true ) ) {
			return;
		}
		wp_enqueue_style(
			'ecs-admin',
			ecs_asset_url( ECS_URL . 'assets/admin/css/ecs-admin.css', ECS_PATH . 'assets/admin/css/ecs-admin.css' ),
			[],
			ECS_VERSION
		);
		wp_enqueue_script(
			'ecs-admin',
			ecs_asset_url( ECS_URL . 'assets/admin/js/ecs-admin.js', ECS_PATH . 'assets/admin/js/ecs-admin.js' ),
			[],
			ECS_VERSION,
			true
		);
	}
}
