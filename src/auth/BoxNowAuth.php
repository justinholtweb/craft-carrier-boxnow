<?php

namespace justinholtweb\carrierboxnow\auth;

use Craft;
use justinholtweb\carrier\auth\BaseAuth;

/**
 * BOX NOW's client-credentials grant, which is not quite OAuth.
 *
 * The token endpoint is `POST /api/v1/auth-sessions` and its documented body is **JSON**
 * (`{"grant_type":"client_credentials","client_id":…,"client_secret":…}`), where the shared
 * `OAuth2ClientCredentials` strategy posts a form. Whether BOX NOW also accepts a form is
 * UNVERIFIED, so this sends exactly what the manual shows. The token lives an hour; it is cached
 * a few minutes short of that so a long locker sync or bulk run never dies on an expired one.
 */
class BoxNowAuth extends BaseAuth
{
    private const EXPIRY_SLACK = 300;

    /**
     * @param \Closure $tokenUrl returns the absolute token URL — the host is a per-country setting
     */
    public function __construct(private \Closure $tokenUrl)
    {
    }

    public function headers(): array
    {
        $token = $this->accessToken();

        return $token !== null ? ['Authorization' => 'Bearer ' . $token] : [];
    }

    public function isConfigured(): bool
    {
        return (string)$this->setting('clientId') !== '' && (string)$this->setting('clientSecret') !== '';
    }

    public function reauthenticate(): bool
    {
        $this->cacheForget($this->cacheSuffix());

        return $this->accessToken() !== null;
    }

    public function accessToken(): ?string
    {
        $cached = $this->cacheGet($this->cacheSuffix());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->http()->post(($this->tokenUrl)(), [
            'grant_type' => 'client_credentials',
            'client_id' => (string)$this->setting('clientId'),
            'client_secret' => (string)$this->setting('clientSecret'),
        ], ['headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']]);

        if (!$response->ok()) {
            Craft::warning('Carrier could not get a BOX NOW access token: ' . $response->errorMessage(), 'carrier');

            return null;
        }

        $token = (string)$response->at('access_token', '');

        if ($token === '') {
            return null;
        }

        $this->cacheSet($this->cacheSuffix(), $token, max(30, (int)$response->at('expires_in', 3600) - self::EXPIRY_SLACK));

        return $token;
    }

    /**
     * The host and client are part of the key: a merchant who switches country or environment
     * must never send a token minted by another BOX NOW host.
     */
    private function cacheSuffix(): string
    {
        return 'access:' . md5(($this->tokenUrl)() . '|' . $this->setting('clientId'));
    }
}
