#!/bin/sh

### BEGIN INIT INFO
# Provides:          {{service_name}}
# Required-Start:    $all
# Required-Stop:     $local_fs $network $named $time $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Description:       Phala.network full stack service
### END INIT INFO

SUBSYSFILE=/var/lock/subsys/{{service_name}}
LOGFILE=/var/log/{{service_name}}.log

case "$1" in
    start)
        {{deploy_path}}/main.sh start stack &

        touch $SUBSYSFILE

        DATE=`date +"%Y-%m-%d"`
        echo "$DATE starting service" >> $LOGFILE
        ;;
    stop)
        {{deploy_path}}/main.sh stop stack

        DATE=`date +"%Y-%m-%d"`
        echo "$DATE stopping service" >> $LOGFILE
        ;;
    *)
        echo "Usage: $0 {start|stop}"
esac


