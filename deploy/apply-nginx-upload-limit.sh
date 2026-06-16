#!/usr/bin/env bash
# One-time fix on an already-running EC2 when uploads return 413 Request Entity Too Large.
# Usage: sudo bash deploy/apply-nginx-upload-limit.sh
set -euo pipefail

NGINX_SITE="/etc/nginx/sites-available/campusloop"

if [ ! -f "$NGINX_SITE" ]; then
    echo "Nginx site not found at $NGINX_SITE"
    echo "Copy deploy/nginx/campusloop-ip.conf manually if you use IP-only access."
    exit 1
fi

if grep -q 'client_max_body_size' "$NGINX_SITE"; then
    echo "client_max_body_size already present in $NGINX_SITE"
else
    sed -i '/server_name /a\
\
    client_max_body_size 100M;' "$NGINX_SITE"
    echo "Added client_max_body_size 100M to $NGINX_SITE"
fi

nginx -t
systemctl reload nginx
echo "Host nginx reloaded. Rebuild backend container to apply PHP upload limits:"
echo "  cd /opt/campusloop/campusloop-backend"
echo "  docker compose -f compose.prod.yaml --env-file compose.prod.env up -d --build backend queue"
