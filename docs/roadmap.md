# Roadmap

## M0 — Foundation and connectivity

Implemented: GLPI plugin metadata/lifecycle, tools menu, administrator-only routes and configuration UI, GLPI protected token storage, HTTPS BookStack client, connectivity check, experimental API search, consistent HTTP error messages, and simulated client tests.

Validated in GLPI 11.0.4. Live BookStack connectivity was confirmed as part of M0 acceptance; the local automated API tests remain simulated and are not a replacement for that environment check.

M0.1 local network-safety update: exact private IPv4/IPv6 allowlisting, DNS answer validation and address pinning, TLS-preserving transport controls, and generic network configuration guidance are implemented locally. Validation in the separate test environment and its PHP/cURL trust configuration remains pending and must be done by an authorized operator; this workspace does not contain or record infrastructure identifiers.

## E1 — Per-entity connection foundation (in progress)

Implemented locally: a plugin-owned connection table keyed by GLPI entity, GLPI `SECURED_FIELDS` registration for both credential columns, encrypted repository reads/writes with partial credential preservation and safe replacement after decryption failure, plus an explicit one-entity legacy-copy primitive requiring backup and destination confirmation. Installation preserves existing global M0 settings and does not migrate them automatically. The foundation is not yet used by controllers or navigation. Real GLPI schema and key-rotation validation is pending; review E1 before starting E2.

## M1 — Navigation and reader

### M1.1 — Structural navigation

Implemented locally: read-only navigation for users with GLPI's native Knowledge base `READ` permission, covering shelves, books, chapters, and page titles; breadcrumbs, back links, empty/error states, and shelf pagination. BookStack remains the source of truth; no content is copied into GLPI. The page body, images, and attachments are not rendered. Simulated tests cover API shapes and navigation; functional behavior was also validated in GLPI 11.0.4 with a real BookStack instance.

### M1.2 — Page reader (planned)

Implement full page reading, safe HTML sanitization, authorized images/attachments, and links while preserving BookStack permissions. Do not start this work unless requested.

## Later milestones

M2 general search; M3 persistent Ticket article links with GLPI authorization; M4 integration and authorization testing. V0.3 selective one-way BookStack-to-`KnowbaseItem` sync remains out of scope until separately specified.
