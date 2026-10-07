<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Inspecciona la salud estructural del organigrama de un tenant directamente en la base.
 * Detecta: múltiples raíces, ramas huérfanas, jefes de otro tenant y ciclos.
 */
final class HierarchySnapshot
{
    /**
     * @param array<int, ?int> $supervisorByUserId
     * @param list<int> $rootIds
     * @param list<int> $unreachableIds Usuarios que no cuelgan de ninguna raíz (huérfanos o en ciclo).
     * @param list<int> $crossTenantIds Usuarios cuyo jefe no pertenece al mismo client_id.
     */
    private function __construct(
        public readonly array $supervisorByUserId,
        public readonly array $rootIds,
        public readonly array $unreachableIds,
        public readonly array $crossTenantIds,
        public readonly int $maxDepth,
    ) {
    }

    public static function capture(PDO $pdo, int $clientId): self
    {
        $statement = $pdo->prepare('SELECT id, user_id FROM users WHERE client_id = :client_id');
        $statement->bindValue(':client_id', $clientId, PDO::PARAM_INT);
        $statement->execute();

        $supervisorByUserId = [];
        foreach ($statement->fetchAll() as $row) {
            $supervisorByUserId[(int) $row['id']] = $row['user_id'] === null ? null : (int) $row['user_id'];
        }

        $rootIds = array_keys(array_filter($supervisorByUserId, static fn (?int $s): bool => $s === null));
        $crossTenantIds = array_keys(array_filter(
            $supervisorByUserId,
            static fn (?int $s): bool => $s !== null && !array_key_exists($s, $supervisorByUserId),
        ));

        [$depthByUserId, $maxDepth] = self::walkFromRoots($supervisorByUserId, $rootIds);
        $unreachableIds = array_values(array_diff(array_keys($supervisorByUserId), array_keys($depthByUserId)));

        return new self($supervisorByUserId, $rootIds, $unreachableIds, $crossTenantIds, $maxDepth);
    }

    public function isHealthy(): bool
    {
        return count($this->rootIds) === 1 && $this->unreachableIds === [] && $this->crossTenantIds === [];
    }

    public function userCount(): int
    {
        return count($this->supervisorByUserId);
    }

    /**
     * BFS desde las raíces: todo nodo no alcanzado está huérfano o forma parte de un ciclo.
     *
     * @param array<int, ?int> $supervisorByUserId
     * @param list<int> $rootIds
     * @return array{0: array<int, int>, 1: int}
     */
    private static function walkFromRoots(array $supervisorByUserId, array $rootIds): array
    {
        $childrenBySupervisor = [];
        foreach ($supervisorByUserId as $userId => $supervisorId) {
            if ($supervisorId !== null) {
                $childrenBySupervisor[$supervisorId][] = $userId;
            }
        }

        $depthByUserId = [];
        $queue = array_map(static fn (int $rootId): array => [$rootId, 1], $rootIds);
        $maxDepth = 0;

        while ($queue !== []) {
            [$userId, $depth] = array_shift($queue);

            if (isset($depthByUserId[$userId])) {
                continue;
            }

            $depthByUserId[$userId] = $depth;
            $maxDepth = max($maxDepth, $depth);

            foreach ($childrenBySupervisor[$userId] ?? [] as $childId) {
                $queue[] = [$childId, $depth + 1];
            }
        }

        return [$depthByUserId, $maxDepth];
    }
}

