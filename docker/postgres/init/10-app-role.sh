#!/bin/bash
# MobiShop — Postgres bootstrap.
#
# Golden rule 1: Row-Level Security is the second line of tenancy defence. Postgres
# exempts two kinds of role from RLS policies:
#
#   1. superusers, and roles with BYPASSRLS — always exempt, no way to force them;
#   2. the table owner — exempt UNLESS the table declares FORCE ROW LEVEL SECURITY.
#
# The role created here (${APP_DB_USER}) is deliberately NOT a superuser, and every
# tenant table is created with FORCE ROW LEVEL SECURITY by the enableRls() migration
# helper. Together that means RLS applies to the application even though the
# application owns its own tables — which in turn lets migrations, seeders, tests and
# request traffic all share one connection with no privilege juggling.
#
# ${POSTGRES_USER} remains a superuser but is infrastructure-only: `make psql`,
# backups and manual surgery. No application code ever connects as it. If you use it
# to poke at data, remember RLS will NOT protect you and you will see every tenant.

set -euo pipefail

APP_DB_USER="${APP_DB_USER:-mobishop_app}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-app-secret}"
TEST_DB="${POSTGRES_DB}_test"

create_app_role() {
    psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
        CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
SQL
}

grant_database() {
    local db="$1"

    psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${db}" <<-SQL
        GRANT CONNECT ON DATABASE ${db} TO ${APP_DB_USER};

        -- The app role creates and owns its own tables (migrations run as this role),
        -- so it needs CREATE on the schema. FORCE ROW LEVEL SECURITY is what keeps
        -- ownership from becoming an RLS bypass.
        GRANT USAGE, CREATE ON SCHEMA public TO ${APP_DB_USER};
SQL
}

create_app_role

# Dedicated database for the Pest suite so a test run never truncates dev data.
psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
    CREATE DATABASE ${TEST_DB} OWNER ${POSTGRES_USER};
SQL

grant_database "${POSTGRES_DB}"
grant_database "${TEST_DB}"

# ---------------------------------------------------------------------------
# pg_stat_statements is PRELOADED by postgresql.prod.conf, but a preloaded
# library is not an available view: the extension still has to be created in
# the database. Without this the runbook's advice to read the slowest
# statements after a load test fails with `relation "pg_stat_statements" does
# not exist` — found exactly that way, during the first real load test.
# ---------------------------------------------------------------------------
# Guarded on the library actually being preloaded, because this script is shared with
# the dev stack (compose.yaml mounts the same directory) and only postgresql.prod.conf
# preloads it. `set -euo pipefail` plus ON_ERROR_STOP would turn a failure here into a
# database that never finishes initialising.
if psql -At --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" \
        -c "SHOW shared_preload_libraries" | grep -q pg_stat_statements; then
    psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
        CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
SQL
fi
