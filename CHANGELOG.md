# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.14.0] - 2026-11-26

### Added

- `obter_faixas()` and `obter_planos_disponiveis()`: faixa registry (GUID => letter/weight) and active plans with per-faixa composition — `{ id, nome, desconto, faixas: [letra => créditos] }`, alphabetical by name, long-lived cache (filter `hacklabr/composicao_plano_cache_ttl`). Contract for the association wizard's plan picker (theme) and future benefit displays. Fail-closed: inactive plans or plans without composition are not exposed
- `consultar_saldo_detalhado()`: balance query enriched with used credits per faixa — resolves the partnership's plan (`obter_plano_da_parceria()`), reads the plan composition (`obter_composicao_plano()`, active records only, long-lived cache) and derives utilization via the pure `calcular_creditos_utilizados()` (clamped at zero). Contract for the associates area display (total consumed + validity)
- `obter_faixa_do_projeto()`, `tem_saldo_na_faixa()` and `parceria_esta_vigente()`: event faixa lookup (long-lived cache) and pure balance/validity checks backing the pre-registration gate (RQ-0004) consumed by the theme's event form

## [0.13.0] - 2026-11-25

### Added

- `includes/association-api.php`: reusable layer for the Plano de Associação Custom APIs in the CRM (definition in `docs/api-plano-associacao.md`). Domain functions in PT — `consultar_saldo()` (accepts numeric and the new alphanumeric CNPJ; short-lived transient cache with `$fresh` bypass), `criar_transacao_credito()`, `criar_transacoes_lote()`, `estornar_transacao()`, `obter_associadas()`, `obter_contatos_aprovados()` — over a generic `crm_call_custom_action()` that maps OData errors to `WP_Error` carrying the CRM message, and works around the toolkit bug with bound actions. Wire action names centralized in `CRM_ACTION_*` constants. Validated end-to-end in the sandbox: balance query, credit consumption and refund loop
- `includes/admin-saldo-test.php`: admin test page for the balance query (Ferramentas → *Ethos d365i Saldo*, slug `ethos-saldo-test`), kept out of the domain layer

## [0.12.1] - 2026-10-27

### Removed

- Timing telemetry log emitted during Dynamics client construction

## [0.12.0] - 2026-10-13

### Added

- `includes/psr6-cache.php`: minimal PSR-6 cache pool over `wp_cache_*`, replacing snicco/better-wp-cache and the nullcacheitem compatibility patch

### Changed

- Registration flow optimizations (batch helpers, CRM helpers, data sender)
- Composer dependencies trimmed (patch machinery removed)

## [0.11.1] - 2026-10-05

### Fixed

- Admin panel: migration in-progress check now reads the chunk lock transient (was reusing the cycle lock key)

## [0.11.0] - 2026-10-05

### Added

- `get_crm_entities_page()`: retrieves a single page of CRM entities using FetchXML page-number pagination (a restartable cursor), enabling chunked long-running iterations
- Migration status panel: in-progress cycle progress (current page, accounts seen, contacts, errors, next chunk)

## [0.9.0] - 2026-08-07

- Support for batching requests to CRM

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
