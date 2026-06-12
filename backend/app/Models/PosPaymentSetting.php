<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPaymentSetting extends Model
{
    protected $table = 'pos_payment_settings';

    protected $fillable = [
        'tenant_id',
        'cash_enabled', 'cash_label',
        'transfer_enabled', 'transfer_bank_name',
        'transfer_account_number', 'transfer_account_name',
        'gopay_enabled', 'gopay_number', 'gopay_name',
        'dana_enabled', 'dana_number', 'dana_name',
        'qris_enabled', 'qris_image_path', 'qris_label',
    ];

    protected $casts = [
        'cash_enabled'     => 'boolean',
        'transfer_enabled' => 'boolean',
        'gopay_enabled'    => 'boolean',
        'dana_enabled'     => 'boolean',
        'qris_enabled'     => 'boolean',
    ];
}