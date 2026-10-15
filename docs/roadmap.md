# Roadmap

## M0 — Foundation and connectivity

Implemented: GLPI plugin metadata/lifecycle, tools menu, administrator-only routes and configuration UI, GLPI protected token storage, HTTPS BookStack client, connectivity check, experimental API search, consistent HTTP error messages, and simulated client tests.

Pending acceptance validation: install/activate/configure in a real GLPI 11 instance and run the connectivity/search requests against a reachable BookStack server. No such instance or credentials were supplied in this workspace.

M0.1 local network-safety update: exact private IPv4/IPv6 allowlisting, DNS answer validation and address pinning, TLS-preserving transport controls, and generic network configuration guidance are implemented locally. Validation in the separate test environment and its PHP/cURL trust configuration remains pending and must be done by an authorized operator; this workspace does not contain or record infrastructure identifiers.

## M1 — Navigation and reader

Not implemented. Add shelves/books/chapters/pages navigation, direct book pages, breadcrumbs, and a sanitized page reader. BookStack permissions must continue to be enforced per request through the configured user's API permissions.

## Later milestones

M2 general search; M3 persistent Ticket article links with GLPI authorization; M4 integration and authorization testing. V0.3 selective one-way BookStack-to-`KnowbaseItem` sync remains out of scope until separately specified.
