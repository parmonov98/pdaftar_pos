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

export type AuthResult = {
  token: string
  user: { id: number; name: string; phone_number: string; linked_to_pdaftar: boolean }
  shops: ShopSummary[]
}

/**
 * Sign in to the POS.
 *
 * This used to post to pDaftar's /api/mobile/login. That only worked while the
 * till was served from the same origin as pDaftar's API; the POS now runs on
 * its own server with its own database, where that route does not exist and a
 * pDaftar token could not be validated anyway.
 *
 * The response carries the shops too, so the shop step no longer costs a second
 * round trip — which matters on a till opening at 8am on shop wifi.
 */
export async function login(phone: string, password: string): Promise<AuthResult> {
  const res = await request<{ data: AuthResult }>(`${POS_BASE}/auth/login`, {
    method: 'POST',
    body: JSON.stringify({
      phone_number: normalizePhone(phone),
      password,
    }),
  })

  if (!res.data?.token) throw new ApiError('Token qaytmadi', 500, res)
  return res.data
}

/**
 * Create an account and its first shop.
 *
 * The POS is its own product: someone who has never heard of pDaftar can open
 * a till with it. Registering returns a signed-in session, because making
 * someone register and then immediately log in with what they just typed is a
 * step that exists only for the server's convenience.
 */
export async function register(input: {
  name: string
  phone: string
  password: string
  shopName: string
}): Promise<AuthResult> {
  const res = await request<{ data: AuthResult }>(`${POS_BASE}/auth/register`, {
    method: 'POST',
    body: JSON.stringify({
      name: input.name.trim(),
      phone_number: normalizePhone(input.phone),
      password: input.password,
      shop_name: input.shopName.trim(),
    }),
  })

  if (!res.data?.token) throw new ApiError('Token qaytmadi', 500, res)
  return res.data
}

/**
 * Re-read the signed-in USER's session.
 *
 * Named apart from fetchMe() below, which answers for the TERMINAL: two
 * different subjects, and conflating them is how a seller's shop list ends up
 * scoped to one till.
 */
export async function fetchAccount(userToken: string): Promise<AuthResult> {
  const res = await request<{ data: Omit<AuthResult, 'token'> }>(`${POS_BASE}/auth/me`, {
    token: userToken,
  })
  return { token: userToken, ...res.data }
}

export async function fetchShops(userToken: string): Promise<ShopSummary[]> {
  return (await fetchAccount(userToken)).shops
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

  // Unwrapped, like every other call here. Without the `.data` the till reads
  // `next_since` off the envelope instead of the payload, stores an empty
  // cursor, and hands absorb() the envelope — so a pull that returned a full
  // catalogue quietly saves nothing and reports success.
  return pos<{ data: PullResponse }>(`/sync/pull?${q.toString()}`).then((r) => r.data)
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

export type RecentSale = {
  /**
   * Which table the sale lives in, and therefore what it means:
   * `income` — paid at the counter, money in Kassa, nobody owes anything.
   * `debt`   — nasiya, the customer owes it.
   */
  kind: 'income' | 'debt'
  id: number
  total: number
  paid_amount: number
  is_credit: boolean
  discount_amount: number
  currency_id: number | null
  payment_type: string | null
  client_name: string | null
  client_phone: string | null
  seller_name: string | null
  is_cancelled: boolean
  /** Kassa's free-text line: products, quantities, discount. */
  description: string | null
  created_at: string | null
  items: Array<{ product_id: number; name: string | null; quantity: number | null; total: number }>
}

export function fetchRecentSales(params: { limit?: number; mine?: boolean } = {}): Promise<RecentSale[]> {
  const q = new URLSearchParams()
  q.set('limit', String(params.limit ?? 50))
  if (params.mine) q.set('mine', '1')

  return pos<{ data: RecentSale[] }>(`/sales/recent?${q.toString()}`).then((r) => r.data)
}

export type ProductInput = {
  name: string
  barcode?: string | null
  code?: string | null
  price?: number | null
  unit_id?: number | null
  currency_id?: number | null
  /** Absent = stock not tracked. NOT the same as 0 — see the backend. */
  quantity?: number | null
  low_stock_threshold?: number | null
}

/**
 * Create a product.
 *
 * Online only, deliberately. The cart addresses products by the server's id,
 * and a product created offline has none until it syncs — so it could be put
 * in a basket that then names something the server has never heard of.
 * Selling stays fully offline; adding to the catalogue is a back-office job
 * that can wait for a signal.
 */
export function createProduct(input: ProductInput): Promise<{ id: number }> {
  return pos<{ data: { data: { product: { id: number } } } }>('/products', {
    method: 'POST',
    body: JSON.stringify({ client_operation_id: crypto.randomUUID(), ...input }),
  }).then((r) => r.data.data.product)
}

export function updateProduct(id: number, input: Partial<ProductInput>): Promise<{ id: number }> {
  return pos<{ data: { data: { product: { id: number } } } }>('/products', {
    method: 'PATCH',
    body: JSON.stringify({ client_operation_id: crypto.randomUUID(), id, ...input }),
  }).then((r) => r.data.data.product)
}

export function lookupByCode(code: string) {
  return pos<{ data: unknown }>(`/products/lookup?code=${encodeURIComponent(code)}`).then((r) => r.data)
}
