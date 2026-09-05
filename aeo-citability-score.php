<?php
/**
 * Plugin Name:       AEO Citability Score
 * Plugin URI:        https://kitmobley.com/plugins/aeo-citability-score/
 * Description:       Score every post on the factors that actually cause AI citation — front-loaded answers, extractable passages, entity clarity, source attribution, speakable coverage. Pro adds AI-powered rubric + inline rewrites via your own OpenAI / Anthropic / Gemini key.
 * Version:           1.0.0
 * Requires at least: 5.9
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

if ( ! defined( 'AECS_PRO_URL' ) ) {
	define( 'AECS_PRO_URL', 'https://kitmobley.com/plugins/aeo-citability-score/#pricing' );
}

// Shared kitmobley/wp-plugin-core library (bundled at build time).
// Free plugin. Everything below is unconditional and ungated.
require_once AECS_DIR . 'includes/class-scorer.php';
require_once AECS_DIR . 'includes/class-editor.php';
require_once AECS_DIR . 'includes/class-admin.php';

// Pro add-on, absent from the WordPress.org build. Guideline 5 forbids shipping
// functionality that is locked behind payment, so rather than gate AI features
// with an is_pro() check we ship none of that code here. When the add-on is
// present it attaches through the hooks the free plugin exposes.
if ( file_exists( AECS_DIR . 'includes/pro/class-pro.php' ) ) {
	require_once AECS_DIR . 'includes/pro/config.php';
	require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/Config.php';
	require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/License.php';
	require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/Updater.php';
	require_once AECS_DIR . 'includes/vendor/kitmobley-core/src/LicenseUI.php';
	require_once AECS_DIR . 'includes/class-license.php';
	require_once AECS_DIR . 'includes/class-updater.php';
	require_once AECS_DIR . 'includes/pro/class-llm.php';
	require_once AECS_DIR . 'includes/pro/class-pro.php';
}
register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'aecs_settings' ) ) {
		add_option( 'aecs_settings', apply_filters( 'aecs_default_settings', array(
			'post_types'         => array( 'post' ),
			'auto_score_on_save' => 1,
		) ) );
	}
	if ( class_exists( 'AECS_License' ) && ! wp_next_scheduled( 'aecs_license_revalidate' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'aecs_license_revalidate' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( 'aecs_license_revalidate' );
	if ( $ts ) wp_unschedule_event( $ts, 'aecs_license_revalidate' );
} );

add_action( 'aecs_license_revalidate', function () {
	if ( class_exists( 'AECS_License' ) ) ( new AECS_License() )->revalidate();
} );

add_action( 'init', function () {
	// Both absent in the WordPress.org build.
	if ( class_exists( 'AECS_Updater' ) ) ( new AECS_Updater() )->register();
	if ( class_exists( 'AECS_Pro' ) )     new AECS_Pro();

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
