<?php
/**
 * Editor metabox: the citability score, its signals, and a re-score control.
 *
 * Everything in this file is free and ungated. AI analysis and rewriting live
 * in the separate Pro add-on, which hooks `aecs_editor_actions` to add its own
 * control and `aecs_editor_scripts` to add its own behaviour. That separation
 * is deliberate: the WordPress.org build ships this file and no AI code at all,
 * rather than shipping AI code behind a licence check.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Editor {

	public function __construct() {
		add_action( 'add_meta_boxes',          array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts',   array( $this, 'assets' ) );
		add_action( 'wp_ajax_aecs_score_post', array( $this, 'ajax_score' ) );
	}

	public function register() {
		$s   = get_option( 'aecs_settings', array() );
		$pts = ! empty( $s['post_types'] ) && is_array( $s['post_types'] ) ? $s['post_types'] : array( 'post', 'page' );
		foreach ( $pts as $pt ) {
			add_meta_box( 'aecs_score_box', __( 'AEO Citability Score', 'aeo-citability-score' ), array( $this, 'render_box' ), $pt, 'side', 'high' );
		}
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;
		wp_enqueue_style( 'aecs-editor', AECS_URL . 'assets/editor.css', array(), AECS_VERSION );
	}

	public function render_box( $post ) {
		$score_data = get_post_meta( $post->ID, AECS_META_SCORE, true );
		if ( ! is_array( $score_data ) ) {
			$score_data = ( new AECS_Scorer() )->score_post( $post->ID );
		}

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
					<div class="aecs-signal aecs-signal-<?php echo esc_attr( $this->band_class( $sig['score'] ) ); ?>">
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
				<?php
				/**
				 * Extra controls for this metabox. The Pro add-on renders its AI
				 * analysis button here. Nothing hooks this in the free plugin.
				 *
				 * @param WP_Post $post  The post being edited.
				 * @param string  $nonce Nonce for the aecs_editor action.
				 */
				do_action( 'aecs_editor_actions', $post, $nonce );
				?>
			</p>
			<?php
			/**
			 * Block-level additions below the buttons. The Pro add-on renders its
			 * upsell notice and AI result container here.
			 *
			 * @param WP_Post $post The post being edited.
			 */
			do_action( 'aecs_editor_after', $post );
			?>
		</div>

		<script>
		(function(){
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var box   = document.currentScript.previousElementSibling;
			if (!box) return;
			var postId = box.getAttribute('data-post-id');
			box.addEventListener('click', function(e){
				var b = e.target.closest('[data-aecs-action="rescore"]');
				if (!b) return;
				var nonce = b.getAttribute('data-nonce');
				b.disabled = true;
				var origText = b.textContent; b.textContent = 'Working…';
				fetch(ajax, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({ action: 'aecs_score_post', nonce: nonce, post_id: postId })
				})
					.then(function(r){ return r.json(); })
					.then(function(r){
						b.disabled = false; b.textContent = origText;
						if (!r || !r.success) { alert((r && r.data && r.data.message) || 'Failed'); return; }
						window.location.reload();
					})
					.catch(function(){ b.disabled = false; b.textContent = origText; alert('Network error'); });
			});
		})();
		</script>
		<?php
		/**
		 * Extra scripts for this metabox. The Pro add-on prints its AI handling
		 * here so the free build carries no AI JavaScript.
		 *
		 * @param WP_Post $post The post being edited.
		 */
		do_action( 'aecs_editor_scripts', $post );
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
}
