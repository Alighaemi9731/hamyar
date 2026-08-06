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
