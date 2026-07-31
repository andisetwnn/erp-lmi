<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectReassignmentLog extends Model
{
    protected $table = 'prospect_reassignment_log';

    public $timestamps = false;

    protected $fillable = [
        'prospect_customer_id',
        'from_sales_id',
        'to_sales_id',
        'alasan',
        'reassigned_by_sales_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function prospectCustomer(): BelongsTo
    {
        return $this->belongsTo(ProspectCustomer::class);
    }

    public function fromSales(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'from_sales_id');
    }

    public function toSales(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'to_sales_id');
    }

    public function reassignedBy(): BelongsTo
    {
        return $this->belongsTo(Sales::class, 'reassigned_by_sales_id');
    }
}
