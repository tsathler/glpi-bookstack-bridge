# GLPI BookStack Bridge

GLPI 11 plugin foundation for an authenticated, read-only connection to the BookStack REST API. M0 includes protected connection settings, an API connectivity check, and an experimental search screen. All three actions are restricted to users with GLPI configuration update rights.

## Requirements

- GLPI 11.0.x (declared range: `>=11.0.0,<11.1.0`)
- PHP 8.2 or newer, matching GLPI 11 requirements
- PHP cURL and Sodium extensions
- BookStack with API access enabled and a dedicated read-only user allowed to access the system API
- HTTPS BookStack URL resolving only to public IP addresses

## Install

Copy this repository to `<GLPI_ROOT>/plugins/bookstackbridge`, then open **Setup → Plugins**, install and activate **GLPI BookStack Bridge**. GLPI's official plugin lifecycle installs through the plugin list; uninstalling removes plugin configuration but does not remove its files.

The plugin uses GLPI 11's automatic plugin controller discovery and PSR-4 namespace. No Composer runtime dependencies are required.

## Configure and verify

Open **Tools → BookStack knowledge base** as a user with configuration update rights. Set the BookStack HTTPS URL, API token ID, and API token secret. Create the token for a dedicated BookStack account that has only the read permissions required by the integration plus the “Access System API” role permission. Save, then select **Check BookStack API**. The experimental search form submits a query to BookStack and shows returned results within GLPI.

The secret is stored in GLPI's `plugin:bookstackbridge` configuration context and declared through GLPI's `secured_configs` hook, so GLPI encrypts it with its security key and can migrate it when that key changes. The plugin does not display the secret back in the form. Configure TLS correctly on the BookStack host; certificate verification remains enabled.

## Development checks

Run `php tests/run.php` for simulated HTTP response handling and URL policy checks. These tests do not connect to GLPI or a BookStack server. Run `php -l` on the PHP files when making changes. See [docs/development.md](docs/development.md) and [docs/architecture.md](docs/architecture.md).

## Status and limits

M0 is implemented. Search is intentionally restricted to configuration administrators in this milestone; do not treat this as a general user knowledge portal. Search results and excerpts are HTML escaped by Twig. BookStack result links are external links. M1 navigation, article display, sanitization of full page HTML, and ticket linking are not implemented. Integration against a live GLPI/BookStack installation has not been validated in this repository.
