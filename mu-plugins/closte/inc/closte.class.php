<?php

defined("ABSPATH") or exit();

class Closte
{
    public static function instance()
    {
        new self();
    }

    public function __construct()
    {
        add_action(
            "upgrader_process_complete",
            [&$this, "flush_cache_after_wp_update"],
            PHP_INT_MAX,
            2
        );

        //disable updates
        add_filter("pre_site_transient_update_core", [
            $this,
            "remove_core_updates",
        ]);
        add_action("admin_enqueue_scripts", [
            &$this,
            "closte_requirements_enqueue_scripts",
        ]);
        add_filter("auto_core_update_send_email", "__return_false"); // mute core update send email
        add_filter("send_core_update_notification_email", "__return_false"); // mute core update email
        add_filter("cron_schedules", [$this, "closte_cron_schedules"]);

        if (!wp_next_scheduled("closte_10_minutes_cron")) {
            wp_schedule_event(time(), "10min", "closte_10_minutes_cron");
        }
        add_action("closte_10_minutes_cron", [$this, "closte_10min_cron"]);
        add_action("wp_logout", [
            $this,
            "closte_dynamic_ip_whitelist_clear_logout",
        ]);

        //cache plugins
        if (method_exists("LiteSpeed_Cache_API", "hook_control")) {
            LiteSpeed_Cache_API::hook_control([
                &$this,
                "enable_micro_cache_and_304",
            ]);
        }

        //litspeed 3+
        add_action("litespeed_control_finalize", [
            &$this,
            "enable_micro_cache_and_304",
        ]);

        add_action(
            "wpfc_cache_detection_info",
            [&$this, "closte_third_party_is_cacheable_wpfc"],
            PHP_INT_MAX
        );
        add_filter(
            "rocket_buffer",
            [&$this, "closte_third_party_is_cacheable_wp_rocket"],
            10,
            1
        );
        add_filter(
            "w3tc_pagecache_set",
            [&$this, "closte_third_party_is_cacheable_w3tc"],
            10,
            2
        );

        //smtp
        add_action("phpmailer_init", [&$this, "closte_smtp"], PHP_INT_MAX);

        //wp ultimo IP address
        add_filter("wu_get_setting", [&$this, "closte_wu_get_setting"], 10, 3);

        if (
            defined("DOING_AUTOSAVE") && DOING_AUTOSAVE or
            defined("DOING_CRON") && DOING_CRON or
            defined("DOING_AJAX") && DOING_AJAX or
            defined("XMLRPC_REQUEST") && XMLRPC_REQUEST
        ) {
            if (defined("DOING_AJAX") && DOING_AJAX) {
                if ($this::user_can_manage_admin_settings() && is_admin()) {
                    add_action("wp_ajax_closte_create_backup", [
                        "Closte_Ajax",
                        "closte_create_backup",
                    ]);
                    add_action("wp_ajax_closte_get_task_status", [
                        "Closte_Ajax",
                        "closte_get_task_status",
                    ]);
                    add_action("wp_ajax_closte_cdn_invalidation", [
                        "Closte_Ajax",
                        "closte_cdn_invalidation",
                    ]);
                    add_action("wp_ajax_closte_add_domain_alias", [
                        "Closte_Ajax",
                        "closte_add_domain_alias",
                    ]);
                    add_action("wp_ajax_closte_welcome_tour", [
                        "Closte_Ajax",
                        "closte_welcome_tour",
                    ]);
                }
            }

            return;
        }

        if (
            $this::user_can_manage_admin_settings() &&
            (is_admin() || is_network_admin())
        ) {
            Closte_Settings::update();
        }

        if (current_user_can("editor") || current_user_can("administrator")) {
            self::closte_dynamic_ip_whitelist();
        }

        $options = Closte_Options::get_options();

        if (
            $this::user_can_manage_admin_settings() &&
            (is_network_admin() || is_admin())
        ) {
            $menu_type = is_network_admin()
                ? "network_admin_menu"
                : "admin_menu";

            add_action("admin_enqueue_scripts", function () {
                wp_enqueue_style(
                    "closte_css",
                    "/wp-content/mu-plugins/closte/css/closte.css"
                );
            });

            add_action("admin_init", [__CLASS__, "flush_cache_listener"]);

            if (defined("HIDE_CLOSTE_PLUGIN") && HIDE_CLOSTE_PLUGIN) {
            } else {
                add_action(
                    "wp_before_admin_bar_render",
                    [__CLASS__, "closte_bar_menu"],
                    100
                );
                add_action($menu_type, [__CLASS__, "closte_menu"], 100);
            }

            add_action("current_screen", [&$this, "render_tour"]);

            self::admin_notice_development_mode();
        }

        /* admin notices */
        add_action("after_setup_theme", [__CLASS__, "closte_autologin"]);

        add_filter(
            "closte_final_output",
            [&$this, "parse_final_output"],
            PHP_INT_MAX,
            1
        );
    }

