/**
 * Talking to pDaftar.
 *
 * Two credentials, two lifetimes, deliberately not interchangeable:
 *   - the USER token, held only long enough to register this device;
 *   - the TERMINAL token, which every POS call after that uses.
 *
 * The user token is dropped as soon as registration succeeds. Keeping it in
 * localStorage on a shared till would leave a credential that can do everything
 * the owner's phone can do sitting on a machine the whole shop touches.
 */

export const TERMINAL_TOKEN_KEY = 'pos.terminal_token'
export const DEVICE_ID_KEY = 'pos.device_id'

const POS_BASE = '/api/pos/v1'
const MOBILE_BASE = '/api/mobile'

export class ApiError extends Error {
  // Declared as fields rather than constructor parameter properties: the
  // scaffold enables `erasableSyntaxOnly`, which rejects the shorthand.
  readonly status: number
  readonly body: unknown

  constructor(message: string, status: number, body: unknown = null) {
    super(message)
    this.status = status
    this.body = body
  }
}

/** Thrown when the terminal's credential is gone or was revoked. */
export class AuthExpiredError extends ApiError {}

export function getTerminalToken(): string | null {
  return localStorage.getItem(TERMINAL_TOKEN_KEY)
}

export function setTerminalToken(token: string | null): void {
  if (token === null) localStorage.removeItem(TERMINAL_TOKEN_KEY)
  else localStorage.setItem(TERMINAL_TOKEN_KEY, token)
}

/**
 * This machine's identity, stable across reloads and reinstalls of the tab.
 *
 * The server keys terminals on (shop, device_id) so that re-registering reuses
 * the same row. A device_id regenerated on every load would consume a fresh
 * kassa slot each time the browser was reopened, and the shop would hit its
 * limit by lunchtime.
 */
export function getDeviceId(): string {
  let id = localStorage.getItem(DEVICE_ID_KEY)
  if (!id) {
    id = `web-${crypto.randomUUID()}`
    localStorage.setItem(DEVICE_ID_KEY, id)
  }
  return id
}

async function request<T>(
  url: string,
  options: RequestInit & { token?: string | null } = {},
): Promise<T> {
  const { token, headers, ...rest } = options

  const response = await fetch(url, {
    ...rest,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
  })

  const text = await response.text()
  let body: unknown = null
  try {
    body = text ? JSON.parse(text) : null
  } catch {
    body = text
  }

  if (!response.ok) {
    const message = extractMessage(body) ?? `Xatolik (${response.status})`

    // 401/403 from a POS route means the terminal token is dead — revoked from
    // another device, or the user was removed from the shop. Distinguished from
    // an ordinary error so the app can send the cashier back to login instead of
    // showing a toast they cannot act on.
    if (response.status === 401 || response.status === 403) {
      throw new AuthExpiredError(message, response.status, body)
    }

    throw new ApiError(message, response.status, body)
  }

  return body as T
}

function extractMessage(body: unknown): string | null {
  if (typeof body !== 'object' || body === null) return null
  const b = body as Record<string, unknown>
  if (typeof b.message === 'string') return b.message
  const data = b.data as Record<string, unknown> | undefined
  if (data && typeof data.error === 'string') return data.error
  return null
}

// ─── Registration (user token) ───

export type ShopSummary = { id: number; name: string; currency_id: number | null }

export async function login(phone: string, password: string): Promise<string> {
  const res = await request<{ data?: { token?: string }; token?: string }>(`${MOBILE_BASE}/login`, {
    method: 'POST',
    body: JSON.stringify({
      phone_number: phone,
      password,
      // The mobile login demands one. A till has no push channel, so it
      // identifies itself rather than sending a fake device token.
      fcm_token: 'pos-web',
    }),
  })

  const token = res.data?.token ?? res.token
  if (!token) throw new ApiError('Token qaytmadi', 500, res)
  return token
}

export async function fetchShops(userToken: string): Promise<ShopSummary[]> {
  const res = await request<{ data?: ShopSummary[] }>(`${MOBILE_BASE}/shops/user-shops`, {
    token: userToken,
  })
  return res.data ?? []
}

export type RegisterResult = {
  token: string
  terminal: { id: number; name: string; device_id: string; provider: string; shop_id: number }
  scopes: string[]
  kassa: { used: number; limit: number }
}

export async function registerTerminal(
  userToken: string,
  shopId: number,
  name: string,
): Promise<RegisterResult> {
  const res = await request<{ data: RegisterResult }>(`${POS_BASE}/terminals/register`, {
    method: 'POST',
    token: userToken,
    body: JSON.stringify({
      shop_id: shopId,
      device_id: getDeviceId(),
      name,
      provider: 'pdaftar_pos',
    }),
  })
  return res.data
}

// ─── POS calls (terminal token) ───

function pos<T>(path: string, options: RequestInit = {}): Promise<T> {
  return request<T>(`${POS_BASE}${path}`, { ...options, token: getTerminalToken() })
}

export type MeResponse = {
  terminal: { id: number; name: string; device_id: string; shop_id: number }
  user: { id: number; name: string | null; phone_number: string | null }
  shop: {
    id: number
    name: string
    currency_id: number | null
    allow_negative_stock: boolean
    low_stock_threshold: number | null
  }
  scopes: string[]
  kassa: { used: number; limit: number }
  server_time: string
}

export function fetchMe(): Promise<MeResponse> {
  return pos<{ data: MeResponse }>('/me').then((r) => r.data)
}

export type PullResponse = {
  server_time: string
  has_more: boolean
  next_since: string | null
  next_since_id: number | null
  data: Record<string, unknown[]>
}

export function pullCatalog(params: {
  since?: string | null
  sinceId?: number | null
  entities?: string[]
  limit?: number
}): Promise<PullResponse> {
  const q = new URLSearchParams()
  if (params.since) q.set('since', params.since)
  if (params.sinceId != null) q.set('since_id', String(params.sinceId))
  if (params.entities?.length) q.set('entities', params.entities.join(','))
  q.set('limit', String(params.limit ?? 500))

  return pos<PullResponse & { success: boolean }>(`/sync/pull?${q.toString()}`)
}

export type OperationResult = {
  client_operation_id: string
  type: string
  status: 'applied' | 'failed'
  replayed: boolean
  data: Record<string, unknown>
  error: string | null
}

export type PushEnvelope = {
  client_operation_id: string
  type: string
  occurred_at: string
  payload: unknown
}

export function pushOperations(operations: PushEnvelope[]): Promise<{
  results: OperationResult[]
  applied: number
  failed: number
  local_ids: Record<string, number>
}> {
  return pos<{ data: { results: OperationResult[]; applied: number; failed: number; local_ids: Record<string, number> } }>(
    '/sync/push',
    { method: 'POST', body: JSON.stringify({ operations }) },
  ).then((r) => r.data)
}

export function fetchSyncStatus(): Promise<{
  last_sync_at: string | null
  counts: { applied: number; failed: number; error: number }
  operations: Array<{ client_operation_id: string; type: string; status: string; error: string | null }>
}> {
  return pos<{ data: never }>('/sync/status').then((r) => r.data)
}

export function lookupByCode(code: string) {
  return pos<{ data: unknown }>(`/products/lookup?code=${encodeURIComponent(code)}`).then((r) => r.data)
}
