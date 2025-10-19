<?php
declare(strict_types=1);

namespace App\DTOs;

/**
 * DTO پاسخ سادهٔ OK از API.
 * - برای متدهایی که فقط موفقیت/شکست برمی‌گردانند (مثل cancelOrder).
 */
final readonly class ApiOkDto
{
    public function __construct(
        public bool $ok,
        public ?string $message = null,
    ) {}

    /**
     * ساخت از پاسخ API نوبیتکس.
     * انتظار: { "status":"ok" } یا { "status":"error", "message":"..." }
     */
    public static function fromApi(array $payload): self
    {
        $ok = strtolower((string) ($payload['status'] ?? '')) === 'ok';
        $msg = $payload['message']
            ?? $payload['error']
            ?? ($payload['errors'][0] ?? null)
            ?? null;

        return new self($ok, $msg);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isError(): bool
    {
        return !$this->ok;
    }

    public function toArray(): array
    {
        return [
            'ok'      => $this->ok,
            'message' => $this->message,
        ];
    }
}
