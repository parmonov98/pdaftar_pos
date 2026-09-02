<?php

declare(strict_types=1);

namespace Pos\Constants;

/**
 * Whose till is talking to us.
 *
 * pDaftar POS is listed alongside the third parties on purpose: it is a client
 * of this API, not the owner of it. Nothing in the request pipeline branches on
 * this value for permissions — it exists for reporting ("how many sales came in
 * from AliPOS this month?") and for the few places where a foreign system's
 * data needs labelling in the UI.
 */
enum PosProvider: string {
    case PDAFTAR_POS = 'pdaftar_pos';
    case ALIPOS = 'alipos';
    case YESPOS = 'yespos';
    case OTHER = 'other';

    /** @return string[] */
    public static function values(): array {
        return array_column(self::cases(), 'value');
    }

    /**
     * Third-party tills push their sales to us and read the catalog; they do
     * not run our inventory. Boss's rule: "ular bizga faqat sotuv yuborsin".
     * Enforced as the scope ceiling at token-issue time, in
     * PosScope::defaultFor().
     */
    public function isExternal(): bool {
        return $this !== self::PDAFTAR_POS;
    }
}
