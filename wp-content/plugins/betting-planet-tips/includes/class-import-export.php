<?php
/** @package BettingPlanetTips */
namespace BettingPlanetTips;

defined( 'ABSPATH' ) || exit;

final class Import_Export {
	const ACTION_EXPORT = 'bpt_export_tips';
	const ACTION_IMPORT = 'bpt_import_tips';
	const NONCE_EXPORT  = 'bpt_export_tips';
	const NONCE_IMPORT  = 'bpt_import_tips';
	const UID_META      = '_bpt_import_uid';

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=betting_tip',
			__( 'Import / Export', 'betting-planet-tips' ),
			__( 'Import / Export', 'betting-planet-tips' ),
			'edit_posts',
			'bpt-import-export',
			array( self::class, 'page' )
		);
	}

	public static function page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage betting tips.', 'betting-planet-tips' ) );
		}
		$notice = isset( $_GET['bpt_notice'] ) ? sanitize_key( wp_unslash( $_GET['bpt_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Betting Tips Import / Export', 'betting-planet-tips' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo 'imported' === $notice || 'exported' === $notice ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( self::notice_text( $notice ) ); ?></p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Export betting tips as a JSON file, then import that file on another WordPress site running this plugin.', 'betting-planet-tips' ); ?></p>
			<div class="card" style="max-width: 760px;">
				<h2><?php esc_html_e( 'Export', 'betting-planet-tips' ); ?></h2>
				<p><?php esc_html_e( 'The export includes native post title/content/status, betting fields, league taxonomy data, and calculated performance values. Featured image URLs are included for reference only.', 'betting-planet-tips' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
					<?php wp_nonce_field( self::NONCE_EXPORT ); ?>
					<?php submit_button( __( 'Download Betting Tips Export', 'betting-planet-tips' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
			<div class="card" style="max-width: 760px;">
				<h2><?php esc_html_e( 'Import', 'betting-planet-tips' ); ?></h2>
				<p><?php esc_html_e( 'Import creates missing leagues, validates betting fields, saves tips, then recalculates status, return, profit, and yield. Existing imported tips are updated by UID.', 'betting-planet-tips' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>" />
					<?php wp_nonce_field( self::NONCE_IMPORT ); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="bpt_import_file"><?php esc_html_e( 'Export file', 'betting-planet-tips' ); ?></label></th>
								<td><input id="bpt_import_file" name="bpt_import_file" type="file" accept="application/json,.json" required /></td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Import Betting Tips', 'betting-planet-tips' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private static function notice_text( $notice ) {
		$messages = array(
			'imported'     => __( 'Betting tips imported successfully.', 'betting-planet-tips' ),
			'empty'        => __( 'The export file did not contain any betting tips.', 'betting-planet-tips' ),
			'invalid_file' => __( 'Choose a valid Betting Tips JSON export file.', 'betting-planet-tips' ),
			'permission'   => __( 'You do not have permission to import or export betting tips.', 'betting-planet-tips' ),
			'failed'       => __( 'Import failed. Check the file and try again.', 'betting-planet-tips' ),
		);
		return $messages[ $notice ] ?? __( 'Action complete.', 'betting-planet-tips' );
	}

	public static function handle_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			self::redirect( 'permission' );
		}
		check_admin_referer( self::NONCE_EXPORT );
		$data = self::export_data();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=betting-tips-export-' . gmdate( 'Y-m-d-His' ) . '.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			self::redirect( 'permission' );
		}
		check_admin_referer( self::NONCE_IMPORT );
		if ( empty( $_FILES['bpt_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bpt_import_file']['tmp_name'] ) ) {
			self::redirect( 'invalid_file' );
		}
		$raw = file_get_contents( $_FILES['bpt_import_file']['tmp_name'] );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			self::redirect( 'invalid_file' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['tips'] ) || ! is_array( $data['tips'] ) ) {
			self::redirect( empty( $data['tips'] ) ? 'empty' : 'invalid_file' );
		}
		$result = self::import_data( $data );
		self::redirect( is_wp_error( $result ) ? 'failed' : 'imported' );
	}

	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'bpt_notice', rawurlencode( $notice ), admin_url( 'edit.php?post_type=betting_tip&page=bpt-import-export' ) ) );
		exit;
	}

	public static function export_data() {
		$query = new \WP_Query( array(
			'post_type' => 'betting_tip', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ), 'no_found_rows' => true,
		) );
		$tips = array();
		foreach ( $query->posts as $post ) {
			$tips[] = self::export_tip( $post );
		}
		wp_reset_postdata();
		return array(
			'plugin'       => 'betting-planet-tips',
			'version'      => BPT_VERSION,
			'generated_at' => gmdate( 'c' ),
			'site_url'     => home_url(),
			'tips'         => $tips,
		);
	}

	private static function export_tip( $post ) {
		$league = array( 'slug' => '', 'name' => '' );
		$terms  = wp_get_object_terms( $post->ID, Leagues::TAXONOMY );
		if ( ! is_wp_error( $terms ) && $terms ) {
			$league = array( 'slug' => $terms[0]->slug, 'name' => $terms[0]->name );
		}
		$meta = array();
		foreach ( array( 'season', 'match_datetime', 'home_team', 'away_team', 'bet_selection', 'stake', 'odds', 'match_result', 'bet_status', 'return', 'profit', 'yield' ) as $field ) {
			$meta[ $field ] = get_post_meta( $post->ID, '_bpt_' . $field, true );
		}
		$uid = get_post_meta( $post->ID, self::UID_META, true );
		if ( '' === $uid ) {
			$uid = md5( home_url() . '|' . $post->ID . '|' . $post->post_date_gmt );
		}
		return array(
			'uid'                => $uid,
			'original_id'        => (int) $post->ID,
			'title'              => get_the_title( $post ),
			'slug'               => $post->post_name,
			'content'            => $post->post_content,
			'excerpt'            => $post->post_excerpt,
			'status'             => $post->post_status,
			'post_date'          => $post->post_date,
			'post_date_gmt'      => $post->post_date_gmt,
			'author_login'       => get_the_author_meta( 'user_login', $post->post_author ),
			'featured_image_url' => get_the_post_thumbnail_url( $post, 'full' ) ?: '',
			'league'             => $league,
			'meta'               => $meta,
		);
	}

	public static function import_data( array $data ) {
		if ( empty( $data['tips'] ) || ! is_array( $data['tips'] ) ) {
			return new \WP_Error( 'bpt_import_empty', __( 'No tips to import.', 'betting-planet-tips' ) );
		}
		$created = 0;
		$updated = 0;
		foreach ( $data['tips'] as $tip ) {
			$post_id = self::import_tip( is_array( $tip ) ? $tip : array() );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			if ( 'created' === get_post_meta( $post_id, '_bpt_last_import_result', true ) ) {
				++$created;
			} else {
				++$updated;
			}
			delete_post_meta( $post_id, '_bpt_last_import_result' );
		}
		return array( 'created' => $created, 'updated' => $updated );
	}

	private static function import_tip( array $tip ) {
		$uid = isset( $tip['uid'] ) && is_scalar( $tip['uid'] ) ? sanitize_text_field( (string) $tip['uid'] ) : '';
		if ( '' === $uid ) {
			return new \WP_Error( 'bpt_import_uid', __( 'A tip is missing its import UID.', 'betting-planet-tips' ) );
		}
		$existing_id = self::find_existing( $uid );
		$postarr = array(
			'post_type'    => 'betting_tip',
			'post_title'   => isset( $tip['title'] ) ? sanitize_text_field( (string) $tip['title'] ) : __( 'Imported Betting Tip', 'betting-planet-tips' ),
			'post_name'    => isset( $tip['slug'] ) ? sanitize_title( (string) $tip['slug'] ) : '',
			'post_content' => isset( $tip['content'] ) ? wp_kses_post( (string) $tip['content'] ) : '',
			'post_excerpt' => isset( $tip['excerpt'] ) ? wp_kses_post( (string) $tip['excerpt'] ) : '',
			'post_status'  => self::sanitize_status( $tip['status'] ?? 'draft' ),
			'post_author'  => get_current_user_id(),
		);
		foreach ( array( 'post_date', 'post_date_gmt' ) as $date_field ) {
			if ( isset( $tip[ $date_field ] ) && is_string( $tip[ $date_field ] ) && preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $tip[ $date_field ] ) ) {
				$postarr[ $date_field ] = $tip[ $date_field ];
			}
		}
		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id = wp_update_post( wp_slash( $postarr ), true );
			$result = 'updated';
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			$result = 'created';
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, self::UID_META, $uid );
		update_post_meta( $post_id, '_bpt_last_import_result', $result );
		$league = isset( $tip['league'] ) && is_array( $tip['league'] ) ? $tip['league'] : array();
		$assigned = self::import_league( $post_id, $league );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}
		$meta = isset( $tip['meta'] ) && is_array( $tip['meta'] ) ? $tip['meta'] : array();
		foreach ( Fields::schema() as $field => $config ) {
			if ( 'league' === $field ) {
				continue;
			}
			$value = Fields::validate( $field, $meta[ $field ] ?? '' );
			if ( is_wp_error( $value ) ) {
				return new \WP_Error( 'bpt_import_meta', $config['label'] . ': ' . $value->get_error_message() );
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, '_bpt_' . $field );
			} else {
				update_post_meta( $post_id, '_bpt_' . $field, wp_slash( $value ) );
			}
		}
		Settlement::settle( $post_id );
		return $post_id;
	}

	private static function find_existing( $uid ) {
		$posts = get_posts( array(
			'post_type' => 'betting_tip', 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'meta_key' => self::UID_META, 'meta_value' => $uid,
		) );
		return $posts ? (int) $posts[0] : 0;
	}

	private static function import_league( $post_id, array $league ) {
		$slug = isset( $league['slug'] ) && is_scalar( $league['slug'] ) ? sanitize_title( (string) $league['slug'] ) : '';
		$name = isset( $league['name'] ) && is_scalar( $league['name'] ) ? sanitize_text_field( (string) $league['name'] ) : '';
		if ( '' === $slug ) {
			return wp_set_object_terms( $post_id, array(), Leagues::TAXONOMY, false );
		}
		$term = get_term_by( 'slug', $slug, Leagues::TAXONOMY );
		if ( ! $term ) {
			$created = wp_insert_term( '' !== $name ? $name : ucwords( str_replace( '-', ' ', $slug ) ), Leagues::TAXONOMY, array( 'slug' => $slug ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		return wp_set_object_terms( $post_id, array( $term_id ), Leagues::TAXONOMY, false );
	}

	private static function sanitize_status( $status ) {
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : 'draft';
		return in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $status : 'draft';
	}
}
