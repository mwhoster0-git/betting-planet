<?php
/** @package BettingPlanetTips */
defined( 'ABSPATH' ) || exit;
?>
<section class="bp-hand-tips" data-bpt-open-bets aria-label="<?php esc_attr_e( 'Open betting tips', 'betting-planet-tips' ); ?>">
	<?php if ( ! $tips ) : ?>
		<p class="bp-empty"><?php esc_html_e( 'No open bets at the moment. Check back for the next tip.', 'betting-planet-tips' ); ?></p>
	<?php else : ?>
	<div class="bp-hand-deck" id="<?php echo esc_attr( $id ); ?>" tabindex="0" role="region" aria-roledescription="<?php esc_attr_e( 'carousel', 'betting-planet-tips' ); ?>" aria-label="<?php esc_attr_e( 'Open tips. Swipe or use the left and right arrow keys to browse.', 'betting-planet-tips' ); ?>">
		<?php foreach ( $tips as $index => $tip ) : ?>
		<article class="bp-hand-card" role="group" aria-roledescription="<?php esc_attr_e( 'slide', 'betting-planet-tips' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%1$d of %2$d', 'betting-planet-tips' ), $index + 1, count( $tips ) ) ); ?>">
			<div class="bp-open-badge"><?php esc_html_e( 'Open', 'betting-planet-tips' ); ?></div>
			<div class="bp-card-eyebrow"><?php esc_html_e( 'Current Betting Planet Tip', 'betting-planet-tips' ); ?></div>
			<h3><?php echo esc_html( $tip['title'] ); ?></h3>
			<div class="bp-match-meta"><span><?php echo esc_html( implode( ' · ', array_filter( array( $tip['league'], $tip['season'] ) ) ) ); ?></span><time datetime="<?php echo esc_attr( $tip['date_iso'] ); ?>"><?php echo esc_html( $tip['date_label'] ); ?></time></div>
			<div class="bp-teams">
				<div class="bp-team"><div class="bp-team-logo" aria-hidden="true"><?php echo esc_html( \BettingPlanetTips\Open_Bets::initials( $tip['home_team'] ) ); ?></div><div><strong><?php echo esc_html( $tip['home_team'] ); ?></strong><small><?php esc_html_e( 'Home', 'betting-planet-tips' ); ?></small></div></div>
				<div class="bp-vs" aria-hidden="true">VS</div>
				<div class="bp-team bp-team-away"><div><strong><?php echo esc_html( $tip['away_team'] ); ?></strong><small><?php esc_html_e( 'Away', 'betting-planet-tips' ); ?></small></div><div class="bp-team-logo" aria-hidden="true"><?php echo esc_html( \BettingPlanetTips\Open_Bets::initials( $tip['away_team'] ) ); ?></div></div>
			</div>
			<div class="bp-bet-info">
				<div><span><?php esc_html_e( 'Our Tip', 'betting-planet-tips' ); ?></span><strong><?php echo esc_html( $tip['bet_selection'] . ' · ' . $tip['selection_label'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'Stake', 'betting-planet-tips' ); ?></span><strong><?php echo esc_html( sprintf( __( '%s Units', 'betting-planet-tips' ), $tip['stake'] ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Odds', 'betting-planet-tips' ); ?></span><strong><?php echo esc_html( $tip['odds'] ); ?></strong></div>
			</div>
			<?php if ( '' !== $tip['analysis'] ) : ?><div class="bp-analysis"><span><?php esc_html_e( 'Analysis', 'betting-planet-tips' ); ?></span><p><?php echo esc_html( $tip['analysis'] ); ?></p></div><?php endif; ?>
			<div class="bp-card-note"><?php esc_html_e( 'Awaiting match result · Updated manually', 'betting-planet-tips' ); ?></div>
		</article>
		<?php endforeach; ?>
	</div>
	<div class="bp-hand-controls" hidden>
		<button class="bp-hand-arrow" data-bpt-prev type="button" aria-controls="<?php echo esc_attr( $id ); ?>" aria-label="<?php esc_attr_e( 'Previous tip', 'betting-planet-tips' ); ?>">←</button>
		<div class="bp-hand-counter" aria-live="polite" aria-atomic="true"><span data-bpt-current>1</span> / <?php echo esc_html( count( $tips ) ); ?></div>
		<button class="bp-hand-arrow" data-bpt-next type="button" aria-controls="<?php echo esc_attr( $id ); ?>" aria-label="<?php esc_attr_e( 'Next tip', 'betting-planet-tips' ); ?>">→</button>
	</div>
	<?php endif; ?>
</section>
