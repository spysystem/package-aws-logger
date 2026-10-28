# Changelog

## 1.1.0

### Notes

- PHP 8.1 support dropped; `php` is now `~8.5.0`
- Now required because the code uses them directly: `guzzlehttp/promises` `~2.1`, `guzzlehttp/psr7` `~2.11`, `psr/http-factory` `~1.1`,
  `psr/http-message` `~1.1 || ~2.0`, `ramsey/uuid` `~4.7`
- Requires spysystem/package-rest `~1.3.2` (its 1.3.0 breaking changes do not affect this package) and the new lines of
  spysystem/package-centralized-logger-data `~1.1.0` and spysystem/package-data `~1.1.0`
- `require-dev`: removed `jetbrains/phpstorm-attributes`, `phpunit/phpunit` (unused)
