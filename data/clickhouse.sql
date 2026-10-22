CREATE DATABASE IF NOT EXISTS bigstocks;

-- Generic append-only archive of provider records.
-- idempotency_hash = SHA-256(endpoint + canonicalized record payload)
-- Do not include fetched_at in that hash.
CREATE TABLE IF NOT EXISTS bigstocks.massive_raw
(
    endpoint            LowCardinality(String),
    request_key         FixedString(64),
    record_key          String,
    idempotency_hash    FixedString(64),
    symbol              LowCardinality(String),
    provider_updated_at Nullable(DateTime64(3, 'UTC')),
    observed_at         Nullable(DateTime64(3, 'UTC')),
    fetched_at          DateTime64(3, 'UTC'),
    schema_version      UInt16 DEFAULT 1,
    payload             String
)
ENGINE = ReplacingMergeTree(fetched_at)
PARTITION BY cityHash64(idempotency_hash) % 64
ORDER BY (endpoint, idempotency_hash);

-- Snapshot values: latest trade/quote and day/minute/previous-day aggregates.
CREATE TABLE IF NOT EXISTS bigstocks.stock_snapshot
(
    symbol              LowCardinality(String),
    observed_at         DateTime64(3, 'UTC'),
    last_price          Nullable(Decimal(18, 6)),
    last_trade_size     Nullable(UInt64),
    last_trade_ts       Nullable(DateTime64(9, 'UTC')),
    bid_price           Nullable(Decimal(18, 6)),
    bid_size            Nullable(UInt64),
    ask_price           Nullable(Decimal(18, 6)),
    ask_size            Nullable(UInt64),
    day_open            Nullable(Decimal(18, 6)),
    day_high            Nullable(Decimal(18, 6)),
    day_low             Nullable(Decimal(18, 6)),
    day_close           Nullable(Decimal(18, 6)),
    day_volume          Nullable(UInt64),
    day_vwap            Nullable(Decimal(18, 6)),
    previous_close      Nullable(Decimal(18, 6)),
    todays_change       Nullable(Decimal(18, 6)),
    todays_change_pct   Nullable(Float64),
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(observed_at)
ORDER BY (symbol, observed_at);

-- OHLCV bars from grouped daily, custom range, or WebSocket aggregate feeds.
CREATE TABLE IF NOT EXISTS bigstocks.stock_aggregates
(
    symbol          LowCardinality(String),
    timespan        LowCardinality(String),
    multiplier      UInt32,
    bar_start       DateTime64(3, 'UTC'),
    bar_end         Nullable(DateTime64(3, 'UTC')),
    open            Decimal(18, 6),
    high            Decimal(18, 6),
    low             Decimal(18, 6),
    close           Decimal(18, 6),
    volume          UInt64,
    vwap            Nullable(Decimal(18, 6)),
    transaction_count Nullable(UInt64),
    adjusted        Bool DEFAULT true,
    source          LowCardinality(String),
    ingested_at     DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(bar_start)
ORDER BY (symbol, timespan, multiplier, bar_start, adjusted);

-- Individual trade prints. Keep condition codes and exchange IDs as provided.
CREATE TABLE IF NOT EXISTS bigstocks.ticker
(
    symbol              LowCardinality(String),
    trade_ts            DateTime64(9, 'UTC'),
    participant_ts      Nullable(DateTime64(9, 'UTC')),
    sip_ts              Nullable(DateTime64(9, 'UTC')),
    trf_ts              Nullable(DateTime64(9, 'UTC')),
    sequence_number     Nullable(UInt64),
    trade_id            String,
    exchange_id         Nullable(UInt16),
    tape                Nullable(UInt8),
    price               Decimal(18, 6),
    size                UInt64,
    conditions          Array(UInt16),
    indicators          Array(UInt16),
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(trade_ts)
ORDER BY (symbol, trade_ts, sequence_number, trade_id);

-- NBBO quote updates. This is top-of-book, not Level 2 depth/MMID data.
CREATE TABLE IF NOT EXISTS bigstocks.stock_quotes
(
    symbol              LowCardinality(String),
    quote_ts            DateTime64(9, 'UTC'),
    participant_ts      Nullable(DateTime64(9, 'UTC')),
    sip_ts              Nullable(DateTime64(9, 'UTC')),
    trf_ts              Nullable(DateTime64(9, 'UTC')),
    sequence_number     Nullable(UInt64),
    bid_price           Nullable(Decimal(18, 6)),
    bid_size            Nullable(UInt64),
    bid_exchange_id     Nullable(UInt16),
    ask_price           Nullable(Decimal(18, 6)),
    ask_size            Nullable(UInt64),
    ask_exchange_id     Nullable(UInt16),
    conditions          Array(UInt16),
    indicators          Array(UInt16),
    tape                Nullable(UInt8),
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(quote_ts)
ORDER BY (symbol, quote_ts, sequence_number);

-- Calculated indicators. parameters_json stores window/series-specific settings.
CREATE TABLE IF NOT EXISTS bigstocks.technical_indicators
(
    symbol              LowCardinality(String),
    indicator           LowCardinality(String),
    observed_at         DateTime64(3, 'UTC'),
    timespan            LowCardinality(String),
    multiplier          UInt32,
    parameters_json     String,
    value               Nullable(Float64),
    value_signal        Nullable(Float64),
    value_histogram     Nullable(Float64),
    source              LowCardinality(String),
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(observed_at)
ORDER BY (symbol, indicator, timespan, multiplier, observed_at, parameters_json);

-- Captured market operation status and holiday responses.
CREATE TABLE IF NOT EXISTS bigstocks.market_operations
(
    dataset             LowCardinality(String),
    observed_at         DateTime64(3, 'UTC'),
    market_or_exchange  LowCardinality(String),
    status              LowCardinality(String),
    event_date          Nullable(Date),
    open_ts             Nullable(DateTime64(3, 'UTC')),
    close_ts            Nullable(DateTime64(3, 'UTC')),
    payload             String,
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(observed_at)
ORDER BY (dataset, market_or_exchange, observed_at, event_date);

-- Provider financial ratios/statements, preserving named values as JSON.
CREATE TABLE IF NOT EXISTS bigstocks.fundamentals
(
    symbol              LowCardinality(String),
    dataset             LowCardinality(String),
    period_end          Nullable(Date),
    filing_date         Nullable(Date),
    fiscal_year         Nullable(Int32),
    fiscal_period       LowCardinality(String),
    timeframe           LowCardinality(String),
    observed_at         DateTime64(3, 'UTC'),
    values_json         String,
    ingested_at         DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(observed_at)
ORDER BY (symbol, dataset, period_end, timeframe, observed_at);

-- Filings, disclosures, and news are lower-volume, semi-structured records.
CREATE TABLE IF NOT EXISTS bigstocks.research_raw
(
    dataset             LowCardinality(String),
    provider_record_id  String,
    symbol              LowCardinality(String),
    published_at        Nullable(DateTime64(3, 'UTC')),
    filed_at            Nullable(DateTime64(3, 'UTC')),
    fetched_at          DateTime64(3, 'UTC'),
    idempotency_hash    FixedString(64),
    payload             String
)
ENGINE = ReplacingMergeTree(fetched_at)
PARTITION BY cityHash64(idempotency_hash) % 32
ORDER BY (dataset, idempotency_hash);
