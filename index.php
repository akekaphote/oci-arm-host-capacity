<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    'ap-singapore-1', // region
    'ocid1.user.oc1..aaaaaaaaollsndg5o55fxg5rw6cswldtrctynlr3dfc2mif73la3wvf6stmq', // user
    'ocid1.tenancy.oc1..aaaaaaaaa7b4g4lhnf5xtmfy6rngmvhvehjqo6lbmhew6pqfjd43risrdnjq', //tenancy
    '28:e3:9c:fa:cf:74:b9:87:f7:d9:d5:45:6e:b6:2d:22', //fingerprint
    "akekaphote@gmail.com-2026-11-06T12_26_12.239Z.pem", // key_file
    'spdR:AP-SINGAPORE-1-AD-1', //availabilityDomain
    'ocid1.subnet.oc1.ap-singapore-1.aaaaaaaan2sawysz3zrbhrmyjnxmyovdhu6eibwzqv57qkdatiays6krra3q', //subnet_ID
    'ocid1.image.oc1.ap-singapore-1.aaaaaaaaxvfsj3bvzatjfbtulypvtqjavwbdgmxdrz4tn3nc7xrsvcfejoka', //image_ID
    (int) getenv('OCI_OCPUS') ?: 4,
    (int) getenv('MEMORY_IN_GBS') ?: 24
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = 'VM.Standard.A1.Flex'; // or VM.Standard.E2.1.Micro

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC0lgKXZILHkB62sUAKVXNYN2u4JxEAreWMGYwSeeSLJZ8oB0gIQoNuXenSeyDxObkFR1urrbX+5lg+Ote6I5+XVrBgmkRHRfexakX8TPddkcooIEjBd8c17cJZ11zYG5fTvgQnfGM4z5ESnUmR4G9u5u+dKpQhzL1K3J+gYPNn7ujZTK6NVHtDxm5HnyUfE6t87PwHQvnKwoW6y/H89FFGZIhOAOpGMHL0+53rxpG4MdL3zG8RtVlnzj1a8iuSvVQY41pjTAYtTb+ymIf1SBw7hMVxy/0t1gHEZjtwLJ6C6ARhZneQOuDas70Xt9fql23CXFG/Z+RyvV3fHpTDSeN5 ssh-key-2026-11-06', $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";
//            if ($notifier->isSupported()) {
//                $notifier->notify($message);
//            }

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            // trying next availability domain
            sleep(16);
            continue;
        }

        // current config is broken
        return;
    }

    // success
    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}