    function parse_final_output($output)
    {
        include_once ABSPATH . "wp-admin/includes/plugin.php";

        if (!is_plugin_active("litespeed-cache/litespeed-cache.php")) {
            return apply_filters("closte_cdn_rewrite_final_output", $output);
        }

        if (is_user_logged_in()) {
            return apply_filters("closte_cdn_rewrite_final_output", $output);
        }

        $is_cacheable = self::closte_is_cacheable_header_set(headers_list());

        if (!$is_cacheable) {
            return apply_filters("closte_cdn_rewrite_final_output", $output);
        }

        $is_html = self::checkIsHtml($output);

        if (!$is_html) {
            //cacheable and is HTML

            return apply_filters("closte_cdn_rewrite_final_output", $output);
        }

        $options = Closte_Options::get_options();
        if (
            $options &&
            $options["enable_cdn"] == 1 &&
            CLOSTE_CDN_DISABLED != true
        ) {
            $options = Closte_Options::get_options();
            $excludes = array_map("trim", explode(",", $options["excludes"]));
            $rewriter = new Closte_Rewriter($excludes);
            $output = $rewriter->rewrite($output);
        }

        return apply_filters("closte_cdn_rewrite_final_output", $output);
    }

    function checkIsHtml($buffer)
    {
        if (!$buffer) {
            return false;
        }

        if (
            preg_match("/<html[^\>]*>/si", $buffer) &&
            preg_match("/<body[^\>]*>/si", $buffer)
        ) {
            return true;
        }

        return false;
    }

    function getClientIpAddress()
    {
        if (
            array_key_exists("HTTP_X_FORWARDED_FOR", $_SERVER) &&
            preg_match(
                '/\b((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\.|$)){4}\b/',
                $_SERVER["HTTP_X_FORWARDED_FOR"]
            )
        ) {
            return $_SERVER["HTTP_X_FORWARDED_FOR"];
        } elseif (array_key_exists("REMOTE_ADDR", $_SERVER)) {
            return $_SERVER["REMOTE_ADDR"];
        } elseif (array_key_exists("HTTP_CLIENT_IP", $_SERVER)) {
            return $_SERVER["HTTP_CLIENT_IP"];
        } else {
            return "";
        }
    }

    function closte_is_cacheable_header_set($headers)
    {
        $x_cacheable_header_content = "X-Cacheable";
        $header_content = "";

        foreach ($headers as $header) {
            $splitted = explode(":", $header);

            if ($splitted[0] == $x_cacheable_header_content) {
                $header_content = $splitted[1];
            }
        }

        if (strpos($header_content, " yes") === 0) {
            return true;
        }

        return false;
    }

    function closte_third_party_is_cacheable_wpfc()
    {
        header("X-Cacheable: yes", true);
    }

    function closte_third_party_is_cacheable_wp_rocket($buffer)
    {
        header("X-Cacheable: yes", true);
        return $buffer;
    }

    function closte_third_party_is_cacheable_w3tc($data, $this_page_key)
    {
        header("X-Cacheable: yes", true);
        return $data;
    }

