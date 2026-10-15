# Network configuration

This document uses documentation-only addresses and example names. `192.0.2.10` is reserved for examples and is not a real target. Replace example values only in local commands with the destination authorized for your environment. Never commit real internal addresses, hostnames, credentials, or certificate fingerprints to this public repository.

## Connectivity requirements

- The GLPI PHP process must establish outbound TCP connections to the configured BookStack server on HTTPS port 443.
- The plugin makes a direct connection and deliberately disables proxy settings from the environment so the pinned destination cannot be replaced by a proxy-side DNS lookup. Environments requiring an outbound proxy need a separately designed, explicitly pinned proxy path; do not enable ambient proxies to work around this restriction.
- Scope firewall rules to the GLPI server as the source and the exact BookStack server address as the destination. Permit TCP 443 only; do not add broad subnet or any-to-any rules for this plugin.
- Provide routing from the GLPI host to the destination. For DNS names, the GLPI host must resolve both A and AAAA records through its configured resolver. Every returned address is checked before a connection is attempted.
- The PHP/cURL runtime must trust the certificate chain and verify the endpoint identity. TLS peer and SAN/hostname checks stay enabled. The HTTP client does not follow redirects or use environment-configured proxies.
- A self-signed certificate can be used only when the corresponding trust anchor is installed in the trust store used by the PHP/cURL runtime and the certificate SAN matches the configured IP address or DNS hostname. Trusting the certificate does not relax SAN validation.
- For an IP URL, the certificate SAN must contain that exact IP address as an IP SAN. For a DNS URL, the SAN must match that DNS hostname. A Common Name without a matching SAN is insufficient.

Private RFC1918 IPv4 and IPv6 unique-local destinations are blocked by default. An administrator can explicitly allow exact private IP addresses in the plugin configuration. The allowlist accepts one IP per line, rejects CIDR ranges and hostnames, and is checked against all DNS answers. Loopback, link-local, metadata, multicast, and reserved addresses remain blocked even if submitted. HTTPS, standard port 443, TLS verification, fixed-address transport, and redirect blocking remain in force.

## Diagnostics

Run network checks on the GLPI host, under an account that can inspect the PHP/cURL environment. ICMP ping can be blocked independently of HTTPS and does not prove TCP 443 connectivity.

```powershell
ping -n 4 192.0.2.10
Test-NetConnection -ComputerName 192.0.2.10 -Port 443
curl --connect-timeout 5 --max-time 15 --verbose https://192.0.2.10/
openssl s_client -connect 192.0.2.10:443 -verify_return_error -verify_ip 192.0.2.10 -showcerts
```

On Linux, the equivalent port check is:

```sh
ping -c 4 192.0.2.10
nc -vz -w 5 192.0.2.10 443
curl --connect-timeout 5 --max-time 15 --verbose https://192.0.2.10/
openssl s_client -connect 192.0.2.10:443 -verify_return_error -verify_ip 192.0.2.10 -showcerts
```

For a DNS hostname, use the hostname in the URL and test its SAN explicitly:

```sh
curl --connect-timeout 5 --max-time 15 --verbose https://bookstack.example.test/
openssl s_client -connect bookstack.example.test:443 -servername bookstack.example.test -verify_return_error -verify_hostname bookstack.example.test -showcerts
```

To inspect a certificate file and its SANs:

```sh
openssl x509 -in bookstack-certificate.pem -noout -fingerprint -sha256 -subject -ext subjectAltName
```

Before trusting a self-signed certificate, compare its SHA-256 fingerprint with the value obtained independently from the BookStack administrator over an authenticated channel (for example, a separately verified phone call or signed change record). Do not trust a fingerprint obtained only from the same connection being evaluated. Do not publish the real fingerprint.

Interpret the checks carefully:

- A ping timeout can mean ICMP is filtered; use the TCP port check and HTTPS checks for the actual service path.
- A TCP timeout or refusal on port 443 points to routing, firewall policy, or a service that is not listening. Ask the network/service owner to permit the narrow GLPI-to-BookStack TCP 443 flow and confirm the HTTPS service is running.
- If TCP connects but TLS fails, inspect certificate dates, chain trust, and SAN matching. `curl` and `openssl` run outside PHP may use a different trust store from PHP/cURL; validate with the runtime that GLPI uses as well.
- A BookStack API `401` indicates rejected/missing token credentials. `403` indicates the API token user lacks permission. `429` indicates rate limiting; wait and retry at a reasonable rate. These statuses show that an HTTP/API response was received, so investigate token, role, or request limits rather than firewall reachability.
- The plugin intentionally displays safe summaries and does not expose raw cURL diagnostics, response bodies, or secrets. Use server-side PHP/cURL diagnostics and BookStack/GLPI logs managed by administrators; do not add tokens or internal topology to public issue reports.

## Configuration procedure

1. **GLPI reaches the host but TCP 443 is blocked:** request a firewall rule whose source is the GLPI server and whose destination is the exact BookStack server IP, limited to TCP 443. Confirm the server listens on HTTPS and routing is in place. A private IP allowlist does not open a firewall port.
2. **TCP 443 is open but the certificate is not trusted:** install the appropriate CA certificate chain into the trust store used by PHP/cURL, then verify with `curl` and `openssl` without disabling validation. Confirm that the GLPI PHP runtime sees the same CA configuration.
3. **The certificate is self-signed but has a valid SAN:** independently verify its SHA-256 fingerprint, then install the verified trust anchor in the PHP/cURL trust store. Keep peer verification and SAN checks enabled.
4. **HTTPS succeeds but the plugin rejects the private destination:** enter each exact private IP returned by DNS in **Allowed private destination IPs**, one per line, then save the URL and settings. For an IP URL, allowlist that exact IP. For a DNS URL with several private A/AAAA records, allowlist each private address that DNS may return. Public answers do not need allowlisting; all answers are still validated. Do not enter a CIDR range. Reserved, loopback, link-local, metadata, and multicast addresses cannot be authorized.
5. **The server uses an internal CA:** prefer installing the organization-approved CA chain in the PHP/cURL system trust store and monitor its rotation. The plugin currently has no per-plugin CA file setting. A future per-plugin CA bundle would limit the trust change to this plugin, but would require securely managed file ownership, permissions, path validation, and certificate updates. A global trust-store change affects other software using that store; follow the host's change-control process. Neither approach should disable certificate verification.

After changing configuration, use **Check BookStack API** and the experimental search only with a read-only BookStack account. Real connectivity and certificate behavior must be validated in the separate GLPI test environment; simulated tests do not prove network access.
