<?php

defined('ABSPATH') || exit;

/**
 * Registers system CLI commands as abilities.
 *
 * Unlike WP-CLI abilities, system commands are predefined (no discovery/sync step)
 * and registered statically on every wp_abilities_api_init.
 */
class WP_CLI_Abilities_System_Commands {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'system';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton instance.
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
		add_action('wp_abilities_api_init', [$this, 'register_abilities']);
	}

	/**
	 * Register the system ability category.
	 */
	public function register_category(): void {

		if (wp_has_ability_category(self::CATEGORY)) {
			return;
		}

		wp_register_ability_category(self::CATEGORY, [
			'label'       => 'System CLI Commands',
			'description' => 'General-purpose Linux CLI commands exposed as abilities.',
		]);
	}

	/**
	 * Register all system command abilities.
	 */
	public function register_abilities(): void {

		$commands = self::get_command_definitions();

		foreach ($commands as $name => $def) {
			$this->register_command($name, $def);
		}
	}

	/**
	 * Register a single system command as an ability.
	 *
	 * @param string $name Ability name suffix (e.g. 'whois').
	 * @param array  $def  Command definition.
	 */
	private function register_command(string $name, array $def): void {

		$ability_name = self::CATEGORY . '/' . $name;
		$access_level = $def['access_level'] ?? WP_CLI_Abilities_Command_Permissions::LEVEL_READ;
		$annotations  = WP_CLI_Abilities_Command_Permissions::get_annotations_for_level($access_level);
		$timeout      = $def['timeout'] ?? WP_CLI_Abilities_System_Executor::DEFAULT_TIMEOUT;

		$args = [
			'label'               => $def['label'],
			'description'         => $def['description'],
			'category'            => self::CATEGORY,
			'permission_callback' => function() use ($access_level) {
				return WP_CLI_Abilities_Command_Permissions::check_level($access_level);
			},
			'execute_callback'    => function($input = null) use ($def, $timeout) {

				$input = is_array($input) ? $input : [];

				return $this->execute_command($def, $input, $timeout);
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

		if (!empty($def['input_schema'])) {
			$args['input_schema'] = $def['input_schema'];
		}

		wp_register_ability($ability_name, $args);
	}

	/**
	 * Execute a system command from its definition.
	 *
	 * @param array $def     Command definition.
	 * @param array $input   Input from ability invocation.
	 * @param int   $timeout Timeout in seconds.
	 * @return string|\WP_Error
	 */
	private function execute_command(array $def, array $input, int $timeout) {

		$binary    = $def['binary'];
		$build_fn  = $def['build_args'];
		$built     = $build_fn($input);

		// build_args returns [args, stdin] or just args.
		if (is_array($built) && array_key_exists('args', $built)) {
			$args  = $built['args'];
			$stdin = $built['stdin'] ?? null;
		} else {
			$args  = $built;
			$stdin = null;
		}

		// Special: curl SSRF validation.
		if ($binary === 'curl' && !empty($input['url'])) {
			$url_check = WP_CLI_Abilities_System_Executor::validate_curl_url($input['url']);

			if (is_wp_error($url_check)) {
				return $url_check;
			}
		}

		return WP_CLI_Abilities_System_Executor::execute($binary, $args, $stdin, $timeout);
	}

	/**
	 * Get all system command definitions.
	 *
	 * @return array<string, array>
	 */
	public static function get_command_definitions(): array {

		return array_merge(
			self::network_commands(),
			self::http_commands(),
			self::datetime_commands(),
			self::sysinfo_commands(),
			self::text_commands(),
			self::crypto_commands(),
			self::email_commands(),
			self::admin_commands(),
			self::exec_commands(),
			self::archive_commands(),
			self::math_commands()
		);
	}

	/**
	 * Network/DNS commands (read).
	 */
	private static function network_commands(): array {

		return [
			'whois' => [
				'binary'       => 'whois',
				'label'        => 'WHOIS domain lookup',
				'description'  => 'Query WHOIS information for a domain name, showing registrar, nameservers, and expiry dates.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'domain' => [
							'type'        => 'string',
							'description' => 'Domain name to look up (e.g. example.com)',
						],
					],
					'required' => ['domain'],
				],
				'build_args' => function(array $input): array {
					return [$input['domain']];
				},
			],

			'dig' => [
				'binary'       => 'dig',
				'label'        => 'DNS lookup',
				'description'  => 'Perform DNS lookups for a domain, querying specific record types like A, MX, NS, TXT, etc.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'domain' => [
							'type'        => 'string',
							'description' => 'Domain name to query',
						],
						'type' => [
							'type'        => 'string',
							'enum'        => ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'ANY'],
							'description' => 'DNS record type to query',
						],
					],
					'required' => ['domain'],
				],
				'build_args' => function(array $input): array {
					$args = [$input['domain']];

					if (!empty($input['type'])) {
						$args[] = $input['type'];
					}

					return $args;
				},
			],

			'nslookup' => [
				'binary'       => 'nslookup',
				'label'        => 'Name server lookup',
				'description'  => 'Query a DNS name server for information about a host. Optionally specify which DNS server to query.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'host' => [
							'type'        => 'string',
							'description' => 'Hostname or IP to look up',
						],
						'server' => [
							'type'        => 'string',
							'description' => 'DNS server to query (optional)',
						],
					],
					'required' => ['host'],
				],
				'build_args' => function(array $input): array {
					$args = [$input['host']];

					if (!empty($input['server'])) {
						$args[] = $input['server'];
					}

					return $args;
				},
			],

			'host' => [
				'binary'       => 'host',
				'label'        => 'DNS lookup (simple)',
				'description'  => 'Simple DNS lookup utility. Returns IP addresses for a hostname or hostname for an IP.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'hostname' => [
							'type'        => 'string',
							'description' => 'Hostname or IP to look up',
						],
						'type' => [
							'type'        => 'string',
							'enum'        => ['A', 'AAAA', 'MX', 'NS', 'TXT'],
							'description' => 'Record type to query',
						],
					],
					'required' => ['hostname'],
				],
				'build_args' => function(array $input): array {
					$args = [];

					if (!empty($input['type'])) {
						$args[] = '-t';
						$args[] = $input['type'];
					}

					$args[] = $input['hostname'];

					return $args;
				},
			],

			'ping' => [
				'binary'       => 'ping',
				'label'        => 'Ping host',
				'description'  => 'Send ICMP echo requests to a host. Limited to a configurable number of pings (default 4).',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 30,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'host' => [
							'type'        => 'string',
							'description' => 'Hostname or IP to ping',
						],
						'count' => [
							'type'        => 'string',
							'description' => 'Number of pings to send (default: 4)',
							'default'     => '4',
						],
					],
					'required' => ['host'],
				],
				'build_args' => function(array $input): array {
					$count = $input['count'] ?? '4';

					// Limit count to prevent abuse.
					$count = max(1, min((int) $count, 10));

					return ['-c', (string) $count, $input['host']];
				},
			],

			'tracepath' => [
				'binary'       => 'tracepath',
				'label'        => 'Trace network path',
				'description'  => 'Trace the network path to a host, showing each hop and latency.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 60,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'host' => [
							'type'        => 'string',
							'description' => 'Hostname or IP to trace',
						],
					],
					'required' => ['host'],
				],
				'build_args' => function(array $input): array {
					return [$input['host']];
				},
			],
		];
	}

	/**
	 * HTTP commands (write).
	 */
	private static function http_commands(): array {

		return [
			'curl' => [
				'binary'       => 'curl',
				'label'        => 'HTTP request',
				'description'  => 'Make HTTP requests to URLs. Supports GET, HEAD, POST, PUT, DELETE methods with custom headers and data. SSRF protection blocks internal IPs and dangerous schemes.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_WRITE,
				'timeout'      => 30,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => 'URL to request',
						],
						'method' => [
							'type'        => 'string',
							'enum'        => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'],
							'description' => 'HTTP method (default: GET)',
						],
						'headers' => [
							'type'        => 'string',
							'description' => 'HTTP headers, one per line (e.g. "Content-Type: application/json")',
						],
						'data' => [
							'type'        => 'string',
							'description' => 'Request body data',
						],
						'output_headers' => [
							'type'        => 'boolean',
							'description' => 'Include response headers in output',
						],
					],
					'required' => ['url'],
				],
				'build_args' => function(array $input): array {
					$args = ['-sS', '--max-time', '25', '-L'];

					if (!empty($input['output_headers'])) {
						$args[] = '-i';
					}

					if (!empty($input['method'])) {
						$args[] = '-X';
						$args[] = strtoupper($input['method']);
					}

					if (!empty($input['headers'])) {
						$headers = preg_split('/\r?\n/', $input['headers']);

						foreach ($headers as $header) {
							$header = trim($header);
							if ($header !== '') {
								$args[] = '-H';
								$args[] = $header;
							}
						}
					}

					if (!empty($input['data'])) {
						$args[] = '-d';
						$args[] = $input['data'];
					}

					$args[] = $input['url'];

					return $args;
				},
			],
		];
	}

	/**
	 * Date/Time commands (read).
	 */
	private static function datetime_commands(): array {

		return [
			'date' => [
				'binary'       => 'date',
				'label'        => 'Current date/time',
				'description'  => 'Display the current date and time. Supports custom format strings and date calculations.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'format' => [
							'type'        => 'string',
							'description' => 'Output format string (e.g. "+%Y-%m-%d %H:%M:%S")',
						],
						'date' => [
							'type'        => 'string',
							'description' => 'Date string to parse (e.g. "next friday", "2024-01-01")',
						],
						'utc' => [
							'type'        => 'boolean',
							'description' => 'Use UTC timezone',
						],
					],
				],
				'build_args' => function(array $input): array {
					$args = [];

					if (!empty($input['utc'])) {
						$args[] = '-u';
					}

					if (!empty($input['date'])) {
						$args[] = '-d';
						$args[] = $input['date'];
					}

					if (!empty($input['format'])) {
						$args[] = $input['format'];
					}

					return $args;
				},
			],

			'cal' => [
				'binary'       => 'cal',
				'label'        => 'Calendar display',
				'description'  => 'Display a calendar for a given month and/or year.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'month' => [
							'type'        => 'string',
							'description' => 'Month number (1-12)',
						],
						'year' => [
							'type'        => 'string',
							'description' => 'Year (e.g. 2026)',
						],
					],
				],
				'build_args' => function(array $input): array {
					$args = [];

					if (!empty($input['month'])) {
						$args[] = $input['month'];
					}

					if (!empty($input['year'])) {
						$args[] = $input['year'];
					}

					return $args;
				},
			],
		];
	}

	/**
	 * System info commands (read).
	 */
	private static function sysinfo_commands(): array {

		return [
			'uptime' => [
				'binary'       => 'uptime',
				'label'        => 'System uptime',
				'description'  => 'Show how long the system has been running, the number of users, and load averages.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'build_args' => function(array $input): array {
					return [];
				},
			],

			'free' => [
				'binary'       => 'free',
				'label'        => 'Memory usage',
				'description'  => 'Display the amount of free and used memory in the system.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'human' => [
							'type'        => 'boolean',
							'description' => 'Human-readable output (default: true)',
							'default'     => true,
						],
					],
				],
				'build_args' => function(array $input): array {
					$human = $input['human'] ?? true;
					return $human ? ['-h'] : [];
				},
			],

			'df' => [
				'binary'       => 'df',
				'label'        => 'Disk usage',
				'description'  => 'Report file system disk space usage.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'human' => [
							'type'        => 'boolean',
							'description' => 'Human-readable output (default: true)',
							'default'     => true,
						],
						'path' => [
							'type'        => 'string',
							'description' => 'Path to check (optional)',
						],
					],
				],
				'build_args' => function(array $input): array {
					$args  = [];
					$human = $input['human'] ?? true;

					if ($human) {
						$args[] = '-h';
					}

					if (!empty($input['path'])) {
						$args[] = $input['path'];
					}

					return $args;
				},
			],

			'du' => [
				'binary'       => 'du',
				'label'        => 'Directory size',
				'description'  => 'Estimate file space usage for a directory.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 30,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'path' => [
							'type'        => 'string',
							'description' => 'Directory path to measure',
						],
						'human' => [
							'type'        => 'boolean',
							'description' => 'Human-readable output (default: true)',
							'default'     => true,
						],
						'max_depth' => [
							'type'        => 'string',
							'description' => 'Maximum depth of directories to display (default: 1)',
							'default'     => '1',
						],
					],
					'required' => ['path'],
				],
				'build_args' => function(array $input): array {
					$args  = [];
					$human = $input['human'] ?? true;
					$depth = $input['max_depth'] ?? '1';

					if ($human) {
						$args[] = '-h';
					}

					$args[] = '--max-depth=' . max(0, min((int) $depth, 5));
					$args[] = $input['path'];

					return $args;
				},
			],

			'uname' => [
				'binary'       => 'uname',
				'label'        => 'System information',
				'description'  => 'Print system information (kernel name, hostname, version, architecture, etc.).',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'all' => [
							'type'        => 'boolean',
							'description' => 'Print all system information (default: true)',
							'default'     => true,
						],
					],
				],
				'build_args' => function(array $input): array {
					$all = $input['all'] ?? true;
					return $all ? ['-a'] : [];
				},
			],

			'hostname' => [
				'binary'       => 'hostname',
				'label'        => 'System hostname',
				'description'  => 'Display the system hostname.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'build_args' => function(array $input): array {
					return [];
				},
			],
		];
	}

	/**
	 * Text/Data processing commands (read).
	 */
	private static function text_commands(): array {

		return [
			'jq' => [
				'binary'       => 'jq',
				'label'        => 'JSON processor',
				'description'  => 'Process and transform JSON data using jq filter expressions. Input is piped via stdin.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 10,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'filter' => [
							'type'        => 'string',
							'description' => 'jq filter expression (e.g. ".name", ".[] | select(.active)")',
						],
						'input' => [
							'type'        => 'string',
							'description' => 'JSON string to process',
						],
						'raw_output' => [
							'type'        => 'boolean',
							'description' => 'Output raw strings without JSON quotes',
						],
					],
					'required' => ['filter', 'input'],
				],
				'build_args' => function(array $input): array {
					$args = [];

					if (!empty($input['raw_output'])) {
						$args[] = '-r';
					}

					$args[] = $input['filter'];

					return [
						'args'  => $args,
						'stdin' => $input['input'],
					];
				},
			],

			'base64' => [
				'binary'       => 'base64',
				'label'        => 'Base64 encode/decode',
				'description'  => 'Encode or decode data using Base64 encoding. Input is piped via stdin.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'input' => [
							'type'        => 'string',
							'description' => 'String to encode or decode',
						],
						'decode' => [
							'type'        => 'boolean',
							'description' => 'Decode instead of encode (default: false)',
							'default'     => false,
						],
					],
					'required' => ['input'],
				],
				'build_args' => function(array $input): array {
					$args = [];

					if (!empty($input['decode'])) {
						$args[] = '-d';
					}

					return [
						'args'  => $args,
						'stdin' => $input['input'],
					];
				},
			],

			'md5sum' => [
				'binary'       => 'md5sum',
				'label'        => 'MD5 hash',
				'description'  => 'Compute the MD5 hash of input text. Input is piped via stdin.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'input' => [
							'type'        => 'string',
							'description' => 'Text to hash',
						],
					],
					'required' => ['input'],
				],
				'build_args' => function(array $input): array {
					return [
						'args'  => [],
						'stdin' => $input['input'],
					];
				},
			],

			'sha256sum' => [
				'binary'       => 'sha256sum',
				'label'        => 'SHA-256 hash',
				'description'  => 'Compute the SHA-256 hash of input text. Input is piped via stdin.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'input' => [
							'type'        => 'string',
							'description' => 'Text to hash',
						],
					],
					'required' => ['input'],
				],
				'build_args' => function(array $input): array {
					return [
						'args'  => [],
						'stdin' => $input['input'],
					];
				},
			],
		];
	}

	/**
	 * Crypto/SSL commands (read).
	 */
	private static function crypto_commands(): array {

		return [
			'openssl' => [
				'binary'       => 'openssl',
				'label'        => 'OpenSSL operations',
				'description'  => 'Perform OpenSSL operations. Limited to safe read-only subcommands: s_client (check SSL certs), x509 (parse certificates), rand (generate random data), dgst (compute digests), enc (encode/decode).',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'subcommand' => [
							'type'        => 'string',
							'enum'        => ['s_client', 'x509', 'rand', 'dgst', 'enc'],
							'description' => 'OpenSSL subcommand',
						],
						'args' => [
							'type'        => 'string',
							'description' => 'Additional arguments as a single string (will be split on spaces)',
						],
					],
					'required' => ['subcommand', 'args'],
				],
				'build_args' => function(array $input): array {
					$allowed = ['s_client', 'x509', 'rand', 'dgst', 'enc'];
					$sub     = $input['subcommand'];

					if (!in_array($sub, $allowed, true)) {
						return [];
					}

					$args = [$sub];

					if (!empty($input['args'])) {
						// Split args string safely.
						$parts = preg_split('/\s+/', trim($input['args']));

						foreach ($parts as $part) {
							if ($part !== '') {
								$args[] = $part;
							}
						}
					}

					return $args;
				},
			],
		];
	}

	/**
	 * Email commands (write).
	 */
	private static function email_commands(): array {

		return [
			'mail' => [
				'binary'       => 'mail',
				'label'        => 'Send email',
				'description'  => 'Send an email using the system mail command.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_WRITE,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'to' => [
							'type'        => 'string',
							'description' => 'Recipient email address',
						],
						'subject' => [
							'type'        => 'string',
							'description' => 'Email subject line',
						],
						'body' => [
							'type'        => 'string',
							'description' => 'Email body text',
						],
					],
					'required' => ['to', 'subject', 'body'],
				],
				'build_args' => function(array $input): array {
					return [
						'args'  => ['-s', $input['subject'], $input['to']],
						'stdin' => $input['body'],
					];
				},
			],
		];
	}

	/**
	 * System admin commands (read).
	 */
	private static function admin_commands(): array {

		return [
			'journalctl' => [
				'binary'       => 'journalctl',
				'label'        => 'System logs',
				'description'  => 'Query the systemd journal for log entries. Filter by unit, priority, time range, or grep pattern.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'unit' => [
							'type'        => 'string',
							'description' => 'Systemd unit name to filter (e.g. "nginx", "php-fpm")',
						],
						'lines' => [
							'type'        => 'string',
							'description' => 'Number of lines to show (default: 50)',
							'default'     => '50',
						],
						'since' => [
							'type'        => 'string',
							'description' => 'Show entries since this time (e.g. "1 hour ago", "today")',
						],
						'priority' => [
							'type'        => 'string',
							'description' => 'Filter by priority (e.g. "err", "warning")',
						],
						'grep' => [
							'type'        => 'string',
							'description' => 'Filter output by grep pattern',
						],
						'no_pager' => [
							'type'        => 'boolean',
							'description' => 'Disable pager (default: true)',
							'default'     => true,
						],
					],
				],
				'build_args' => function(array $input): array {
					$args = [];

					$no_pager = $input['no_pager'] ?? true;

					if ($no_pager) {
						$args[] = '--no-pager';
					}

					if (!empty($input['unit'])) {
						$args[] = '-u';
						$args[] = $input['unit'];
					}

					$lines = $input['lines'] ?? '50';
					$lines = max(1, min((int) $lines, 500));
					$args[] = '-n';
					$args[] = (string) $lines;

					if (!empty($input['since'])) {
						$args[] = '--since';
						$args[] = $input['since'];
					}

					if (!empty($input['priority'])) {
						$args[] = '-p';
						$args[] = $input['priority'];
					}

					if (!empty($input['grep'])) {
						$args[] = '--grep';
						$args[] = $input['grep'];
					}

					return $args;
				},
			],

			'systemctl-status' => [
				'binary'       => 'systemctl',
				'label'        => 'Service status',
				'description'  => 'Check the status of a systemd service. Read-only — only the "status" subcommand is used.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 10,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'service' => [
							'type'        => 'string',
							'description' => 'Service name (e.g. "nginx", "mariadb", "php8.2-fpm")',
						],
					],
					'required' => ['service'],
				],
				'build_args' => function(array $input): array {
					return ['status', '--no-pager', $input['service']];
				},
			],

			'ss' => [
				'binary'       => 'ss',
				'label'        => 'Socket statistics',
				'description'  => 'Display socket statistics — active connections, listening ports, TCP/UDP info.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 10,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'listening' => [
							'type'        => 'boolean',
							'description' => 'Show only listening sockets (default: true)',
							'default'     => true,
						],
						'tcp' => [
							'type'        => 'boolean',
							'description' => 'Show TCP sockets',
						],
						'udp' => [
							'type'        => 'boolean',
							'description' => 'Show UDP sockets',
						],
						'processes' => [
							'type'        => 'boolean',
							'description' => 'Show process using each socket',
						],
					],
				],
				'build_args' => function(array $input): array {
					$args      = [];
					$listening = $input['listening'] ?? true;

					if ($listening) {
						$args[] = '-l';
					}

					if (!empty($input['tcp'])) {
						$args[] = '-t';
					}

					if (!empty($input['udp'])) {
						$args[] = '-u';
					}

					if (!empty($input['processes'])) {
						$args[] = '-p';
					}

					return $args;
				},
			],
		];
	}

	/**
	 * Execution commands (write — require manage_network).
	 */
	private static function exec_commands(): array {

		return [
			'php' => [
				'binary'       => 'php',
				'label'        => 'Execute PHP code',
				'description'  => 'Execute arbitrary PHP code via `php -r`. Requires super admin (manage_network) capability.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_DESTRUCTIVE,
				'timeout'      => 30,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'code' => [
							'type'        => 'string',
							'description' => 'PHP code to execute (without <?php tags)',
						],
					],
					'required' => ['code'],
				],
				'build_args' => function(array $input): array {
					return ['-r', $input['code']];
				},
			],

			'bash' => [
				'binary'       => 'bash',
				'label'        => 'Execute bash script',
				'description'  => 'Execute a bash script via `bash -c`. Supports full bash syntax including loops, pipes, conditionals, etc. Requires super admin (manage_network) capability.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_DESTRUCTIVE,
				'timeout'      => 60,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'script' => [
							'type'        => 'string',
							'description' => 'Bash script to execute',
						],
					],
					'required' => ['script'],
				],
				'build_args' => function(array $input): array {
					return ['-c', $input['script']];
				},
			],
		];
	}

	/**
	 * Archive commands (read for listing).
	 */
	private static function archive_commands(): array {

		return [
			'tar-list' => [
				'binary'       => 'tar',
				'label'        => 'List tar archive contents',
				'description'  => 'List the contents of a tar archive (supports .tar, .tar.gz, .tar.bz2, .tar.xz).',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'file' => [
							'type'        => 'string',
							'description' => 'Path to the tar archive file',
						],
					],
					'required' => ['file'],
				],
				'build_args' => function(array $input): array {
					return ['-tf', $input['file']];
				},
			],

			'zip-list' => [
				'binary'       => 'unzip',
				'label'        => 'List zip archive contents',
				'description'  => 'List the contents of a zip archive.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 15,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'file' => [
							'type'        => 'string',
							'description' => 'Path to the zip archive file',
						],
					],
					'required' => ['file'],
				],
				'build_args' => function(array $input): array {
					return ['-l', $input['file']];
				},
			],
		];
	}

	/**
	 * Math commands (read).
	 */
	private static function math_commands(): array {

		return [
			'expr' => [
				'binary'       => 'expr',
				'label'        => 'Evaluate math expression',
				'description'  => 'Evaluate a mathematical or string expression using expr. Supports basic arithmetic (+, -, *, /, %), comparisons, and string operations.',
				'access_level' => WP_CLI_Abilities_Command_Permissions::LEVEL_READ,
				'timeout'      => 5,
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'expression' => [
							'type'        => 'string',
							'description' => 'Expression to evaluate (e.g. "2 + 3", "10 \\* 5"). Note: * must be escaped as \\*.',
						],
					],
					'required' => ['expression'],
				],
				'build_args' => function(array $input): array {
					// Split expression into tokens for expr.
					return preg_split('/\s+/', trim($input['expression']));
				},
			],
		];
	}
}
