<?php

namespace Deployer;

use Deployer\Task\Context;
use Pimple\Tests\Fixtures\Service;

require 'recipe/common.php';

// general deployer configuration
set('application', 'phala-miner-deployer');
set('repository', 'git@github.com:l00k/phala-miner-deployer.git');
set('git_tty', true);
set('allow_anonymous_stats', false);

// project configuration
set('service_name', 'phala-stack');
set('network', 'main');
set('use_as_node', false);
inventory('nodes.yml');


// utils
set('bin/dep', function () {
    return parse('{{bin/php}} ./vendor/bin/dep');
});


$deployExtPath = __DIR__ . '/deploy-ext.inc.php';
if (file_exists($deployExtPath)) {
    require($deployExtPath);
}


set('nodesByNetwork', function() {
    $nodesByNetwork = [];

    writeln('Fetching node IPs');

    foreach (Deployer::get()->hosts as $host) {
        $_hostname = $host->getHostname();
        $useAsNode = (bool) $host->get('use_as_node');

        if ($useAsNode) {
            write('.');

            $network = $host->get('network', 'main');
            $nodePorts = $host->getConfig()->get('ports');

            if (!isset($nodesByNetwork[$network])) {
                $nodesByNetwork[$network] = [];
            }

            $nodeIps = [];
            if ($host->get('public_node_ip', false)) {
                $nodeIps[] = $host->get('public_node_ip');
            }
            else {
                try {
                    $allNodeIpsTxt = runLocally("{{bin/dep}} get-local-network-ip -q $_hostname");
                    $allNodeIps = explode(' ', $allNodeIpsTxt);

                    // remove 172.* ips
                    $nodeIps = array_filter(
                        $allNodeIps,
                        function($ip) {
                            $group1 = explode('.', $ip)[0];
                            return !in_array($group1, [ 172 ]);
                        }
                    );
                }
                catch(\Exception $e) {}
            }

            foreach ($nodeIps as $nodeIp) {
                $nodesByNetwork[$network][] = implode(':', [ $nodeIp, $nodePorts[0], $nodePorts[1] ]);
            }
        }
    }

    writeln('');

    return $nodesByNetwork;
});

set('bin/dep', function () {
    return parse('{{bin/php}} ./vendor/bin/dep');
});


desc('Setup stack');
task('setup', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    runLocally("{{bin/dep}} docker:reinstall $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} driver:install $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} check_compatibility $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} deploy $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} reboot $hostname", [ 'tty' => true ]);
});

