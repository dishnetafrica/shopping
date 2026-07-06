-- CloudBSS durable event log + ledger projection (Postgres).
-- Append-only. Never UPDATE or DELETE an events row (Constitution rule 10: events are immutable).

-- ---- the ONE durable write model ----
-- global_position is assigned by POSTGRES (identity), so it is the single ordering authority even
-- when many short-lived processes (web requests / queue jobs) each append. The in-memory kernel
-- EventStore's per-process position is ignored on persist.
CREATE TABLE IF NOT EXISTS cloudbss_events (
    global_position    BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id           TEXT        NOT NULL UNIQUE,    -- idempotency key (DomainEvent.eventId)
    name               TEXT        NOT NULL,
    schema_version     INT         NOT NULL,
    aggregate_type     TEXT        NOT NULL,
    aggregate_id       TEXT        NOT NULL,
    aggregate_sequence INT         NOT NULL,
    payload            JSONB       NOT NULL,
    occurred_at        TIMESTAMPTZ NOT NULL,
    tenant_id          TEXT        NOT NULL,
    entity_id          TEXT        NOT NULL,
    correlation_id     TEXT,
    causation_id       TEXT,
    recorded_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT cloudbss_events_aggregate_seq_uniq UNIQUE (aggregate_id, aggregate_sequence)
);
CREATE INDEX IF NOT EXISTS cloudbss_events_aggregate_idx ON cloudbss_events (aggregate_id, aggregate_sequence);
CREATE INDEX IF NOT EXISTS cloudbss_events_tenant_idx    ON cloudbss_events (tenant_id, entity_id, global_position);
CREATE INDEX IF NOT EXISTS cloudbss_events_name_idx      ON cloudbss_events (name);

-- ---- durable ledger projection (so a balance is a cheap query, not a full-log replay) ----
-- One row per (tenant, entity, account, currency); net_minor is debit-positive / credit-negative.
-- Folded incrementally from JournalPosted; always rebuildable from the events log.
CREATE TABLE IF NOT EXISTS cloudbss_ledger_balances (
    tenant_id   TEXT   NOT NULL,
    entity_id   TEXT   NOT NULL,
    account     TEXT   NOT NULL,
    currency    TEXT   NOT NULL,
    net_minor   BIGINT NOT NULL DEFAULT 0,      -- debit + , credit -
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, entity_id, account, currency)
);

CREATE TABLE IF NOT EXISTS cloudbss_projection_checkpoints (
    projection  TEXT   PRIMARY KEY,
    position    BIGINT NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
