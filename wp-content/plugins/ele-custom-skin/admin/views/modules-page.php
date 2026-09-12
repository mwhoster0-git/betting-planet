<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// ── Integrations data ──────────────────────────────────────────────────────────
$integrations = [
	[
		'id'          => 'aicom',
		'title'       => 'AICOM — AI Commander',
		'description' => 'Use your AI subscription to manage WordPress: create Elementor pages, update content, automate tasks, and stay fully in control.',
		'plugin_file' => 'aicom/aicom.php',
		'link'        => admin_url( 'plugin-install.php?s=aicom&tab=search&type=term' ),
		'link_target' => '_self',
		'bg'          => '#efffdb',
	],
	[
		'id'          => 'widget_finder',
		'title'       => 'Widget Finder for Elementor',
		'description' => 'Search and install Elementor widgets without leaving the editor. Discover thousands of widgets from WordPress.org in a single click.',
		'plugin_file' => 'widget-finder-for-elementor/widget-finder-for-elementor.php',
		'link'        => 'https://github.com/dudaster/widget-finder-for-elementor/releases/latest',
		'link_target' => '_blank',
		'bg'          => '#fff3e0',
	],
	[
		'id'          => 'ele_conditions',
		'title'       => 'Ele Conditions',
		'description' => 'Trigger actions on any Elementor element: show, hide, animate, or redirect on click, scroll, hover, or exit — no code needed.',
		'plugin_file' => 'ele-conditions/ele-conditions.php',
		'link'        => admin_url( 'plugin-install.php?s=ele+conditions&tab=search&type=term' ),
		'link_target' => '_self',
		'bg'          => '#e0f8f8',
	],
];

$manager       = ECS_Core::instance()->modules();
$pro_active    = defined( 'ELECSP_VER' );
$pro_installed = $pro_active || file_exists( WP_PLUGIN_DIR . '/ele-custom-skin-pro/ele-custom-skin-pro.php' );
$pro_activate_url = admin_url( 'plugins.php?action=activate&plugin=' . urlencode( 'ele-custom-skin-pro/ele-custom-skin-pro.php' ) . '&_wpnonce=' . wp_create_nonce( 'activate-plugin_ele-custom-skin-pro/ele-custom-skin-pro.php' ) );

