<?php

/*
| YODU Open API — QRIS Acquirer MPM (pembayaran iuran member).
| Nilai diambil dari portal OACP:
|   Configuration → Access API           : Client ID, Client Secret Key (isi Public Key & Callback URL di sana)
|   Profile → Company Profile → API Info : Merchant ID, Channel ID, Store ID, Terminal ID
|
| Private key RSA disimpan di luar repo — JANGAN di-commit:
|   - Server (Laravel Cloud, filesystem tidak permanen): YODU_PRIVATE_KEY = isi PEM di-base64
|     (generate: `base64 -w0 yodu-private.pem`). Kalau diisi, YODU_PRIVATE_KEY_PATH diabaikan.
|   - Lokal: file di storage/app/private/keys (ter-gitignore) lewat YODU_PRIVATE_KEY_PATH.
*/
return [
    // 'yodu' = sungguhan (sandbox/production) | 'fake' = simulasi lokal tanpa YODU (ditolak di production)
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    // Sandbox: https://sandbox-yodupay-api.yodu.id   Production: https://yodupay-api.yodu.id
    'base_url' => env('YODU_BASE_URL', 'https://sandbox-yodupay-api.yodu.id'),
    'token_path' => env('YODU_TOKEN_PATH', '/openapi/v1.0/access/token'),

    'client_id' => env('YODU_CLIENT_ID'),
    'client_secret' => env('YODU_CLIENT_SECRET'),
    'private_key' => env('YODU_PRIVATE_KEY'),
    'private_key_path' => env('YODU_PRIVATE_KEY_PATH', 'storage/app/private/keys/yodu-private.pem'),

    'channel_id' => env('YODU_CHANNEL_ID'),
    'merchant_id' => env('YODU_MERCHANT_ID'),
    'store_id' => env('YODU_STORE_ID'),
    'terminal_id' => env('YODU_TERMINAL_ID'),

    'qr_expiry_seconds' => (int) env('YODU_QR_EXPIRY', 300),           // = default YODU (Spec MPM: 300 detik)
    'signature_encoding' => env('YODU_SIGNATURE_ENCODING', 'base64'),   // 'hex' kalau sandbox menolak signature
    // Kosong = semua IP diterima, tapi SUCCESS dari callback tetap dikonfirmasi ke API cek status.
    'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('YODU_CALLBACK_IPS', ''))))),
    // Client secret di header X-CLIENT-KEY pada request transaksi. Wajib menurut Spec Doc YODU Security API
    // (tanpa header ini generate QR ditolak 4008001); bisa dimatikan untuk gateway SNAP lain yang tidak memintanya.
    'transaction_client_key_header' => filter_var(env('YODU_TX_CLIENT_KEY_HEADER', true), FILTER_VALIDATE_BOOL),
    'origin' => env('YODU_ORIGIN'),
    'timeout' => (int) env('YODU_TIMEOUT', 30),
];
