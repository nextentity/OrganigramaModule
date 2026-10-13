<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Repository;

use App\Domain\Model\SourceChannel;
use App\Domain\Model\Ticket;
use App\Domain\Model\TicketSeverity;
use App\Domain\Model\TicketStatus;
use App\Domain\Model\TicketType;
use App\Domain\Model\TriageResult;
use App\Infrastructure\Repository\PdoTicketRepository;
use App\Infrastructure\Support\Uuid;
use PDO;
use Tests\Support\TestCase;

final class PdoTicketRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoTicketRepository $repository;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $this->repository = new PdoTicketRepository($this->pdo);
    }

    public function testSaveAndFindTicket(): void
    {
        $id = Uuid::v4();
        $ticket = Ticket::createPending(
            id: $id,
            sourceChannel: SourceChannel::WHATSAPP,
            customerId: 'wa_12345',
            rawPayload: ['text' => 'Boton no responde', 'user_id' => 99],
        );

        $this->repository->save($ticket);

        $found = $this->repository->findById($id);
        $this->assertTrue($found !== null);
        $this->assertSame($id, $found->id);
        $this->assertSame(SourceChannel::WHATSAPP, $found->sourceChannel);
        $this->assertSame('wa_12345', $found->customerId);
        $this->assertSame(TicketStatus::PENDING, $found->status());
        $this->assertSame('Boton no responde', $found->rawPayload['text']);
        $this->assertNull($found->triageResult());
    }

    public function testFindPendingForProcessingOrdersChronologically(): void
    {
        $id1 = Uuid::v4();
        $id2 = Uuid::v4();

        $t1 = Ticket::createPending($id1, SourceChannel::API, 'c1', ['msg' => 'first']);
        $t2 = Ticket::createPending($id2, SourceChannel::API, 'c2', ['msg' => 'second']);

        $this->repository->save($t1);
        $this->repository->save($t2);

        $pending = $this->repository->findPendingForProcessing(limit: 5);
        $this->assertSame(2, count($pending));
        $this->assertSame($id1, $pending[0]->id);
        $this->assertSame($id2, $pending[1]->id);
    }

    public function testClaimTicketAtomicConcurrency(): void
    {
        $id = Uuid::v4();
        $ticket = Ticket::createPending($id, SourceChannel::WEBFORM, 'client_1', ['msg' => 'concurrency test']);
        $this->repository->save($ticket);

        // Primer worker intenta reclamar el ticket: debe tener éxito
        $firstClaim = $this->repository->claimTicket($id);
        $this->assertTrue($firstClaim, 'El primer worker debe lograr reclamar el ticket');

        // Segundo worker concurrente intenta reclamar el mismo ticket: debe fallar
        $secondClaim = $this->repository->claimTicket($id);
        $this->assertFalse($secondClaim, 'El segundo worker no debe poder reclamar un ticket ya reservado');

        // El estado en BD debe haber pasado a TRIAGED
        $fresh = $this->repository->findById($id);
        $this->assertSame(TicketStatus::TRIAGED, $fresh?->status());
    }

    public function testUpdateTicketPersistsTriageAndExternalKeys(): void
    {
        $id = Uuid::v4();
        $ticket = Ticket::createPending($id, SourceChannel::HELPDESK, 'helpdesk_1', ['msg' => 'pantalla rota']);
        $this->repository->save($ticket);

        $triage = new TriageResult(
            summary: 'Pantalla rota',
            type: TicketType::BUG,
            severity: TicketSeverity::HIGH,
            reproSteps: ['Abrir módulo'],
            isAutoFixable: true,
            suggestedArea: 'frontend',
            patchHints: 'Cambiar ngIf por hidden',
        );

        $ticket->markAsTriaged($triage);
        $ticket->markAsAutoFixing(githubIssueNumber: 88, jiraKey: 'SUP-88');
        $ticket->setGithubPrUrl('https://github.com/acme/repo/pull/89');

        $this->repository->update($ticket);

        $updated = $this->repository->findById($id);
        $this->assertTrue($updated !== null);
        $this->assertSame(TicketStatus::AUTO_FIXING, $updated->status());
        $this->assertSame(88, $updated->githubIssueNumber());
        $this->assertSame('SUP-88', $updated->jiraKey());
        $this->assertSame('https://github.com/acme/repo/pull/89', $updated->githubPrUrl());
        $this->assertSame('Pantalla rota', $updated->triageResult()?->summary);
        $this->assertTrue($updated->triageResult()?->isAutoFixable ?? false);
    }
}

