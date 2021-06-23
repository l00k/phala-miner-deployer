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

### Additional features
- `run.sh` script selects node instance based on node state. So if one of your devices boots up (may have local database out of sync) scripts telnet's each node to find running one. Next `phala-phost` is executed with working node ;)  
- rc script ensures node will be stopped before device shutdown / reboot (to prevent database crash)  
- stats monitor - check `monitor-hooks.php`

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
`docker:reinstall <tag>` - uninstalls previous docker packages and installs latest docker packages [docs](https://wiki.phala.network/en-us/docs/poc3/1-2-software-configuration/#install-docker-ce)  
`sgx_enable <tag>` - enables SGX (if software controlled) [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#bios-settings)  
`driver:check <tag>` - checks SGX driver [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#sgx-driver-installation)  
`driver:install <tag>` - installs DCAP / SGX driver (based on support) [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#sgx-driver-installation)  
`check_compatibility <tag>` - verifies miner compatibility [docs](https://wiki.phala.network/en-us/docs/poc3/1-1-hardware-configuration/#double-check-the-sgx-capability)  
`deploy <tag>` - deploys 3 scripts (based on templates from `templates/`) and installs system service `phala-stack`  
`upgrade <tag>` - upgrades stack docker images  
`stack:restart <tag>` - restarts stack  
  
For all those commands `<tag>` needs to be replaced with deployer instance name (example `my-nice-miner`) or with `miner` (in order to run command for all instances)  

For my HiveOS rigs it was:
```
php vendor/bin/dep docker:reinstall miner
php vendor/bin/dep sgx_enable miner
// reboot all instances
php vendor/bin/dep driver:install miner
php vendor/bin/dep driver:check miner
php vendor/bin/dep check_compatibility miner
php vendor/bin/dep deploy miner
```

## Configuration

### Nodes (nodes.yml)
You can use `nodes.dist.yml` as a template.

### Extra parameters (deploy-ext.inc.php)
In this file you can place extra parameters definition
For example global definition for `deploy_path`
```
<?php
namespace Deployer;
set('deploy_path', '/root/phala');
```





