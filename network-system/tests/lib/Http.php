<?php
declare(strict_types=1);

namespace Tests;

/**
 * متصفح بسيط للاختبارات: ملفات تعريف الارتباط، رمز CSRF، ونماذج الدخول.
 */
final class Http
{
    private string $jar;
    public string $csrf = '';
    public array $headers = [];

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'mnxjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string, headers:array, url:string, json:?array, location:string} */
    public function request(string $method, string $path, array|string|null $data = null, array $headers = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $ch = curl_init($url);
        $hdrs = [];
        foreach ($this->headers + $headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        ];
        if ($data !== null) {
            if (is_array($data) && ($headers['Content-Type'] ?? '') === 'application/json') {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            } else {
                $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hsize);
        $body = substr($raw, $hsize);
        $h = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = strtolower(trim($k));
                $h[$k] = isset($h[$k]) ? $h[$k] . "\n" . trim($v) : trim($v);
            }
        }
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]{64})"/', $body, $m) || preg_match('/name="_token" value="([a-f0-9]{64})"/', $body, $m)) {
            $this->csrf = $m[1];
        }
        $json = null;
        if (str_contains($h['content-type'] ?? '', 'json')) {
            $json = json_decode($body, true);
        }
        return ['status' => $status, 'body' => $body, 'headers' => $h, 'url' => $url, 'json' => is_array($json) ? $json : null, 'location' => $h['location'] ?? ''];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** POST نموذج مع رمز CSRF الحالي */
    public function post(string $path, array $data = [], array $headers = []): array
    {
        if (!isset($data['_token']) && $this->csrf !== '') {
            $data['_token'] = $this->csrf;
        }
        return $this->request('POST', $path, $data, $headers + ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /** طلب AJAX بـ JSON كما يرسله app.js */
    public function ajax(string $method, string $path, array $data = []): array
    {
        $h = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        if ($method !== 'GET') {
            $h['X-CSRF-Token'] = $this->csrf;
            $h['Content-Type'] = 'application/json';
            return $this->request($method, $path, $data, $h);
        }
        return $this->request('GET', $path, null, $h);
    }

    /** الدخول بنموذج صفحة الدخول (نظام الشبكة أو لوحة المسؤول) */
    public function login(string $loginPath, string $user, string $pass): array
    {
        $this->get($loginPath);
        $r = $this->post($loginPath, ['username' => $user, 'password' => $pass]);
        if ($r['status'] === 302 && $r['location'] !== '') {
            $this->get($r['location']);
        }
        return $r;
    }
}
