<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppService
{
    public function sendOtp(string $phone, int $otp): void
    {
        Log::info('WhatsBots sendOtp entered');

        try {
            foreach (['url', 'token', 'from'] as $key) {
                if (
                    ! is_string(config("services.whatsapp.{$key}")) ||
                    blank(config("services.whatsapp.{$key}"))
                ) {
                    Log::error('WhatsBots configuration missing', [
                        'setting' => "services.whatsapp.{$key}",
                    ]);

                    throw new WhatsAppDeliveryException(
                        'WhatsBots is not configured.'
                    );
                }
            }

            $to = ltrim(PhoneNumber::normalize($phone), '+');
            $from = ltrim(
                PhoneNumber::normalize(config('services.whatsapp.from')),
                '+'
            );

            $token = config('services.whatsapp.token');

            Log::info('WhatsBots request', [
                'host' => parse_url(
                    config('services.whatsapp.url'),
                    PHP_URL_HOST
                ),
                'method' => 'POST',
                'from' => '***'.substr($from, -4),
                'to' => '***'.substr($to, -4),
            ]);

            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->withoutRedirecting()
                ->post(config('services.whatsapp.url'), [
                    'messageType' => 'text',
                    'requestType' => 'POST',
                    'token' => $token,
                    'from' => $from,
                    'to' => $to,
                    'text' => "Your SkillMatch verification code is {$otp}",
                    'enableLog' => false,
                ]);

            Log::info('WhatsBots response', [
                'status' => $response->status(),
                'body_bytes' => strlen($response->body()),
                'body' => $this->sanitizeResponse(
                    $response->json() ?? $response->body(),
                    [$token, $from, $to, (string) $otp]
                ),
            ]);

            if (! $response->successful()) {
                throw new WhatsAppDeliveryException(
                    'WhatsBots returned HTTP '.$response->status()
                );
            }

            if ($response->json('success') !== true) {
                throw new WhatsAppDeliveryException('WhatsBots did not confirm message acceptance.');
            }

        } catch (Throwable $exception) {
            Log::error('WhatsBots sendOtp failed', [
                'exception' => $exception::class,
                'reason' => $exception instanceof WhatsAppDeliveryException ? $exception->getMessage() : 'Transport or request failure.',
            ]);

            throw new WhatsAppDeliveryException(
                'Unable to send the WhatsApp verification code.'
            );
        }
    }

    /**
     * @param  list<string>  $secrets
     */
    private function sanitizeResponse(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_match('/token|password|secret|authorization|text|otp|phone|^(from|to|code)$/i', (string) $key)
                    ? '[redacted]'
                    : $this->sanitizeResponse($item, $secrets);
            }

            return $value;
        }

        if (is_string($value)) {
            $value = str_replace([...$secrets, ...array_map('rawurlencode', $secrets), ...array_map('urlencode', $secrets)], '[redacted]', $value);

            return mb_substr(preg_replace('/\+?[0-9][0-9\s()\-]{5,}[0-9]/', '[redacted]', $value), 0, 2000);
        }

        return is_numeric($value) && in_array((string) $value, $secrets, true) ? '[redacted]' : $value;
    }
}
