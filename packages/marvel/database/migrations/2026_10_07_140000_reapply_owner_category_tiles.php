<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Re-apply the owner's category tiles once.
 *
 * On staging, 2026_10_07_130000 ran and the boot seeders then undid it: three writers
 * re-applied stock Unsplash photos to existing categories and categorize-plants deleted
 * every Plants category outside its list (Bonsai, Palms, Rare & Exotic). They are
 * create-only / spare flagged categories as of this commit, so applying once more sticks.
 * On an environment where 130000 just ran (production), this writes the same values again.
 *
 * Same writes, same backup table: 130000's down() replays every snapshot newest-first, so
 * it still lands on the state from before the owner's tiles.
 */
return new class extends Migration {
    public function up(): void
    {
        (require __DIR__ . '/2026_10_07_130000_apply_owner_category_tiles.php')->up();
    }

    public function down(): void
    {
        // 130000's down() restores everything this wrote.
    }
};
