<?php

defined('ABSPATH') OR exit;


if (!defined( 'WP_CLI' ) || !WP_CLI ) return;

/**
 * closte_wp_cli short summary.
 *
 * closte_wp_cli description.
 *
 * @version 1.0
 * @author Closte
 */





class Closte_WP_CLI {
    
    /**
     * Get HalfElf Stats
     *
     * ## EXAMPLES
     *
     * wp halfelf stats
     *
     */
    public function devmode($args) {

        $state = $args[0];

        if($state =='enable'){           

            $options = Closte_Options::get_options();

            $options['development_mode'] = 1;
            Closte_Options::save_options($options);
           

            Closte::SetObjectCache(true);
         

            Closte::insert_htaccess_rules();

            WP_CLI::success( __( 'Development mode is enabled.', 'closte' ) );
            
        }else  if($state =='disable'){
            

            $options = Closte_Options::get_options();

            $options['development_mode'] = 0;
            Closte_Options::save_options($options);
           

            Closte::SetObjectCache(true);
         

            Closte::insert_htaccess_rules();
            WP_CLI::success( __( 'Development mode is disabled.', 'closte' ) );
        }else{
            WP_CLI::error( __( 'Wrong argument', 'closte' ) ); 
        }

       
    }


    public function install_smtp($args,$assoc_args) {


        $username = $assoc_args['username'];
        $password = $assoc_args['password'];
        $from_email = $assoc_args['from_email'];       

        $options = Closte_Options::get_options();  
        $options['smtp_configured'] = '1';
        $options['smtp_enabled'] = '1';
        $options['smtp_configured_domain'] =explode('@',$username)[1];
        $options['smtp_username'] = $username;
        $options['smtp_password'] = $password;
        $options['smtp_from_email'] = $from_email;       

   
        Closte_Options::save_options($options);
        WP_CLI::success( __( 'SMTP is configured.', 'closte' ) );
        
    }

   public function objectcache($args,$assoc_args) {

   
        $options = Closte_Options::get_options();
        $options['enable_object_cache'] = '0';
        Closte_Options::save_options($options);
        WP_CLI::success( __( 'Object-Cache is disabled.', 'closte' ) );
        
    }
   
   
}