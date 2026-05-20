<?php
/**
 * Enchilada Framework - INI Configuration Reader
 *
 * Type-safe configuration reader for INI files with section support.
 *
 * @package    Enchilada\Config
 * @author     Daniel Morante
 * @copyright  2024-2026 The Daniel Morante Company, Inc.
 * @license    BSD-3-Clause
 */

namespace Enchilada\Config;

/**
 * IniConfig - Type-safe INI file configuration reader.
 *
 * Provides typed access to INI configuration values with validation,
 * default values, and helpful error messages.
 *
 * Example usage:
 * ```php
 * $config = IniConfig::load('config/settings.ini');
 * $host = $config->getString('database', 'host', 'localhost');
 * $port = $config->getInt('database', 'port', 3306, min: 1, max: 65535);
 * $debug = $config->getBool('app', 'debug', false);
 * ```
 *
 * INI file format:
 * ```ini
 * [database]
 * host = localhost
 * port = 3306
 *
 * [app]
 * debug = true
 * ```
 */
class IniConfig
{
    /**
     * Path to the loaded INI file.
     *
     * @var string
     */
    private string $filename;

    /**
     * Parsed INI data as section => [key => value].
     *
     * @var array<string,mixed>
     */
    private array $data;

    /**
     * Private constructor - use load() factory method.
     *
     * @param string $filename Path to the INI file
     * @param array  $data     Parsed INI data
     */
    private function __construct(string $filename, array $data)
    {
        $this->filename = $filename;
        $this->data = $data;
    }

    /**
     * Load and parse an INI configuration file.
     *
     * @param  string $filename Path to the INI file
     * @return self             New IniConfig instance
     * @throws \RuntimeException If the file cannot be parsed
     */
    public static function load(string $filename): self
    {
        $data = \parse_ini_file($filename, true, \INI_SCANNER_TYPED);
        if ($data === false || !is_array($data)) {
            throw new \RuntimeException('Failed to parse INI file: ' . $filename);
        }

        return new self($filename, $data);
    }

    /**
     * Get the filename of the loaded INI file.
     *
     * @return string Path to the INI file
     */
    public function filename(): string
    {
        return $this->filename;
    }

    /**
     * Create a new config with overlay values merged on top.
     *
     * @param  array<string,mixed> $overlay Values to merge over existing data
     * @return self                         New IniConfig instance with merged data
     */
    public function withOverlay(array $overlay): self
    {
        $merged = self::mergeRecursive($this->data, $overlay);
        return new self($this->filename, $merged);
    }

    /**
     * Return the underlying parsed INI data.
     *
     * @return array<string,mixed> Section => [key => value] structure
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Check if a configuration value exists.
     *
     * @param  string $section INI section name
     * @param  string $key     Key within the section
     * @return bool            True if the value exists
     */
    public function has(string $section, string $key): bool
    {
        $this->assertSectionKey($section, $key);

        if (!array_key_exists($section, $this->data) || !is_array($this->data[$section])) {
            return false;
        }

        return array_key_exists($key, $this->data[$section]);
    }

    /**
     * Check if a configuration value exists using dot notation.
     *
     * @param  string $path Path in "section.key" format
     * @return bool         True if the value exists
     */
    public function hasPath(string $path): bool
    {
        [$section, $key] = $this->splitPath($path);
        return $this->has($section, $key);
    }

    /**
     * Get a string configuration value.
     *
     * @param  string      $section INI section name
     * @param  string      $key     Key within the section
     * @param  string|null $default Default value if key not found
     * @return string|null          The value or default
     * @throws \InvalidArgumentException If the value is not a string
     */
    public function getString(string $section, string $key, ?string $default = null): ?string
    {
        $value = $this->getRaw($section, $key);
        if ($value === null) {
            return $default;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->formatTypeError($section, $key, 'string', $value));
        }

