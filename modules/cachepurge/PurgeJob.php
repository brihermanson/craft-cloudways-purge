<?php
namespace modules\cachepurge;

use Craft;
use craft\helpers\App;
use craft\queue\BaseJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Calls the Cloudways API to clear the app cache, and optionally the
 * server-wide Varnish cache. Failures surface in Utilities > Queue Manager
 * with the API's own response text.
 */
class PurgeJob extends BaseJob
{
    public function execute($queue): void
    {
        $base = rtrim(App::env('CLOUDWAYS_API_BASE') ?: 'https://api.cloudways.com/api/v2', '/') . '/';

        $client = Craft::createGuzzleClient([
            'base_uri' => $base,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . App::env('CLOUDWAYS_ACCESS_TOKEN'),
                'Accept' => 'application/json',
            ],
        ]);

        $serverId = App::env('CLOUDWAYS_SERVER_ID');
        $appId = App::env('CLOUDWAYS_APP_ID');

        // App-level cache clear (this app only)
        $this->post($client, 'app/cache/purge', [
            'server_id' => $serverId,
            'app_id' => $appId,
        ]);

        // Optional server-wide Varnish purge (every app on the server)
        if (filter_var(App::env('CLOUDWAYS_PURGE_SERVER_VARNISH'), FILTER_VALIDATE_BOOLEAN)) {
            $this->post($client, 'service/varnish', [
                'server_id' => $serverId,
                'action' => 'purge',
            ]);
        }
    }

    private function post(Client $client, string $path, array $params): void
    {
        try {
            $res = $client->post($path, ['form_params' => $params]);
            Craft::info("Cloudways {$path}: {$res->getStatusCode()} " . $res->getBody(), 'cachepurge');
        } catch (RequestException $e) {
            $res = $e->getResponse();
            $detail = $res
                ? $res->getStatusCode() . ' ' . substr((string)$res->getBody(), 0, 500)
                : $e->getMessage();
            throw new \RuntimeException("Cloudways {$path} failed: {$detail}");
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Purging Cloudways cache';
    }
}
