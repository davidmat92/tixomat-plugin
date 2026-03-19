#!/bin/bash
# Tixomat Plugin – Deploy Script
# Pusht zu GitHub und deployed auf alle Server
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE_TIXOMAT="runcloud@tixomat.de"
REMOTE_KITCHEN="runcloud@51.195.61.6"

# Alle Deploy-Targets (Server:Pfad)
declare -A TARGETS=(
  ["tixomat.de"]="$REMOTE_TIXOMAT:/home/runcloud/webapps/Tixomat/wp-content/plugins/tixomat-plugin/"
  ["kitchen-klub.de"]="$REMOTE_KITCHEN:/home/runcloud/webapps/kitchen-klub/wp-content/plugins/tixomat/"
  ["kitchen.mdj.events"]="$REMOTE_KITCHEN:/home/runcloud/webapps/demo-mdj-events/wp-content/plugins/tixomat/"
)

echo "══════════════════════════════════"
echo "  Tixomat Deploy"
echo "══════════════════════════════════"

# 1. Git push
echo ""
echo "▸ Git push..."
cd "$PLUGIN_DIR"
git push origin main

# 2. Deploy to all targets
for site in "${!TARGETS[@]}"; do
  echo ""
  echo "▸ Deploy → $site..."
  rsync -avzc --delete --exclude='.git' --exclude='node_modules' --exclude='.claude' \
    "$PLUGIN_DIR/" "${TARGETS[$site]}"
done

# 3. OPcache reset tixomat.de
echo ""
echo "▸ OPcache reset..."
ssh "$REMOTE_TIXOMAT" "echo '<?php opcache_reset(); echo \"OK\"; unlink(__FILE__);' > /home/runcloud/webapps/Tixomat/opcache_reset.php"
curl -s https://tixomat.de/opcache_reset.php
echo ""

# 4. OPcache reset kitchen server
ssh "$REMOTE_KITCHEN" "php -r 'opcache_reset(); echo \"OK\n\";'" 2>/dev/null || true

echo ""
echo "══════════════════════════════════"
echo "  ✓ Deploy complete"
echo "══════════════════════════════════"
