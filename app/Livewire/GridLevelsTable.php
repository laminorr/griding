<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Collection;

class GridLevelsTable extends Component
{
    // Properties
    public Collection $gridLevels;
    public float $centerPrice = 0;
    public string $sortBy = 'level';
    public string $sortDirection = 'asc';
    public string $filterType = 'all';
    public bool $showDetails = true;
    public string $priceFormat = 'rial';
    
    // Protected properties
    protected $listeners = ['refreshTable' => '$refresh'];
    
    /**
     * Component initialization
     */
    public function mount(
        Collection $gridLevels = null, 
        float $centerPrice = 0,
        array $options = []
    ): void {
        $this->gridLevels = $gridLevels ?? collect();
        $this->centerPrice = $centerPrice;
        $this->showDetails = $options['showDetails'] ?? true;
        $this->priceFormat = $options['priceFormat'] ?? 'rial';
        
        // Add any missing properties to grid levels
        $this->gridLevels = $this->gridLevels->map(function ($level, $index) {
            if (!isset($level['level'])) {
                $level['level'] = $index + 1;
            }
            if (!isset($level['priority'])) {
                $level['priority'] = 5;
            }
            if (!isset($level['execution_probability'])) {
                $level['execution_probability'] = $this->calculateExecutionProbability($level);
            }
            return $level;
        });
    }

    /**
     * Get filtered and sorted levels
     */
    public function getFilteredLevelsProperty(): Collection
    {
        $levels = $this->gridLevels;

        // Apply type filter
        if ($this->filterType !== 'all') {
            $levels = $levels->where('type', $this->filterType);
        }

        // Apply sorting
        $levels = $levels->sortBy([
            [$this->sortBy, $this->sortDirection],
            ['price', 'asc'] // Secondary sort by price
        ]);

        return $levels->values();
    }

