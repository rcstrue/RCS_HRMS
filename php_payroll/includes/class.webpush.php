<?php
/**
 * RCS HRMS — Web Push Notification Sender (Pure PHP)
 * 
 * Implements VAPID auth (RFC 8292) and payload encryption (RFC 8291 aes128gcm)
 * using only openssl + hash extensions. No composer dependencies.
 * Requires PHP 8.0+ (for openssl_pkey_derive).
 */

class WebPush
{
    private string $privateKey;
    private string $publicKey;
    private string $subject;
    private int $ttl;

    public function __construct(
        string $vapidPrivateKey,
        string $vapidPublicKey,
        string $vapidSubject,
        int    $ttl = 2419200
    ) {
        $this->privateKey = self::base64UrlDecode($vapidPrivateKey);
        $this->publicKey  = self::base64UrlDecode($vapidPublicKey);
        $this->subject    = $vapidSubject;
        $this->ttl        = $ttl;
    }

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Send a push notification to one subscription.
     */
    public function send(array $subscription, string $title, string $body, string $url = '/', ?string $icon = null): array
    {
        $endpoint  = $subscription['endpoint'] ?? '';
        $p256dhKey = $subscription['p256dh_key'] ?? '';
        $authKey   = $subscription['auth_key'] ?? '';

        if (empty($endpoint)) {
            return ['success' => false, 'error' => 'Missing endpoint'];
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
            'icon'  => $icon ?? '/logo.png',
        ]);

        $encrypted = null;
        $headers   = ['TTL: ' . $this->ttl];

        if (!empty($p256dhKey) && !empty($authKey)) {
            try {
                $encrypted = $this->encryptPayload(
                    $payload,
                    self::base64UrlDecode($p256dhKey),
                    self::base64UrlDecode($authKey)
                );
                $headers[] = 'Content-Encoding: aes128gcm';
                $headers[] = 'Content-Type: application/octet-stream';
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Encryption failed: ' . $e->getMessage()];
            }
        }

        $vapidHeader = $this->createVapidHeader($endpoint);
        $headers[] = 'Authorization: ' . $vapidHeader;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($encrypted !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encrypted);
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        if ($httpCode === 201 || $httpCode === 200) {
            return ['success' => true];
        }

