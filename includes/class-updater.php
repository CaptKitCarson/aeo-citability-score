<?php
/**
 * Backward-compat wrapper around KitMobley\PluginCore\Updater.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class AECS_Updater extends \KitMobley\PluginCore\Updater {
	public function __construct() {
		parent::__construct( aecs_plugin_config(), new AECS_License() );
	}
}
