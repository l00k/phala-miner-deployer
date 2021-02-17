#!/bin/bash

cd "{{deploy_path}}"

ACTION=$1
SERVICE=$2
DELAY=$3
if [[ $DELAY == '' ]]; then
    DELAY=60
fi

start_runtime() {
    STATUS=$(sudo docker ps | grep "phala-pruntime")
    if [[ $STATUS != '' ]]; then
        return
    fi

    sudo docker run -dit --rm \
        --name phala-pruntime \
        -p 8000:8000 \
        -v {{deploy_path}}/phala-pruntime-data:/root/data \
        {{pruntime_devices}} \
        phalanetwork/phala-poc3-pruntime

    # wait for pruntime init
    sleep 10
}

stop_runtime() {
    sudo docker stop phala-pruntime
}

start_host() {
    STATUS=$(sudo docker ps | grep "phala-phost")
    if [[ $STATUS != '' ]]; then
        return
    fi

    NODES=({{node_ips}})
    DONE=0

    for NODE in "${NODES[@]}"; do
        echo "Checking $NODE"

        STATUS=$(echo 'exit' | telnet $NODE 9944 | grep "Connected to")
        if [[ $STATUS == '' ]]; then
            echo "Websocket is down!"
            continue
        fi

        STATUS=$(curl -s -H "Content-Type: application/json" --data '{ "jsonrpc":"2.0", "method": "system_health", "params":[], "id":1 }' $NODE:9933 | grep '"isSyncing":true')
        if [[ $STATUS != '' ]]; then
            echo "Node is syncing!"
            continue
        fi

        for TRY in {1..3}; do
            echo "Connecting to $NODE (try $TRY)"

            # start host
            sudo docker run -d -ti --rm \
                --name phala-phost \
                -e PRUNTIME_ENDPOINT="http://phala-pruntime:8000" \
                -e PHALA_NODE_WS_ENDPOINT="ws://$NODE:9944" \
                -e MNEMONIC="{{miner_mnemonic}}" \
                -e EXTRA_OPTS="-r" \
                --link phala-pruntime \
                phalanetwork/phala-poc3-phost

            sleep 15

            STATUS=$(sudo docker ps | grep "phala-phost")
            if [[ $STATUS != '' ]]; then
                echo "It works"
                DONE=1
                break
            fi
        done

        if [[ $DONE == 1 ]]; then
            break
        fi
    done
}

stop_host() {
    sudo docker stop phala-phost
}

start_stack() {
    sleep $DELAY
    start_runtime
    start_host

    start_watch &
}

stop_stack() {
    stop_host
    stop_runtime
}

start_watch() {
     STATUS=$(sudo docker ps | grep "phala-pruntime")
     if [[ $STATUS == '' ]]; then
         start_runtime
         sleep 10
     fi

     STATUS=$(sudo docker ps | grep "phala-phost")
     if [[ $STATUS == '' ]]; then
         start_host
         sleep 10
     fi

    # wait and repeat
    sleep 60

    start_watch
}


# RUN
case "$1" in
start)
    case "$2" in
    runtime)
        start_runtime
        ;;
    host)
        start_host
        ;;
    stack)
        start_stack
        ;;
    watch)
        start_watch
        ;;
    *)
        exit 1
        ;;
    esac
    ;;
stop)
    case "$2" in
    runtime)
        stop_runtime
        ;;
    host)
        stop_host
        ;;
    stack)
        stop_stack
        ;;
    *)
        exit 1
        ;;
    esac
    ;;
*)
    exit 1
    ;;
esac

exit 0
