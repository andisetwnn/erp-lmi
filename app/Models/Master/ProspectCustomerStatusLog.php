<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectCustomerStatusLog extends Model
{
    protected $table = 'prospect_customer_status_log';

    public $timestamps = false;

    protected $fillable = [
        'prospect_customer_id',
        'status_dari',
        'status_ke',
        'catatan',
        'changed_by_sales_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function prospectCustomer(): BelongsTo
    {
        return $this->belongsTo(ProspectCustomer::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'changed_by_sales_id');
    }
}
