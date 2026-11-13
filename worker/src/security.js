/**
 * Pure functions of the Worker: configuration, origin and token checks, header filtering.
 * They only use Web standard APIs (Request, Headers, URL, crypto.subtle), available both in
 * the Workers runtime and in Node.js, where the tests run.
 */

/** Header added to every relayed request, checked by SharedSecretAuthenticator on the PHP side. */
export const DEFAULT_SECRET_HEADER = 'X-Mcp-Origin-Secret';

/** Largest relayed body, in bytes (1 MiB), unless MAX_BODY_BYTES says otherwise. */
export const DEFAULT_MAX_BODY_BYTES = 1048576;

/**
 * Request headers relayed to the origin; every other header (cookies included) is dropped.
 * Origin is checked here and not relayed: the PHP server, reachable only with the shared
 * secret, does not need its own list of allowed origins.
 */
const FORWARDED_REQUEST_HEADERS = ['content-type', 'accept', 'mcp-protocol-version', 'mcp-method', 'mcp-name', 'user-agent'];

/** Response headers relayed back to the client. */
const FORWARDED_RESPONSE_HEADERS = ['content-type', 'www-authenticate', 'allow', 'retry-after', 'cache-control'];

const SHORTEST_TOKEN = 16;
const SHORTEST_SECRET = 32;

export class ConfigurationError extends Error {}

/**
 * Reads and validates the Worker configuration. Throws ConfigurationError when it is unusable:
 * the Worker then refuses every request (fail closed).
 *
 * @param {Record<string, unknown>} env
 */
export function readConfig(env) {
  const tokens = splitList(env.MCP_TOKEN);
  if (tokens.length === 0 || tokens.some((token) => token.length < SHORTEST_TOKEN)) {
    throw new ConfigurationError(`MCP_TOKEN must hold one or more tokens of at least ${SHORTEST_TOKEN} characters`);
  }

  let originUrl;
  try {
    originUrl = new URL(String(env.ORIGIN_URL ?? ''));
  } catch {
    throw new ConfigurationError('ORIGIN_URL must be an absolute URL');
  }
  const local = originUrl.hostname === 'localhost' || originUrl.hostname === '127.0.0.1';
  if (originUrl.protocol !== 'https:' && !(local && originUrl.protocol === 'http:')) {
    throw new ConfigurationError('ORIGIN_URL must use https (http is accepted for localhost only)');
  }
  if (originUrl.username !== '' || originUrl.password !== '') {
    throw new ConfigurationError('ORIGIN_URL must not contain credentials');
  }

  const originSecret = typeof env.ORIGIN_SECRET === 'string' ? env.ORIGIN_SECRET.trim() : '';
  if (originSecret.length < SHORTEST_SECRET) {
    throw new ConfigurationError(`ORIGIN_SECRET must contain at least ${SHORTEST_SECRET} characters`);
  }

  const secretHeader = typeof env.ORIGIN_SECRET_HEADER === 'string' && env.ORIGIN_SECRET_HEADER !== ''
    ? env.ORIGIN_SECRET_HEADER
    : DEFAULT_SECRET_HEADER;
  if (!/^[A-Za-z0-9-]+$/.test(secretHeader)) {
    throw new ConfigurationError('ORIGIN_SECRET_HEADER must be a valid header name');
  }

  const maxBodyBytes = env.MAX_BODY_BYTES === undefined ? DEFAULT_MAX_BODY_BYTES : Number(env.MAX_BODY_BYTES);
  if (!Number.isInteger(maxBodyBytes) || maxBodyBytes < 1024) {
    throw new ConfigurationError('MAX_BODY_BYTES must be an integer of at least 1024');
  }

  return {
    tokens,
    originUrl: originUrl.toString(),
    originSecret,
    secretHeader,
    allowedOrigins: new Set(splitList(env.ALLOWED_ORIGINS).map(normaliseOrigin)),
    maxBodyBytes,
    forwardAuthorization: env.FORWARD_AUTHORIZATION === 'true',
  };
}

/**
 * Compares two strings in constant time: both are hashed (SHA-256) first, so that neither
 * their content nor their length changes the duration of the comparison.
 *
 * @param {string} given
 * @param {string} expected
 * @returns {Promise<boolean>}
 */
export async function timingSafeEqualStrings(given, expected) {
  const encoder = new TextEncoder();
  const [a, b] = await Promise.all([
    crypto.subtle.digest('SHA-256', encoder.encode(given)),
    crypto.subtle.digest('SHA-256', encoder.encode(expected)),
  ]);
  const left = new Uint8Array(a);
  const right = new Uint8Array(b);
  // Workers runtime: native constant-time comparison.
  if (typeof crypto.subtle.timingSafeEqual === 'function') {
    return crypto.subtle.timingSafeEqual(left, right);
  }
  let difference = 0;
  for (let i = 0; i < left.length; i++) {
    difference |= left[i] ^ right[i];
  }
  return difference === 0;
}

/**
 * True when the token matches one of the accepted tokens. Every token is compared, without
 * stopping at the first match.
 *
 * @param {string | null} token
 * @param {string[]} accepted
 */
export async function isAcceptedToken(token, accepted) {
  if (token === null) {
    return false;
  }
  const results = await Promise.all(accepted.map((candidate) => timingSafeEqualStrings(token, candidate)));
  return results.reduce((found, result) => found || result, false);
}

/**
 * Token of an `Authorization: Bearer <token>` header, or null.
 *
 * @param {string | null} header
 */
export function parseBearer(header) {
  if (header === null) {
    return null;
  }
  const match = /^\s*Bearer\s+(\S+)\s*$/i.exec(header);
  return match === null ? null : match[1];
}

/**
 * @param {string} origin
 */
export function normaliseOrigin(origin) {
  return origin.trim().replace(/\/+$/, '').toLowerCase();
}

/**
 * Headers relayed to the origin: the allow-listed ones, the `Mcp-Param-*` headers, the shared
 * secret, and `Authorization` only when FORWARD_AUTHORIZATION is "true".
 *
 * @param {Headers} incoming
 * @param {ReturnType<typeof readConfig>} config
 */
export function forwardedRequestHeaders(incoming, config) {
  const headers = new Headers();
  for (const [name, value] of incoming) {
    if (FORWARDED_REQUEST_HEADERS.includes(name) || name.startsWith('mcp-param-')) {
      headers.set(name, value);
    }
  }
  if (config.forwardAuthorization && incoming.has('authorization')) {
    headers.set('authorization', incoming.get('authorization'));
  }
  headers.set(config.secretHeader, config.originSecret);
  return headers;
}

/**
 * Headers of the origin response relayed to the client, plus the security headers.
 *
 * @param {Headers} upstream
 */
export function forwardedResponseHeaders(upstream) {
  const headers = new Headers();
  for (const name of FORWARDED_RESPONSE_HEADERS) {
    const value = upstream.get(name);
    if (value !== null) {
      headers.set(name, value);
    }
  }
  if (!headers.has('cache-control')) {
    headers.set('cache-control', 'no-store');
  }
  headers.set('x-content-type-options', 'nosniff');
  return headers;
}

/**
 * @param {unknown} value comma-separated list
 * @returns {string[]}
 */
function splitList(value) {
  if (typeof value !== 'string') {
    return [];
  }
  return value.split(',').map((item) => item.trim()).filter((item) => item !== '');
}
