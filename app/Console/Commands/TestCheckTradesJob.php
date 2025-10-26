<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\CheckTradesJob;
use App\Models\BotConfig;

class TestCheckTradesJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:check-trades {bot_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test CheckTradesJob execution with detailed output';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('======================================');
        $this->info('Testing CheckTradesJob');
        $this->info('======================================');

        // Get bot ID from argument or find first active bot
        $botId = $this->argument('bot_id') ?? BotConfig::where('is_active', true)->first()?->id;

        if (!$botId) {
            $this->error('No active bots found');
            return 1;
        }

        // Load bot before execution
        $bot = BotConfig::find($botId);
        if (!$bot) {
            $this->error("Bot with ID {$botId} not found");
            return 1;
        }

        $this->info("Bot: {$bot->name} (ID: {$bot->id})");
        $this->info("Status: " . ($bot->is_active ? 'Active' : 'Inactive'));
        $this->info("last_check_at BEFORE: " . ($bot->last_check_at ?? 'NULL'));
        $this->newLine();

        $this->info('Executing CheckTradesJob...');
        $this->newLine();

        try {
            // Execute the job
            $job = new CheckTradesJob();
            $job->handle();

            $this->info('Job execution completed');
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('Job execution failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            $this->newLine();
        }

        // Reload bot to get updated data
        $bot->refresh();

        $this->info('======================================');
        $this->info('Results:');
        $this->info('======================================');
        $this->info("last_check_at AFTER: " . ($bot->last_check_at ?? 'NULL'));
        $this->newLine();

        if ($bot->last_check_at) {
            $this->info('✅ SUCCESS! Timestamp was updated.');
            return 0;
        } else {
            $this->error('❌ FAILED - last_check_at still NULL');
            $this->error('Check the logs for details');
            return 1;
        }
    }
}