    /**
     * Sort by specific field
     */
    public function sortBy($field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Set filter type
     */
    public function setFilter($type): void
    {
        $this->filterType = $type;
    }

    /**
     * Toggle details visibility
     */
    public function toggleDetails(): void
    {
        $this->showDetails = !$this->showDetails;
    }

    /**
     * Get type icon emoji
     */
    public function getTypeIcon($type): string
    {
        return match($type) {
            'buy' => '🟢',
            'sell' => '🔴',
            default => '🟡'
        };
    }

    /**
     * Get type label in Persian
     */
    public function getTypeLabel($type): string
    {
        return match($type) {
            'buy' => 'خرید',
            'sell' => 'فروش',
            default => 'مرکز'
        };
    }

    /**
     * Get CSS classes for type styling
     */
    public function getTypeClass($type): string
    {
        return match($type) {
            'buy' => 'text-green-700 bg-green-50 border-green-200',
            'sell' => 'text-red-700 bg-red-50 border-red-200',
            default => 'text-yellow-700 bg-yellow-50 border-yellow-200'
        };
    }

    /**
     * Calculate price distance from center as percentage
     */
    public function getPriceDistancePercent($price): float
    {
        if ($this->centerPrice <= 0) return 0;
        return round((($price - $this->centerPrice) / $this->centerPrice) * 100, 2);
    }

    /**
     * Get CSS class for price distance styling
     */
    public function getPriceDistanceClass($distance): string
    {
        $abs = abs($distance);
        if ($abs <= 1) return 'text-green-600';
        if ($abs <= 3) return 'text-yellow-600';
        return 'text-red-600';
    }

    /**
     * Format price based on format setting
     */
    public function formatPrice($price): string
    {
        if ($this->priceFormat === 'rial') {
            return number_format($price, 0) . ' ریال';
        }
        return number_format($price, 2);
    }

    /**
     * Format cryptocurrency amount
     */
    public function formatAmount($amount): string
    {
        if ($amount <= 0) {
            return '0 BTC';
        }
        return number_format($amount, 8) . ' BTC';
    }

    /**
     * Calculate execution probability for a level
     */
    private function calculateExecutionProbability($level): float
    {
        if (!$this->centerPrice || !isset($level['price'])) {
            return 0.5; // Default 50%
        }
        
        $distance = abs($this->getPriceDistancePercent($level['price']));
        
        // Probability decreases as distance from center increases
        if ($distance <= 1) return 0.9;
        if ($distance <= 2) return 0.8;
        if ($distance <= 3) return 0.7;
        if ($distance <= 5) return 0.6;
        return 0.5;
    }

    /**
     * Get comprehensive statistics
     */
    public function getStatistics(): array
    {
        $buyLevels = $this->gridLevels->where('type', 'buy');
        $sellLevels = $this->gridLevels->where('type', 'sell');
        
        return [
            'total_levels' => $this->gridLevels->count(),
            'buy_levels' => $buyLevels->count(),
            'sell_levels' => $sellLevels->count(),
            'price_range' => [
                'min' => $this->gridLevels->min('price') ?? 0,
                'max' => $this->gridLevels->max('price') ?? 0,
            ],
            'total_coverage' => $this->calculateTotalCoverage(),
            'avg_spacing' => $this->calculateAverageSpacing(),
            'total_investment' => $this->calculateTotalInvestment()
        ];
    }

    /**
     * Calculate total price coverage percentage
     */
    private function calculateTotalCoverage(): float
    {
        if ($this->gridLevels->isEmpty() || $this->centerPrice <= 0) {
            return 0;
        }

        $minPrice = $this->gridLevels->min('price');
        $maxPrice = $this->gridLevels->max('price');
        
        if (!$minPrice || !$maxPrice || $minPrice >= $maxPrice) {
            return 0;
        }
        
        return round((($maxPrice - $minPrice) / $this->centerPrice) * 100, 1);
    }

    /**
     * Calculate average spacing between levels
     */
    private function calculateAverageSpacing(): float
    {
        if ($this->gridLevels->count() < 2) {
            return 0;
        }

        $sortedPrices = $this->gridLevels->pluck('price')->sort()->values();
        $spacings = [];
        
        for ($i = 1; $i < $sortedPrices->count(); $i++) {
            $spacing = (($sortedPrices[$i] - $sortedPrices[$i-1]) / $sortedPrices[$i-1]) * 100;
            $spacings[] = $spacing;
        }
        
        return round(collect($spacings)->avg(), 2);
    }

    /**
     * Calculate total investment required
     */
    private function calculateTotalInvestment(): float
    {
        return $this->gridLevels->sum(function ($level) {
            $amount = $level['amount'] ?? 0;
            $price = $level['price'] ?? 0;
            return $amount * $price;
        });
    }

    /**
     * Export table data to array
     */
    public function exportToArray(): array
    {
        return [
            'metadata' => [
                'total_levels' => $this->gridLevels->count(),
                'center_price' => $this->centerPrice,
                'filter_applied' => $this->filterType,
                'sort_by' => $this->sortBy,
                'sort_direction' => $this->sortDirection,
                'generated_at' => now()->toISOString()
            ],
            'statistics' => $this->getStatistics(),
            'grid_levels' => $this->filteredLevels->map(function ($level) {
                return [
                    'level' => $level['level'] ?? 0,
                    'type' => $level['type'],
                    'type_label' => $this->getTypeLabel($level['type']),
                    'price' => $level['price'],
                    'price_formatted' => $this->formatPrice($level['price']),
                    'amount' => $level['amount'] ?? 0,
                    'amount_formatted' => $this->formatAmount($level['amount'] ?? 0),
                    'distance_from_center' => $this->getPriceDistancePercent($level['price']),
                    'execution_probability' => $level['execution_probability'] ?? 0,
                    'value_irt' => ($level['amount'] ?? 0) * $level['price']
                ];
            })->toArray()
        ];
    }

    /**
     * Get summary for display
     */
    public function getSummary(): array
    {
        $stats = $this->getStatistics();
        
        return [
            'total_levels' => $stats['total_levels'],
            'buy_sell_ratio' => $stats['buy_levels'] . ':' . $stats['sell_levels'],
            'price_coverage' => $stats['total_coverage'] . '%',
            'avg_spacing' => $stats['avg_spacing'] . '%'
        ];
    }

    /**
     * Render the component
     */
    public function render()
    {
        return view('livewire.grid-levels-table', [
            'levels' => $this->filteredLevels,
            'statistics' => $this->getStatistics(),
            'summary' => $this->getSummary()
        ]);
    }
}