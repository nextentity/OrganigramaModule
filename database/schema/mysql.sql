-- =============================================================================
-- Organigrama multi-tenant · Esquema MySQL 8+ / MariaDB 10.5+
-- Idempotente: puede ejecutarse múltiples veces.
-- Uso directo:  mysql -u root -p organigrama < database/schema/mysql.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_hash` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified_by` varchar(255) DEFAULT NULL,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_hash` (`client_hash`),
  UNIQUE KEY `name` (`name`),
  KEY `client_hash_2` (`client_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_hash` varchar(128) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'Jefe directo (users.id). NULL = nivel 1 (CEO)',
  `email` varchar(128) NOT NULL,
  `password` varchar(128) NOT NULL,
  `name` varchar(500) NOT NULL,
  `last_name` varchar(500) NOT NULL,
  `identification_number` int(11) NOT NULL,
  `identification_code` int(11) DEFAULT NULL,
  `block_login` tinyint(1) NOT NULL DEFAULT 0,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(500) DEFAULT NULL,
  `modified` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `modified_by` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_hash` (`user_hash`),
  UNIQUE KEY `email` (`email`),
  KEY `client_id` (`client_id`),
  KEY `user_id` (`user_id`),
  -- Índice compuesto para consultas jerárquicas acotadas por tenant:
  -- "subordinados de X dentro del cliente Y" y "raíz(es) del cliente Y" (user_id IS NULL).
  KEY `idx_users_client_hierarchy` (`client_id`, `user_id`),
  KEY `identification_number` (`client_id`,`identification_number`,`identification_code`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Triggers de integridad jerárquica (defensa en profundidad; la capa de
-- servicios valida lo mismo y devuelve errores de negocio legibles).
--   HIERARCHY_CROSS_TENANT : el jefe debe existir y pertenecer al mismo client_id.
--   HIERARCHY_SELF_REFERENCE: un usuario no puede ser su propio jefe.
--   HIERARCHY_CYCLE        : el nuevo jefe no puede ser un subordinado (directo o indirecto).
--   HIERARCHY_TENANT_MOVE  : no se puede mover de cliente a quien tiene subordinados.
-- -----------------------------------------------------------------------------

DROP TRIGGER IF EXISTS `trg_users_before_insert_hierarchy`;
DROP TRIGGER IF EXISTS `trg_users_before_update_hierarchy`;

DELIMITER $$

CREATE TRIGGER `trg_users_before_insert_hierarchy`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
  IF NEW.`user_id` IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `users` WHERE `id` = NEW.`user_id` AND `client_id` <=> NEW.`client_id`
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'HIERARCHY_CROSS_TENANT: el jefe directo no existe o pertenece a otro cliente';
  END IF;
END$$

CREATE TRIGGER `trg_users_before_update_hierarchy`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
  DECLARE v_ancestor_id INT DEFAULT NULL;
  DECLARE v_depth INT DEFAULT 0;

  IF NOT (NEW.`client_id` <=> OLD.`client_id`) AND EXISTS (
    SELECT 1 FROM `users` WHERE `user_id` = OLD.`id`
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'HIERARCHY_TENANT_MOVE: no se puede cambiar de cliente a un usuario con subordinados';
  END IF;

  IF NEW.`user_id` IS NOT NULL
     AND NOT (NEW.`user_id` <=> OLD.`user_id` AND NEW.`client_id` <=> OLD.`client_id`) THEN

    IF NEW.`user_id` = NEW.`id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HIERARCHY_SELF_REFERENCE: un usuario no puede ser su propio jefe';
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM `users` WHERE `id` = NEW.`user_id` AND `client_id` <=> NEW.`client_id`
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'HIERARCHY_CROSS_TENANT: el jefe directo no existe o pertenece a otro cliente';
    END IF;

    -- Recorre la cadena de jefes hacia arriba; si aparece el propio usuario hay ciclo.
    SET v_ancestor_id = NEW.`user_id`;
    WHILE v_ancestor_id IS NOT NULL AND v_depth < 10000 DO
      IF v_ancestor_id = NEW.`id` THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'HIERARCHY_CYCLE: el jefe asignado es subordinado del usuario';
      END IF;
      SET v_ancestor_id = (SELECT `user_id` FROM `users` WHERE `id` = v_ancestor_id);
      SET v_depth = v_depth + 1;
    END WHILE;
  END IF;
END$$

DELIMITER ;

