<?php

defined('ABSPATH') || exit;

/**
 * Executes WP-CLI commands via proc_open with proper escaping.
 */
class WP_CLI_Abilities_Command_Executor {

	/**
	 * Execute a WP-CLI command.
	 *
	 * @param string $command_path Space-separated command path (e.g. "post list").
	 * @param array  $input        Input arguments from the ability invocation.
	 * @param array  $synopsis     The command's synopsis for type awareness.
	 * @return array|string|\WP_Error Parsed JSON array, raw string output, or WP_Error.
	 */
	public static function execute(string $command_path, array $input, array $synopsis = []) {

		$wp_binary = self::find_wp_cli();

		if (is_wp_error($wp_binary)) {
			return $wp_binary;
		}

		$cmd_parts = [escapeshellarg($wp_binary)];

		// Add the command path segments.
		foreach (explode(' ', $command_path) as $segment) {
			$cmd_parts[] = escapeshellarg($segment);
		}

		// Build a lookup of synopsis param types by name.
		$param_types = [];

		foreach ($synopsis as $param) {

			$name = $param['name'] ?? '';
			$type = $param['type'] ?? '';

			if (!empty($name)) {
				$param_types[$name] = $type;
			}
		}

		// Add input arguments.
		foreach ($input as $key => $value) {

			$synopsis_type = $param_types[$key] ?? 'assoc';

			if ($synopsis_type === 'positional') {
				// Positional args go without a key prefix.
				$cmd_parts[] = escapeshellarg((string) $value);
			} elseif ($synopsis_type === 'flag') {
				// Flags are only added when truthy.
				if ($value) {
					$cmd_parts[] = escapeshellarg("--{$key}");
				}
			} else {
				// Assoc and generic args use --key=value.
				$cmd_parts[] = escapeshellarg("--{$key}") . '=' . escapeshellarg((string) $value);
			}
		}

		// Add --format=json if the command supports it.
		if (self::supports_format($synopsis) && !isset($input['format'])) {
			$cmd_parts[] = '--format=json';
		}

		// Add multisite context.
		$cmd_parts[] = '--path=' . escapeshellarg(ABSPATH);

		if (is_multisite()) {
			$cmd_parts[] = '--url=' . escapeshellarg(network_site_url());
		}

		// Pass the current user so WP-CLI commands that check permissions
		// (e.g. WooCommerce REST-based CLI) run as the authenticated user.
		$current_user_id = get_current_user_id();

		if ($current_user_id > 0) {
			$cmd_parts[] = '--user=' . escapeshellarg((string) $current_user_id);
		}

		// Ensure non-interactive.
		$cmd_parts[] = '--no-color';

		$command = implode(' ', $cmd_parts);

		return self::run($command);
	}

	/**
	 * Find the WP-CLI binary path.
	 *
	 * @return string|\WP_Error Path to wp-cli or WP_Error if not found.
	 */
	private static function find_wp_cli() {

		/**
		 * Filter the WP-CLI binary path.
		 *
		 * @param string $path Path to the WP-CLI binary.
		 */
		$path = apply_filters('wp_cli_abilities_wp_binary', '');

		if (!empty($path) && is_executable($path)) {
			return $path;
		}

		// Check common locations.
		$candidates = [
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			ABSPATH . 'wp-cli.phar',
			getenv('HOME') . '/.local/bin/wp',
		];

		foreach ($candidates as $candidate) {
			if (file_exists($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		// Try which.
		$which = trim((string) shell_exec('which wp 2>/dev/null'));

		if (!empty($which) && is_executable($which)) {
			return $which;
		}

		return new \WP_Error(
			'wp_cli_not_found',
			'WP-CLI binary not found. Install WP-CLI or set the path via the wp_cli_abilities_wp_binary filter.'
		);
	}

	/**
	 * Check if the command supports --format based on its synopsis.
	 *
	 * @param array $synopsis Parsed synopsis.
	 * @return bool
	 */
	private static function supports_format(array $synopsis): bool {

		foreach ($synopsis as $param) {
			if (($param['name'] ?? '') === 'format' && ($param['type'] ?? '') === 'assoc') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run a shell command via proc_open.
	 *
	 * @param string $command The full shell command.
	 * @return array|string|\WP_Error
	 */
	private static function run(string $command) {

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- proc_open is essential: this plugin's core purpose is executing WP-CLI commands via process pipes.
		$process = proc_open($command, $descriptors, $pipes, ABSPATH);

		if (!is_resource($process)) {
			return new \WP_Error('proc_open_failed', 'Failed to execute WP-CLI command.');
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing proc_open() process pipes, not filesystem file handles. WP_Filesystem is not applicable.

		// Close stdin immediately.
		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		// phpcs:enable

		$exit_code = proc_close($process);

		if ($exit_code !== 0) {
			return new \WP_Error(
				'wp_cli_error',
				!empty($stderr) ? trim($stderr) : "WP-CLI exited with code {$exit_code}",
				[
					'exit_code' => $exit_code,
					'stderr'    => $stderr,
					'stdout'    => $stdout,
				]
			);
		}

		// Try to parse as JSON.
		$decoded = json_decode($stdout, true);

		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return trim($stdout);
	}
}
