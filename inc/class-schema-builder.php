<?php

defined('ABSPATH') || exit;

/**
 * Converts WP-CLI synopsis arrays into JSON Schema for ability input_schema.
 */
class WP_CLI_Abilities_Schema_Builder {

	/**
	 * Build a JSON Schema input_schema from a WP-CLI synopsis array.
	 *
	 * @param array $synopsis Parsed synopsis entries from WP-CLI.
	 * @return array JSON Schema array.
	 */
	public static function build(array $synopsis): array {

		$properties  = [];
		$required    = [];
		$has_generic = false;

		foreach ($synopsis as $param) {
			$type = $param['type'] ?? '';
			$name = $param['name'] ?? '';

			if (empty($name) && $type !== 'generic') {
				continue;
			}

			switch ($type) {
				case 'positional':
					$prop = [
						'type'        => 'string',
						'description' => $param['description'] ?? "Positional argument: {$name}",
					];

					if (! empty($param['options'])) {
						$prop['enum'] = $param['options'];
					}

					$properties[ $name ] = $prop;

					if (empty($param['optional'])) {
						$required[] = $name;
					}

					break;

				case 'assoc':
					$prop = [
						'type'        => 'string',
						'description' => $param['description'] ?? "Option: --{$name}",
					];

					if (! empty($param['default'])) {
						$prop['default'] = $param['default'];
					}

					if (! empty($param['options'])) {
						$prop['enum'] = $param['options'];
					}

					$properties[ $name ] = $prop;

					if (! empty($param['required']) || (isset($param['optional']) && ! $param['optional'])) {
						$required[] = $name;
					}

					break;

				case 'flag':
					$properties[ $name ] = [
						'type'        => 'boolean',
						'description' => $param['description'] ?? "Flag: --{$name}",
						'default'     => false,
					];

					break;

				case 'generic':
					$has_generic = true;
					break;
			}
		}

		if (empty($properties) && ! $has_generic) {
			return [];
		}

		$schema = [
			'type'       => 'object',
			'properties' => $properties,
		];

		if (! empty($required)) {
			$schema['required'] = $required;
		}

		$schema['additionalProperties'] = $has_generic;

		return $schema;
	}
}
