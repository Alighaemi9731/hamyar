#!/bin/bash
# MobiShop — Postgres bootstrap.
#
# Golden rule 1: Row-Level Security is the second line of tenancy defence. Postgres
# lets *table owners* and *superusers* bypass RLS unless FORCE ROW LEVEL SECURITY is
# set, so we deliberately split two roles:
#
#   ${POSTGRES_USER}  — owner. Runs migrations. Owns the schema.
#   ${APP_DB_USER}    — the role the application connects as. Owns nothing,
#                       is not a superuser, therefore RLS always applies to it.
#
# Migrations additionally apply FORCE ROW LEVEL SECURITY (see the enableRls() helper)
# so even the owner cannot read across tenants outside of an explicit escape hatch.

set -euo pipefail

APP_DB_USER="${APP_DB_USER:-mobishop_app}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-app-secret}"
TEST_DB="${POSTGRES_DB}_test"

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
    CREATE ROLE ${APP_DB_USER} LOGIN PASSWORD '${APP_DB_PASSWORD}';

    GRANT CONNECT ON DATABASE ${POSTGRES_DB} TO ${APP_DB_USER};
    GRANT USAGE ON SCHEMA public TO ${APP_DB_USER};

    -- Everything the owner creates from now on is usable by the app role.
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${APP_DB_USER};
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT USAGE, SELECT ON SEQUENCES TO ${APP_DB_USER};
SQL

# Dedicated database for the Pest suite so a test run never truncates dev data.
psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
    CREATE DATABASE ${TEST_DB} OWNER ${POSTGRES_USER};
SQL

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${TEST_DB}" <<-SQL
    GRANT CONNECT ON DATABASE ${TEST_DB} TO ${APP_DB_USER};
    GRANT USAGE ON SCHEMA public TO ${APP_DB_USER};
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${APP_DB_USER};
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT USAGE, SELECT ON SEQUENCES TO ${APP_DB_USER};
SQL
