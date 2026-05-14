#!/bin/bash
# DP-Checkout — Deploy-Script
# Pusht den dp-checkout/-Ordner per rsync zu dpconnect.de und resettet die Caches.
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)/dp-checkout"
REMOTE="root@65.108.193.227"
REMOTE_PATH="/home/runcloud/webapps/dpconnect-v2/wp-content/plugins/dp-checkout"
WP_PATH="/home/runcloud/webapps/dpconnect-v2"
RSYNC_OPTS="-avzc --delete --exclude=.git --exclude=node_modules --exclude=.DS_Store"

if [ ! -d "$PLUGIN_DIR" ]; then
    echo "✗ Plugin-Ordner nicht gefunden: $PLUGIN_DIR"
    exit 1
fi

echo "══════════════════════════════════"
echo "  DP-Checkout → dpconnect.de"
echo "══════════════════════════════════"

# 1. Optional: Git push, wenn Repo + Remote vorhanden
if git -C "$(dirname "$PLUGIN_DIR")" rev-parse --git-dir > /dev/null 2>&1; then
    if git -C "$(dirname "$PLUGIN_DIR")" remote get-url origin > /dev/null 2>&1; then
        echo ""
        echo "▸ Git push (origin main)..."
        git -C "$(dirname "$PLUGIN_DIR")" push origin main || echo "  (push uebersprungen)"
    fi
fi

# 2. rsync hochladen
echo ""
echo "▸ rsync → $REMOTE:$REMOTE_PATH"
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE:$REMOTE_PATH/"

# 3. Ownership / Permissions
echo ""
echo "▸ chown + chmod..."
ssh "$REMOTE" "chown -R runcloud:runcloud '$REMOTE_PATH' && \
    find '$REMOTE_PATH' -type d -exec chmod 755 {} \; && \
    find '$REMOTE_PATH' -type f -exec chmod 644 {} \;"

# 4. Caches flushen + sicherstellen, dass das Plugin aktiv ist
echo ""
echo "▸ wp-cli: Plugin aktiv halten + Caches flushen..."
ssh "$REMOTE" "cd '$WP_PATH' && \
    sudo -u runcloud wp plugin is-active dp-checkout 2>/dev/null || sudo -u runcloud wp plugin activate dp-checkout && \
    sudo -u runcloud wp litespeed-purge all 2>/dev/null | head -1 || true && \
    sudo -u runcloud wp eval 'function_exists(\"opcache_reset\") && opcache_reset();'"

echo ""
echo "══════════════════════════════════"
echo "  ✓ Deploy complete"
echo "══════════════════════════════════"
echo ""
echo "  → https://dpconnect.de/kasse/bestellung/"
