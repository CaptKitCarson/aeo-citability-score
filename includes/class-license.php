<?php
/**
 * Backward-compat wrapper around KitMobley\PluginCore\License.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_License extends \KitMobley\PluginCore\License {
	public function __construct() {
		parent::__construct( aecs_plugin_config() );
	}
}
