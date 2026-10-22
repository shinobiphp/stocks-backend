BEGIN;

CREATE TABLE users (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_ts      TIMESTAMPTZ,
    email           TEXT NOT NULL,
    display_name    TEXT,
    enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT users_email_not_blank CHECK (length(trim(email)) > 0)
);

CREATE UNIQUE INDEX users_active_email_uq
    ON users (lower(email))
    WHERE deleted_ts IS NULL;

CREATE TABLE auth_tokens (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_ts      TIMESTAMPTZ NOT NULL,
    used_ts         TIMESTAMPTZ,
    token_hash      TEXT NOT NULL UNIQUE,
    CONSTRAINT auth_tokens_expiration_after_creation
        CHECK (expires_ts > created_ts)
);

CREATE INDEX auth_tokens_user_created_idx
    ON auth_tokens (user_id, created_ts DESC);

CREATE INDEX auth_tokens_unspent_expiration_idx
    ON auth_tokens (expires_ts)
    WHERE used_ts IS NULL;

CREATE TABLE stocks (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_ts          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_ts          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_ts          TIMESTAMPTZ,
    symbol              TEXT NOT NULL,
    name                TEXT,
    company_details     JSONB NOT NULL DEFAULT '{}'::jsonb,
    market_snapshot     JSONB NOT NULL DEFAULT '{}'::jsonb,
    enabled             BOOLEAN NOT NULL DEFAULT TRUE,
    provider_updated_ts TIMESTAMPTZ,
    CONSTRAINT stocks_symbol_not_blank CHECK (length(trim(symbol)) > 0),
    CONSTRAINT stocks_company_details_object
        CHECK (jsonb_typeof(company_details) = 'object'),
    CONSTRAINT stocks_market_snapshot_object
        CHECK (jsonb_typeof(market_snapshot) = 'object')
);

CREATE UNIQUE INDEX stocks_active_symbol_uq
    ON stocks (upper(symbol))
    WHERE deleted_ts IS NULL;

CREATE TABLE workspaces (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_ts  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_ts  TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_ts  TIMESTAMPTZ,
    name        TEXT NOT NULL,
    enabled     BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT workspaces_name_not_blank CHECK (length(trim(name)) > 0),
    CONSTRAINT workspaces_id_user_id_uq UNIQUE (id, user_id)
);

CREATE INDEX workspaces_user_idx
    ON workspaces (user_id)
    WHERE deleted_ts IS NULL;

CREATE TABLE watchlists (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    workspace_id    BIGINT,
    created_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_ts      TIMESTAMPTZ,
    name            TEXT NOT NULL,
    enabled         BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT watchlists_name_not_blank CHECK (length(trim(name)) > 0),
    CONSTRAINT watchlists_workspace_owner_fk
        FOREIGN KEY (workspace_id, user_id)
        REFERENCES workspaces (id, user_id)
        ON DELETE CASCADE
);

CREATE INDEX watchlists_user_idx
    ON watchlists (user_id)
    WHERE deleted_ts IS NULL;

CREATE INDEX watchlists_workspace_idx
    ON watchlists (workspace_id)
    WHERE deleted_ts IS NULL;

CREATE TABLE watchlist_stocks (
    watchlist_id BIGINT NOT NULL REFERENCES watchlists(id) ON DELETE CASCADE,
    stock_id     BIGINT NOT NULL REFERENCES stocks(id) ON DELETE RESTRICT,
    created_ts   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (watchlist_id, stock_id)
);

CREATE INDEX watchlist_stocks_stock_idx ON watchlist_stocks (stock_id);

CREATE TABLE workspace_stocks (
    workspace_id BIGINT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    stock_id     BIGINT NOT NULL REFERENCES stocks(id) ON DELETE RESTRICT,
    created_ts   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (workspace_id, stock_id)
);

CREATE INDEX workspace_stocks_stock_idx ON workspace_stocks (stock_id);

-- Request-response cache. request_key is SHA-256 of the normalized
-- method + path + query parameters; never include the API key.
CREATE TABLE api_cache (
    request_key     CHAR(64) PRIMARY KEY,
    provider        TEXT NOT NULL DEFAULT 'massive',
    http_method     TEXT NOT NULL,
    endpoint_path   TEXT NOT NULL,
    normalized_query JSONB NOT NULL DEFAULT '{}'::jsonb,
    response_status SMALLINT,
    response_body   JSONB NOT NULL,
    fetched_ts      TIMESTAMPTZ NOT NULL DEFAULT now(),
    fresh_until_ts  TIMESTAMPTZ NOT NULL,
    etag            TEXT,
    last_modified   TEXT,
    CONSTRAINT api_cache_key_sha256 CHECK (request_key ~ '^[0-9a-f]{64}$'),
    CONSTRAINT api_cache_query_object
        CHECK (jsonb_typeof(normalized_query) = 'object')
);

CREATE INDEX api_cache_fresh_until_idx ON api_cache (fresh_until_ts);

-- Synchronization cursors for paginated or incremental endpoint jobs.
CREATE TABLE provider_sync_state (
    provider       TEXT NOT NULL,
    dataset        TEXT NOT NULL,
    sync_key       TEXT NOT NULL DEFAULT '',
    cursor         TEXT,
    watermark_ts   TIMESTAMPTZ,
    last_started_ts TIMESTAMPTZ,
    last_finished_ts TIMESTAMPTZ,
    last_status    TEXT,
    updated_ts     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (provider, dataset, sync_key)
);

COMMIT;
