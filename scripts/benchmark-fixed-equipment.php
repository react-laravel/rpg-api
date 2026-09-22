<?php

use App\Models\Game\GameCharacter;
use App\Models\Game\GameCharacterSkill;
use App\Models\Game\GameSkillDefinition;
use App\Services\Game\Combat\CombatEffectApplier;
use App\Services\Game\CombatRoundProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Read-only, in-memory benchmark using the real combat processor. No database access.
// See docs/fixed-equipment-balance.md for assumptions and the 30-day target.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'production', 'game.test_mode.enabled' => false, 'logging.default' => 'null']);

class SimSkills extends HasMany
{
    public function __construct(private Collection $rows) {}

    public function with($relations)
    {
        return $this;
    }

    public function get($columns = ['*'])
    {
        return $this->rows;
    }
}
class SimCharacter extends GameCharacter
{
    public array $statsSpec = [];

    public HasMany $skillSpec;

    public function initializeHpMana(): void {}

    public function getCombatStats(): array
    {
        return $this->statsSpec;
    }

    public function getCurrentHp(): int
    {
        return (int) $this->current_hp;
    }

    public function getCurrentMana(): int
    {
        return (int) $this->current_mana;
    }

    public function skills(): HasMany
    {
        return $this->skillSpec;
    }
}
$skillDefs = require database_path('seeders/Game/Data/Skills/skills_mage.php');
$allSkills = [];
foreach ($skillDefs as $i => $row) {
    $model = new GameSkillDefinition;
    $model->forceFill(['id' => 101 + $i] + $row);
    $allSkills[$model->id] = $model;
}
function runSample(array $monsters, array $stats, array $skills, int $rounds = 600): array
{
    mt_srand(1729);
    $ch = new SimCharacter;
    $ch->statsSpec = $stats;
    $ch->skillSpec = new SimSkills(new Collection($skills));
    $ch->difficulty_tier = 0;
    $ch->current_hp = $stats['max_hp'];
    $ch->current_mana = $stats['max_mana'];
    $ch->combat_monsters = array_fill(0, 5, null);
    $ch->combat_buffs = [];
    $processor = new CombatRoundProcessor(effectApplier: new CombatEffectApplier);
    $cooldowns = [];
    $agg = [];
    $exp = 0;
    $taken = 0;
    $kills = 0;
    $lastRefresh = -100;
    $pick = function () use ($monsters) {
        $kind = rand(1, 100) <= 95 ? 'normal' : 'special';
        $pool = array_values(array_filter($monsters, fn ($m) => $kind === 'normal' ? $m['type'] === 'normal' : $m['type'] !== 'normal'));
        if (! $pool) {
            $pool = $monsters;
        }

        return $pool[array_rand($pool)];
    };
    $spawn = function ($m, $slot) {
        $hp = (int) $m['hp_base'];

        return [
            'id' => $m['id'], 'name' => $m['name'], 'type' => $m['type'], 'level' => $m['level'], 'reward_layer' => $m['level'], 'hp' => $hp, 'max_hp' => $hp, 'attack' => $m['attack_base'], 'defense' => $m['defense_base'], 'experience' => $m['experience_base'], 'position' => $slot];
    };
    for ($r = 0; $r < $rounds; $r++) {
        $old = $ch->combat_monsters;
        $alive = array_filter($old, fn ($m) => is_array($m) && $m['hp'] > 0);
        $refresh = $r - $lastRefresh >= 20;
        if (! $alive || $refresh) {
            $next = array_fill(0, 5, null);
            $n = rand(1, 5);
            $m = $pick();
            for ($i = 0; $i < $n; $i++) {
                $next[$i] = $spawn($m, $i);
                if ($refresh && isset($old[$i])) {
                    $next[$i]['hp'] = min($old[$i]['hp'], $next[$i]['max_hp']);
                }
            }
            $ch->combat_monsters = $next;
            $cooldowns = [];
            $agg = [];
            $ch->combat_buffs = [];
            $lastRefresh = $r;
        }
        $result = $processor->processOneRound($ch, $cooldowns, $agg, null);
        $exp += $result['experience_gained'];
        $taken += $result['round_damage_taken'];
        $kills += count($result['slots_where_monster_died_this_round']);
        $ch->current_hp = $result['new_char_hp'];
        $ch->current_mana = $result['new_char_mana'];
        if ($ch->current_hp <= 0) {
            return ['diedAt' => $r + 1, 'xpPerHour' => round($exp / (($r + 1) * 3) * 3600), 'taken' => $taken];
        }
        $ch->current_hp = min($stats['max_hp'], $ch->current_hp + $stats['hp_regen']);
        $ch->current_mana = min($stats['max_mana'], $ch->current_mana + $stats['mana_regen']);
        $cooldowns = $result['new_cooldowns'];
        $agg = $result['new_skills_aggregated'];
        $next = $result['monsters_updated'];
        $empty = [];
        for ($i = 0; $i < 5; $i++) {
            if ((! isset($next[$i]) || $next[$i]['hp'] <= 0) && ! in_array($i, $result['slots_where_monster_died_this_round'])) {
                $empty[] = $i;
            }
        }
        if ($empty && rand(1, 100) > 30) {
            $roll = rand(1, 100);
            $n = $roll <= 40 ? 1 : ($roll <= 65 ? 2 : ($roll <= 85 ? 3 : ($roll <= 95 ? 4 : 5)));
            shuffle($empty);
            $m = $pick();
            foreach (array_slice($empty, 0, $n) as $slot) {
                $next[$slot] = $spawn($m, $slot);
            }
        }
        $ch->combat_monsters = $next;
    }

    return ['diedAt' => null, 'xpPerHour' => round($exp / ($rounds * 3) * 3600), 'killsPerRound' => round($kills / $rounds, 3), 'damagePerRound' => round($taken / $rounds, 3), 'hpEnd' => $ch->current_hp];
}
$items = require database_path('seeders/Game/Data/items.php');
$monsters = require database_path('seeders/Game/Data/monsters.php');
foreach ($monsters as $i => &$m) {
    $m['id'] = $i + 1;
}
unset($m);
function profile(int $level, array $items, array $allSkills): array
{
    $points = $level - 1;
    $s = 3 + (int) floor($points * .5);
    $v = 3 + (int) floor($points * .3);
    $e = 5 + $points - (int) floor($points * .5) - (int) floor($points * .3);
    $d = 4;
    $gear = [];
    foreach (['weapon', 'helmet', 'armor', 'gloves', 'boots', 'belt', 'ring', 'amulet'] as $slot) {
        $available = array_values(array_filter($items, fn ($i) => $i['type'] === $slot && $i['required_level'] <= $level));
        usort($available, fn ($a, $b) => ($b['required_level'] <=> $a['required_level']) ?: count($b['base_stats']) <=> count($a['base_stats']));
        foreach ($available[0]['base_stats'] ?? [] as $key => $value) {
            $gear[$key] = ($gear[$key] ?? 0) + $value;
        }
    }
    $order = [101, 102, 104, 105, 106, 108, 117, 118, 120, 109, 110, 111, 121, 122, 124, 125, 126, 128, 137];
    $budget = $level;
    $skills = [];
    $lines = 0;
    $hasKey = false;
    foreach ($order as $id) {
        $skill = $allSkills[$id];
        $cost = (int) ($skill->skill_points_cost ?? 1);
        if ($budget < $cost || (int) $skill->unlock_level > $level) {
            continue;
        }
        $budget -= $cost;
        $cs = new GameCharacterSkill;
        $cs->setRelation('skill', $skill);
        $skills[] = $cs;
        if ($skill->node_tier == 2) {
            $lines++;
        }
        if ($id === 137) {
            $hasKey = true;
        }
    }
    $stats = [
        'attack' => (int) round(($s + ($gear['attack'] ?? 0)) * (1 + ($hasKey ? .02 * $lines : 0))),
        'defense' => (int) ($v * .35 + $d * .2) + ($gear['defense'] ?? 0),
        'max_hp' => 10 + $v * 3 + ($gear['max_hp'] ?? 0),
        'max_mana' => (int) round((20 + $e * 2 + ($gear['max_mana'] ?? 0)) * (1 + ($hasKey ? .05 * $lines : 0))),
        'crit_rate' => min(.3, $d * .002 + ($gear['crit_rate'] ?? 0)),
        'crit_damage' => 1.5 + ($gear['crit_damage'] ?? 0),
        'hp_regen' => (int) round($v * .25),
        'mana_regen' => (int) round($e * .5),
    ];

    return [$stats, $skills];
}
$options = getopt('', ['rounds:', 'levels:', 'output:', 'best-from:']);
$bestMaps = [];
if (isset($options['best-from'])) {
    foreach (json_decode(file_get_contents($options['best-from']), true, flags: JSON_THROW_ON_ERROR)['profiles'] as $row) {
        $bestMaps[$row['level']] = $row['best']['map'];
    }
}
$rounds = max(1, (int) ($options['rounds'] ?? 600));
$levels = isset($options['levels']) ? array_map('intval', explode(',', $options['levels'])) : [1, 5, 10, 15, 25, 45, 65, 85, 100, 125, 150, 175, 199];
$out = [];
foreach ($levels as $level) {
    [$stats,$skills] = profile($level, $items, $allSkills);
    $samples = [];
    foreach (isset($bestMaps[$level]) ? [$bestMaps[$level]] : range(1, 41) as $map) {
        $pool = array_values(array_filter($monsters, fn ($m) => $m['level'] === $map));
        $sample = runSample($pool, $stats, $skills, $rounds);
        $samples[] = ['map' => $map] + $sample;
    }
    $safe = array_values(array_filter($samples, fn ($s) => $s['diedAt'] === null));
    usort($safe, fn ($a, $b) => $b['xpPerHour'] <=> $a['xpPerHour']);
    $out[] = ['level' => $level, 'stats' => $stats, 'best' => $safe[0] ?? null, 'safeMaps' => array_column($safe, 'map')];
    echo json_encode(end($out), JSON_UNESCAPED_UNICODE),"\n";
    flush();
}
if (isset($options['output'])) {
    file_put_contents($options['output'], json_encode(['rounds_per_map' => $rounds, 'round_seconds' => 3, 'experience_scale' => config('game.monster_progression.experience_scale'), 'profiles' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
}
