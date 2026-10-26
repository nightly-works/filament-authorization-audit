<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['number', 'customer_name', 'amount_cents', 'status'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid', 'refunded' => 'Refunded'];
}
