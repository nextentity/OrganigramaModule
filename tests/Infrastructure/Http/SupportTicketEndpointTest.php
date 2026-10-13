<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Http;

use App\Application\Service\TicketIngestionService;
use App\Domain\Model\SourceChannel;
use App\Domain\Model\Ticket;
use App\Domain\Model\TicketStatus;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Http\SupportTicketController;
use App\Infrastructure\Repository\PdoTicketRepository;
use App\Infrastructure\Support\Uuid;
use PDO;
use Tests\Support\TestCase;

final class SupportTicketEndpointTest extends TestCase
{
    private PDO $pdo;
    private Router $router;
    private PdoTicketRepository $repository;

    public function setUp(): void
    {
        $this->pdo = $this->createMigratedDatabase();
        $this->repository = new PdoTicketRepository($this->pdo);
        $ingestionService = new TicketIngestionService($this->repository);
        $controller = new SupportTicketController($ingestionService, $this->repository);

        $this->router = new Router();
        $this->router->add('GET', '/api/v1/tickets', [$controller, 'listTickets']);
        $this->router->add('POST', '/api/v1/tickets', [$controller, 'createTicket']);
        $this->router->add('POST', '/api/v1/tickets/sync', [$controller, 'syncTickets']);
        $this->router->add('GET', '/api/v1/tickets/{ticketId}', [$controller, 'getTicket']);
        $this->router->add('POST', '/api/v1/tickets/{ticketId}/retry', [$controller, 'retryTicket']);
    }

    public function testListTicketsReturnsArrayOfRecentTickets(): void
    {
        $id = Uuid::v4();
        $ticket = Ticket::createPending($id, SourceChannel::WEBFORM, 'user_list', ['msg' => 'test']);
        $this->repository->save($ticket);

        $response = $this->dispatch('GET', '/api/v1/tickets');

        $this->assertSame('success', $response['status']);
        $this->assertTrue(is_array($response['data']));
        $this->assertTrue(count($response['data']) >= 1);
        $this->assertSame($id, $response['data'][0]['id']);
    }

    public function testPostTicketReturns202AcceptedWithTicketIdAndPendingStatus(): void
    {
        $payload = [
            'source_channel' => 'webform',
            'customer_id'    => 'client_vip_123',
            'message'        => 'No puedo actualizar el rol de un usuario dependiente',
        ];

        $response = $this->dispatch('POST', '/api/v1/tickets', $payload);

        $this->assertTrue(isset($response['ticket_id']), 'La respuesta debe incluir ticket_id');
        $this->assertTrue(Uuid::isValid($response['ticket_id']), 'El ticket_id debe ser un UUIDv4 válido');
        $this->assertSame('PENDING', $response['status']);

        // Verificar persistencia en SQLite
        $ticket = $this->repository->findById($response['ticket_id']);
        $this->assertTrue($ticket !== null);
        $this->assertSame('client_vip_123', $ticket->customerId);
        $this->assertSame(SourceChannel::WEBFORM, $ticket->sourceChannel);
    }

    public function testPostWhatsAppCloudApiPayloadReturns202Accepted(): void
    {
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'from' => '5491199887766',
                                        'text' => [
                                            'body' => 'Error 500 al eliminar el CEO',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->dispatch('POST', '/api/v1/tickets', $payload);

        $this->assertTrue(isset($response['ticket_id']));
        $this->assertSame('PENDING', $response['status']);

        $ticket = $this->repository->findById($response['ticket_id']);
        $this->assertTrue($ticket !== null);
        $this->assertSame(SourceChannel::WHATSAPP, $ticket->sourceChannel);
        $this->assertSame('wa_5491199887766', $ticket->customerId);
    }

    public function testPostInvalidPayloadReturns422ValidationError(): void
    {
        $response = $this->dispatch('POST', '/api/v1/tickets', []);

        $this->assertSame('error', $response['status']);
        $this->assertTrue(isset($response['details']['payload']));
    }

    public function testGetTicketReturnsDetailsOr404(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::API, 'customer_get_test', ['message' => 'Test GET']);
        $this->repository->save($ticket);

        // 1. Ticket existente
        $successResponse = $this->dispatch('GET', "/api/v1/tickets/{$ticketId}");
        $this->assertSame('success', $successResponse['status']);
        $this->assertSame($ticketId, $successResponse['data']['id']);
        $this->assertSame('customer_get_test', $successResponse['data']['customer_id']);

        // 2. Ticket inexistente
        $notFoundId = Uuid::v4();
        $errorResponse = $this->dispatch('GET', "/api/v1/tickets/{$notFoundId}");
        $this->assertSame('error', $errorResponse['status']);
        $this->assertTrue(str_contains($errorResponse['message'], 'Ticket no encontrado'));
    }

    public function testRetryFailedTicketRequeuesToPending(): void
    {
        $ticketId = Uuid::v4();
        $ticket = Ticket::createPending($ticketId, SourceChannel::WEBFORM, 'customer_retry', ['message' => 'Test Retry']);
        $ticket->markAsFailed('Simulated failure reason');
        $this->repository->save($ticket);

        $response = $this->dispatch('POST', "/api/v1/tickets/{$ticketId}/retry");

        $this->assertSame($ticketId, $response['ticket_id']);
        $this->assertSame('PENDING', $response['status']);

        $refreshed = $this->repository->findById($ticketId);
        $this->assertSame(TicketStatus::PENDING, $refreshed?->status());
    }

    public function testSyncTicketsEndpointReturnsSuccess(): void
    {
        $response = $this->dispatch('POST', '/api/v1/tickets/sync');

        $this->assertSame('success', $response['status']);
        $this->assertTrue(isset($response['data']['reconciled']));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function dispatch(string $method, string $uri, ?array $body = null): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $GLOBALS['__TEST_REQUEST_BODY__'] = $body ?? [];

        ob_start();
        $this->router->dispatch($method, $uri);
        $output = ob_get_clean();

        unset($GLOBALS['__TEST_REQUEST_BODY__']);

        $decoded = json_decode((string) $output, true);
        return is_array($decoded) ? $decoded : ['output' => $output];
    }
}

