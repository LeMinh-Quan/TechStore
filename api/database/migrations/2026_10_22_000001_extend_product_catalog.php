<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('san_pham')) {
            Schema::create('san_pham', function (Blueprint $table) {
                $table->char('ma_sp', 10)->primary();
                $table->string('ten_sp', 255)->nullable();
                $table->string('loai', 100)->nullable()->index();
                $table->string('thuong_hieu', 100)->nullable()->index();
                $table->text('mo_ta')->nullable();
                $table->decimal('gia_ban', 10, 0)->nullable();
                $table->integer('so_luong')->default(0);
                $table->integer('stt')->default(0);
            });
        }

        Schema::table('san_pham', function (Blueprint $table) {
            if (! Schema::hasColumn('san_pham', 'slug')) {
                $table->string('slug', 255)->nullable()->unique();
            }
            if (! Schema::hasColumn('san_pham', 'trang_thai_hien_thi')) {
                $table->string('trang_thai_hien_thi', 20)->default('draft')->index();
            }
            if (! Schema::hasColumn('san_pham', 'gia_niem_yet')) {
                $table->decimal('gia_niem_yet', 10, 0)->nullable();
            }
            if (! Schema::hasColumn('san_pham', 'source_url')) {
                $table->string('source_url', 512)->nullable()->unique();
            }
            if (! Schema::hasColumn('san_pham', 'thong_so_ky_thuat')) {
                $table->longText('thong_so_ky_thuat')->nullable();
            }
        });

        $productKey = $this->columnCharacterSet('san_pham', 'ma_sp');
        if (! Schema::hasTable('san_pham_hinh_anh')) {
            Schema::create('san_pham_hinh_anh', function (Blueprint $table) use ($productKey) {
                if ($productKey) {
                    $table->charset($productKey->charset);
                    $table->collation($productKey->collation);
                }
                $table->bigIncrements('id_hinh_anh');
                $productId = $table->char('ma_sp', 10);
                if ($productKey) {
                    $productId->charset($productKey->charset);
                    $productId->collation($productKey->collation);
                }
                $table->string('duong_dan_anh', 2048);
                $table->string('alt_text', 255)->nullable();
                $table->unsignedInteger('thu_tu')->default(0);
                $table->timestamps();
                $table->foreign('ma_sp')->references('ma_sp')->on('san_pham')->cascadeOnDelete();
            });
        }
        $this->ensureProductImageForeignKey($productKey);

        if (! Schema::hasTable('nguoi_dung')) {
            Schema::create('nguoi_dung', function (Blueprint $table) {
                $table->string('ma_kh', 20)->primary();
                $table->string('ten_dang_nhap', 255)->unique();
                $table->string('email', 255)->unique();
                $table->string('mat_khau', 255);
            });
        }
        if (! Schema::hasColumn('nguoi_dung', 'ten_khach_hang')) {
            Schema::table('nguoi_dung', function (Blueprint $table) {
                $table->string('ten_khach_hang', 200)->nullable();
            });
        }

        if (! Schema::hasTable('dia_chi')) {
            Schema::create('dia_chi', function (Blueprint $table) {
                $table->string('ma_kh', 20);
                $table->string('loai_dia_chi', 20)->default('Nha');
                $table->string('dia_chi_giao_hang', 20)->default('Nha');
                $table->string('chi_tiet_dia_chi', 255);
                $table->string('thanh_pho', 100)->nullable();
                $table->string('quan_huyen', 100)->nullable();
                $table->string('phuong_xa', 100)->nullable();
                $table->string('ghi_chu', 255)->nullable();
                $table->primary(['ma_kh', 'loai_dia_chi']);
                $table->foreign('ma_kh')->references('ma_kh')->on('nguoi_dung')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('quan_tri')) {
            Schema::create('quan_tri', function (Blueprint $table) {
                $table->string('tk_admin', 20)->primary();
                $table->string('mat_khau', 255);
                $table->string('ten_admin', 200)->nullable();
                $table->string('email', 255)->nullable()->unique();
            });
        }

        if (! Schema::hasTable('gio_hang')) {
            Schema::create('gio_hang', function (Blueprint $table) {
                $table->char('ma_cart', 20)->primary();
                $table->string('ma_kh', 20)->nullable()->index();
                $table->date('ngay_tao')->nullable();
                $table->foreign('ma_kh')->references('ma_kh')->on('nguoi_dung')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('chi_tiet_gio_hang')) {
            Schema::create('chi_tiet_gio_hang', function (Blueprint $table) {
                $table->char('ma_cart', 20);
                $table->char('ma_sp', 10);
                $table->unsignedInteger('so_luong');
                $table->decimal('gia', 10, 0);
                $table->primary(['ma_cart', 'ma_sp']);
                $table->foreign('ma_cart')->references('ma_cart')->on('gio_hang')->cascadeOnDelete();
                $table->foreign('ma_sp')->references('ma_sp')->on('san_pham')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('don_hang')) {
            Schema::create('don_hang', function (Blueprint $table) {
                $table->char('ma_dh', 10)->primary();
                $table->string('ma_kh', 20)->nullable()->index();
                $table->date('ngay_dat');
                $table->decimal('tong_tien', 12, 0);
                $table->string('trang_thai', 50)->default('Cho xac nhan');
                $table->foreign('ma_kh')->references('ma_kh')->on('nguoi_dung');
            });
        }

        if (! Schema::hasTable('chi_tiet_don_hang')) {
            Schema::create('chi_tiet_don_hang', function (Blueprint $table) {
                $table->char('ma_dh', 10);
                $table->char('ma_sp', 10);
                $table->unsignedInteger('so_luong');
                $table->decimal('don_gia', 10, 0);
                $table->primary(['ma_dh', 'ma_sp']);
                $table->foreign('ma_dh')->references('ma_dh')->on('don_hang')->cascadeOnDelete();
                $table->foreign('ma_sp')->references('ma_sp')->on('san_pham');
            });
        }

        if (! Schema::hasTable('thanh_toan')) {
            Schema::create('thanh_toan', function (Blueprint $table) {
                $table->char('ma_tt', 10)->primary();
                $table->char('ma_dh', 10)->nullable()->index();
                $table->string('phuong_thuc', 30)->default('Tien mat');
                $table->dateTime('ngay_va_gio')->nullable();
                $table->string('trang_thai', 30)->default('Chua Thanh Toan');
                $table->foreign('ma_dh')->references('ma_dh')->on('don_hang')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('nhat_ky_trang_thai_don')) {
            Schema::create('nhat_ky_trang_thai_don', function (Blueprint $table) {
                $table->bigIncrements('id_nhat_ky');
                $table->char('ma_dh', 10)->index();
                $table->string('trang_thai', 50);
                $table->string('ghi_chu', 255)->nullable();
                $table->timestamp('thoi_gian_tao')->useCurrent();
                $table->foreign('ma_dh')->references('ma_dh')->on('don_hang')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('nhat_ky_hoat_dong')) {
            Schema::create('nhat_ky_hoat_dong', function (Blueprint $table) {
                $table->bigIncrements('id_nhat_ky');
                $table->string('tk_admin', 20);
                $table->string('hanh_dong', 100);
                $table->text('chi_tiet')->nullable();
                $table->string('dia_chi_ip', 45)->nullable();
                $table->timestamp('thoi_gian_tao')->useCurrent();
                $table->foreign('tk_admin')->references('tk_admin')->on('quan_tri');
            });
        }

        if (Schema::hasTable('don_hang')) {
            Schema::table('don_hang', function (Blueprint $table) {
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
        }

        if (! Schema::hasTable('api_access_tokens')) {
            Schema::create('api_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('token_hash', 64)->unique();
                $table->string('account_type', 20);
                $table->string('account_id', 20);
                $table->timestamp('expires_at')->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->index(['account_type', 'account_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('san_pham_hinh_anh');
        Schema::dropIfExists('api_access_tokens');

        if (Schema::hasTable('don_hang')) {
            Schema::table('don_hang', function (Blueprint $table) {
                foreach (['ten_nguoi_nhan', 'dien_thoai_nguoi_nhan', 'dia_chi_giao_hang'] as $column) {
                    if (Schema::hasColumn('don_hang', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('san_pham')) {
            Schema::table('san_pham', function (Blueprint $table) {
                if (Schema::hasColumn('san_pham', 'gia_niem_yet')) {
                    $table->dropColumn('gia_niem_yet');
                }
                if (Schema::hasColumn('san_pham', 'thong_so_ky_thuat')) {
                    $table->dropColumn('thong_so_ky_thuat');
                }
                if (Schema::hasColumn('san_pham', 'source_url')) {
                    $table->dropUnique(['source_url']);
                    $table->dropColumn('source_url');
                }
                if (Schema::hasColumn('san_pham', 'trang_thai_hien_thi')) {
                    $table->dropIndex(['trang_thai_hien_thi']);
                    $table->dropColumn('trang_thai_hien_thi');
                }
                if (Schema::hasColumn('san_pham', 'slug')) {
                    $table->dropUnique(['slug']);
                    $table->dropColumn('slug');
                }
            });
        }
    }

    private function columnCharacterSet(string $table, string $column): ?object
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }

        $metadata = DB::selectOne(
            'select CHARACTER_SET_NAME as charset, COLLATION_NAME as collation
            from information_schema.COLUMNS
            where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$table, $column],
        );

        return $metadata?->charset && $metadata?->collation ? $metadata : null;
    }

    private function ensureProductImageForeignKey(?object $productKey): void
    {
        if (! Schema::hasTable('san_pham_hinh_anh')) {
            return;
        }

        $hasForeignKey = DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
                ->where('TABLE_NAME', 'san_pham_hinh_anh')
                ->where('COLUMN_NAME', 'ma_sp')
                ->where('REFERENCED_TABLE_NAME', 'san_pham')
                ->exists()
            : collect(Schema::getForeignKeys('san_pham_hinh_anh'))->contains(
                fn (array $foreignKey): bool => $foreignKey['columns'] === ['ma_sp']
                    && $foreignKey['foreign_table'] === 'san_pham',
            );

        if ($hasForeignKey) {
            return;
        }

        if ($productKey) {
            $imageKey = $this->columnCharacterSet('san_pham_hinh_anh', 'ma_sp');
            if ($imageKey && ($imageKey->charset !== $productKey->charset
                || $imageKey->collation !== $productKey->collation)) {
                Schema::table('san_pham_hinh_anh', function (Blueprint $table) use ($productKey): void {
                    $table->char('ma_sp', 10)
                        ->charset($productKey->charset)
                        ->collation($productKey->collation)
                        ->change();
                });
            }
        }

        Schema::table('san_pham_hinh_anh', function (Blueprint $table): void {
            $table->foreign('ma_sp')->references('ma_sp')->on('san_pham')->cascadeOnDelete();
        });
    }
};