    function closte_wu_get_setting($setting_value, $setting, $default)
    {
        if ($setting == "network_ip" && defined("CLOSTE_PUBLIC_IP_ADDRESS")) {
            return CLOSTE_PUBLIC_IP_ADDRESS;
        }
        return $setting_value;
    }

    public static function user_can_manage_admin_settings()
    {
        $capability = is_network_admin()
            ? "manage_network_options"
            : "manage_options";

        if (current_user_can($capability)) {
            return true;
        }
        return false;
    }

    function flush_cache_after_wp_update($array, $int)
    {
        opcache_reset();
        wp_cache_flush();
        if (function_exists("apcu_clear_cache")) {
            apcu_clear_cache();
        }
    }

    static function closte_bar_menu()
    {
        global $wp_admin_bar;

        if (is_multisite()) {
            if (!is_main_site()) {
                $global_options = Closte_Options::get_options(true);

                if ($global_options["allow_per_site_config"] == 0) {
                    return;
                }
            }
        }

        $label = "Closte";

        if (is_multisite()) {
            if (!is_main_site()) {
                $label = "Hosting";
            }
        }

        $wp_admin_bar->add_menu([
            "id" => "closte_top_bar",
            "title" => __($label, "closte"),
            "href" => "admin.php?page=closte_main_menu",
        ]);

        $flush_url = add_query_arg([
            "closte_flush_cache_action" => "flush_closte_caches",
        ]);
        $nonced_url = wp_nonce_url($flush_url, "closte_top_bar_action");

        $wp_admin_bar->add_menu([
            "id" => "closte_top_bar_flush_opcache",
            "parent" => "closte_top_bar",
            "title" => __("Clear OPcache & Object-Cache", "closte"),
            "href" => $nonced_url,
        ]);

        $wp_admin_bar->add_menu([
            "id" => "closte_top_bar_flush_cdn",
            "parent" => "closte_top_bar",
            "title" => __("Clear CDN cache", "closte"),
            "href" => "admin.php?page=closte_main_menu.cdn-invalidation",
        ]);

        $wp_admin_bar->add_menu([
            "id" => "closte_top_bar_settings",
            "parent" => "closte_top_bar",
            "title" => __("Settings", "closte"),
            "href" => "admin.php?page=closte_main_menu",
        ]);
    }

    static function closte_menu()
    {
        $label = "Closte";
        $add_all_pages = true;
        $settings_page_type = is_network_admin()
            ? "network_settings_page"
            : "settings_page";

        if (is_multisite()) {
            if (!is_main_site()) {
                $label = "Hosting";
                $add_all_pages = false;
                $global_options = Closte_Options::get_options(true);
                $settings_page_type = "subsite_settings_page";

                if ($global_options["allow_per_site_config"] == 0) {
                    return;
                }
            }
        }

        $icon = "dashicons-menu";
        add_menu_page(
            "Closte Cloud Management",
            $label,
            "read",
            "closte_main_menu",
            ["Closte_Settings", $settings_page_type],
            $icon,
            1
        );
        add_submenu_page(
            "closte_main_menu",
            "Settings",
            "Settings",
            "read",
            "closte_main_menu",
            ["Closte_Settings", $settings_page_type]
        );
        add_submenu_page(
            "closte_main_menu",
            "CDN Invalidation",
            "CDN Invalidation",
            "read",
            "closte_main_menu.cdn-invalidation",
            ["Closte_Settings", "cdn_invalidation_page"]
        );

        if ($add_all_pages) {
            add_submenu_page(
                "closte_main_menu",
                "Backups",
                "Backups",
                "read",
                "closte_main_menu.backups",
                ["Closte_Settings", "backups_page"]
            );
            add_submenu_page(
                "closte_main_menu",
                "Domains",
                "Domains",
                "read",
                "closte_main_menu.domains",
                ["Closte_Settings", "domains_page"]
            );
        }
    }

