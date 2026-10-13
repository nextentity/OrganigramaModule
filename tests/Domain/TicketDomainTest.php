<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Application\DTO\CreateTicketDTO;
use App\Domain\Exception\InvalidTicketTransitionException;
use App\Domain\Exception\ValidationException;
use App\Domain\Model\SourceChannel;
use App\Domain\Model\Ticket;
use App\Domain\Model\TicketSeverity;
use App\Domain\Model\TicketStatus;
use App\Domain\Model\TicketType;
use App\Domain\Model\TriageResult;
use App\Infrastructure\Support\Uuid;
use Tests\Support\TestCase;
use ValueError;

final class TicketDomainTest extends TestCase
{
    public function testEnumsParseAndValidateCorrectly(): void
    {
        $this->assertSame(TicketStatus::PENDING, TicketStatus::from('PENDING'));
        $this->assertTrue(TicketStatus::RESOLVED->isTerminal());
        $this->assertTrue(TicketStatus::FAILED->isTerminal());
        $this->assertFalse(TicketStatus::PENDING->isTerminal());

        $this->assertSame(TicketSeverity::HIGH, TicketSeverity::fromString('HIGH'));
        $this->assertSame(TicketSeverity::LOW, TicketSeverity::fromString('low'));

        $this->assertSame(TicketType::BUG, TicketType::fromString('bug'));
        $this->assertSame(TicketType::OPERATIONAL_QUESTION, TicketType::fromString('operational_question'));

        $this->assertSame(SourceChannel::WHATSAPP, SourceChannel::fromString('whatsapp'));
        $this->assertSame(SourceChannel::WEBFORM, SourceChannel::fromString('webform'));

        $this->assertThrows(
            static fn () => TicketSeverity::fromString('unknown'),
            ValueError::class,
            'Severidad inválida',
        );
    }

    public function testTriageResultValidationAndSerialization(): void
    {
        $triage = new TriageResult(
            summary: 'Error al cambiar supervisor',
            type: TicketType::BUG,
            severity: TicketSeverity::HIGH,
            reproSteps: ['Paso 1: click en editar', 'Paso 2: seleccionar jefe'],
            isAutoFixable: false,
            suggestedArea: 'src/Application/Service',
            patchHints: 'Revisar validación de ciclo',
            cannedResponse: null,
        );

        $array = $triage->toArray();
        $this->assertSame('Error al cambiar supervisor', $array['summary']);
        $this->assertSame('bug', $array['type']);
        $this->assertSame('high', $array['severity']);
        $this->assertSame(2, count($array['repro_steps']));

        $restored = TriageResult::fromJson($triage->toJson());
        $this->assertSame($triage->summary, $restored->summary);
        $this->assertSame($triage->type, $restored->type);
        $this->assertSame($triage->severity, $restored->severity);
        $this->assertSame($triage->isAutoFixable, $restored->isAutoFixable);
    }

    public function testOperationalQuestionRequiresCannedResponse(): void
    {
        $this->assertThrows(
            static fn () => new TriageResult(
                summary: 'Cómo crear un usuario',
                type: TicketType::OPERATIONAL_QUESTION,
                severity: TicketSeverity::LOW,
                reproSteps: [],
                isAutoFixable: false,
                suggestedArea: 'docs',
                patchHints: '',
                cannedResponse: null,
            ),
            ValidationException::class,
            'Error de validación en la solicitud',
        );
    }

    public function testTicketInvariantsAndStateTransitions(): void
    {
        $uuid = Uuid::v4();
        $ticket = Ticket::createPending(
            id: $uuid,
            sourceChannel: SourceChannel::WEBFORM,
            customerId: 'cust_42',
            rawPayload: ['message' => 'El sistema se cuelga al exportar'],
        );

        $this->assertSame(TicketStatus::PENDING, $ticket->status());
        $this->assertSame('cust_42', $ticket->customerId);

        $triage = new TriageResult(
            summary: 'Error al exportar',
            type: TicketType::BUG,
            severity: TicketSeverity::MEDIUM,
            reproSteps: ['Click exportar'],
            isAutoFixable: true,
            suggestedArea: 'src/Infrastructure/Http',
            patchHints: 'Aumentar timeout de renderizado',
        );

        // 1. PENDING -> TRIAGED
        $ticket->markAsTriaged($triage);
        $this->assertSame(TicketStatus::TRIAGED, $ticket->status());

        // 2. TRIAGED -> AUTO_FIXING
        $ticket->markAsAutoFixing(githubIssueNumber: 123, jiraKey: 'SUP-456');
        $this->assertSame(TicketStatus::AUTO_FIXING, $ticket->status());
        $this->assertSame(123, $ticket->githubIssueNumber());
        $this->assertSame('SUP-456', $ticket->jiraKey());

        // 3. AUTO_FIXING -> RESOLVED
        $ticket->markAsResolved();
        $this->assertSame(TicketStatus::RESOLVED, $ticket->status());
        $this->assertTrue($ticket->status()->isTerminal());

        // 4. Intentar transicionar desde estado terminal debe lanzar excepción
        $this->assertThrows(
            static fn () => $ticket->markAsOpen(999),
            InvalidTicketTransitionException::class,
            'Transición de estado inválida',
        );
    }

    public function testAutoFixingRejectsNonBugsOrNonAutoFixable(): void
    {
        $uuid = Uuid::v4();
        $ticket = Ticket::createPending(
            id: $uuid,
            sourceChannel: SourceChannel::WEBFORM,
            customerId: 'cust_1',
            rawPayload: ['message' => 'No es un bug'],
        );

        $triageQuestion = new TriageResult(
            summary: 'Duda de uso',
            type: TicketType::OPERATIONAL_QUESTION,
            severity: TicketSeverity::LOW,
            reproSteps: [],
            isAutoFixable: false,
            suggestedArea: 'manual',
            patchHints: '',
            cannedResponse: 'Usa el menú lateral',
        );

        $ticket->markAsTriaged($triageQuestion);

        $this->assertThrows(
            static fn () => $ticket->markAsAutoFixing(100),
            InvalidTicketTransitionException::class,
            'Solo los incidentes clasificados como bug con is_auto_fixable=true',
        );
    }

    public function testCreateTicketDtoNormalizesHeterogeneousPayloads(): void
    {
        // Caso A: Formato Directo API
        $directDto = CreateTicketDTO::fromArray([
            'source_channel' => 'api',
            'customer_id'    => 'client_99',
            'message'        => 'Fallo en endpoint de organigrama',
        ]);
        $this->assertSame(SourceChannel::API, $directDto->sourceChannel);
        $this->assertSame('client_99', $directDto->customerId);
        $this->assertSame('Fallo en endpoint de organigrama', $directDto->extractedMessage);

        // Caso B: Formato WhatsApp Cloud API
        $whatsAppPayload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'from' => '5491122334455',
                                        'text' => [
                                            'body' => 'No puedo ver a mi jefe en el organigrama',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $waDto = CreateTicketDTO::fromArray($whatsAppPayload);
        $this->assertSame(SourceChannel::WHATSAPP, $waDto->sourceChannel);
        $this->assertSame('wa_5491122334455', $waDto->customerId);
        $this->assertSame('No puedo ver a mi jefe en el organigrama', $waDto->extractedMessage);
    }
}
