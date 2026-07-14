<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneCombatLogsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('game_combat_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('game_combat_logs');

        parent::tearDown();
    }

    public function test_it_only_deletes_logs_older_than_24_hours(): void
    {
        $this->travelTo('2026-07-14 12:00:00');

        DB::table('game_combat_logs')->insert([
            ['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)],
            ['created_at' => now()->subHours(24), 'updated_at' => now()->subHours(24)],
            ['created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
        ]);

        $this->artisan('rpg:prune-combat-logs --hours=24 --batch=1')
            ->expectsOutput('Deleted 1 combat logs older than 24 hours.')
            ->assertSuccessful();

        $this->assertDatabaseCount('game_combat_logs', 2);
    }
}
