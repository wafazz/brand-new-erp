<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BillplzClient
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function configured(): bool
    {
        return is_string($this->config['api_key'] ?? null)
            && $this->config['api_key'] !== ''
            && is_string($this->config['collection_id'] ?? null)
            && $this->config['collection_id'] !== ''
            && is_string($this->config['x_signature_key'] ?? null)
            && $this->config['x_signature_key'] !== '';
    }

    public function base(): string
    {
        return ($this->config['sandbox'] ?? true)
            ? 'https://www.billplz-sandbox.com/api/v3'
            : 'https://www.billplz.com/api/v3';
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array{id: string, url: string}
     */
    public function createBill(array $bill): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Billplz is not configured. Set BILLPLZ_API_KEY, BILLPLZ_COLLECTION_ID and BILLPLZ_X_SIGNATURE_KEY.');
        }

        $response = Http::withBasicAuth((string) $this->config['api_key'], '')
            ->asForm()
            ->timeout(20)
            ->post($this->base().'/bills', [
                'collection_id' => (string) $this->config['collection_id'],
                ...$bill,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Billplz refused to create the bill: '.$response->body());
        }

        $id = $response->json('id');
        $url = $response->json('url');

        if (! is_string($id) || ! is_string($url)) {
            throw new RuntimeException('Billplz returned no bill id or URL.');
        }

        return ['id' => $id, 'url' => $url];
    }
}
