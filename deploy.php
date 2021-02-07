<?php

namespace Deployer;

use Deployer\Task\Context;

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


$deployExtPath = __DIR__ . '/deploy-ext.inc.php';
if (file_exists($deployExtPath)) {
    require($deployExtPath);
}


desc('Install old docker (if necessary)');
task('phala:docker:uninstall', function () {
    run('
        sudo apt update && sudo apt upgrade -y && sudo apt autoremove -y;
        sudo apt-get remove docker docker-engine docker.io containerd runc;
    ', [ 'tty' => true ]);
});

desc('Install docker');
task('phala:docker:install', function () {
    run('
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -;
        sudo add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable";
        sudo apt-get install -y docker-ce docker-ce-cli containerd.io;
    ', [ 'tty' => true ]);
});

desc('Enable SGX (if software controlled)');
task('phala:sgx_enable', function () {
    run('
        wget https://github.com/Phala-Network/sgx-tools/releases/download/0.1/sgx_enable;
        chmod +x sgx_enable;
        sudo ./sgx_enable;
        rm sgx_enable;
    ', [ 'tty' => true ]);
});

desc('Check SGX driver');
task('phala:driver:check', function () {
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
task('phala:driver:install', function () {
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
        run('sudo apt-get install -y dkms');
    }

    // first - try install DCAP
    writeln('Installing DCAP driver');

    run('
        wget https://download.01.org/intel-sgx/sgx-dcap/1.9/linux/distro/ubuntu18.04-server/sgx_linux_x64_driver_1.36.2.bin;
        chmod +x sgx_linux_x64_driver_1.36.2.bin;
        sudo ./sgx_linux_x64_driver_1.36.2.bin;
        rm sgx_linux_x64_driver_1.36.2.bin;
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
        wget https://download.01.org/intel-sgx/sgx-linux/2.12/distro/ubuntu18.04-server/sgx_linux_x64_driver_2.11.0_4505f07.bin;
        chmod +x sgx_linux_x64_driver_2.11.0_4505f07.bin;
        sudo ./sgx_linux_x64_driver_2.11.0_4505f07.bin;
        rm sgx_linux_x64_driver_2.11.0_4505f07.bin;
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
task('phala:check_compatibility', function () {
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
task('phala:stack:deploy', function () {
    $target = Context::get()->getHost();

    $hostname = $target->getHostname();
    $withNode = $target->get('use_as_node');
    $withNodeText = $withNode
        ? 'with node'
        : 'without node';

    writeln("<info>Deploying to ${hostname} (${withNodeText})</info>");

    $scripts = [
        'rc-script.sh' => 'rc-script.sh',
        'main.sh' => $withNode ? 'main-with-node.sh' : 'main-without-node.sh',
        'stack-stats.php' => 'stack-stats.php'
    ];

    // collect all nodes ips
    $nodesByNetwork = [];

    foreach (Deployer::get()->hosts as $host) {
        $network = $host->get('network');
        $useAsNode = (bool) $host->get('use_as_node');
        if ($useAsNode) {
            if (!isset($nodesByNetwork[$network])) {
                $nodesByNetwork[$network] = [];
            }
            $nodesByNetwork[$network][] = $host->get('node_ip');
        }
    }

    $nodes = [];

    if ($target->get('force_node_ip', false)) {
        $nodes[] = $target->get('force_node_ip');
    }
    else {
        $network = $target->get('network');
        $nodes = $nodesByNetwork[$network];
    }

    $nodeIpsRaw = '"' . join('" "', $nodes) . '"';
    set('node_ips', $nodeIpsRaw);

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

    // step 1 - clear directories
    writeln('<comment>Clearing build directory (local)</comment>');

    if (testLocally('[[ -e ./build ]]')) {
        runLocally('rm -r ./build');
    }
    runLocally("mkdir -p ./build/$hostname");

    // step 2 - generate scripts
    writeln('<comment>Genereting scripts (local)</comment>');

    foreach ($scripts as $scriptDest => $scriptSrc) {
        writeln($scriptSrc);

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
        writeln($scriptDest);

        $localPath = __DIR__ . "/build/$hostname/" . $scriptSrc;
        $remotePath = '{{deploy_path}}/' . $scriptDest;

        upload($localPath, $remotePath);
        run("sudo chown root:root $remotePath");
        run("sudo chmod +x $remotePath");
    }

    // step 4 - enable service
    writeln('<comment>Configuring service</comment>');

    $serviceExists = test('[[ -e /etc/init.d/{{service_name}} ]]');
    if (!$serviceExists) {
        run("sudo ln -s {{deploy_path}}/rc-script.sh /etc/init.d/{{service_name}}");
    }

    run('update-rc.d -f {{service_name}} remove');
    run('update-rc.d {{service_name}} defaults');
});

desc('Start stack');
task('phala:stack:start', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Starting ${hostname}</info>");

    $isMainScriptWorking = test('[[ `pgrep {{deploy_path}}/main.sh` != "" ]]');
    if ($isMainScriptWorking) {
        run('kill -s 9 $(pgrep {{deploy_path}}/main.sh)');
    }

    run('{{deploy_path}}/main.sh start stack 1', [ 'tty' => true ]);
});


desc('Upgrade docker containers');
task('phala:stack:upgrade', function () {
    $isMainScriptWorking = test('[[ `pgrep {{deploy_path}}/main.sh` != "" ]]');
    if ($isMainScriptWorking) {
        run('kill -s 9 $(pgrep {{deploy_path}}/main.sh)');
    }

    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');
    run('docker stop phala-node || true');

    run('docker pull phalanetwork/phala-poc3-node');
    run('docker pull phalanetwork/phala-poc3-pruntime');
    run('docker pull phalanetwork/phala-poc3-phost');

    run('{{deploy_path}}/main.sh start stack 1', [ 'tty' => true ]);
});

desc('Fetch stack stats');
task('phala:stack:stats', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("<info>Fetching ${hostname} stats</info>");

    // prepare to php
    $phpInstalled = test('[[ `which php` == "" ]]');
    if ($phpInstalled) {
        writeln('Installing PHP');
        run('sudo apt-get install -y php');
    }

    run('{{deploy_path}}/stack-stats.php', [ 'tty' => true ]);
});
