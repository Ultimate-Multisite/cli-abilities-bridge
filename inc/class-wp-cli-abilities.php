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
	 * WP-CLI command groups included in essential mode.
	 *
	 * When full mode is disabled (the default), only commands whose top-level
	 * group appears in this list are registered as abilities. This keeps the
	 * tool count manageable for local models (~120 abilities vs 524+).
	 *
	 * Override with the `wp_cli_abilities_essential_groups` filter or set the
	 * `WP_CLI_ABILITIES_FULL_MODE` constant to `true` for all commands.
	 *
	 * @var string[]
	 */
	const ESSENTIAL_GROUPS = [
		'post',
		'option',
		'plugin',
		'theme',
		'user',
		'site',
		'term',
		'comment',
		'media',
		'menu',
		'widget',
		'cron',
		'transient',
		'post-type',
		'taxonomy',
		'role',
		'rewrite',
		'export',
		'import',
	];

	/**
	 * WP-CLI global parameters that appear on every command.
	 *
	 * These are stripped from both the synopsis and longdesc during sync to
	 * avoid repeating ~500 tokens of boilerplate per ability.
	 *
	 * @var string[]
	 */
	const GLOBAL_PARAMETERS = [
		'path',
		'url',
		'ssh',
		'http',
		'user',
		'skip-plugins',
		'skip-themes',
		'skip-packages',
		'require',
		'exec',
		'context',
		'color',
		'no-color',
		'debug',
		'prompt',
		'quiet',
	];

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

		WP_CLI::add_command(
			'abilities sync',
			function ($args, $assoc_args) {

				$root     = WP_CLI::get_root_command();
				$commands = [];

				$this->walk_commands($root, [], $commands);

				WP_CLI_Abilities_Command_Cache::save_commands($commands);

				WP_CLI::success(sprintf('Cached %d WP-CLI commands as abilities.', count($commands)));
			},
			[
				'shortdesc' => 'Sync WP-CLI commands to the Abilities API cache.',
				'longdesc'  => 'Discovers all registered WP-CLI leaf commands and caches their metadata for the Abilities API.',
			]
		);

		WP_CLI::add_command(
			'abilities clear',
			function () {

				WP_CLI_Abilities_Command_Cache::clear();

				WP_CLI::success('Abilities command cache cleared.');
			},
			[
				'shortdesc' => 'Clear the Abilities API command cache.',
			]
		);

		WP_CLI::add_command(
			'abilities list',
			function ($args, $assoc_args) {

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
			},
			[
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
			]
		);
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

			// Strip global parameters from synopsis to save tokens.
			$synopsis = self::strip_global_params($synopsis);

			$shortdesc = '';
			$longdesc  = '';

			if (method_exists($command, 'get_shortdesc')) {
				$shortdesc = $command->get_shortdesc();
			}

			if (method_exists($command, 'get_longdesc')) {
				$longdesc = $command->get_longdesc();
				$longdesc = self::strip_global_params_from_longdesc($longdesc);
			}

			// Enrich synopsis with options from longdesc YAML blocks.
			$synopsis = self::enrich_synopsis_from_longdesc($synopsis, $longdesc);

			$results[ $ability_name ] = [
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
		$sanitized = array_map(
			function (string $segment): string {
				$segment = str_replace('_', '-', $segment);
				return preg_replace('/[^a-z0-9-]/', '', strtolower($segment));
			},
			$path_segments
		);

		// Remove empty segments after sanitization.
		$sanitized = array_values(
			array_filter(
				$sanitized,
				function (string $s): bool {
					return $s !== '';
				}
			)
		);

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
		if (! preg_match('/^[a-z0-9-]+(?:\/[a-z0-9-]+){1,3}$/', $name)) {
			return null;
		}

		return $name;
	}

	/**
	 * Remove global WP-CLI parameters from a synopsis array.
	 *
	 * @param array $synopsis Parsed synopsis entries.
	 * @return array Filtered synopsis without global params.
	 */
	private static function strip_global_params(array $synopsis): array {

		return array_values(
			array_filter($synopsis, function ($param) {
				$name = $param['name'] ?? '';
				return ! in_array($name, self::GLOBAL_PARAMETERS, true);
			})
		);
	}

	/**
	 * Remove the "GLOBAL PARAMETERS" section from a longdesc string.
	 *
	 * WP-CLI appends a standard block starting with "## GLOBAL PARAMETERS"
	 * to every command's longdesc. This strips it to save tokens.
	 *
	 * @param string $longdesc The command's long description.
	 * @return string Trimmed description without global parameters section.
	 */
	private static function strip_global_params_from_longdesc(string $longdesc): string {

		// The section typically starts with "## GLOBAL PARAMETERS" or "GLOBAL PARAMETERS".
		$pos = stripos($longdesc, 'GLOBAL PARAMETERS');

		if ($pos !== false) {
			// Walk back to the start of the heading line (e.g. "## ").
			$line_start = strrpos(substr($longdesc, 0, $pos), "\n");
			$cut_pos    = $line_start !== false ? $line_start : $pos;
			$longdesc   = substr($longdesc, 0, $cut_pos);
		}

		return trim($longdesc);
	}

	/**
	 * Enrich synopsis entries with options extracted from longdesc YAML blocks.
	 *
	 * WP-CLI longdescs contain YAML blocks like:
	 *   [--format=<format>]
	 *   : Render output in a particular format.
	 *   ---
	 *   options:
	 *     - table
	 *     - json
	 *   ---
	 *
	 * SynopsisParser::parse() doesn't extract these, so we parse them from
	 * the longdesc and merge into the synopsis array.
	 *
	 * @param array  $synopsis Synopsis entries from SynopsisParser.
	 * @param string $longdesc The command's long description.
	 * @return array Enriched synopsis with options arrays.
	 */
	private static function enrich_synopsis_from_longdesc(array $synopsis, string $longdesc): array {

		if (empty($longdesc)) {
			return $synopsis;
		}

		// Find all YAML blocks preceded by a param definition.
		// Pattern: [--param=<param>] followed by `: description` lines, then --- yaml ---
		// The description lines start with `:` so we constrain the match to avoid
		// jumping over other param definitions to reach an unrelated YAML block.
		$pattern = '/\[--(\w[\w-]*)=<[^>]+>\]\n(?::\s*[^\n]+\n)*---\s*\n(.*?)\n\s*---/s';

		if (!preg_match_all($pattern, $longdesc, $matches, PREG_SET_ORDER)) {
			return $synopsis;
		}

		$param_options = [];

		foreach ($matches as $match) {
			$param_name = $match[1];
			$yaml_body  = $match[2];

			// Extract options list from the YAML block.
			if (preg_match('/options:\s*\n((?:\s+-\s+.+\n?)+)/i', $yaml_body, $opts_match)) {
				$options = [];

				preg_match_all('/^\s+-\s+[\'"]?([^\'";\n]+)[\'"]?\s*$/m', $opts_match[1], $opt_items);

				foreach ($opt_items[1] as $item) {
					$options[] = trim($item);
				}

				if (!empty($options)) {
					$param_options[$param_name] = $options;
				}
			}
		}

		if (empty($param_options)) {
			return $synopsis;
		}

		// Merge options into matching synopsis entries.
		foreach ($synopsis as &$param) {
			$name = $param['name'] ?? '';

			if (isset($param_options[$name]) && empty($param['options'])) {
				$param['options'] = $param_options[$name];
			}
		}

		unset($param);

		return $synopsis;
	}

	/**
	 * Register the wp-cli ability category.
	 */
	public function register_category(): void {

		if (wp_has_ability_category(self::CATEGORY)) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => 'WP-CLI Commands',
				'description' => 'WordPress CLI commands exposed as abilities. Run `wp abilities sync` to refresh.',
			]
		);
	}

	/**
	 * Register abilities from the cached commands.
	 *
	 * By default only commands in the essential groups list are registered.
	 * Define `WP_CLI_ABILITIES_FULL_MODE` as true to register all commands,
	 * or use the `wp_cli_abilities_essential_groups` filter to customise.
	 */
	public function register_abilities(): void {

		$commands = WP_CLI_Abilities_Command_Cache::get_commands();

		if (empty($commands)) {
			return;
		}

		$full_mode = defined('WP_CLI_ABILITIES_FULL_MODE') && WP_CLI_ABILITIES_FULL_MODE;

		if (! $full_mode) {
			/**
			 * Filter the list of essential WP-CLI command groups.
			 *
			 * @param string[] $groups Top-level command group slugs to include.
			 */
			$essential = apply_filters('wp_cli_abilities_essential_groups', self::ESSENTIAL_GROUPS);

			$commands = array_filter($commands, function ($meta) use ($essential) {
				$group = explode(' ', $meta['path'])[0] ?? '';
				return in_array($group, $essential, true);
			});
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
			'permission_callback' => function () use ($command_path) {
				return WP_CLI_Abilities_Command_Permissions::check($command_path);
			},
			'execute_callback'    => function ($input = null) use ($command_path, $synopsis) {

				$input_array = is_array($input) ? $input : [];
				$input_array = (array) $input_array;

				return WP_CLI_Abilities_Command_Executor::execute(
					$command_path,
					$input_array,
					$synopsis
				);
			},
			'meta'                => [
				'show_in_rest' => true,
				'annotations'  => $annotations,
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
			],
		];

		if (! empty($input_schema)) {
			$args['input_schema'] = $input_schema;
		}

		wp_register_ability($ability_name, $args);
	}
}
