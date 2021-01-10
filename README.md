# phala-miner-deployer

Phala docs are good enough to make whole deployment manually, but when you have to deal with mutliple instance it may take some time to deploy all. 
Also maintainig it may be tricky ;) 
That is why this script has been implemented. 
You can install and deploy N instances at once through "deployer" tool.

I personally have used it to deploy full stack to multiple HiveOS instances (which are mining ETH meanwhile).

Root account access to device is required! (optionally account with `sudo` allowed without password)

**NOTE!**  
You are using this scripts for your own risk!  
If you don't know what are you doing - stop.  
Running someone else scripts with root privilege, even with checking source (which I strongly suggest you to do) may lead you to data / funds / device lost.

Entire process based on:  
https://wiki.phala.network/en-us/docs/poc3/

Feel free to contact me on phala discord @l00k

PS. Also worth noting - `run.sh` script selects node instance based on current machine state. So if one of your devices boots up (may have local database out of sync) scripts telnet's each node to find running one. Next `phala-phost` is executed with working node ;)

## Requirements

- PHP >= 7.2
- Composer

### Dependencies
Only one direct dependency - `deployer/deployer`  
https://deployer.org/

## Usage

1. First of all you need to clone (or download) repository  
`git clone git@github.com:l00k/phala-miner-deployer.git`
2. Install dependencies  
`composer install`
3. Prepare `nodes.yml` configuration file. Check [configuration section](#configuration)  
4. (optional) `deploy-ext.inc.php` configuration file
5. Following phala.network tutorial deploy full stack. All following commands can be run per instance or for all (using tag `miner`)  
`phala:docker:uninstall <tag>` - uninstalls previous docker packages [docs](https://wiki.phala.network/en-us/docs/poc3/1-2-software-configuration/#install-docker-ce)  
`phala:docker:install <tag>` - installs latest docker packages [docs](https://wiki.phala.network/en-us/docs/poc3/1-2-software-configuration/#install-docker-ce)  
`phala:sgx_enable <tag>` - enables SGX (if software controlled) [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#bios-settings)  
`phala:driver:check <tag>` - checks SGX driver [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#sgx-driver-installation)  
`phala:driver:install <tag>` - installs DCAP / SGX driver (based on support) [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#sgx-driver-installation)  
`phala:check_compatibility <tag>` - verifies miner compatibility [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#double-check-the-sgx-capability)  
`phala:stack:deploy <tag>` - deploys 3 scripts (based on templates from `templates/`) and installs system service `phala-stack`  
  
For all those commands `<tag>` needs to be replaced with deployer instance name (example `my-nice-miner`) or with `miner` (in order to run command for all instances)  

For my HiveOS rigs it was:
```
php vendor/bin/dep phala:docker:uninstall miner
php vendor/bin/dep phala:docker:install miner
php vendor/bin/dep phala:sgx_enable miner
// reboot all instances
php vendor/bin/dep phala:driver:install miner
php vendor/bin/dep phala:driver:check miner
php vendor/bin/dep phala:check_compatibility miner
php vendor/bin/dep phala:stack:deploy miner
```

## Configuration

### Nodes (nodes.yml)
You can use `nodes.dist.yml` as a template.
```
my-nice-miner:
    hostname: 123.45.67.89
    port: 22
    user: root
    stage: 'miner'
    deploy_path: '/root/phala'
    node_name: 'my-nice-miner'
    miner_mnemonic: 'secret words here secret words here secret words here secret words here'
<other node internal name>:
    (...)
```
`my-nice-miner` - deployer host identifier (make it unique for each node)  
`<node>.hostname` - IP address of your node  
`<node>.port` - self explanatory  
`<node>.user` - root or other user which has access to `sudo` command (without password - google "visudo nopasswd")  
`<node>.deploy_path` - directory where all scripts and node data will be placed  
`<node>.node_name` - place name which will be used publically by Phala network to identify your node  
`<node>.miner_mnemonic` - your controller account mnemonic

### Extra parameters (deploy-ext.inc.php)
In this file you can place extra parameters definition
For example global definition for `deploy_path` or `join_lan_cmd`
```
<?php
namespace Deployer;
set('deploy_path', '/root/phala');
set('join_lan_cmd', 'zerotier-cli join <lan id>');
```





