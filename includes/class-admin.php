<?php
/**
 * Admin surface — Dashboard, Bulk Scan, Settings, License.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Admin {

	public function __construct() {
		add_action( 'admin_menu',                array( $this, 'menu' ) );
		add_action( 'admin_init',                array( $this, 'settings' ) );
		add_action( 'wp_ajax_aecs_bulk_scan',    array( $this, 'ajax_bulk_scan' ) );
	}

	public function menu() {
		add_menu_page( __( 'AEO Score', 'aeo-citability-score' ), __( 'AEO Score', 'aeo-citability-score' ), 'manage_options', 'aeo-citability-score', array( $this, 'render' ), 'dashicons-chart-line', 56 );
	}

	public function settings() {
		register_setting( 'aecs_settings_group', 'aecs_settings', array(
			'type' => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
			/**
			 * Default settings. Add-ons register their own keys here rather than
			 * the free plugin carrying settings for features it does not ship.
			 *
			 * @param array $defaults Default settings.
			 */
			'default' => apply_filters( 'aecs_default_settings', array(
				'post_types'         => array( 'post' ),
				'auto_score_on_save' => 1,
			) ),
		) );
	}

	public function sanitize( $in ) {
		$types = array();
		$avail = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		foreach ( (array) ( $in['post_types'] ?? array( 'post' ) ) as $pt ) {
			if ( in_array( $pt, $avail, true ) ) $types[] = $pt;
		}
		if ( empty( $types ) ) $types = array( 'post' );
		$out = array(
			'post_types'         => $types,
			'auto_score_on_save' => ! empty( $in['auto_score_on_save'] ) ? 1 : 0,
		);

		/**
		 * Sanitised settings. Add-ons sanitise and merge their own keys here.
		 * Without this an add-on's settings would be dropped on every save.
		 *
		 * @param array $out Sanitised settings so far.
		 * @param array $in  Raw submitted settings.
		 */
		return apply_filters( 'aecs_sanitize_settings', $out, $in );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		// Read-only view switch on an admin screen already gated by
		// current_user_can( 'manage_options' ); nothing is written from it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		/**
		 * Admin tabs. The Pro add-on appends its License tab; the free plugin has
		 * no licence system and therefore no tab to show.
		 *
		 * @param array $tabs Tab slugs.
		 */
		$tabs = apply_filters( 'aecs_admin_tabs', array( 'dashboard', 'bulk', 'settings' ) );
		if ( ! in_array( $tab, $tabs, true ) ) $tab = 'dashboard';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AEO Citability Score', 'aeo-citability-score' ); ?>
				<?php do_action( 'aecs_admin_badge' ); ?>
			</h1>
			<nav class="nav-tab-wrapper">
				<?php $this->tab( 'dashboard', __( 'Dashboard', 'aeo-citability-score' ), $tab ); ?>
				<?php $this->tab( 'bulk',      __( 'Bulk scan', 'aeo-citability-score' ), $tab ); ?>
				<?php $this->tab( 'settings',  __( 'Settings',  'aeo-citability-score' ), $tab ); ?>
				<?php foreach ( array_diff( $tabs, array( 'dashboard', 'bulk', 'settings' ) ) as $extra ) : ?>
					<?php $this->tab( $extra, ucfirst( $extra ), $tab ); ?>
				<?php endforeach; ?>
			</nav>
			<div style="margin-top:20px;">
				<?php
				switch ( $tab ) {
					case 'bulk':     $this->render_bulk();     break;
					case 'settings': $this->render_settings(); break;
					case 'dashboard': $this->render_dashboard(); break;
					default:
						/**
						 * Render a tab supplied by an add-on.
						 *
						 * @param string $tab Current tab slug.
						 */
						do_action( 'aecs_admin_render_tab', $tab );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	private function tab( $slug, $label, $current ) {
		$url = add_query_arg( array( 'page' => 'aeo-citability-score', 'tab' => $slug ), admin_url( 'admin.php' ) );
		$cls = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
		printf( '<a class="%s" href="%s">%s</a>', esc_attr( $cls ), esc_url( $url ), esc_html( $label ) );
	}

	private function render_dashboard() {
		$settings = get_option( 'aecs_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post' );
		// Get all posts with score meta.
		$q = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'meta_key'       => AECS_META_SCORE,
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		$ids = $q->posts ?: array();
		$scores = array();
		foreach ( $ids as $pid ) {
			$m = get_post_meta( $pid, AECS_META_SCORE, true );
			if ( is_array( $m ) && isset( $m['score'] ) ) $scores[ $pid ] = (int) $m['score'];
		}
		$total = count( $scores );
		$avg = $total ? (int) round( array_sum( $scores ) / $total ) : 0;
		$excellent = count( array_filter( $scores, fn( $s ) => $s >= 85 ) );
		$good      = count( array_filter( $scores, fn( $s ) => $s >= 70 && $s < 85 ) );
		$fair      = count( array_filter( $scores, fn( $s ) => $s >= 50 && $s < 70 ) );
		$poor      = count( array_filter( $scores, fn( $s ) => $s < 50 ) );
		asort( $scores );
		$attention = array_slice( $scores, 0, 10, true );
		?>
		<style>
			.aecs-stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin:16px 0 24px; max-width:900px; }
			.aecs-stat { background:#fff; border:1px solid #dcdcde; border-radius:3px; padding:18px 22px; }
			.aecs-stat-num { font-size:38px; font-weight:800; line-height:1; font-family:ui-monospace,monospace; }
			.aecs-stat-lbl { font-size:12px; text-transform:uppercase; letter-spacing:0.06em; color:#50575e; margin-top:6px; }
			.aecs-good  { color:#0f6c50; }
			.aecs-fair  { color:#8b6a0e; }
			.aecs-poor  { color:#a12c2e; }
			.aecs-bar { display:flex; height:14px; border-radius:7px; overflow:hidden; background:#f0f0f1; max-width:900px; margin:0 0 24px; }
			.aecs-bar-segment { color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; }
			.aecs-bar-excellent { background:#1D9E75; }
			.aecs-bar-good      { background:#5DE0B0; color:#06090f; }
			.aecs-bar-fair      { background:#dba617; }
			.aecs-bar-poor      { background:#d63638; }
		</style>
		<div class="aecs-stat-grid">
			<div class="aecs-stat"><div class="aecs-stat-num"><?php echo esc_html( $total ); ?></div><div class="aecs-stat-lbl">Posts scored</div></div>
			<div class="aecs-stat"><div class="aecs-stat-num aecs-good"><?php echo esc_html( $avg ); ?></div><div class="aecs-stat-lbl">Site avg score</div></div>
			<div class="aecs-stat"><div class="aecs-stat-num aecs-poor"><?php echo esc_html( $poor ); ?></div><div class="aecs-stat-lbl">Poor (&lt;50)</div></div>
			<div class="aecs-stat"><div class="aecs-stat-num aecs-fair"><?php echo esc_html( $fair ); ?></div><div class="aecs-stat-lbl">Fair (50-69)</div></div>
		</div>
		<?php if ( $total > 0 ) : ?>
			<div class="aecs-bar">
				<?php
				foreach ( array( 'excellent' => $excellent, 'good' => $good, 'fair' => $fair, 'poor' => $poor ) as $band => $count ) {
					if ( $count <= 0 ) continue;
					$pct = ( $count / $total ) * 100;
					printf( '<div class="aecs-bar-segment aecs-bar-%s" style="flex:%s;">%d</div>', esc_attr( $band ), esc_attr( $pct ), (int) $count );
				}
				?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Top 10 needs-attention posts', 'aeo-citability-score' ); ?></h2>
		<?php if ( empty( $attention ) ) : ?>
			<p><em><?php esc_html_e( 'No posts scored yet. Save a published post to generate its score, or run a bulk scan.', 'aeo-citability-score' ); ?></em></p>
			<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'bulk', menu_page_url( 'aeo-citability-score', false ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run bulk scan', 'aeo-citability-score' ); ?></a></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px;">
				<thead><tr><th>Post</th><th style="width:100px;">Score</th><th style="width:120px;"></th></tr></thead>
				<tbody>
					<?php foreach ( $attention as $pid => $sc ) : ?>
						<tr>
							<td><strong><?php echo esc_html( get_the_title( $pid ) ); ?></strong></td>
							<td><span class="<?php echo $sc >= 70 ? 'aecs-good' : ( $sc >= 50 ? 'aecs-fair' : 'aecs-poor' ); ?>"><?php echo (int) $sc; ?>/100</span></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" class="button button-secondary">Edit</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private function render_bulk() {
		$settings = get_option( 'aecs_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post' );
		$total = 0;
		foreach ( $post_types as $pt ) {
			$c = wp_count_posts( $pt );
			$total += (int) ( $c->publish ?? 0 );
		}
		?>
		<div class="card" style="max-width:800px;padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-radius:3px;">
			<h2 style="margin-top:0;"><?php echo esc_html( number_format_i18n( $total ) ); ?> published posts eligible</h2>
			<p><?php esc_html_e( 'Bulk scan runs the structural scorer on every post. Fast — no LLM calls. Scores land in each post\'s meta.', 'aeo-citability-score' ); ?></p>
			<p>
				<label>Batch size: <input type="number" id="aecs-batch" value="25" min="1" max="100" style="width:70px" /></label>
				<button type="button" id="aecs-bulk-run" class="button button-primary"><?php esc_html_e( 'Run batch', 'aeo-citability-score' ); ?></button>
				<span id="aecs-bulk-msg" style="margin-left:8px;font-size:13px;color:#50575e;"></span>
			</p>
			<div id="aecs-bulk-log" style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px 12px;font-family:ui-monospace,monospace;font-size:12px;border-radius:3px;margin-top:12px;"></div>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'aecs_admin' ) ); ?>;
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var offset = 0;
			document.getElementById('aecs-bulk-run')?.addEventListener('click', function(){
				var btn = this; btn.disabled = true;
				var msg = document.getElementById('aecs-bulk-msg');
				var log = document.getElementById('aecs-bulk-log');
				msg.textContent = 'Scanning…'; msg.style.color = '#50575e';
				var batch = parseInt(document.getElementById('aecs-batch').value, 10) || 25;
				var body = new URLSearchParams({ action:'aecs_bulk_scan', nonce:nonce, batch:batch, offset:offset });
				fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body })
					.then(function(r){ return r.json(); })
					.then(function(r){
						btn.disabled = false;
						if (r && r.success) {
							msg.textContent = '✓ Scored ' + r.data.processed + ' posts. Avg: ' + r.data.avg_score + '/100.'; msg.style.color = '#1D9E75';
							(r.data.items || []).forEach(function(it){
								var line = document.createElement('div');
								line.textContent = '#' + it.id + '  score=' + it.score + '  ' + it.title;
								log.appendChild(line);
							});
							offset += (r.data.processed || 0);
						} else {
							msg.textContent = (r && r.data && r.data.message) || 'Failed'; msg.style.color = '#d63638';
						}
					})
					.catch(function(){ btn.disabled = false; msg.textContent = 'Network error'; msg.style.color = '#d63638'; });
			});
		})();
		</script>
		<?php
	}

	private function render_settings() {
		$s = wp_parse_args( get_option( 'aecs_settings', array() ), array(
			'post_types'         => array( 'post' ),
			'auto_score_on_save' => 1,
		) );
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<form method="post" action="options.php" style="max-width:800px;">
			<?php settings_fields( 'aecs_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Post types', 'aeo-citability-score' ); ?></th><td>
					<?php foreach ( $public_types as $pt ) : ?>
						<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="aecs_settings[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->singular_name ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Auto-score on save', 'aeo-citability-score' ); ?></th><td>
					<label><input type="checkbox" name="aecs_settings[auto_score_on_save]" value="1" <?php checked( $s['auto_score_on_save'] ); ?>> <?php esc_html_e( 'Structural score recalculated when a post is saved (free tier — no LLM cost)', 'aeo-citability-score' ); ?></label>
				</td></tr>
				<?php
				/**
				 * LLM configuration rows. Supplied by the Pro add-on; the free
				 * plugin ships no AI functionality and so renders nothing here.
				 *
				 * @param array $s Current settings.
				 */
				do_action( 'aecs_settings_llm', $s );
				?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	public function ajax_bulk_scan() {
		check_ajax_referer( 'aecs_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		$batch  = isset( $_POST['batch'] )  ? max( 1, min( 100, (int) $_POST['batch'] ) )  : 25;
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$settings = get_option( 'aecs_settings', array() );
		$post_types = $settings['post_types'] ?? array( 'post' );

		$q = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		$ids = $q->posts ?: array();
		$scorer = new AECS_Scorer();
		$items = array();
		$sum = 0;
		foreach ( $ids as $pid ) {
			$res = $scorer->score_post( $pid );
			update_post_meta( $pid, AECS_META_SCORE, $res );
			$items[] = array( 'id' => $pid, 'title' => get_the_title( $pid ), 'score' => (int) $res['score'] );
			$sum += (int) $res['score'];
		}
		$avg = count( $ids ) > 0 ? (int) round( $sum / count( $ids ) ) : 0;
		wp_send_json_success( array( 'processed' => count( $ids ), 'avg_score' => $avg, 'items' => $items ) );
	}

}
