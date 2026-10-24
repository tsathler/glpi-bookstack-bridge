# GLPI BookStack Bridge

**Read-only BookStack knowledge-base integration for GLPI 11, with explicit access controls and guarded API connectivity.**

> **Status:** M0 and M1.1 functionally validated on GLPI 11.0.4 with a real BookStack instance. Page-body reading, ticket linking, and multi-entity integration are **not complete**.

## At a glance

| Capability | Current state |
| --- | --- |
| Protected configuration and API check | Implemented and validated |
| Experimental search | Implemented; restricted to configuration administrators |
| Shelf, book, chapter, and page-title navigation | Implemented; follows GLPI Knowledge base read permission |
| Page content reader and ticket linking | Not implemented |
| Multi-entity persistence foundation | In progress; not connected to the UI |

## Integration overview

```text
Authorized GLPI user
        │ GLPI permissions
        ▼
GLPI 11 plugin ── HTTPS / read-only API ──► BookStack
        │
        └── guarded destination policy and protected credentials
```

**Security considerations:** HTTPS verification remains enabled; private IP destinations require an explicit exact-address allowlist. The dedicated BookStack API account determines the content scope available to authorized GLPI readers. Review [network configuration](docs/network-configuration.md) before connecting an internal destination.

---

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

---

## Português

**Plugin para GLPI 11 que integra a navegação na base de conhecimento do BookStack por API REST com acesso somente leitura.**

### Estado do projeto

| Funcionalidade | Estado |
| --- | --- |
| Configuração protegida e teste da API | Implementados e validados |
| Pesquisa experimental | Implementada; restrita a administradores de configuração |
| Navegação por estantes, livros, capítulos e títulos | Implementada; respeita a permissão de leitura da base de conhecimento no GLPI |
| Leitura do conteúdo de páginas e vínculos com chamados | Não implementados |
| Persistência por entidade | Base em desenvolvimento; ainda não integrada à interface |

M0 e M1.1 foram validados funcionalmente no GLPI 11.0.4 com uma instância real do BookStack. Os testes automatizados usam respostas simuladas e não substituem a validação de conectividade real.

### Segurança e requisitos

- GLPI 11.0.x, PHP 8.2+, extensões cURL e Sodium.
- Conta dedicada do BookStack com acesso à API e permissões mínimas de leitura.
- HTTPS com verificação de certificados. Endereços IP privados exigem liberação explícita de cada IP.
- Credenciais protegidas pelo mecanismo de configuração segura do GLPI.
- O escopo visível aos leitores do GLPI corresponde às permissões da conta de integração do BookStack.

### Instalação e validação

Copie os arquivos para `<GLPI_ROOT>/plugins/bookstackbridge`, instale e ative em **Configurar → Plugins** (ou equivalente no idioma da interface). Em **Ferramentas → Base de conhecimento BookStack**, configure a conexão com privilégios apropriados e execute a verificação da API.

```sh
php tests/run.php
```

Esse comando executa testes simulados. Para maiores detalhes, consulte [Arquitetura](docs/architecture.md), [Desenvolvimento](docs/development.md) e [Configuração de rede](docs/network-configuration.md).

**Limitações:** o leitor de conteúdo, a sanitização de HTML rico e a vinculação a chamados não estão prontos. Antes de desinstalar, faça backup do banco GLPI e da chave `glpicrypt.key`; a desinstalação remove configurações do plugin.
