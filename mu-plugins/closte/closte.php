<?php
/*
Plugin Name: Closte
Plugin URI: https://closte.com
Description: High performance one click implementation of object cache and Google Cloud CDN for Closte cloud platform users.
Version: 2.1.2
Author: Closte LLC
Author URI: https://closte.com
*/

defined('ABSPATH') OR exit;


/* constants */

define('CLOSTE_DIR', dirname(__FILE__));
define('CLOSTE_BASE', plugin_basename(__FILE__));
define('CLOSTE_MIN_WP', '4.0');


/* loader */
add_action(
	'plugins_loaded',
	array(
		'Closte',
		'instance'
	)
);





/* autoload init */
spl_autoload_register('Closte_autoload');

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    // Register CLI cmd
	if ( method_exists( 'WP_CLI', 'add_command' ) ) {
		WP_CLI::add_command( 'closte', 'Closte_WP_CLI' ) ;
		
	}
}


 

/* autoload funktion */
function Closte_autoload($class) {
	if ( in_array($class, array('Closte', 'Closte_Rewriter', 'Closte_Settings' ,'Closte_Api' ,'Closte_Ajax','Closte_WP_CLI','Closte_Options')) ) {
		require_once(
			sprintf(
				'%s/inc/%s.class.php',
				CLOSTE_DIR,
				strtolower($class)
			)
		);
	}
   

}