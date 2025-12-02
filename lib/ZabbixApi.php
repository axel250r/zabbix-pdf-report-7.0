<?php
// lib/ZabbixApi.php — Wrapper JSON-RPC para Zabbix 6/7 (PHP 7.2–8.x, UTF-8)

class ZabbixApi {
    private $url;
    private $username;
    private $password;
    private $auth = null;
    private $timeout = 30;
    private $verifySsl = false;
    private $extraHeaders = [];

    /**
     * @param string $url  Base del front (ej: http://X.Y.Z.W/zabbix) o API (termina igual funcionando)
     * @param string $username
     * @param string $password
     * @param int    $timeout
     * @param bool   $verifySsl
     * @param array  $extraHeaders
     */
    public function __construct(
        string $url,
        string $username,
        string $password,
        int $timeout = 30,
        bool $verifySsl = false,
        array $extraHeaders = []
    ) {
        $this->url          = rtrim($url, '/');
        $this->username     = $username;
        $this->password     = $password;
        $this->timeout      = $timeout;
        $this->verifySsl    = $verifySsl;
        $this->extraHeaders = $extraHeaders;
    }

    public function setAuth(?string $auth): void { $this->auth = $auth; }
    public function getAuth(): ?string { return $this->auth; }

    /** Login compatible con Zabbix 6/7 (usa 'username', NO 'user'). */
    public function login(): string {
        $res = $this->rawCall('user.login', [
            'username' => $this->username,
            'password' => $this->password,
        ], false);
        if (!is_string($res) || $res === '') {
            throw new \RuntimeException('Fallo login: token vacío');
        }
        $this->auth = $res;
        return $this->auth;
    }

    /** Llamada de alto nivel que asegura login previo. */
    public function call(string $method, array $params = []) {
        if ($method !== 'user.login' && empty($this->auth)) {
            $this->login();
        }
        return $this->rawCall($method, $params, $method !== 'user.login');
    }

    /** POST JSON-RPC: solo jsonrpc/method/params/id/auth; sin campos raíz extra. */
    private function rawCall(string $method, array $params, bool $withAuth) {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => (int)(microtime(true) * 1000) % 2147483647,
        ];
        if ($withAuth && $this->auth) {
            $payload['auth'] = $this->auth;
        }

        // Acepta tanto URL base del front como de la API
        $apiUrl = (stripos($this->url, 'api_jsonrpc.php') !== false)
            ? $this->url
            : ($this->url . '/api_jsonrpc.php');

        $ch = curl_init($apiUrl);
        $headers = array_merge(['Content-Type: application/json-rpc'], $this->extraHeaders);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP error: $err");
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP status $code: $resp");
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Respuesta no JSON válida: $resp");
        }
        if (isset($json['error'])) {
            throw new \RuntimeException("API error: " . json_encode($json['error'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return $json['result'] ?? null;
    }

    // ---------- Helpers opcionales y usados por tu generate.php ----------

    /** Devuelve mapa nombre->hostid para nombres dados. */
    public function hostMapByNames(array $names): array {
        if (empty($names)) return [];
        $res = $this->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'filter' => ['host' => $names],
        ]);
        $map = [];
        foreach ((array)$res as $h) {
            if (!empty($h['hostid'])) {
                $key = !empty($h['host']) ? $h['host'] : (!empty($h['name']) ? $h['name'] : null);
                if ($key) $map[$key] = (string)$h['hostid'];
            }
        }
        return $map;
    }

    /** Devuelve hostids pertenecientes a ciertos groupids. */
    public function hostIdsByGroupIds(array $groupids): array {
        if (empty($groupids)) return [];
        $res = $this->call('host.get', [
            'groupids' => $groupids,
            'output'   => ['hostid'],
        ]);
        $ids = [];
        foreach ((array)$res as $h) {
            if (!empty($h['hostid'])) $ids[] = (string)$h['hostid'];
        }
        return array_values(array_unique($ids));
    }

    /** Info básica de hosts por IDs. */
    public function hostGetBasicByIds(array $hostids): array {
        if (empty($hostids)) return [];
        $res = $this->call('host.get', [
            'output'  => ['hostid', 'host', 'name'],
            'hostids' => $hostids
        ]);
        return is_array($res) ? $res : [];
    }
}
