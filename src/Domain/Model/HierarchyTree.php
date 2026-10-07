<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Exception\NotFoundException;

/**
 * Árbol jerárquico en memoria de un único tenant.
 *
 * Calcula niveles (BFS desde la raíz), tamaños de subárbol, descendientes,
 * el formato TreeNode de PrimeNG y métricas de negocio. Es puro: no accede a la base.
 */
final class HierarchyTree
{
    /** @var array<int, User> */
    private array $usersById = [];

    /** @var array<int, list<int>> */
    private array $childIdsBySupervisor = [];

    /** @var list<int> */
    private array $rootIds = [];

    /** @var array<int, int> */
    private array $levelById = [];

    /** @var array<int, int> Cantidad de nodos del subárbol (incluye al propio nodo). */
    private array $subtreeSizeById = [];

    /**
     * @param list<User> $users Usuarios de un mismo client_id.
     */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->usersById[$user->id] = $user;
        }

        ksort($this->usersById);

        foreach ($this->usersById as $userId => $user) {
            // Un jefe inexistente en el tenant se trata defensivamente como raíz.
            if ($user->supervisorId === null || !isset($this->usersById[$user->supervisorId])) {
                $this->rootIds[] = $userId;
                continue;
            }

            $this->childIdsBySupervisor[$user->supervisorId][] = $userId;
        }

        $this->computeLevelsAndSubtreeSizes();
    }

    public function has(int $userId): bool
    {
        return isset($this->usersById[$userId]);
    }

    public function get(int $userId): User
    {
        return $this->usersById[$userId] ?? throw NotFoundException::user($userId);
    }

    public function count(): int
    {
        return count($this->usersById);
    }

    /**
     * @return list<int>
     */
    public function rootIds(): array
    {
        return $this->rootIds;
    }

    /**
     * @return list<User>
     */
    public function childrenOf(int $userId): array
    {
        return array_map(fn (int $childId): User => $this->usersById[$childId], $this->childIdsBySupervisor[$userId] ?? []);
    }

    public function levelOf(int $userId): int
    {
        return $this->levelById[$userId] ?? throw NotFoundException::user($userId);
    }

    /**
     * Usuarios no alcanzables desde una raíz (sólo posible con ciclos en datos legados).
     *
     * @return list<int>
     */
    public function unreachableIds(): array
    {
        return array_values(array_diff(array_keys($this->usersById), array_keys($this->levelById)));
    }

    /**
     * ¿$candidateId está por debajo de $ancestorId en la jerarquía?
     * Recorre la cadena de jefes hacia arriba (O(profundidad)).
     */
    public function isDescendantOf(int $candidateId, int $ancestorId): bool
    {
        $visited = [];
        $currentId = $this->usersById[$candidateId]->supervisorId ?? null;

        while ($currentId !== null && !isset($visited[$currentId])) {
            if ($currentId === $ancestorId) {
                return true;
            }

            $visited[$currentId] = true;
            $currentId = $this->usersById[$currentId]->supervisorId ?? null;
        }

        return false;
    }

    /**
     * Retorna la ruta de IDs desde $startUserId hacia arriba hasta $targetAncestorId inclusive.
     * Ejemplo: para C -> B -> A, getAncestorPath(C, A) retorna [C, B, A].
     *
     * @return list<int>|null
     */
    public function getAncestorPath(int $startUserId, int $targetAncestorId): ?array
    {
        if (!isset($this->usersById[$startUserId]) || !isset($this->usersById[$targetAncestorId])) {
            return null;
        }

        $path = [$startUserId];
        $visited = [$startUserId => true];
        $currentId = $this->usersById[$startUserId]->supervisorId ?? null;

        while ($currentId !== null && !isset($visited[$currentId])) {
            $path[] = $currentId;
            $visited[$currentId] = true;

            if ($currentId === $targetAncestorId) {
                return $path;
            }

            $currentId = $this->usersById[$currentId]->supervisorId ?? null;
        }

        return null;
    }

    public function view(int $userId): UserView
    {
        $user = $this->get($userId);
        $supervisor = $user->supervisorId !== null ? ($this->usersById[$user->supervisorId] ?? null) : null;

        return new UserView(
            user: $user,
            level: $this->levelOf($userId),
            supervisorName: $supervisor?->fullName(),
            directReports: count($this->childIdsBySupervisor[$userId] ?? []),
            totalReports: ($this->subtreeSizeById[$userId] ?? 1) - 1,
        );
    }

    /**
     * Personal ordenado por nivel jerárquico (1 = CEO), luego apellido y nombre.
     *
     * @return list<UserView>
     */
    public function viewsOrderedByLevel(): array
    {
        $views = array_map(fn (int $userId): UserView => $this->view($userId), array_keys($this->levelById));

        usort($views, static fn (UserView $a, UserView $b): int => [
            $a->level,
            mb_strtolower($a->user->lastName),
            mb_strtolower($a->user->name),
            $a->user->id,
        ] <=> [
            $b->level,
            mb_strtolower($b->user->lastName),
            mb_strtolower($b->user->name),
            $b->user->id,
        ]);

        return $views;
    }

    /**
     * Nodos en formato TreeNode de PrimeNG (OrganizationChart), con nivel en cada nodo.
     *
     * @return list<array<string, mixed>>
     */
    public function toOrgChartNodes(): array
    {
        return array_map(fn (int $rootId): array => $this->buildOrgChartNode($rootId), $this->rootIds);
    }

    /**
     * Métricas de negocio para la cabecera del organigrama.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $usersPerLevel = array_count_values($this->levelById);
        ksort($usersPerLevel);

        $spans = array_map('count', $this->childIdsBySupervisor);
        $managers = count($spans);
        $blockedUsers = count(array_filter($this->usersById, static fn (User $user): bool => $user->blockLogin));

        return [
            'total_users'             => $this->count(),
            'hierarchy_depth'         => $this->levelById === [] ? 0 : max($this->levelById),
            'managers'                => $managers,
            'individual_contributors' => $this->count() - $managers,
            'average_span_of_control' => $managers === 0 ? 0.0 : round(array_sum($spans) / $managers, 2),
            'max_span_of_control'     => $spans === [] ? 0 : max($spans),
            'blocked_users'           => $blockedUsers,
            'active_users'            => $this->count() - $blockedUsers,
            'users_per_level'         => array_map(
                static fn (int $level, int $count): array => ['level' => $level, 'count' => $count],
                array_keys($usersPerLevel),
                array_values($usersPerLevel),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrgChartNode(int $userId): array
    {
        $view = $this->view($userId);

        return [
            'key'        => (string) $userId,
            'label'      => $view->user->fullName(),
            'type'       => 'person',
            'styleClass' => 'org-level-' . $view->level,
            'expanded'   => true,
            'level'      => $view->level,
            'data'       => $view->jsonSerialize(),
            'children'   => array_map(
                fn (int $childId): array => $this->buildOrgChartNode($childId),
                $this->childIdsBySupervisor[$userId] ?? [],
            ),
        ];
    }

    private function computeLevelsAndSubtreeSizes(): void
    {
        $visitOrder = [];
        $queue = array_map(static fn (int $rootId): array => [$rootId, 1], $this->rootIds);

        for ($index = 0; $index < count($queue); $index++) {
            [$userId, $level] = $queue[$index];

            if (isset($this->levelById[$userId])) {
                continue;
            }

            $this->levelById[$userId] = $level;
            $visitOrder[] = $userId;

            foreach ($this->childIdsBySupervisor[$userId] ?? [] as $childId) {
                $queue[] = [$childId, $level + 1];
            }
        }

        // Post-orden (BFS invertido): cada hijo se procesa antes que su jefe.
        foreach (array_reverse($visitOrder) as $userId) {
            $this->subtreeSizeById[$userId] = 1 + array_sum(array_map(
                fn (int $childId): int => $this->subtreeSizeById[$childId] ?? 0,
                $this->childIdsBySupervisor[$userId] ?? [],
            ));
        }
    }
}

