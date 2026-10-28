<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE items ADD CONSTRAINT items_minimum_stock_nonnegative CHECK (minimum_stock >= 0)');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER items_ownership_immutable BEFORE UPDATE ON items FOR EACH ROW
                BEGIN
                    IF NEW.ownership_type <> OLD.ownership_type THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item ownership cannot be changed';
                    END IF;
                END
                SQL);

            foreach (['INSERT', 'UPDATE'] as $event) {
                $suffix = strtolower($event);
                DB::unprepared("CREATE TRIGGER vendor_items_owner_{$suffix} BEFORE {$event} ON vendor_items FOR EACH ROW
                    BEGIN
                        IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = 'CONSIGNMENT') THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only consignment items may have vendors';
                        END IF;
                    END");
                DB::unprepared("CREATE TRIGGER stock_item_locations_owner_{$suffix} BEFORE {$event} ON stock_item_locations FOR EACH ROW
                    BEGIN
                        IF NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = 'STOCK') THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only stock items may have internal placements';
                        END IF;
                    END");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER items_ownership_immutable BEFORE UPDATE OF ownership_type ON items
                WHEN NEW.ownership_type <> OLD.ownership_type
                BEGIN SELECT RAISE(ABORT, 'Item ownership cannot be changed'); END
                SQL);

            foreach (['INSERT', 'UPDATE'] as $event) {
                $suffix = strtolower($event);
                DB::unprepared("CREATE TRIGGER items_minimum_stock_{$suffix} BEFORE {$event} ON items
                    WHEN NEW.minimum_stock < 0
                    BEGIN SELECT RAISE(ABORT, 'Minimum stock cannot be negative'); END");
                DB::unprepared("CREATE TRIGGER vendor_items_owner_{$suffix} BEFORE {$event} ON vendor_items
                    WHEN NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = 'CONSIGNMENT')
                    BEGIN SELECT RAISE(ABORT, 'Only consignment items may have vendors'); END");
                DB::unprepared("CREATE TRIGGER stock_item_locations_owner_{$suffix} BEFORE {$event} ON stock_item_locations
                    WHEN NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND ownership_type = 'STOCK')
                    BEGIN SELECT RAISE(ABORT, 'Only stock items may have internal placements'); END");
            }
        } else {
            throw new RuntimeException('Master Data migrations support MySQL and SQLite only.');
        }
    }

    public function down(): void
    {
        foreach (['items_ownership_immutable', 'items_minimum_stock_insert', 'items_minimum_stock_update',
            'vendor_items_owner_insert', 'vendor_items_owner_update',
            'stock_item_locations_owner_insert', 'stock_item_locations_owner_update'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE items DROP CHECK items_minimum_stock_nonnegative');
        }
    }
};
