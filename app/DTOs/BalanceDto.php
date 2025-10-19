<?php
namespace App\DTOs;


final class BalanceDto
{
public string $currency;
public string $total;
public string $available;
public string $blocked;
public ?int $rialBalance;
public ?int $rialBalanceSell;


public function __construct(
string $currency,
string $total = '0',
string $available = '0',
string $blocked = '0',
?int $rialBalance = null,
?int $rialBalanceSell = null
) {
$this->currency = strtolower($currency);
$this->total = $total;
$this->available = $available;
$this->blocked = $blocked;
$this->rialBalance = $rialBalance;
$this->rialBalanceSell = $rialBalanceSell;
}


public static function fromApi(array $row): self
{
$currency = (string)($row['currency'] ?? $row['symbol'] ?? '');
if ($currency === '') {
throw new \InvalidArgumentException('BalanceDto: currency missing');
}
$total = (string)($row['balance'] ?? $row['total'] ?? $row['amount'] ?? '0');
$available = (string)($row['activeBalance'] ?? $row['available'] ?? $row['availableBalance'] ?? $row['balance'] ?? '0');
$blocked = (string)($row['blockedBalance'] ?? $row['blocked'] ?? '0');
$rialBalance = isset($row['rialBalance']) ? (int)$row['rialBalance'] : null;
$rialBalanceSell = isset($row['rialBalanceSell']) ? (int)$row['rialBalanceSell'] : null;


return new self($currency, $total, $available, $blocked, $rialBalance, $rialBalanceSell);
}


public function hasAvailableGreaterThan(string $amount, int $scale = 8): bool
{
return \bccomp($this->available, $amount, $scale) >= 0;
}


public function toArray(): array
{
return [
'currency' => $this->currency,
'total' => $this->total,
'available' => $this->available,
'blocked' => $this->blocked,
'rialBalance' => $this->rialBalance,
'rialBalanceSell' => $this->rialBalanceSell,
];
}
}