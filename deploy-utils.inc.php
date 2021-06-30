<?php

namespace Deployer;

use Deployer\Host\Host;


set('bin/dep', function() {
    return parse('{{bin/php}} ./vendor/bin/dep');
});


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
            $children = \Deployer\array_flattern_with_path($value, $path);
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
