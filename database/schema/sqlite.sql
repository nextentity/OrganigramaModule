-- =============================================================================
-- Organigrama multi-tenant · Esquema SQLite (fallback cuando MySQL no está disponible)
-- Equivalente funcional de database/schema/mysql.sql.
-- Nota: la directiva DELIMITER es interpretada por SqlScriptParser (no por sqlite3 CLI).
-- =============================================================================

CREATE TABLE IF NOT EXISTS clients (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  client_hash VARCHAR(128) NOT NULL UNIQUE,
  name        VARCHAR(255) NOT NULL UNIQUE,
  description VARCHAR(500) DEFAULT NULL,
  created_by  VARCHAR(255) DEFAULT NULL,
  created     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_by VARCHAR(255) DEFAULT NULL,
  modified    DATETIME     DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  user_hash             VARCHAR(128) NOT NULL UNIQUE,
  client_id             INTEGER      DEFAULT NULL REFERENCES clients (id) ON DELETE CASCADE ON UPDATE CASCADE,
  user_id               INTEGER      DEFAULT NULL REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  email                 VARCHAR(128) NOT NULL UNIQUE,
  password              VARCHAR(128) NOT NULL,
  name                  VARCHAR(500) NOT NULL,
  last_name             VARCHAR(500) NOT NULL,
  identification_number INTEGER      NOT NULL,
  identification_code   INTEGER      DEFAULT NULL,
  block_login           INTEGER      NOT NULL DEFAULT 0 CHECK (block_login IN (0, 1)),
  created               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by            VARCHAR(500) DEFAULT NULL,
  modified              DATETIME     DEFAULT NULL,
  modified_by           VARCHAR(500) DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_users_client_id ON users (client_id);
CREATE INDEX IF NOT EXISTS idx_users_user_id ON users (user_id);
CREATE INDEX IF NOT EXISTS idx_users_client_hierarchy ON users (client_id, user_id);
CREATE INDEX IF NOT EXISTS idx_users_identification ON users (client_id, identification_number, identification_code);

-- -----------------------------------------------------------------------------
-- Emulación de "ON UPDATE CURRENT_TIMESTAMP"
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_clients_after_update_modified;
DROP TRIGGER IF EXISTS trg_users_after_update_modified;

-- -----------------------------------------------------------------------------
-- Integridad jerárquica (mismos códigos de error que en MySQL)
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_users_before_insert_hierarchy;
DROP TRIGGER IF EXISTS trg_users_before_update_tenant_move;
DROP TRIGGER IF EXISTS trg_users_before_update_self_reference;
DROP TRIGGER IF EXISTS trg_users_before_update_cross_tenant;
DROP TRIGGER IF EXISTS trg_users_before_update_cycle;

DELIMITER $$

CREATE TRIGGER trg_clients_after_update_modified
AFTER UPDATE ON clients
FOR EACH ROW WHEN NEW.modified IS OLD.modified
BEGIN
  UPDATE clients SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
END$$

CREATE TRIGGER trg_users_after_update_modified
AFTER UPDATE ON users
FOR EACH ROW WHEN NEW.modified IS OLD.modified
BEGIN
  UPDATE users SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
END$$

CREATE TRIGGER trg_users_before_insert_hierarchy
BEFORE INSERT ON users
FOR EACH ROW WHEN NEW.user_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM users WHERE id = NEW.user_id AND client_id IS NEW.client_id
)
BEGIN
  SELECT RAISE(ABORT, 'HIERARCHY_CROSS_TENANT: el jefe directo no existe o pertenece a otro cliente');
END$$

CREATE TRIGGER trg_users_before_update_tenant_move
BEFORE UPDATE OF client_id ON users
FOR EACH ROW WHEN NEW.client_id IS NOT OLD.client_id AND EXISTS (
  SELECT 1 FROM users WHERE user_id = OLD.id
)
BEGIN
  SELECT RAISE(ABORT, 'HIERARCHY_TENANT_MOVE: no se puede cambiar de cliente a un usuario con subordinados');
END$$

CREATE TRIGGER trg_users_before_update_self_reference
BEFORE UPDATE OF user_id ON users
FOR EACH ROW WHEN NEW.user_id IS NOT NULL AND NEW.user_id = NEW.id
BEGIN
  SELECT RAISE(ABORT, 'HIERARCHY_SELF_REFERENCE: un usuario no puede ser su propio jefe');
END$$

CREATE TRIGGER trg_users_before_update_cross_tenant
BEFORE UPDATE OF user_id, client_id ON users
FOR EACH ROW WHEN NEW.user_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM users WHERE id = NEW.user_id AND client_id IS NEW.client_id
)
BEGIN
  SELECT RAISE(ABORT, 'HIERARCHY_CROSS_TENANT: el jefe directo no existe o pertenece a otro cliente');
END$$

CREATE TRIGGER trg_users_before_update_cycle
BEFORE UPDATE OF user_id ON users
FOR EACH ROW WHEN NEW.user_id IS NOT NULL AND NEW.user_id <> NEW.id AND EXISTS (
  WITH RECURSIVE ancestors (id) AS (
    SELECT NEW.user_id
    UNION
    SELECT u.user_id FROM users u INNER JOIN ancestors a ON u.id = a.id WHERE u.user_id IS NOT NULL
  )
  SELECT 1 FROM ancestors WHERE id = NEW.id
)
BEGIN
  SELECT RAISE(ABORT, 'HIERARCHY_CYCLE: el jefe asignado es subordinado del usuario');
END$$

DELIMITER ;