desc('Reinstall docker');
task('docker:reinstall', function () {
    run('sudo apt update && sudo apt upgrade -y && sudo apt autoremove -y;', [ 'tty' => true ]);

    try {
        run('sudo apt-get remove docker docker-engine docker.io containerd runc;', [ 'tty' => true ]);
    }
    catch (\Exception $e) {}

    run('
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -;
        sudo add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable";
        sudo apt-get install -y docker-ce docker-ce-cli containerd.io;
    ', [ 'tty' => true ]);
});

desc('Enable SGX (if software controlled)');
task('sgx_enable', function () {
    run('
        wget https://github.com/Phala-Network/sgx-tools/releases/download/0.1/sgx_enable;
        chmod +x sgx_enable;
        sudo ./sgx_enable;
        rm sgx_enable;
    ', [ 'tty' => true ]);
});

desc('Check SGX driver');
task('driver:check', function () {
    $isInstalled = test('[[ -e /dev/sgx ]]');
    if ($isInstalled) {
        writeln('<comment>DCAP driver is installed</comment>');
        return;
    }

    $isInstalled = test('[[ -e /dev/isgx ]]');
    if ($isInstalled) {
        writeln('<comment>SGX driver is installed</comment>');
        return;
    }

    writeln('<error>Sadly - none of drivers seems to work.</error>');
});

desc('Install SGX driver');
task('driver:install', function () {
    $isInstalled = test('[[ -e /dev/sgx ]]');
    if ($isInstalled) {
        writeln('<comment>DCAP driver is already installed</comment>');
        return;
    }

    $isInstalled = test('[[ -e /dev/isgx ]]');
    if ($isInstalled) {
        writeln('<comment>SGX driver is already installed</comment>');
        return;
    }

    // prepare to install
    $dkmsInstalled = test('[[ `which dkms` == "" ]]');
    if ($dkmsInstalled) {
        writeln('Installing dkms');
        run('sudo apt-get install -y dkms', [ 'tty' => true ]);
    }

    // first - try install DCAP
    writeln('Installing DCAP driver');

    run('
        wget https://download.01.org/intel-sgx/sgx-dcap/1.10.3/linux/distro/ubuntu20.04-server/sgx_linux_x64_driver_1.41.bin;
        chmod +x sgx_linux_x64_driver_1.41.bin;
        sudo ./sgx_linux_x64_driver_1.41.bin;
        rm sgx_linux_x64_driver_1.41.bin;
    ');
    $isInstalled = test('[[ -e /dev/sgx ]]');
    $uninstallExists = test('[[ -e /opt/intel/sgxdriver/uninstall.sh ]]');
    if ($isInstalled) {
        writeln('<info>DCAP driver successfully installed!</info>');
        return;
    }
    elseif ($uninstallExists) {
        writeln('DCAP driver doesn\'t work. Uninstalling...');
        run('sudo /opt/intel/sgxdriver/uninstall.sh');
    }

    // try install SGX driver
    writeln('Installing SGX driver');

    run('
        wget https://download.01.org/intel-sgx/sgx-linux/2.13.3/distro/ubuntu20.04-server/sgx_linux_x64_driver_2.11.0_2d2b795.bin;
        chmod +x sgx_linux_x64_driver_2.11.0_2d2b795.bin;
        sudo ./sgx_linux_x64_driver_2.11.0_2d2b795.bin;
        rm sgx_linux_x64_driver_2.11.0_2d2b795.bin;
    ');
    $isInstalled = test('[[ -e /dev/isgx ]]');
    $uninstallExists = test('[[ -e /opt/intel/sgxdriver/uninstall.sh ]]');
    if ($isInstalled) {
        writeln('<info>SGX driver successfully installed!</info>');
        return;
    }
    elseif ($uninstallExists) {
        writeln('SGX driver doesn\'t work. Uninstalling...');
        run('sudo /opt/intel/sgxdriver/uninstall.sh');
    }

    writeln('<error>Sadly - none of drivers seems to work.</error>');
});

desc('Check Phala miner compatibility');
task('check_compatibility', function () {
    $usingDcapDriver = test('[[ -e /dev/sgx ]]');
    $usingSgxDriver = test('[[ -e /dev/isgx ]]');

    run('sudo docker pull phalanetwork/phala-sgx_detect', [ 'tty' => true ]);

    if ($usingDcapDriver) {
        writeln('<comment>Checking Phala stack compatablity with DCAP driver</comment>');
        $result = run('sudo docker run --rm --name phala-sgx_detect --device /dev/sgx/enclave --device /dev/sgx/provision phalanetwork/phala-sgx_detect');
    }
    elseif ($usingSgxDriver) {
        writeln('<comment>Checking Phala stack compatablity with SGX driver</comment>');
        $result = run('sudo docker run --rm --name phala-sgx_detect --device /dev/isgx phalanetwork/phala-sgx_detect');
    }
    else {
        writeln('<comment>None of drivers installed. Running for diagnose purpose</comment>');
        $result = run('sudo docker run --rm --name phala-sgx_detect phalanetwork/phala-sgx_detect');
    }

    writeln($result);

    writeln('');
    writeln('<comment>Checking:</comment>');

    $check1 = strpos($result, 'yes Flexible launch control');
    if ($check1 === false) {
        writeln('<error>Flexible launch control</error>');
    }
    else {
        $check2 = strpos($result, '  yes Able to launch production mode enclave', $check1);
        if ($check2 === false) {
            writeln('<error>Flexible launch control → Able to launch production mode enclave</error>');
        }
        else {
            writeln('<info>Flexible launch control → Able to launch production mode enclave</info>');
        }
    }

    $check1 = strpos($result, 'yes SGX system software');
    if ($check1 === false) {
        writeln('<error>SGX system software</error>');
    }
    else {
        $check2 = strpos($result, '  yes Able to launch enclaves', $check1);
        if ($check2 === false) {
            writeln('<error>SGX system software → Able to launch enclaves → Production Mode</error>');
        }
        else {
            $check3 = strpos($result, '    yes Production mode', $check2);
            if ($check3 === false) {
                writeln('<error>SGX system software → Able to launch enclaves</error>');
            }
            else {
                writeln('<info>SGX system software → Able to launch enclaves → Production Mode</info>');
            }
        }
    }

    writeln('');
});

desc('Deploy stack');
task('deploy', function () {
    $target = Context::get()->getHost();

    // setup node name
    if (!$target->get('node_name', false)) {
        $target->set('node_name', $target->getHostname());
    }

    $hostname = $target->getHostname();
    $useAsNode = $target->get('use_as_node');
    $useAsNodeText = $useAsNode
        ? 'with node'
        : 'without node';

    writeln("<info>Deploying to ${hostname} (${useAsNodeText})</info>");

    $scripts = [
        'rc-script.sh' => 'rc-script.sh',
        'main.sh' => $useAsNode ? 'main-with-node.sh' : 'main-without-node.sh',
    ];

    $publicDeviceStats = get('public_device_stats', false);
    if ($publicDeviceStats) {
        $scripts['device-state-updater.php'] = 'device-state-updater.php';
    };

    // setup ports
    $nodePorts = $target->get('ports', []);
    foreach ($nodePorts as $idx => $nodePort) {
        set("ports_$idx", $nodePort);
    }

    // collect all nodes ips
    $nodes = [];

    if ($target->get('force_node_ip', false)) {
        $nodes[] = $target->get('force_node_ip');
    }
    else {
        if ($useAsNode) {
            $nodes[] = "phala-node:$nodePorts[0]:$nodePorts[1]";
        }

        $nodesByNetwork = get('nodesByNetwork');
        $network = $target->get('network');

        $nodes = array_merge(
            $nodes,
            $nodesByNetwork[$network]
        );
    }

    $nodeIpsRaw = '"' . join('" "', $nodes) . '"';
    set('nodes', $nodeIpsRaw);

    // get device
    $dockerDevices = '';

    $isDcapDriver = test('[[ -e /dev/sgx ]]');
    if ($isDcapDriver) {
        $dockerDevices = '--device /dev/sgx/enclave --device /dev/sgx/provision';
    }

    $isSgxDriver = test('[[ -e /dev/isgx ]]');
    if ($isSgxDriver) {
        $dockerDevices = '--device /dev/isgx';
    }

    set('pruntime_devices', $dockerDevices);

    // step 0 - install dependencies
    if ($publicDeviceStats) {
        run("which php || sudo apt install -y php");
    }

    // step 1 - clear directories
    writeln('<comment>Clearing build directory (local)</comment>');

    if (testLocally('[[ ! -e ./build ]]')) {
        runLocally('mkdir -p ./build');
    }

    if (testLocally("[[ -e ./build/$hostname ]]")) {
        runLocally("rm -r ./build/$hostname");
    }
    runLocally("mkdir -p ./build/$hostname");

    // step 2 - generate scripts
    writeln('<comment>Genereting scripts (local)</comment>');

    foreach ($scripts as $scriptDest => $scriptSrc) {
        writeln("\t" . $scriptSrc);

        $templatePath = __DIR__ . '/templates/' . $scriptSrc;
        $scriptPath = __DIR__ . "/build/$hostname/" . $scriptSrc;

        $templateContent = file_get_contents($templatePath);
        $scriptContent = parse($templateContent);

        file_put_contents($scriptPath, $scriptContent);
    }

    // step 3 - uploading scripts
    writeln('<comment>Uploading scripts</comment>');

    $deployDirExists = test('[[ -e {{deploy_path}} ]]');
    if (!$deployDirExists) {
        run('sudo mkdir -p {{deploy_path}}');
    }

    foreach ($scripts as $scriptDest => $scriptSrc) {
        writeln("\t" . $scriptDest);

        $localPath = __DIR__ . "/build/$hostname/" . $scriptSrc;
        $remotePath = '{{deploy_path}}/' . $scriptDest;

        upload($localPath, $remotePath);
        run("sudo chown root:root $remotePath");
        run("sudo chmod +x $remotePath");
    }

    // step 4 - enable service
    writeln('<comment>Configuring service</comment>');

    run("
        cd {{deploy_path}}
        ./rc-script.sh install
    ");
});

desc('Get local network IP');
task('get-local-network-ip', function () {
    echo run("hostname -I");
});

desc('Reboot device');
task('reboot', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Rebooting ${hostname}</info>");
    try {
        run('sudo reboot');
    }
    catch(\Exception $e) {}
});

desc('Refresh stack');
task('stack:refresh', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Refreshing ${hostname} stack</info>");

    runLocally("{{bin/dep}} deploy $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} stack:restart $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} stats:restart $hostname", [ 'tty' => true ]);
});

desc('Restart host');
task('stack:restart', function () {
    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('docker stop phala-phost || true');
    run("nohup {{deploy_path}}/main.sh start stack 1 > /dev/null 2>&1 &");
});

desc('Stop stack');
task('stack:stop', function () {
    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');
});


desc('Upgrade docker containers');
task('stack:upgrade', function () {
    $target = Context::get()->getHost();
    $withNode = $target->get('use_as_node');

    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    // stop dockers
    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');

    // pull again
    if ($withNode) {
        run('docker pull phalanetwork/phala-poc4-node', [ 'tty' => true ]);
    }
    run('docker pull phalanetwork/phala-poc4-pruntime', [ 'tty' => true ]);
    run('docker pull phalanetwork/phala-poc4-phost', [ 'tty' => true ]);

    run("nohup {{deploy_path}}/main.sh start stack 1 > /dev/null 2>&1 &");
});


desc('Create stack database backup');
task('db:backup', function () {
    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    // stop dockers
    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');

    // create backup
    if (test('[[ -e {{deploy_path}}/phala-node-data ]]')) {
        run("
            cd {{deploy_path}}
            [[ -e phala-node-data-bak ]] && rm -r phala-node-data-bak
            cp -r phala-node-data phala-node-data-bak
        ", [ 'timeout' => 0 ]);
    }

    if (test('[[ -e {{deploy_path}}/phala-pruntime-data ]]')) {
        run("
            cd {{deploy_path}}
            [[ -e phala-pruntime-data-bak ]] && rm -r phala-pruntime-data-bak
            cp -r phala-pruntime-data phala-pruntime-data-bak
        ", [ 'timeout' => 0 ]);
    }

    run("nohup {{deploy_path}}/main.sh start stack 1 > /dev/null 2>&1 &");
});


desc('Restore stack datatbase from backup');
task('db:restore', function () {
    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    // stop dockers
    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');

    // create backup
    if (test('[[ -e {{deploy_path}}/phala-node-data-bak ]]')) {
        run("
            cd {{deploy_path}}
            [[ -e phala-node-data ]] && rm -r phala-node-data
            rsync -aHAX --progress phala-node-data-bak phala-node-data
        ", [ 'timeout' => 0, 'tty' => true ]);
    }

    if (test('[[ -e {{deploy_path}}/phala-pruntime-data-bak ]]')) {
        run("
            cd {{deploy_path}}
            [[ -e phala-pruntime-data ]] && rm -r phala-pruntime-data
            rsync -aHAX --progress phala-pruntime-data-bak phala-pruntime-data
        ", [ 'timeout' => 0, 'tty' => true ]);
    }

    run("nohup {{deploy_path}}/main.sh start stack 1 > /dev/null 2>&1 &");
});


task('stats:restart', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Stats start for ${hostname}</info>");

    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('nohup {{deploy_path}}/main.sh start stats > /dev/null 2>&1 &', [ 'timeout' => 1 ]);
});


task('purge', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Purge for ${hostname}</info>");

    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');
    run('docker rmi -f $(docker images -a -q)');
    run('rm -rf {{deploy_path}}');
    run('update-rc.d {{service_name}} remove');
    run('rm /etc/init.d/{{service_name}} || true');
    run('rm /etc/rc*.d/*{{service_name}} || true');
});
