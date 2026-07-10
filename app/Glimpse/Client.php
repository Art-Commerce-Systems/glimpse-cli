<?php

namespace App\Glimpse;

use App\Enums\ImageFormat;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class Client
{
    public function __construct(private readonly Config $config) {}

    public function convert(string $bytes, ImageFormat $format, bool $optimize = false, ?int $quality = null): ImageResult
    {
        return ImageResult::fromResponse($this->post('/v1/convert', [
            'format' => $format->value,
            'optimize' => $optimize ?: null,
            'quality' => $quality,
        ], $bytes));
    }

    public function optimize(string $bytes, ?int $quality = null): ImageResult
    {
        return ImageResult::fromResponse($this->post('/v1/optimize', ['quality' => $quality], $bytes));
    }

    public function resize(string $bytes, ?int $width = null, ?int $height = null, bool $optimize = false, ?int $quality = null): ImageResult
    {
        return ImageResult::fromResponse($this->post('/v1/resize', [
            'width' => $width,
            'height' => $height,
            'optimize' => $optimize ?: null,
            'quality' => $quality,
        ], $bytes));
    }

    public function thumbnail(string $bytes, ?int $width = null, ?int $height = null, ?int $quality = null): ImageResult
    {
        return ImageResult::fromResponse($this->post('/v1/thumbnail', ['width' => $width, 'height' => $height, 'quality' => $quality], $bytes));
    }

    /**
     * @return array<string, mixed>
     */
    public function info(string $bytes): array
    {
        return $this->post('/v1/info', [], $bytes);
    }

    /**
     * Predict converted output sizes from metadata alone; no image bytes
     * are sent. The optional sample bits per pixel (a local JPEG trial
     * encode, see SampleProbe) makes the lossy estimates far tighter.
     *
     * @return list<array<string, mixed>>
     */
    public function analyze(ImageFormat $format, int $size, ?int $width = null, ?int $height = null, ?int $quality = null, ?float $sampleBpp = null): array
    {
        $estimates = $this->post('/v1/analyze', [
            'format' => $format->value,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'quality' => $quality,
            'sample_bpp' => $sampleBpp,
        ]);

        return array_values(array_filter($estimates, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    public function user(?string $token = null): array
    {
        $response = $this->request($token ?? $this->requireToken())->get('/user');

        $user = $this->guard($response)->json();

        return is_array($user) ? $user : [];
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $params
     * @return array<string, mixed>
     */
    private function post(string $path, array $params, ?string $bytes = null): array
    {
        $payload = ($bytes === null ? [] : [
            'input' => ['type' => 'BASE64', 'data' => base64_encode($bytes)],
        ]) + array_filter($params, fn ($value) => $value !== null);

        $response = $this->request($this->requireToken())->post($path, $payload);

        $data = $this->guard($response)->json('data');

        return is_array($data) ? $data : [];
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl($this->config->apiUrl())
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(120);
    }

    private function guard(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new AuthException('Invalid or missing token. Run: glimpse auth');
        }

        if ($response->status() === 422) {
            $message = $response->json('message');
            $errors = $response->json('errors');

            throw new ValidationException(
                is_string($message) && $message !== '' ? $message : 'The request was invalid.',
                is_array($errors) ? $errors : [],
            );
        }

        if ($response->failed()) {
            $message = $response->json('message');

            throw new ApiException(sprintf(
                'API error (%d)%s',
                $response->status(),
                is_string($message) && $message !== '' ? ': '.$message : '',
            ));
        }

        return $response;
    }

    private function requireToken(): string
    {
        return $this->config->token() ?? throw new AuthException('Not authenticated. Run: glimpse auth');
    }
}
