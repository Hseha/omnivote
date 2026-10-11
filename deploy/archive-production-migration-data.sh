#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

DB_NAME="omnivote"
BACKUP_ROOT="/root/omnivote-backups"

log() { printf '\n==> %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

if [[ $EUID -ne 0 ]]; then
    fail "Run on the server with sudo bash."
fi

for tool in mysql mysqldump gzip sha256sum install wc date df du awk tr; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required tool '$tool' is missing."
done

db_exists="$(mysql --batch --skip-column-names -e \
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${DB_NAME}'")"
[[ "$db_exists" == "1" ]] || fail "Database ${DB_NAME} does not exist."

required_columns="$(mysql --batch --skip-column-names -e "
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE (table_schema='${DB_NAME}' AND table_name='registrar_imports' AND column_name IN ('id','grade_level'))
       OR (table_schema='${DB_NAME}' AND table_name='users' AND column_name IN ('id','year_level'))")"
[[ "$required_columns" == "4" ]] || fail "Expected production columns are missing; no archive was created."

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${BACKUP_ROOT}/predeploy-${timestamp}-$$"
[[ ! -e "$OUT_DIR" ]] || fail "Archive directory already exists: $OUT_DIR"
install -d -m 700 "$OUT_DIR"

log "Creating a fresh full database backup"
backup="${OUT_DIR}/omnivote-${timestamp}.sql.gz"
mysqldump --single-transaction --quick --routines --triggers --events "$DB_NAME" \
    | gzip -c > "$backup"
gzip -t "$backup"
chmod 600 "$backup"

log "Exporting registrar grade_level values before their column is dropped"
registrar_export="${OUT_DIR}/registrar-import-grade-level.tsv"
mysql --batch --raw --skip-column-names "$DB_NAME" -e \
    "SELECT id, grade_level FROM registrar_imports WHERE grade_level IS NOT NULL ORDER BY id" \
    > "$registrar_export"

log "Exporting noncanonical year levels before normalization"
year_level_export="${OUT_DIR}/users-noncanonical-year-level.tsv"
mysql --batch --raw --skip-column-names "$DB_NAME" -e "
    SELECT id, year_level
    FROM users
    WHERE year_level IS NOT NULL
      AND TRIM(year_level) NOT IN ('1','2','3','4','11','12','13','14')
    ORDER BY id" > "$year_level_export"

expected_registrar="$(mysql --batch --skip-column-names "$DB_NAME" -e \
    "SELECT COUNT(*) FROM registrar_imports WHERE grade_level IS NOT NULL")"
expected_year_levels="$(mysql --batch --skip-column-names "$DB_NAME" -e "
    SELECT COUNT(*)
    FROM users
    WHERE year_level IS NOT NULL
      AND TRIM(year_level) NOT IN ('1','2','3','4','11','12','13','14')")"
actual_registrar="$(wc -l < "$registrar_export" | tr -d ' ')"
actual_year_levels="$(wc -l < "$year_level_export" | tr -d ' ')"
[[ "$actual_registrar" == "$expected_registrar" ]] \
    || fail "Registrar export count mismatch: expected $expected_registrar, got $actual_registrar."
[[ "$actual_year_levels" == "$expected_year_levels" ]] \
    || fail "Year-level export count mismatch: expected $expected_year_levels, got $actual_year_levels."

manifest="${OUT_DIR}/manifest.txt"
{
    printf 'Created UTC: %s\n' "$timestamp"
    printf 'Database: %s\n' "$DB_NAME"
    printf 'Registrar rows with grade_level exported: %s\n' "$actual_registrar"
    printf 'Users with noncanonical year_level exported: %s\n' "$actual_year_levels"
    printf 'Full database dump: %s\n' "$backup"
    printf 'Registrar TSV: %s\n' "$registrar_export"
    printf 'Year-level TSV: %s\n' "$year_level_export"
    printf '\nSHA-256 checksums:\n'
    sha256sum "$backup" "$registrar_export" "$year_level_export"
} > "$manifest"
chmod 600 "$manifest"

log "Archive complete (production database was not modified)"
printf 'Archive directory: %s\n' "$OUT_DIR"
printf 'Backup size: '
du -h "$backup" | awk '{print $1}'
printf 'grade_level rows exported: %s\n' "$actual_registrar"
printf 'noncanonical year_level rows exported: %s\n' "$actual_year_levels"
printf 'Private manifest: %s\n' "$manifest"
df -h "$BACKUP_ROOT"
