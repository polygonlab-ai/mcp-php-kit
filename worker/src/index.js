/**
 * mcp-php-kit Worker: an optional Cloudflare Worker placed in front of an mcp-php-kit server.
 *
 *   client --HTTPS--> Worker --HTTPS + shared secret--> PHP server
 *
 * For every request: Origin check, POST only (OPTIONS for CORS preflight), rate limit per IP
 * address, bearer token compared in constant time, body size limit, then relay to ORIGIN_URL
 * with the shared secret header. One JSON log line per request, without token, body or IP.
 *
 * Configuration (wrangler.toml and secrets):
 *   MCP_TOKEN             secret: accepted bearer token(s), comma-separated for a rotation
 *   ORIGIN_SECRET         secret: shared value added to relayed requests (32 characters or more)
 *   ORIGIN_URL            var: URL of the PHP server, https
 *   ALLOWED_ORIGINS       var: browser origins allowed, comma-separated (empty: none)
 *   ORIGIN_SECRET_HEADER  var: header name of the shared secret (default X-Mcp-Origin-Secret)
 *   MAX_BODY_BYTES        var: largest body (default 1048576)
 *   FORWARD_AUTHORIZATION var: "true" to relay the Authorization header too (default: dropped)
 *   MCP_RATE_LIMITER      binding: Workers Rate Limiting API (optional, recommended)
 */

import {
  ConfigurationError,
  forwardedRequestHeaders,
  forwardedResponseHeaders,
  isAcceptedToken,
  normaliseOrigin,
  parseBearer,
  readConfig,
} from './security.js';

/** Window of the rate limiter, in seconds (must match the binding period). */
const RATE_LIMIT_PERIOD = 60;

/** Longest wait for the origin, in milliseconds. */
const ORIGIN_TIMEOUT_MS = 30000;

const ALLOWED_HEADERS = 'Content-Type, Accept, Authorization, MCP-Protocol-Version, Mcp-Method, Mcp-Name';

export default {
  /**
   * @param {Request} request
   * @param {Record<string, any>} env
   */
  async fetch(request, env) {
    return handleRequest(request, env);
  },
};

/**
 * Dependencies injected in tests.
 *
 * @typedef {object} Dependencies
 * @property {typeof fetch} [fetch] client used to reach the origin
 * @property {(line: string) => void} [log] log sink
 */

/**
 * @param {Request} request
 * @param {Record<string, any>} env
 * @param {Dependencies} [deps]
 * @returns {Promise<Response>}
 */
export async function handleRequest(request, env, deps = {}) {
  const fetchOrigin = deps.fetch ?? fetch;
  const log = deps.log ?? ((line) => console.log(line));
  const start = Date.now();
  const response = await processRequest(request, env, fetchOrigin, log);
  log(JSON.stringify({
    event: 'mcp_request',
    method: request.method,
    mcpMethod: request.headers.get('mcp-method'),
    status: response.status,
    durationMs: Date.now() - start,
  }));
  return response;
}

/**
 * @param {Request} request
 * @param {Record<string, any>} env
 * @param {typeof fetch} fetchOrigin
 * @param {(line: string) => void} log
 */
async function processRequest(request, env, fetchOrigin, log) {
  let config;
  try {
    config = readConfig(env);
  } catch (error) {
    if (error instanceof ConfigurationError) {
      log(JSON.stringify({ event: 'mcp_configuration_error', message: error.message }));
      return json(500, { error: 'server_misconfigured' });
    }
    throw error;
  }

  // 1. Origin: browsers only, against DNS rebinding and cross-site calls.
  const origin = request.headers.get('origin');
  const originAllowed = origin !== null && config.allowedOrigins.has(normaliseOrigin(origin));
  if (origin !== null && !originAllowed) {
    return json(403, { jsonrpc: '2.0', error: { code: -32600, message: 'Forbidden: origin not allowed' } });
  }
  const cors = (response) => withCors(response, originAllowed ? origin : null);

  // 2. HTTP method.
  if (request.method === 'OPTIONS') {
    const headers = new Headers({ allow: 'POST, OPTIONS' });
    if (originAllowed) {
      headers.set('access-control-allow-methods', 'POST, OPTIONS');
      headers.set('access-control-allow-headers', ALLOWED_HEADERS);
      headers.set('access-control-max-age', '600');
    }
    return cors(new Response(null, { status: 204, headers }));
  }
  if (request.method !== 'POST') {
    return cors(new Response(null, { status: 405, headers: { allow: 'POST, OPTIONS', 'cache-control': 'no-store' } }));
  }

  // 3. Rate limit per IP address, before the token check (slows down token guessing).
  if (env.MCP_RATE_LIMITER && typeof env.MCP_RATE_LIMITER.limit === 'function') {
    const key = request.headers.get('cf-connecting-ip') ?? 'unknown';
    const { success } = await env.MCP_RATE_LIMITER.limit({ key });
    if (!success) {
      return cors(json(429, { error: 'rate_limited' }, { 'retry-after': String(RATE_LIMIT_PERIOD) }));
    }
  }

  // 4. Bearer token, compared in constant time.
  const token = parseBearer(request.headers.get('authorization'));
  if (!(await isAcceptedToken(token, config.tokens))) {
    const challenge = token === null ? 'Bearer realm="mcp"' : 'Bearer realm="mcp", error="invalid_token"';
    return cors(json(401, { error: 'unauthorized' }, { 'www-authenticate': challenge }));
  }

  // 5. Body size.
  const declared = Number(request.headers.get('content-length') ?? '0');
  if (Number.isFinite(declared) && declared > config.maxBodyBytes) {
    return cors(json(413, { error: 'body_too_large' }));
  }
  const body = await request.arrayBuffer();
  if (body.byteLength > config.maxBodyBytes) {
    return cors(json(413, { error: 'body_too_large' }));
  }

  // 6. Relay to the origin, with the shared secret; redirects are never followed.
  let upstream;
  try {
    upstream = await fetchOrigin(config.originUrl, {
      method: 'POST',
      headers: forwardedRequestHeaders(request.headers, config),
      body,
      redirect: 'manual',
      signal: AbortSignal.timeout(ORIGIN_TIMEOUT_MS),
    });
  } catch {
    return cors(json(502, { jsonrpc: '2.0', error: { code: -32603, message: 'Origin server unreachable' } }));
  }
  if (upstream.status >= 300 && upstream.status < 400) {
    return cors(json(502, { jsonrpc: '2.0', error: { code: -32603, message: 'Unexpected redirect from the origin server' } }));
  }

  const status = upstream.status;
  const responseBody = status === 202 || status === 204 ? null : await upstream.arrayBuffer();
  return cors(new Response(responseBody, { status, headers: forwardedResponseHeaders(upstream.headers) }));
}

/**
 * @param {number} status
 * @param {unknown} body
 * @param {Record<string, string>} [headers]
 */
function json(status, body, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', 'cache-control': 'no-store', 'x-content-type-options': 'nosniff', ...headers },
  });
}

/**
 * @param {Response} response
 * @param {string | null} origin allowed origin, or null
 */
function withCors(response, origin) {
  if (origin === null) {
    return response;
  }
  const headers = new Headers(response.headers);
  headers.set('access-control-allow-origin', origin);
  headers.set('vary', 'Origin');
  return new Response(response.body, { status: response.status, headers });
}
