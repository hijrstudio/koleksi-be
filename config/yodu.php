<?php

/*
| YODU Open API — QRIS Acquirer MPM (pembayaran iuran member).
| Nilai diambil dari portal OACP:
|   Configuration → Access API           : Client ID, Client Secret Key (isi Public Key & Callback URL di sana)
|   Profile → Company Profile → API Info : Merchant ID, Channel ID, Store ID, Terminal ID
|
| Private key RSA disimpan di luar repo (storage/app/private/keys, ter-gitignore) — JANGAN di-commit.
*/
return [
    // 'yodu' = sungguhan (sandbox/production) | 'fake' = simulasi lokal tanpa YODU (ditolak di production)
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    // Sandbox: https://sandbox-yodupay-api.yodu.id   Production: https://yodupay-api.yodu.id
    'base_url' => env('YODU_BASE_URL', 'https://sandbox-yodupay-api.yodu.id'),
    'token_path' => env('YODU_TOKEN_PATH', '/openapi/v1.0/access/token'),

    'client_id' => env('YODU_CLIENT_ID'),
    'client_secret' => env('YODU_CLIENT_SECRET'),
    'private_key_path' => env('YODU_PRIVATE_KEY_PATH', 'storage/app/private/keys/yodu-private.pem'),

    'channel_id' => env('YODU_CHANNEL_ID'),
    'merchant_id' => env('YODU_MERCHANT_ID'),
    'store_id' => env('YODU_STORE_ID'),
    'terminal_id' => env('YODU_TERMINAL_ID'),

    'qr_expiry_seconds' => (int) env('YODU_QR_EXPIRY', 900),           // default YODU 300 detik
    'signature_encoding' => env('YODU_SIGNATURE_ENCODING', 'base64'),   // 'hex' kalau sandbox menolak signature
    // Kosong = semua IP diterima, tapi SUCCESS dari callback tetap dikonfirmasi ke API cek status.
    'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('YODU_CALLBACK_IPS', ''))))),
    // Kirim client secret di header X-CLIENT-KEY pada request transaksi — hanya kalau YODU memintanya.
    'transaction_client_key_header' => (bool) env('YODU_TX_CLIENT_KEY_HEADER', false),
    'origin' => env('YODU_ORIGIN'),
    'timeout' => (int) env('YODU_TIMEOUT', 30),
];
