<?php
/**
 * Shared plugin-core configuration for the licence and updater services.
 *
 * Pro only. The WordPress.org build ships neither service, so this file is
 * excluded from it and the free plugin carries no reference to PluginCore.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
			'pro_url'       => AECS_PRO_URL,
		) );
	}
	return $cfg;
}
