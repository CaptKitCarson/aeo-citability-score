<?php
/**
 * Typed bootstrap configuration for a plugin using KitMobley\PluginCore.
 *
 * Each host plugin instantiates one Config and hands it to License and
 * Updater. The Config carries the plugin-specific identifiers so nothing
 * inside the library is hard-coded to any one plugin.
 *
 * All fields are validated / defaulted in the constructor so callers
 * can rely on the accessors returning strings (never null).
 */

namespace KitMobley\PluginCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {

	/** @var string wp.org-style plugin slug (e.g. "schema-conflict-auditor") */
	private $slug;
	/** @var string Semver — the host plugin's current version */
	private $version;
	/** @var string plugin_basename( __FILE__ ) result — e.g. "schema-conflict-auditor/schema-conflict-auditor.php" */
	private $basename;
	/** @var string i18n text-domain */
	private $text_domain;
	/** @var string License / updates API base — usually "https://kitmobley.com/api" */
	private $api_base;
	/** @var string wp_options row key for storing license state */
	private $option_key;
	/** @var string wp_transient key for the update manifest cache */
	private $transient_key;
	/** @var string prefix for AJAX action names, e.g. "sca" → wp_ajax_sca_license_save */
	private $ajax_prefix;
	/** @var string public product URL used in error messages / upsells */
	private $pro_url;

	/**
	 * @param array{
	 *   slug:string,
	 *   version:string,
	 *   basename:string,
	 *   text_domain?:string,
	 *   api_base?:string,
	 *   option_key:string,
	 *   transient_key?:string,
	 *   ajax_prefix:string,
	 *   pro_url?:string,
	 * } $args
	 */
	public function __construct( array $args ) {
		if ( empty( $args['slug'] ) )        throw new \InvalidArgumentException( 'Config.slug is required' );
		if ( empty( $args['version'] ) )     throw new \InvalidArgumentException( 'Config.version is required' );
		if ( empty( $args['basename'] ) )    throw new \InvalidArgumentException( 'Config.basename is required' );
		if ( empty( $args['option_key'] ) )  throw new \InvalidArgumentException( 'Config.option_key is required' );
		if ( empty( $args['ajax_prefix'] ) ) throw new \InvalidArgumentException( 'Config.ajax_prefix is required' );

		$this->slug          = $args['slug'];
		$this->version       = $args['version'];
		$this->basename      = $args['basename'];
		$this->text_domain   = $args['text_domain']   ?? $args['slug'];
		$this->api_base      = $args['api_base']      ?? 'https://kitmobley.com/api';
		$this->option_key    = $args['option_key'];
		$this->transient_key = $args['transient_key'] ?? ( $args['ajax_prefix'] . '_update_manifest' );
		$this->ajax_prefix   = $args['ajax_prefix'];
		$this->pro_url       = $args['pro_url']       ?? ( 'https://kitmobley.com/plugins/' . $args['slug'] . '/#pricing' );
	}

	public function slug()          { return $this->slug; }
	public function version()       { return $this->version; }
	public function basename()      { return $this->basename; }
	public function text_domain()   { return $this->text_domain; }
	public function api_base()      { return $this->api_base; }
	public function option_key()    { return $this->option_key; }
	public function transient_key() { return $this->transient_key; }
	public function ajax_prefix()   { return $this->ajax_prefix; }
	public function pro_url()       { return $this->pro_url; }
}
