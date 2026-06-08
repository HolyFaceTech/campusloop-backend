#!/usr/bin/env bash
# One-time EC2 bootstrap for CampusLoop production.
# Run on Ubuntu 22.04+ as root or with sudo:
#   curl -fsSL ... | sudo bash
#   or: sudo bash deploy/ec2-bootstrap.sh
set -euo pipefail

DEPLOY_ROOT="${DEPLOY_ROOT:-/opt/campusloop}"
BACKEND_REPO="${BACKEND_REPO:-https://github.com/YOUR_ORG/campusloop-backend.git}"
FRONTEND_REPO="${FRONTEND_REPO:-https://github.com/YOUR_ORG/campusloop-frontend.git}"
BACKEND_BRANCH="${BACKEND_BRANCH:-main}"
FRONTEND_BRANCH="${FRONTEND_BRANCH:-main}"

echo "==> Installing Docker..."
apt-get update
apt-get install -y ca-certificates curl git
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable docker
systemctl start docker

echo "==> Creating deploy directories..."
mkdir -p "$DEPLOY_ROOT"
cd "$DEPLOY_ROOT"

if [ ! -d campusloop-backend/.git ]; then
    git clone --branch "$BACKEND_BRANCH" "$BACKEND_REPO" campusloop-backend
else
    echo "campusloop-backend already cloned, skipping."
fi

if [ ! -d campusloop-frontend/.git ]; then
    git clone --branch "$FRONTEND_BRANCH" "$FRONTEND_REPO" campusloop-frontend
else
    echo "campusloop-frontend already cloned, skipping."
fi

cd "$DEPLOY_ROOT/campusloop-backend"

if [ ! -f compose.prod.env ]; then
    cp docker/compose.prod.env.example compose.prod.env
    echo "Created compose.prod.env — edit it before first deploy."
fi

if [ ! -f .env ]; then
    cp .env.production.example .env
    echo "Created .env from .env.production.example — fill in secrets and RDS settings."
fi

chmod +x deploy/aws/setup-checklist.sh deploy/install-github-runner.sh deploy/install-host-nginx.sh 2>/dev/null || true

cat <<EOF

Bootstrap complete.

Next steps:
1. Edit $DEPLOY_ROOT/campusloop-backend/compose.prod.env
2. Edit $DEPLOY_ROOT/campusloop-backend/.env (APP_KEY, mail, RECAPTCHA_SECRET, etc.)
3. bash deploy/aws/setup-checklist.sh   # AWS checklist if not done yet
4. docker compose -f compose.prod.yaml --env-file compose.prod.env up -d --build
5. bash deploy/install-github-runner.sh backend
6. bash deploy/install-github-runner.sh frontend
7. sudo bash deploy/install-host-nginx.sh your-domain.com

EOF