    // Where we handle OPcache flush action
    static function flush_cache_listener()
    {
        if (!isset($_REQUEST["closte_flush_cache_action"])) {
            return;
        }

        $action = sanitize_key($_REQUEST["closte_flush_cache_action"]);
        if ($action == "done_flush_closte_caches") {
            $flush_message_type = "admin_notices";

            if (is_multisite() && is_network_admin()) {
                $flush_message_type = "network_admin_notices";
            }
            add_action($flush_message_type, [
                __CLASS__,
                "admin_notice_flushed_caches",
            ]);
            return;
        }

        if ($action == "flush_closte_caches") {
            check_admin_referer("closte_top_bar_action");
            opcache_reset();
            wp_cache_flush();

            $url = esc_url_raw(remove_query_arg("closte_flush_cache_action"));
            $url = esc_url_raw(remove_query_arg("_wpnonce"));
            $url = esc_url_raw(
                add_query_arg([
                    "closte_flush_cache_action" => "done_flush_closte_caches",
                ])
            );

            wp_redirect($url);
        }
    }

    static function admin_notice_flushed_caches()
    {
        $class = "notice notice-success is-dismissible";
        $message = "OPcache & Object-Cache were successfully flushed.";
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    static function admin_notice_development_mode()
    {
        //options use the old values before the update, check if is save page
        if (
            defined("CLOSTE_DEVELOPMENT_MODE") &&
            (!isset($_POST["submit"]) && !isset($_POST["closte"]))
        ) {
            add_action("admin_notices", [
                __CLASS__,
                "admin_notice_development_mode_text",
            ]);
            if (is_multisite()) {
                add_action("network_admin_notices", [
                    __CLASS__,
                    "admin_notice_development_mode_text",
                ]);
            }
        }
    }

    static function admin_notice_development_mode_text()
    {
        $class = "notice notice-warning";
        $message =
            'Development mode is enabled and many performance & security features are disabled. <a href="' .
            menu_page_url("closte_main_menu", false) .
            '">Disable Development Mode</a>';
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($class),
            $message
        );
    }

    public function remove_core_updates()
    {
        global $wp_version;
        return (object) [
            "last_checked" => time(),
            "updates" => [],
            "version_checked" => $wp_version,
        ];
    }

    function closte_requirements_enqueue_scripts($hook)
    {
        if ("update-core.php" == $hook) {
            wp_enqueue_script(
                "closte_requirements_update_core",
                "/wp-content/mu-plugins/closte/js/update-core.js"
            );
        }
    }

    function closte_cron_schedules($schedules)
    {
        if (!isset($schedules["10min"])) {
            $schedules["10min"] = [
                "interval" => 10 * 60,
                "display" => __("Once every 10 minutes"),
            ];
        }

        return $schedules;
    }

