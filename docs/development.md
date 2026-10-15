# Development

## Local checks

Run `php tests/run.php` to exercise the API client's response parsing, authentication error mapping, and URL rejection using a simulated transport. The test does not issue network requests.

Run `php -l` on each PHP file to check syntax. A real compatibility check requires installing the plugin in a GLPI 11 instance; a real connectivity check requires a BookStack instance and an administrator-provided read-only API token.

## GLPI setup

Place the plugin under `plugins/bookstackbridge`. GLPI discovers the plugin from its folder and uses `setup.php` and `hook.php` for metadata and lifecycle. Controllers are automatically discovered in `src/Controller`. No Composer install is required at runtime.

## Security checks

- Keep GLPI's generated `glpicrypt.key` backed up and protected; GLPI needs it to decrypt protected settings.
- Use a dedicated BookStack integration account, only read permissions, token expiry and rotation.
- Keep TLS verification on. No redirects are followed.
- Do not test with production credentials in a development environment.
