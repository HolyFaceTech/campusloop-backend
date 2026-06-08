#!/usr/bin/env bash
# Install host Nginx reverse proxy + Let's Encrypt SSL for CampusLoop.
# Usage: sudo bash deploy/install-host-nginx.sh your-domain.com [admin@email.com]
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-admin@${DOMAIN}}"

if [ -z "$DOMAIN" ]; then
    echo "Usage: sudo $0 your-domain.com [letsencrypt-email]"
    exit 1
fi

apt-get update
apt-get install -y nginx certbot python3-certbot-nginx

cat > "/etc/nginx/sites-available/campusloop" <<EOF
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

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
    }
}
EOF

ln -sf /etc/nginx/sites-available/campusloop /etc/nginx/sites-enabled/campusloop
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

certbot --nginx -d "$DOMAIN" -d "www.$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect

echo "Nginx + SSL configured for https://${DOMAIN}"
echo "Ensure compose.prod.env uses APP_URL=https://${DOMAIN} and FRONTEND_URL=https://${DOMAIN}"
