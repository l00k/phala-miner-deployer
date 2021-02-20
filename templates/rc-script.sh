#!/bin/bash

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
        [[ {{public_device_stats}} == 1 ]] && {{deploy_path}}/main.sh start stats &

        touch $SUBSYSFILE

        DATE=`date +"%F %T"`
        echo "$DATE starting service" >> $LOGFILE
        exit 0
        ;;
    stop)
        {{deploy_path}}/main.sh stop stack

        DATE=`date +"%F %T"`
        echo "$DATE stopping service" >> $LOGFILE
        exit 0
        ;;
    install)
        update-rc.d {{service_name}} remove
        [[ -e /etc/init.d/{{service_name}} ]] && rm /etc/init.d/{{service_name}}
        cp {{deploy_path}}/rc-script.sh /etc/init.d/{{service_name}}
        chmod +x /etc/init.d/{{service_name}}
        update-rc.d {{service_name}} defaults
        exit 0
        ;;
    *)
        echo "Usage: $0 {start|stop}"
        exit 1
esac


