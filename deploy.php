<?php

namespace Deployer;

use Deployer\Host\Host;
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
set('miner_mnemonic', 'unknown');
set('controller_address', 'unknown');
set('run_node', 0);
set('run_miner', 1);
set('node_config', [
    'name' => 'phala-node',
    'ports' => [ 9933, 9944, 30333 ],
]);
set('miner_config', [
    'mnemonic' => 'unknown',
    'controller_addres' => 'unknown',
]);
set('public_device_stats', 0);

inventory('nodes.yml');


// utils
set('bin/dep', function () {
    return parse('{{bin/php}} ./vendor/bin/dep');
});


$deployExtPath = __DIR__ . '/deploy-ext.inc.php';
if (file_exists($deployExtPath)) {
    require($deployExtPath);
}


function getHostIdx(Host $host)
{
    $hosts = Deployer::get()->hosts->toArray();

    $idx = 0;
    foreach ($hosts as $_host) {
        if ($_host === $host) {
            break;
        }
        ++$idx;
    }

    return $idx;
}

function array_flattern_with_path(array $source, $pathPrefix = ''): array
{
    $out = [];
    foreach ($source as $name => $value) {
        $path = ($pathPrefix ? $pathPrefix . '.' : '') . $name;

        if (is_array($value)) {
            $children = array_flattern_with_path($value, $path);
            $out = array_merge($out, $children);
        }
        else {
            $out[$path] = $value;
        }
    }
    return $out;
}

function decrypt_mnemonic(string $method, string $encryptedMnemonic, string $key, string $iv): string
{
    return openssl_decrypt(
        $encryptedMnemonic,
        $method,
        $key,
        0,
        $iv
    );
}


set('nodesByNetwork', function() {
    $nodesByNetwork = [];

    writeln('Fetching node IPs');

    $idx = 0;

    $hosts = Deployer::get()->hosts->toArray();
    foreach ($hosts as $host) {
        $_hostname = $host->getHostname();
        $runNode = (bool) $host->get('run_node');

        if ($runNode) {
            write('.');

            $network = $host->get('network');
            $nodeConfig = $host->getConfig()->get('node_config');
            $nodePorts = $nodeConfig['ports'];

            if (!isset($nodesByNetwork[$network])) {
                $nodesByNetwork[$network] = [];
            }

            $nodeIps = [];
            if (!empty($nodeConfig['ips'])) {
                $nodeIps = $nodeConfig['ips'];
            }
            else {
                try {
                    $allNodeIpsTxt = runLocally("{{bin/dep}} get-local-network-ip -q $_hostname");
                    $allNodeIps = explode(' ', $allNodeIpsTxt);

                    // remove 172.* ips
                    $nodeIps = array_filter(
                        $allNodeIps,
                        function($ip) {
                            $part1 = explode('.', $ip)[0];
                            return !in_array($part1, [ 172 ]);
                        }
                    );
                }
                catch(\Exception $e) {}
            }

            $nodesByNetwork[$network][$idx] = [];
            foreach ($nodeIps as $nodeIp) {
                $nodesByNetwork[$network][$idx][] = implode(':', [ $nodeIp, $nodePorts[0], $nodePorts[1] ]);
            }

            ++$idx;
        }
    }

    writeln('');

    return $nodesByNetwork;
});

set('bin/dep', function () {
    return parse('{{bin/php}} ./vendor/bin/dep');
});


desc('Encrypt mnemonics');
task('mnemonic_encrypt', function () {
    $config = yaml_parse_file('nodes.yml');

    $target = Context::get()->getHost();
    $encryptionAlgorithm = 'aes-256-cbc-hmac-sha256';
    $encryptionKey = $target->get('encryption_key');

    foreach ($config as $hostname => &$hostConfig) {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc-hmac-sha256');
        $iv = substr($hostname, 0, $ivLength);
        $iv = str_pad($iv, $ivLength);

        $hostConfig['miner_config']['encrypted_mnemonic'] = openssl_encrypt(
            $hostConfig['miner_config']['mnemonic'],
            $encryptionAlgorithm,
            $encryptionKey,
            0,
            $iv
        );

        $hostConfig['miner_config'] = [
            'encrypted_mnemonic' => $hostConfig['miner_config']['encrypted_mnemonic'],
            'controller_address' => $hostConfig['miner_config']['controller_address'],
        ];
    }

    yaml_emit_file('nodes.yml', $config);
})->local();


