import { describe, expect, it } from 'vitest';
import {
  ConfigurationError,
  DEFAULT_SECRET_HEADER,
  forwardedRequestHeaders,
  forwardedResponseHeaders,
  isAcceptedToken,
  parseBearer,
  readConfig,
  timingSafeEqualStrings,
} from '../src/security.js';

const ENV = {
  MCP_TOKEN: 'first-worker-test-token',
  ORIGIN_URL: 'https://origin.example.com/mcp/index.php',
  ORIGIN_SECRET: 'shared-value-between-worker-and-origin-server',
};

describe('readConfig', () => {
  it('reads a valid configuration with its defaults', () => {
    const config = readConfig({ ...ENV, ALLOWED_ORIGINS: 'https://App.example.com/, http://localhost:5173' });

    expect(config.tokens).toEqual(['first-worker-test-token']);
    expect(config.originUrl).toBe('https://origin.example.com/mcp/index.php');
    expect(config.secretHeader).toBe(DEFAULT_SECRET_HEADER);
    expect(config.maxBodyBytes).toBe(1048576);
    expect(config.forwardAuthorization).toBe(false);
    expect([...config.allowedOrigins]).toEqual(['https://app.example.com', 'http://localhost:5173']);
  });

  it('accepts several tokens for a rotation', () => {
    expect(readConfig({ ...ENV, MCP_TOKEN: 'first-worker-test-token, second-worker-test-token' }).tokens).toHaveLength(2);
  });

  it('accepts plain http for localhost only', () => {
    expect(readConfig({ ...ENV, ORIGIN_URL: 'http://localhost:8080/' }).originUrl).toBe('http://localhost:8080/');
    expect(() => readConfig({ ...ENV, ORIGIN_URL: 'http://origin.example.com/' })).toThrow(ConfigurationError);
  });

  it.each([
    ['token missing', { MCP_TOKEN: undefined }],
    ['token too short', { MCP_TOKEN: 'short' }],
    ['origin URL missing', { ORIGIN_URL: undefined }],
    ['origin URL relative', { ORIGIN_URL: '/mcp' }],
    // Built from parts, so that the source never contains a URL with credentials.
    ['origin URL with credentials', { ORIGIN_URL: ['https://', 'user', ':', 'pw', '@', 'origin.example.com/'].join('') }],
    ['origin secret missing', { ORIGIN_SECRET: undefined }],
    ['origin secret too short', { ORIGIN_SECRET: 'too-short' }],
    ['bad secret header', { ORIGIN_SECRET_HEADER: 'X Header' }],
    ['bad body limit', { MAX_BODY_BYTES: '12' }],
  ])('refuses an unusable configuration: %s', (_label, override) => {
    expect(() => readConfig({ ...ENV, ...override })).toThrow(ConfigurationError);
  });
});

describe('token comparison', () => {
  it('compares strings through their digests', async () => {
    expect(await timingSafeEqualStrings('same-value', 'same-value')).toBe(true);
    expect(await timingSafeEqualStrings('same-value', 'same-valuE')).toBe(false);
    expect(await timingSafeEqualStrings('short', 'a much longer value')).toBe(false);
    expect(await timingSafeEqualStrings('', '')).toBe(true);
  });

  it('checks every accepted token', async () => {
    const accepted = ['first-worker-test-token', 'second-worker-test-token'];

    expect(await isAcceptedToken('second-worker-test-token', accepted)).toBe(true);
    expect(await isAcceptedToken('third-worker-test-token', accepted)).toBe(false);
    expect(await isAcceptedToken(null, accepted)).toBe(false);
  });

  it('parses bearer credentials', () => {
    expect(parseBearer('Bearer abc')).toBe('abc');
    expect(parseBearer('bearer   abc  ')).toBe('abc');
    expect(parseBearer('Basic abc')).toBeNull();
    expect(parseBearer('Bearer a b')).toBeNull();
    expect(parseBearer(null)).toBeNull();
  });
});

describe('header filtering', () => {
  it('relays the MCP headers and the shared secret only', () => {
    const config = readConfig(ENV);
    const headers = forwardedRequestHeaders(new Headers({
      'content-type': 'application/json',
      accept: 'application/json, text/event-stream',
      'mcp-protocol-version': '2026-07-28',
      'mcp-method': 'tools/call',
      'mcp-name': 'add',
      'mcp-param-region': 'eu',
      authorization: 'Bearer first-worker-test-token',
      cookie: 'session=1',
      origin: 'https://app.example.com',
      'x-mcp-origin-secret': 'forged-by-the-client',
    }), config);

    expect(Object.fromEntries(headers)).toEqual({
      'content-type': 'application/json',
      accept: 'application/json, text/event-stream',
      'mcp-protocol-version': '2026-07-28',
      'mcp-method': 'tools/call',
      'mcp-name': 'add',
      'mcp-param-region': 'eu',
      'x-mcp-origin-secret': 'shared-value-between-worker-and-origin-server',
    });
  });

  it('relays Authorization when asked to', () => {
    const config = readConfig({ ...ENV, FORWARD_AUTHORIZATION: 'true' });
    const headers = forwardedRequestHeaders(new Headers({ authorization: 'Bearer first-worker-test-token' }), config);

    expect(headers.get('authorization')).toBe('Bearer first-worker-test-token');
  });

  it('keeps the useful response headers and adds the security ones', () => {
    const headers = forwardedResponseHeaders(new Headers({
      'content-type': 'application/json',
      'set-cookie': 'a=b',
      server: 'Apache',
      'www-authenticate': 'Bearer realm="mcp"',
    }));

    expect(Object.fromEntries(headers)).toEqual({
      'cache-control': 'no-store',
      'content-type': 'application/json',
      'www-authenticate': 'Bearer realm="mcp"',
      'x-content-type-options': 'nosniff',
    });
  });
});
