<?php

namespace App\Enums;

enum ReturnRequestStatus: string
{
    case Submitted = 'submitted';
    case Reviewing = 'reviewing';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case AwaitingHandover = 'awaiting_handover';
    case Received = 'received';
    case RefundProcessed = 'refund_processed';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Reviewing, self::Rejected],
            self::Reviewing => [self::Approved, self::Rejected],
            self::Approved => [self::AwaitingHandover, self::Rejected],
            self::AwaitingHandover => [self::Received, self::Rejected],
            self::Received => [self::RefundProcessed, self::Closed, self::Rejected],
            self::RefundProcessed => [self::Closed],
            self::Rejected, self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