desc('Setup stack');
task('setup', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();

    writeln("Setup for <info>${hostname}</info>");

    runLocally("{{bin/dep}} docker:reinstall $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} driver:install $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} check_compatibility $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} deploy $hostname", [ 'tty' => true ]);
    runLocally("{{bin/dep}} reboot $hostname", [ 'tty' => true ]);
});

desc('Reinstall docker');
task('docker:reinstall', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Reinstalling docker for <info>${hostname}</info>");

    run('
        sudo apt update;
        sudo apt upgrade -y;
        sudo apt autoremove -y;
        sudo apt -y install curl
    ', [ 'tty' => true ]);

    try {
        run('sudo apt-get remove docker docker-engine docker.io containerd runc;', [ 'tty' => true ]);
    }
    catch (\Exception $e) {}

    run('
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg;
        echo \
            "deb [arch=amd64 signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
            $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null;
        sudo apt update;
    ', [ 'tty' => true ]);

    run('sudo apt install -y docker-ce docker-ce-cli containerd.io', [ 'tty' => true ]);
});

desc('Enable SGX (if software controlled)');
task('sgx_enable', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Enabling software managed SGX for <info>${hostname}</info>");

    run('
        wget https://github.com/Phala-Network/sgx-tools/releases/download/0.1/sgx_enable;
        chmod +x sgx_enable;
        sudo ./sgx_enable;
        rm sgx_enable;
    ', [ 'tty' => true ]);
});

desc('Check SGX driver');
task('driver:check', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Checking driver for <info>${hostname}</info>");

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
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Installing driver for <info>${hostname}</info>");

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
    ', [ 'tty' => true ]);

    sleep(1);

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
    ', [ 'tty' => true ]);

    sleep(1);

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
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Checking compatibility for <info>${hostname}</info>");

    $usingDcapDriver = test('[[ -e /dev/sgx ]]');
    $usingSgxDriver = test('[[ -e /dev/isgx ]]');

    run('docker pull phalanetwork/phala-sgx_detect', [ 'tty' => true ]);

    if ($usingDcapDriver) {
        writeln('<comment>Checking Phala stack compatablity with DCAP driver</comment>');
        $result = run('docker run --rm --name phala-sgx_detect --device /dev/sgx/enclave --device /dev/sgx/provision phalanetwork/phala-sgx_detect');
    }
    elseif ($usingSgxDriver) {
        writeln('<comment>Checking Phala stack compatablity with SGX driver</comment>');
        $result = run('docker run --rm --name phala-sgx_detect --device /dev/isgx phalanetwork/phala-sgx_detect');
    }
    else {
        writeln('<comment>None of drivers installed. Running for diagnose purpose</comment>');
        $result = run('docker run --rm --name phala-sgx_detect phalanetwork/phala-sgx_detect');
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
    $targetIdx = getHostIdx($target);

    // display info
    $hostname = $target->getHostname();
    writeln("<info>Deploying to ${hostname}</info>");

    // prepare scripts list based on config
    $scripts = [
        'rc-script.sh' => 'rc-script.sh',
        'main.sh' => 'main.sh',
    ];

    $publicDeviceStats = get('public_device_stats');
    if ($publicDeviceStats) {
        $scripts['device-state-updater.php'] = 'device-state-updater.php';
    };

    // get configuration
    $nodeConfig = $target->get('node_config');
    $minerConfig = $target->get('miner_config');

    // decrypt mnemnoic
    if (empty($minerConfig['mnemonic']) && !empty($minerConfig['encrypted_mnemonic'])) {
        $encryptionAlgorithm = 'aes-256-cbc-hmac-sha256';
        $encryptionKey = $target->get('encryption_key');

        $ivLength = openssl_cipher_iv_length('aes-256-cbc-hmac-sha256');
        $iv = substr($hostname, 0, $ivLength);
        $iv = str_pad($iv, $ivLength);

        $minerConfig['mnemonic'] = decrypt_mnemonic(
            $encryptionAlgorithm,
            $minerConfig['encrypted_mnemonic'],
            $encryptionKey,
            $iv
        );
    }

    // set placeholders
    $placeholders = [
        'node_config.name' => null,
        'node_config.ips' => null,
        'node_config.ports' => null,
        'node_config.extra_opts' => null,
        'miner_config.controller_address' => null,
        'node_config' => $nodeConfig,
        'miner_config' => $minerConfig,
    ];

    // get nodes
    if (!empty($minerConfig['force_node_ip'])) {
        $nodes[] = $minerConfig['force_node_ip'];
    }
    else {
        $nodesByNetwork = get('nodesByNetwork');
        $network = $target->get('network');
        $nodes = $nodesByNetwork[$network];
    }

    for ($i=0; $i<$targetIdx; ++$i) {
        array_push($nodes, array_shift($nodes));
    }

    $flatNodes = array_merge(...$nodes);
    $placeholders['nodes'] = '"' . join('" "', $flatNodes) . '"';

    // get device
    $placeholders['pruntime_devices'] = '';

    $isDcapDriver = test('[[ -e /dev/sgx ]]');
    if ($isDcapDriver) {
        $placeholders['pruntime_devices'] = '--device /dev/sgx/enclave --device /dev/sgx/provision';
    }

    $isSgxDriver = test('[[ -e /dev/isgx ]]');
    if ($isSgxDriver) {
        $placeholders['pruntime_devices'] = '--device /dev/isgx';
    }

    // assign placeholders
    $placeholders = array_flattern_with_path($placeholders);

    foreach ($placeholders as $placeholder => $value) {
        $target->set($placeholder, $value);
    }

    // step 1 - install dependencies
    if ($publicDeviceStats) {
        run("which php || sudo apt install -y php");
    }

    // step 2 - clear directories
    writeln('<comment>Clearing build directory (local)</comment>');

    if (testLocally('[[ ! -e ./build ]]')) {
        runLocally('mkdir -p ./build');
    }

    if (testLocally("[[ -e ./build/$hostname ]]")) {
        runLocally("rm -r ./build/$hostname");
    }
    runLocally("mkdir -p ./build/$hostname");

    // step 3 - generate scripts
    writeln('<comment>Genereting scripts (local)</comment>');

    foreach ($scripts as $scriptDest => $scriptSrc) {
        writeln("\t" . $scriptSrc);

        $templatePath = __DIR__ . '/templates/' . $scriptSrc;
        $scriptPath = __DIR__ . "/build/$hostname/" . $scriptSrc;

        $templateContent = file_get_contents($templatePath);
        $scriptContent = parse($templateContent);

        file_put_contents($scriptPath, $scriptContent);
    }

    // step 4 - uploading scripts
    writeln('<comment>Uploading scripts</comment>');

    $deployDirExists = test('[[ -e {{deploy_path}} ]]');
    if (!$deployDirExists) {
        run('mkdir -p {{deploy_path}}');
    }

    foreach ($scripts as $scriptDest => $scriptSrc) {
        writeln("\t" . $scriptDest);

        $localPath = __DIR__ . "/build/$hostname/" . $scriptSrc;
        $remotePath = '{{deploy_path}}/' . $scriptDest;

        upload($localPath, $remotePath);

        run("chmod +x $remotePath");
    }

    // step 5 - enable service
    writeln('<comment>Configuring service</comment>');

    run("
        cd {{deploy_path}}
        sudo ./rc-script.sh install
    ");
});

desc('Get local network IP');
task('get-local-network-ip', function () {
    echo run("hostname -I | awk '{print $1}'");
});

desc('Reboot device');
task('reboot', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Rebooting <info>${hostname}</info>");

    try {
        run('sudo reboot');
    }
    catch(\Exception $e) {}
});

desc('Restart host');
task('stack:restart', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Restarting stack for <info>${hostname}</info>");


    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stack' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('docker stop phala-phost || true');
    run('docker stop phala-pruntime || true');

    run('cd {{deploy_path}} && rm -r phala-pruntime-data || true');

    run("nohup {{deploy_path}}/main.sh start stack 1 > /dev/null 2>&1 &");
});

desc('Upgrade docker containers');
task('stack:upgrade', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Upgrading stack for <info>${hostname}</info>");

    $withNode = $target->get('run_node');

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

task('stats:restart', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Restarting stats for <info>${hostname}</info>");

    if (test("[[ `ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep'` != '' ]]")) {
        run("ps aux | grep '{{deploy_path}}/main.sh start stats' | grep -v 'grep' | awk '{print $2}' | xargs kill");
    }

    run('nohup {{deploy_path}}/main.sh start stats > /dev/null 2>&1 &', [ 'timeout' => 1 ]);
});

task('purge', function () {
    $target = Context::get()->getHost();
    $hostname = $target->getHostname();
    writeln("Purge stack for <info>${hostname}</info>");

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
    run('sudo update-rc.d {{service_name}} remove');
    run('sudo rm /etc/init.d/{{service_name}} || true');
    run('sudo rm /etc/rc*.d/*{{service_name}} || true');
});
