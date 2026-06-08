#!/usr/bin/env bash
# Install GitHub Actions self-hosted runner for one repo.
# Usage:
#   bash deploy/install-github-runner.sh backend
#   bash deploy/install-github-runner.sh frontend
#
# Before running, get a registration token from:
#   GitHub repo -> Settings -> Actions -> Runners -> New self-hosted runner
set -euo pipefail

TARGET="${1:-}"
if [ "$TARGET" != "backend" ] && [ "$TARGET" != "frontend" ]; then
    echo "Usage: $0 {backend|frontend}"
    exit 1
fi

RUNNER_VERSION="${RUNNER_VERSION:-2.323.0}"
RUNNER_ROOT="${RUNNER_ROOT:-/opt/actions-runner}"
RUNNER_DIR="$RUNNER_ROOT/$TARGET"
RUNNER_USER="${RUNNER_USER:-ubuntu}"
RUNNER_LABELS="${RUNNER_LABELS:-self-hosted,linux,campusloop-prod}"

mkdir -p "$RUNNER_DIR"
cd "$RUNNER_DIR"

if [ ! -f ./config.sh ]; then
    curl -fsSL -o actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz \
        "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
    tar xzf actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz
    rm actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz
fi

echo "Configure runner for: $TARGET"
echo "Repo URL example:"
if [ "$TARGET" = "backend" ]; then
    echo "  https://github.com/YOUR_ORG/campusloop-backend"
else
    echo "  https://github.com/YOUR_ORG/campusloop-frontend"
fi
read -rp "GitHub repository URL: " REPO_URL
read -rp "Registration token from GitHub: " REG_TOKEN

./config.sh \
    --url "$REPO_URL" \
    --token "$REG_TOKEN" \
    --name "campusloop-${TARGET}-$(hostname)" \
    --labels "$RUNNER_LABELS" \
    --unattended \
    --replace

chown -R "$RUNNER_USER:$RUNNER_USER" "$RUNNER_DIR"

./svc.sh install "$RUNNER_USER"
./svc.sh start

echo "Runner installed for $TARGET at $RUNNER_DIR"
