<?php
/**
 * @package Machool
 */
namespace Inc\Base;

use \Inc\Base\BaseController;

class SettingsLinks extends BaseController
{
	public function register() 
	{
		add_filter( "plugin_action_links", array( $this, 'machool_settings_link' ), 10, 2 );
	}

	public function machool_settings_link( $links, $file ) 
	{
		// Only show this for the machool plugin
	    if ( stripos($file, "machool-for-woocommerce") === 0)
	    {
			$settings_link = '<a href="admin.php?page=wc-settings&tab=shipping&section=machool_shipping">Settings</a>';
			array_push( $links, $settings_link );
		}
		return $links;
	}
}