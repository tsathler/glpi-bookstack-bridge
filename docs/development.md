# Development

## Local checks

Run `php tests/run.php` to exercise the API client's response parsing, authentication error mapping, private-address policy, DNS multi-answer and pinning behavior, redirect refusal, TLS option preservation, allowlist normalization, and structural navigation using simulated resolvers/transports and API responses. The test does not issue network requests or perform a real TLS handshake. If a local GLPI source/install tree is available, set `GLPI_ROOT` to its root before running to exercise the optional Twig layout and rendered navigation links against that tree.

Run `php -l` on each PHP file to check syntax. A real compatibility check requires installing the plugin in a GLPI 11 instance; a real connectivity check requires a BookStack instance and an administrator-provided read-only API token.

For GLPI versions earlier than 11.0.7, plugin routes with POST-only method declarations may not be found. The configuration, test, and search routes include GET for router discovery compatibility and enforce POST in the controller. Verify GET requests return method-not-allowed and that POST forms continue to require GLPI CSRF validation in the target GLPI instance.

## GLPI setup

Place the plugin under `plugins/bookstackbridge`. GLPI discovers the plugin from its folder and uses `setup.php` and `hook.php` for metadata and lifecycle. Controllers are automatically discovered in `src/Controller`. No Composer install is required at runtime.

The administrator can save a validated HTTPS URL and private-IP allowlist before creating a BookStack API token. API credentials are optional configuration fields; blank fields preserve their stored values, while API test and search operations require a complete token ID and secret. The form does not render stored credentials.

Structural navigation routes are automatically discovered in `src/Controller/KnowledgeController.php`. They are GET-only and require GLPI's native `knowbase` `READ` right; configuration, API test, and search routes continue to require `config` `UPDATE`. Shelf lists use `count=100` and `offset`; the BookStack shelf and book detail responses provide embedded relationship data. The navigation service only passes structural IDs, names, and descriptions to Twig. The integration account's BookStack permissions define the shared content scope for all GLPI readers. Do not add page bodies or API-provided URLs to navigation output; page reading is a separate M1.2 scope.

## Security checks

The E1 storage foundation adds a plugin-owned entity connection table. Its `api_token_id` and `api_token` columns are registered with `Hooks::SECURED_FIELDS`; GLPI handles these values with its encryption mechanism and the `glpi:security:changekey` command. The legacy global config remains preserved and is not copied during install or update. A future admin flow must require an explicit target entity, a verified database/key backup, and confirmation before invoking the repository's one-entity copy primitive. Back up the database and GLPI security key before uninstall; normal plugin uninstall removes this table and the legacy plugin config context.

- Keep GLPI's generated `glpicrypt.key` backed up and protected; GLPI needs it to decrypt protected settings.
- Use a dedicated BookStack integration account, only read permissions, token expiry and rotation.
- Keep TLS peer and SAN verification on. The HTTP client never follows redirects and ignores proxy environment settings.
- Private destinations remain denied unless each exact RFC1918/ULA IP is explicitly authorized in the plugin's administrator configuration. CIDR ranges, reserved space, loopback, link-local, metadata, and multicast remain blocked.
- Hostname resolution checks all A/AAAA results and pins the selected validated address to the request. Do not remove `CURLOPT_RESOLVE` pinning or trust an unchecked hostname lookup.
- Review [network-configuration.md](network-configuration.md) before authorizing internal addresses. The plugin relies on PHP/cURL's trust store and does not configure a plugin-specific CA file.
- Do not test with production credentials in a development environment.
