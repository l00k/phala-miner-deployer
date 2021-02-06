#!/usr/bin/php
<?php

chdir('{{deploy_path}}');

function clear() {
    system('clear');
}

function _display(string $color = "\033[39m",  $text = '', int $level = 0) {
    echo str_repeat("\t", $level) . $color . $text . "\033[39m" . PHP_EOL;
}

function displayHeader(string $text = '', int $level = 0) {
    _display("\033[96m", $text, $level);
}

function displayLog(string $text = '', int $level = 0) {
    _display("\033[39m", $text, $level);
}

function displayComment(string $text = '', int $level = 0) {
    _display("\033[37m", $text, $level);
}

function displayInfo(string $text = '', int $level = 0) {
    _display("\033[32m", $text, $level);
}

function displayWarn(string $text = '', int $level = 0) {
    _display("\033[93m", $text, $level);
}

function displayError(string $text = '', int $level = 0) {
    _display("\033[91m", $text, $level);
}

function checkDockerContainer(string $name): int {
    $exists = `sudo docker ps | grep "$name"`;
    if (!$exists) {
        return 1;
    }

    $status = `sudo docker ps | grep "$name" | grep " Up "`;
    if (!$status) {
        return 2;
    }

    return 0;
}

function checkNode() {
    displayHeader('Node');

    // check container
    $status = checkDockerContainer('phala-node');
    if ($status === 0) {
        displayComment('Container is runnning', 1);
    }
    elseif ($status === 1) {
        displayComment('Container does not exist', 1);
        return;
    }
    elseif ($status === 2) {
        displayError('Container is not runnning', 1);
        return;
    }

    // check service status
    $serviceStatusRaw = `curl -s -H "Content-Type: application/json" --data '{"jsonrpc":"2.0","method":"system_health","params":[],"id":1}' localhost:9933`;
    $serviceStatusJson = json_decode($serviceStatusRaw, true);
    $serviceStatus = $serviceStatusJson['result'];
    if (!$serviceStatus['isSyncing']) {
        displayInfo('Node is in sync', 1);
    }
    else {
        displayWarn('Node is syncing', 1);
    }
}

function checkRuntime() {
    displayHeader('Runtime');

    // check container
    $status = checkDockerContainer('phala-pruntime');
    if ($status === 0) {
        displayComment('Container is running', 1);
    }
    else {
        displayError('Container is not running', 1);
        return;
    }

    // check service status
    $serviceStatusRaw = `curl -s -X POST -H "Content-Type: application/json" --data '{"input":{},"nonce":{"id":0}}' localhost:8000/get_info`;
    $serviceStatusJson = json_decode($serviceStatusRaw, true);

    if ($serviceStatusJson['status'] === 'ok') {
        displayComment('Runtime status OK', 1);
    }
    else {
        displayError('Runtime wrong status', 1);
        return;
    }

    $getInfoPayload = json_decode($serviceStatusJson['payload'], true);
    if ($getInfoPayload['initialized']) {
        displayInfo('Runtime is initialized and working', 1);
    }
    else {
        displayInfo('Runtime is not initialized. Did you start host?', 1);
    }
}


function checkHost() {
    displayHeader('Host');

    // check container
    $status = checkDockerContainer('phala-phost');
    if ($status === 0) {
        displayInfo('Container is running', 1);
    }
    else {
        displayError('Container is not running', 1);
    }
}


function checkSystem() {

    $devices = [];

    // cpu
    $devices['cpu'] = [];

    $raw = `cat /proc/cpuinfo | grep "model name" | head -n 1`;
    $devices['cpu']['name'] = trim(preg_replace('/^model name[\s]+:[\s]+(.*)$/', '$1', $raw));

    $raw = `cat /proc/cpuinfo | grep "cpu cores" | head -n 1`;
    $devices['cpu']['cores'] = trim(preg_replace('/^cpu cores[\s]+:[\s]+(.*)$/', '$1', $raw));

    // temperatures
    $temperatures = [];

    $rawTypes = array_filter(explode(PHP_EOL, `cat /sys/class/thermal/thermal_zone*/type`));
    $types = array_map(
        function($value, $index) { return $value . '-' . $index; },
        $rawTypes,
        array_keys($rawTypes)
    );
    $rawTemps = array_filter(explode(PHP_EOL, `cat /sys/class/thermal/thermal_zone*/temp`));
    $temps = array_combine(
        $types,
        array_map('floatval', $rawTemps)
    );

    $cpuIndices = array_filter($types, function($type) { return strpos($type, 'pkg_temp') !== false; });
    if ($cpuIndices) {
        $cpuIndex = array_shift($cpuIndices);
        $devices['cpu']['temp'] = $temps[$cpuIndex] / 1000;
        $temperatures['cpu'] = $temps[$cpuIndex] / 1000;
    }

    // display
    displayHeader('Devices');
    foreach ($devices as $type => $device) {
        displayLog($type, 1);
        foreach ($device as $key => $value) {
            displayComment("$key\t$value", 2);
        }
    }

    displayHeader('Temperatures');
    foreach ($temperatures as $device => $temp) {
        displayLog("$device\t${temp}°C", 1);
    }
}


checkNode();
checkRuntime();
checkHost();
checkSystem();
