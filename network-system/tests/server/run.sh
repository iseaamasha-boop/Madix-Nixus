#!/usr/bin/env bash
# اختبار الخادم الحقيقي داخل Docker (Ubuntu نظيف لكل سيناريو):
#   bash tests/server/run.sh --prev=OLD-INSTALLER.sh [--new=NEW-INSTALLER.sh] [--image=ubuntu:24.04] [--only=fresh|update]
#
#   fresh  : تثبيت جديد بالمثبّت الجديد ← بيانات ← نسخة احتياطية ← حذف ← استرجاع ← فحص
#   update : تثبيت الإصدار السابق ← بيانات ← madix update بالمثبّت الجديد ← فحص البيانات والترحيل وكل الشبكات
#            ← استرجاع النسخة التي أخذها التحديث نفسه (نسخة من الإصدار السابق على الإصدار الجديد)
# بلا --new يُبنى المثبّت من المستودع الحالي (deploy/vps/build.sh).
set -Eeuo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SYS=$(cd "$HERE/../.." && pwd)
PREV="" NEW="" IMAGE=ubuntu:24.04 ONLY=""
for a in "$@"; do
    case "$a" in
        --prev=*) PREV=${a#*=} ;;
        --new=*) NEW=${a#*=} ;;
        --image=*) IMAGE=${a#*=} ;;
        --only=*) ONLY=${a#*=} ;;
        *) echo "unknown option $a"; exit 2 ;;
    esac
done
command -v docker >/dev/null || { echo "docker is required"; exit 2; }
WORK=$(mktemp -d /tmp/madix-server-test.XXXXXX)
trap 'rm -rf "$WORK"; docker rm -f madix-test-fresh madix-test-update >/dev/null 2>&1 || true' EXIT

if [ -z "$NEW" ]; then
    bash "$SYS/deploy/vps/build.sh" "$WORK/new.sh" >/dev/null
    NEW=$WORK/new.sh
fi
cp "$NEW" "$WORK/new.sh"
cp "$SYS/tests/seed-demo.php" "$WORK/seed-demo.php"
cp "$HERE/inside.sh" "$WORK/inside.sh"
if [ -n "$PREV" ]; then cp "$PREV" "$WORK/prev.sh"; fi

fail=0
run() { # run NAME
    local name=$1
    echo "==> $name on $IMAGE"
    docker rm -f "madix-test-$name" >/dev/null 2>&1 || true
    if docker run --name "madix-test-$name" --rm -v "$WORK:/work:ro" -e DEBIAN_FRONTEND=noninteractive "$IMAGE" bash /work/inside.sh "$name" 2>&1 | sed 's/^/    /'; then
        echo "    ✔ $name"
    else
        echo "    ✘ $name"
        fail=1
    fi
}

if [ -z "$ONLY" ] || [ "$ONLY" = fresh ]; then run fresh; fi
if [ -z "$ONLY" ] || [ "$ONLY" = update ]; then
    if [ -f "$WORK/prev.sh" ]; then run update; else echo "==> update skipped (no --prev)"; fail=1; fi
fi
exit $fail