    function closte_dynamic_ip_whitelist()
    {
        $option_key = "closte_dynamic_ip_whitelist";
        $user_ip = self::getClientIpAddress();

        if (empty($user_ip) || $user_ip == "127.0.0.1") {
            return;
        }

        $should_update_htaccess = false;
        $minutes = 60;

        $whitelisted = get_site_option($option_key);
        if (!$whitelisted || !is_array($whitelisted)) {
            $whitelisted = [];
        }

        $now = new DateTime();

        if (array_key_exists($user_ip, $whitelisted)) {
            $valid_until = $whitelisted[$user_ip];

            if ($valid_until < $now) {
                $new_valid_until = new DateTime();
                $valid_until = $new_valid_until->modify("+{$minutes} minutes");
                $whitelisted[$user_ip] = $valid_until;
                update_site_option($option_key, $whitelisted);

                //maybe the IP is removed from htaccess
                $should_update_htaccess = true;
            }
        } else {
            $new_valid_until = new DateTime();
            $valid_until = $new_valid_until->modify("+{$minutes} minutes");
            $whitelisted[$user_ip] = $valid_until;
            update_site_option($option_key, $whitelisted);
            $should_update_htaccess = true;
        }

        $save = false;
        foreach ($whitelisted as $key => $value) {
            if ($value < $now) {
                unset($whitelisted[$key]);
                $should_update_htaccess = true;
                $save = true;
            }
        }

        if ($save == true) {
            update_site_option($option_key, $whitelisted);
        }

        if ($should_update_htaccess == true) {
            self::insert_htaccess_rules();
        }
    }
    function closte_dynamic_ip_whitelist_clear()
    {
        $option_key = "closte_dynamic_ip_whitelist";
        $should_update_htaccess = false;

        $whitelisted = get_site_option($option_key);
        if (!$whitelisted || !is_array($whitelisted)) {
            $whitelisted = [];
        }

        $now = new DateTime();
        $save = false;
        foreach ($whitelisted as $key => $value) {
            if ($value < $now) {
                unset($whitelisted[$key]);
                $should_update_htaccess = true;
                $save = true;
            }
        }

        if ($save == true) {
            update_site_option($option_key, $whitelisted);
        }

        if ($should_update_htaccess == true) {
            self::insert_htaccess_rules();
        }
    }
    function closte_dynamic_ip_whitelist_clear_logout()
    {
        $option_key = "closte_dynamic_ip_whitelist";
        $user_ip = self::getClientIpAddress();

        if (empty($user_ip)) {
            return;
        }

        $whitelisted = get_site_option($option_key);
        if (!$whitelisted || !is_array($whitelisted)) {
            $whitelisted = [];
        }

        if (array_key_exists($user_ip, $whitelisted)) {
            unset($whitelisted[$user_ip]);
            update_site_option($option_key, $whitelisted);
            self::insert_htaccess_rules();
        }
    }

    function closte_10min_cron()
    {
        self::closte_dynamic_ip_whitelist_clear();
    }

    function enable_micro_cache_and_304()
    {
        $options = Closte_Options::get_options();

        if ($options["load_balancer_micro_cache"]) {
            $seconds = $options["load_balancer_micro_cache_seconds"];
            header("Cache-Control: public,s-maxage=" . $seconds, true);
            header("X-Cacheable: yes", true);
            header_remove("pragma");
        } else {
            header("Cache-Control:no-cache, must-revalidate, max-age=0");
            header_remove("pragma");
            header("X-Cacheable: yes", true);
        }
    }

    function closte_smtp(&$phpmailer)
    {
        $options = Closte_Options::get_options();

        if ($options["smtp_configured"] == 0 || $options["smtp_enabled"] == 0) {
            return;
        }

        /* Set the mailer type as per config above, this overrides the already called isMail method */
        $phpmailer->IsSMTP();

        if (
            isset($options["smtp_from_name"]) &&
            !empty($options["smtp_from_name"]) &&
            $options["smtp_force_from_name"] === 1
        ) {
            $from_name = $options["smtp_from_name"];
        } else {
            $from_name = !empty($phpmailer->FromName)
                ? $phpmailer->FromName
                : $options["smtp_from_name"];
        }

        $from_email =
            $options["smtp_from_email"] .
            "@" .
            $options["smtp_configured_domain"];

        if (!empty($options["smtp_reply_to_email"])) {
            $phpmailer->AddReplyTo($options["smtp_reply_to_email"], $from_name);
        }

        $phpmailer->From = $from_email;
        $phpmailer->FromName = $from_name;
        $phpmailer->SetFrom($phpmailer->From, $phpmailer->FromName);

        /* Set the other options */
        $phpmailer->SMTPSecure = "tls";
        $phpmailer->Host = "smtp.mailgun.org";
        $phpmailer->Port = "2525";
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $options["smtp_username"];
        $phpmailer->Password = $options["smtp_password"];
        $phpmailer->SMTPAutoTLS = true;

        if (
            defined("CLOSTE_DEVELOPMENT_MODE") &&
            !empty($options["smtp_dev_rec_add"])
        ) {
            $phpmailer->ClearAllRecipients();
            $phpmailer->addAddress(
                $options["smtp_dev_rec_add"],
                "Staging Environment"
            );
        }
    }

    private static function format_message($mesg, $tag)
    {
        $formatted = sprintf(
            "%s [%s:%s] [%s] %s\n",
            date("r"),
            $_SERVER["REMOTE_ADDR"],
            $_SERVER["REMOTE_PORT"],
            $tag,
            $mesg
        );
        return $formatted;
    }

