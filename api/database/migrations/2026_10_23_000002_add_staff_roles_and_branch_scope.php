<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cua_hang')) {
            Schema::create('cua_hang', function (Blueprint $table): void {
                $table->integer('id_cua_hang')->autoIncrement();
                $table->string('ten_cua_hang', 255);
                $table->text('dia_chi');
                $table->string('dien_thoai', 20)->nullable();
                $table->boolean('trang_thai')->default(true);
            });
        }

        if (! Schema::hasTable('ton_kho_cua_hang')) {
            Schema::create('ton_kho_cua_hang', function (Blueprint $table): void {
                $table->integer('id_cua_hang');
                $table->char('ma_sp', 10);
                $table->integer('so_luong_ton')->default(0);
                $table->primary(['id_cua_hang', 'ma_sp']);
                $table->foreign('id_cua_hang')->references('id_cua_hang')->on('cua_hang')->cascadeOnDelete();
            });
        }

        $addAdminBranch = ! Schema::hasColumn('quan_tri', 'id_cua_hang');
        Schema::table('quan_tri', function (Blueprint $table) use ($addAdminBranch): void {
            if (! Schema::hasColumn('quan_tri', 'role')) {
                $table->string('role', 40)->default('chu_quan');
            }
            if ($addAdminBranch) {
                $table->integer('id_cua_hang')->nullable()->index();
            }
        });

        $this->ensureIntegerForeignKey('quan_tri', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'null');

        Schema::table('san_pham', function (Blueprint $table): void {
            if (! Schema::hasColumn('san_pham', 'tk_admin_tao')) {
                $table->string('tk_admin_tao', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('san_pham', 'id_cua_hang')) {
                $table->integer('id_cua_hang')->nullable()->index();
            }
        });
        $this->ensureIntegerForeignKey('san_pham', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'null');
        $this->ensureStringForeignKey('san_pham', 'tk_admin_tao', 'quan_tri', 'tk_admin', 'null');

        Schema::table('don_hang', function (Blueprint $table): void {
            if (! Schema::hasColumn('don_hang', 'id_cua_hang')) {
                $table->integer('id_cua_hang')->nullable()->index();
            }
            if (! Schema::hasColumn('don_hang', 'nhan_vien_id')) {
                $table->string('nhan_vien_id', 20)->nullable()->index();
            }
        });
        $this->ensureIntegerForeignKey('don_hang', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'null');
        $this->ensureStringForeignKey('don_hang', 'nhan_vien_id', 'quan_tri', 'tk_admin', 'null');

        if (Schema::hasTable('phieu_nhap_kho')) {
            Schema::table('phieu_nhap_kho', function (Blueprint $table): void {
                if (! Schema::hasColumn('phieu_nhap_kho', 'id_cua_hang')) {
                    $table->integer('id_cua_hang')->nullable()->index();
                }
            });
            $this->ensureIntegerForeignKey('phieu_nhap_kho', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'null');
        }

        $this->ensureStringForeignKey('ton_kho_cua_hang', 'ma_sp', 'san_pham', 'ma_sp', 'cascade');
        $this->ensureIntegerForeignKey('ton_kho_cua_hang', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'cascade');

        if (Schema::hasTable('so_serial_san_pham')) {
            Schema::table('so_serial_san_pham', function (Blueprint $table): void {
                if (! Schema::hasColumn('so_serial_san_pham', 'id_cua_hang')) {
                    $table->integer('id_cua_hang')->nullable()->index();
                }
            });
            $this->ensureIntegerForeignKey('so_serial_san_pham', 'id_cua_hang', 'cua_hang', 'id_cua_hang', 'null');
        }

        if (Schema::hasTable('bao_hanh')) {
            Schema::table('bao_hanh', function (Blueprint $table): void {
                if (! Schema::hasColumn('bao_hanh', 'trang_thai_xu_ly')) {
                    $table->string('trang_thai_xu_ly', 30)->default('active');
                }
                if (! Schema::hasColumn('bao_hanh', 'ghi_chu_xu_ly')) {
                    $table->text('ghi_chu_xu_ly')->nullable();
                }
            });
        }

        if (! Schema::hasTable('bang_luong')) {
            Schema::create('bang_luong', function (Blueprint $table): void {
                $table->id();
                $table->string('tk_admin', 20);
                $table->unsignedTinyInteger('thang');
                $table->unsignedSmallInteger('nam');
                $table->decimal('luong_co_ban', 12, 0)->default(0);
                $table->decimal('phu_cap', 12, 0)->default(0);
                $table->decimal('thuong', 12, 0)->default(0);
                $table->decimal('khau_tru', 12, 0)->default(0);
                $table->string('trang_thai', 20)->default('chua_thanh_toan');
                $table->timestamp('ngay_thanh_toan')->nullable();
                $table->timestamps();
                $table->unique(['tk_admin', 'nam', 'thang']);
            });
        }
        $this->ensureStringForeignKey('bang_luong', 'tk_admin', 'quan_tri', 'tk_admin', 'cascade');
    }

    public function down(): void
    {
        Schema::dropIfExists('bang_luong');

        foreach ([
            'so_serial_san_pham' => ['id_cua_hang'],
            'phieu_nhap_kho' => ['id_cua_hang'],
            'don_hang' => ['id_cua_hang', 'nhan_vien_id'],
            'san_pham' => ['tk_admin_tao', 'id_cua_hang'],
            'quan_tri' => ['id_cua_hang'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($tableName, $column)));
            if ($existing === []) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($existing): void {
                foreach ($existing as $column) {
                    $table->dropForeign([$column]);
                    $table->dropIndex([$column]);
                }
                $table->dropColumn($existing);
            });
        }

        if (Schema::hasTable('bao_hanh')) {
            Schema::table('bao_hanh', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    ['trang_thai_xu_ly', 'ghi_chu_xu_ly'],
                    fn (string $column): bool => Schema::hasColumn('bao_hanh', $column),
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
        if (Schema::hasColumn('quan_tri', 'role')) {
            Schema::table('quan_tri', fn (Blueprint $table) => $table->dropColumn('role'));
        }
    }

    private function ensureStringForeignKey(
        string $tableName,
        string $columnName,
        string $parentTable,
        string $parentColumn,
        string $deleteAction,
    ): void {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasColumn($tableName, $columnName)
            || ! Schema::hasTable($parentTable)
            || ! Schema::hasColumn($parentTable, $parentColumn)
            || $this->hasForeignKey($tableName, $columnName, $parentTable)) {
            return;
        }

        $this->matchForeignKeyCollation($tableName, $columnName, $parentTable, $parentColumn);
        Schema::table($tableName, function (Blueprint $table) use ($columnName, $parentTable, $parentColumn, $deleteAction): void {
            $foreign = $table->foreign($columnName)->references($parentColumn)->on($parentTable);
            if ($deleteAction === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($deleteAction === 'null') {
                $foreign->nullOnDelete();
            }
        });
    }

    private function ensureIntegerForeignKey(
        string $tableName,
        string $columnName,
        string $parentTable,
        string $parentColumn,
        string $deleteAction,
    ): void {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasColumn($tableName, $columnName)
            || ! Schema::hasTable($parentTable)
            || ! Schema::hasColumn($parentTable, $parentColumn)
            || $this->hasForeignKey($tableName, $columnName, $parentTable)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName, $parentTable, $parentColumn, $deleteAction): void {
            $foreign = $table->foreign($columnName)->references($parentColumn)->on($parentTable);
            if ($deleteAction === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($deleteAction === 'null') {
                $foreign->nullOnDelete();
            }
        });
    }

    private function hasForeignKey(string $tableName, string $columnName, string $parentTable): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return collect(Schema::getForeignKeys($tableName))->contains(
                fn (array $key): bool => $key['columns'] === [$columnName]
                    && $key['foreign_table'] === $parentTable,
            );
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->where('REFERENCED_TABLE_NAME', $parentTable)
            ->exists();
    }

    private function matchForeignKeyCollation(
        string $tableName,
        string $columnName,
        string $parentTable,
        string $parentColumn,
    ): void {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $parent = $this->columnMetadata($parentTable, $parentColumn);
        $child = $this->columnMetadata($tableName, $columnName);
        if (! $parent || ! $child
            || $parent->charset === $child->charset && $parent->collation === $child->collation) {
            return;
        }
        if (! in_array($child->type_name, ['char', 'varchar'], true) || ! $child->length) {
            throw new RuntimeException("Cannot align foreign-key collation for {$tableName}.{$columnName}.");
        }

        $columnType = $child->type_name === 'varchar' ? 'string' : 'char';
        Schema::table($tableName, function (Blueprint $table) use ($child, $columnName, $parent, $columnType): void {
            $column = $table->{$columnType}($columnName, (int) $child->length)
                ->charset($parent->charset)
                ->collation($parent->collation)
                ->nullable($child->nullable === 'YES');
            if ($child->default_value !== null) {
                $column->default($child->default_value);
            }
            $column->change();
        });
    }

    private function columnMetadata(string $tableName, string $columnName): ?object
    {
        return DB::selectOne(
            'select DATA_TYPE as type_name, CHARACTER_MAXIMUM_LENGTH as length,
                CHARACTER_SET_NAME as charset, COLLATION_NAME as collation,
                IS_NULLABLE as nullable, COLUMN_DEFAULT as default_value
            from information_schema.COLUMNS
            where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$tableName, $columnName],
        );
    }
};
