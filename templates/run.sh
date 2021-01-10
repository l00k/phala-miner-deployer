#!/bin/bash

cd "{{deploy_path}}"

# start node
sudo docker run -dit --rm \
    --name phala-node \
    -e NODE_NAME="{{node_name}}" \
    -p 9933:9933 -p 9944:9944 -p 30333:30333 \
    -v {{deploy_path}}/phala-node-data:/root/data \
    phalanetwork/phala-poc3-node

# wait for whole system resources to boot up
sleep 60

# start runtime
sudo docker run -dit --rm \
    --name phala-pruntime \
    -p 8000:8000 \
    -v {{deploy_path}}/phala-pruntime-data:/root/data \
    {{pruntime_devices}} \
    phalanetwork/phala-poc3-pruntime

# join LAN
{{join_lan_cmd}}

# select node from all
NODES=({{node_ips}})
NODES+=("phala-node")
DONE=0

for NODE_IP in "${NODES[@]}"; do
    echo "Checking $NODE_IP"
    STATUS=`echo 'exit' | telnet $NODE_IP 9944 | grep "Connected to"`
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

        STATUS=`sudo docker ps | grep "phala-phost"`
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


