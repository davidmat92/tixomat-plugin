#!/bin/bash
# Tixomat Plugin – Deploy Script
# Pusht zu GitHub und deployed auf alle Server
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
REMOTE_TIXOMAT="runcloud@tixomat.de"
REMOTE_KITCHEN="runcloud@51.195.61.6"
RSYNC_OPTS="-avzc --delete --exclude=.git --exclude=node_modules --exclude=.claude"

echo "══════════════════════════════════"
echo "  Tixomat Deploy"
echo "══════════════════════════════════"

# 1. Git push
echo ""
echo "▸ Git push..."
cd "$PLUGIN_DIR"
git push origin main

# 2. Deploy tixomat.de
echo ""
echo "▸ Deploy → tixomat.de..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_TIXOMAT:/home/runcloud/webapps/Tixomat/wp-content/plugins/tixomat-plugin/"

# 3. Deploy kitchen-klub.de
echo ""
echo "▸ Deploy → kitchen-klub.de..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_KITCHEN:/home/runcloud/webapps/kitchen-klub/wp-content/plugins/tixomat/"

# 4. Deploy kitchen.mdj.events (demo-mdj-events)
echo ""
echo "▸ Deploy → kitchen.mdj.events..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_KITCHEN:/home/runcloud/webapps/demo-mdj-events/wp-content/plugins/tixomat/"

# 5. Deploy evendis
echo ""
echo "▸ Deploy → evendis..."
rsync $RSYNC_OPTS "$PLUGIN_DIR/" "$REMOTE_KITCHEN:/home/runcloud/webapps/evendis/wp-content/plugins/tixomat/"

# 6. OPcache reset
echo ""
echo "▸ OPcache reset..."
ssh "$REMOTE_TIXOMAT" "echo '<?php opcache_reset(); echo \"OK\"; unlink(__FILE__);' > /home/runcloud/webapps/Tixomat/opcache_reset.php"
curl -s https://tixomat.de/opcache_reset.php
echo ""
ssh "$REMOTE_KITCHEN" "php -r 'opcache_reset(); echo \"OK\n\";'" 2>/dev/null || true

echo ""
echo "══════════════════════════════════"
echo "  ✓ Deploy complete"
echo "══════════════════════════════════"
