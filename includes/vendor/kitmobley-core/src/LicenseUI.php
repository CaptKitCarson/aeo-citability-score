<?php
/**
 * Reusable license activation card + AJAX wiring.
 *
 * Registers wp_ajax_<prefix>_license_save and wp_ajax_<prefix>_license_remove.
 * Provides render() to output the card HTML in an admin page.
 */

namespace KitMobley\PluginCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LicenseUI {

	/** @var Config */
	private $config;
	/** @var License */
	private $license;
	/** @var Updater|null */
	private $updater;

	public function __construct( Config $config, License $license, ?Updater $updater = null ) {
		$this->config  = $config;
		$this->license = $license;
		$this->updater = $updater;
	}

	public function register_ajax() {
		add_action( 'wp_ajax_' . $this->config->ajax_prefix() . '_license_save',   array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_' . $this->config->ajax_prefix() . '_license_remove', array( $this, 'ajax_remove' ) );
	}

	public function ajax_save() {
		check_ajax_referer( $this->config->ajax_prefix() . '_license', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => 'License key required' ), 400 );
		}
		$state = $this->license->activate( $key );
		if ( 'active' === $state['status'] ) {
			if ( $this->updater ) $this->updater->bust_cache();
			wp_send_json_success( array( 'state' => $state, 'message' => 'License activated.' ) );
		}
		wp_send_json_error( array(
			'state'   => $state,
			'message' => ! empty( $state['message'] ) ? $state['message'] : 'Activation failed.',
		), 200 );
	}

	public function ajax_remove() {
		check_ajax_referer( $this->config->ajax_prefix() . '_license', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$this->license->deactivate();
		if ( $this->updater ) $this->updater->bust_cache();
		wp_send_json_success( array( 'message' => 'Site deactivated.' ) );
	}

	/**
	 * @param array{prefix_label?:string,key_placeholder?:string} $opts
	 */
	public function render( array $opts = array() ) {
		$state = $this->license->get_state();
		$is_pro = $this->license->is_pro();
		$prefix = $opts['prefix_label']   ?? 'KM';
		$placeholder = $opts['key_placeholder'] ?? ( strtoupper( $prefix ) . '-XXXX-XXXX-XXXX-XXXX' );
		$expires_label = '';
		if ( ! empty( $state['expires_at'] ) ) {
			$ts = strtotime( $state['expires_at'] );
			if ( $ts ) $expires_label = date_i18n( get_option( 'date_format' ), $ts );
		}
		$ajax_prefix = $this->config->ajax_prefix();
		$nonce = wp_create_nonce( $ajax_prefix . '_license' );
		?>
		<div class="kmpc-license-card <?php echo $is_pro ? 'kmpc-license-active' : ''; ?>">
			<?php if ( $is_pro ) : ?>
				<div class="kmpc-license-head">
					<h2><?php esc_html_e( 'Pro is active', 'kitmobley-plugin-core' ); ?></h2>
					<span class="kmpc-badge kmpc-badge-active">&#10003; <?php echo esc_html( $this->license->status_label() ); ?></span>
				</div>
				<dl class="kmpc-license-meta">
					<dt><?php esc_html_e( 'License key', 'kitmobley-plugin-core' ); ?></dt>
					<dd><code><?php echo esc_html( $state['key'] ); ?></code></dd>
					<dt><?php esc_html_e( 'Tier', 'kitmobley-plugin-core' ); ?></dt>
					<dd><?php echo esc_html( ucfirst( (string) $state['tier'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Sites used', 'kitmobley-plugin-core' ); ?></dt>
					<dd><?php echo esc_html( count( (array) $state['activated_sites'] ) . ' / ' . (int) $state['max_sites'] ); ?></dd>
					<?php if ( $expires_label ) : ?>
						<dt><?php esc_html_e( 'Renews', 'kitmobley-plugin-core' ); ?></dt>
						<dd><?php echo esc_html( $expires_label ); ?></dd>
					<?php endif; ?>
				</dl>
				<p>
					<button type="button" class="button button-secondary kmpc-license-remove"><?php esc_html_e( 'Deactivate this site', 'kitmobley-plugin-core' ); ?></button>
				</p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Activate Pro', 'kitmobley-plugin-core' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste your license key from the purchase email.', 'kitmobley-plugin-core' ); ?></p>
				<?php if ( ! empty( $state['message'] ) && 'inactive' !== $state['status'] ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $state['message'] ); ?></p></div>
				<?php endif; ?>
				<p>
					<label for="kmpc-license-key"><strong><?php esc_html_e( 'License key', 'kitmobley-plugin-core' ); ?></strong></label><br />
					<input type="text" id="kmpc-license-key" class="regular-text kmpc-license-key" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" />
				</p>
				<p>
					<button type="button" class="button button-primary kmpc-license-activate"><?php esc_html_e( 'Activate', 'kitmobley-plugin-core' ); ?></button>
					<a href="<?php echo esc_url( $this->config->pro_url() ); ?>" target="_blank" rel="noopener" class="button button-secondary"><?php esc_html_e( 'Get a license', 'kitmobley-plugin-core' ); ?> &rarr;</a>
				</p>
			<?php endif; ?>
		</div>
		<style>
			.kmpc-license-card { max-width:720px; background:#fff; border:1px solid #dcdcde; border-radius:3px; padding:24px 28px; }
			.kmpc-license-card.kmpc-license-active { border-left:4px solid #1D9E75; }
			.kmpc-license-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
			.kmpc-license-head h2 { margin:0; }
			.kmpc-license-meta { display:grid; grid-template-columns:140px 1fr; row-gap:8px; column-gap:16px; margin:0 0 20px; }
			.kmpc-license-meta dt { font-weight:600; color:#50575e; font-size:13px; text-transform:uppercase; letter-spacing:0.06em; }
			.kmpc-license-meta dd { margin:0; color:#1d2327; font-size:14px; }
			.kmpc-license-meta code { font-size:13px; background:#f6f7f7; padding:3px 8px; border-radius:3px; letter-spacing:1px; }
			.kmpc-badge { display:inline-block; padding:3px 10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; border-radius:3px; }
			.kmpc-badge-active { background:rgba(29,158,117,0.14); color:#106a4d; }
		</style>
		<script>
		(function(){
			var ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var prefix = <?php echo wp_json_encode( $ajax_prefix ); ?>;
			document.addEventListener('click', function(e){
				var t = e.target;
				if (t.classList && t.classList.contains('kmpc-license-activate')) {
					var card = t.closest('.kmpc-license-card');
					var input = card ? card.querySelector('.kmpc-license-key') : null;
					var key = input ? input.value : '';
					if (!key) return;
					t.disabled = true; t.textContent = 'Activating…';
					fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({ action: prefix + '_license_save', nonce: nonce, license_key: key }) })
						.then(function(r){ return r.json(); })
						.then(function(r){ if (r && r.success) { window.location.reload(); } else { t.disabled = false; t.textContent = 'Activate'; alert((r && r.data && r.data.message) || 'Activation failed'); } })
						.catch(function(){ t.disabled = false; t.textContent = 'Activate'; alert('Network error'); });
				}
				if (t.classList && t.classList.contains('kmpc-license-remove')) {
					if (!confirm('Deactivate this site from your license?')) return;
					fetch(ajax, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({ action: prefix + '_license_remove', nonce: nonce }) }).finally(function(){ window.location.reload(); });
				}
			});
		})();
		</script>
		<?php
	}
}
