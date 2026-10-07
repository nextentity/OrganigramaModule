<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Service\OrganigramaService;
use App\Domain\Model\DeletionStrategy;

final class OrganigramaController
{
    public function __construct(
        private readonly OrganigramaService $service,
    ) {
    }

    public function listClients(): void
    {
        $clients = $this->service->listClients();
        JsonResponse::success($clients);
    }

    public function getOrganigrama(int $clientId): void
    {
        $result = $this->service->getOrganigrama($clientId);
        JsonResponse::success($result);
    }

    public function listUsers(int $clientId): void
    {
        $result = $this->service->listUsersOrderedByLevel($clientId);
        JsonResponse::success($result);
    }

    public function getUser(int $clientId, int $userId): void
    {
        $userView = $this->service->getUser($clientId, $userId);
        JsonResponse::success($userView);
    }

    public function createUser(int $clientId): void
    {
        $body = Request::getJsonBody();
        $input = Request::parseUserInput($body, forcedClientId: $clientId);

        $userView = $this->service->createUser($input);
        JsonResponse::success($userView, 201);
    }

    public function updateUser(int $clientId, int $userId): void
    {
        $body = Request::getJsonBody();
        $input = Request::parseUserInput($body, forcedClientId: $clientId, isUpdate: true);

        $userView = $this->service->updateUser($clientId, $userId, $input);
        JsonResponse::success($userView);
    }

    public function deleteUser(int $clientId, int $userId): void
    {
        $body = Request::getJsonBody();

        $strategyValue = $body['strategy'] ?? $_GET['strategy'] ?? DeletionStrategy::ReassignToGrandparent->value;
        $strategy = DeletionStrategy::tryFrom((string) $strategyValue) ?? DeletionStrategy::ReassignToGrandparent;

        $reassignToUserId = isset($body['reassign_to_user_id'])
            ? (int) $body['reassign_to_user_id']
            : (isset($_GET['reassign_to_user_id']) ? (int) $_GET['reassign_to_user_id'] : null);

        $result = $this->service->deleteUser($clientId, $userId, $strategy, $reassignToUserId);
        JsonResponse::success($result);
    }

    public function resetSeed(): void
    {
        $this->service->resetSeedData();
        JsonResponse::success(['message' => 'Datos de prueba restablecidos exitosamente.']);
    }
}

