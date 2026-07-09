<?php
/**
 * Plugin Name:       AEO Citability Score
 * Plugin URI:        https://kitmobley.com/plugins/aeo-citability-score/
 * Description:       Score every post on the factors that actually cause AI citation — front-loaded answers, extractable passages, entity clarity, source attribution, speakable coverage. Pro adds AI-powered rubric + inline rewrites via your own OpenAI / Anthropic / Gemini key.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Kit Mobley
 * Author URI:        https://kitmobley.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aeo-citability-score
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AECS_VERSION',  '1.0.0' );
define( 'AECS_SLUG',     'aeo-citability-score' );
define( 'AECS_FILE',     __FILE__ );
define( 'AECS_DIR',      plugin_dir_path( __FILE__ ) );
define( 'AECS_URL',      plugin_dir_url( __FILE__ ) );
define( 'AECS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AECS_META_SCORE',   '_aecs_score' );
define( 'AECS_META_ANALYSIS','_aecs_analysis' );

if ( ! defined( 'AECS_LICENSE_API_BASE' ) ) {
	define( 'AECS_LICENSE_API_BASE', 'https://kitmobley.com/api' );
}

// Shared kitmobley/wp-plugin-core library (bundled at build time).
require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/Config.php';
require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/License.php';
require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/Updater.php';
require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/LicenseUI.php';

require_once AECS_DIR . 'includes/class-scorer.php';
require_once AECS_DIR . 'includes/class-llm.php';
require_once AECS_DIR . 'includes/class-license.php';
require_once AECS_DIR . 'includes/class-updater.php';
require_once AECS_DIR . 'includes/class-editor.php';
require_once AECS_DIR . 'includes/class-admin.php';

function aecs_plugin_config() {
	static $cfg = null;
	if ( null === $cfg ) {
		$cfg = new \KitMobley\PluginCore\Config( array(
			'slug'          => AECS_SLUG,
			'version'       => AECS_VERSION,
			'basename'      => AECS_BASENAME,
			'text_domain'   => 'aeo-citability-score',
			'api_base'      => AECS_LICENSE_API_BASE,
			'option_key'    => 'aecs_license',
			'transient_key' => 'aecs_update_manifest',
			'ajax_prefix'   => 'aecs',
			'pro_url'       => 'https://kitmobley.com/plugins/' . AECS_SLUG . '/#pricing',
		) );
	}
	return $cfg;
}

register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'aecs_settings' ) ) {
		add_option( 'aecs_settings', array(
			'post_types'   => array( 'post' ),
			'llm_provider' => 'none',
			'llm_api_key'  => '',
			'llm_model'    => '',
			'auto_score_on_save' => 1,
		) );
	}
	if ( ! wp_next_scheduled( 'aecs_license_revalidate' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'aecs_license_revalidate' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( 'aecs_license_revalidate' );
	if ( $ts ) wp_unschedule_event( $ts, 'aecs_license_revalidate' );
} );

add_action( 'aecs_license_revalidate', function () {
	( new AECS_License() )->revalidate();
} );

add_action( 'init', function () {
	( new AECS_Updater() )->register();
	if ( is_admin() ) {
		new AECS_Editor();
		new AECS_Admin();
	}
} );

// Auto-score on save (structural — no LLM cost).
add_action( 'save_post', function ( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	$settings = get_option( 'aecs_settings', array() );
	if ( empty( $settings['auto_score_on_save'] ) ) return;
	if ( ! in_array( $post->post_type, (array) ( $settings['post_types'] ?? array( 'post' ) ), true ) ) return;
	if ( 'publish' !== $post->post_status ) return;

	$score = ( new AECS_Scorer() )->score_post( $post_id );
	update_post_meta( $post_id, AECS_META_SCORE, $score );
}, 10, 2 );

add_filter( 'plugin_action_links_' . AECS_BASENAME, function ( $links ) {
	$link = '<a href="' . esc_url( admin_url( 'admin.php?page=aeo-citability-score' ) ) . '">' . esc_html__( 'Dashboard', 'aeo-citability-score' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
} );
