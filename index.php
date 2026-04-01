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
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
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
     * Configurado para Telegram por defecto si las keys están en el .env
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');
$maxRunningInstancesOfThatShape = (getenv('OCI_MAX_INSTANCES') !== false) ? (int) getenv('OCI_MAX_INSTANCES') : 1;

/**
 * LÓGICA DE REINTENTOS PARA GITHUB ACTIONS
 * Esto multiplica las posibilidades de "cazar" una instancia liberada.
 */
$maxAttemptsPerExecution = 10; 
$sleepBetweenAttempts = 15;    
$currentAttempt = 0;

while ($currentAttempt < $maxAttemptsPerExecution) {
    $currentAttempt++;
    echo "\n[" . date('H:i:s') . "] --- Iniciando Intento $currentAttempt de $maxAttemptsPerExecution ---\n";

    try {
        $instances = $api->getInstances($config);
        $existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);

        if ($existingInstances) {
            echo "Aviso: $existingInstances\n";
            return; // Ya tienes la instancia, no hace falta seguir.
        }

        if (!empty($config->availabilityDomains)) {
            $availabilityDomains = is_array($config->availabilityDomains) ? $config->availabilityDomains : [ $config->availabilityDomains ];
        } else {
            $availabilityDomains = $api->getAvailabilityDomains($config);
        }

        foreach ($availabilityDomains as $availabilityDomainEntity) {
            $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
            
            try {
                $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
                
                // --- CASO DE ÉXITO ---
                $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
                echo "¡INSTANCIA CREADA CON ÉXITO!\n$message\n";
                
                if ($notifier->isSupported()) {
                    $notifier->notify("OCI ARM Success: \n" . $message);
                }
                return; // Finalizar ejecución completa por éxito.

            } catch(ApiCallException $e) {
                $message = $e->getMessage();
                
                // Verificamos si el error es falta de capacidad
                $isOutOfCapacity = (
                    $e->getCode() === 500 &&
                    strpos($message, 'InternalError') !== false &&
                    strpos($message, 'Out of host capacity') !== false
                );

                if ($isOutOfCapacity) {
                    echo "Resultado: Sin capacidad en $availabilityDomain.\n";
                } else {
                    // Si es un error distinto (401 Auth, 404 Not Found, etc), paramos para revisar configuración
                    echo "Error Crítico de API: $message\n";
                    return; 
                }
            }
        }

    } catch (\Exception $generalException) {
        echo "Error general en la ejecución: " . $generalException->getMessage() . "\n";
        return;
    }

    // Si no ha tenido éxito y quedan intentos, esperamos
    if ($currentAttempt < $maxAttemptsPerExecution) {
        echo "Esperando {$sleepBetweenAttempts}s para el próximo reintento...\n";
        sleep($sleepBetweenAttempts);
    }
}

echo "\nTerminada la tanda de intentos actual. GitHub Actions se cerrará hasta la próxima programación cron.\n";
