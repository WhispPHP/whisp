<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Laravel\Prompts\Concerns\Colors;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

$colors = new class
{
    use Colors;
};

$ipInfo = spin(
    message: 'Geolocating with my personal geostationary satellite...',
    callback: function () {
        usleep(400000);
        $clientIp = $_SERVER['WHISP_CLIENT_IP'] ?? getenv('WHISP_CLIENT_IP');

        if (empty($clientIp) || filter_var(
            $clientIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return [];
        }

        return json_decode(file_get_contents('https://ipinfo.io/'.$clientIp.'?token=e106d6f2bcb686'), true);
    }
);

if (empty($ipInfo)) {
    echo info('My personal pocket geostationary sattelite couldn\'t detect your location, so we can\'t calculate daylight.');
    echo 'Soz'.PHP_EOL;
    exit(1);
}

[$lat, $lon] = explode(',', $ipInfo['loc']);
$sunInfo = date_sun_info(time(), (float) $lat, (float) $lon);
$timezone = $ipInfo['timezone'];
$sunriseTime = new DateTime('@'.$sunInfo['sunrise'], new DateTimeZone($timezone));
$sunsetTime = new DateTime('@'.$sunInfo['sunset'], new DateTimeZone($timezone));

$text = 'My personal pocket geostationary satellite detected your location as: '.$ipInfo['city'].', '.$ipInfo['region'].' ('.$ipInfo['loc'].')';
$styled = $colors->bold($text);
$styled = $colors->gray($styled);
$styled = $colors->bgCyan($styled);
echo $styled.PHP_EOL.PHP_EOL;

echo info($colors->bold('Sunrise: ').$sunriseTime->format('H:i'));
echo info($colors->bold('Sunset: ').$sunsetTime->format('H:i'));
$daylightSeconds = $sunsetTime->getTimestamp() - $sunriseTime->getTimestamp();
$daylightHours = intdiv($daylightSeconds, 3600);
$daylightMinutes = round(($daylightSeconds % 3600) / 60);
echo info(sprintf('%s: %d hours and %d minutes (ish)', $colors->bold('Daylight'), $daylightHours, $daylightMinutes));

usleep(500000);
