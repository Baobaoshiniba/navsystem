<?php
/**
 * 供 HTTP 端到端测试使用的最小客户端（不是测试，由 tests/http_serve.php 引入）。
 *
 * 用裸 socket 手写 HTTP/1.0 而不是 curl 扩展：后者在不同环境里未必装了，
 * 而且默认会跟随重定向、自动管理 Cookie —— 恰恰是我们要自己控制的两个行为。
 *
 * Cookie 这里刻意模拟浏览器的真实取舍：明文 http 下不会保存带 Secure 的 Cookie。
 * 少了这条，服务端一旦把 Secure 写死，本地开发将永远登不进后台，
 * 而测试因为「无条件回传 Cookie」照样全绿。
 */
declare(strict_types=1);

final class TestClient
{
    private string $port;

    /** @var array<string,string> name => value */
    private array $cookies = [];

    public function __construct(string $port)
    {
        $this->port = $port;
    }

    /**
     * @param array<string,string> $form 非空则按 application/x-www-form-urlencoded 提交
     * @return array{status:int, headers:array<string,string>, setCookies:array<int,string>, body:string}
     */
    public function request(string $method, string $path, array $form = [], array $extraHeaders = []): array
    {
        $body = $form === [] ? '' : http_build_query($form);

        $headers = ["Host: 127.0.0.1:{$this->port}", 'User-Agent: nav-http-test', 'Connection: close'];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($body !== '') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $fp = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 10);
        if ($fp === false) {
            throw new RuntimeException('Connect failed: ' . $errstr);
        }
        stream_set_timeout($fp, 30);
        fwrite($fp, $method . ' ' . $path . " HTTP/1.0\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
        $raw = (string)stream_get_contents($fp);
        fclose($fp);

        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            throw new RuntimeException('Malformed HTTP response for ' . $method . ' ' . $path);
        }
        $head      = substr($raw, 0, $split);
        $respBody  = substr($raw, $split + 4);
        $lines     = explode("\r\n", $head);

        if (preg_match('#^HTTP/\S+ (\d{3})#', $lines[0], $m) !== 1) {
            throw new RuntimeException('No status line for ' . $method . ' ' . $path);
        }

        $parsed     = [];
        $setCookies = [];
        for ($i = 1; $i < count($lines); $i++) {
            if (strpos($lines[$i], ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $lines[$i], 2);
            $key = strtolower(trim($k));
            $val = trim($v);
            if ($key === 'set-cookie') {
                $setCookies[] = $val;
                continue;
            }
            $parsed[$key] = $val;
        }

        foreach ($setCookies as $cookie) {
            $this->absorbCookie($cookie);
        }

        return [
            'status'     => (int)$m[1],
            'headers'    => $parsed,
            'setCookies' => $setCookies,
            'body'       => $respBody,
        ];
    }

    public function get(string $path, array $extraHeaders = []): array
    {
        return $this->request('GET', $path, [], $extraHeaders);
    }

    public function post(string $path, array $form, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $form, $extraHeaders);
    }

    /** 浏览器不会在明文 http 里保存 Secure Cookie，这里照做；过期的一律丢弃 */
    private function absorbCookie(string $cookie): void
    {
        $parts = explode(';', $cookie);
        $pair  = trim(array_shift($parts));
        if ($pair === '' || strpos($pair, '=') === false) {
            return;
        }
        [$name, $value] = explode('=', $pair, 2);
        $name  = trim($name);
        $value = trim($value);

        $flags = [];
        $expires = null;
        foreach ($parts as $attr) {
            $attr = trim($attr);
            if ($attr === '') {
                continue;
            }
            if (strpos($attr, '=') !== false) {
                [$ak, $av] = explode('=', $attr, 2);
                $flags[strtolower(trim($ak))] = trim($av);
            } else {
                $flags[strtolower($attr)] = '';
            }
        }

        if (array_key_exists('secure', $flags)) {
            return;                       // 明文 http：浏览器根本不会存
        }
        if (isset($flags['expires'])) {
            $ts = strtotime($flags['expires']);
            if ($ts !== false && $ts < time()) {
                unset($this->cookies[$name]);   // 服务端要求删除
                return;
            }
        }
        if ($value === '' && isset($flags['expires'])) {
            unset($this->cookies[$name]);
            return;
        }

        $this->cookies[$name] = $value;
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }
}
