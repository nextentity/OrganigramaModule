<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Estrategia de reasignación al eliminar un supervisor que tiene subordinados directos.
 */
enum DeletionStrategy: string
{
    /**
     * Promover al abuelo/jefe superior (user_id del supervisor eliminado).
     * Si el supervisor eliminado era CEO (user_id = NULL), los subordinados pasan a ser Nivel 1 (raíces).
     */
    case ReassignToGrandparent = 'reassign_to_grandparent';

    /**
     * Reasignar los subordinados directos a un nuevo supervisor específico indicado por el usuario.
     */
    case ReassignToSpecificUser = 'reassign_to_specific';
}

