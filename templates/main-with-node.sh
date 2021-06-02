#!/bin/bash

cd "{{deploy_path}}"

ACTION=$1
SERVICE=$2
DELAY=$3
if [[ $DELAY == '' ]]; then
    DELAY=60
fi

start_node() {
    STATUS=$(sudo docker ps | grep "phala-node")
    if [[ $STATUS != '' ]]; then
        return
    fi

    sudo docker run -dit --rm \
        --name phala-node \
        -e NODE_NAME="{{node_name}}" \
        -p {{ports_0}}:{{ports_0}} \
        -p {{ports_1}}:{{ports_1}} \
        -p {{ports_2}}:{{ports_2}} \
        -v {{deploy_path}}/phala-node-data:/root/data \
        phalanetwork/phala-poc4-node
}

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
        phalanetwork/phala-poc4-pruntime

    # wait for pruntime init
    sleep 10
}

start_host() {
    STATUS=$(sudo docker ps | grep "phala-phost")
    if [[ $STATUS != '' ]]; then
        return
    fi

    NODES+=({{nodes}})
    DONE=0

    for NODE in "${NODES[@]}"; do
        NODE_PARTS=(${NODE//:/ })

        NODE_HOST=${NODE_PARTS[0]}
        NODE_PORT_WS=${NODE_PARTS[1]}
        NODE_PORT_RPC=${NODE_PARTS[2]}

        PUBLIC_HOST=$NODE_HOST
        if [[ $NODE == "phala-node" ]]; then
            PUBLIC_HOST="localhost"
        fi

        echo "Checking $NODE_HOST / $PUBLIC_HOST"

        STATUS=$(echo 'exit' | telnet $PUBLIC_HOST $NODE_PORT_RPC | grep "Connected to")
        if [[ $STATUS == '' ]]; then
            echo "Websocket is down!"
            echo "Discard"
            continue
        fi

        STATUS=$(curl -s -H "Content-Type: application/json" --data '{ "jsonrpc":"2.0", "method": "system_health", "params":[], "id":1 }' $PUBLIC_HOST:$NODE_PORT_RPC | grep '"isSyncing":true')
        if [[ $STATUS != '' ]]; then
            echo "Node is syncing!"
            echo "Discard"
            continue
        fi

        for TRY in {1..3}; do
            echo "Connecting to $NODE_HOST / $PUBLIC_HOST (try $TRY)"

            # start host
            sudo docker run -d -ti --rm \
                --name phala-phost \
                -e PRUNTIME_ENDPOINT="http://phala-pruntime:8000" \
                -e PHALA_NODE_WS_ENDPOINT="ws://$NODE_HOST:$NODE_PORT_WS" \
                -e MNEMONIC="{{miner_mnemonic}}" \
                -e EXTRA_OPTS="-r" \
                --link phala-node \
                --link phala-pruntime \
                phalanetwork/phala-poc4-phost

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

start_watch() {
     STATUS=$(sudo docker ps | grep "phala-node")
     if [[ $STATUS == '' ]]; then
         start_node
         sleep 10
     fi

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

start_stats() {
    ./device-state-updater.php

    # wait and repeat
    sleep 300
    start_stats
}

start_stack() {
    sleep $DELAY
    start_node
    start_runtime
    start_host

    start_watch &
}


# ##########
# STOP TASKS

stop_node() {
    sudo docker stop phala-node
}

stop_runtime() {
    sudo docker stop phala-pruntime
}

stop_host() {
    sudo docker stop phala-phost
}

stop_stack() {
    stop_host
    stop_runtime
    stop_node
}


# ###########
# RUN

case "$1" in
start)
    case "$2" in
    stack)
        start_stack
        ;;
    stats)
        start_stats
        ;;
    *)
        exit 1
        ;;
    esac
    ;;
stop)
    case "$2" in
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
