#!/usr/bin/env bash
# Nginx + Let's Encrypt for a subdomain only (e.g. lms.holyface.school).
# Usage: sudo bash deploy/install-subdomain-nginx.sh lms.holyface.school admin@holyface.school
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"

if [ -z "$DOMAIN" ] || [ -z "$EMAIL" ]; then
    echo "Usage: sudo $0 <subdomain.fqdn> <letsencrypt-email>"
    echo "Example: sudo $0 lms.holyface.school admin@holyface.school"
    exit 1
fi

apt-get update
apt-get install -y nginx certbot python3-certbot-nginx

cat > "/etc/nginx/sites-available/campusloop" <<EOF
server {
    listen 80;
    server_name ${DOMAIN};

    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8000/api/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        client_max_body_size 100M;
    }

    location /up {
        proxy_pass http://127.0.0.1:8000/up;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
    }

    location /storage/ {
        proxy_pass http://127.0.0.1:8000/storage/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        client_max_body_size 100M;
    }
}
EOF

ln -sf /etc/nginx/sites-available/campusloop /etc/nginx/sites-enabled/campusloop
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect

echo "Nginx + SSL configured for https://${DOMAIN}"
echo "Update /opt/campusloop/campusloop-backend/.env and compose.prod.env:"
echo "  APP_URL=https://${DOMAIN}"
echo "  FRONTEND_URL=https://${DOMAIN}"
echo "  VITE_API_BASE_URL=https://${DOMAIN}/api"
echo "  SANCTUM_STATEFUL_DOMAINS=${DOMAIN}"
echo "Then rebuild frontend and run: php artisan config:cache"
