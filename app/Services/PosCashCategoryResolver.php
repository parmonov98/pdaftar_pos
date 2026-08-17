<?php

declare(strict_types=1);

namespace Pos\Services;

use App\Constants\ShopExpenseCategory\ShopExpenseCategoryTypeEnum;
use App\Models\ShopExpenseCategory;
use App\Models\ShopIncomeCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where a till's kirim/chiqim lands when the cashier picked no category.
 *
 * `shop_incomes.shop_income_category_id` and its expense twin are NOT NULL, and
 * a brand-new shop has no categories at all — so a till that simply posts an
 * amount would hit a constraint violation, which is exactly what the first
 * end-to-end run of this API did.
 *
 * The fallback is a POS-specific bucket, deliberately NOT the existing
 * `is_sales_default` / `is_supplier_default` categories. Those two are the
 * targets of the automatic Kassa mirrors (client repayments, supplier chiqim);
 * dropping manual till cash into them would silently inflate reports that are
 * supposed to mean "money that came in from clients" and "money paid to
 * suppliers".
 *
 * A till that DOES send a category_id is honoured — and validated against the
 * shop, because an id from another shop would file a sale's cash under a
 * stranger's books.
 */
class PosCashCategoryResolver {
    private const INCOME_NAME = 'Kassa (POS)';

    private const EXPENSE_NAME = 'Kassa (POS)';

    public function income(int $shopId, mixed $requestedId): int {
        if ($requestedId !== null) {
            return $this->assertOwned(
                ShopIncomeCategory::query()->where('shop_id', $shopId)->whereKey((int) $requestedId)->value('id'),
                (int) $requestedId,
            );
        }

        return DB::transaction(function () use ($shopId) {
            $existing = ShopIncomeCategory::query()
                ->where('shop_id', $shopId)
                ->where('name', self::INCOME_NAME)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            return ShopIncomeCategory::create([
                'shop_id' => $shopId,
                'name' => self::INCOME_NAME,
                'color' => '#2E7D32',
            ])->id;
        });
    }

    public function expense(int $shopId, mixed $requestedId): int {
        if ($requestedId !== null) {
            return $this->assertOwned(
                ShopExpenseCategory::query()->where('shop_id', $shopId)->whereKey((int) $requestedId)->value('id'),
                (int) $requestedId,
            );
        }

        return DB::transaction(function () use ($shopId) {
            $existing = ShopExpenseCategory::query()
                ->where('shop_id', $shopId)
                ->where('name', self::EXPENSE_NAME)
                ->where('type', ShopExpenseCategoryTypeEnum::EXPENCE->value)
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            return ShopExpenseCategory::create([
                'shop_id' => $shopId,
                'name' => self::EXPENSE_NAME,
                'type' => ShopExpenseCategoryTypeEnum::EXPENCE->value,
                'color' => '#C62828',
            ])->id;
        });
    }

    private function assertOwned(mixed $found, int $requestedId): int {
        if ($found === null) {
            throw ValidationException::withMessages([
                'category_id' => ["Kategoriya topilmadi yoki bu do'konga tegishli emas (id: {$requestedId})"],
            ]);
        }

        return (int) $found;
    }
}
