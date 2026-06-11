#!/usr/bin/env bash
# AWS one-time setup checklist for CampusLoop production.
# Run locally for reference — does not create resources automatically.
set -euo pipefail

cat <<'EOF'
CampusLoop AWS setup checklist (ap-southeast-1 recommended)
============================================================

1. S3 bucket
   - Name: campusloop-prod-uploads-<unique>
   - Block all public access: ON
   - Object Ownership: Bucket owner enforced (ACLs disabled) — required by Laravel S3 config
   - Note bucket name and region for compose.prod.env

2. IAM policy + EC2 role
   - Edit deploy/aws/iam-ec2-s3-policy.json (replace YOUR_BUCKET_NAME with exact bucket, e.g. campusloop-prod-uploads-holyface)
   - Create IAM policy from JSON (ListBucket on bucket ARN; object actions on bucket/*)
   - Create IAM role: EC2 trust policy (CampusLoopEC2Role)
   - Attach S3 policy + AmazonSSMManagedInstanceCore (optional, for Session Manager)
   - Attach role to EC2 instance (no AWS keys in .env)
   - Verify on EC2: aws s3 cp /tmp/test.txt s3://YOUR_BUCKET_NAME/test.txt --region YOUR_REGION

3. RDS MySQL 8.x
   - Identifier: campusloop-prod
   - DB name: campusloop
   - Master username/password: store securely
   - Public access: NO
   - VPC: same as EC2
   - Security group: allow 3306 from EC2 security group only

4. EC2
   - Ubuntu 22.04 LTS, t3.small or larger
   - Attach IAM role from step 2
   - Security group: 22 (your IP), 80, 443 from 0.0.0.0/0
   - Optional: Elastic IP for stable DNS

5. After EC2 is running
   - SSH in and run: sudo bash deploy/ec2-bootstrap.sh
   - Copy docker/compose.prod.env.example -> compose.prod.env
   - Copy .env.production.example -> .env and fill secrets + RDS endpoint
   - Run deploy/install-github-runner.sh twice (backend + frontend repos)
   - Run deploy/install-host-nginx.sh (SSL + reverse proxy)

6. GitHub (each repo)
   - Settings -> Actions -> Runners -> New self-hosted runner
   - Use labels: self-hosted, linux, campusloop-prod
EOF
