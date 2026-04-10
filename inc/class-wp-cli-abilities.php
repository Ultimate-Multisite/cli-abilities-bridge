<?php

defined('ABSPATH') || exit;

/**
 * Registers a single WP-CLI ability that accepts raw command strings.
 *
 * Instead of discovering every WP-CLI command and registering hundreds of
 * individual abilities (expensive in tokens for AI agents), this exposes
 * one `wp-cli/execute` ability. Agents pass commands exactly as they would
 * type them in a terminal — the most natural interface for any LLM that
 * already understands bash.
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
	 * Constructor. Registers hooks.
	 */
	private function __construct() {

		add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
		add_action('wp_abilities_api_init', [$this, 'register_ability']);
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
				'label'       => 'WP-CLI',
				'description' => 'Execute WP-CLI commands on this WordPress installation.',
			]
		);
	}

	/**
	 * Register the single wp-cli/execute ability.
	 */
	public function register_ability(): void {

		$description = implode("\n", [
			'Execute any WP-CLI command and return the output.',
			'Pass commands exactly as you would type them in a terminal, without the "wp" prefix.',
			'',
			'Examples:',
			'  post list --post_type=page --format=json',
			'  option get blogname',
			'  plugin list --status=active --format=json',
			'  user list --role=administrator --format=json',
			'  term list category --format=json',
			'  site list --format=json',
			'  post create --post_title="Hello World" --post_status=publish',
			'  option update blogdescription "My new tagline"',
			'',
			'Tips:',
			'- Use --format=json for structured data when the command supports it.',
			'- For multisite, add --url=<site-url> to target a specific site.',
			'- Commands that modify data require write permissions.',
			'- Some dangerous commands are blocked: db, eval, shell, config, core, search-replace, scaffold.',
		]);

		wp_register_ability(
			self::CATEGORY . '/execute',
			[
				'label'               => 'Execute WP-CLI Command',
				'description'         => $description,
				'category'            => self::CATEGORY,
				'permission_callback' => function () {
					// Gate on manage_options as the minimum capability.
					// Per-command destructive checks happen in the executor.
					if (current_user_can('manage_network')) {
						return true;
					}

					if (current_user_can('manage_options')) {
						return true;
					}

					return new \WP_Error(
						'wp_cli_abilities_forbidden',
						'You do not have permission to execute WP-CLI commands. Required capability: manage_options.',
						['status' => 403]
					);
				},
				'execute_callback'    => function ($input = null) {

					$command = '';

					if (is_array($input)) {
						$command = $input['command'] ?? '';
					} elseif (is_string($input)) {
						$command = $input;
					}

					return WP_CLI_Abilities_Command_Executor::execute($command);
				},
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'command' => [
							'type'        => 'string',
							'description' => 'The WP-CLI command to execute, without the "wp" prefix. Example: "post list --post_type=page --format=json"',
						],
					],
					'required'             => ['command'],
					'additionalProperties' => false,
				],
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'title'       => 'WP-CLI',
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
						'open_world'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}
}
