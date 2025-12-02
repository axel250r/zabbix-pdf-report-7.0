<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['zbx_auth_ok'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Sesión inválida']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApi.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $api = new ZabbixApi(ZABBIX_API_URL, ZABBIX_API_USER, ZABBIX_API_PASS, 30, false);
    $groups = $api->call('hostgroup.get', [
        'output'    => ['groupid','name'],
        'sortfield' => 'name'
    ]);
    echo json_encode(is_array($groups) ? $groups : []);
} catch (Throwable $e) {
    error_log("Zabbix PDF Report - API Error en get_host_groups.php: " . $e->getMessage());
    echo json_encode([]);
}
