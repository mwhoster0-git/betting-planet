<?php
/**
 * Module: Alternative Color Scheme
 *
 * Provides:
 *  - CSS custom properties prefixed with --ecs-
 *  - Scheme toggling via <html data-ecs-scheme="alt">
 *  - ECS Color Switcher Elementor widget
 *  - Cookie-based persistence (cache-safe, readable server-side)
 *  - Anti-FOUC inline script injected in <head>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECS_Color_Scheme_Module extends ECS_Module_Base {

	public function get_id(): string {
		return 'color_scheme';
	}

	public function get_title(): string {
		return __( 'Dark Mode Colours', 'ele-custom-skin' );
	}

	public function get_description(): string {
		return __( 'Set default and dark-mode colours in Site Settings. Includes a Dark Mode Switcher widget so visitors can toggle between light and dark.', 'ele-custom-skin' );
	}

	public function boot(): void {
		// PHP-level: add data-ecs-scheme="alt" to <html> when cookie is set.
		// Runs before any output — works even with page caches that vary by cookie.
		add_filter( 'language_attributes', [ $this, 'inject_scheme_html_attribute' ] );

		// Anti-FOUC fallback: inline script in <head> for caches that do NOT vary by cookie.
		add_action( 'wp_head', [ $this, 'inject_anti_fouc_script' ], 1 );

		// Generate dark mode CSS with correct specificity.
		add_action( 'wp_head', [ $this, 'inject_dark_mode_css' ], 5 );

		// Register widget
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );

		// Register "Dark Mode Colours" tab in Elementor Site Settings → Global section
		add_action( 'elementor/kit/register_tabs', [ $this, 'register_kit_tab' ] );
	}

	/**
	 * Add data-ecs-scheme="alt" to the <html> tag when the cookie is set.
	 * This runs server-side so the attribute is present in the initial HTML —
	 * guaranteed zero FOUC regardless of caching.
	 */
	public function inject_scheme_html_attribute( string $output ): string {
		if ( ! empty( $_COOKIE['ecs_color_scheme'] ) && $_COOKIE['ecs_color_scheme'] === 'alt' ) {
			$output .= ' data-ecs-scheme="alt"';
		}
		return $output;
	}

	public function register_kit_tab( $kit ): void {
		require_once $this->module_path() . 'class-ecs-default-colours-tab.php';

		// Replace the built-in "Global Colors" with "Default Colours"
		// which includes a Dark Mode colour picker on every colour entry.
		$kit->register_tab( 'global-colors', ECS_Default_Colours_Tab::class );
	}

	public function register_widgets( $widgets_manager ): void {
		require_once $this->module_path() . 'widgets/class-ecs-color-switcher-widget.php';
		$widgets_manager->register( new ECS_Color_Switcher_Widget() );
	}

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'ecs-color-scheme',
			$this->module_asset_url( 'assets/css/ecs-color-scheme.css' ),
			[],
			ECS_VERSION
		);

		wp_enqueue_script(
			'ecs-color-switcher',
			$this->module_asset_url( 'assets/js/ecs-color-switcher.js' ),
			[],
			ECS_VERSION,
			true
		);
	}

	public function enqueue_editor_assets(): void {
		// Frontend colour variables (needed in the editor preview iframe too)
		wp_enqueue_style(
			'ecs-color-scheme-editor',
			$this->module_asset_url( 'assets/css/ecs-color-scheme.css' ),
			[],
			ECS_VERSION
		);

		// Default Colours panel: tab UI styles
		wp_enqueue_style(
			'ecs-colour-editor-ui',
			$this->module_asset_url( 'assets/css/ecs-colour-editor.css' ),
			[],
			ECS_VERSION
		);

		// Default Colours panel: tab switching JS
		wp_enqueue_script(
			'ecs-colour-editor',
			$this->module_asset_url( 'assets/js/ecs-colour-editor.js' ),
			[ 'jquery', 'elementor-editor' ],
			ECS_VERSION,
			true
		);
	}

	/**
	 * Generate dark mode CSS by reading dark_color values from the active kit.
	 *
	 * Outputs a <style> block targeting [data-ecs-scheme="alt"] .elementor-kit-{id}
	 * (specificity 0,2,0) which beats .elementor-kit-{id} alone (0,1,0), so the
	 * dark values correctly override the default variables on <body>.
	 */
	public function inject_dark_mode_css(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return;
		}

		$kit_id        = absint( $kit->get_id() );
		$system_colors = $kit->get_settings( 'system_colors' ) ?: [];
		$custom_colors = $kit->get_settings( 'custom_colors' ) ?: [];

		$rules = [];
		foreach ( array_merge( $system_colors, $custom_colors ) as $color ) {
			if ( empty( $color['dark_color'] ) || empty( $color['_id'] ) ) {
				continue;
			}
			$id    = sanitize_key( $color['_id'] );
			$value = sanitize_hex_color( $color['dark_color'] );
			if ( $id && $value ) {
				$rules[] = '--e-global-color-' . $id . ':' . $value . ';';
			}
		}

		if ( empty( $rules ) ) {
			return;
		}

		$selector = '[data-ecs-scheme="alt"] .elementor-kit-' . $kit_id;
		echo "\n<style id=\"ecs-dark-mode-css\">\n" . $selector . '{' . implode( '', $rules ) . "}\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Anti-FOUC fallback: inline script that reads the cookie and applies
	 * data-ecs-scheme="alt" before any CSS is painted.
	 *
	 * This covers page-caches that serve static HTML without running PHP
	 * (so the language_attributes filter above didn't run for that request).
	 * Priority 1 ensures it runs before any stylesheet is enqueued.
	 */
	public function inject_anti_fouc_script(): void {
		$system_auto   = false;
		$dark_logo_url = '';
		if ( did_action( 'elementor/loaded' ) ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit ) {
				$system_auto = $kit->get_settings( 'ecs_system_auto' ) === 'yes';
				$dark_logo   = $kit->get_settings( 'ecs_dark_logo' );
				if ( is_array( $dark_logo ) && ! empty( $dark_logo['url'] ) ) {
					$dark_logo_url = esc_url_raw( $dark_logo['url'] );
				}
			}
		}
		$auto_js = $system_auto ? 'true' : 'false';
		$logo_js = wp_json_encode( $dark_logo_url );
		?>
		<script>
		(function(){
			window.ecsSchemeConfig={systemAuto:<?php echo $auto_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>,darkLogoUrl:<?php echo $logo_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>};
			var m=document.cookie.match(/(?:^|;\s*)ecs_color_scheme=([^;]+)/);
			if(m&&m[1]==='alt'){
				document.documentElement.setAttribute('data-ecs-scheme','alt');
			}else if(!m&&<?php echo $auto_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>){
				if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches){
					document.documentElement.setAttribute('data-ecs-scheme','alt');
				}
			}
		})();
		</script>
		<?php
	}
}
