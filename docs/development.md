# Development

## Local checks

Run `php tests/run.php` to exercise the API client's response parsing, authentication error mapping, private-address policy, DNS multi-answer and pinning behavior, redirect refusal, TLS option preservation, and allowlist normalization using simulated resolvers/transports. The test does not issue network requests or perform a real TLS handshake. If a local GLPI source/install tree is available, set `GLPI_ROOT` to its root before running to exercise the optional Twig layout regression against that tree.

Run `php -l` on each PHP file to check syntax. A real compatibility check requires installing the plugin in a GLPI 11 instance; a real connectivity check requires a BookStack instance and an administrator-provided read-only API token.

For GLPI versions earlier than 11.0.7, plugin routes with POST-only method declarations may not be found. The configuration, test, and search routes include GET for router discovery compatibility and enforce POST in the controller. Verify GET requests return method-not-allowed and that POST forms continue to require GLPI CSRF validation in the target GLPI instance.

## GLPI setup

Place the plugin under `plugins/bookstackbridge`. GLPI discovers the plugin from its folder and uses `setup.php` and `hook.php` for metadata and lifecycle. Controllers are automatically discovered in `src/Controller`. No Composer install is required at runtime.

## Security checks

- Keep GLPI's generated `glpicrypt.key` backed up and protected; GLPI needs it to decrypt protected settings.
- Use a dedicated BookStack integration account, only read permissions, token expiry and rotation.
- Keep TLS peer and SAN verification on. The HTTP client never follows redirects and ignores proxy environment settings.
- Private destinations remain denied unless each exact RFC1918/ULA IP is explicitly authorized in the plugin's administrator configuration. CIDR ranges, reserved space, loopback, link-local, metadata, and multicast remain blocked.
- Hostname resolution checks all A/AAAA results and pins the selected validated address to the request. Do not remove `CURLOPT_RESOLVE` pinning or trust an unchecked hostname lookup.
- Review [network-configuration.md](network-configuration.md) before authorizing internal addresses. The plugin relies on PHP/cURL's trust store and does not configure a plugin-specific CA file.
- Do not test with production credentials in a development environment.
