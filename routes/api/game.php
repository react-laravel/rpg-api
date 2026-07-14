<?php

use App\Http\Controllers\Api\Game\CharacterController;
use App\Http\Controllers\Api\Game\CombatController;
use App\Http\Controllers\Api\Game\CompendiumController;
use App\Http\Controllers\Api\Game\GemController;
use App\Http\Controllers\Api\Game\InventoryController;
use App\Http\Controllers\Api\Game\MapController;
use App\Http\Controllers\Api\Game\SkillController;
use Illuminate\Support\Facades\Route;

Route::prefix('rpg')->group(function (): void {
    Route::get('/characters', [CharacterController::class, 'index']);
    Route::get('/character', [CharacterController::class, 'show']);
    Route::post('/character', [CharacterController::class, 'store']);
    Route::delete('/character', [CharacterController::class, 'destroy']);
    Route::match(['put', 'patch'], '/character/stats', [CharacterController::class, 'allocateStats']);
    Route::match(['put', 'patch'], '/character/difficulty', [CharacterController::class, 'updateDifficulty']);
    Route::get('/character/detail', [CharacterController::class, 'detail']);
    Route::post('/character/online', [CharacterController::class, 'online']);

    Route::middleware('idempotency')->group(function (): void {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/inventory/equip', [InventoryController::class, 'equip']);
        Route::post('/inventory/unequip', [InventoryController::class, 'unequip']);
        Route::post('/inventory/sell', [InventoryController::class, 'sell']);
        Route::post('/inventory/sell-by-quality', [InventoryController::class, 'sellByQuality']);
        Route::post('/inventory/auto-recycle-settings', [InventoryController::class, 'updateAutoRecycleSettings']);
        Route::post('/inventory/move', [InventoryController::class, 'move']);
        Route::post('/inventory/sort', [InventoryController::class, 'sort']);
    });

    Route::get('/skills', [SkillController::class, 'index']);
    Route::post('/skills/learn', [SkillController::class, 'learn']);
    Route::post('/gems/socket', [GemController::class, 'socket']);
    Route::post('/gems/unsocket', [GemController::class, 'unsocket']);
    Route::get('/gems', [GemController::class, 'getGems']);
    Route::get('/maps', [MapController::class, 'index']);
    Route::get('/maps/current', [MapController::class, 'current']);
    Route::post('/maps/{map}/enter', [MapController::class, 'enter']);
    Route::post('/maps/{map}/teleport', [MapController::class, 'teleport']);
    Route::get('/combat/status', [CombatController::class, 'status']);
    Route::post('/combat/start', [CombatController::class, 'start']);
    Route::post('/combat/revive', [CombatController::class, 'revive']);
    Route::post('/combat/stop', [CombatController::class, 'stop']);
    Route::post('/combat/skills', [CombatController::class, 'updateSkills']);
    Route::get('/combat/logs', [CombatController::class, 'logs']);
    Route::get('/combat/logs/{log}', [CombatController::class, 'logDetail']);
    Route::get('/combat/stats', [CombatController::class, 'stats']);
    Route::get('/compendium/items', [CompendiumController::class, 'items']);
    Route::get('/compendium/monsters', [CompendiumController::class, 'monsters']);
    Route::get('/compendium/monsters/{monster}/drops', [CompendiumController::class, 'monsterDrops']);
});
