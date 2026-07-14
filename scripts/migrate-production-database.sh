#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/rpg-extraction}"
STAMP="$(date +%Y%m%d%H%M%S)"
ARCHIVE="$BACKUP_DIR/next-game-tables-$STAMP.dump"
PASSWORD_FILE="/root/.rpg-db-password"

sudo install -d -o postgres -g postgres -m 700 "$BACKUP_DIR"

if sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='rpg'" | grep -q 1; then
  table_count="$(sudo -u postgres psql -d rpg -Atqc "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'game_%'")"
  if [ "$table_count" -gt 0 ]; then
    echo "rpg database already contains game tables; refusing to overwrite it" >&2
    exit 1
  fi
fi

echo "Creating recoverable production snapshot: $ARCHIVE"
sudo -u postgres pg_dump --format=custom --no-owner --dbname=next \
  --table='public.game_*' --file="$ARCHIVE"
sudo chmod 600 "$ARCHIVE"

if ! sudo test -s "$PASSWORD_FILE"; then
  sudo sh -c "umask 077; openssl rand -hex 32 > '$PASSWORD_FILE'"
fi

db_password="$(sudo cat "$PASSWORD_FILE")"
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='rpg'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE rpg LOGIN PASSWORD '$db_password'"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE rpg LOGIN PASSWORD '$db_password'"
fi

if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='rpg'" | grep -q 1; then
  sudo -u postgres createdb --owner=rpg rpg
fi

echo "Restoring game tables into the independent rpg database"
sudo -u postgres pg_restore --exit-on-error --no-owner --role=rpg --dbname=rpg "$ARCHIVE"

sudo -u postgres psql -v ON_ERROR_STOP=1 --dbname=rpg <<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id bigserial PRIMARY KEY,
    migration varchar(255) NOT NULL,
    batch integer NOT NULL
);
ALTER TABLE migrations OWNER TO rpg;
ALTER SEQUENCE migrations_id_seq OWNER TO rpg;
GRANT USAGE, CREATE ON SCHEMA public TO rpg;

INSERT INTO migrations (migration, batch)
SELECT migration, 1
FROM (VALUES
    ('2026_02_13_000000_create_game_tables'),
    ('2026_06_30_084700_add_skill_tree_columns_to_game_skill_definitions_table'),
    ('2026_06_30_084800_keep_fireball_single_target')
) AS expected(migration)
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migrations.migration = expected.migration
);

DELETE FROM game_combat_logs
WHERE created_at < now() - interval '24 hours';
SQL

echo "Validating restored table inventory"
tables="$(sudo -u postgres psql --dbname=rpg -Atqc "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'game_%' ORDER BY tablename")"
while IFS= read -r table; do
  [ -n "$table" ] || continue
  source_count="$(sudo -u postgres psql --dbname=next -Atqc "SELECT count(*) FROM $table")"
  target_count="$(sudo -u postgres psql --dbname=rpg -Atqc "SELECT count(*) FROM $table")"
  echo "$table source=$source_count target=$target_count"
done <<< "$tables"

echo "Snapshot retained at $ARCHIVE"
