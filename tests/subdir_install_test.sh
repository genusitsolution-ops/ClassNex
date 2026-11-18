#!/usr/bin/env bash
# Installs a copy of ClassNex into an Apache web root under /ClassNex/ (like https://genusitsolution.com/ClassNex/),
# runs the web installer over HTTP, then the caller runs the API / browser suites against that address.
#   sudo bash tests/subdir_install_test.sh [port]
# Needs: apache2 + libapache2-mod-php, MySQL/MariaDB user classnex/classnex with rights on classnex_subdir_test.
set -euo pipefail
PORT=${1:-8090}
SRC=$(cd "$(dirname "$0")/.." && pwd)
WEB=/var/www/subdir
DEST=$WEB/ClassNex
DB=classnex_subdir_test

rm -rf "$DEST"; mkdir -p "$DEST"
# copy exactly what is deployed (no Android, no local config, no runtime data)
tar -C "$SRC" --exclude=./android --exclude=./.git --exclude=./config/config.php --exclude='./storage/*' --exclude='./uploads/*' -cf - . | tar -C "$DEST" -xf -
mkdir -p "$DEST/storage" "$DEST/uploads"; cp "$SRC/storage/.htaccess" "$DEST/storage/" 2>/dev/null || true; cp "$SRC/uploads/.htaccess" "$DEST/uploads/" 2>/dev/null || true
chown -R www-data:www-data "$DEST"

cat > /etc/apache2/sites-available/classnex-subdir.conf <<CONF
Listen $PORT
<VirtualHost *:$PORT>
  DocumentRoot $WEB
  <Directory $WEB>
    AllowOverride All
    Require all granted
  </Directory>
  ErrorLog /var/log/apache2/classnex-subdir-error.log
</VirtualHost>
CONF
a2enmod -q rewrite headers >/dev/null
a2ensite -q classnex-subdir >/dev/null
apache2ctl -k restart 2>/dev/null || apache2ctl start
sleep 1

mysql -uclassnex -pclassnex -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null \
  || mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

BASE="http://127.0.0.1:$PORT/ClassNex"
JAR=$(mktemp)
PAGE=$(curl -s -c "$JAR" "$BASE/install.php")
CSRF=$(echo "$PAGE" | grep -o 'name="csrf" value="[a-f0-9]*"' | sed 's/.*value="//;s/"//')
[ -n "$CSRF" ] || { echo "installer page not reachable"; exit 1; }
curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/install.php" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "db_host=localhost" --data-urlencode "db_port=3306" \
  --data-urlencode "db_name=$DB" --data-urlencode "db_user=classnex" --data-urlencode "db_pass=classnex" \
  --data-urlencode "app_url=$BASE" --data-urlencode "admin_name=Subdir Admin" --data-urlencode "admin_mobile=9000000001" \
  --data-urlencode "admin_email=subdir-admin@classnex.test" --data-urlencode "admin_password=Admin@12345" | grep -q "ClassNex is installed" \
  && echo "INSTALLER: OK ($BASE)" || { echo "INSTALLER: FAILED"; tail -20 /var/log/apache2/classnex-subdir-error.log; exit 1; }
# a second POST must be refused
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/install.php" -d "db_name=x")
echo "INSTALLER LOCKED AFTER INSTALL: HTTP $CODE"
for u in config/config.php storage/installed.lock database/install.sql docs/README.md cron/run.php classes/DB.php includes/functions.php .git/config; do
  printf 'PRIVATE %-28s HTTP %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$u")"
done
