<?php
/**
 * WP update-server client.
 *
 * Registers into WP's plugin update pipeline:
 *   - pre_set_site_transient_update_plugins — inject update info
 *   - plugins_api — synthesize "View details" popup response
 *   - upgrader_source_selection — rename mismatched extracted folder
 *
 * Polls Config->api_base() + /plugin-updates for the manifest, caching
 * for 12 hours in the transient named by Config->transient_key().
 */

namespace KitMobley\PluginCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {

	const TTL = 12 * HOUR_IN_SECONDS;

	/** @var Config */
	private $config;
	/** @var License */
	private $license;

	public function __construct( Config $config, License $license ) {
		$this->config  = $config;
		$this->license = $license;
	}

	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api',                            array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection',              array( $this, 'normalize_source_folder' ), 10, 4 );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) $transient = new \stdClass();
		if ( ! isset( $transient->response ) ) $transient->response = array();

		$manifest = $this->fetch_manifest();
		if ( ! $manifest ) return $transient;

		$current = $this->config->version();
		$latest  = $manifest['version'] ?? $current;

		if ( version_compare( $latest, $current, '>' ) ) {
			$transient->response[ $this->config->basename() ] = (object) $this->build_manifest_object( $latest, $manifest );
		} else {
			if ( ! isset( $transient->no_update ) ) $transient->no_update = array();
			$obj = $this->build_manifest_object( $current, $manifest );
			$obj['package'] = '';
			$transient->no_update[ $this->config->basename() ] = (object) $obj;
		}
		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) return $result;
		if ( empty( $args->slug ) || $this->config->slug() !== $args->slug ) return $result;

		$manifest = $this->fetch_manifest();
		$latest   = $manifest['version'] ?? $this->config->version();

		return (object) array(
			'name'         => 'KitMobley Plugin',
			'slug'         => $this->config->slug(),
			'version'      => $latest,
			'author'       => '<a href="https://kitmobley.com">Kit Mobley</a>',
			'homepage'     => 'https://kitmobley.com/plugins/' . $this->config->slug() . '/',
			'requires'     => $manifest['requires']     ?? '5.8',
			'tested'       => $manifest['tested']       ?? '6.7',
			'requires_php' => $manifest['requires_php'] ?? '7.4',
			'download_link'=> $manifest['package_url']  ?? '',
			'sections'     => array(
				'description' => 'See ' . 'https://kitmobley.com/plugins/' . $this->config->slug() . '/ for full details.',
			),
		);
	}

	/**
	 * Ensure the extracted update ZIP's folder matches the plugin slug so
	 * WP's copy step overwrites the existing install (instead of creating
	 * schema-conflict-auditor-1.2.0/ next to schema-conflict-auditor/).
	 */
	public function normalize_source_folder( $source, $remote_source, $upgrader, $extra = null ) {
		if ( ! is_string( $source ) ) return $source;
		$expected = trailingslashit( $remote_source ) . $this->config->slug();
		if ( trailingslashit( $source ) === trailingslashit( $expected ) ) return $source;
		$basename = basename( untrailingslashit( $source ) );
		if ( 0 !== strpos( $basename, $this->config->slug() ) ) return $source;
		if ( @rename( untrailingslashit( $source ), $expected ) ) {
			return trailingslashit( $expected );
		}
		return $source;
	}

	public function fetch_manifest() {
		$cached = get_transient( $this->config->transient_key() );
		if ( is_array( $cached ) ) return $cached;

		$license_state = $this->license->get_state();
		$args = array(
			'slug'        => $this->config->slug(),
			'version'     => $this->config->version(),
			'site_url'    => home_url( '/' ),
			'license_key' => ! empty( $license_state['key'] ) ? $license_state['key'] : '',
		);
		$url = $this->config->api_base() . '/plugin-updates?' . http_build_query( $args );

		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) return null;
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) return null;

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) return null;

		set_transient( $this->config->transient_key(), $data, self::TTL );
		return $data;
	}

	public function bust_cache() {
		delete_transient( $this->config->transient_key() );
	}

	private function build_manifest_object( $version, array $manifest ) {
		return array(
			'slug'         => $this->config->slug(),
			'plugin'       => $this->config->basename(),
			'new_version'  => $version,
			'url'          => $manifest['homepage']     ?? ( 'https://kitmobley.com/plugins/' . $this->config->slug() . '/' ),
			'package'      => $manifest['package_url']  ?? '',
			'requires'     => $manifest['requires']     ?? '5.8',
			'tested'       => $manifest['tested']       ?? '6.7',
			'requires_php' => $manifest['requires_php'] ?? '7.4',
		);
	}
}
