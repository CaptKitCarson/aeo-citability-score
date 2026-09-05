<?php
/**
 * Pro features: AI rubric analysis and paragraph rewriting.
 *
 * This whole directory is excluded from the WordPress.org build. Guideline 5
 * forbids shipping functionality that is restricted or locked behind payment,
 * so the free plugin does not contain this code at all rather than containing
 * it behind an is_pro() check.
 *
 * Everything here attaches through the hooks the free plugin exposes, so the
 * free build has no dangling references to it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Pro {

	public function __construct() {
		add_action( 'wp_ajax_aecs_ai_analyze', array( $this, 'ajax_ai_analyze' ) );
		add_action( 'wp_ajax_aecs_ai_rewrite', array( $this, 'ajax_ai_rewrite' ) );
		add_action( 'aecs_editor_actions',     array( $this, 'render_action' ), 10, 2 );
		add_action( 'aecs_editor_after',       array( $this, 'render_after' ) );
		add_action( 'aecs_editor_scripts',     array( $this, 'print_scripts' ) );
		add_action( 'aecs_settings_llm',       array( $this, 'render_settings' ) );
		add_filter( 'aecs_default_settings',   array( $this, 'default_settings' ) );
		add_filter( 'aecs_sanitize_settings',  array( $this, 'sanitize_settings' ), 10, 2 );
		add_action( 'aecs_admin_badge',        array( $this, 'render_badge' ) );
		add_filter( 'aecs_admin_tabs',         array( $this, 'register_tab' ) );
		add_action( 'aecs_admin_render_tab',   array( $this, 'render_tab' ) );
		add_action( 'wp_ajax_aecs_license_save',   array( $this, 'ajax_license_save' ) );
		add_action( 'wp_ajax_aecs_license_remove', array( $this, 'ajax_license_remove' ) );
	}

	public function default_settings( $defaults ) {
		return array_merge( $defaults, array(
			'llm_provider' => 'none',
			'llm_api_key'  => '',
			'llm_model'    => '',
		) );
	}

	public function sanitize_settings( $out, $in ) {
		$out['llm_provider'] = in_array( $in['llm_provider'] ?? 'none', array( 'none', 'openai', 'anthropic', 'gemini' ), true ) ? $in['llm_provider'] : 'none';
		$out['llm_api_key']  = trim( sanitize_text_field( $in['llm_api_key'] ?? '' ) );
		$out['llm_model']    = sanitize_text_field( $in['llm_model'] ?? '' );
		return $out;
	}

	private function is_pro() {
		return class_exists( 'AECS_License' ) && ( new AECS_License() )->is_pro();
	}

	private function llm_configured() {
		$s = get_option( 'aecs_settings', array() );
		return ! empty( $s['llm_provider'] ) && 'none' !== $s['llm_provider'] && ! empty( $s['llm_api_key'] );
	}

	public function render_badge() {
		if ( ! $this->is_pro() ) return;
		$tier = ( new AECS_License() )->tier();
		echo '<span style="display:inline-block;margin-left:12px;padding:3px 10px;font-size:11px;font-weight:700;letter-spacing:0.1em;background:linear-gradient(90deg,#1D9E75,#5DE0B0);color:#06090f;border-radius:3px;vertical-align:middle;">PRO &middot; ' . esc_html( ucfirst( (string) $tier ) ) . '</span>';
	}

	public function render_action( $post, $nonce ) {
		$is_pro     = $this->is_pro();
		$configured = $is_pro && $this->llm_configured();
		?>
		<button type="button" class="button button-primary" data-aecs-action="ai" data-nonce="<?php echo esc_attr( $nonce ); ?>" <?php disabled( ! $configured ); ?>>
			<?php echo $is_pro ? esc_html__( 'Run AI analysis', 'aeo-citability-score' ) : esc_html__( 'AI analysis (Pro)', 'aeo-citability-score' ); ?>
		</button>
		<?php
	}

	public function render_after( $post ) {
		$is_pro     = $this->is_pro();
		$configured = $is_pro && $this->llm_configured();
		?>
		<?php if ( ! $is_pro ) : ?>
			<p class="aecs-upsell"><a href="<?php echo esc_url( AECS_PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Unlock AI rubric + rewrites', 'aeo-citability-score' ); ?> &rarr;</a></p>
		<?php elseif ( ! $configured ) : ?>
			<p class="aecs-upsell"><a href="<?php echo esc_url( admin_url( 'admin.php?page=aeo-citability-score&tab=settings' ) ); ?>"><?php esc_html_e( 'Configure your LLM API key', 'aeo-citability-score' ); ?> &rarr;</a></p>
		<?php endif; ?>
		<div class="aecs-ai-result" style="display:none;"></div>
		<?php
	}

	public function print_scripts( $post ) {
		?>
		<script>
		(function(){
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var box  = document.querySelector('.aecs-box[data-post-id="<?php echo (int) $post->ID; ?>"]');
			if (!box) return;
			var postId = box.getAttribute('data-post-id');
			box.addEventListener('click', function(e){
				var b = e.target.closest('[data-aecs-action="ai"]');
				if (!b) return;
				var nonce = b.getAttribute('data-nonce');
				b.disabled = true;
				var origText = b.textContent; b.textContent = 'Working…';
				fetch(ajax, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({ action: 'aecs_ai_analyze', nonce: nonce, post_id: postId })
				})
					.then(function(r){ return r.json(); })
					.then(function(r){
						b.disabled = false; b.textContent = origText;
						if (!r || !r.success) { alert((r && r.data && r.data.message) || 'Failed'); return; }
						var wrap = box.querySelector('.aecs-ai-result');
						if (wrap) { wrap.style.display = 'block'; wrap.innerHTML = renderAI(r.data); }
					})
					.catch(function(){ b.disabled = false; b.textContent = origText; alert('Network error'); });
			});
			function renderAI(d){
				var html = '<div class="aecs-ai-head"><span class="aecs-ai-num">' + (d.score||0) + '</span><span class="aecs-ai-label">AI RUBRIC</span></div>';
				if (d.reasoning) html += '<p class="aecs-ai-reasoning">' + escapeHtml(d.reasoning) + '</p>';
				if (d.signals) {
					html += '<div class="aecs-ai-signals">';
					Object.keys(d.signals).forEach(function(k){
						var s = d.signals[k];
						html += '<div class="aecs-ai-sig"><strong>' + escapeHtml(k) + ': ' + (s.score||0) + '</strong><br><small>' + escapeHtml(s.note||'') + '</small></div>';
					});
					html += '</div>';
				}
				if (d.weak_passages && d.weak_passages.length) {
					html += '<div class="aecs-ai-weak"><h4>Weak passages</h4>';
					d.weak_passages.forEach(function(w){
						html += '<div class="aecs-ai-weak-item"><em>' + escapeHtml(w.snippet||'') + '</em><br>' + escapeHtml(w.why||'') + '<br><small>Suggestion: ' + escapeHtml(w.suggestion||'') + '</small></div>';
					});
					html += '</div>';
				}
				return html;
			}
			function escapeHtml(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
		})();
		</script>
		<?php
	}

	public function render_settings( $s ) {
		$is_pro = $this->is_pro();
		$s = array_merge( array( 'llm_provider' => 'none', 'llm_api_key' => '', 'llm_model' => '' ), (array) $s );
		?>
		<tr><td colspan="2"><h3 style="margin:16px 0 4px;">LLM</h3><p class="description">BYO API key. Never leaves your server.</p></td></tr>
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
			<input type="text" id="aecs_llm_model" name="aecs_settings[llm_model]" value="<?php echo esc_attr( $s['llm_model'] ); ?>" class="regular-text" placeholder="gpt-4o-mini &middot; claude-3-5-haiku-latest &middot; gemini-2.0-flash" <?php disabled( ! $is_pro ); ?>>
		</td></tr>
		<?php
	}

	// ── AJAX ─────────────────────────────────────────────────────

	public function ajax_ai_analyze() {
		check_ajax_referer( 'aecs_editor', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		if ( ! $this->is_pro() ) wp_send_json_error( array( 'message' => 'Pro required' ), 402 );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) wp_send_json_error( array( 'message' => 'Bad post' ), 400 );
		$body = wp_strip_all_tags( $post->post_content );
		$res = ( new AECS_LLM() )->score_content( $post->post_title, $body );
		if ( empty( $res['ok'] ) ) wp_send_json_error( array( 'message' => $res['error'] ?? 'AI failed' ), 200 );
		update_post_meta( $post_id, AECS_META_ANALYSIS, $res['data'] );
		wp_send_json_success( $res['data'] );
	}

	public function ajax_ai_rewrite() {
		check_ajax_referer( 'aecs_editor', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		if ( ! $this->is_pro() ) wp_send_json_error( array( 'message' => 'Pro required' ), 402 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$original = isset( $_POST['original'] ) ? wp_unslash( $_POST['original'] ) : '';
		$context  = isset( $_POST['context'] )  ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
		if ( empty( $original ) ) wp_send_json_error( array( 'message' => 'No original text' ), 400 );
		$res = ( new AECS_LLM() )->rewrite_paragraph( $original, $context );
		if ( empty( $res['ok'] ) ) wp_send_json_error( array( 'message' => $res['error'] ?? 'AI failed' ), 200 );
		wp_send_json_success( array( 'rewrite' => $res['data'] ) );
	}

	// ── Licence surface, relocated out of the free admin class ───

	public function register_tab( $tabs ) {
		$tabs[] = 'license';
		return $tabs;
	}

	public function render_tab( $tab ) {
		if ( 'license' !== $tab ) return;
		$this->render_license( new AECS_License() );
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
