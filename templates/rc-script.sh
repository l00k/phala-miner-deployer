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

start() {
    DATE=`date +"%Y-%m-%d"`
    echo "$DATE starting service" >> $LOGFILE

    {{deploy_path}}/run.sh &
    touch $SUBSYSFILE
}

stop() {
    DATE=`date +"%Y-%m-%d"`
    echo "$DATE stopping service" >> $LOGFILE

    {{deploy_path}}/stop.sh
}

case "$1" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    restart)
        stop
        start
        ;;
    *)
        echo "Usage: $0 {start|stop|restart}"
esac


