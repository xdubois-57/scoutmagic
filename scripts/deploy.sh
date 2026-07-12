#!/bin/bash
set -euo pipefail

# Usage: ./scripts/deploy.sh [--dry-run]
# Environment variables required: FTP_HOST, FTP_USER, FTP_PASS, FTP_REMOTE_DIR
# Optional: MIGRATION_URL (URL to trigger DB migration after upload)

DRY_RUN=""
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN="--dry-run"
fi

# Build
composer install --no-dev --optimize-autoloader --no-interaction

# Deploy via lftp (differential sync: upload changed, delete removed)
lftp -c "
  set ssl:verify-certificate no;
  open -u ${FTP_USER},${FTP_PASS} ${FTP_HOST};
  mirror --reverse --delete --verbose ${DRY_RUN} \
    --exclude vendor/ \
    --exclude .git/ \
    --exclude .github/ \
    --exclude tests/ \
    --exclude storage/keys/ \
    --exclude storage/config/ \
    --exclude storage/temp/ \
    --exclude config/app.php \
    --exclude .gitignore \
    --exclude .env \
    ./ ${FTP_REMOTE_DIR};
"

# Trigger DB migration if URL provided
if [[ -n "${MIGRATION_URL:-}" ]]; then
    echo "Triggering database migration..."
    curl -s -o /dev/null -w "%{http_code}" "${MIGRATION_URL}" || echo "Migration trigger failed"
fi

echo "Deployment complete."
