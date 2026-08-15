<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Model;

class ApprovalOutcomeApplier
{
    private const STATUSES = [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'returned' => 'draft',
    ];

    public function apply(ApprovalRequest $request): ?Model
    {
        $status = self::STATUSES[$request->status] ?? null;

        if ($status === null) {
            return null;
        }

        $subject = $this->subjectFor($request);

        if ($subject === null) {
            return null;
        }

        if ($subject instanceof PurchaseOrder && $status === 'rejected') {
            $status = 'cancelled';
        }

        $subject->forceFill(['status' => $status])->save();

        return $subject->refresh();
    }

    public function subjectFor(ApprovalRequest $request): ?Model
    {
        return match ($request->approvable_type) {
            PurchaseRequest::class => PurchaseRequest::query()->find($request->approvable_id),
            PurchaseOrder::class => PurchaseOrder::query()->find($request->approvable_id),
            default => null,
        };
    }

    public function describe(ApprovalRequest $request): string
    {
        $subject = $this->subjectFor($request);

        return match (true) {
            $subject instanceof PurchaseRequest => "Purchase request {$subject->reference}",
            $subject instanceof PurchaseOrder => "Purchase order {$subject->reference}",
            default => class_basename((string) $request->approvable_type),
        };
    }
}
