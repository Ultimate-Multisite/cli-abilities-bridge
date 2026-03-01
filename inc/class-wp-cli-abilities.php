<?php

defined('ABSPATH') || exit;

/**
 * Main plugin class. Handles CLI-side command discovery and web-side ability registration.
 */
class WP_CLI_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'wp-cli';

	/**
	 * Internal WP-CLI dispatcher methods that get exposed as false subcommands
	 * when a command class extends CompositeCommand or Subcommand.
	 *
	 * @var string[]
	 */
	const INTERNAL_METHODS = [
		'add_subcommand',
		'can_have_subcommands',
		'find_subcommand',
		'get_alias',
		'get_hook',
		'get_longdesc',
		'get_name',
		'get_parent',
		'get_shortdesc',
		'get_subcommands',
		'get_synopsis',
		'get_usage',
		'invoke',
		'remove_subcommand',
		'set_longdesc',
		'set_shortdesc',
		'show_usage',
	];

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {

		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Registers hooks for both CLI and web contexts.
	 */
	private function __construct() {

		if (defined('WP_CLI') && WP_CLI) {
			$this->register_cli_commands();
		}

		// Web path: register abilities from cache.
		add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
		add_action('wp_abilities_api_init', [$this, 'register_abilities']);
	}

	/**
	 * Register WP-CLI commands for syncing.
	 */
	private function register_cli_commands(): void {

		WP_CLI::add_command('abilities sync', function($args, $assoc_args) {

			$root    = WP_CLI::get_root_command();
			$commands = [];

			$this->walk_commands($root, [], $commands);

			WP_CLI_Abilities_Command_Cache::save_commands($commands);

			WP_CLI::success(sprintf('Cached %d WP-CLI commands as abilities.', count($commands)));
		}, [
			'shortdesc' => 'Sync WP-CLI commands to the Abilities API cache.',
			'longdesc'  => 'Discovers all registered WP-CLI leaf commands and caches their metadata for the Abilities API.',
		]);

		WP_CLI::add_command('abilities clear', function() {

			WP_CLI_Abilities_Command_Cache::clear();

			WP_CLI::success('Abilities command cache cleared.');
		}, [
			'shortdesc' => 'Clear the Abilities API command cache.',
		]);

		WP_CLI::add_command('abilities list', function($args, $assoc_args) {

			$commands = WP_CLI_Abilities_Command_Cache::get_commands();

			if (empty($commands)) {
				WP_CLI::warning('No cached commands. Run `wp abilities sync` first.');
				return;
			}

			$items = [];

			foreach ($commands as $ability_name => $meta) {
				$items[] = [
					'ability'   => $ability_name,
					'command'   => 'wp ' . $meta['path'],
					'access'    => WP_CLI_Abilities_Command_Permissions::classify($meta['path']),
					'shortdesc' => $meta['shortdesc'],
				];
			}

			$format = $assoc_args['format'] ?? 'table';

			WP_CLI\Utils\format_items($format, $items, ['ability', 'command', 'access', 'shortdesc']);
		}, [
			'shortdesc' => 'List cached WP-CLI abilities.',
			'synopsis'  => [
				[
					'type'     => 'assoc',
					'name'     => 'format',
					'optional' => true,
					'default'  => 'table',
					'options'  => ['table', 'json', 'csv', 'yaml', 'count'],
				],
			],
		]);
	}

	/**
	 * Recursively walk the WP-CLI command tree collecting leaf commands.
	 *
	 * @param object $command     Current command node.
	 * @param array  $parent_path Path segments of parent commands.
	 * @param array  &$results    Collected command metadata.
	 */
	private function walk_commands(object $command, array $parent_path, array &$results): void {

		$subcommands = $command->get_subcommands();

		if (empty($subcommands)) {
			// This is a leaf command. Collect its metadata.
			$path_str = implode(' ', $parent_path);

			if (WP_CLI_Abilities_Command_Cache::is_blocked($path_str)) {
				return;
			}

			// Skip internal WP-CLI dispatcher methods exposed as false subcommands.
			$leaf_name = end($parent_path);

			if (in_array($leaf_name, self::INTERNAL_METHODS, true)) {
				return;
			}

			// Build the ability name: wp-cli/{top-level}/{rest-joined-with-dashes}
			// This keeps it within the 2-4 segment limit.
			$ability_name = self::build_ability_name($parent_path);

			if ($ability_name === null) {
				return;
			}

			// Get synopsis.
			$synopsis = [];

			if (method_exists($command, 'get_synopsis')) {
				$raw_synopsis = $command->get_synopsis();

				if (is_string($raw_synopsis)) {
					$synopsis = \WP_CLI\SynopsisParser::parse($raw_synopsis);
				} elseif (is_array($raw_synopsis)) {
					$synopsis = $raw_synopsis;
				}
			}

			$shortdesc = '';
			$longdesc  = '';

			if (method_exists($command, 'get_shortdesc')) {
				$shortdesc = $command->get_shortdesc();
			}

			if (method_exists($command, 'get_longdesc')) {
				$longdesc = $command->get_longdesc();
			}

			$results[$ability_name] = [
				'path'      => $path_str,
				'shortdesc' => $shortdesc,
				'longdesc'  => $longdesc,
				'synopsis'  => $synopsis,
			];

			return;
		}

		foreach ($subcommands as $name => $subcommand) {
			$this->walk_commands($subcommand, array_merge($parent_path, [$name]), $results);
		}
	}

	/**
	 * Build a valid ability name from command path segments.
	 *
	 * Ability names must be 2-4 segments separated by `/`, using only [a-z0-9-].
	 * Strategy: wp-cli/{segment1}/{segment2}/{remaining-joined-with-dashes}
	 *
	 * @param array $path_segments Command path segments (e.g. ['post', 'list']).
	 * @return string|null The ability name or null if invalid.
	 */
	private static function build_ability_name(array $path_segments): ?string {

		if (empty($path_segments)) {
			return null;
		}

		// Sanitize each segment: replace underscores with dashes, strip invalid chars.
		$sanitized = array_map(function(string $segment): string {
			$segment = str_replace('_', '-', $segment);
			return preg_replace('/[^a-z0-9-]/', '', strtolower($segment));
		}, $path_segments);

		// Remove empty segments after sanitization.
		$sanitized = array_values(array_filter($sanitized, function(string $s): bool {
			return $s !== '';
		}));

		if (empty($sanitized)) {
			return null;
		}

		// We have the category "wp-cli" as segment 1, leaving 1-3 more segments.
		// If path has >3 segments, join the excess into the last segment with dashes.
		if (count($sanitized) > 3) {
			$first_two = array_slice($sanitized, 0, 2);
			$rest      = array_slice($sanitized, 2);
			$sanitized = array_merge($first_two, [implode('-', $rest)]);
		}

		$name = self::CATEGORY . '/' . implode('/', $sanitized);

		// Final validation.
		if (!preg_match('/^[a-z0-9-]+(?:\/[a-z0-9-]+){1,3}$/', $name)) {
			return null;
		}

		return $name;
	}

	/**
	 * Register the wp-cli ability category.
	 */
	public function register_category(): void {

		if (wp_has_ability_category(self::CATEGORY)) {
			return;
		}

		wp_register_ability_category(self::CATEGORY, [
			'label'       => 'WP-CLI Commands',
			'description' => 'WordPress CLI commands exposed as abilities. Run `wp abilities sync` to refresh.',
		]);
	}

	/**
	 * Register abilities from the cached commands.
	 */
	public function register_abilities(): void {

		$commands = WP_CLI_Abilities_Command_Cache::get_commands();

		if (empty($commands)) {
			return;
		}

		foreach ($commands as $ability_name => $meta) {
			$this->register_single_ability($ability_name, $meta);
		}
	}

	/**
	 * Register a single ability from cached command metadata.
	 *
	 * @param string $ability_name The ability name (e.g. "wp-cli/post-list").
	 * @param array  $meta         Cached command metadata.
	 */
	private function register_single_ability(string $ability_name, array $meta): void {

		$synopsis     = $meta['synopsis'] ?? [];
		$input_schema = WP_CLI_Abilities_Schema_Builder::build($synopsis);
		$command_path = $meta['path'];
		$annotations  = WP_CLI_Abilities_Command_Permissions::get_annotations($command_path);

		$args = [
			'label'               => $meta['shortdesc'] ?: "wp {$command_path}",
			'description'         => $meta['longdesc'] ?: $meta['shortdesc'] ?: "Execute: wp {$command_path}",
			'category'            => self::CATEGORY,
			'permission_callback' => function() use ($command_path) {
				return WP_CLI_Abilities_Command_Permissions::check($command_path);
			},
			'execute_callback'    => function($input = null) use ($command_path, $synopsis) {

				$input_array = is_array($input) ? $input : [];
				$input_array = (array) $input_array;

				return WP_CLI_Abilities_Command_Executor::execute(
					$command_path,
					$input_array,
					$synopsis
				);
			},
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => $annotations,
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
			],
		];

		if (!empty($input_schema)) {
			$args['input_schema'] = $input_schema;
		}

		wp_register_ability($ability_name, $args);
	}
}
