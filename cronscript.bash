#!/bin/bash

case "$(ps -All |grep -v grep |grep -c ffmpeg)" in

0) /usr/bin/php /ruta/boa/admin/src/index.cron.php >/dev/null &
   ;;
*) # all ok
   ;;
esac
