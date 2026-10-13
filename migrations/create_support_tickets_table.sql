-- =============================================================================
-- Support Ticket Triaging Module · SQLite Migration (Idempotent)
-- =============================================================================

PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS support_tickets (
    id                  VARCHAR(36)  NOT NULL PRIMARY KEY,
    source_channel      VARCHAR(50)  NOT NULL,
    customer_id         VARCHAR(128) NOT NULL,
    raw_payload         TEXT         NOT NULL,
    status              VARCHAR(30)  NOT NULL CHECK (status IN ('PENDING', 'TRIAGED', 'AUTO_FIXING', 'OPEN', 'RESOLVED', 'FAILED')),
    triage_result       TEXT         DEFAULT NULL,
    github_issue_number INTEGER      DEFAULT NULL,
    github_pr_url       VARCHAR(500) DEFAULT NULL,
    jira_key            VARCHAR(100) DEFAULT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_support_tickets_status ON support_tickets (status);
CREATE INDEX IF NOT EXISTS idx_support_tickets_customer_id ON support_tickets (customer_id);
CREATE INDEX IF NOT EXISTS idx_support_tickets_created_at ON support_tickets (created_at);
CREATE INDEX IF NOT EXISTS idx_support_tickets_channel ON support_tickets (source_channel);