        return $value;
    }

    /**
     * Get a string configuration value using dot notation.
     *
     * @param  string      $path    Path in "section.key" format
     * @param  string|null $default Default value if key not found
     * @return string|null          The value or default
     */
    public function getStringPath(string $path, ?string $default = null): ?string
    {
        [$section, $key] = $this->splitPath($path);
        return $this->getString($section, $key, $default);
    }

    /**
     * Get a required string configuration value.
     *
     * @param  string $section INI section name
     * @param  string $key     Key within the section
     * @return string          The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqString(string $section, string $key): string
    {
        $value = $this->getString($section, $key, null);
        if ($value === null) {
            throw new \RuntimeException($this->formatMissingError($section, $key));
        }
        return $value;
    }

    /**
     * Get a required string configuration value using dot notation.
     *
     * @param  string $path Path in "section.key" format
     * @return string       The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqStringPath(string $path): string
    {
        [$section, $key] = $this->splitPath($path);
        return $this->reqString($section, $key);
    }

    /**
     * Get a boolean configuration value.
     *
     * Accepts: true/false, yes/no, on/off, 1/0
     *
     * @param  string    $section INI section name
     * @param  string    $key     Key within the section
     * @param  bool|null $default Default value if key not found
     * @return bool|null          The value or default
     * @throws \InvalidArgumentException If the value cannot be parsed as bool
     */
    public function getBool(string $section, string $key, ?bool $default = null): ?bool
    {
        $value = $this->getRaw($section, $key);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 0) {
                return false;
            }
            if ($value === 1) {
                return true;
            }
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === 'true' || $v === 'yes' || $v === 'on' || $v === '1') {
                return true;
            }
            if ($v === 'false' || $v === 'no' || $v === 'off' || $v === '0') {
                return false;
            }
        }

        throw new \InvalidArgumentException($this->formatTypeError($section, $key, 'bool', $value));
    }

    /**
     * Get a boolean configuration value using dot notation.
     *
     * @param  string    $path    Path in "section.key" format
     * @param  bool|null $default Default value if key not found
     * @return bool|null          The value or default
     */
    public function getBoolPath(string $path, ?bool $default = null): ?bool
    {
        [$section, $key] = $this->splitPath($path);
        return $this->getBool($section, $key, $default);
    }

    /**
     * Get a required boolean configuration value.
     *
     * @param  string $section INI section name
     * @param  string $key     Key within the section
     * @return bool            The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqBool(string $section, string $key): bool
    {
        $value = $this->getBool($section, $key, null);
        if ($value === null) {
            throw new \RuntimeException($this->formatMissingError($section, $key));
        }
        return $value;
    }

    /**
     * Get a required boolean configuration value using dot notation.
     *
     * @param  string $path Path in "section.key" format
     * @return bool         The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqBoolPath(string $path): bool
    {
        [$section, $key] = $this->splitPath($path);
        return $this->reqBool($section, $key);
    }

    /**
     * Get an integer configuration value with optional range validation.
     *
     * @param  string   $section INI section name
     * @param  string   $key     Key within the section
     * @param  int|null $default Default value if key not found
     * @param  int|null $min     Minimum allowed value
     * @param  int|null $max     Maximum allowed value
     * @return int|null          The value or default
     * @throws \InvalidArgumentException If the value is not an integer or out of range
     */
    public function getInt(string $section, string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->getRaw($section, $key);
        if ($value === null) {
            return $default;
        }

        $intVal = null;
        if (is_int($value)) {
            $intVal = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $intVal = (int)trim($value);
        }

        if ($intVal === null) {
            throw new \InvalidArgumentException($this->formatTypeError($section, $key, 'int', $value));
        }

        $this->assertMinMax($section, $key, $intVal, $min, $max);
        return $intVal;
    }

    /**
     * Get an integer configuration value using dot notation.
     *
     * @param  string   $path    Path in "section.key" format
     * @param  int|null $default Default value if key not found
     * @param  int|null $min     Minimum allowed value
     * @param  int|null $max     Maximum allowed value
     * @return int|null          The value or default
     */
    public function getIntPath(string $path, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        [$section, $key] = $this->splitPath($path);
        return $this->getInt($section, $key, $default, $min, $max);
    }

    /**
     * Get a required integer configuration value.
     *
     * @param  string   $section INI section name
     * @param  string   $key     Key within the section
     * @param  int|null $min     Minimum allowed value
     * @param  int|null $max     Maximum allowed value
     * @return int               The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqInt(string $section, string $key, ?int $min = null, ?int $max = null): int
    {
        $value = $this->getInt($section, $key, null, $min, $max);
        if ($value === null) {
            throw new \RuntimeException($this->formatMissingError($section, $key));
        }
        return $value;
    }

    /**
     * Get a required integer configuration value using dot notation.
     *
     * @param  string   $path Path in "section.key" format
     * @param  int|null $min  Minimum allowed value
     * @param  int|null $max  Maximum allowed value
     * @return int            The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqIntPath(string $path, ?int $min = null, ?int $max = null): int
    {
        [$section, $key] = $this->splitPath($path);
        return $this->reqInt($section, $key, $min, $max);
    }

    /**
     * Get a float configuration value with optional range validation.
     *
     * @param  string     $section INI section name
     * @param  string     $key     Key within the section
     * @param  float|null $default Default value if key not found
     * @param  float|null $min     Minimum allowed value
     * @param  float|null $max     Maximum allowed value
     * @return float|null          The value or default
     * @throws \InvalidArgumentException If the value is not numeric or out of range
     */
    public function getFloat(string $section, string $key, ?float $default = null, ?float $min = null, ?float $max = null): ?float
    {
        $value = $this->getRaw($section, $key);
        if ($value === null) {
            return $default;
        }

        $floatVal = null;
        if (is_float($value)) {
            $floatVal = $value;
        } elseif (is_int($value)) {
            $floatVal = (float)$value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $floatVal = (float)trim($value);
        }

        if ($floatVal === null || is_nan($floatVal) || is_infinite($floatVal)) {
            throw new \InvalidArgumentException($this->formatTypeError($section, $key, 'float', $value));
        }

        $this->assertMinMax($section, $key, $floatVal, $min, $max);
        return $floatVal;
    }

    /**
     * Get a float configuration value using dot notation.
     *
     * @param  string     $path    Path in "section.key" format
     * @param  float|null $default Default value if key not found
     * @param  float|null $min     Minimum allowed value
     * @param  float|null $max     Maximum allowed value
     * @return float|null          The value or default
     */
    public function getFloatPath(string $path, ?float $default = null, ?float $min = null, ?float $max = null): ?float
    {
        [$section, $key] = $this->splitPath($path);
        return $this->getFloat($section, $key, $default, $min, $max);
    }

    /**
     * Get a required float configuration value.
     *
     * @param  string     $section INI section name
     * @param  string     $key     Key within the section
     * @param  float|null $min     Minimum allowed value
     * @param  float|null $max     Maximum allowed value
     * @return float               The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqFloat(string $section, string $key, ?float $min = null, ?float $max = null): float
    {
        $value = $this->getFloat($section, $key, null, $min, $max);
        if ($value === null) {
            throw new \RuntimeException($this->formatMissingError($section, $key));
        }
        return $value;
    }

    /**
     * Get a required float configuration value using dot notation.
     *
     * @param  string     $path Path in "section.key" format
     * @param  float|null $min  Minimum allowed value
     * @param  float|null $max  Maximum allowed value
     * @return float            The value
     * @throws \RuntimeException If the value is missing
     */
    public function reqFloatPath(string $path, ?float $min = null, ?float $max = null): float
    {
        [$section, $key] = $this->splitPath($path);
        return $this->reqFloat($section, $key, $min, $max);
    }

    /**
     * Get a boolean value with section override support.
     *
     * Checks override section first, falls back to base section.
     *
     * @param  string    $overrideSection Section to check first
     * @param  string    $baseSection     Fallback section
     * @param  string    $key             Key within the section
     * @param  bool|null $default         Default value if not found
     * @return bool|null                  The value or default
     */
    public function getOverrideBool(string $overrideSection, string $baseSection, string $key, ?bool $default = null): ?bool
    {
        if ($this->has($overrideSection, $key)) {
            return $this->getBool($overrideSection, $key, $default);
        }
        return $this->getBool($baseSection, $key, $default);
    }

    /**
     * Get an integer value with section override support.
     *
     * @param  string   $overrideSection Section to check first
     * @param  string   $baseSection     Fallback section
     * @param  string   $key             Key within the section
     * @param  int|null $default         Default value if not found
     * @param  int|null $min             Minimum allowed value
     * @param  int|null $max             Maximum allowed value
     * @return int|null                  The value or default
     */
    public function getOverrideInt(string $overrideSection, string $baseSection, string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        if ($this->has($overrideSection, $key)) {
            return $this->getInt($overrideSection, $key, $default, $min, $max);
        }
        return $this->getInt($baseSection, $key, $default, $min, $max);
    }

    /**
     * Get a float value with section override support.
     *
     * @param  string     $overrideSection Section to check first
     * @param  string     $baseSection     Fallback section
     * @param  string     $key             Key within the section
     * @param  float|null $default         Default value if not found
     * @param  float|null $min             Minimum allowed value
     * @param  float|null $max             Maximum allowed value
     * @return float|null                  The value or default
     */
    public function getOverrideFloat(string $overrideSection, string $baseSection, string $key, ?float $default = null, ?float $min = null, ?float $max = null): ?float
    {
        if ($this->has($overrideSection, $key)) {
            return $this->getFloat($overrideSection, $key, $default, $min, $max);
        }
        return $this->getFloat($baseSection, $key, $default, $min, $max);
    }

    /**
     * Get a string value with section override support.
     *
     * @param  string      $overrideSection Section to check first
     * @param  string      $baseSection     Fallback section
     * @param  string      $key             Key within the section
     * @param  string|null $default         Default value if not found
     * @return string|null                  The value or default
     */
    public function getOverrideString(string $overrideSection, string $baseSection, string $key, ?string $default = null): ?string
    {
        if ($this->has($overrideSection, $key)) {
            return $this->getString($overrideSection, $key, $default);
        }
        return $this->getString($baseSection, $key, $default);
    }

    /**
     * Get the raw value for a configuration key.
     *
     * @param  string $section INI section name
     * @param  string $key     Key within the section
     * @return mixed           The raw value or null if not found
     */
    private function getRaw(string $section, string $key): mixed
    {
        $this->assertSectionKey($section, $key);

        if (!array_key_exists($section, $this->data) || !is_array($this->data[$section])) {
            return null;
        }

        if (!array_key_exists($key, $this->data[$section])) {
            return null;
        }

        return $this->data[$section][$key];
    }

    /**
     * Validate that section and key are non-empty strings.
     *
     * @param  string $section Section name to validate
     * @param  string $key     Key name to validate
     * @throws \InvalidArgumentException If either is empty
     */
    private function assertSectionKey(string $section, string $key): void
    {
        if (trim($section) === '') {
            throw new \InvalidArgumentException('Config section must not be empty');
        }
        if (trim($key) === '') {
            throw new \InvalidArgumentException('Config key must not be empty');
        }
    }

    /**
     * Split a dot-notation path into section and key.
     *
     * @param  string $path Path in "section.key" format
     * @return array{0:string,1:string} [section, key] tuple
     * @throws \InvalidArgumentException If path format is invalid
     */
    private function splitPath(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Config path must not be empty');
        }

        $pos = strpos($path, '.');
        if ($pos === false) {
            throw new \InvalidArgumentException('Config path must be of the form "section.key": ' . $path);
        }

        $section = substr($path, 0, $pos);
        $key = substr($path, $pos + 1);

        if (trim($section) === '' || trim($key) === '') {
            throw new \InvalidArgumentException('Config path must be of the form "section.key": ' . $path);
        }

        return [$section, $key];
    }

    /**
     * Format an error message for missing required values.
     *
     * @param  string $section Section name
     * @param  string $key     Key name
     * @return string          Formatted error message
     */
    private function formatMissingError(string $section, string $key): string
    {
        return 'Missing required config value: ' . $section . '.' . $key . ' (file: ' . $this->filename . ')';
    }

    /**
     * Format an error message for type mismatches.
     *
     * @param  string $section      Section name
     * @param  string $key          Key name
     * @param  string $expectedType Expected type name
     * @param  mixed  $actual       Actual value received
     * @return string               Formatted error message
     */
    private function formatTypeError(string $section, string $key, string $expectedType, mixed $actual): string
    {
        $actualType = gettype($actual);
        return 'Invalid type for config value ' . $section . '.' . $key . ': expected ' . $expectedType . ', got ' . $actualType . ' (file: ' . $this->filename . ')';
    }

    /**
     * Recursively merge two arrays, with overlay values taking precedence.
     *
     * @param  array<string,mixed> $base    Base array
     * @param  array<string,mixed> $overlay Overlay array
     * @return array<string,mixed>          Merged array
     */
    private static function mergeRecursive(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if (array_key_exists($k, $base) && is_array($base[$k]) && is_array($v)) {
                $base[$k] = self::mergeRecursive($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /**
     * Validate that a numeric value is within the specified range.
     *
     * @param  string        $section Section name (for error messages)
     * @param  string        $key     Key name (for error messages)
     * @param  float|int     $value   Value to validate
     * @param  float|int|null $min    Minimum allowed value
     * @param  float|int|null $max    Maximum allowed value
     * @throws \InvalidArgumentException If value is out of range
     */
    private function assertMinMax(string $section, string $key, float|int $value, float|int|null $min, float|int|null $max): void
    {
        if ($min !== null && $value < $min) {
            throw new \InvalidArgumentException('Config value ' . $section . '.' . $key . ' must be >= ' . $min . ' (got ' . $value . ', file: ' . $this->filename . ')');
        }
        if ($max !== null && $value > $max) {
            throw new \InvalidArgumentException('Config value ' . $section . '.' . $key . ' must be <= ' . $max . ' (got ' . $value . ', file: ' . $this->filename . ')');
        }
    }
}
