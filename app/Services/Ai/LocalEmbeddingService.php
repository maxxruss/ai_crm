<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\EmbeddingServiceInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Calls the local Hugging Face TEI service; source text never leaves the Compose network.
 */
final class LocalEmbeddingService implements EmbeddingServiceInterface
{
    public function embedDocument(string $text): array
    {
        return $this->embed('passage', $text);
    }

    public function embedQuery(string $text): array
    {
        return $this->embed('query', $text);
    }

    /**
     * @return list<float>
     */
    private function embed(string $prefix, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Embedding input must not be empty.');
        }

        $input = $prefix.': '.$text;
        $baseUrl = rtrim((string) config('services.local_embeddings.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Local embeddings URL is not configured.');
        }

        /** @var Response $response */
        $response = Http::acceptJson()
            ->timeout((int) config('services.local_embeddings.timeout', 60))
            ->retry(12, 1000)
            ->post($baseUrl.'/embed', ['inputs' => $input])
            ->throw();

        $payload = $response->json();

        if (is_array($payload) && isset($payload['embeddings'])) {
            $payload = $payload['embeddings'];
        }

        // TEI returns a batch of vectors for batched input and a single vector
        // for a string input. Accept both shapes to keep the adapter resilient.
        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        if (! is_array($payload) || $payload === []) {
            throw new UnexpectedValueException('Local embedding service returned an empty or invalid vector.');
        }

        $vector = array_map(
            static function (mixed $value): float {
                if (! is_numeric($value)) {
                    throw new UnexpectedValueException('Local embedding service returned a non-numeric value.');
                }

                return (float) $value;
            },
            $payload,
        );

        $dimensions = (int) config('services.local_embeddings.dimensions', 768);

        if (count($vector) !== $dimensions) {
            throw new UnexpectedValueException(sprintf(
                'Local embedding dimension mismatch: configured %d, received %d.',
                $dimensions,
                count($vector),
            ));
        }

        return array_values($vector);
    }
}
