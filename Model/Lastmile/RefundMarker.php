<?php

declare(strict_types=1);

namespace Cwax\Banner\Model\Lastmile;

/**
 * Credit memos refunded in this request and not yet saved. sales_order_creditmemo_refund fires once per
 * refund, but before the credit memo has an id; its first save then has the id. Later saves of the same
 * credit memo (a comment, the email flag) are not refunds. A WeakMap so a credit memo whose save fails
 * leaves nothing behind. Shared instance (Magento default), so both observers see the same marks.
 */
class RefundMarker
{
    /** @var \WeakMap<object, true> */
    private \WeakMap $pending;

    public function __construct()
    {
        $this->pending = new \WeakMap();
    }

    public function mark(object $creditmemo): void
    {
        $this->pending[$creditmemo] = true;
    }

    /** True once for a marked credit memo, then the mark is gone. */
    public function take(object $creditmemo): bool
    {
        if (!isset($this->pending[$creditmemo])) {
            return false;
        }
        unset($this->pending[$creditmemo]);

        return true;
    }
}
