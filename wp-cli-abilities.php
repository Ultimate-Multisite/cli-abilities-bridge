<?php
/**
 * Plugin Name: WP-CLI Abilities Bridge
 * Description: Automatically discovers WP-CLI commands and exposes them as abilities via the WordPress Abilities API.
 * Version: 1.0.0
 * Author: Ultimate Multisite
 * Network: true
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('WP_CLI_ABILITIES_DIR', plugin_dir_path(__FILE__));

add_action(
	'plugins_loaded',
	function () {

		if (! function_exists('wp_register_ability')) {
			return;
		}

		require_once WP_CLI_ABILITIES_DIR . 'inc/class-command-cache.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-command-permissions.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-schema-builder.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-command-executor.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-wp-cli-abilities.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-system-executor.php';
		require_once WP_CLI_ABILITIES_DIR . 'inc/class-system-commands.php';

		WP_CLI_Abilities::get_instance();
		WP_CLI_Abilities_System_Commands::get_instance();
	}
);