    public static function SetObjectCache()
    {
        $wordpress_object_cache_file_location =
            ABSPATH . "wp-content/object-cache.php";
        $disable = true;
        $options = Closte_Options::get_options(true);

        if (
            $options["development_mode"] == 0 &&
            $options["enable_object_cache"] == 1
        ) {
            $disable = false;
        }

        if ($disable == true) {
            if (file_exists($wordpress_object_cache_file_location)) {
                unlink($wordpress_object_cache_file_location);
                wp_cache_flush();
                if (function_exists("apcu_clear_cache")) {
                    apcu_clear_cache();
                }
            }
        } else {
            if (!file_exists($wordpress_object_cache_file_location)) {
                $object_cache_file_location =
                    CLOSTE_DIR . "/inc/class-closte-object-cache.php";
                $object_cache_php = file_get_contents(
                    $object_cache_file_location
                );
                file_put_contents(
                    $wordpress_object_cache_file_location,
                    $object_cache_php
                );
                wp_cache_flush();
                if (function_exists("apcu_clear_cache")) {
                    apcu_clear_cache();
                }
            }
        }
    }

    public static function closte_autologin()
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST" && self::Is_Backend_LOGIN()) {
            if (isset($_POST["closte_auto_login_value"])) {
                $local_file = "";

                try {
                    $closte_auto_login_value =
                        $_POST["closte_auto_login_value"];
                    $user_ip = "";

                    if (
                        array_key_exists("HTTP_X_FORWARDED_FOR", $_SERVER) &&
                        preg_match(
                            '/\b((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(\.|$)){4}\b/',
                            $_SERVER["HTTP_X_FORWARDED_FOR"]
                        )
                    ) {
                        $user_ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
                        
                    } elseif (array_key_exists("REMOTE_ADDR", $_SERVER)) {
                        $user_ip = $_SERVER["REMOTE_ADDR"];
                    } elseif (array_key_exists("HTTP_CLIENT_IP", $_SERVER)) {
                        $user_ip = $_SERVER["HTTP_CLIENT_IP"];
                    }

                    if (empty($user_ip)) {
                        return;
                    }

                    $local_file =
                        dirname(constant("ABSPATH")) . "/conf/cal_" . $user_ip;

                    if (
                        file_exists($local_file) &&
                        fileowner($local_file) === 0
                    ) {
                        $file_content = file_get_contents($local_file);
                        $validUntil = date(
                            "F j Y g:i:s A T",
                            self::ticks_to_time(explode(",", $file_content)[0])
                        );
                        $file_value = explode(",", $file_content)[1];
                        $utc_now = date("F j Y g:i:s A T", time() - date("Z"));
                        $wp_user = explode(",", $file_content)[2];
                        $file_ip = explode(",", $file_content)[3];

                        if (
                            $file_value === $closte_auto_login_value &&
                            $validUntil > $utc_now &&
                            $file_ip === $user_ip
                        ) {
                            if (
                                username_exists($wp_user) ||
                                email_exists($wp_user)
                            ) {
                                if (is_user_logged_in()) {
                                    wp_logout();
                                }

                                //get user's ID
                                $user = get_user_by("login", $wp_user);

                                if ($user == false) {
                                    $user = get_user_by("email", $wp_user);
                                }

                                $user_id = $user->ID;
                                //login
                                wp_set_current_user($user_id, $wp_user);
                                wp_set_auth_cookie($user_id);
                                do_action("wp_login", $user->user_login, $user);
                                //redirect to home page after logging in (i.e. don't show content of www.site.com/?p=1234 )
                                wp_redirect(get_admin_url());
                                exit();
                            } else {
                                header("HTTP/1.1 200 OK");
                                header(
                                    "Content-Type: text/html; charset=utf-8"
                                );
                                echo "<strong>ERROR:</strong> User with " .
                                    $wp_user .
                                    ' username or email address does not exist. Go to <a href="https://app.closte.com/sites/settings/' .
                                    CLOSTE_APP_ID .
                                    '">Site->Settings page</a> and change the auto login username.';
                                exit(1);
                            }
                        }
                    }
                } catch (Exception $e) {
                } finally {
                }
            }
        }
    }

    public static function Is_Backend_LOGIN()
    {
        $ABSPATH_MY = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, ABSPATH);

        $included_files = get_included_files();

        return in_array($ABSPATH_MY . "wp-login.php", $included_files) ||
            in_array($ABSPATH_MY . "wp-register.php", $included_files) ||
            $GLOBALS["pagenow"] === "wp-login.php" ||
            $_SERVER["PHP_SELF"] == "/wp-login.php";
    }

    public static function ticks_to_time($ticks)
    {
        return floor(($ticks - 621355968000000000) / 10000000);
    }

    public static function ticks_to_time_with_zone($epoch)
    {
        $d = date("Y-m-d H:i:s", $epoch);
        $local_timestamp = get_date_from_gmt($d, "d M y h:i A");
        return $local_timestamp;
    }

    public static function insert_htaccess_rules()
    {
        $insertion = ["", "## Do not edit the contents of this block! ##"];
        array_push($insertion, "RewriteEngine On");

        $global_options = Closte_Options::get_options(true);

        if (is_multisite() && $global_options["allow_per_site_config"] == 1) {
            //$args = array('limit'=>1000);
            //$sites = array();

            //if(function_exists('get_sites')){
            //    $sites = get_sites($args);
            //}else if(function_exists('wp_get_sites')){

            //    $sites = wp_get_sites($args);
            //}

            //if($sites){

            //    foreach ($sites as $site) {

            //        $blog_id = 0;

            //        if(is_array($site)){
            //            $blog_id = $site['blog_id'];
            //        }else{
            //            $blog_id = $site->blog_id;
            //        }

            //        switch_to_blog($blog_id);
            //        $options = Closte_Options::get_options();

            //        if($options['development_mode'] == 1){

            //            //$domains = self::get_subsite_domains($blog_id);

            //            //foreach ($domains as $domain) {
            //            //     array_push($insertion,
            //            //         'RewriteCond %{HTTP_HOST} ^'.$domain.' [NC]',
            //            //         "RewriteRule .* - [E=noconntimeout:1]"
            //            //           );

            //            //      if($options['development_mode'] == 1){
            //            //    array_push($insertion,
            //            //        'RewriteCond %{HTTP_HOST} ^'.$domain.' [NC]',
            //            //        "RewriteRule .* - [E=Cache-Control:no-cache]"
            //            //                 );
            //            //       }

            //            //}

            //        }

            //        restore_current_blog();
            //    }
            //}
        } elseif ((is_multisite() && is_main_site()) || !is_multisite()) {
            //$options =  Closte_Options::get_options();

            //if($options['development_mode'] == 1){

            //    array_push($insertion,
            //        "SecFilterEngine Off",
            //        "SecFilterScanPOST Off",
            //        "RewriteEngine On",
            //        "RewriteRule .* - [E=noconntimeout:1]"
            //        );

            //    if($options['development_mode'] == 1){
            //        array_push($insertion,
            //     "CacheDisable public /",
            //    "CacheDisable private /"
            //       );
            //    }

            //}
        }

        $whitelisted = get_site_option("closte_dynamic_ip_whitelist");
        if (!$whitelisted || !is_array($whitelisted)) {
            $whitelisted = [];
        }

        foreach ($whitelisted as $key => $value) {
            array_push(
                $insertion,
                "SetEnvIfNoCase Remote_Addr ^" . $key . '$ MODSEC-OFF'
            );
            array_push(
                $insertion,
                "RewriteCond %{REMOTE_ADDR} ^" .
                    str_replace(".", "\.", $key) .
                    '$',
                "RewriteRule .* - [E=noconntimeout:1]"
            );
        }

        if (
            defined("CLOSTE_DEVELOPMENT_MODE") &&
            CLOSTE_DEVELOPMENT_MODE == true
        ) {
            array_push($insertion, "SetEnv MODSEC-OFF");
        }

        array_push($insertion, "");

        $marker = "CLOSTE";
        $filename = "/home/" . CLOSTE_APP_ID . "/public_html/.htaccess";

        if (!file_exists($filename)) {
            if (!is_writable(dirname($filename))) {
                return false;
            }

            try {
                touch($filename);
            } catch (ErrorException $ex) {
                return false;
            }
        } elseif (!is_writeable($filename)) {
            return false;
        }

        if (!is_array($insertion)) {
            $insertion = explode("\n", $insertion);
        }

        $start_marker = "# BEGIN {$marker}";
        $end_marker = "# END {$marker}";

        $fp = fopen($filename, "r+");
        if (!$fp) {
            return false;
        }

        // Attempt to get a lock. If the filesystem supports locking, this will block until the lock is acquired.
        flock($fp, LOCK_EX);

        $lines = [];
        while (!feof($fp)) {
            $lines[] = rtrim(fgets($fp), "\r\n");
        }

        // Split out the existing file into the preceding lines, and those that appear after the marker
        $pre_lines = $post_lines = $existing_lines = [];
        $found_marker = $found_end_marker = false;
        foreach ($lines as $line) {
            if (!$found_marker && false !== strpos($line, $start_marker)) {
                $found_marker = true;
                continue;
            } elseif (
                !$found_end_marker &&
                false !== strpos($line, $end_marker)
            ) {
                $found_end_marker = true;
                continue;
            }

            if (!$found_marker) {
                $pre_lines[] = $line;
            } elseif ($found_marker && $found_end_marker) {
                $post_lines[] = $line;
            } else {
                $existing_lines[] = $line;
            }
        }

        // Check to see if there was a change
        if ($existing_lines === $insertion) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return true;
        }

        // Generate the new file data
        $new_file_data = implode(
            "\n",
            array_merge(
                $pre_lines,
                [$start_marker],
                $insertion,
                [$end_marker],
                $post_lines
            )
        );

        // Write to the start of the file, and truncate it to that length
        fseek($fp, 0);
        $bytes = fwrite($fp, $new_file_data);
        if ($bytes) {
            ftruncate($fp, ftell($fp));
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return (bool) $bytes;
    }

    function render_tour()
    {
        if (self::is_wp_dashboard()) {
            $options = Closte_Options::get_options();
            $should_render_tour = true;

            if (isset($options["welcome_tour"])) {
                if ($options["welcome_tour"] == 1) {
                    $should_render_tour = false;
                }
            }

            if ($should_render_tour) {
                add_action("admin_enqueue_scripts", function () {
                    $script_file =
                        "/wp-content/mu-plugins/closte/js/welcome_tour_pointer.js";

                    if (is_multisite()) {
                        if (!is_main_site()) {
                            $script_file =
                                "/wp-content/mu-plugins/closte/js/subsite_welcome_tour_pointer.js";
                        }
                    }
                    // Add pointers style to queue.
                    wp_enqueue_style("wp-pointer");
                    wp_enqueue_script("wp-pointer");
                    wp_enqueue_script("closte_hopscotch", $script_file);
                });
            }
        }
    }

    public static function is_wp_dashboard()
    {
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen->id == "dashboard") {
                return true;
            }
        }

        return false;
    }

    function get_subsite_domains($blog_id)
    {
        global $wpdb;

        try {
            $default_site_url = get_site_url($blog_id);
            $domains = [];
            array_push($domains, parse_url($default_site_url, PHP_URL_HOST));

            try {
                $query = "SELECT * FROM {$wpdb->base_prefix}domain_mapping WHERE blog_id = {$blog_id}";
                $results = $wpdb->get_results($query, OBJECT);

                if ($results) {
                    foreach ($results as $d) {
                        array_push($domains, $d->domain);
                    }
                }
            } catch (Exception $e) {
            }

            return $domains;
        } catch (Exception $e) {
            return [];
        }
    }
}
