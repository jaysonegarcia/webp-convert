# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-05-19

### Fixed
- Palette (indexed-color) PNGs no longer fatal the conversion with "Palette image not supported by webp". The converter detects palette PNGs via the IHDR color-type byte and promotes them to truecolor through raw GD before encoding.
- A failed conversion no longer leaves a 0-byte `.webp` that short-circuits subsequent retries — `convert_file()` now treats empty target files as missing.

## [1.0.1] - 2026-05-19

### Changed
- Bumped minimum PHP requirement to 8.0 in `composer.json`.

## [1.0.0] - 2026-04-17

### Added
- Initial release.
