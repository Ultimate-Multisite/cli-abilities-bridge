<?php

defined('ABSPATH') || exit;

/**
 * Manages the cached WP-CLI command metadata stored as a network option.
 */
class WP_CLI_Abilities_Command_Cache {

	/**
	 * Network option key for the command cache.
	 */
	const OPTION_KEY = 'wp_cli_abilities_commands';

	/**
	 * Network option key for the blocklist.
	 */
	const BLOCKLIST_OPTION_KEY = 'wp_cli_abilities_blocklist';

	/**
	 * Default top-level command prefixes to block entirely.
	 *
	 * These namespaces are too dangerous or irrelevant to expose as abilities.
	 *
	 * @var string[]
	 */
	const DEFAULT_BLOCKLIST = [
		'db',            // Database drop, export, import, query — too dangerous.
		'server',        // Starts a local dev server — irrelevant for abilities.
		'shell',         // Interactive PHP shell — cannot work non-interactively.
		'cli',           // Meta commands about WP-CLI itself.
		'config',        // wp-config.php manipulation — dangerous.
		'core',          // WordPress core install, update, download — infrastructure.
		'package',       // WP-CLI package management — irrelevant.
		'abilities',     // Our own sync/clear commands — meta.
		'eval',          // Arbitrary PHP execution.
		'eval-file',     // Arbitrary PHP file execution.
		'search-replace', // Mass database string replacement — very dangerous.
		'scaffold',      // Generates files on disk — not useful via abilities.
	];

	/**
	 * Default sub-command paths to block.
	 *
	 * These are specific dangerous operations within otherwise safe namespaces.
	 * Each entry is a full space-separated command path (e.g. "site empty").
	 *
	 * @var string[]
	 */
	const DEFAULT_SUBCOMMAND_BLOCKLIST = [
		// Site destruction.
		'site empty',           // Empties all content from a site.
		'site generate',        // Mass-generates sites — resource intensive.

		// Plugin/theme filesystem operations.
		'plugin install',       // Installs arbitrary plugins from any source.
		'plugin uninstall',     // Removes plugin files from disk.
		'theme install',        // Installs arbitrary themes from any source.

		// User security.
		'super-admin add',      // Grants super admin — extreme privilege escalation.
		'super-admin remove',   // Revokes super admin.
		'user application-password create', // Creates app passwords — credential generation.

		// Role/capability manipulation.
		'cap add',              // Adds capabilities to roles.
		'cap remove',           // Removes capabilities from roles.
		'role delete',          // Deletes roles entirely.
		'role reset',           // Resets roles to defaults — destroys customizations.

		// Maintenance.
		'maintenance-mode activate', // Takes the site offline.

		// WooCommerce infrastructure.
		'wc update',            // Runs WC database migrations.
		'wc com connect',       // WooCommerce.com account linking.
		'wc com disconnect',    // WooCommerce.com account unlinking.
		'wc com extension install', // Installs WC marketplace extensions.

		// Data generators (mass-create junk data).
		'post generate',
		'comment generate',
		'term generate',
		'user generate',
	];

	/**
	 * Get all cached commands.
	 *
	 * @return array<string, array{path: string, shortdesc: string, longdesc: string, synopsis: array}>
	 */
	public static function get_commands(): array {

		$commands = get_network_option(get_main_network_id(), self::OPTION_KEY, []);

		return is_array($commands) ? $commands : [];
	}

	/**
	 * Save commands to the cache.
	 *
	 * @param array $commands Command metadata array keyed by ability name.
	 */
	public static function save_commands(array $commands): void {

		update_network_option(get_main_network_id(), self::OPTION_KEY, $commands);
	}

	/**
	 * Clear the command cache.
	 */
	public static function clear(): void {

		delete_network_option(get_main_network_id(), self::OPTION_KEY);
	}

	/**
	 * Get the blocklist of top-level command prefixes.
	 *
	 * @return string[]
	 */
	public static function get_blocklist(): array {

		$blocklist = get_network_option(get_main_network_id(), self::BLOCKLIST_OPTION_KEY, null);

		if ($blocklist === null) {
			$blocklist = self::DEFAULT_BLOCKLIST;
		}

		/**
		 * Filter the WP-CLI top-level command blocklist.
		 *
		 * @param string[] $blocklist Array of top-level command names to block entirely.
		 */
		return apply_filters('wp_cli_abilities_blocklist', $blocklist);
	}

	/**
	 * Get the sub-command level blocklist.
	 *
	 * @return string[]
	 */
	public static function get_subcommand_blocklist(): array {

		/**
		 * Filter the WP-CLI sub-command blocklist.
		 *
		 * Each entry is a space-separated command path (e.g. "site empty").
		 * Commands matching these paths exactly are blocked.
		 *
		 * @param string[] $blocklist Array of command paths to block.
		 */
		return apply_filters('wp_cli_abilities_subcommand_blocklist', self::DEFAULT_SUBCOMMAND_BLOCKLIST);
	}

	/**
	 * Check if a command path is blocked (top-level or sub-command).
	 *
	 * @param string $command_path Space-separated command path (e.g. "site empty").
	 * @return bool
	 */
	public static function is_blocked(string $command_path): bool {

		// Check top-level blocklist.
		$parts     = explode(' ', $command_path);
		$top_level = $parts[0] ?? '';
		$blocklist = self::get_blocklist();

		if (in_array($top_level, $blocklist, true)) {
			return true;
		}

		// Check sub-command blocklist (exact match).
		$subcommand_blocklist = self::get_subcommand_blocklist();

		if (in_array($command_path, $subcommand_blocklist, true)) {
			return true;
		}

		// Check prefix match for sub-commands (e.g. "user application-password create"
		// should match "user application-password" blocklist entry too).
		foreach ($subcommand_blocklist as $blocked_path) {
			if (strpos($command_path, $blocked_path . ' ') === 0 || $command_path === $blocked_path) {
				return true;
			}
		}

		return false;
	}
}
