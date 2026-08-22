<?php

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Extension;
use App\Catalog\Entity\Vendor;
use App\Moderation\Entity\Complaint;
use App\Moderation\Enum\ComplaintKind;
use App\Moderation\Enum\ComplaintStatus;
use PHPUnit\Framework\TestCase;

/**
 * The complaint model exists to make a promise measurable. The takedown policy
 * commits to seven days for rights complaints, and until this entity existed there
 * was no way to say how many were open or how close any of them was to breaching it.
 */
final class ComplaintTest extends TestCase
{
    public function testOnlyRightsAndSecurityCarryTheSevenDayClock(): void
    {
        self::assertTrue(ComplaintKind::Rights->isUrgent());
        self::assertTrue(ComplaintKind::Security->isUrgent());

        // A miscategorised extension is worth fixing, but nobody is exposed while it
        // waits. Treating every report as urgent is how the urgent ones get lost.
        self::assertFalse(ComplaintKind::Metadata->isUrgent());
        self::assertFalse(ComplaintKind::Licence->isUrgent());
        self::assertFalse(ComplaintKind::Other->isUrgent());
    }

    public function testAnUrgentComplaintGoesOverdueAfterSevenDays(): void
    {
        $sixDays = $this->complaintAgedDays(ComplaintKind::Rights, 6);
        $sevenDays = $this->complaintAgedDays(ComplaintKind::Rights, 7);

        self::assertFalse($sixDays->isOverdue());
        self::assertTrue($sevenDays->isOverdue());
    }

    public function testANonUrgentComplaintIsNeverOverdue(): void
    {
        // No commitment was made about metadata corrections, so flagging one as
        // breached would be inventing an obligation and burying the real ones.
        self::assertFalse($this->complaintAgedDays(ComplaintKind::Metadata, 90)->isOverdue());
    }

    public function testAResolvedComplaintStopsBeingOverdue(): void
    {
        $complaint = $this->complaintAgedDays(ComplaintKind::Rights, 30);
        self::assertTrue($complaint->isOverdue());

        $complaint->resolve(ComplaintStatus::Upheld, 'Delisted at the rights holder request.', null);

        self::assertFalse($complaint->isOverdue());
        self::assertTrue($complaint->getStatus()->isClosed());
        self::assertNotNull($complaint->getResolvedAt());
    }

    /**
     * A rejection is recorded rather than deleted. A complainant who disagrees is
     * entitled to see that their report was read and answered.
     */
    public function testARejectionKeepsItsReasoning(): void
    {
        $complaint = $this->complaint(ComplaintKind::Rights);
        $complaint->resolve(ComplaintStatus::Rejected, 'The named trademark is used descriptively.', null);

        self::assertSame(ComplaintStatus::Rejected, $complaint->getStatus());
        self::assertStringContainsString('descriptively', (string) $complaint->getResolution());
    }

    public function testResolvingToOpenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->complaint(ComplaintKind::Rights)->resolve(ComplaintStatus::Open, 'n/a', null);
    }

    private function complaint(ComplaintKind $kind): Complaint
    {
        $extension = new Extension(new Vendor('acme', 'acme'), 'acme/plugin', 'acme-plugin', 'Plugin');

        return new Complaint($extension, $kind, 'legal@example.com', 'Please remove this.');
    }

    private function complaintAgedDays(ComplaintKind $kind, int $days): Complaint
    {
        $complaint = $this->complaint($kind);

        // createdAt is set in the constructor and has no setter, which is correct,
        // nothing in the application may backdate a complaint.
        $property = new \ReflectionProperty($complaint, 'createdAt');
        $property->setValue($complaint, new \DateTimeImmutable(\sprintf('-%d days', $days)));

        return $complaint;
    }
}
