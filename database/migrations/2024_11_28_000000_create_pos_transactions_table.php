<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration برای جدول تراکنش‌های POS
 * 
 * @author Zhina Rohi
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 2)->comment('مبلغ تراکنش');
            $table->string('terminal_id', 50)->nullable()->comment('شناسه 
ترمینال');
            $table->string('reference_number', 
100)->nullable()->comment('شماره مرجع');
            $table->string('tracking_code', 100)->nullable()->comment('کد 
پیگیری');
            $table->string('card_number', 20)->nullable()->comment('شماره 
کارت (ماسک شده)');
            $table->enum('status', ['pending', 'success', 'failed', 
'cancelled', 'refunded'])
                ->default('pending')
                ->comment('وضعیت تراکنش');
            $table->json('metadata')->nullable()->comment('اطلاعات 
اضافی');
            
$table->unsignedBigInteger('user_id')->nullable()->comment('شناسه کاربر');
            $table->integer('retry_count')->default(0)->comment('تعداد 
تلاش مجدد');
            $table->text('error_message')->nullable()->comment('پیام 
خطا');
            $table->timestamp('completed_at')->nullable()->comment('زمان 
تکمیل');
            $table->timestamps();
            
            // ایندکس‌ها برای بهبود کارایی
            $table->index('status');
            $table->index('terminal_id');
            $table->index('user_id');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
    }
};
