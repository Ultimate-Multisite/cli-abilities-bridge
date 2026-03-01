<?php

defined('ABSPATH') || exit;

/**
 * Role-based permission system for WP-CLI abilities.
 *
 * Commands are classified into three access levels:
 *   - read:        Safe, read-only commands (list, get, status, exists, etc.)
 *   - write:       Commands that create or modify data (create, update, add, set, etc.)
 *   - destructive: Commands that delete data or alter critical state (delete, drop, reset, etc.)
 *
 * Each access level maps to a WordPress capability:
 *   - read        → wp_cli_read        (or manage_options as fallback)
 *   - write       → wp_cli_write       (or manage_options as fallback)
 *   - destructive → wp_cli_destructive  (or manage_network as fallback)
 *
 * Super admins (manage_network) always have full access to all levels.
 */
class WP_CLI_Abilities_Command_Permissions {

	/**
	 * Access level constants.
	 */
	const LEVEL_READ        = 'read';
	const LEVEL_WRITE       = 'write';
	const LEVEL_DESTRUCTIVE = 'destructive';

	/**
	 * Leaf command names that indicate read-only operations.
	 *
	 * @var string[]
	 */
	const READ_ACTIONS = [
		'list',
		'get',
		'status',
		'exists',
		'is-active',
		'is-installed',
		'count',
		'check-password',
		'check-update',
		'path',
		'search',
		'version',
		'type',
		'pluck',
		'supports',
		'verify',
		'info',
		'describe',
		'diff',
		'logs',
		'next',
		'structure',
		'providers',
		'is-active',
		'data-store',
		'runner',
		'source',
		'snapshot',
		'compatibility-info',
	];

	/**
	 * Leaf command names that indicate destructive operations.
	 *
	 * @var string[]
	 */
	const DESTRUCTIVE_ACTIONS = [
		'delete',
		'drop',
		'reset',
		'destroy',
		'flush',
		'flush-group',
		'clean',
		'remove',
		'uninstall',
		'empty',
		'spam',
		'archive',
		'deactivate',
		'disable',
		'abort-regeneration',
	];

	/**
	 * Default capability map: access_level => capability.
	 *
	 * @var array<string, string>
	 */
	const DEFAULT_CAPABILITY_MAP = [
		self::LEVEL_READ        => 'manage_options',
		self::LEVEL_WRITE       => 'manage_options',
		self::LEVEL_DESTRUCTIVE => 'manage_network',
	];

	/**
	 * Classify a command's access level based on its leaf action.
	 *
	 * @param string $command_path Space-separated command path (e.g. "post list").
	 * @return string One of the LEVEL_* constants.
	 */
	public static function classify(string $command_path): string {

		$parts = explode(' ', $command_path);
		$leaf  = end($parts);

		if (in_array($leaf, self::READ_ACTIONS, true)) {
			return self::LEVEL_READ;
		}

		if (in_array($leaf, self::DESTRUCTIVE_ACTIONS, true)) {
			return self::LEVEL_DESTRUCTIVE;
		}

		// Everything else is a write operation (create, update, add, set, activate,
		// enable, import, export, generate, regenerate, run, etc.)
		return self::LEVEL_WRITE;
	}

	/**
	 * Get the capability map (access level → required capability).
	 *
	 * @return array<string, string>
	 */
	public static function get_capability_map(): array {

		/**
		 * Filter the WP-CLI abilities capability map.
		 *
		 * Allows overriding which WordPress capability is required for each
		 * access level. Keys are 'read', 'write', 'destructive'.
		 *
		 * Example — restrict all write operations to super admins:
		 *
		 *     add_filter('wp_cli_abilities_capability_map', function($map) {
		 *         $map['write'] = 'manage_network';
		 *         return $map;
		 *     });
		 *
		 * @param array<string, string> $map Access level to capability mapping.
		 */
		return apply_filters('wp_cli_abilities_capability_map', self::DEFAULT_CAPABILITY_MAP);
	}

	/**
	 * Check if the current user has permission to execute a command.
	 *
	 * @param string $command_path Space-separated command path.
	 * @return bool|WP_Error True if allowed, WP_Error if denied.
	 */
	public static function check(string $command_path) {

		// Super admins always have full access.
		if (current_user_can('manage_network')) {
			return true;
		}

		$level          = self::classify($command_path);
		$capability_map = self::get_capability_map();
		$required_cap   = $capability_map[$level] ?? 'manage_network';

		if (current_user_can($required_cap)) {
			return true;
		}

		return new WP_Error(
			'wp_cli_abilities_forbidden',
			sprintf(
				'You do not have permission to execute this %s command. Required capability: %s.',
				$level,
				$required_cap
			),
			['status' => 403]
		);
	}

	/**
	 * Check if the current user has permission for a given access level.
	 *
	 * Unlike check() which classifies from a command path, this accepts
	 * an explicit access level. Used by system commands which have
	 * predefined access levels.
	 *
	 * @param string $level One of the LEVEL_* constants.
	 * @return bool|WP_Error True if allowed, WP_Error if denied.
	 */
	public static function check_level(string $level) {

		// Super admins always have full access.
		if (current_user_can('manage_network')) {
			return true;
		}

		$capability_map = self::get_capability_map();
		$required_cap   = $capability_map[$level] ?? 'manage_network';

		if (current_user_can($required_cap)) {
			return true;
		}

		return new WP_Error(
			'wp_cli_abilities_forbidden',
			sprintf(
				'You do not have permission to execute this %s command. Required capability: %s.',
				$level,
				$required_cap
			),
			['status' => 403]
		);
	}

	/**
	 * Get the MCP annotation hints for a command based on its access level.
	 *
	 * @param string $command_path Space-separated command path.
	 * @return array Annotations array for the ability meta.
	 */
	public static function get_annotations(string $command_path): array {

		$level = self::classify($command_path);

		return self::get_annotations_for_level($level);
	}

	/**
	 * Get the MCP annotation hints for an explicit access level.
	 *
	 * @param string $level One of the LEVEL_* constants.
	 * @return array Annotations array for the ability meta.
	 */
	public static function get_annotations_for_level(string $level): array {

		switch ($level) {
			case self::LEVEL_READ:
				return [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				];

			case self::LEVEL_WRITE:
				return [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				];

			case self::LEVEL_DESTRUCTIVE:
				return [
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				];
		}

		return [];
	}
}
