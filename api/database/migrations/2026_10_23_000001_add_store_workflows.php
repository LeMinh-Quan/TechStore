<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('san_pham', function (Blueprint $table): void {
            if (! Schema::hasColumn('san_pham', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->index();
            }
        });

        Schema::table('nguoi_dung', function (Blueprint $table): void {
            if (! Schema::hasColumn('nguoi_dung', 'so_dien_thoai')) {
                $table->string('so_dien_thoai', 20)->nullable()->unique();
            }
        });

        Schema::table('dia_chi', function (Blueprint $table): void {
            if (! Schema::hasColumn('dia_chi', 'ten_nguoi_nhan')) {
                $table->string('ten_nguoi_nhan', 200)->nullable();
            }
            if (! Schema::hasColumn('dia_chi', 'so_dien_thoai')) {
                $table->string('so_dien_thoai', 20)->nullable();
            }
        });

        Schema::table('don_hang', function (Blueprint $table): void {
            if (! Schema::hasColumn('don_hang', 'idempotency_key')) {
                $table->string('idempotency_key', 36)->nullable()->unique();
            }
            if (! Schema::hasColumn('don_hang', 'ma_voucher')) {
                $table->string('ma_voucher', 40)->nullable();
            }
            if (! Schema::hasColumn('don_hang', 'tien_giam')) {
                $table->decimal('tien_giam', 12, 0)->default(0);
            }
            if (! Schema::hasColumn('don_hang', 'ten_nguoi_nhan')) {
                $table->string('ten_nguoi_nhan', 200)->nullable();
            }
            if (! Schema::hasColumn('don_hang', 'dien_thoai_nguoi_nhan')) {
                $table->string('dien_thoai_nguoi_nhan', 20)->nullable();
            }
            if (! Schema::hasColumn('don_hang', 'dia_chi_giao_hang')) {
                $table->text('dia_chi_giao_hang')->nullable();
            }
        });

        Schema::table('chi_tiet_don_hang', function (Blueprint $table): void {
            if (! Schema::hasColumn('chi_tiet_don_hang', 'ten_sp_snapshot')) {
                $table->string('ten_sp_snapshot', 255)->nullable();
            }
            if (! Schema::hasColumn('chi_tiet_don_hang', 'thong_so_snapshot')) {
                $table->text('thong_so_snapshot')->nullable();
            }
        });

        Schema::table('nhat_ky_trang_thai_don', function (Blueprint $table): void {
            if (! Schema::hasColumn('nhat_ky_trang_thai_don', 'trang_thai_cu')) {
                $table->string('trang_thai_cu', 50)->nullable();
            }
            if (! Schema::hasColumn('nhat_ky_trang_thai_don', 'nguoi_thuc_hien')) {
                $table->string('nguoi_thuc_hien', 60)->nullable();
            }
        });

        if (! Schema::hasTable('danh_gia')) {
            Schema::create('danh_gia', function (Blueprint $table): void {
                $table->id();
                $table->string('ma_kh', 20);
                $table->char('ma_sp', 10);
                $table->unsignedTinyInteger('rating');
                $table->text('content');
                $table->timestamps();
                $table->unique(['ma_kh', 'ma_sp']);
            });
        }

        if (! Schema::hasTable('phieu_giam_gia')) {
            Schema::create('phieu_giam_gia', function (Blueprint $table): void {
                $table->string('code', 40)->primary();
                $table->string('discount_type', 10);
                $table->decimal('discount_value', 12, 0);
                $table->decimal('minimum_order_value', 12, 0)->default(0);
                $table->decimal('maximum_discount', 12, 0)->nullable();
                $table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('usage_count')->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } elseif (Schema::hasColumn('phieu_giam_gia', 'ma_code')) {
            Schema::table('phieu_giam_gia', function (Blueprint $table): void {
                $table->integer('so_luong')->nullable()->change();
                $table->dateTime('ngay_bat_dau')->nullable()->change();
                $table->dateTime('ngay_ket_thuc')->nullable()->change();
            });
        }

        if (! Schema::hasTable('lich_su_su_dung_voucher')) {
            Schema::create('lich_su_su_dung_voucher', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40);
                $table->string('ma_kh', 20);
                $table->char('ma_dh', 10)->unique();
                $table->decimal('discount_amount', 12, 0);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('nha_cung_cap')) {
            Schema::create('nha_cung_cap', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 200)->unique();
                $table->string('phone', 20)->nullable();
                $table->string('email', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('phieu_nhap_kho')) {
            Schema::create('phieu_nhap_kho', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_id')->constrained('nha_cung_cap');
                $table->string('reference', 100)->nullable();
                $table->string('tk_admin', 20);
                $table->timestamp('received_at');
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('phieu_nhap_kho', 'reference')) {
            Schema::table('phieu_nhap_kho', function (Blueprint $table): void {
                $table->string('reference', 100)->nullable();
            });
        }

        if (! Schema::hasTable('chi_tiet_phieu_nhap_kho') && ! Schema::hasTable('chi_tiet_phieu_nhap')) {
            Schema::create('chi_tiet_phieu_nhap_kho', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('stock_receipt_id')->constrained('phieu_nhap_kho')->cascadeOnDelete();
                $table->char('ma_sp', 10);
                $table->unsignedInteger('quantity');
            });
        }

        if (! Schema::hasTable('so_serial_san_pham')) {
            Schema::create('so_serial_san_pham', function (Blueprint $table): void {
                $table->id();
                $table->string('serial_number', 100)->unique();
                $table->char('ma_sp', 10)->index();
                $table->foreignId('stock_receipt_id')->constrained('phieu_nhap_kho');
                $table->char('ma_dh', 10)->nullable()->index();
                $table->string('status', 20)->default('In_Stock')->index();
                $table->timestamps();
            });
        } else {
            Schema::table('so_serial_san_pham', function (Blueprint $table): void {
                if (! Schema::hasColumn('so_serial_san_pham', 'ma_dh')) {
                    $table->char('ma_dh', 10)->nullable()->index();
                }
                if (Schema::hasColumn('so_serial_san_pham', 'id_serial')
                    && ! Schema::hasColumn('so_serial_san_pham', 'id_phieu_nhap')) {
                    $table->string('id_phieu_nhap', 20)->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('bao_hanh')) {
            Schema::create('bao_hanh', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_serial_id')->unique()->constrained('so_serial_san_pham');
                $table->string('ma_kh', 20);
                $table->char('ma_dh', 10);
                $table->date('starts_at');
                $table->date('expires_at');
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        foreach ([
            ['danh_gia', 'ma_kh', 'nguoi_dung', 'ma_kh', 'cascade'],
            ['danh_gia', 'ma_sp', 'san_pham', 'ma_sp', 'cascade'],
            ['lich_su_su_dung_voucher', 'ma_kh', 'nguoi_dung', 'ma_kh', null],
            ['lich_su_su_dung_voucher', 'ma_dh', 'don_hang', 'ma_dh', 'cascade'],
            ['phieu_nhap_kho', 'tk_admin', 'quan_tri', 'tk_admin', null],
            ['chi_tiet_phieu_nhap_kho', 'ma_sp', 'san_pham', 'ma_sp', null],
            ['so_serial_san_pham', 'ma_sp', 'san_pham', 'ma_sp', null],
            ['so_serial_san_pham', 'ma_dh', 'don_hang', 'ma_dh', 'null'],
            ['bao_hanh', 'ma_kh', 'nguoi_dung', 'ma_kh', null],
            ['bao_hanh', 'ma_dh', 'don_hang', 'ma_dh', null],
        ] as [$table, $column, $parentTable, $parentColumn, $deleteAction]) {
            $this->ensureStringForeignKey($table, $column, $parentTable, $parentColumn, $deleteAction);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('so_serial_san_pham', 'id_phieu_nhap')) {
            Schema::table('so_serial_san_pham', function (Blueprint $table): void {
                Schema::table('nhat_ky_trang_thai_don', function (Blueprint $table): void {
                    $columns = array_values(array_filter(
                        ['trang_thai_cu', 'nguoi_thuc_hien'],
                        fn (string $column): bool => Schema::hasColumn('nhat_ky_trang_thai_don', $column),
                    ));
                    if ($columns !== []) {
                        $table->dropColumn($columns);
                    }
                });
                Schema::table('chi_tiet_don_hang', function (Blueprint $table): void {
                    $columns = array_values(array_filter(
                        ['ten_sp_snapshot', 'thong_so_snapshot'],
                        fn (string $column): bool => Schema::hasColumn('chi_tiet_don_hang', $column),
                    ));
                    if ($columns !== []) {
                        $table->dropColumn($columns);
                    }
                });
                if (Schema::hasColumn('don_hang', 'idempotency_key')) {
                    Schema::table('don_hang', function (Blueprint $table): void {
                        $table->dropUnique(['idempotency_key']);
                        $table->dropColumn('idempotency_key');
                    });
                }
                if (Schema::hasColumn('so_serial_san_pham', 'ma_dh')) {
                    $table->dropIndex(['ma_dh']);
                    $table->dropColumn('ma_dh');
                }
                $table->dropIndex(['id_phieu_nhap']);
                $table->dropColumn('id_phieu_nhap');
            });
        }
        if (Schema::hasColumn('phieu_nhap_kho', 'id_phieu_nhap')
            && Schema::hasColumn('phieu_nhap_kho', 'reference')) {
            Schema::table('phieu_nhap_kho', function (Blueprint $table): void {
                $table->dropColumn('reference');
            });
        }
        if (Schema::hasColumn('bao_hanh', 'product_serial_id')) {
            Schema::dropIfExists('bao_hanh');
        }
        if (Schema::hasColumn('so_serial_san_pham', 'serial_number')) {
            Schema::dropIfExists('so_serial_san_pham');
        }
        Schema::dropIfExists('chi_tiet_phieu_nhap_kho');
        if (Schema::hasColumn('phieu_nhap_kho', 'supplier_id')) {
            Schema::dropIfExists('phieu_nhap_kho');
        }
        if (Schema::hasColumn('nha_cung_cap', 'name')) {
            Schema::dropIfExists('nha_cung_cap');
        }
        Schema::dropIfExists('lich_su_su_dung_voucher');
        if (Schema::hasColumn('phieu_giam_gia', 'code')) {
            Schema::dropIfExists('phieu_giam_gia');
        }
        if (Schema::hasColumn('danh_gia', 'rating')) {
            Schema::dropIfExists('danh_gia');
        }

        Schema::table('don_hang', function (Blueprint $table): void {
            $columns = array_values(array_filter(['ma_voucher', 'tien_giam'], fn (string $column) => Schema::hasColumn('don_hang', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
        Schema::table('nguoi_dung', function (Blueprint $table): void {
            if (Schema::hasColumn('nguoi_dung', 'so_dien_thoai')) {
                $table->dropUnique(['so_dien_thoai']);
                $table->dropColumn('so_dien_thoai');
            }
        });
        Schema::table('dia_chi', function (Blueprint $table): void {
            $columns = array_values(array_filter(['ten_nguoi_nhan', 'so_dien_thoai'], fn (string $column) => Schema::hasColumn('dia_chi', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
        Schema::table('san_pham', function (Blueprint $table): void {
            if (Schema::hasColumn('san_pham', 'deleted_at')) {
                $table->dropIndex(['deleted_at']);
                $table->dropColumn('deleted_at');
            }
        });
    }

    private function ensureStringForeignKey(
        string $tableName,
        string $columnName,
        string $parentTable,
        string $parentColumn,
        ?string $deleteAction,
    ): void {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasColumn($tableName, $columnName)
            || ! Schema::hasTable($parentTable)
            || ! Schema::hasColumn($parentTable, $parentColumn)) {
            return;
        }

        if ($this->hasForeignKey($tableName, $columnName, $parentTable)) {
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
