<?php

namespace Tests\Unit;

use App\Enums\DisputeStatus;
use PHPUnit\Framework\TestCase;

class DisputeStatusTest extends TestCase
{
    public function test_maps_woovi_final_statuses(): void
    {
        $this->assertSame(DisputeStatus::ACCEPTED, DisputeStatus::fromWooviStatus('ACCEPTED'));
        $this->assertSame(DisputeStatus::REJECTED, DisputeStatus::fromWooviStatus('rejected'));
        $this->assertSame(DisputeStatus::CANCELED, DisputeStatus::fromWooviStatus('CANCELED'));
    }

    public function test_non_final_woovi_statuses_do_not_touch_the_internal_flow(): void
    {
        foreach (['OPENED', 'IN_REVIEW', 'PENDING', 'PENDING_DOCUMENTATION', 'CREATED', 'ACKNOWLEDGED', null, ''] as $status) {
            $this->assertNull(DisputeStatus::fromWooviStatus($status), (string) $status);
        }
    }

    public function test_only_pending_seller_accepts_the_seller_form(): void
    {
        foreach (DisputeStatus::cases() as $status) {
            $this->assertSame($status === DisputeStatus::PENDING_SELLER, $status->acceptsSellerSubmission(), $status->value);
        }
    }

    public function test_closed_statuses_cannot_be_sent_to_woovi(): void
    {
        foreach (DisputeStatus::closed() as $status) {
            $this->assertFalse($status->canSendToWoovi());
            $this->assertTrue($status->isClosed());
        }
    }
}
