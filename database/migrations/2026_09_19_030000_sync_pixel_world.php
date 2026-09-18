<?php

use App\Services\Game\PixelWorldCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PixelWorldCatalog::class)->sync();
    }

    public function down(): void
    {
        // 保留定义 ID、玩家位置与发现记录；旧名称可通过升级前备份恢复。
    }
};
