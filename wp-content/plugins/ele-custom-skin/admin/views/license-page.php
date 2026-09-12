<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$license_key    = get_option( 'elecs-license-key', '' );
$is_licensed    = ! empty( $license_key );
$is_pro_active  = defined( 'ELECSP_VER' );
$has_error      = isset( $_GET['msg'] ) && $_GET['msg'] === 'invalid';

// Mask the license key for display: show first 4 + last 4 chars.
$masked_key = '';
if ( $is_licensed ) {
	$len        = strlen( $license_key );
	$masked_key = $len > 8
		? substr( $license_key, 0, 4 ) . str_repeat( '•', max( 4, $len - 8 ) ) . substr( $license_key, -4 )
		: str_repeat( '•', $len );
}
?>
<div class="wrap ecs-admin-wrap">

	<div class="ecs-admin-header">
		<h1><?php esc_html_e( 'ECS — Ele Custom Skin for Elementor', 'ele-custom-skin' ); ?></h1>
		<span class="ecs-version">v<?php echo esc_html( ECS_VERSION ); ?></span>
	</div>

	<?php if ( $has_error ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'License key invalid. Please check the key and try again.', 'ele-custom-skin' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="ecs-license-wrap">

		<!-- ── Sidebar nav ────────────────────────────────────── -->
		<div class="ecs-license-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ecs-modules' ) ); ?>" class="ecs-license-nav__item">
				<?php esc_html_e( 'Modules', 'ele-custom-skin' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ecs-license' ) ); ?>" class="ecs-license-nav__item is-active">
				<?php esc_html_e( 'License', 'ele-custom-skin' ); ?>
			</a>
		</div>

		<!-- ── License card ──────────────────────────────────── -->
		<div class="ecs-license-card">

			<?php if ( ! $is_pro_active ) : ?>

				<div class="ecs-license-card__icon">🔒</div>
				<h2 class="ecs-license-card__title"><?php esc_html_e( 'ECS Pro is not active', 'ele-custom-skin' ); ?></h2>
				<p class="ecs-license-card__desc">
					<?php esc_html_e( 'Install and activate ECS Pro to unlock the license management.', 'ele-custom-skin' ); ?>
				</p>
				<a href="https://dudaster.com/ecs-pro/" target="_blank" class="button button-primary">
					<?php esc_html_e( 'Get ECS Pro →', 'ele-custom-skin' ); ?>
				</a>

			<?php elseif ( $is_licensed ) : ?>

				<div class="ecs-license-status ecs-license-status--active">
					<span class="ecs-license-status__dot"></span>
					<?php esc_html_e( 'License Active', 'ele-custom-skin' ); ?>
				</div>

				<h2 class="ecs-license-card__title"><?php esc_html_e( 'ECS Pro Licensed', 'ele-custom-skin' ); ?></h2>

				<div class="ecs-license-key-display">
					<span class="ecs-license-key-display__label"><?php esc_html_e( 'License Key', 'ele-custom-skin' ); ?></span>
					<code class="ecs-license-key-display__value"><?php echo esc_html( $masked_key ); ?></code>
				</div>

				<p class="ecs-license-card__desc">
					<?php esc_html_e( 'Your license is active. You will receive automatic updates and access to all Pro modules.', 'ele-custom-skin' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="elecs-license" value="">
					<?php wp_nonce_field( 'elecs-settings-save', 'elecs-custom-message' ); ?>
					<button type="submit" class="ecs-license-deactivate-btn">
						<?php esc_html_e( 'Deactivate License', 'ele-custom-skin' ); ?>
					</button>
				</form>

			<?php else : ?>

				<div class="ecs-license-status ecs-license-status--inactive">
					<span class="ecs-license-status__dot"></span>
					<?php esc_html_e( 'No License', 'ele-custom-skin' ); ?>
				</div>

				<h2 class="ecs-license-card__title"><?php esc_html_e( 'Activate Your License', 'ele-custom-skin' ); ?></h2>

				<p class="ecs-license-card__desc">
					<?php esc_html_e( 'Enter your ECS Pro license key to enable automatic updates and access to all Pro modules.', 'ele-custom-skin' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecs-license-form">
					<div class="ecs-license-form__row">
						<input
							type="text"
							name="elecs-license"
							id="elecs-license"
							class="ecs-license-form__input"
							placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX-XXXX', 'ele-custom-skin' ); ?>"
							value=""
							autocomplete="off"
						>
						<?php wp_nonce_field( 'elecs-settings-save', 'elecs-custom-message' ); ?>
						<button type="submit" class="button button-primary ecs-license-form__btn">
							<?php esc_html_e( 'Activate License', 'ele-custom-skin' ); ?>
						</button>
					</div>
					<p class="ecs-license-form__hint">
						<?php
						printf(
							wp_kses(
								/* translators: %s: link to purchase page */
								__( 'Don\'t have a license yet? <a href="%s" target="_blank">Get ECS Pro →</a>', 'ele-custom-skin' ),
								[ 'a' => [ 'href' => [], 'target' => [] ] ]
							),
							'https://dudaster.com/ecs-pro/'
						);
						?>
					</p>
				</form>

			<?php endif; ?>

		</div><!-- .ecs-license-card -->

	</div><!-- .ecs-license-wrap -->

</div>
