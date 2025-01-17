#!/usr/bin/env bash

ctest_driver="/cdash/.github/workflows/ctest_driver_script.cmake"

database="$1"

if [ "$database" != "mysql" ] && [ "$database" != "postgres" ]; then
  echo "Database type required: mysql or postgres"
  exit 1;
fi

submit_type="$2"
submit_type="${submit_type:-Experimental}"

site="${SITENAME:-$(hostname)}"

echo "site=$site"
echo "database=$database"
echo "ctest_driver=$ctest_driver"
echo "submit_type=$submit_type"

# Suppress any uncommitted changes left after the image build
docker exec cdash bash -c "cd /cdash && /usr/bin/git checkout ."

docker exec cdash bash -c "\
  ctest \
    -VV \
    -j 4 \
    --schedule-random \
    -DSITENAME=\"${site}\" \
    -DDATABASE=\"${database}\" \
    -DSUBMIT_TYPE=\"${submit_type}\" \
    -S \"${ctest_driver}\" \
"
