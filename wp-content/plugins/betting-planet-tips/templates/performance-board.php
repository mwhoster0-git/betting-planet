<?php
/** @var array<int,array<string,string>> $rows */
defined( 'ABSPATH' ) || exit;
$status_labels = array( 'won' => __( 'GEWONNEN', 'betting-planet-tips' ), 'lost' => __( 'VERLOREN', 'betting-planet-tips' ), 'void' => __( 'VOID', 'betting-planet-tips' ) );
?>
<div class="perf-board" data-bpt-performance-board>
	<div class="perf-board-scroll">
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Datum', 'betting-planet-tips' ); ?></th>
					<th><?php esc_html_e( 'Match', 'betting-planet-tips' ); ?></th>
					<th><?php esc_html_e( 'Tipp', 'betting-planet-tips' ); ?></th>
					<th class="num"><?php esc_html_e( 'Einsatz', 'betting-planet-tips' ); ?></th>
					<th class="num"><?php esc_html_e( 'Quote', 'betting-planet-tips' ); ?></th>
					<th><?php esc_html_e( 'Resultat', 'betting-planet-tips' ); ?></th>
					<th class="num"><?php esc_html_e( 'Profit', 'betting-planet-tips' ); ?></th>
					<th class="num"><?php esc_html_e( 'Yield', 'betting-planet-tips' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $rows ) : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $tone = 'void' === $row['status'] ? 'neutral' : ( 'won' === $row['status'] ? 'positive' : 'negative' ); ?>
						<tr>
							<td><?php echo esc_html( $row['date'] ); ?></td>
							<td class="match"><?php echo esc_html( $row['match'] ); ?></td>
							<td><?php echo esc_html( '0' === $row['selection'] ? 'X' : $row['selection'] ); ?></td>
							<td class="num"><?php echo esc_html( $row['stake'] ); ?> U</td>
							<td class="num"><?php echo esc_html( $row['odds'] ); ?></td>
							<td class="outcome"><?php echo esc_html( $status_labels[ $row['status'] ] ); ?></td>
							<td class="num <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( \BettingPlanetTips\Performance_Board::signed_amount( $row['profit'], ' U' ) ); ?></td>
							<td class="num <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( \BettingPlanetTips\Performance_Board::compact_percent( $row['yield'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="8" class="empty"><?php esc_html_e( 'No settled betting tips found.', 'betting-planet-tips' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
