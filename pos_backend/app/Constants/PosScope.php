<?php

declare(strict_types=1);

namespace Pos\Constants;

/**
 * What a terminal token is allowed to do.
 *
 * These ride on the Sanctum token as abilities, so the check is
 * `$token->can('pos:sales.write')` — no new permission system. They sit ON TOP
 * of the user's own shop permissions: a token cannot grant its user something
 * the user does not already have, it can only narrow it. Both are checked.
 */
enum PosScope: string
{
    case CATALOG_READ = 'pos:catalog.read';
    case SALES_WRITE = 'pos:sales.write';
    case PRODUCTS_WRITE = 'pos:products.write';
    case CLIENTS_WRITE = 'pos:clients.write';
    case SUPPLIERS_WRITE = 'pos:suppliers.write';
    case STOCK_WRITE = 'pos:stock.write';
    case CASH_WRITE = 'pos:cash.write';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The scopes a provider may hold at most.
     *
     * A third-party till gets read + sell and nothing else, per the integration
     * rule agreed with the boss: they keep their own inventory, we keep ours,
     * and the only thing that crosses the boundary is the sale. Letting AliPOS
     * write stock movements into our ledger would mean two systems both
     * believing they own the same number, which is the failure mode this whole
     * design exists to avoid.
     *
     * @return string[]
     */
    public static function defaultFor(PosProvider $provider): array
    {
        if ($provider->isExternal()) {
            return [
                self::CATALOG_READ->value,
                self::SALES_WRITE->value,
                self::CLIENTS_WRITE->value,
            ];
        }

        return self::values();
    }
}
