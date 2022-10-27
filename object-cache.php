<?php
defined('ABSPATH') or exit;

$use_object_cache = true;

if (defined("DISABLE_CLOSTE_PLUGIN") && DISABLE_CLOSTE_PLUGIN)
{
    $use_object_cache = false;
}
else if (defined("DISABLE_OBJECT_CACHE_PERMANENTLY") && DISABLE_OBJECT_CACHE_PERMANENTLY)
{
    $use_object_cache = false;
}
else if (defined('WP_CLI') && WP_CLI)
{
    $use_object_cache = false;
}
else if (!defined("CLOSTE_APP_ID"))
{
    $use_object_cache = false;
}
else if (!function_exists('apcu_fetch'))
{
    $use_object_cache = false;
}
else if (version_compare(PHP_VERSION, '7.1.0') < 0)
{
    $use_object_cache = false;
}
else if (php_sapi_name() == "cli")
{
    $use_object_cache = false;
}

if ($use_object_cache == true){
    require_once( WP_CONTENT_DIR . '/mu-plugins/closte/inc/object-cache.php' );
}