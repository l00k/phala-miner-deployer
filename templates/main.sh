#!/bin/bash

cd "{{deploy_path}}"

ACTION=$1
SERVICE=$2
DELAY=$3
if [[ $DELAY == '' ]]; then
    DELAY=60
fi

start_node() {
    STATUS=$(docker ps | grep "phala-node")
    if [[ $STATUS != '' ]]; then
        return
    fi

    docker run -dit --rm \
        --name phala-node \
        -e NODE_NAME="{{node_config.name}}" \
        -e EXTRA_OPTS="{{node_config.extra_opts}} --port {{node_config.ports.2}}" \
        -p {{node_config.ports.0}}:9933 \
        -p {{node_config.ports.1}}:9944 \
        -p {{node_config.ports.2}}:{{node_config.ports.2}} \
        -v {{deploy_path}}/phala-node-data:/root/data \
        phalanetwork/phala-poc4-node
}

start_runtime() {
    STATUS=$(docker ps | grep "phala-pruntime")
    if [[ $STATUS != '' ]]; then
        return
    fi

    docker run -dit --rm \
        --name phala-pruntime \
        -p 8000:8000 \
        -v {{deploy_path}}/phala-pruntime-data:/root/data \
        {{pruntime_devices}} \
        phalanetwork/phala-poc4-pruntime

    # wait for pruntime init
    sleep 10
}

start_host() {
    STATUS=$(docker ps | grep "phala-phost")
    if [[ $STATUS != '' ]]; then
        return
    fi

    if [[ {{run_node}} == 1 ]]; then
        NODES=("phala-node:{{node_config.ports.0}}:{{node_config.ports.1}}", {{nodes}})
    else
        NODES=({{nodes}})
    fi

    DONE=0

    for NODE in "${NODES[@]}"; do
        NODE_PARTS=(${NODE//:/ })

        NODE_HOST=${NODE_PARTS[0]}
        NODE_PORT_RPC=${NODE_PARTS[1]}
        NODE_PORT_WS=${NODE_PARTS[2]}

        LINKS=""

        PUBLIC_HOST=$NODE_HOST
        if [[ $NODE_HOST == "phala-node" ]]; then
            LINKS="--link phala-node"
            PUBLIC_HOST="localhost"
        fi

        echo "Checking $NODE_HOST / $PUBLIC_HOST"

        # check websocket
        STATUS=$(echo 'exit' | timeout --signal=9 2 telnet $PUBLIC_HOST $NODE_PORT_WS | grep "Connected to")
        if [[ $STATUS == '' ]]; then
            echo "Websocket is down!"
            echo "Discard"
            continue
        fi

        # check syncing
        SYSTEM_HEALTH=$(curl -s -H "Content-Type: application/json" --data '{ "jsonrpc":"2.0", "method": "system_health", "params":[], "id":1 }' $PUBLIC_HOST:$NODE_PORT_WS)

        STATUS=$(echo $SYSTEM_HEALTH | grep '"isSyncing":true')
        if [[ $STATUS != '' ]]; then
            echo "Node is syncing!"
            echo "Discard"
            continue
        fi

        # check peers
        STATUS=$(echo $SYSTEM_HEALTH | grep '"peers":0')
        if [[ $STATUS != '' ]]; then
            echo "Node without peers!"
            echo "Discard"
            continue
        fi

        for TRY in {1..3}; do
            echo "Connecting to $NODE_HOST / $PUBLIC_HOST (try $TRY)"

            # start host
            docker run -d -ti --rm \
                --name phala-phost \
                -e PRUNTIME_ENDPOINT="http://phala-pruntime:8000" \
                -e PHALA_NODE_WS_ENDPOINT="ws://$NODE_HOST:$NODE_PORT_WS" \
                -e MNEMONIC="{{miner_config.mnemonic}}" \
                -e EXTRA_OPTS="-r" \
                $LINKS \
                --link phala-pruntime \
                phalanetwork/phala-poc4-phost

            sleep 15

            STATUS=$(docker ps | grep "phala-phost")
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
    if [[ {{run_node}} == 1 ]]; then
        STATUS=$(docker ps | grep "phala-node")
        if [[ $STATUS == '' ]]; then
            start_node
            sleep 10
        fi
    fi

    if [[ {{run_miner}} == 1 ]]; then
        STATUS=$(docker ps | grep "phala-pruntime")
        if [[ $STATUS == '' ]]; then
            start_runtime
            sleep 10
        fi

        STATUS=$(docker ps | grep "phala-phost")
        if [[ $STATUS == '' ]]; then
            start_host
            sleep 10
        fi
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
    if [[ {{run_node}} == 1 ]]; then
        start_node
    fi

    if [[ {{run_miner}} == 1 ]]; then
        sleep $DELAY

        start_runtime
        start_host
    fi

    start_watch &
}

# ##########
# STOP TASKS

stop_stack() {
    if [[ `ps aux | grep 'main.sh start stack' | grep -v 'grep'` != '' ]]; then
        ps aux | grep 'main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill
    fi

    if [[ {{run_miner}} == 1 ]]; then
        docker stop phala-phost
        docker stop phala-pruntime
    fi
    if [[ {{run_node}} == 1 ]]; then
        docker stop phala-node
    fi
}

stop_stats() {
    if [[ `ps aux | grep 'main.sh start stats' | grep -v 'grep'` != '' ]]; then
        ps aux | grep 'main.sh start stats' | grep -v 'grep' | awk '{print $2}' | xargs kill
    fi
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
    stats)
        stop_stats
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
