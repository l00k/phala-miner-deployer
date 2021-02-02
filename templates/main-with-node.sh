#!/bin/bash

cd "{{deploy_path}}"

ACTION=$1
SERVICE=$2
DELAY=$3
if [[ $DELAY == '' ]]; then
    DELAY=60
fi

start_node() {
    sudo docker run -dit --rm \
        --name phala-node \
        -e NODE_NAME="{{node_name}}" \
        -p 9933:9933 -p 9944:9944 -p 30333:30333 \
        -v {{deploy_path}}/phala-node-data:/root/data \
        phalanetwork/phala-poc3-node
}

stop_node() {
    sudo docker stop phala-node
}

restart_node() {
    stop_node
    rm -r {{deploy_path}}/phala-node-data
    start_node
}

start_runtime() {
    sudo docker run -dit --rm \
        --name phala-pruntime \
        -p 8000:8000 \
        -v {{deploy_path}}/phala-pruntime-data:/root/data \
        {{pruntime_devices}} \
        phalanetwork/phala-poc3-pruntime
}

stop_runtime() {
    sudo docker stop phala-pruntime
}

restart_runtime() {
    stop_runtime
    rm -r {{deploy_path}}/phala-pruntime-data
    start_runtime
}

start_host() {
    NODES=({{node_ips}})
    NODES+=("phala-node")
    DONE=0

    for NODE_IP in "${NODES[@]}"; do
        echo "Checking $NODE_IP"
        STATUS=$(echo 'exit' | telnet $NODE_IP 9944 | grep "Connected to")
        if [[ $STATUS == '' ]]; then
            echo "Down!"
            continue
        fi

        for TRY in {1..2}; do
            echo "Connecting to $NODE_IP"

            # start host
            sudo docker run -d -ti --rm \
                --name phala-phost \
                -e PRUNTIME_ENDPOINT="http://phala-pruntime:8000" \
                -e PHALA_NODE_WS_ENDPOINT="ws://$NODE_IP:9944" \
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

stop_host() {
    sudo docker stop phala-phost
}

start_stack() {
    # start node and verify
    start_node

    sleep 5
    STATUS=$(sudo docker ps | grep "phala-node")
    if [[ $STATUS == '' ]]; then
        echo "Restart node"
        restart_node
    fi

    # start runtime
    start_runtime

    # join VPN
    {{join_lan_cmd}}

    # select node from all
    start_host

    # start watch
    start_watch
}

stop_stack() {
    stop_host
    stop_runtime
    stop_node
}

start_watch() {
    STATUS=$(sudo docker ps | grep "phala-node")
    if [[ $STATUS == '' ]]; then
        start_node
        sleep 1
    fi

    STATUS=$(sudo docker ps | grep "phala-pruntime")
    if [[ $STATUS == '' ]]; then
        start_runtime
        sleep 1
    fi

    STATUS=$(sudo docker ps | grep "phala-phost")
    if [[ $STATUS == '' ]]; then
        start_host
        sleep 1
    fi

    # wait and repeat
    sleep 60

    start_watch
}


# RUN
case "$1" in
start)
    case "$2" in
    node)
        start_node
        ;;
    runtime)
        start_runtime
        ;;
    host)
        start_host
        ;;
    stack)
        start_stack
        ;;
    *)
        exit 1
        ;;
    esac
    ;;
stop)
    case "$2" in
    node)
        stop_node
        ;;
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
restart)
    case "$2" in
    node)
        restart_node
        ;;
    runtime)
        restart_runtime
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
