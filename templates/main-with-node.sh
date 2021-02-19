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
        -p 9933:9933 -p 9944:9944 -p 30333:30333 \
        -v {{deploy_path}}/phala-node-data:/root/data \
        phalanetwork/phala-poc3-node
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
        phalanetwork/phala-poc3-pruntime

    # wait for pruntime init
    sleep 10
}

start_host() {
    STATUS=$(sudo docker ps | grep "phala-phost")
    if [[ $STATUS != '' ]]; then
        return
    fi

    NODES=("phala-node")
    NODES+=({{node_ips}})
    DONE=0

    for NODE in "${NODES[@]}"; do
        PUBLIC_ADDRESS=$NODE
        if [[ $NODE == "phala-node" ]]; then
            PUBLIC_ADDRESS="localhost"
        fi

        echo "Checking $NODE / $PUBLIC_ADDRESS"

        STATUS=$(echo 'exit' | telnet $PUBLIC_ADDRESS 9944 | grep "Connected to")
        if [[ $STATUS == '' ]]; then
            echo "Websocket is down!"
            continue
        fi

        STATUS=$(curl -s -H "Content-Type: application/json" --data '{ "jsonrpc":"2.0", "method": "system_health", "params":[], "id":1 }' $PUBLIC_ADDRESS:9933 | grep '"isSyncing":true')
        if [[ $STATUS != '' ]]; then
            echo "Node is syncing!"
            continue
        fi

        for TRY in {1..3}; do
            echo "Connecting to $NODE / $PUBLIC_ADDRESS (try $TRY)"

            # start host
            sudo docker run -d -ti --rm \
                --name phala-phost \
                -e PRUNTIME_ENDPOINT="http://phala-pruntime:8000" \
                -e PHALA_NODE_WS_ENDPOINT="ws://$NODE:9944" \
                -e MNEMONIC="{{miner_mnemonic}}" \
                -e EXTRA_OPTS="-r" \
                --link phala-node \
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
