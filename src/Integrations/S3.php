<?php
namespace VMForge\Integrations;

use VMForge\Core\Env;

class S3 {
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $ak;
    private string $sk;
    private bool $pathStyle;
    private bool $ssl;

    public function __construct() {
        $this->endpoint  = rtrim(Env::get('S3_ENDPOINT', ''), '/');
        $this->region    = Env::get('S3_REGION', 'us-east-1');
        $this->bucket    = Env::get('S3_BUCKET', '');
        $this->ak        = Env::get('S3_ACCESS_KEY', '');
        $this->sk        = Env::get('S3_SECRET_KEY', '');
        $this->pathStyle = Env::get('S3_USE_PATH_STYLE', '1') === '1';
        $this->ssl       = Env::get('S3_SSL', '1') === '1';
        if ($this->endpoint === '' || $this->bucket === '' || $this->ak === '' || $this->sk === '') {
            throw new \RuntimeException('S3 not configured');
        }
    }

    public function isConfigured(): bool {
        return $this->endpoint !== '' && $this->bucket !== '' && $this->ak !== '' && $this->sk !== '';
    }

    private function url(string $key): string {
        $scheme = $this->ssl ? 'https' : 'http';
        if ($this->pathStyle) {
            return sprintf('%s://%s/%s/%s', $scheme, $this->endpoint, rawurlencode($this->bucket), $this->escapeKey($key));
        }
        return sprintf('%s://%s.%s/%s', $scheme, $this->bucket, $this->endpoint, $this->escapeKey($key));
    }

    private function escapeKey(string $key): string {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    public function putObject(string $key, string $filePath, string $contentType = 'application/octet-stream'): array {
        $body = fopen($filePath, 'rb');
        if (!$body) { throw new \RuntimeException('cannot open file for read'); }
        $length = filesize($filePath);
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $url  = $this->url($key);
        $host = $this->pathStyle ? $this->endpoint : ($this->bucket . '.' . $this->endpoint);
        $payloadHash = hash_file('sha256', $filePath);

        $canonicalURI = '/' . ($this->pathStyle ? rawurlencode($this->bucket) . '/' : '') . $this->escapeKey($key);
        $canonicalQuery = '';
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
            'content-type' => $contentType,
            'content-length' => (string)$length,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeadersArr = [];
        foreach ($headers as $k=>$v) { $canonicalHeaders .= strtolower($k).':'.$v."\n"; $signedHeadersArr[] = strtolower($k); }
        $signedHeaders = implode(';', $signedHeadersArr);
        $canonicalRequest = "PUT\n{$canonicalURI}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "$date/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->hmac('AWS4' . $this->sk, $date);
        $signingKey = $this->hmac($signingKey, $this->region);
        $signingKey = $this->hmac($signingKey, 's3');
        $signingKey = $this->hmac($signingKey, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $auth = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->ak, $credentialScope, $signedHeaders, $signature);

        $headersOut = [
            'Authorization: ' . $auth,
            'x-amz-date: ' . $now,
            'x-amz-content-sha256: ' . $payloadHash,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $length,
            'Host: ' . $host,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $body,
            CURLOPT_INFILESIZE => $length,
            CURLOPT_HTTPHEADER => $headersOut,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($body);
        if ($code < 200 or $code >= 300) {
            throw new \RuntimeException('S3 putObject failed: HTTP ' . $code . ' ' . $err . ' ' . (string)$resp);
        }
        return ['code'=>$code, 'body'=>$resp];
    }

    public function deleteObject(string $key): void {
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $url  = $this->url($key);
        $host = $this->pathStyle ? $this->endpoint : ($this->bucket . '.' . $this->endpoint);
        $payloadHash = hash('sha256', '');

        $canonicalURI = '/' . ($this->pathStyle ? rawurlencode($this->bucket) . '/' : '') . $this->escapeKey($key);
        $canonicalQuery = '';
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeadersArr = [];
        foreach ($headers as $k=>$v) { $canonicalHeaders .= strtolower($k).':'.$v+"\n"; $signedHeadersArr[] = strtolower($k); }
        $signedHeaders = implode(';', $signedHeadersArr);
        $canonicalRequest = "DELETE\n{$canonicalURI}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "$date/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->hmac('AWS4' . $this->sk, $date);
        $signingKey = $this->hmac($signingKey, $this->region);
        $signingKey = $this->hmac($signingKey, 's3');
        $signingKey = $this->hmac($signingKey, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $auth = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->ak, $credentialScope, $signedHeaders, $signature);

        $headersOut = [
            'Authorization: ' . $auth,
            'x-amz-date: ' . $now,
            'x-amz-content-sha256: ' . $payloadHash,
            'Host: ' . $host,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headersOut,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code < 200 or $code >= 300) {
            throw new \RuntimeException('S3 deleteObject failed: HTTP ' . $code . ' ' . $err . ' ' . (string)$resp);
        }
    }

    private function hmac(string $key, string $data): string { return hash_hmac('sha256', $data, $key, true); }
}
