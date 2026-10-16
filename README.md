# GLPI BookStack Bridge

GLPI 11 plugin for an authenticated, read-only connection to the BookStack REST API. M0 includes protected connection settings, an API connectivity check, and experimental search. M1.1 adds read-only structural navigation of shelves, books, chapters, and page titles. Settings, API check, and experimental search require GLPI configuration update rights; structural navigation requires the native GLPI Knowledge base read right.

## Requirements

- GLPI 11.0.x (declared range: `>=11.0.0,<11.1.0`)
- PHP 8.2 or newer, matching GLPI 11 requirements
- PHP cURL and Sodium extensions
- BookStack with API access enabled and a dedicated read-only user allowed to access the system API
- HTTPS BookStack URL on TCP 443 with a certificate trusted by the PHP/cURL runtime
- Private BookStack destinations remain blocked unless each exact private IP is explicitly allowlisted by a GLPI administrator

## Install

Copy this repository to `<GLPI_ROOT>/plugins/bookstackbridge`, then open **Setup → Plugins**, install and activate **GLPI BookStack Bridge**. GLPI's official plugin lifecycle installs through the plugin list; uninstalling removes plugin configuration but does not remove its files.

The plugin uses GLPI 11's automatic plugin controller discovery and PSR-4 namespace. No Composer runtime dependencies are required.

## Configure and verify

Open **Tools → BookStack knowledge base** as a user with configuration update rights. Save the BookStack HTTPS URL and any private-IP allowlist entries independently of API credentials. Token ID and secret are optional during initial network setup; BookStack test and search operations require both. Enter either credential field to replace that value and leave it blank to retain the stored value. The form never displays stored credentials. Create the token for a dedicated BookStack account that has only the read permissions required by the integration plus the “Access System API” role permission. When the page reports that the integration is ready for test, select **Check BookStack API**. The experimental search form submits a query to BookStack and shows returned results within GLPI.

The secret is stored in GLPI's `plugin:bookstackbridge` configuration context and declared through GLPI's `secured_configs` hook, so GLPI encrypts it with its security key and can migrate it when that key changes. The plugin does not display the secret back in the form. Private IP destinations can be authorized individually in **Allowed private destination IPs**. Enter one exact RFC1918 IPv4 or unique-local IPv6 address per line; CIDR ranges and hostnames are not accepted. DNS names resolving to private addresses require each private address returned by DNS to be listed. Loopback, link-local, metadata, multicast, and reserved destinations remain blocked. HTTPS certificate verification remains enabled and redirects are never followed. The plugin connects directly and ignores proxy environment settings so the validated IP can be pinned. See [Network configuration](docs/network-configuration.md) before enabling an internal destination.

## Browse BookStack structure

Use **Tools → BookStack knowledge base** to browse shelves, then books, chapters, and page titles. A book may be listed under more than one shelf; the book view shows its shelf memberships. Pages directly under a book are listed alongside chapters. Shelf lists are paginated. Navigation fetches structural metadata through the BookStack API on each request; it does not store or synchronize content in GLPI. M1.1 does not display page body HTML, images, or attachments. The reader is reserved for M1.2. The BookStack API uses the configured read-only integration account, so its BookStack content permissions apply to every GLPI reader. Grant the GLPI Knowledge base read right only to profiles whose users are authorized for the integration account's full content scope. Experimental search and settings remain on the administrator connection page.

## Development checks

Run `php tests/run.php` for simulated HTTP response handling and URL policy checks. These tests do not connect to GLPI or a BookStack server. Run `php -l` on the PHP files when making changes. See [docs/development.md](docs/development.md), [docs/architecture.md](docs/architecture.md), and [network configuration](docs/network-configuration.md).

## Status and limits

M0 and M1.1 were functionally validated in GLPI 11.0.4 with a real BookStack instance. Search remains restricted to configuration administrators; navigation follows GLPI Knowledge base read permissions. Search results and excerpts are HTML escaped by Twig. The page body reader, rich HTML sanitization, and ticket linking are not implemented. The automated API and navigation tests use simulated responses and do not prove live BookStack connectivity.

The E1 multi-entity persistence foundation is in progress and is not connected to the existing configuration screens or catalog. It preserves legacy global configuration and requires an explicit, confirmed one-entity copy operation; installation never assigns existing credentials to entities automatically. See [architecture](docs/architecture.md) and [development](docs/development.md) for the storage and key-rotation design. Before uninstalling, back up the GLPI database and `glpicrypt.key`; normal plugin uninstall removes plugin-owned entity connections and legacy plugin configuration.
