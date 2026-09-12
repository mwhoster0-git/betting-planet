<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Meta_Boxes {
	private static $saving = false;

	public static function register( $post ) {
		foreach ( array( 'match' => __( 'Match Details', 'betting-planet-tips' ), 'bet' => __( 'Bet Details', 'betting-planet-tips' ), 'result' => __( 'Match Result', 'betting-planet-tips' ) ) as $group => $title ) {
			add_meta_box( 'bpt_' . $group, $title, array( self::class, 'render' ), 'betting_tip', 'normal', 'default', array( 'group' => $group ) );
		}
		add_meta_box( 'bpt_performance', __( 'Bet Performance', 'betting-planet-tips' ), array( Admin::class, 'performance' ), 'betting_tip', 'side' );
	}

	public static function render( $post, $box ) {
		$group = $box['args']['group'];
		if ( 'match' === $group ) {
			wp_nonce_field( 'bpt_save_' . $post->ID, 'bpt_nonce' );
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Fields::schema() as $field => $config ) {
			if ( $group !== $config['group'] ) {
				continue;
			}
			$value = 'league' === $field ? Leagues::selected( $post->ID ) : get_post_meta( $post->ID, '_bpt_' . $field, true );
			if ( 'match_result' === $field && '' === $value ) {
				$value = 'pending';
			}
			if ( 'match_datetime' === $field ) {
				$value = str_replace( ' ', 'T', $value );
			}
			$id   = 'bpt_' . $field;
			$name = 'bpt[' . $field . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $config['label'] ) . '</label></th><td>';
			if ( 'select' === $config['type'] ) {
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				if ( 'match_result' !== $field ) {
					echo '<option value="">' . esc_html__( '— Select —', 'betting-planet-tips' ) . '</option>';
				}
				foreach ( $config['choices'] as $key => $label ) {
					if ( 'stake' === $field ) {
						/* translators: %d: number of betting units. */
						$label = sprintf( __( '%d Units', 'betting-planet-tips' ), $key );
					}
					echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input class="regular-text" type="' . esc_attr( $config['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';
				if ( 'odds' === $field ) {
					echo ' min="1.01" max="100000.00" step="0.01" aria-describedby="bpt_odds_help"';
				} elseif ( 'match_datetime' === $field ) {
					echo ' step="1" aria-describedby="bpt_date_help"';
				}
				echo ' />';
			}
			if ( 'league' === $field && current_user_can( 'manage_categories' ) ) {
				echo '<p class="description"><a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=bpt_league&post_type=betting_tip' ) ) . '">' . esc_html__( 'Manage leagues', 'betting-planet-tips' ) . '</a></p>';
			}
			if ( 'match_datetime' === $field ) {
				/* translators: %s: configured WordPress timezone. */
				echo '<p id="bpt_date_help" class="description">' . esc_html( sprintf( __( 'WordPress timezone: %s.', 'betting-planet-tips' ), wp_timezone_string() ) ) . '</p>';
			} elseif ( 'odds' === $field ) {
				echo '<p id="bpt_odds_help" class="description">' . esc_html__( 'Decimal odds greater than 1.00; up to two decimal places. Example: 2.50.', 'betting-planet-tips' ) . '</p>';
			} elseif ( 'match_result' === $field ) {
				echo '<p class="description">' . esc_html__( 'Update this after the match, then save the tip to calculate performance.', 'betting-planet-tips' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function save( $post_id, $post ) {
		if ( self::$saving || 'betting_tip' !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::$saving = true;
		try {
			// Quick edit and other authorized saves can resettle existing inputs.
			if ( isset( $_POST['bpt'] ) || isset( $_POST['bpt_nonce'] ) ) {
				if ( ! isset( $_POST['bpt_nonce'] ) || ! is_string( $_POST['bpt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bpt_nonce'] ) ), 'bpt_save_' . $post_id ) ) {
					Admin::remember_errors( $post_id, array( __( 'Betting fields were not saved because the security check failed. Reload the editor and try again.', 'betting-planet-tips' ) ) );
					return;
				}
				$input  = isset( $_POST['bpt'] ) && is_array( $_POST['bpt'] ) ? wp_unslash( $_POST['bpt'] ) : array();
				$values = array();
				$errors = array();
				foreach ( Fields::schema() as $field => $config ) {
					if ( ! array_key_exists( $field, $input ) ) {
						$errors[] = __( 'Incomplete betting form. Reload the editor and try again.', 'betting-planet-tips' );
						break;
					}
					$values[ $field ] = Fields::validate( $field, $input[ $field ] );
					if ( is_wp_error( $values[ $field ] ) ) {
						$errors[] = $config['label'] . ': ' . $values[ $field ]->get_error_message();
					}
				}
				if ( $errors ) {
					Admin::remember_errors( $post_id, $errors );
				} else {
					$assigned = Leagues::assign( $post_id, $values['league'] );
					if ( is_wp_error( $assigned ) ) {
						Admin::remember_errors( $post_id, array( $assigned->get_error_message() ) );
						return;
					}
					unset( $values['league'] );
					delete_transient( Admin::notice_key( $post_id ) );
					foreach ( $values as $field => $value ) {
						if ( '' === $value ) {
							delete_post_meta( $post_id, '_bpt_' . $field );
						} else {
							update_post_meta( $post_id, '_bpt_' . $field, wp_slash( $value ) );
						}
					}
				}
			}
			Settlement::settle( $post_id );
		} finally {
			self::$saving = false;
		}
	}
}
