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
		add_action( 'wp_ajax_aecs_license_save', array( $this, 'ajax_license_save' ) );
		add_action( 'wp_ajax_aecs_license_remove',array( $this, 'ajax_license_remove' ) );
	}

	public function menu() {
		add_menu_page( __( 'AEO Score', 'aeo-citability-score' ), __( 'AEO Score', 'aeo-citability-score' ), 'manage_options', 'aeo-citability-score', array( $this, 'render' ), 'dashicons-chart-line', 56 );
	}

	public function settings() {
		register_setting( 'aecs_settings_group', 'aecs_settings', array(
			'type' => 'array',
			'sanitize_callback' => array( $this, 'sanitize' ),
			'default' => array(
				'post_types'         => array( 'post' ),
				'llm_provider'       => 'none',
				'llm_api_key'        => '',
				'llm_model'          => '',
				'auto_score_on_save' => 1,
			),
		) );
	}

	public function sanitize( $in ) {
		$types = array();
		$avail = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		foreach ( (array) ( $in['post_types'] ?? array( 'post' ) ) as $pt ) {
			if ( in_array( $pt, $avail, true ) ) $types[] = $pt;
		}
		if ( empty( $types ) ) $types = array( 'post' );
		return array(
			'post_types'         => $types,
			'llm_provider'       => in_array( $in['llm_provider'] ?? 'none', array( 'none', 'openai', 'anthropic', 'gemini' ), true ) ? $in['llm_provider'] : 'none',
			'llm_api_key'        => trim( sanitize_text_field( $in['llm_api_key'] ?? '' ) ),
			'llm_model'          => sanitize_text_field( $in['llm_model'] ?? '' ),
			'auto_score_on_save' => ! empty( $in['auto_score_on_save'] ) ? 1 : 0,
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		if ( ! in_array( $tab, array( 'dashboard', 'bulk', 'settings', 'license' ), true ) ) $tab = 'dashboard';
		$license = new AECS_License();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AEO Citability Score', 'aeo-citability-score' ); ?>
				<?php if ( $license->is_pro() ) : ?>
					<span style="display:inline-block;margin-left:12px;padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:0.1em;background:linear-gradient(90deg,#1D9E75,#5DE0B0);color:#06090f;border-radius:3px;vertical-align:middle;">PRO · <?php echo esc_html( ucfirst( (string) $license->tier() ) ); ?></span>
				<?php endif; ?>
			</h1>
			<nav class="nav-tab-wrapper">
				<?php $this->tab( 'dashboard', __( 'Dashboard', 'aeo-citability-score' ), $tab ); ?>
				<?php $this->tab( 'bulk',      __( 'Bulk scan', 'aeo-citability-score' ), $tab ); ?>
				<?php $this->tab( 'settings',  __( 'Settings',  'aeo-citability-score' ), $tab ); ?>
				<?php $this->tab( 'license',   __( 'License',   'aeo-citability-score' ), $tab ); ?>
			</nav>
			<div style="margin-top:20px;">
				<?php
				switch ( $tab ) {
					case 'bulk':     $this->render_bulk();     break;
					case 'settings': $this->render_settings(); break;
					case 'license':  $this->render_license( $license ); break;
					default:         $this->render_dashboard(); break;
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
			'llm_provider'       => 'none',
			'llm_api_key'        => '',
			'llm_model'          => '',
			'auto_score_on_save' => 1,
		) );
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		$is_pro = ( new AECS_License() )->is_pro();
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
				<tr><td colspan="2"><h3 style="margin:16px 0 4px;">LLM (Pro)</h3><p class="description">BYO API key. Never leaves your server. Free tier can safely ignore.</p></td></tr>
				<tr><th><label for="aecs_llm_provider">Provider</label></th><td>
					<select name="aecs_settings[llm_provider]" id="aecs_llm_provider" <?php disabled( ! $is_pro ); ?>>
						<option value="none"      <?php selected( $s['llm_provider'], 'none' ); ?>>None</option>
						<option value="openai"    <?php selected( $s['llm_provider'], 'openai' ); ?>>OpenAI</option>
						<option value="anthropic" <?php selected( $s['llm_provider'], 'anthropic' ); ?>>Anthropic</option>
						<option value="gemini"    <?php selected( $s['llm_provider'], 'gemini' ); ?>>Google Gemini</option>
					</select>
				</td></tr>
				<tr><th><label for="aecs_llm_api_key">API key</label></th><td>
					<input type="password" id="aecs_llm_api_key" name="aecs_settings[llm_api_key]" value="<?php echo esc_attr( $s['llm_api_key'] ); ?>" class="regular-text" autocomplete="off" <?php disabled( ! $is_pro ); ?>>
				</td></tr>
				<tr><th><label for="aecs_llm_model">Model</label></th><td>
					<input type="text" id="aecs_llm_model" name="aecs_settings[llm_model]" value="<?php echo esc_attr( $s['llm_model'] ); ?>" class="regular-text" placeholder="gpt-4o-mini · claude-3-5-haiku-latest · gemini-2.0-flash" <?php disabled( ! $is_pro ); ?>>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_license( AECS_License $license ) {
		$state = $license->get_state();
		$is_pro = $license->is_pro();
		?>
		<div class="card" style="max-width:720px;padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-radius:3px;">
			<?php if ( $is_pro ) : ?>
				<h2 style="margin-top:0;">Pro is active</h2>
				<p><strong>Key:</strong> <code><?php echo esc_html( $state['key'] ); ?></code></p>
				<p><strong>Tier:</strong> <?php echo esc_html( ucfirst( (string) $state['tier'] ) ); ?> · <strong>Sites:</strong> <?php echo esc_html( count( (array) $state['activated_sites'] ) . ' / ' . (int) $state['max_sites'] ); ?></p>
				<p><button type="button" class="button button-secondary" id="aecs-license-remove">Deactivate this site</button></p>
			<?php else : ?>
				<h2 style="margin-top:0;">Activate Pro</h2>
				<p>Pro unlocks: BYO-LLM rubric scoring, inline rewrites, historical trend, bulk analysis, CSV export.</p>
				<?php if ( ! empty( $state['message'] ) && 'inactive' !== $state['status'] ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $state['message'] ); ?></p></div><?php endif; ?>
				<p><label for="aecs-license-key"><strong>License key</strong></label><br><input type="text" id="aecs-license-key" class="regular-text" placeholder="AECS-XXXX-XXXX-XXXX-XXXX" autocomplete="off"></p>
				<p><button type="button" class="button button-primary" id="aecs-license-activate">Activate</button> <a href="https://kitmobley.com/plugins/<?php echo esc_attr( AECS_SLUG ); ?>/#pricing" target="_blank" rel="noopener" class="button button-secondary">Get a license →</a></p>
			<?php endif; ?>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'aecs_license' ) ); ?>;
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			document.addEventListener('click', function(e){
				if (e.target && e.target.id === 'aecs-license-activate') {
					var key = document.getElementById('aecs-license-key').value; if (!key) return;
					e.target.disabled = true; e.target.textContent = 'Activating…';
					fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:'aecs_license_save', nonce:nonce, license_key:key}) })
						.then(r=>r.json()).then(r=>{ if (r&&r.success) location.reload(); else { e.target.disabled=false; e.target.textContent='Activate'; alert((r&&r.data&&r.data.message)||'Activation failed'); } })
						.catch(()=>{ e.target.disabled=false; e.target.textContent='Activate'; alert('Network error'); });
				}
				if (e.target && e.target.id === 'aecs-license-remove') {
					if (!confirm('Deactivate this site?')) return;
					fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:'aecs_license_remove', nonce:nonce}) }).finally(()=>location.reload());
				}
			});
		})();
		</script>
		<?php
	}

	// ── AJAX ────────────────────────────────────────────────────

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

	public function ajax_license_save() {
		check_ajax_referer( 'aecs_license', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( ! $key ) wp_send_json_error( array( 'message' => 'License key required' ), 400 );
		$state = ( new AECS_License() )->activate( $key );
		if ( 'active' === $state['status'] ) {
			( new AECS_Updater() )->bust_cache();
			wp_send_json_success( array( 'state' => $state, 'message' => 'Activated' ) );
		}
		wp_send_json_error( array( 'state' => $state, 'message' => $state['message'] ?? 'Activation failed' ), 200 );
	}

	public function ajax_license_remove() {
		check_ajax_referer( 'aecs_license', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		( new AECS_License() )->deactivate();
		( new AECS_Updater() )->bust_cache();
		wp_send_json_success( array( 'message' => 'Deactivated' ) );
	}
}
