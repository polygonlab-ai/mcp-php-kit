# Changelog

All notable changes to this repository are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-13 - First release: MCP server over Streamable HTTP, tools, bearer auth, Cloudflare Worker

### Added

- MCP protocol revision 2026-07-28 (stateless requests with `_meta`, `server/discover`), and revisions 2025-11-25 and 2025-06-18 through the `initialize` handshake, with version negotiation.
- JSON-RPC 2.0: requests, notifications, standard error codes, MCP error codes `-32020` (header mismatch) and `-32022` (unsupported protocol version).
- Streamable HTTP transport over POST with JSON responses: `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` and `Mcp-Param-*` header checks, `Origin` allow-list, CORS preflight, body size limit.
- `tools/list` with cursor pagination and caching hints, `tools/call` with `isError` results and structured content.
- Tool declaration with the `#[Tool]` and `#[Param]` attributes, or as callables; input schema generated from the parameter types, or provided explicitly.
- Validation of the arguments against the JSON Schema (2020-12 subset) before every call.
- `BearerTokenAuthenticator` (constant-time comparison, `WWW-Authenticate` challenge), `SharedSecretAuthenticator`, `RequireAll`.
- Optional logging through the `Logger` interface, with `CallableLogger` for any PSR-3 logger; arguments and tokens are never logged.
- Optional Cloudflare Worker: bearer token, rate limit per IP address, filtered relay with a shared secret, one log line per request.
- Examples `examples/minimal/` and `examples/attributes/`, tested over real HTTP.

[0.1.0]: https://github.com/polygonlab-ai/mcp-php-kit/releases/tag/v0.1.0

---
— Conçu et développé par PolygonLab · polygonlab.ai · Licence MIT
