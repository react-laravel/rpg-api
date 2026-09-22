<?php

use App\Services\Game\FixedEquipmentUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(FixedEquipmentUpgrade::class)->apply(
            backup: ! app()->runningUnitTests(),
            stopJobs: ! app()->runningUnitTests(),
        );
    }

    public function down(): void
    {
        // Random rolls cannot be reconstructed. Restore the pre-upgrade backup explicitly.
    }
};
