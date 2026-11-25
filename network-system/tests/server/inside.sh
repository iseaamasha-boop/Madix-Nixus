#!/usr/bin/env bash
# يعمل داخل حاوية Ubuntu نظيفة (يستدعيه tests/server/run.sh). الوسيط: fresh | update
set -Eeuo pipefail
SCENARIO=$1
export MADIX_ADMIN_PASS=AdminPass123
APP=/var/www/madix

step() { printf '\n--- %s\n' "$*"; }
die() { printf 'FAIL: %s\n' "$*"; tail -n 40 /var/log/madix-install.log 2>/dev/null || true; exit 1; }
check() { "$@" || die "check failed: $*"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "http://127.0.0.1$1"; }
db() { mysql --protocol=socket -uroot -N -e "$1"; }
version() { sed -n 's/^MADIX_VERSION=//p' /etc/madix/madix.env; }
seed() { # seed SLUG
    mkdir -p $APP/tests && cp /work/seed-demo.php $APP/tests/seed-demo.php
    runuser -u www-data -- php $APP/tests/seed-demo.php --slug="$1" --create --subscribers=300 --cards=3000 >/dev/null
}
pages_ok() {
    local p c
    for p in /health / /login /admin/login /net-a/login /net-b/login; do
        c=$(code "$p")
        case "$c" in 200|301|302|303) ;; *) die "$p answered $c" ;; esac
    done
}

# الملفات الثابتة: أرقام إصدار من المحتوى، ونسخ brotli/gzip تُرسل بنوعها الصحيح، وملفات مصغّرة
assets_ok() {
    [ -f $APP/storage/cache/assets.php ] || die "no asset manifest"
    [ -f $APP/public/assets/css/app.css.br ] || die "no brotli copy of app.css"
    [ -f $APP/public/assets/js/app.js.gz ] || die "no gzip copy of app.js"
    local h
    h=$(curl -s -D - -o /dev/null -H 'Accept-Encoding: br' "http://127.0.0.1/assets/css/app.css?v=1")
    echo "$h" | grep -qi '^content-encoding: br' || { echo "$h"; die "app.css not served as brotli"; }
    echo "$h" | grep -qi '^content-type: text/css' || { echo "$h"; die "brotli app.css has the wrong type"; }
    h=$(curl -s -D - -o /dev/null -H 'Accept-Encoding: gzip' "http://127.0.0.1/assets/js/app.js?v=1")
    echo "$h" | grep -qi '^content-encoding: gzip' || { echo "$h"; die "app.js not served as gzip"; }
    curl -s "http://127.0.0.1/assets/css/app.css" | head -c 300 | grep -q '/\*' && die "app.css is not minified"
    return 0
}

apt-get update -qq >/dev/null
apt-get install -y -qq curl ca-certificates >/dev/null

case "$SCENARIO" in
fresh)
    step "fresh install ($(sed -n 's/^MADIX_VERSION="\(.*\)"/\1/p' /work/new.sh))"
    cp /work/new.sh /root/madix-nexus-vps.sh
    bash /root/madix-nexus-vps.sh --yes --no-ssl --no-firewall --mode=platform --admin-user=admin --admin-name=Admin >/tmp/install.out 2>&1 || { cat /tmp/install.out; die "install failed"; }
    seed net-a
    seed net-b
    pages_ok
    assets_ok
    subs=$(db "SELECT COUNT(*) FROM mnx_net_a.subscribers")
    [ "$subs" = 300 ] || die "subscribers after seed: $subs"
    step "backup"
    madix backup >/dev/null || die "backup failed"
    b=$(ls -1dt /var/backups/madix/20*/ | head -n1)
    [ -f "$b/mnx_net_a.sql.gz" ] && [ -f "$b/madix_platform.sql.gz" ] || die "backup incomplete: $(ls "$b")"
    step "damage data, then restore"
    db "DELETE FROM mnx_net_a.subscriber_transactions; DELETE FROM mnx_net_a.logins WHERE owner_type='subscriber'; DELETE FROM mnx_net_a.subscribers"
    [ "$(db 'SELECT COUNT(*) FROM mnx_net_a.subscribers')" = 0 ] || die "delete did not work"
    sleep 1
    echo yes | madix restore "$(basename "$b")" >/tmp/restore.out 2>&1 || { cat /tmp/restore.out; die "restore failed"; }
    [ "$(db 'SELECT COUNT(*) FROM mnx_net_a.subscribers')" = 300 ] || die "subscribers after restore: $(db 'SELECT COUNT(*) FROM mnx_net_a.subscribers')"
    pages_ok
    madix doctor >/tmp/doctor.out 2>&1 || true
    # في الحاوية: لا نطاق، والمهام المجدولة قد لا تكون عملت بعد
    if grep 'FAIL' /tmp/doctor.out | grep -vqE 'Domain|Scheduled tasks|Router tunnel'; then cat /tmp/doctor.out; die "madix doctor"; fi
    echo "fresh: install + backup + restore OK ($(version))"
    ;;
