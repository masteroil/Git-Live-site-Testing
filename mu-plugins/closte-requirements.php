<?php
header('X-Cacheable: no',true);

defined('ABSPATH') OR exit;

if(defined('DISABLE_CLOSTE_PLUGIN') && DISABLE_CLOSTE_PLUGIN){
    ini_set('opcache.enable', '0');
    return;
}

if(!defined("CLOSTE_APP_ID")){
    return;
}

if(!defined("LITESPEED_DISABLE_OBJECT")){
    define("LITESPEED_DISABLE_OBJECT",true);
}



require WPMU_PLUGIN_DIR.'/closte/closte.php';
//Closte::SetObjectCache();

$options =  Closte_Options::get_options();



if($options) {


    if(!defined("CLOSTE_DEVELOPMENT_MODE_TYPE")){
        define("CLOSTE_DEVELOPMENT_MODE_TYPE",$options["development_mode"]);
    }
    

    
    
    //development mode
    if(CLOSTE_DEVELOPMENT_MODE_TYPE == 1){
        
        if(!defined("CLOSTE_DEVELOPMENT_MODE")){
            define("CLOSTE_DEVELOPMENT_MODE",true);
        }     

    }

    

    
    if($options['enable_opcache'] == 1 && !defined("CLOSTE_DEVELOPMENT_MODE")){

    }else{
        ini_set('opcache.enable', '0');
    }
    


}




function closte_filter_output($final) {
    return apply_filters('closte_final_output', $final);
}


if(!defined("CLOSTE_DEVELOPMENT_MODE") && $options && $options["enable_cdn"] == 1){       
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    
    if (is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) {
        ob_start("closte_filter_output");
    }
    
}