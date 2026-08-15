/**
 * Talking to pDaftar.
 *
 * Two credentials, two lifetimes, deliberately not interchangeable:
 *   - the USER token, held only long enough to hand over this device;
 *   - the DEVICE token, which every POS call after that uses.
 *
 * The user token is dropped as soon as the handshake succeeds. Keeping it in
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
 * The server keys devices on (shop, device_id) so re-registering reuses the same
 * row. A device_id regenerated on every load would leave a trail of dead device
 * records in the owner's list, one per browser restart.
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

/**
 * Put a typed phone number into the shape pDaftar stores.
 *
 * Numbers are stored WITH the leading `+`, and a lookup is an exact string
 * match — so `998905650500`, `90 565 05 00` and `+998 (90) 565-05-00` are all
 * the same human and all fail to log in as typed. A cashier retyping their
 * number three different ways and being told "user not found" each time has no
 * way to guess that the spaces are the problem.
 */
export function normalizePhone(input: string): string {
  const digits = input.replace(/\D/g, '')

  if (digits === '') return ''
  // 9 national digits — the country code was left off entirely.
  if (digits.length === 9) return `+998${digits}`
  if (digits.startsWith('998')) return `+${digits}`

  return `+${digits}`
}

export async function login(phone: string, password: string): Promise<string> {
  const res = await request<{ data?: { token?: string }; token?: string }>(`${MOBILE_BASE}/login`, {
    method: 'POST',
    body: JSON.stringify({
      phone_number: normalizePhone(phone),
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

export type TerminalSummary = {
  id: number
  name: string
  device_id: string
  provider: string
  shop_id: number
  is_active: boolean
  last_seen_at: string | null
  last_sync_at: string | null
}

export type RegisterResult = {
  token: string
  terminal: TerminalSummary
  scopes: string[]
}

export async function fetchShopTerminals(
  userToken: string,
  shopId: number,
): Promise<{ terminals: TerminalSummary[]; devices: number }> {
  const res = await request<{ data: TerminalSummary[]; meta: { devices: number } }>(
    `${POS_BASE}/terminals/manage?shop_id=${shopId}`,
    { token: userToken },
  )
  return { terminals: res.data, devices: res.meta.devices }
}

export async function revokeTerminal(userToken: string, terminalId: number): Promise<void> {
  await request(`${POS_BASE}/terminals/manage/${terminalId}`, {
    method: 'DELETE',
    token: userToken,
  })
}

/**
 * Silent device handshake, run straight after login.
 *
 * The seller never sees this and it can never refuse them: there is no kassa to
 * create and no quota to hit. It exists so offline operation ids are scoped per
 * device and a sale can be attributed to the machine it was rung up on.
 */
export async function registerTerminal(
  userToken: string,
  shopId: number,
): Promise<RegisterResult> {
    const res = await request<{ data: RegisterResult }>(`${POS_BASE}/terminals/register`, {
      method: 'POST',
      token: userToken,
      body: JSON.stringify({
        shop_id: shopId,
        device_id: getDeviceId(),
        // No name: the server derives "Anvar · Chrome" from who signed in and
        // what they signed in on. Asking a seller to name a till is exactly
        // the step this design removes.
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
