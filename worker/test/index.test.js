import { describe, expect, it, vi } from 'vitest';
import worker, { handleRequest } from '../src/index.js';

const TOKEN = 'first-worker-test-token';
const SHARED = 'shared-value-between-worker-and-origin-server';
const ENV = {
  MCP_TOKEN: TOKEN,
  ORIGIN_URL: 'https://origin.example.com/mcp/index.php',
  ORIGIN_SECRET: SHARED,
  ALLOWED_ORIGINS: 'https://app.example.com',
};
const BODY = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}';

function mcpRequest(headers = {}, init = {}) {
  return new Request('https://mcp.example.com/', {
    method: 'POST',
    body: BODY,
    ...init,
    headers: {
      'content-type': 'application/json',
      accept: 'application/json, text/event-stream',
      authorization: `Bearer ${TOKEN}`,
      'mcp-protocol-version': '2026-07-28',
      'mcp-method': 'tools/list',
      'cf-connecting-ip': '192.0.2.10',
      ...headers,
    },
  });
}

function origin(status = 200, body = '{"jsonrpc":"2.0","id":1,"result":{}}', headers = { 'content-type': 'application/json' }) {
  return vi.fn(async () => new Response(body, { status, headers }));
}

async function run(request, env = ENV, fetch = origin()) {
  const lines = [];
  const response = await handleRequest(request, env, { fetch, log: (line) => lines.push(line) });
  return { response, fetch, lines };
}

describe('relay', () => {
  it('relays an authenticated request with the shared secret', async () => {
    const { response, fetch } = await run(mcpRequest());

    expect(response.status).toBe(200);
    expect(await response.text()).toBe('{"jsonrpc":"2.0","id":1,"result":{}}');
    expect(response.headers.get('x-content-type-options')).toBe('nosniff');
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, init] = fetch.mock.calls[0];
    expect(url).toBe('https://origin.example.com/mcp/index.php');
    expect(init.method).toBe('POST');
    expect(init.redirect).toBe('manual');
    expect(new TextDecoder().decode(init.body)).toBe(BODY);
    expect(init.headers.get('x-mcp-origin-secret')).toBe(SHARED);
    expect(init.headers.get('authorization')).toBeNull();
    expect(init.headers.get('cf-connecting-ip')).toBeNull();
    expect(init.headers.get('mcp-method')).toBe('tools/list');
  });

  it('relays a 202 without body', async () => {
    const { response } = await run(mcpRequest(), ENV, vi.fn(async () => new Response(null, { status: 202 })));

    expect(response.status).toBe(202);
    expect(await response.text()).toBe('');
  });

  it('relays the errors of the origin', async () => {
    const { response } = await run(mcpRequest(), ENV, origin(400, '{"jsonrpc":"2.0","id":1,"error":{"code":-32022,"message":"Unsupported protocol version"}}'));

    expect(response.status).toBe(400);
    expect(await response.json()).toMatchObject({ error: { code: -32022 } });
  });

  it('answers 502 when the origin is unreachable', async () => {
    const { response } = await run(mcpRequest(), ENV, vi.fn(async () => { throw new TypeError('network'); }));

    expect(response.status).toBe(502);
    expect(await response.json()).toMatchObject({ error: { code: -32603 } });
  });

  it('never follows a redirect of the origin', async () => {
    const { response } = await run(mcpRequest(), ENV, origin(302, '', { location: 'https://elsewhere.example.com/' }));

    expect(response.status).toBe(502);
    expect(response.headers.get('location')).toBeNull();
  });

  it('logs one line without token, body or IP address', async () => {
    const { lines } = await run(mcpRequest());

    expect(lines).toHaveLength(1);
    expect(JSON.parse(lines[0])).toMatchObject({ event: 'mcp_request', method: 'POST', mcpMethod: 'tools/list', status: 200 });
    expect(lines[0]).not.toContain(TOKEN);
    expect(lines[0]).not.toContain('192.0.2.10');
    expect(lines[0]).not.toContain('jsonrpc');
  });

  it('is exported as a module Worker', async () => {
    expect(typeof worker.fetch).toBe('function');
  });
});

