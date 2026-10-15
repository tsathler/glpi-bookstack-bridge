# Architecture decisions

## Runtime contracts

- Plugin metadata and install lifecycle follow the GLPI plugin structure: `setup.php` supplies `plugin_init_*`, version and prerequisite functions; `hook.php` supplies install/uninstall functions. GLPI's plugin manager installs, activates, updates and uninstalls the plugin. Uninstall removes the plugin-owned configuration context; it does not delete plugin source.
- The supported version range is GLPI `>=11.0.0,<11.1.0`. Plugin controllers are discovered from `src/Controller`, use PSR-4 `GlpiPlugin\Bookstackbridge\Controller`, extend `Glpi\Controller\AbstractController`, use Symfony `Route` attributes and return Symfony responses. Twig templates are addressed using `@bookstackbridge/...`.
- GLPI versions before 11.0.7 have a plugin route method-matching bug. Form routes advertise `GET` and `POST` for discovery compatibility, then explicitly reject non-POST requests in the controller; this does not permit state changes by GET.
- In GLPI 11.0.4, `TemplateRenderer` constructs `RoutingExtension` without a router. The plugin routes live in the separate plugin router, so Twig `path()` cannot resolve their names and falls back to `Html::getPrefixedUrl()`. Use `path('/plugins/<plugin-key>/<route>')` for plugin form actions; `Html::getPrefixedUrl()` prepends GLPI's `root_doc`, preserving installations below a URL subdirectory. This project uses `/plugins/bookstackbridge/`; do not use `Plugin::getWebDir()`, which GLPI deprecates.
- Navigation is registered with the documented `menu_toadd` hook. The menu only appears for users with `config` update rights. Routes independently enforce this right; hiding a menu is never treated as authorization.
- The `csrf_compliant` hook opts the plugin into GLPI's CSRF validation. Mutating and query forms carry GLPI's `_glpi_csrf_token` Twig token field.
- `secured_configs` declares `api_token` in the `plugin:bookstackbridge` context. GLPI's `GLPIKey` encrypts the value and includes it in security-key migration. No custom cryptography or secret display is used.

## BookStack API

BookStack's API routes are rooted at `/api`, so the configured URL should be the BookStack site origin or installation path prefix, without an `/api` suffix. The client appends `/api/search` and `/api/shelves`.

BookStack's official API uses `Authorization: Token <id>:<secret>`. Standard list endpoints contain `data` and `total`, accept `count` (default 100, maximum 500) and `offset`, and report errors with 4xx/5xx status codes. Global search is `GET /api/search` with required `query`, `page` (minimum 1) and `count` (maximum 100); this plugin requests 20 results per page. Connectivity is tested with a one-item `GET /api/shelves` list request. Responses remain scoped to the BookStack token owner's roles and content permissions.

The HTTP client is read-only, verifies TLS, does not follow redirects, disables proxy use, limits connect/total time, and accepts only HTTPS on the standard port. URL policy rejects credentials, query/fragment, local names, malformed hostnames, unsafe paths, loopback, link-local, metadata, multicast, and reserved IPs. Private RFC1918/ULA IPs are rejected unless each exact private IP is explicitly present in the administrator-managed `allowed_private_ips` configuration; CIDR ranges and hostnames are not accepted in that allowlist. DNS A and AAAA results are all validated and the chosen address is pinned to the hostname and port with cURL `CURLOPT_RESOLVE` for the request, preserving hostname-based TLS/SAN verification and preventing a DNS change between policy validation and connect. The API token is never written to logs or rendered into HTML.

Private destination authorization does not alter the TLS trust store or bypass certificate/SAN checks. The plugin currently relies on the PHP/cURL runtime's trusted CA configuration and has no per-plugin CA bundle option. See [network-configuration.md](network-configuration.md) for the allowlist, trust-store, firewall, and diagnostic procedures.

## Scope

No BookStack database access, core modifications, migrations, JavaScript framework, or third-party runtime libraries are used. Controllers, API client, security policy, and menu are the only components needed for M0. Future ticket associations and selective GLPI knowledge-base sync remain out of scope.
