<?php

namespace App\Services\POS;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * مدیریت هوشمند تراکنش‌های POS
 * 
 * این کلاس تراکنش‌های POS را مدیریت، ذخیره و گزارش‌گیری می‌کند
 * 
 * @author Zhina Rohi
 * @version 1.0.0
 */
class TransactionManager
{
    /**
     * وضعیت‌های تراکنش
     */
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    /**
     * حداکثر تعداد تلاش مجدد
     */
    const MAX_RETRY_ATTEMPTS = 3;

    /**
     * ذخیره تراکنش جدید
     *
     * @param array $data اطلاعات تراکنش
     * @return int شناسه تراکنش
     */
    public function createTransaction(array $data): int
    {
        try {
            $transactionId = DB::table('pos_transactions')->insertGetId([
                'amount' => $data['amount'],
                'terminal_id' => $data['terminal_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'tracking_code' => $data['tracking_code'] ?? null,
                'card_number' => 
$this->maskCardNumber($data['card_number'] ?? null),
                'status' => self::STATUS_PENDING,
                'metadata' => json_encode($data['metadata'] ?? []),
                'user_id' => $data['user_id'] ?? null,
                'retry_count' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            Log::info("تراکنش جدید ایجاد شد", [
                'transaction_id' => $transactionId,
                'amount' => $data['amount']
            ]);

            return $transactionId;

        } catch (\Exception $e) {
            Log::error("خطا در ایجاد تراکنش: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * به‌روزرسانی وضعیت تراکنش
     *
     * @param int $transactionId
     * @param string $status
     * @param array $additionalData
     * @return bool
     */
    public function updateTransactionStatus(int $transactionId, string 
$status, array $additionalData = []): bool
    {
        try {
            $updateData = [
                'status' => $status,
                'updated_at' => Carbon::now(),
            ];

            if (!empty($additionalData)) {
                $updateData = array_merge($updateData, $additionalData);
            }

            // ذخیره زمان تکمیل برای تراکنش‌های موفق
            if ($status === self::STATUS_SUCCESS) {
                $updateData['completed_at'] = Carbon::now();
            }

            DB::table('pos_transactions')
                ->where('id', $transactionId)
                ->update($updateData);

            Log::info("وضعیت تراکنش به‌روز شد", [
                'transaction_id' => $transactionId,
                'status' => $status
            ]);

            // پاک کردن کش
            Cache::forget("transaction_{$transactionId}");

            return true;

        } catch (\Exception $e) {
            Log::error("خطا در به‌روزرسانی تراکنش: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تلاش مجدد برای تراکنش‌های ناموفق
     *
     * @param int $transactionId
     * @return bool
     */
    public function retryTransaction(int $transactionId): bool
    {
        $transaction = $this->getTransaction($transactionId);

        if (!$transaction) {
            return false;
        }

        // بررسی محدودیت تعداد تلاش
        if ($transaction->retry_count >= self::MAX_RETRY_ATTEMPTS) {
            Log::warning("تراکنش به حداکثر تعداد تلاش رسید", [
                'transaction_id' => $transactionId
            ]);
            return false;
        }

        // افزایش شمارنده تلاش
        DB::table('pos_transactions')
            ->where('id', $transactionId)
            ->increment('retry_count');

        Log::info("تلاش مجدد برای تراکنش", [
            'transaction_id' => $transactionId,
            'retry_count' => $transaction->retry_count + 1
        ]);

        return true;
    }

    /**
     * دریافت اطلاعات تراکنش
     *
     * @param int $transactionId
     * @return object|null
     */
    public function getTransaction(int $transactionId): ?object
    {
        return Cache::remember("transaction_{$transactionId}", 3600, 
function () use ($transactionId) {
            return DB::table('pos_transactions')
                ->where('id', $transactionId)
                ->first();
        });
    }

    /**
     * دریافت آمار تراکنش‌ها
     *
     * @param array $filters
     * @return array
     */
    public function getStatistics(array $filters = []): array
    {
        $query = DB::table('pos_transactions');

        // اعمال فیلترها
        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        if (!empty($filters['terminal_id'])) {
            $query->where('terminal_id', $filters['terminal_id']);
        }

        $stats = [
            'total_count' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'success_count' => (clone $query)->where('status', 
self::STATUS_SUCCESS)->count(),
            'failed_count' => (clone $query)->where('status', 
self::STATUS_FAILED)->count(),
            'pending_count' => (clone $query)->where('status', 
self::STATUS_PENDING)->count(),
            'success_amount' => (clone $query)->where('status', 
self::STATUS_SUCCESS)->sum('amount'),
        ];

        // محاسبه نرخ موفقیت
        $stats['success_rate'] = $stats['total_count'] > 0 
            ? round(($stats['success_count'] / $stats['total_count']) * 
100, 2) 
            : 0;

        return $stats;
    }

    /**
     * دریافت تراکنش‌های اخیر
     *
     * @param int $limit
     * @param array $filters
     * @return \Illuminate\Support\Collection
     */
    public function getRecentTransactions(int $limit = 10, array $filters 
= [])
    {
        $query = DB::table('pos_transactions')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->get();
    }

    /**
     * پاکسازی تراکنش‌های قدیمی
     *
     * @param int $daysOld تعداد روز
     * @return int تعداد تراکنش‌های پاک شده
     */
    public function cleanupOldTransactions(int $daysOld = 90): int
    {
        $date = Carbon::now()->subDays($daysOld);

        $count = DB::table('pos_transactions')
            ->where('created_at', '<', $date)
            ->whereIn('status', [self::STATUS_SUCCESS, 
self::STATUS_CANCELLED])
            ->delete();

        Log::info("تراکنش‌های قدیمی پاک شدند", [
            'count' => $count,
            'older_than' => $daysOld . ' days'
        ]);

        return $count;
    }

    /**
     * تولید گزارش روزانه
     *
     * @param string $date
     * @return array
     */
    public function getDailyReport(string $date): array
    {
        $startDate = Carbon::parse($date)->startOfDay();
        $endDate = Carbon::parse($date)->endOfDay();

        $transactions = DB::table('pos_transactions')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $report = [
            'date' => $date,
            'total_transactions' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
            'successful_transactions' => $transactions->where('status', 
self::STATUS_SUCCESS)->count(),
            'failed_transactions' => $transactions->where('status', 
self::STATUS_FAILED)->count(),
            'successful_amount' => $transactions->where('status', 
self::STATUS_SUCCESS)->sum('amount'),
            'average_amount' => $transactions->where('status', 
self::STATUS_SUCCESS)->avg('amount'),
            'by_terminal' => [],
        ];

        // گروه‌بندی بر اساس ترمینال
        $byTerminal = $transactions->groupBy('terminal_id');
        foreach ($byTerminal as $terminalId => $terminalTransactions) {
            $report['by_terminal'][$terminalId] = [
                'count' => $terminalTransactions->count(),
                'amount' => $terminalTransactions->sum('amount'),
                'success_count' => $terminalTransactions->where('status', 
self::STATUS_SUCCESS)->count(),
            ];
        }

        return $report;
    }

    /**
     * صادرات تراکنش‌ها به فرمت CSV
     *
     * @param array $filters
     * @return string مسیر فایل
     */
    public function exportToCSV(array $filters = []): string
    {
        $query = DB::table('pos_transactions')
            ->orderBy('created_at', 'desc');

        // اعمال فیلترها
        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        $transactions = $query->get();

        // ایجاد فایل CSV
        $filename = 'transactions_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/exports/' . $filename);

        // ایجاد پوشه در صورت عدم وجود
        if (!file_exists(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        $file = fopen($filepath, 'w');

        // هدر CSV
        fputcsv($file, [
            'شناسه',
            'مبلغ',
            'شماره ترمینال',
            'کد پیگیری',
            'شماره کارت',
            'وضعیت',
            'تاریخ ایجاد',
            'تاریخ تکمیل'
        ]);

        // نوشتن داده‌ها
        foreach ($transactions as $transaction) {
            fputcsv($file, [
                $transaction->id,
                $transaction->amount,
                $transaction->terminal_id,
                $transaction->tracking_code,
                $transaction->card_number,
                $this->getStatusLabel($transaction->status),
                $transaction->created_at,
                $transaction->completed_at ?? '-'
            ]);
        }

        fclose($file);

        Log::info("فایل CSV صادر شد", ['filepath' => $filepath]);

        return $filepath;
    }

    /**
     * ماسک کردن شماره کارت
     *
     * @param string|null $cardNumber
     * @return string|null
     */
    private function maskCardNumber(?string $cardNumber): ?string
    {
        if (!$cardNumber) {
            return null;
        }

        // نگه‌داری 6 رقم اول و 4 رقم آخر
        return substr($cardNumber, 0, 6) . '******' . substr($cardNumber, 
-4);
    }

    /**
     * دریافت برچسب فارسی وضعیت
     *
     * @param string $status
     * @return string
     */
    private function getStatusLabel(string $status): string
    {
        $labels = [
            self::STATUS_PENDING => 'در انتظار',
            self::STATUS_SUCCESS => 'موفق',
            self::STATUS_FAILED => 'ناموفق',
            self::STATUS_CANCELLED => 'لغو شده',
            self::STATUS_REFUNDED => 'برگشت داده شده',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * بررسی تراکنش‌های معلق
     * 
     * تراکنش‌هایی که بیش از 5 دقیقه در حالت pending هستند
     *
     * @return \Illuminate\Support\Collection
     */
    public function getPendingTransactions()
    {
        return DB::table('pos_transactions')
            ->where('status', self::STATUS_PENDING)
            ->where('created_at', '<', Carbon::now()->subMinutes(5))
            ->get();
    }

    /**
     * تشخیص الگوی تراکنش‌های مشکوک
     *
     * @param int $userId
     * @return bool
     */
    public function detectSuspiciousActivity(int $userId): bool
    {
        // بررسی تعداد تراکنش‌های ناموفق در 1 ساعت اخیر
        $recentFailures = DB::table('pos_transactions')
            ->where('user_id', $userId)
            ->where('status', self::STATUS_FAILED)
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count();

        if ($recentFailures >= 5) {
            Log::warning("فعالیت مشکوک شناسایی شد", [
                'user_id' => $userId,
                'failed_count' => $recentFailures
            ]);
            return true;
        }

        return false;
    }
}
