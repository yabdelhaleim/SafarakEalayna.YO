\DB::beginTransaction();
try {
    $manual = \App\Models\Transaction::find(365);
    if ($manual) {
        $manual->delete();
        echo "Deleted manual reverse TX 365\n";
    } else {
        echo "TX 365 not found\n";
    }
    $svc = app(\App\Services\Finance\TransactionService::class);
    $tx = $svc->recordJournalTransfer([
        'amount' => 800,
        'converted_amount' => 132000,
        'from_account_id' => 27,
        'to_account_id' => 109,
        'module' => 'flight',
        'related_type' => 'App\Models\Transaction',
        'related_id' => 364,
        'notes' => 'عكس معاملة 364 (دفعة خاطئة 132000 EGP / 800 KWD)',
        'created_by' => 1,
        'allow_from_negative' => true,
    ]);
    echo "Proper reversal TX ID: ".$tx->id."\n";
    \DB::commit();
    echo "DONE - committed\n";
} catch (\Exception $e) {
    \DB::rollBack();
    echo "ERROR: ".$e->getMessage()."\n";
}
