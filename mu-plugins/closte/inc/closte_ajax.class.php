<?php
defined('ABSPATH') OR exit;
/**
 * closte_ajax short summary.
 *
 * closte_ajax description.
 *
 * @version 1.0
 * @author BatoPC
 */
class Closte_Ajax
{

    public static function closte_create_backup(){

        $result = array('success'  => false);
        $task = Closte_Api::ManualBackup();
       

        
        if(!is_null($task)){
            $result['success'] = true;
            $result['taskid'] = $task;
        }

        wp_send_json($result); 
    }

    public static function closte_get_task_status(){


        $id = $_POST['closte_task_id'];

        $result = array('success'  => false);
        $task = Closte_Api::GetTaskStatus($id);
        
        if(!is_null($task)){
            $result['success'] = true;
            $result['task'] = $task;
        }

        wp_send_json($result);       
    }


    public static function closte_cdn_invalidation(){


        

        $result = array('success'  => false);
        $task = Closte_Api::FlushGoogleCdn();
        
        if(!is_null($task)){
            $result['success'] = true;
            $result['taskid'] = $task;
        }

        wp_send_json($result);       
    }
    
     public static function closte_add_domain_alias(){

        $domain = $_POST['domain'];
        $result = Closte_Api::AddDomainAlias($domain); 
        wp_send_json($result);       
    }


    public static function closte_welcome_tour(){
        
        $options = Closte_Options::get_options();
        $options['welcome_tour'] = 1;
        Closte_Options::save_options($options);       
        $result = array('success'  => true);
        wp_send_json($result);   
    }
}