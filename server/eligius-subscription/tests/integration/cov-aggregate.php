<?php
$dir = '/srv/cov/';
$merged = array();
foreach (glob($dir . '*.json') as $f) {
    $data = json_decode(file_get_contents($f), true);
    if (!is_array($data)) continue;
    foreach ($data as $file => $lines) {
        if (strpos($file, 'eligius-subscription') === false) continue;
        if (strpos($file, '/tests/') !== false) continue;
        foreach ($lines as $line => $info) {
            $line = (int) $line;
            $count = is_array($info) ? (int) $info['ct'] : (int) $info;
            $merged[$file][$line] = max($merged[$file][$line] ?? 0, $count);
        }
    }
}
$pluginRoot = '/srv/wp/wp-content/plugins/eligius-subscription/';
$critical = array(
    'license/class-license-manager.php'    => 'License Management',
    'license/class-license-issuer.php'     => 'License Issuance',
    'license/class-device-transfer.php'    => 'Device Transfer',
    'license/class-trial-manager.php'      => 'Trial Management',
    'payment/class-payment-processor.php'  => 'Payment Processing',
    'payment/gateways/class-zarinpal-adapter.php' => 'ZarinPal Adapter',
    'payment/class-gateway-factory.php'    => 'Gateway Factory',
    'payment/class-idempotency-repository.php' => 'Idempotency',
    'security/class-rate-limiter.php'      => 'Rate Limiter',
    'auth/class-device-auth.php'           => 'Device Auth (challenge)',
    'api/class-api-controller.php'         => 'API Controller (fail-closed)',
    'api/class-payment-controller.php'     => 'Payment Controller',
    'api/class-license-controller.php'     => 'License Controller',
    'api/class-device-controller.php'      => 'Device Controller',
);
printf('%-34s %7s %7s %7s' . PHP_EOL, 'critical class', 'cov', 'total', 'line%');
$aggC = 0; $aggT = 0;
foreach ($critical as $rel => $label) {
    $file = $pluginRoot . 'includes/' . $rel;
    $lines = $merged[$file] ?? array();
    $covered = 0; $total = 0;
    foreach ($lines as $count) { $total++; if ((int) $count > 0) $covered++; }
    $aggC += $covered; $aggT += $total;
    printf('%-34s %7d %7d %6.1f%%' . PHP_EOL, $label, $covered, $total, 100 * $covered / max(1, $total));
}
printf('%-34s %7d %7d %6.1f%%' . PHP_EOL, 'CRITICAL AGGREGATE', $aggC, $aggT, 100 * $aggC / max(1, $aggT));
$allC = 0; $allT = 0; $n = 0;
foreach ($merged as $file => $lines) {
    if (strpos($file, $pluginRoot . 'includes/') !== 0) continue;
    $n++;
    foreach ($lines as $count) { $allT++; if ((int) $count > 0) $allC++; }
}
printf('%-34s %7d %7d %6.1f%%  (%d files)' . PHP_EOL, 'PLUGIN includes/ TOTAL', $allC, $allT, 100 * $allC / max(1, $allT), $n);