        $error = "HTTP $httpCode";
        if ($response) {
            $decoded = json_decode($response, true);
            if ($decoded && isset($decoded['error'])) {
                $error .= ': ' . $decoded['error'];
            }
        }
        $expired = in_array($httpCode, [404, 410]);
        return ['success' => false, 'error' => $error, 'expired' => $expired];
    }

    /**
     * Send to multiple subscriptions. Returns stats.
     */
    public function sendBatch(array $subscriptions, string $title, string $body, string $url = '/', ?string $icon = null): array
    {
        $sent = $failed = $expired = 0;
        $errors = [];
        foreach ($subscriptions as $sub) {
            $result = $this->send($sub, $title, $body, $url, $icon);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                if (!empty($result['expired'])) $expired++;
                $errors[] = $result['error'] ?? 'Unknown';
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'expired' => $expired, 'errors' => $errors];
    }

    // ── VAPID JWT (RFC 8292) ────────────────────────────────────────

    private function createVapidHeader(string $endpoint): string
    {
        $origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $header  = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::base64UrlEncode(json_encode([
            'aud' => $origin,
            'exp' => time() + 43200,
            'sub' => $this->subject,
        ]));

        $signingInput = "$header.$payload";
        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $sig = self::base64UrlEncode($signature);
        $pubKey = self::base64UrlEncode($this->publicKey);

        return "vapid t=$header.$payload.$sig, k=$pubKey";
    }

    // ── Payload Encryption (RFC 8291 — aes128gcm) ───────────────────

    private function encryptPayload(string $plaintext, string $userPublicKey, string $userAuth): string
    {
        // 1. Generate ephemeral ECDH key pair
        $ephemeral = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $ephemeralDetails = openssl_pkey_get_details($ephemeral);
        $localPublicKeyRaw = $ephemeralDetails['key']; // 65 bytes uncompressed (0x04...)

        // 2. ECDH: compute shared secret
        $userPubKeyPem = $this->rawEcKeyToPem($userPublicKey);
        $userPubObj = openssl_pkey_get_public($userPubKeyPem);
        if (!$userPubObj) {
            throw new \RuntimeException('Failed to parse user P-256 public key');
        }

        // openssl_pkey_derive returns raw shared secret (x-coordinate, 32 bytes)
        if (PHP_MAJOR_VERSION < 8 || !function_exists('openssl_pkey_derive')) {
            throw new \RuntimeException('PHP 8.0+ required for Web Push');
        }
        $sharedSecret = openssl_pkey_derive($ephemeral, $userPubObj, 32);
        if ($sharedSecret === false) {
            throw new \RuntimeException('ECDH derivation failed: ' . openssl_error_string());
        }

        // 3. Build info parameter
        $info = "WebPush: info" . "\x00"
            . $this->uint16Bytes(strlen($userPublicKey)) . $userPublicKey
            . $this->uint16Bytes(strlen($localPublicKeyRaw)) . $localPublicKeyRaw;

        // 4. HKDF-Extract: PRK = HMAC-SHA256(salt, IKM)
        $salt = str_pad(substr($userAuth, -16), 16, "\x00", STR_PAD_LEFT); // pad/truncate auth to 16 bytes
        $prk = hash_hmac('sha256', $sharedSecret, $salt, true);

        // 5. HKDF-Expand: derive content encryption key (16 bytes) and nonce (12 bytes)
        $cek   = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

        // 6. AES-128-GCM encrypt
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-128-GCM encryption failed: ' . openssl_error_string());
        }

        // 7. Build result: rs(2 bytes) || ephemeral_pub(65) || padding(N) || ciphertext || tag(16)
        $padLen = random_int(0, 3200);
        $pad = str_repeat("\x00", $padLen);
        $recordSize = pack('n', 0); // placeholder, not used in aes128gcm

        return pack('n', $padLen) . $localPublicKeyRaw . $pad . $ciphertext . $tag;
    }

    /**
     * HKDF-Expand (RFC 5869)
     */
    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $hashLen = 32; // SHA-256
        $n = (int)ceil($length / $hashLen);
        $okm = '';
        $t = '';
        for ($i = 1; $i <= $n; $i++) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }
        return substr($okm, 0, $length);
    }

    // ── Key helpers ─────────────────────────────────────────────────

    private function rawEcKeyToPem(string $raw65): string
    {
        // Ensure it's a valid uncompressed P-256 point (65 bytes starting with 0x04)
        if (strlen($raw65) === 87 && $raw65[0] === "\x00") {
            $raw65 = substr($raw65, 1);
        }
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw65;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
    }

    private function uint16Bytes(int $len): string
    {
        return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    // ── Base64url encoding ──────────────────────────────────────────

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) $data .= str_repeat('=', 4 - $remainder);
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // ── VAPID Key Generation ─────────────────────────────────────────

    /**
     * Generate a VAPID key pair.
     * Returns ['public_key' => base64url string, 'private_key' => base64url string].
     */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$key) throw new \RuntimeException('Key generation failed: ' . openssl_error_string());

        $details = openssl_pkey_get_details($key);
        $pubRaw = $details['key']; // 65 bytes

        // Extract raw private key bytes
        openssl_pkey_export($key, $pem);
        $privRes = openssl_pkey_get_private($pem);
        $privDetails = openssl_pkey_get_details($privRes);
        $privHex = str_pad($privDetails['ec']['d'], 64, '0', STR_PAD_LEFT);
        $privRaw = hex2bin($privHex);

        return [
            'public_key'  => self::base64UrlEncode($pubRaw),
            'private_key' => self::base64UrlEncode($privRaw),
        ];
    }
}
