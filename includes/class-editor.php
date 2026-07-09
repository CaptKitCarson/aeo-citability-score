<?php
/**
 * Per-post editor meta box — score gauge, signal breakdown, AI analysis button.
 *
 * Works in both Classic Editor and Gutenberg's post-editor sidebar
 * (via the sidebar-metabox compatibility layer).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Editor {

	public function __construct() {
		add_action( 'add_meta_boxes',              array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts',       array( $this, 'assets' ) );
		add_action( 'wp_ajax_aecs_score_post',     array( $this, 'ajax_score' ) );
		add_action( 'wp_ajax_aecs_ai_analyze',     array( $this, 'ajax_ai_analyze' ) );
		add_action( 'wp_ajax_aecs_ai_rewrite',     array( $this, 'ajax_ai_rewrite' ) );
	}

	public function register() {
		$settings = get_option( 'aecs_settings', array() );
		$types = (array) ( $settings['post_types'] ?? array( 'post' ) );
		foreach ( $types as $pt ) {
			add_meta_box( 'aecs_score_box', __( 'AEO Citability Score', 'aeo-citability-score' ), array( $this, 'render_box' ), $pt, 'side', 'high' );
		}
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && strpos( (string) $hook, 'aeo-citability-score' ) === false ) return;
		wp_enqueue_style( 'aecs-editor', AECS_URL . 'assets/editor.css', array(), AECS_VERSION );
	}

	public function render_box( $post ) {
		$score_data = get_post_meta( $post->ID, AECS_META_SCORE, true );
		if ( ! is_array( $score_data ) ) {
			$score_data = ( new AECS_Scorer() )->score_post( $post->ID );
		}
		$is_pro = ( new AECS_License() )->is_pro();
		$s = get_option( 'aecs_settings', array() );
		$llm_configured = $is_pro && ! empty( $s['llm_provider'] ) && 'none' !== $s['llm_provider'] && ! empty( $s['llm_api_key'] );

		$score = (int) ( $score_data['score'] ?? 0 );
		$tier  = (string) ( $score_data['tier'] ?? 'poor' );
		$nonce = wp_create_nonce( 'aecs_editor' );
		?>
		<div class="aecs-box" data-post-id="<?php echo (int) $post->ID; ?>">
			<div class="aecs-gauge aecs-tier-<?php echo esc_attr( $tier ); ?>">
				<div class="aecs-gauge-num"><?php echo esc_html( $score ); ?><span>/100</span></div>
				<div class="aecs-gauge-tier"><?php echo esc_html( strtoupper( $tier ) ); ?></div>
			</div>

			<div class="aecs-signals">
				<?php if ( ! empty( $score_data['signals'] ) ) : foreach ( $score_data['signals'] as $sig ) : ?>
					<div class="aecs-signal aecs-signal-<?php echo $this->band_class( $sig['score'] ); ?>">
						<div class="aecs-signal-head">
							<span class="aecs-signal-label"><?php echo esc_html( $sig['label'] ); ?></span>
							<span class="aecs-signal-num"><?php echo (int) $sig['score']; ?></span>
						</div>
						<div class="aecs-signal-detail"><?php echo esc_html( $sig['detail'] ); ?></div>
					</div>
				<?php endforeach; endif; ?>
			</div>

			<p>
				<button type="button" class="button" data-aecs-action="rescore" data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Re-score', 'aeo-citability-score' ); ?>
				</button>
				<button type="button" class="button button-primary" data-aecs-action="ai" data-nonce="<?php echo esc_attr( $nonce ); ?>" <?php disabled( ! $llm_configured ); ?>>
					<?php echo $is_pro ? esc_html__( 'Run AI analysis', 'aeo-citability-score' ) : esc_html__( 'AI analysis (Pro)', 'aeo-citability-score' ); ?>
				</button>
			</p>

			<?php if ( ! $is_pro ) : ?>
				<p class="aecs-upsell"><a href="https://kitmobley.com/plugins/<?php echo esc_attr( AECS_SLUG ); ?>/#pricing" target="_blank" rel="noopener"><?php esc_html_e( 'Unlock AI rubric + rewrites →', 'aeo-citability-score' ); ?></a></p>
			<?php elseif ( ! $llm_configured ) : ?>
				<p class="aecs-upsell"><a href="<?php echo esc_url( admin_url( 'admin.php?page=aeo-citability-score&tab=settings' ) ); ?>"><?php esc_html_e( 'Configure your LLM API key →', 'aeo-citability-score' ); ?></a></p>
			<?php endif; ?>

			<div class="aecs-ai-result" style="display:none;"></div>
		</div>

		<script>
		(function(){
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var box   = document.currentScript.previousElementSibling;
			if (!box) return;
			var postId = box.getAttribute('data-post-id');
			box.addEventListener('click', function(e){
				var b = e.target.closest('[data-aecs-action]');
				if (!b) return;
				var action = b.getAttribute('data-aecs-action');
				var nonce  = b.getAttribute('data-nonce');
				b.disabled = true;
				var origText = b.textContent; b.textContent = 'Working…';
				var body = new URLSearchParams({ action: 'rescore' === action ? 'aecs_score_post' : 'aecs_ai_analyze', nonce: nonce, post_id: postId });
				fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
					.then(function(r){ return r.json(); })
					.then(function(r){
						b.disabled = false; b.textContent = origText;
						if (!r || !r.success) { alert((r&&r.data&&r.data.message)||'Failed'); return; }
						if ('rescore' === action) { window.location.reload(); return; }
						var wrap = box.querySelector('.aecs-ai-result');
						if (wrap) {
							wrap.style.display = 'block';
							wrap.innerHTML = renderAI(r.data);
						}
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

	private function band_class( $score ) {
		if ( $score >= 75 ) return 'good';
		if ( $score >= 50 ) return 'fair';
		return 'poor';
	}

	// ── AJAX ─────────────────────────────────────────────────────

	public function ajax_score() {
		check_ajax_referer( 'aecs_editor', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( array( 'message' => 'Bad post' ), 400 );
		$data = ( new AECS_Scorer() )->score_post( $post_id );
		update_post_meta( $post_id, AECS_META_SCORE, $data );
		wp_send_json_success( $data );
	}

	public function ajax_ai_analyze() {
		check_ajax_referer( 'aecs_editor', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		if ( ! ( new AECS_License() )->is_pro() ) wp_send_json_error( array( 'message' => 'Pro required' ), 402 );
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
		if ( ! ( new AECS_License() )->is_pro() ) wp_send_json_error( array( 'message' => 'Pro required' ), 402 );
		$original = isset( $_POST['original'] ) ? wp_unslash( $_POST['original'] ) : '';
		$context  = isset( $_POST['context'] )  ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';
		if ( empty( $original ) ) wp_send_json_error( array( 'message' => 'No original text' ), 400 );
		$res = ( new AECS_LLM() )->rewrite_paragraph( $original, $context );
		if ( empty( $res['ok'] ) ) wp_send_json_error( array( 'message' => $res['error'] ?? 'AI failed' ), 200 );
		wp_send_json_success( array( 'rewrite' => $res['data'] ) );
	}
}