describe('authentication', () => {
  it('refuses a request without token', async () => {
    const { response, fetch } = await run(mcpRequest({ authorization: '' }));

    expect(response.status).toBe(401);
    expect(response.headers.get('www-authenticate')).toBe('Bearer realm="mcp"');
    expect(fetch).not.toHaveBeenCalled();
  });

  it('refuses a wrong token', async () => {
    const { response, fetch } = await run(mcpRequest({ authorization: 'Bearer not-the-right-token' }));

    expect(response.status).toBe(401);
    expect(response.headers.get('www-authenticate')).toBe('Bearer realm="mcp", error="invalid_token"');
    expect(fetch).not.toHaveBeenCalled();
  });

  it('fails closed when the configuration is unusable', async () => {
    const { response, fetch, lines } = await run(mcpRequest(), { ...ENV, ORIGIN_SECRET: undefined });

    expect(response.status).toBe(500);
    expect(await response.json()).toEqual({ error: 'server_misconfigured' });
    expect(fetch).not.toHaveBeenCalled();
    expect(lines.join('\n')).toContain('ORIGIN_SECRET');
  });
});

describe('rate limit', () => {
  it('answers 429 when the limiter refuses, keyed by IP address', async () => {
    const limit = vi.fn(async () => ({ success: false }));
    const { response, fetch } = await run(mcpRequest(), { ...ENV, MCP_RATE_LIMITER: { limit } });

    expect(response.status).toBe(429);
    expect(response.headers.get('retry-after')).toBe('60');
    expect(limit).toHaveBeenCalledWith({ key: '192.0.2.10' });
    expect(fetch).not.toHaveBeenCalled();
  });

  it('limits before checking the token', async () => {
    const limit = vi.fn(async () => ({ success: false }));
    const { response } = await run(mcpRequest({ authorization: 'Bearer guessed-token-value' }), { ...ENV, MCP_RATE_LIMITER: { limit } });

    expect(response.status).toBe(429);
  });

  it('relays when the limiter allows the request', async () => {
    const limit = vi.fn(async () => ({ success: true }));
    const { response } = await run(mcpRequest(), { ...ENV, MCP_RATE_LIMITER: { limit } });

    expect(response.status).toBe(200);
  });
});

describe('HTTP method, origin and size', () => {
  it.each(['GET', 'DELETE', 'PUT'])('refuses %s', async (method) => {
    const { response, fetch } = await run(new Request('https://mcp.example.com/', { method }));

    expect(response.status).toBe(405);
    expect(response.headers.get('allow')).toBe('POST, OPTIONS');
    expect(fetch).not.toHaveBeenCalled();
  });

  it('answers the preflight of an allowed origin', async () => {
    const { response } = await run(new Request('https://mcp.example.com/', { method: 'OPTIONS', headers: { origin: 'https://app.example.com' } }));

    expect(response.status).toBe(204);
    expect(response.headers.get('access-control-allow-origin')).toBe('https://app.example.com');
    expect(response.headers.get('access-control-allow-methods')).toBe('POST, OPTIONS');
  });

  it('refuses another origin', async () => {
    const { response, fetch } = await run(mcpRequest({ origin: 'https://evil.example.net' }));

    expect(response.status).toBe(403);
    expect(fetch).not.toHaveBeenCalled();
  });

  it('adds CORS headers for an allowed origin', async () => {
    const { response } = await run(mcpRequest({ origin: 'https://app.example.com' }));

    expect(response.status).toBe(200);
    expect(response.headers.get('access-control-allow-origin')).toBe('https://app.example.com');
    expect(response.headers.get('vary')).toBe('Origin');
  });

  it('refuses a body larger than the limit', async () => {
    const big = mcpRequest({}, { body: 'x'.repeat(2048) });
    const { response, fetch } = await run(big, { ...ENV, MAX_BODY_BYTES: '1024' });

    expect(response.status).toBe(413);
    expect(fetch).not.toHaveBeenCalled();
  });
});
