#!/usr/bin/env bash
# ADMAN Task 017 Phase B — run ONCE as root on the production Droplet.
# Safe order: create user → verify → then harden SSH.
# Does NOT install Nginx/PHP/MySQL/Redis/Laravel.
set -euo pipefail

ADMIN_USER="${ADMIN_USER:-adman}"
HOSTNAME_TARGET="${HOSTNAME_TARGET:-adman-prod}"
PUBKEY_FILE="${PUBKEY_FILE:-}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi

if [[ -z "$PUBKEY_FILE" || ! -f "$PUBKEY_FILE" ]]; then
  echo "Set PUBKEY_FILE to the path of the admin SSH public key on this machine," >&2
  echo "or paste the public key into /root/adman_admin.pub before running." >&2
  if [[ -f /root/adman_admin.pub ]]; then
    PUBKEY_FILE=/root/adman_admin.pub
  else
    exit 1
  fi
fi

PUBKEY="$(tr -d '\r' < "$PUBKEY_FILE" | head -n 1)"
if [[ -z "$PUBKEY" || "$PUBKEY" != ssh-* ]]; then
  echo "Invalid public key in $PUBKEY_FILE" >&2
  exit 1
fi

echo "==> Baseline (before)"
uname -a
lsb_release -ds || true
free -h
df -h /
swapon --show || true
ss -tuln || true

echo "==> Hostname / timezone"
hostnamectl set-hostname "$HOSTNAME_TARGET"
# Nigerian business ops; Laravel can still use Business timezone later.
timedatectl set-timezone Africa/Lagos || true

echo "==> Create admin user: $ADMIN_USER"
if ! id "$ADMIN_USER" &>/dev/null; then
  adduser --disabled-password --gecos "ADMAN admin" "$ADMIN_USER"
fi
usermod -aG sudo "$ADMIN_USER"
echo "$ADMIN_USER ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${ADMIN_USER}"
chmod 440 "/etc/sudoers.d/90-${ADMIN_USER}"

install -d -m 700 -o "$ADMIN_USER" -g "$ADMIN_USER" "/home/$ADMIN_USER/.ssh"
AUTH_KEYS="/home/$ADMIN_USER/.ssh/authorized_keys"
touch "$AUTH_KEYS"
chmod 600 "$AUTH_KEYS"
chown "$ADMIN_USER:$ADMIN_USER" "$AUTH_KEYS"
if ! grep -qxF "$PUBKEY" "$AUTH_KEYS"; then
  echo "$PUBKEY" >> "$AUTH_KEYS"
fi
chown -R "$ADMIN_USER:$ADMIN_USER" "/home/$ADMIN_USER/.ssh"

echo "==> Verify sudo as $ADMIN_USER (local)"
sudo -u "$ADMIN_USER" sudo -n true
echo "sudo OK"

echo "==> SSH harden drop-in (PasswordAuthentication no, PermitRootLogin no)"
install -d /etc/ssh/sshd_config.d
cat >/etc/ssh/sshd_config.d/99-adman-hardening.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
ChallengeResponseAuthentication no
PermitRootLogin no
PubkeyAuthentication yes
EOF

# Validate config before restart
sshd -t

echo "==> IMPORTANT: Before restarting sshd, open a SECOND session as $ADMIN_USER and confirm login+sudo."
echo "    Then run: systemctl reload ssh || systemctl reload sshd"
echo "    Or re-run this script with APPLY_SSHD=1 to reload now."

if [[ "${APPLY_SSHD:-0}" == "1" ]]; then
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
  echo "sshd reloaded"
fi

echo "==> UFW"
apt-get install -y ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose

echo "==> Fail2ban"
apt-get install -y fail2ban
systemctl enable --now fail2ban
cat >/etc/fail2ban/jail.d/sshd.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
findtime = 10m
bantime = 1h
EOF
systemctl restart fail2ban
fail2ban-client status sshd || fail2ban-client status

echo "==> Swap 1G"
if ! swapon --show | grep -q .; then
  if [[ ! -f /swapfile ]]; then
    fallocate -l 1G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=1024
    chmod 600 /swapfile
    mkswap /swapfile
  fi
  swapon /swapfile
fi
grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
# Prefer RAM; avoid aggressive swap
sysctl vm.swappiness=10
grep -q 'vm.swappiness' /etc/sysctl.d/99-adman-swap.conf 2>/dev/null || echo 'vm.swappiness=10' >/etc/sysctl.d/99-adman-swap.conf

echo "==> Unattended security updates"
apt-get install -y unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades || true

echo "==> System updates"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get -y upgrade

echo "==> Baseline (after)"
hostname
lsb_release -ds || true
uname -r
free -h
swapon --show
df -h /
uptime
ss -tuln
ufw status verbose
systemctl is-active fail2ban
fail2ban-client status sshd || true
systemctl --type=service --state=running --no-pager | head -60

echo "==> Done Phase B core. Confirm SSH as $ADMIN_USER from your laptop before closing root sessions."