update)
    step "install previous version ($(sed -n 's/^MADIX_VERSION="\(.*\)"/\1/p' /work/prev.sh))"
    cp /work/prev.sh /root/prev.sh
    bash /root/prev.sh --yes --no-ssl --no-firewall --mode=platform --admin-user=admin --admin-name=Admin >/tmp/install.out 2>&1 || { cat /tmp/install.out; die "previous install failed"; }
    old=$(version)
    seed net-a
    seed net-b
    # شبكة موقوفة: يجب أن تُرحَّل قاعدتها أيضاً
    db "UPDATE madix_platform.tenants SET status = 'suspended' WHERE slug = 'net-b'"
    pages_ok
    before_cards=$(db "SELECT COUNT(*) FROM mnx_net_a.cards")
    before_sales=$(db "SELECT COUNT(*) FROM mnx_net_a.sales")
    before_tx=$(db "SELECT COUNT(*) FROM mnx_net_a.subscriber_transactions")
    step "madix update with the new installer"
    cp /work/new.sh /root/madix-nexus-vps.sh
    madix update /root/madix-nexus-vps.sh >/tmp/update.out 2>&1 || { cat /tmp/update.out; die "update failed"; }
    new=$(version)
    [ "$new" != "$old" ] || die "version did not change ($old)"
    grep -q 'Backup before update' /tmp/update.out || { cat /tmp/update.out; die "no backup step in update"; }
    [ "$(db "SELECT COUNT(*) FROM mnx_net_a.cards")" = "$before_cards" ] || die "cards changed"
    [ "$(db "SELECT COUNT(*) FROM mnx_net_a.sales")" = "$before_sales" ] || die "sales changed"
    [ "$(db "SELECT COUNT(*) FROM mnx_net_a.subscriber_transactions")" = "$before_tx" ] || die "transactions changed"
    # الترحيل وصل كل الشبكات (حتى الموقوفة) بنفس النسخة
    want=$(php -r 'require "/var/www/madix/app/bootstrap.php"; echo App\Core\Migrator::VERSION;')
    for d in mnx_net_a mnx_net_b; do
        v=$(db "SELECT \`value\` FROM $d.settings WHERE \`key\` = 'app.schema_version'")
        [ "$v" = "$want" ] || die "$d schema_version $v (want $want)"
    done
    db "UPDATE madix_platform.tenants SET status = 'active' WHERE slug = 'net-b'"
    pages_ok
    assets_ok
    grep -q 'PHP reloaded' /tmp/update.out || { cat /tmp/update.out; die "PHP-FPM was not reloaded after the update"; }
    grep -q 'All databases are up to date' /tmp/update.out || { cat /tmp/update.out; die "migration step missing"; }
    step "update twice is safe"
    madix update /root/madix-nexus-vps.sh >/tmp/update2.out 2>&1 || { cat /tmp/update2.out; die "second update failed"; }
    pages_ok
    step "restore the backup taken before the update (made by the previous version)"
    first=$(ls -1dtr /var/backups/madix/20*/ | head -n1)
    grep -q "version=$old" "$first/manifest" || die "oldest backup is not from $old: $(cat "$first/manifest")"
    echo yes | madix restore "$(basename "$first")" >/tmp/restore.out 2>&1 || { cat /tmp/restore.out; die "restore failed"; }
    runuser -u www-data -- php $APP/cli/cron.php 20 >/dev/null 2>&1 || true
    pages_ok
    [ "$(db "SELECT COUNT(*) FROM mnx_net_a.cards")" = "$before_cards" ] || die "cards after restore"
    echo "update: $old → $new, data kept, all networks migrated, restore of old backup OK"
    ;;
*) die "unknown scenario $SCENARIO" ;;
esac
