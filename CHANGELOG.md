# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-02-19

### Changed

- BREAKING: It now requires PHP 8.2+
- Use metadata cache, for significative performance improvements

## [0.7.0] - 2025-10-30

### Changed

- Save CRM value on WordPress when it's 0 (zero)

## [0.6.5] - 2025-05-09

### Fixed

- Bug fix in synchronization of private (or otherwise non-published) events

## [0.6.4] - 2025-05-09

### Fixed

- Bug fix in synchronization of additional organization contacts

## [0.6.3] - 2024-10-14

### Changed

- Change the post content metadata to use 'fut_txt_descricao' attribute.

## [0.6.2] - 2024-09-18

### Added

- Updates the post content metadata when the content of a 'tribe_events' post has changed.

### Changed

- Updates the post content for an event on the import.

## [0.6.1] - 2024-09-11

- Add helper function `get_sync_waiting_list()`

## [0.0.1] - 2024-07-11

- Initial version
