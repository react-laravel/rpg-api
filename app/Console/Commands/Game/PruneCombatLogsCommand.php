<?php

namespace App\Console\Commands\Game;

use App\Models\Game\GameCombatLog;
use Illuminate\Console\Command;

class PruneCombatLogsCommand extends Command
{
    protected $signature = 'rpg:prune-combat-logs {--hours=24 : Retention window in hours} {--batch=10000 : Rows deleted per batch}';

    protected $description = 'Delete RPG combat logs older than the configured retention window';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($hours === false || $hours < 1 || $batch === false || $batch < 1 || $batch > 50000) {
            $this->error('hours must be >= 1 and batch must be between 1 and 50000.');

            return self::INVALID;
        }

        $cutoff = now()->subHours($hours);
        $deleted = 0;

        do {
            $ids = GameCombatLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            $count = $ids->isEmpty()
                ? 0
                : GameCombatLog::query()->whereKey($ids)->delete();
            $deleted += $count;
        } while ($count === $batch);

        $this->info("Deleted {$deleted} combat logs older than {$hours} hours.");

        return self::SUCCESS;
    }
}
