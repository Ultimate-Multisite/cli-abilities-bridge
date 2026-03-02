<?php
/**
 * Plugin Name: CLI Abilities Bridge
 * Plugin URI:  https://ultimatemultisite.com/cli-abilities-bridge
 * Description: Automatically discovers WP-CLI commands and exposes them as abilities via the WordPress Abilities API.
 * Version:     1.0.0
 * Author:      Ultimate Multisite
 * Author URI:  https://ultimatemultisite.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cli-abilities-bridge
 * Network:     true
 * Requires at least: 6.8
 * Tested up to:      6.9
 * Requires PHP:      7.4
 */

defined('ABSPATH') || exit;

define('CLI_ABILITIES_BRIDGE_DIR', plugin_dir_path(__FILE__));

add_action(
	'plugins_loaded',
	function () {

		if (! function_exists('wp_register_ability')) {
			return;
		}

		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-command-cache.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-command-permissions.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-schema-builder.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-command-executor.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-wp-cli-abilities.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-system-executor.php';
		require_once CLI_ABILITIES_BRIDGE_DIR . 'inc/class-system-commands.php';

		WP_CLI_Abilities::get_instance();
		WP_CLI_Abilities_System_Commands::get_instance();
	}
);
