#!/usr/bin/env bash
# Re-provisiona el toolchain del sandbox tras un reset (PHP 8.3 estático + composer + MySQL 8.4.6 en 13306).
# Uso: bash /home/z/my-project/scripts/reprovision-sandbox.sh
# Idempotente: puede re-ejecutarse sin romper nada. El PAT de GitHub NO está incluido (pedirlo al usuario).
set -u
LOCAL="$HOME/.local"; RUNTIME="$HOME/.runtime"; DL="$RUNTIME/downloads"
mkdir -p "$LOCAL/bin" "$RUNTIME/downloads" "$RUNTIME/compat/lib"

# 1) PHP 8.3.32 estático + composer
if [ ! -x "$LOCAL/bin/php" ]; then
  curl -sL -o "$DL/php-static.tar.gz" --max-time 300 \
    "https://dl.static-php.dev/static-php-cli/common/php-8.3.32-cli-linux-x86_64.tar.gz"
  tar -xzf "$DL/php-static.tar.gz" -C "$LOCAL/bin" && chmod +x "$LOCAL/bin/php"
fi
"$LOCAL/bin/php" -v | head -1
if [ ! -f "$LOCAL/bin/composer" ]; then
  curl -sL -o "$LOCAL/bin/composer" --max-time 180 "https://getcomposer.org/download/latest-stable/composer.phar"
  chmod +x "$LOCAL/bin/composer"
fi
"$LOCAL/bin/php" "$LOCAL/bin/composer" --version 2>/dev/null | head -1

# 2) MySQL 8.4.6 minimal (archivos CDN) + libaio/libncurses de Debian
if [ ! -d "$RUNTIME/mysql/bin" ]; then
  curl -sL -o "$DL/mysql-minimal.tar.xz" --max-time 600 \
    "https://cdn.mysql.com/archives/mysql-8.4/mysql-8.4.6-linux-glibc2.28-x86_64-minimal.tar.xz"
  mkdir -p "$RUNTIME/mysql" && tar -xf "$DL/mysql-minimal.tar.xz" -C "$RUNTIME/mysql" --strip-components=1
fi
if [ ! -f "$RUNTIME/compat/lib/libaio.so.1.0.1" ]; then
  curl -sL --max-time 90 -o "$DL/libaio1.deb" \
    "http://deb.debian.org/debian/pool/main/liba/libaio/libaio1_0.3.112-9_amd64.deb"
  d="$DL/libaio"; rm -rf "$d"; mkdir -p "$d" && cd "$d" && ar x "$DL/libaio1.deb" 2>/dev/null || true
  tar -xf data.tar.xz -C "$d" 2>/dev/null || tar -xf data.tar.zst -C "$d" 2>/dev/null || true
  cp -a "$d/usr/lib/x86_64-linux-gnu/libaio.so.1.0.1" "$RUNTIME/compat/lib/" 2>/dev/null || true
fi
[ -f "$RUNTIME/compat/lib/libaio.so.1.0.1" ] && ln -sf libaio.so.1.0.1 "$RUNTIME/compat/lib/libaio.so.1"
if [ ! -f "$RUNTIME/compat/lib/libncurses.so.6" ]; then
  curl -sL -o "$DL/libncurses6.deb" --max-time 60 \
    "http://deb.debian.org/debian/pool/main/n/ncurses/libncurses6_6.4-4_amd64.deb"
  d="$DL/ncurses"; mkdir -p "$d" && cd "$d" && ar x "$DL/libncurses6.deb" && tar -xf data.tar.* -C "$d" 2>/dev/null || true
  cp -a "$d/lib/x86_64-linux-gnu/libncurses.so.6"* "$RUNTIME/compat/lib/" 2>/dev/null || true
  cp -a "$d/lib/x86_64-linux-gnu/libtinfo.so.6"* "$RUNTIME/compat/lib/" 2>/dev/null || true
fi

# 3) vendor del backend
if [ ! -d /home/z/my-project/backend/vendor ]; then
  cd /home/z/my-project/backend && "$LOCAL/bin/php" "$LOCAL/bin/composer" install --no-interaction --prefer-dist --no-progress
fi

# 4) arrancar MySQL (crea sgp_test + usuario sgp/sgp_local_dev)
export LD_LIBRARY_PATH="$RUNTIME/compat/lib"
B="$RUNTIME/mysql"
mkdir -p "$RUNTIME/bin"
if [ ! -f "$RUNTIME/bin/start-mysql.sh" ]; then
  cat > "$RUNTIME/bin/start-mysql.sh" <<'MYSQLD'
#!/usr/bin/env bash
# Arranca MySQL 8.4.6 portable en 13306 (idempotente). Admin via socket, app via TCP.
set -u
RUNTIME="$HOME/.runtime"; B="$RUNTIME/mysql"
export LD_LIBRARY_PATH="$RUNTIME/compat/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
CLIENT="$B/bin/mysql -uroot --socket=$B/tmp/mysql.sock"
if ! $CLIENT -e "SELECT 1" >/dev/null 2>&1; then
  if [ ! -d "$B/data/mysql" ]; then
    rm -rf "$B/data" "$B/tmp"; mkdir -p "$B/data" "$B/tmp"
    "$B/bin/mysqld" --initialize-insecure --datadir="$B/data" --basedir="$B" --log-error="$B/init.err"
  fi
  nohup "$B/bin/mysqld" --datadir="$B/data" --basedir="$B" --port=13306 --bind-address=127.0.0.1 \
    --socket="$B/tmp/mysql.sock" --pid-file="$B/tmp/mysqld.pid" --log-error="$B/mysql.err" \
    --skip-name-resolve >/dev/null 2>&1 &
  for _ in $(seq 1 40); do $CLIENT -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
fi
$CLIENT -e "CREATE DATABASE IF NOT EXISTS sgp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$CLIENT -e "CREATE USER IF NOT EXISTS 'sgp'@'%' IDENTIFIED BY 'sgp_local_dev'"
$CLIENT -e "CREATE USER IF NOT EXISTS 'sgp'@'localhost' IDENTIFIED BY 'sgp_local_dev'"
$CLIENT -e "GRANT ALL PRIVILEGES ON sgp_test.* TO 'sgp'@'%'"
$CLIENT -e "GRANT ALL PRIVILEGES ON sgp_test.* TO 'sgp'@'localhost'"
$CLIENT -e "FLUSH PRIVILEGES"
MYSQLD
  chmod +x "$RUNTIME/bin/start-mysql.sh"
fi
bash "$RUNTIME/bin/start-mysql.sh"
echo "OK: toolchain listo (php/composer en $LOCAL/bin, MySQL 13306). Falta PAT si se necesita push."