// Static list of Pro modules — shown as locked placeholders when Pro is not installed.
$pro_stubs = [
	[
		'id'          => 'dynamic_repeater',
		'title'       => __( 'Dynamic Repeater Builder', 'ele-custom-skin' ),
		'description' => __( 'Populate any Elementor repeater from Posts, Terms, ACF, or JSON. Supports one-time generation and runtime data binding.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'container_responsive',
		'title'       => __( 'Responsive Container Layout', 'ele-custom-skin' ),
		'description' => __( 'Set a different container type (Flexbox, Grid, Slider, Custom Layout) for each breakpoint — desktop, tablet, and mobile independently.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'color_schemes',
		'title'       => __( 'Color Schemes', 'ele-custom-skin' ),
		'description' => __( 'Save named colour palettes and switch them globally or per page — same concept as Global Colors but switchable on the fly.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'font_schemes',
		'title'       => __( 'Font Schemes', 'ele-custom-skin' ),
		'description' => __( 'Save named typography sets and switch them globally or per page — the same concept as Color Schemes but for fonts.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'alt_logos',
		'title'       => __( 'Alternative Site Logos', 'ele-custom-skin' ),
		'description' => __( 'Define multiple logos and serve a different one based on page conditions or time of day — useful for seasonal branding or per-section identity.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'custom_look_feel',
		'title'       => __( 'Custom Look & Feel', 'ele-custom-skin' ),
		'description' => __( 'Build conditional "looks" that activate a colour scheme, font scheme, CSS snippets, and a logo based on page conditions and time intervals.', 'ele-custom-skin' ),
	],
	[
		'id'          => 'legacy_pro',
		'title'       => __( 'Legacy Pro', 'ele-custom-skin' ),
		'description' => __( 'Original ECS Pro functionality kept for backward compatibility.', 'ele-custom-skin' ),
	],
];

// Split registered modules into free / pro.
$free_modules = [];
$pro_modules  = [];   // only populated when Pro is installed

foreach ( $manager->get_all() as $module ) {
	if ( $module->is_pro() ) {
		$pro_modules[] = $module;
	} else {
		$free_modules[] = $module;
	}
}

// Legacy/deprecated modules go last in both lists.
$push_deprecated_last = fn( $a, $b ) => $a->is_deprecated() <=> $b->is_deprecated();
usort( $free_modules, $push_deprecated_last );
usort( $pro_modules,  $push_deprecated_last );

// For card rendering: "locked" means pro not active (installed but inactive, or not installed at all).
$pro_locked_global = ! $pro_active;

/**
 * Render a single module card from a real module object.
 */
$render_card = function ( $module ) use ( $manager, $pro_active ) {
	$is_active     = $manager->is_active( $module->get_id() );
	$is_pro        = $module->is_pro();
	$is_deprecated = $module->is_deprecated();
	$pro_locked    = $is_pro && ! $pro_active;

	$preview_file = ECS_PATH . 'assets/module-previews/' . $module->get_id() . '.gif';
	$preview_url  = file_exists( $preview_file )
		? ECS_URL . 'assets/module-previews/' . $module->get_id() . '.gif'
		: '';

	$card_classes = implode( ' ', array_filter( [
		'ecs-module-card',
		$is_active     ? 'is-active'     : '',
		$is_pro        ? 'is-pro'        : '',
		$pro_locked    ? 'is-locked'     : '',
		$is_deprecated ? 'is-deprecated' : '',
	] ) );
	?>
	<div class="<?php echo esc_attr( $card_classes ); ?>">

		<div class="ecs-module-card__header">
			<span class="ecs-module-card__title"><?php echo esc_html( $module->get_title() ); ?></span>
			<div class="ecs-module-card__badges">
				<?php if ( $is_deprecated ) : ?>
					<span class="ecs-badge ecs-badge--deprecated"><?php esc_html_e( 'Legacy', 'ele-custom-skin' ); ?></span>
				<?php elseif ( $is_pro ) : ?>
					<span class="ecs-badge ecs-badge--pro"><?php esc_html_e( 'PRO', 'ele-custom-skin' ); ?></span>
				<?php else : ?>
					<span class="ecs-badge ecs-badge--free"><?php esc_html_e( 'FREE', 'ele-custom-skin' ); ?></span>
				<?php endif; ?>

				<?php if ( $preview_url ) : ?>
				<div class="ecs-preview-wrap">
					<button type="button"
						class="ecs-preview-btn <?php echo $pro_locked ? 'ecs-preview-btn--locked' : ''; ?>"
						title="<?php esc_attr_e( 'See preview', 'ele-custom-skin' ); ?>">
						<span class="dashicons dashicons-video-alt3"></span>
					</button>
					<div class="ecs-preview-popup">
						<img src="<?php echo esc_url( $preview_url ); ?>"
							alt="<?php echo esc_attr( $module->get_title() ); ?> preview"
							loading="lazy">
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $module->get_description() ) : ?>
			<p class="ecs-module-card__desc"><?php echo esc_html( $module->get_description() ); ?></p>
		<?php endif; ?>

		<?php $deprecated_notice = $is_deprecated ? $module->get_deprecated_notice() : ''; ?>
		<?php if ( $deprecated_notice ) : ?>
			<p class="ecs-module-deprecated-notice">
				<?php echo esc_html( $deprecated_notice ); ?>
			</p>
		<?php endif; ?>

		<div class="ecs-module-card__footer">
			<?php if ( $pro_locked ) : ?>
				<span class="ecs-module-locked"><?php esc_html_e( 'Requires ECS Pro', 'ele-custom-skin' ); ?></span>
			<?php else : ?>
				<label class="ecs-toggle">
					<input type="checkbox"
						name="ecs_modules[]"
						value="<?php echo esc_attr( $module->get_id() ); ?>"
						<?php checked( $is_active ); ?>
					>
					<span class="ecs-toggle__slider"></span>
					<span class="ecs-toggle__label ecs-toggle__label--active"><?php esc_html_e( 'Active', 'ele-custom-skin' ); ?></span>
					<span class="ecs-toggle__label ecs-toggle__label--inactive"><?php esc_html_e( 'Inactive', 'ele-custom-skin' ); ?></span>
				</label>
			<?php endif; ?>
		</div>

	</div>
	<?php
};

/**
 * Render a locked Pro stub card (Pro not installed).
 */
$render_stub = function ( array $stub ) {
	$preview_file = ECS_PATH . 'assets/module-previews/' . $stub['id'] . '.gif';
	$preview_url  = file_exists( $preview_file )
		? ECS_URL . 'assets/module-previews/' . $stub['id'] . '.gif'
		: '';

	$module_id    = $stub['id'];
	$module_title = $stub['title'];
	$module_desc  = $stub['description'];
	$is_active    = false;
	$is_deprecated = false;
	$pro_locked   = true;
	?>
	<div class="ecs-module-card is-pro is-locked">

		<div class="ecs-module-card__header">
			<span class="ecs-module-card__title"><?php echo esc_html( $module_title ); ?></span>
			<div class="ecs-module-card__badges">
				<span class="ecs-badge ecs-badge--pro"><?php esc_html_e( 'PRO', 'ele-custom-skin' ); ?></span>
				<?php if ( $preview_url ) : ?>
				<div class="ecs-preview-wrap">
					<button type="button" class="ecs-preview-btn ecs-preview-btn--locked"
						title="<?php esc_attr_e( 'See preview', 'ele-custom-skin' ); ?>">
						<span class="dashicons dashicons-video-alt3"></span>
					</button>
					<div class="ecs-preview-popup">
						<img src="<?php echo esc_url( $preview_url ); ?>"
							alt="<?php echo esc_attr( $module_title ); ?> preview"
							loading="lazy">
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $module_desc ) : ?>
			<p class="ecs-module-card__desc"><?php echo esc_html( $module_desc ); ?></p>
		<?php endif; ?>

		<div class="ecs-module-card__footer">
			<span class="ecs-module-locked"><?php esc_html_e( 'Requires ECS Pro', 'ele-custom-skin' ); ?></span>
		</div>

	</div>
	<?php
};
/**
 * Render a companion plugin integration card.
 */
$render_integration = function( array $int ) {
	$installed = file_exists( WP_PLUGIN_DIR . '/' . $int['plugin_file'] );
	$active    = $installed && is_plugin_active( $int['plugin_file'] );
	$inactive  = $installed && ! $active;

	$card_classes = 'ecs-module-card ecs-integration-card' . ( $active ? ' is-active' : '' );
	?>
	<div class="<?php echo esc_attr( $card_classes ); ?>" style="--ecs-int-bg:<?php echo esc_attr( $int['bg'] ); ?>">

		<div class="ecs-module-card__header">
			<span class="ecs-module-card__title"><?php echo esc_html( $int['title'] ); ?></span>
			<div class="ecs-module-card__badges">
				<span class="ecs-badge ecs-badge--free"><?php esc_html_e( 'FREE', 'ele-custom-skin' ); ?></span>
			</div>
		</div>

		<p class="ecs-module-card__desc"><?php echo esc_html( $int['description'] ); ?></p>

		<div class="ecs-module-card__footer">
			<?php if ( $active ) : ?>
				<span class="ecs-toggle ecs-int-toggle ecs-int-toggle--active">
					<span class="ecs-toggle__slider"></span>
					<span class="ecs-toggle__label ecs-toggle__label--active"><?php esc_html_e( 'Active', 'ele-custom-skin' ); ?></span>
				</span>
			<?php elseif ( $inactive ) : ?>
				<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"
				   class="ecs-toggle ecs-int-toggle ecs-int-toggle--not-installed">
					<span class="ecs-toggle__slider"></span>
					<span class="ecs-int-status"><?php esc_html_e( 'Inactive', 'ele-custom-skin' ); ?></span>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( $int['link'] ); ?>"
				   class="ecs-toggle ecs-int-toggle ecs-int-toggle--not-installed"
				   <?php if ( '_blank' === $int['link_target'] ) : ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>>
					<span class="ecs-toggle__slider"></span>
					<span class="ecs-int-status"><?php esc_html_e( 'Not installed', 'ele-custom-skin' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

	</div>
	<?php
};
?>
<div class="wrap ecs-admin-wrap">

	<div class="ecs-admin-header">
		<h1><?php esc_html_e( 'ECS — Ele Custom Skin for Elementor', 'ele-custom-skin' ); ?></h1>
		<span class="ecs-version">v<?php echo esc_html( ECS_VERSION ); ?></span>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Module settings saved.', 'ele-custom-skin' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- ── INTEGRATIONS ──────────────────────────────────────── -->
	<div class="ecs-section-header ecs-section-header--integrations">
		<h2 class="ecs-section-title"><?php esc_html_e( 'Integrations', 'ele-custom-skin' ); ?></h2>
	</div>
	<div class="ecs-modules-grid ecs-integrations-grid">
		<?php foreach ( $integrations as $int ) : ?>
			<?php $render_integration( $int ); ?>
		<?php endforeach; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ecs_save_modules' ); ?>
		<input type="hidden" name="action" value="ecs_save_modules">

		<!-- ── FREE modules ───────────────────────────────────── -->
		<div class="ecs-section-header">
			<h2 class="ecs-section-title"><?php esc_html_e( 'Free Modules', 'ele-custom-skin' ); ?></h2>
		</div>
		<div class="ecs-modules-grid">
			<?php foreach ( $free_modules as $module ) : ?>
				<?php $render_card( $module ); ?>
			<?php endforeach; ?>
		</div>

		<!-- ── PRO modules ────────────────────────────────────── -->
		<div class="ecs-section-header ecs-section-header--pro">
			<h2 class="ecs-section-title">
				<?php esc_html_e( 'Pro Modules', 'ele-custom-skin' ); ?>
				<?php if ( $pro_active ) : ?>
					<span class="ecs-pro-active-badge"><?php esc_html_e( 'Active', 'ele-custom-skin' ); ?></span>
					<?php
					$license_key = get_option( 'elecs-license-key', '' );
					if ( ! empty( $license_key ) ) :
					?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ecs-license' ) ); ?>" class="ecs-license-badge">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Licensed', 'ele-custom-skin' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ecs-license' ) ); ?>" class="ecs-license-badge ecs-license-badge--activate">
							<span class="dashicons dashicons-admin-network"></span>
							<?php esc_html_e( 'Activate License', 'ele-custom-skin' ); ?>
						</a>
					<?php endif; ?>
				<?php elseif ( $pro_installed ) : ?>
					<a href="<?php echo esc_url( $pro_activate_url ); ?>" class="ecs-pro-cta ecs-pro-cta--activate">
						<?php esc_html_e( 'Activate ECS Pro →', 'ele-custom-skin' ); ?>
					</a>
				<?php else : ?>
					<a href="https://dudaster.com/ecs-pro/" target="_blank" class="ecs-pro-cta">
						<?php esc_html_e( 'Get ECS Pro →', 'ele-custom-skin' ); ?>
					</a>
				<?php endif; ?>
			</h2>
		</div>
		<div class="ecs-modules-grid">
			<?php if ( $pro_active ) : ?>
				<?php foreach ( $pro_modules as $module ) : ?>
					<?php $render_card( $module ); ?>
				<?php endforeach; ?>
			<?php else : ?>
				<?php foreach ( $pro_stubs as $stub ) : ?>
					<?php $render_stub( $stub ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<?php submit_button( __( 'Save Settings', 'ele-custom-skin' ), 'primary ecs-save-btn' ); ?>

	</form>
</div>
