<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Models\Client;
use App\Models\Shop;

/**
 * The counterparty for a till sale nobody named.
 *
 * pDaftar records a sale as a Debt, and a Debt needs a client — the app has
 * never had a sale without one because a shopkeeper writing in a daftar always
 * knows whose page it goes on. A POS does not: most sales are a stranger paying
 * cash and walking out.
 *
 * Rather than make `client_id` nullable across the debt/balance/SMS machinery,
 * each shop gets ONE house client that anonymous till sales land on. Its balance
 * naturally nets to zero for paid sales (debt raised, repayment recorded), so it
 * never appears in debtor reports, and the cash still reaches Kassa through the
 * ordinary repayment mirror.
 *
 * Never sell to this client on credit from the till — a nasiya sale must name a
 * real person, which is why PosSaleService requires an explicit client for
 * unpaid sales.
 */
class PosWalkInClientResolver {
    public const NAME = 'Naqd xaridor';

    public function resolve(Shop $shop, int $createdBy): Client {
        /** @var Client|null $existing */
        $existing = Client::query()
            ->where('shop_id', $shop->id)
            ->where('name', self::NAME)
            ->whereNull('phone_number')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Client::create([
            'shop_id' => $shop->id,
            'name' => self::NAME,
            'created_by' => $createdBy,
            // A house account must never text anyone: it has no phone, and a
            // shop that later types one in would start SMSing a stranger every
            // cash sale.
            'sending_sms' => false,
            'sending_telegram_message' => false,
        ]);
    }
}
